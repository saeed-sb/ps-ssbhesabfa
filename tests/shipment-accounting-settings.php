<?php
/** Accounting migrations and retries use isolated storage and API fixtures. */
ob_start();
require __DIR__ . '/shipment-gross-accounting.php';
ob_end_clean();
require dirname(__DIR__, 2) . '/ssbdeliverymanager/src/Database/Transaction.php';

use Ssb\DeliveryManager\ShippingCost\AccountingSettings as Settings;

class Shop { public static function getGroupFromShop($id) { return (int) $id > 0 ? 1 : 0; } }
class SettingsDb extends GrossDb {
    public $shops = []; public $backup; public $failWrite = false;
    public function executeS($sql, ...$args) {
        if (preg_match("/FROM `fixture_configuration` WHERE name = '([^']+)' AND id_shop = ([0-9]+)/", $sql, $m)) {
            return array_key_exists($m[1], $this->shops[(int) $m[2]] ?? []) ? [['id_configuration' => 1, 'value' => $this->shops[(int) $m[2]][$m[1]]]] : [];
        }
        return parent::executeS($sql, ...$args);
    }
    public function execute($sql) {
        if ($sql === 'START TRANSACTION') { $this->backup = $this->shops; }
        if ($sql === 'ROLLBACK') { $this->shops = $this->backup; }
        return true;
    }
    public function update($table, $data, $where) {
        preg_match("/name = '([^']+)'/", $where, $m);
        $data['name'] = $m[1]; return $this->insert($table, $data);
    }
    public function insert($table, $data) {
        if ($this->failWrite && $data['name'] === Settings::EXPENSE_ACCOUNT) { return false; }
        $this->shops[(int) $data['id_shop']][$data['name']] = stripslashes($data['value']); return true;
    }
}
$checks = [];
$assert = static function ($name, $pass) use (&$checks) { $checks[] = ['case' => $name, 'pass' => (bool) $pass]; if (!$pass) { throw new RuntimeException($name); } };
$reject = static function ($callback) { try { $callback(); } catch (InvalidArgumentException $e) { return true; } return false; };
$db = new SettingsDb();
$db->shops = [0 => ['SSBHESABFA_FREIGHT_MARGIN_ENABLED' => '1'], 1 => ['SSBHESABFA_FREIGHT_MARGIN_ENABLED' => '1', 'SSBHESABFA_FREIGHT_MARGIN_EXPENSE_ACCOUNT' => 'Expense:Shipping']];
$assert('Legacy exact-shop policy is preserved', Settings::get($db, 1) === [Settings::ENABLED => '1', Settings::EXPENSE_ACCOUNT => 'Expense : Shipping']);
$assert('Another shop never inherits posting permission', Settings::get($db, 2)[Settings::ENABLED] === '0');
Settings::save($db, 1, [Settings::EXPENSE_ACCOUNT => ' Expense : Delivery ']);
$assert('Delivery policy becomes authoritative', Settings::get($db, 1)[Settings::EXPENSE_ACCOUNT] === 'Expense : Delivery');
$assert('Legacy policy remains available for recovery', $db->shops[1]['SSBHESABFA_FREIGHT_MARGIN_EXPENSE_ACCOUNT'] === 'Expense:Shipping');
$before = $db->shops;
$assert('An enabled empty account is rejected', $reject(static function () use ($db) { Settings::save($db, 1, [Settings::EXPENSE_ACCOUNT => '']); }) && $db->shops === $before);
$assert('A malformed account is rejected', $reject(static function () use ($db) { Settings::save($db, 1, [Settings::EXPENSE_ACCOUNT => 'Expense::Shipping']); }));
$db->failWrite = true;
try { Settings::save($db, 1, [Settings::ENABLED => '0', Settings::EXPENSE_ACCOUNT => 'Expense:Other']); } catch (RuntimeException $e) {}
$assert('A failed two-field save rolls back the switch', $db->shops === $before);
$db->failWrite = false;
Settings::save($db, 1, [Settings::ENABLED => '0', Settings::EXPENSE_ACCOUNT => '']);
$assert('Explicit disabled and empty values never fall back', Settings::get($db, 1) === [Settings::ENABLED => '0', Settings::EXPENSE_ACCOUNT => '']);

list($db, $repo, $api, $service, $source) = grossFixture();
$db->config[HesabfaFreightMarginSettings::INCOME_ACCOUNT] = '';
$source['expense_account_path'] = $db->config[HesabfaFreightMarginSettings::EXPENSE_ACCOUNT];
$repo->sourceRow = sourceRevision($source);
$assert('Gross posting needs no income account', $service->process(1, 40001) === true);
$count = count($api->writes);
$assert('Delivery-owned account retry is idempotent', $service->process(1, 40001) === true && count($api->writes) === $count);
$db->config[Settings::EXPENSE_ACCOUNT] = 'Expense : Other';
$assert('A changed account blocks stale writes', $service->process(1, 40001) === false && count($api->writes) === $count);
$source['expense_account_path'] = 'Expense : Other'; $repo->sourceRow = sourceRevision($source);
$assert('Refreshed account edits the owned document', $service->process(1, 40001) === true && $api->remote['Number'] === 90204 && $api->remote['Id'] === 123456);
$assert('Legacy settings cannot override Delivery Manager', $reject(static function () { HesabfaFreightMarginSettings::validateSubmitted(1, ['SSBHESABFA_FREIGHT_MARGIN_ENABLED' => '0']); }));

list($db, $repo, $api, $service, $source) = grossFixture();
$source['expense_account_path'] = $db->config[HesabfaFreightMarginSettings::EXPENSE_ACCOUNT]; $repo->sourceRow = sourceRevision($source);
$api->failBefore = true;
try { $service->process(1, 40001); } catch (RuntimeException $e) {}
$count = count($api->writes); $db->config[Settings::EXPENSE_ACCOUNT] = 'Expense : New';
$assert('Unaccepted pending writes cannot use a stale account', $service->process(1, 40001) === false && count($api->writes) === $count);

list($db, $repo, $api, $service, $source) = grossFixture();
$api->failBefore = true;
try { $service->process(1, 40001); } catch (RuntimeException $e) {}
$count = count($api->writes); $db->config[Settings::EXPENSE_ACCOUNT] = 'Expense : New';
$assert('Pre-migration pending writes also reject a changed account', $service->process(1, 40001) === false && count($api->writes) === $count);

echo json_encode(['success' => true, 'checks' => count($checks), 'tests' => $checks], JSON_PRETTY_PRINT), "\n";
