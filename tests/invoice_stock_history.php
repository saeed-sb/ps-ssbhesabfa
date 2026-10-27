<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
define('_PS_VERSION_', '8.1.7');
define('_DB_PREFIX_', 'ps_');
$checks = 0;
function check($ok, $message) {
    global $checks;
    $checks++;
    if (!$ok) { throw new Exception($message); }
}
function pSQL($text, $html = false) { return addslashes($text); }
class Db {
    public static $rows = array(), $readFail = false, $writeFail = false;
    public static $column = false, $index = false, $ddl = 0;
    public static function getInstance() { return new self(); }
    public function executeS($sql, $array = true, $cache = true) {
        check($cache === false, 'History/schema lookup used cached data');
        if (strpos($sql, 'SHOW COLUMNS') === 0) { return self::$column ? array(array('Field' => 'invoice_item_codes')) : array(); }
        if (strpos($sql, 'SHOW INDEX') === 0) { return self::$index ? array(array('Key_name' => 'idx_invoice_history')) : array(); }
        if (self::$readFail) { return false; }
        check(strpos($sql, '`object_type`="Invoice"') !== false, 'History is not invoice scoped');
        preg_match('/`object_id`="([0-9]+)"/', $sql, $invoice);
        preg_match('/`change_id`<=([0-9]+)/', $sql, $change);
        check(isset($invoice[1], $change[1]), 'History omitted invoice identity or event boundary');
        $rows = array_filter(self::$rows, function ($row) use ($invoice, $change) {
            return $row['object_id'] === (int) $invoice[1] && $row['change_id'] <= (int) $change[1];
        });
        usort($rows, function ($a, $b) { return $b['change_id'] <=> $a['change_id']; });
        return $rows;
    }
    public function update($table, $data, $where) {
        if (self::$writeFail) { return false; }
        preg_match('/`change_id`=([0-9]+)/', $where, $change);
        preg_match('/`object_id`="([0-9]+)"/', $where, $invoice);
        check($table === 'ssb_hesabfa_webhook_change' && isset($change[1], $invoice[1]), 'Snapshot write is not scoped');
        check(isset(self::$rows[$change[1]]) && self::$rows[$change[1]]['object_id'] === (int) $invoice[1], 'Snapshot row missing');
        self::$rows[$change[1]]['invoice_item_codes'] = stripslashes($data['invoice_item_codes']);
        return true;
    }
    public function execute($sql) {
        check(strpos($sql, 'ALTER TABLE `ps_ssb_hesabfa_webhook_change`') === 0, 'Upgrade wrote unrelated data');
        self::$ddl++;
        if (strpos($sql, 'ADD INDEX') !== false) { self::$index = true; }
        else { self::$column = true; }
        return true;
    }
}
class HesabfaRetryPolicy { public static function shouldRetryUntilSuccess(...$args) { return false; } }
class Module {
    public static $calls = 0;
    public static function isInstalled($name) { self::$calls++; return false; }
}
class Ssbhesabfa {
    public static $logs = array();
    public static function addLegacyLog(...$args) { self::$logs[] = $args; }
}
class HesabfaApi {
    public static $codes = array(), $stocks = array(), $requested = array(), $fetches = 0;
    public static $fail = false, $invalid = '', $missing = array();
    public function invoiceGetById($ids) {
        self::$fetches++;
        $items = array_map(function ($code) { return (object) array('ItemCode' => $code); }, self::$codes);
        $invoice = (object) array('Id' => $ids[0], 'InvoiceItems' => $items);
        if (self::$invalid === 'identity') { $invoice->Id++; }
        if (self::$invalid === 'line') { $invoice->InvoiceItems = array((object) array()); }
        return (object) array('Success' => true, 'Result' => self::$invalid === 'empty' ? array() : array($invoice));
    }
    public function itemGetItemsByCodes($codes) {
        self::$requested[] = $codes;
        if (self::$fail) { return (object) array('Success' => false, 'ErrorMessage' => 'temporary lookup failure'); }
        $items = array();
        foreach ($codes as $code) {
            if (!in_array($code, self::$missing, true)) {
                $items[] = (object) array('Code' => $code, 'Stock' => self::$stocks[$code] ?? 0, 'Tag' => '{"id_product":1}');
            }
        }
        return (object) array('Success' => true, 'Result' => (object) array('List' => $items));
    }
}
require dirname(__DIR__) . '/classes/HesabfaWebhookChangeRepository.php';
require dirname(__DIR__) . '/classes/services/HesabfaWebhookService.php';
require dirname(__DIR__) . '/upgrade/upgrade-2.3.44.php';
class HistoryHandler {
    public $stocks = array(), $prices = array(), $invoices = 0, $fail = false;
    public function setInvoiceChanges($invoice) { $this->invoices++; return true; }
    public function setItemChanges($item, $remap, $ignore, $price = true) {
        if ($this->fail) { throw new Exception('interrupted item handler'); }
        $this->stocks[$item->Code] = $item->Stock;
        $this->prices[] = $price;
        return true;
    }
}
class HistoryProbe extends HesabfaWebhookService {
    public function apply($change) { return $this->processChange($change); }
}
function resetHistory() {
    Db::$rows = array(); Db::$readFail = Db::$writeFail = false;
    HesabfaApi::$codes = HesabfaApi::$stocks = HesabfaApi::$requested = HesabfaApi::$missing = array();
    HesabfaApi::$fetches = 0; HesabfaApi::$fail = false; HesabfaApi::$invalid = '';
    Module::$calls = 0; Ssbhesabfa::$logs = array();
}
function event($id, $extra = '', $action = 132, $api = false, $invoice = 150140) {
    $change = (object) array('Id' => $id, 'ObjectId' => $invoice, 'ObjectType' => 'Invoice', 'Action' => $action, 'Extra' => $extra, 'API' => $api);
    Db::$rows[$id] = array('change_id' => $id, 'object_id' => $invoice, 'payload' => json_encode($change), 'invoice_item_codes' => null);
    return $change;
}
function fails($callback, $message) {
    try { $callback(); } catch (Exception $e) { check(true, $message); return; }
    check(false, $message);
}

