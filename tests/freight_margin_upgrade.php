<?php
/** Isolated release upgrade: no shop bootstrap, network, credentials or jobs. */
define('_PS_VERSION_', '8.2.0');
define('_DB_PREFIX_', 'fixture_');

final class FreightUpgradeFixtureDb
{
    public $queries = array();
    public $tables = array();
    public $failAt = 0;

    public function execute($sql)
    {
        $this->queries[] = $sql;
        if (!preg_match('/^CREATE TABLE IF NOT EXISTS fixture_(ssb_hesabfa_freight_margin(?:_audit)?) \(/', $sql, $match)
            || strpos($sql, 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4') === false) {
            throw new RuntimeException('Unexpected SQL in release upgrade.');
        }
        if ($this->failAt === count($this->queries)) {
            return false;
        }
        $this->tables[$match[1]] = true;
        return true;
    }

    public function __call($method, $arguments)
    {
        throw new RuntimeException('Operational database access during release upgrade: ' . $method);
    }
}

final class Db
{
    public static $fixture;
    public static function getInstance() { return self::$fixture; }
}

final class FreightUpgradeFixtureModule
{
    public $hooks = array();
    public $registered = array();
    public $hookSucceeds = true;

    public function registerHook($name)
    {
        if ($name !== 'actionSsbDeliveryShippingCostSaved') {
            throw new RuntimeException('Unexpected hook in release upgrade.');
        }
        $this->hooks[] = $name;
        if ($this->hookSucceeds) {
            $this->registered[$name] = true;
        }
        return $this->hookSucceeds;
    }

    public function __call($method, $arguments)
    {
        throw new RuntimeException('Operational module access during release upgrade: ' . $method);
    }
}

require dirname(__DIR__) . '/upgrade/upgrade-2.3.39.php';

$checks = 0;
function freightUpgradeCheck($condition, $message)
{
    global $checks;
    ++$checks;
    if (!$condition) { throw new RuntimeException($message); }
}

Db::$fixture = new FreightUpgradeFixtureDb();
$module = new FreightUpgradeFixtureModule();
freightUpgradeCheck(upgrade_module_2_3_39($module) === true, 'First upgrade failed.');
freightUpgradeCheck(count(Db::$fixture->queries) === 2, 'Upgrade must issue exactly two CREATE statements.');
freightUpgradeCheck(array_keys(Db::$fixture->tables) === array('ssb_hesabfa_freight_margin', 'ssb_hesabfa_freight_margin_audit'), 'Unexpected table ownership.');
freightUpgradeCheck(strpos(Db::$fixture->queries[0], 'PRIMARY KEY(id_shop,id_order)') !== false, 'Shipment state must remain shop-scoped.');
freightUpgradeCheck(strpos(Db::$fixture->queries[1], 'KEY order_history(id_shop,id_order)') !== false, 'Audit index is missing.');
freightUpgradeCheck($module->hooks === array('actionSsbDeliveryShippingCostSaved'), 'Expected hook was not registered.');
freightUpgradeCheck(upgrade_module_2_3_39($module) === true, 'Repeated upgrade failed.');
freightUpgradeCheck(count(Db::$fixture->queries) === 4 && count(Db::$fixture->tables) === 2, 'Repeat upgrade changed storage ownership.');
freightUpgradeCheck(count($module->registered) === 1, 'Repeated hook registration is not idempotent.');

foreach (array(1, 2) as $failAt) {
    Db::$fixture = new FreightUpgradeFixtureDb();
    Db::$fixture->failAt = $failAt;
    $module = new FreightUpgradeFixtureModule();
    freightUpgradeCheck(upgrade_module_2_3_39($module) === false, 'SQL failure must propagate.');
    freightUpgradeCheck(count(Db::$fixture->queries) === $failAt, 'SQL failure did not stop upgrade.');
    freightUpgradeCheck($module->hooks === array(), 'A failed schema setup must not register hooks.');
}
Db::$fixture = new FreightUpgradeFixtureDb();
$module = new FreightUpgradeFixtureModule();
$module->hookSucceeds = false;
freightUpgradeCheck(upgrade_module_2_3_39($module) === false, 'Hook failure must propagate.');
freightUpgradeCheck(count(Db::$fixture->queries) === 2, 'Hook failure changed schema work.');
freightUpgradeCheck($module->registered === array(), 'Failed hook was marked registered.');

echo json_encode(array('success' => true, 'checks' => $checks, 'external_operations' => 0)) . "\n";
