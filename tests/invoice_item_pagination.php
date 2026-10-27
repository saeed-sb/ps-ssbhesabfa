<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
define('_PS_VERSION_', '8.1.7');
function check($ok, $message) { if (!$ok) { throw new Exception($message); } }
class Configuration { public static function get($key) { return false; } }
class HesabfaSecurity { public static function isOperational() { return true; } }
class HesabfaRequestUniqueId { public static function hasContext() { return false; } }
class HesabfaRetryPolicy { public static function shouldRetryUntilSuccess(...$args) { return false; } }
class Module { public static function isInstalled($name) { return false; } }
class HesabfaWebhookChangeRepository {
    public static function getInvoiceItemCodes($invoiceId, $changeId) { return array(); }
    public static function normalizeItemCodes($codes) { return array_values(array_unique(array_filter(array_map('trim', $codes), 'strlen'))); }
    public static function saveInvoiceItemCodes($changeId, $invoiceId, $codes) { return true; }
}
class Ssbhesabfa {
    public static $logs = array();
    public static function addLegacyLog(...$args) { self::$logs[] = $args; }
}
class HesabfaHttpClient {
    public static $items = array(), $requests = array(), $pageLimit = 100, $mode = '';
    public function post($method, array $data) {
        check($method === 'item/getitems', 'Unexpected API method');
        $q = $data['queryInfo'];
        self::$requests[] = $q;
        $codes = $q['Filters'][0]['Value'];
        $filtered = array_values(array_filter(self::$items, function ($item) use ($codes) {
            return in_array($item->Code, $codes, true);
        }));
        $skip = isset($q['Skip']) ? (int) $q['Skip'] : 0;
        $take = min(self::$pageLimit, isset($q['Take']) ? (int) $q['Take'] : 10);
        if (self::$mode === 'failed_later_batch' && count(self::$requests) > 1) {
            return (object) array('Success' => false, 'ErrorCode' => 'RATE_LIMIT', 'ErrorMessage' => 'Rate limit reached.');
        }
        $page = array_slice($filtered, $skip, $take);
        if (self::$mode === 'empty_later_page' && $skip > 0) { $page = array(); }
        if (self::$mode === 'repeated_page' && $skip > 0) { $page = array_slice($filtered, 0, $take); }
        $result = (object) array('List' => $page, 'FilteredCount' => count($filtered));
        if (self::$mode === 'invalid_list') { $result->List = null; }
        return (object) array('Success' => true, 'ErrorCode' => 0, 'ErrorMessage' => '', 'Result' => $result);
    }
}
require dirname(__DIR__) . '/classes/HesabfaApiResponse.php';
require dirname(__DIR__) . '/classes/HesabfaAPI.php';
require dirname(__DIR__) . '/classes/HesabfaWebhook.php';
require dirname(__DIR__) . '/classes/services/HesabfaWebhookService.php';
class InvoiceHandler {
    public $codes = array();
    public function setItemChanges($item, ...$flags) { $this->codes[] = $item->Code; return true; }
}
class InvoiceServiceProbe extends HesabfaWebhookService {
    public function applyInvoice($codes) {
        return $this->processChange((object) array('API' => false, 'Id' => 7, 'ObjectType' => 'Invoice', 'Action' => 123, 'ObjectId' => 99, 'Extra' => implode(',', $codes)));
    }
}
function seed($count, $mode = '', $limit = 100) {
    HesabfaHttpClient::$items = array();
    HesabfaHttpClient::$requests = array();
    HesabfaHttpClient::$mode = $mode;
    HesabfaHttpClient::$pageLimit = $limit;
    Ssbhesabfa::$logs = array();
    for ($i = 1; $i <= $count; $i++) {
        $code = str_pad((string) $i, 6, '0', STR_PAD_LEFT);
        HesabfaHttpClient::$items[] = (object) array('Code' => $code, 'Tag' => json_encode(array('id_product' => $i)), 'Stock' => $i);
    }
    return array_map(function ($item) { return $item->Code; }, HesabfaHttpClient::$items);
}
$api = new HesabfaApi();
$codes = seed(28);
$baseline = $api->itemGetItems(array('Filters' => array(array('Property' => 'Code', 'Operator' => 'in', 'Value' => $codes))));
check(count($baseline->Result->List) === 10, 'Fixture no longer reproduces the ten-row default');
HesabfaHttpClient::$requests = array();
$result = $api->itemGetItemsByCodes($codes);
check($result->Success && count($result->Result->List) === 28, 'A 28-item invoice was truncated');
check(HesabfaHttpClient::$requests[0]['Skip'] === 0 && HesabfaHttpClient::$requests[0]['Take'] === 28, 'Pagination was not explicit');
$handler = new InvoiceHandler();
check((new InvoiceServiceProbe($handler))->applyInvoice($codes), 'Invoice processing failed');
check($handler->codes === $codes && count(Ssbhesabfa::$logs) === 0, 'Invoice skipped valid items or logged false missing items');
check(count((new HesabfaWebhook(false))->getObjectsByCodeList($codes)) === 28, 'Legacy code-list lookup was truncated');
$codes = seed(205);
$result = $api->itemGetItemsByCodes($codes);
check($result->Success && count($result->Result->List) === 205 && count(HesabfaHttpClient::$requests) === 3, 'Invoices over 100 items were truncated');
foreach (HesabfaHttpClient::$requests as $q) { check($q['Take'] <= 100 && count($q['Filters'][0]['Value']) <= 100, 'API batch limit exceeded'); }
$codes = seed(28, '', 10);
$result = $api->itemGetItemsByCodes($codes);
check($result->Success && count($result->Result->List) === 28 && count(HesabfaHttpClient::$requests) === 3, 'Server-side page limits were not followed');
$codes = seed(205, 'failed_later_batch');
$handler = new InvoiceHandler();
try { (new InvoiceServiceProbe($handler))->applyInvoice($codes); throw new Exception('Later batch failure was accepted'); }
catch (Exception $e) { check($e->getMessage() !== 'Later batch failure was accepted', $e->getMessage()); }
check(count($handler->codes) === 0 && count(Ssbhesabfa::$logs) === 0, 'A partial API response applied stock or logged deleted items');
foreach (array('empty_later_page', 'repeated_page', 'invalid_list') as $mode) {
    $codes = seed(28, $mode, 10);
    $result = $api->itemGetItemsByCodes($codes);
    check(!$result->Success && $result->ErrorCode === 'INCOMPLETE_ITEM_LIST', 'Malformed or stalled pagination was accepted: ' . $mode);
}
$codes = seed(2);
$result = $api->itemGetItemsByCodes(array('000001', '000001', ' ', '000002', '999999'));
check($result->Success && count($result->Result->List) === 2, 'Duplicates, blanks or missing codes broke lookup');
check($result->Result->List[0]->Code === '000001', 'Leading zeros were lost');
$handler = new InvoiceHandler();
check((new InvoiceServiceProbe($handler))->applyInvoice(array_merge($codes, array('999999'))), 'A genuinely deleted item blocked valid items');
check(count($handler->codes) === 2 && count(Ssbhesabfa::$logs) === 1 && Ssbhesabfa::$logs[0][2] === 'WEBHOOK_INVOICE_ITEMS_MISSING', 'Actual missing items were not logged');
HesabfaHttpClient::$requests = array();
$result = $api->itemGetItemsByCodes(array());
check($result->Success && count($result->Result->List) === 0 && count(HesabfaHttpClient::$requests) === 0, 'Empty input queried the entire catalog');
echo "PASS: 28-item invoice, legacy lookup, 205-item invoice, capped pages, partial failure without stock writes, malformed pages, missing items, leading zeros and empty input\n";