// Invoice 482: eleven old lines are replaced by twenty new lines.
resetHistory();
$old = array_map('strval', range(905104, 905114));
$new = array_map('strval', range(905116, 905135));
event(10, implode(',', $old));
$change = event(20, implode(',', $new));
HesabfaApi::$codes = $new;
foreach ($new as $code) { HesabfaApi::$stocks[$code] = 3; }
$handler = new HistoryHandler();
check((new HistoryProbe($handler))->apply($change), 'Replacement invoice failed');
check(count($handler->stocks) === 31, 'Removed invoice lines were omitted');
foreach ($old as $code) { check($handler->stocks[$code] === 0, 'Removed item retained stale stock'); }
foreach ($new as $code) { check($handler->stocks[$code] === 3, 'Current item stock was not read'); }
check(count(json_decode(Db::$rows[20]['invoice_item_codes'], true)) === 31, 'Snapshot lost old codes');

// Empty Extra and an emptied invoice still refresh previously seen items.
$change = event(30);
HesabfaApi::$codes = array(); HesabfaApi::$stocks = array();
$handler = new HistoryHandler();
(new HistoryProbe($handler))->apply($change);
check(count($handler->stocks) === 31 && array_sum($handler->stocks) === 0, 'Empty invoice lost removed items');

// API invoices with no Extra seed stock-only history, without financial/price callbacks.
resetHistory();
$change = event(1, '', 131, true);
HesabfaApi::$codes = array('000007'); HesabfaApi::$stocks = array('000007' => 4);
$handler = new HistoryHandler(); $probe = new HistoryProbe($handler);
$probe->apply($change);
check($handler->stocks['000007'] === 4 && $handler->invoices === 0 && Module::$calls === 0, 'API invoice replayed financial callbacks');
check($handler->prices === array(false), 'API inventory event enabled price updates');
$change = event(2, '000008');
HesabfaApi::$codes = array('000008'); HesabfaApi::$stocks = array('000008' => 2);
$probe->apply($change);
check($handler->stocks['000007'] === 0 && $handler->stocks['000008'] === 2, 'API-created old invoice line was forgotten');

// All invoice deletion families use history and never fetch a deleted invoice.
foreach (array(123, 133, 143, 153) as $action) {
    resetHistory(); event(1, '000007'); $change = event(2, '', $action, true);
    $handler = new HistoryHandler();
    (new HistoryProbe($handler))->apply($change);
    check(HesabfaApi::$fetches === 0 && $handler->stocks === array('000007' => 0), 'Deletion omitted stock or fetched the deleted invoice');
}

