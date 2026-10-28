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
    public static $deletionRows = null, $deletionSql = '';
    public static function getInstance() { return new self(); }
    public function executeS($sql, $array = true, $cache = true) {
        check($cache === false, 'History/schema lookup used cached data');
        if (strpos($sql, 'SHOW COLUMNS') === 0) { return self::$column ? array(array('Field' => 'invoice_item_codes')) : array(); }
        if (strpos($sql, 'SHOW INDEX') === 0) { return self::$index ? array(array('Key_name' => 'idx_invoice_history')) : array(); }
        if (self::$readFail) { return false; }
        check(strpos($sql, '`object_type`="Invoice"') !== false, 'History is not invoice scoped');
        preg_match('/`object_id`="([0-9]+)"/', $sql, $invoice);
        if (strpos($sql, 'SELECT `change_id`, `object_id`, `action_code`, `payload`') === 0) {
            self::$deletionSql = $sql;
            preg_match('/`change_id`>([0-9]+)/', $sql, $change);
            preg_match('/`action_code`=([0-9]+)/', $sql, $action);
            check(isset($invoice[1], $change[1], $action[1]), 'Deletion lookup omitted invoice/action/event scope');
            check(strpos($sql, '`status` IN ("pending","failed","running","done")') !== false, 'Deletion lookup trusted dead events');
            check(strpos($sql, 'ORDER BY `change_id` ASC LIMIT 1') !== false, 'Deletion lookup omitted deterministic bound');
            if (self::$deletionRows !== null) { return self::$deletionRows; }
            $rows = array_filter(self::$rows, function ($row) use ($invoice, $change, $action) {
                return $row['object_type'] === 'Invoice' && $row['object_id'] === (int) $invoice[1]
                    && $row['change_id'] > (int) $change[1] && $row['action_code'] === (int) $action[1]
                    && in_array($row['status'], array('pending', 'failed', 'running', 'done'), true);
            });
            usort($rows, function ($a, $b) { return $a['change_id'] <=> $b['change_id']; });
            return array_slice($rows, 0, 1);
        }
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
    public static $fail = false, $invalid = '', $missing = array(), $lines = null;
    public function invoiceGetById($ids) {
        self::$fetches++;
        $items = array_map(function ($code) { return (object) array('ItemCode' => $code); }, self::$codes);
        $invoice = (object) array('Id' => $ids[0], 'InvoiceItems' => self::$lines === null ? $items : self::$lines);
        if (self::$invalid === 'identity') { $invoice->Id++; }
        if (self::$invalid === 'line') { $invoice->InvoiceItems = array((object) array()); }
        if (self::$invalid === 'missing') { return (object) array('Success' => true); }
        if (self::$invalid === 'null') { return (object) array('Success' => true, 'Result' => null); }
        if (self::$invalid === 'object') { return (object) array('Success' => true, 'Result' => $invoice); }
        if (self::$invalid === 'multiple') { return (object) array('Success' => true, 'Result' => array($invoice, $invoice)); }
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
    Db::$deletionRows = null; Db::$deletionSql = '';
    HesabfaApi::$codes = HesabfaApi::$stocks = HesabfaApi::$requested = HesabfaApi::$missing = array();
    HesabfaApi::$fetches = 0; HesabfaApi::$fail = false; HesabfaApi::$invalid = '';
    HesabfaApi::$lines = null;
    Module::$calls = 0; Ssbhesabfa::$logs = array();
}
function event($id, $extra = '', $action = 132, $api = false, $invoice = 150140) {
    $change = (object) array('Id' => $id, 'ObjectId' => $invoice, 'ObjectType' => 'Invoice', 'Action' => $action, 'Extra' => $extra, 'API' => $api);
    Db::$rows[$id] = array('change_id' => $id, 'object_type' => 'Invoice', 'object_id' => $invoice,
        'action_code' => $action, 'status' => 'pending', 'payload' => json_encode($change), 'invoice_item_codes' => null);
    return $change;
}
function fails($callback, $message) {
    try { $callback(); } catch (Exception $e) { check(true, $message); return; }
    check(false, $message);
}
function sortedCodes($codes) { sort($codes, SORT_STRING); return $codes; }

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

// Live invoice 6481: null top-level codes, four embedded items, only two in Extra.
resetHistory();
$change = event(2251866, '904688,904435', 121, false, 150888);
$liveCodes = array('904688', '904435', '904852', '905295');
HesabfaApi::$lines = array_map(function ($code) {
    return (object) array('ItemCode' => null, 'Item' => (object) array('Code' => $code));
}, $liveCodes);
$handler = new HistoryHandler();
(new HistoryProbe($handler))->apply($change);
check(HesabfaApi::$requested === array($liveCodes), 'Embedded invoice item codes were omitted');
check(json_decode(Db::$rows[2251866]['invoice_item_codes'], true) === $liveCodes, 'Embedded codes were not persisted');
check(count($handler->stocks) === 4 && $handler->invoices === 1, 'Embedded invoice did not complete normal callbacks');

// Direct codes remain authoritative; missing/blank direct codes use the same line's Item.Code.
$validLines = array(
    array((object) array('Item' => (object) array('Code' => '000007')), '000007'),
    array((object) array('ItemCode' => '', 'Item' => (object) array('Code' => '000007')), '000007'),
    array((object) array('ItemCode' => '  ', 'Item' => (object) array('Code' => ' 000007 ')), '000007'),
    array((object) array('ItemCode' => '000008', 'Item' => (object) array('Code' => '000009')), '000008'),
    array((object) array('ItemCode' => 0), '0'),
    array((object) array('ItemCode' => null, 'Item' => (object) array('Code' => 0)), '0'),
);
foreach ($validLines as $case) {
    resetHistory(); $change = event(1);
    HesabfaApi::$lines = array($case[0]);
    $handler = new HistoryHandler();
    (new HistoryProbe($handler))->apply($change);
    check(HesabfaApi::$requested === array(array($case[1])), 'Invoice code fallback or leading zero preservation failed');
}

// No guessing from Extra: malformed or missing codes still stop before every callback/write.
$invalidLines = array(
    null, array('ItemCode' => '000007'), (object) array(),
    (object) array('ItemCode' => null, 'Item' => (object) array()),
    (object) array('ItemCode' => '', 'Item' => (object) array('Code' => '  ')),
    (object) array('ItemCode' => null, 'Item' => array('Code' => '000007')),
    (object) array('ItemCode' => false, 'Item' => (object) array('Code' => '000007')),
    (object) array('ItemCode' => array('000007')),
    (object) array('ItemCode' => null, 'Item' => (object) array('Code' => false)),
    (object) array('ItemCode' => null, 'Item' => (object) array('Code' => 7.5)),
);
foreach ($invalidLines as $line) {
    resetHistory(); $change = event(1, '000001');
    HesabfaApi::$lines = array((object) array('ItemCode' => '000002'), $line);
    $handler = new HistoryHandler(); $probe = new HistoryProbe($handler);
    fails(function () use ($probe, $change) { $probe->apply($change); }, 'Malformed line was accepted');
    check($handler->stocks === array() && $handler->invoices === 0 && HesabfaApi::$requested === array(), 'Malformed line applied partial changes');
    check(Db::$rows[1]['invoice_item_codes'] === null, 'Malformed invoice saved a partial snapshot');
}

// Later-deletion evidence is scoped to an immutable invoice ID and its action family.
foreach (array(121 => 123, 122 => 123, 131 => 133, 132 => 133,
    141 => 143, 142 => 143, 151 => 153, 152 => 153) as $action => $deletion) {
    resetHistory(); event(10, '', $action); event(20, '', $deletion);
    check(HesabfaWebhookChangeRepository::getLaterInvoiceDeletionId(150140, 10, $action) === 20, 'Same-family deletion was not recognized');
}
foreach (array('pending', 'failed', 'running', 'done') as $status) {
    resetHistory(); event(20, '', 143); Db::$rows[20]['status'] = $status;
    check(HesabfaWebhookChangeRepository::getLaterInvoiceDeletionId(150140, 10, 141) === 20, 'Valid deletion status was not recognized');
}
resetHistory();
event(9, '', 143);                         // Earlier deletion.
event(30, '', 143, false, 999);           // Another internal invoice ID.
event(40, '', 133);                       // Another invoice family.
event(50, '', 143); Db::$rows[50]['status'] = 'dead';
event(60, '', 143); Db::$rows[60]['object_type'] = 'Product';
check(HesabfaWebhookChangeRepository::getLaterInvoiceDeletionId(150140, 20, 141) === null, 'Unrelated or ignored deletion was trusted');
event(900, '', 143); event(901, '', 143);
check(HesabfaWebhookChangeRepository::getLaterInvoiceDeletionId(150140, 20, 141) === 900, 'Evidence outside the processing batch was lost or a later candidate was chosen');
resetHistory();
check(HesabfaWebhookChangeRepository::getLaterInvoiceDeletionId(150140, 10, 123) === null && Db::$deletionSql === '', 'Deletion/unknown action queried for fallback evidence');
Db::$readFail = true;
fails(function () { HesabfaWebhookChangeRepository::getLaterInvoiceDeletionId(150140, 10, 141); }, 'Deletion lookup DB failure was accepted');
resetHistory(); event(20, '', 143);
$validDeletionRow = Db::$rows[20];
$corruptDeletionRows = array(false, array($validDeletionRow, $validDeletionRow));
foreach (array('Id' => 21, 'ObjectId' => 999, 'Action' => 133, 'ObjectType' => 'Product') as $field => $value) {
    $row = $validDeletionRow; $payload = json_decode($row['payload']); $payload->$field = $value;
    $row['payload'] = json_encode($payload); $corruptDeletionRows[] = array($row);
}
foreach (array('payload' => 'invalid JSON', 'change_id' => 9, 'object_id' => 999, 'action_code' => 133) as $field => $value) {
    $row = $validDeletionRow; $row[$field] = $value; $corruptDeletionRows[] = array($row);
}
$row = $validDeletionRow; $row['payload'] = '{}'; $corruptDeletionRows[] = array($row);
$row = $validDeletionRow; $row['change_id'] = true; $corruptDeletionRows[] = array($row);
$row = $validDeletionRow; $payload = json_decode($row['payload']); $payload->Id = 20.5;
$row['payload'] = json_encode($payload); $corruptDeletionRows[] = array($row);
foreach ($corruptDeletionRows as $rows) {
    Db::$deletionRows = $rows;
    fails(function () { HesabfaWebhookChangeRepository::getLaterInvoiceDeletionId(150140, 10, 141); }, 'Corrupt deletion evidence was accepted');
}

// Live return 6487 was created and deleted before the create event could be replayed.
resetHistory(); event(2252535, '000007', 142, false, 150924);
$change = event(2252536, '009645', 141, false, 150924);
$deletion = event(2252537, '009646', 143, false, 150924);
HesabfaApi::$invalid = 'empty'; HesabfaApi::$stocks = array('000007' => 0, '009645' => 3, '009646' => 4);
$handler = new HistoryHandler(); $probe = new HistoryProbe($handler);
check($probe->apply($change), 'Deleted-before-replay return was blocked');
check($handler->stocks == array('000007' => 0, '009645' => 3), 'Deleted return lost cumulative history or imported future codes');
check(sortedCodes(json_decode(Db::$rows[2252536]['invoice_item_codes'], true)) === array('000007', '009645'), 'Deleted return did not retain its scoped snapshot');
check(Db::$rows[2252537]['invoice_item_codes'] === null && Db::$rows[2252537]['status'] === 'pending', 'Fallback prematurely processed the later deletion');
check($handler->invoices === 0 && Module::$calls === 0, 'Vanished invoice replayed financial callbacks');
check(count(Ssbhesabfa::$logs) === 1 && Ssbhesabfa::$logs[0][2] === 'WEBHOOK_INVOICE_DELETED_BEFORE_REPLAY', 'Deleted-before-replay completion was not audited');
check(strpos(Ssbhesabfa::$logs[0][0], '2252537') !== false, 'Audit omitted the proving deletion ID');
$probe->apply($deletion);
check(HesabfaApi::$fetches === 1, 'Later deletion fetched the vanished invoice');
check($handler->stocks == array('000007' => 0, '009645' => 3, '009646' => 4), 'Later deletion lost cumulative inventory refresh');
check(sortedCodes(json_decode(Db::$rows[2252537]['invoice_item_codes'], true)) === array('000007', '009645', '009646'), 'Later deletion did not inherit the predecessor snapshot');

// Sales creates/edits must not invoke loyalty add/update after a proved deletion.
foreach (array(121, 122) as $action) {
    resetHistory(); $change = event(10, '000007', $action); event(20, '', 123);
    HesabfaApi::$invalid = 'empty';
    $handler = new HistoryHandler(); (new HistoryProbe($handler))->apply($change);
    check($handler->invoices === 0 && Module::$calls === 0 && count($handler->stocks) === 1, 'Deleted sales invoice replayed invoice/loyalty callbacks');
}
resetHistory(); $change = event(10, '000007', 141, true); event(20, '', 143, true);
HesabfaApi::$invalid = 'empty';
$handler = new HistoryHandler(); (new HistoryProbe($handler))->apply($change);
check($handler->invoices === 0 && Module::$calls === 0 && $handler->prices === array(false), 'API fallback enabled financial or price callbacks');

// Only an explicitly empty response qualifies; malformed or mismatched data stays blocking.
foreach (array('missing', 'null', 'object', 'multiple', 'identity', 'line') as $invalid) {
    resetHistory(); $change = event(10, '000007', 141); event(20, '', 143);
    HesabfaApi::$invalid = $invalid;
    $handler = new HistoryHandler(); $probe = new HistoryProbe($handler);
    fails(function () use ($probe, $change) { $probe->apply($change); }, 'Invalid invoice response used deletion fallback');
    check($handler->stocks === array() && $handler->invoices === 0 && Db::$rows[10]['invoice_item_codes'] === null, 'Invalid response applied partial fallback');
}
resetHistory(); $change = event(10, '000007', 141); event(20, '', 143);
HesabfaApi::$codes = array('000008');
$handler = new HistoryHandler(); (new HistoryProbe($handler))->apply($change);
check($handler->invoices === 1 && Db::$deletionSql === '' && Ssbhesabfa::$logs === array(), 'Valid live response was unnecessarily superseded');

// A failed stock lookup keeps its durable codes; retry cannot consume the future event.
resetHistory(); $change = event(10, '000007', 141); event(20, '000008', 143);
HesabfaApi::$invalid = 'empty'; HesabfaApi::$fail = true;
$handler = new HistoryHandler(); $probe = new HistoryProbe($handler);
fails(function () use ($probe, $change) { $probe->apply($change); }, 'Deleted-invoice stock lookup failure was accepted');
check($handler->stocks === array() && Ssbhesabfa::$logs === array(), 'Failed refresh logged completion or applied partial stock');
check(json_decode(Db::$rows[10]['invoice_item_codes'], true) === array('000007') && Db::$rows[20]['invoice_item_codes'] === null, 'Failed fallback lost its own snapshot or changed the later event');
HesabfaApi::$fail = false; $change->Extra = '';
check($probe->apply($change) && $handler->stocks === array('000007' => 0), 'Fallback retry lost durable codes');
resetHistory(); $change = event(10, '000007', 141); event(20, '', 143);
HesabfaApi::$invalid = 'empty'; Db::$writeFail = true;
$handler = new HistoryHandler(); $probe = new HistoryProbe($handler);
fails(function () use ($probe, $change) { $probe->apply($change); }, 'Failed fallback snapshot was accepted');
check($handler->stocks === array() && HesabfaApi::$requested === array(), 'Snapshot failure changed stock');

// The upgrade is repeatable and only adds metadata and its lookup index.
check(upgrade_module_2_3_44((object) array('name' => 'ssbhesabfa')), 'Upgrade failed');
check(Db::$column && Db::$index && Db::$ddl === 2, 'Upgrade omitted metadata/index');
check(upgrade_module_2_3_44((object) array('name' => 'ssbhesabfa')) && Db::$ddl === 2, 'Upgrade is not idempotent');
check(!upgrade_module_2_3_44((object) array('name' => 'wrong')), 'Upgrade accepted another module');
echo "PASS: $checks checks; removed/replaced items, API and empty invoices, deletions, durable retries, scope, validation and idempotent upgrade\n";