// Codes observed before a failed attempt survive another invoice edit before retry.
resetHistory(); event(1, '000001'); $change = event(2);
HesabfaApi::$codes = array('000002'); HesabfaApi::$fail = true;
$handler = new HistoryHandler(); $probe = new HistoryProbe($handler);
fails(function () use ($probe, $change) { $probe->apply($change); }, 'Failed lookup was accepted');
check($handler->stocks === array(), 'Failed lookup applied partial stock');
check(json_decode(Db::$rows[2]['invoice_item_codes'], true) === array('000001', '000002'), 'Failed attempt lost its durable codes');
HesabfaApi::$fail = false; HesabfaApi::$codes = array('000003');
$probe->apply($change);
check(count($handler->stocks) === 3 && isset($handler->stocks['000002']), 'Retry forgot an intermediate invoice line');

// Snapshot write/history errors stop processing before stock changes.
foreach (array('writeFail', 'readFail') as $flag) {
    resetHistory(); $change = event(1, '000001'); Db::${$flag} = true;
    $handler = new HistoryHandler(); $probe = new HistoryProbe($handler);
    fails(function () use ($probe, $change) { $probe->apply($change); }, 'Journal failure was accepted');
    check($handler->stocks === array() && HesabfaApi::$requested === array(), 'Journal failure changed stock');
}
resetHistory(); event(1, '000001'); $change = event(2, '', 133);
Db::$rows[1]['invoice_item_codes'] = 'invalid JSON';
$probe = new HistoryProbe(new HistoryHandler());
fails(function () use ($probe, $change) { $probe->apply($change); }, 'Malformed snapshot was accepted');

// Unrelated invoices, future events, duplicates and leading zeroes.
resetHistory(); event(1, '000001'); event(2, 'wrong-invoice', 132, false, 999);
event(99, 'future-code'); $change = event(3, '000001, ,000002');
HesabfaApi::$codes = array('000002');
$handler = new HistoryHandler(); (new HistoryProbe($handler))->apply($change);
check(array_keys($handler->stocks) === array('000001', '000002'), 'Invoice/event scope or code normalization failed');
check(HesabfaWebhookChangeRepository::normalizeItemCodes(array('0', '', ' 000001 ', '000001')) === array('0', '000001'), 'Zero/leading zero code was lost');
foreach (array('empty', 'identity', 'line') as $invalid) {
    resetHistory(); $change = event(1, '000001'); HesabfaApi::$invalid = $invalid;
    $handler = new HistoryHandler(); $probe = new HistoryProbe($handler);
    fails(function () use ($probe, $change) { $probe->apply($change); }, 'Invalid invoice response was accepted');
    check($handler->stocks === array(), 'Invalid invoice response changed stock');
}
resetHistory(); $change = event(1, '000001', 133); HesabfaApi::$missing = array('000001');
(new HistoryProbe(new HistoryHandler()))->apply($change);
check(count(Ssbhesabfa::$logs) === 1 && Ssbhesabfa::$logs[0][2] === 'WEBHOOK_INVOICE_ITEMS_MISSING', 'Genuinely deleted item was not logged');
resetHistory(); $change = event(1); $change->ObjectType = 'Product'; $change->API = true;
(new HistoryProbe(new HistoryHandler()))->apply($change);
check(HesabfaApi::$fetches === 0 && HesabfaApi::$requested === array(), 'API non-invoice behavior changed');

// The upgrade is repeatable and only adds metadata and its lookup index.
check(upgrade_module_2_3_44((object) array('name' => 'ssbhesabfa')), 'Upgrade failed');
check(Db::$column && Db::$index && Db::$ddl === 2, 'Upgrade omitted metadata/index');
check(upgrade_module_2_3_44((object) array('name' => 'ssbhesabfa')) && Db::$ddl === 2, 'Upgrade is not idempotent');
check(!upgrade_module_2_3_44((object) array('name' => 'wrong')), 'Upgrade accepted another module');
echo "PASS: $checks checks; removed/replaced items, API and empty invoices, deletions, durable retries, scope, validation and idempotent upgrade\n";
