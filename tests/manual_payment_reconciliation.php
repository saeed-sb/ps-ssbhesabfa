<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('_PS_VERSION_', '8.1.7');
define('_DB_NAME_', 'manual_payment_fixture');
class Db {
    public static $busy = false, $held = false;
    public static function getInstance() { return new self(); }
    public function getValue($query, $cache) {
        if (strpos($query, 'RELEASE_LOCK') !== false) { self::$held = false; return 1; }
        if (self::$busy || self::$held) { return 0; }
        self::$held = true; return 1;
    }
}
class Tools { public static $values; public static function getValue($k, $d = false) { return isset(self::$values[$k]) ? self::$values[$k] : $d; } }
class Configuration { public static $values; public static function get($k) { return isset(self::$values[$k]) ? self::$values[$k] : false; } }
class Validate { public static function isDateFormat($v) { return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $v); } }
class Currency { public $iso_code = 'IRR'; public function __construct($id) {} }
class HesabfaApi {
    public static $receipts = array(), $documents = array(), $writes = array(), $unavailable = false, $filteredCount = null;
    public static function response($r) { return json_decode(json_encode(array('Success' => true, 'Result' => $r))); }
    public function receiptGetReceipts($type, $query) {
        if (self::$unavailable) { return (object) array('Success' => false, 'ErrorMessage' => 'RATE_LIMIT'); }
        $list = array_slice(self::$receipts, $query['skip'], $query['take']);
        return self::response(array('FilteredCount' => self::$filteredCount === null ? count(self::$receipts) : self::$filteredCount, 'List' => $list));
    }
    public function invoiceSavePayment($number, $target, $date, $amount, $reference, $description, $fee, $project) {
        self::$writes[] = 'payment';
        self::$receipts[] = receiptFixture($amount, $target['bankCode'], $reference, 7001);
        return self::response(array('Number' => 7001));
    }
    public function documentGetDocuments($query) {
        return self::response(array('FilteredCount' => count(self::$documents), 'List' => self::$documents));
    }
    public function documentGet($number) { return self::response(self::$documents[0]); }
    public function documentSave($document) {
        self::$writes[] = 'income';
        self::$documents[] = documentFixture($document['debit'], 9001);
        return self::response(array('Number' => 9001));
    }
}
require dirname(__DIR__) . '/classes/traits/HesabfaPaymentTrait.php';
class ManualPaymentSubject {
    use HesabfaPaymentTrait;
    const HESABFA_DEFAULT_BANK_ACCOUNT_PATH = 'Assets:Bank';
    public $operations = array(), $logs = array();
    public function submit() { return $this->processManualGatewayPayment(); }
    public function checkReceipt($operation) { return $this->verifyManualPaymentReceipt(new HesabfaApi(), 42, 33, 'fixture-tara-transaction', $this->getPaymentFeeBreakdown('TEST', 200000000), $operation); }
    public function l($v) { return $v; }
    public static function getPriceInHesabfaDefaultCurrency($v) { return $v * 10; }
    public function normalizeHesabfaResponse($r) { return $r; }
    public function isHesabfaSuccess($r) { return !empty($r->Success); }
    public function getHesabfaErrorMessage($r) { return isset($r->ErrorMessage) ? $r->ErrorMessage : ''; }
    public function renderTemplateText($s, $v) { return $s; }
    public function buildOperationKey($type, $parts) { return $type; }
    public function getCompletedOperation($k) { return isset($this->operations[$k]) && $this->operations[$k]['status'] === 'success' ? $this->operations[$k] : false; }
    public function startOperation($k, $type, $objectType, $id) { $this->operations[$k] = array('status' => 'pending'); }
    public function finishOperation($k, $status, $message, $ref = null) { $this->operations[$k] = array('status' => $status, 'external_reference' => $ref); }
    public function addFollowUpIssue($type, $message, $objectType, $id, $key, $severity) { $this->logs[] = $message; }
    public static function addModuleLog($message, $level, $code, $type, $id) {}
    public static function addLegacyLog($message, $level, $code, $type, $id, $force) {}
}
require dirname(__DIR__) . '/classes/services/HesabfaPaymentFeeService.php';
function receiptFixture($amount = 191938580, $bank = 33, $ref = 'fixture-tara-transaction', $number = 7001) {
    return array('Number' => $number, 'Invoice' => array('Number' => '42', 'InvoiceType' => 0), 'Transactions' => array(array('Reference' => $ref, 'Bank' => array('Code' => $bank), 'Amount' => $amount, 'TransactionFee' => 0)));
}
function documentFixture($amount = 3661420, $number = 9001) {
    return array('Number' => $number, 'Debit' => $amount, 'Credit' => $amount, 'Transactions' => array(
        array('Type' => 0, 'BankCode' => '0033', 'AccountPath' => 'Assets:Bank', 'ContactCode' => '', 'Amount' => $amount),
        array('Type' => 1, 'BankCode' => '', 'AccountPath' => 'Income : Tara', 'ContactCode' => '', 'Amount' => $amount),
    ));
}
function freshSubject() {
    Configuration::$values = array('TEST' => '0033', 'TEST_FEE_TYPE' => 'percent', 'TEST_FEE_PAYER' => 'customer', 'TEST_CUSTOMER_CHARGE_PERCENT' => 4.2, 'TEST_FEE_PERCENT' => 2.2, 'TEST_INCOME_ACCOUNT_PATH' => 'Income:Tara', 'SSBHESABFA_MANUAL_PAYMENT_DESCRIPTION_TEMPLATE' => 'Manual payment', 'SSBHESABFA_MANUAL_FEE_INCOME_DOCUMENT_DESCRIPTION_TEMPLATE' => 'Income for invoice 42');
    Tools::$values = array('SSBHESABFA_MANUAL_PAYMENT_METHOD' => 'TEST', 'SSBHESABFA_MANUAL_INVOICE_NUMBER' => 42, 'SSBHESABFA_MANUAL_GATEWAY_PAID_AMOUNT' => 20000000, 'SSBHESABFA_MANUAL_TRANSACTION_NUMBER' => 'fixture-tara-transaction', 'SSBHESABFA_MANUAL_ORDER_REFERENCE' => '', 'SSBHESABFA_MANUAL_PAYMENT_DATE' => '2026-10-01');
    HesabfaApi::$receipts = HesabfaApi::$documents = HesabfaApi::$writes = array();
    HesabfaApi::$unavailable = false; HesabfaApi::$filteredCount = null;
    Db::$busy = Db::$held = false;
    $s = new ManualPaymentSubject();
    $s->operations = array('manual_invoice_payment' => array('status' => 'success', 'external_reference' => '42'), 'manual_payment_fee_income_document' => array('status' => 'success', 'external_reference' => ''));
    return $s;
}
function check($ok, $label) { if (!$ok) { throw new RuntimeException($label); } echo 'PASS: ', $label, "\n"; }
$s = freshSubject(); $r = $s->submit();
check($r['success'] && HesabfaApi::$writes === array('payment', 'income'), 'Deleted receipt and income document are restored once');
check($s->operations['manual_invoice_payment']['external_reference'] === 'receipt:7001' && $s->operations['manual_payment_fee_income_document']['external_reference'] === 9001, 'Exact external receipt and document numbers are retained');
HesabfaApi::$writes = array(); $r = $s->submit();
check($r['success'] && count(HesabfaApi::$writes) === 0 && strpos($r['message'], 'already exists') !== false, 'Live retry verifies existing records and reports no new payment');
$s = freshSubject(); HesabfaApi::$receipts = array(receiptFixture(19193858)); $r = $s->submit();
check(!$r['success'] && count(HesabfaApi::$writes) === 0 && strpos($r['message'], 'different payment details') !== false, 'Changed amount on an existing transaction is blocked');
$s = freshSubject(); HesabfaApi::$unavailable = true; $r = $s->submit();
check(!$r['success'] && count(HesabfaApi::$writes) === 0 && $s->operations['manual_invoice_payment']['status'] === 'success', 'Unavailable verification does not reset success or send money');
$s = freshSubject(); HesabfaApi::$receipts = array(receiptFixture()); $r = $s->submit();
check($r['success'] && HesabfaApi::$writes === array('income'), 'Missing income document is restored without a second payment');
$s = freshSubject(); HesabfaApi::$receipts = array(receiptFixture(191938580, 26)); $r = $s->submit();
check(!$r['success'] && count(HesabfaApi::$writes) === 0, 'Changed bank account on an existing transaction is blocked');
$s = freshSubject(); $s->operations['manual_invoice_payment']['external_reference'] = 'receipt:7001'; HesabfaApi::$receipts = array(receiptFixture(191938580, 33, 'edited')); $r = $s->submit();
check(!$r['success'] && count(HesabfaApi::$writes) === 0, 'An edited stored receipt is not mistaken for a deletion');
$s = freshSubject(); HesabfaApi::$receipts = array_fill(0, 100, receiptFixture(1, 26, 'other', 1)); HesabfaApi::$receipts[] = receiptFixture();
check($s->checkReceipt(array('external_reference' => '42'))['state'] === 'exists', 'Receipt reconciliation scans all pages');
$s = freshSubject(); HesabfaApi::$filteredCount = 1;
check($s->checkReceipt(array('external_reference' => '42'))['state'] === 'error', 'Incomplete pagination cannot authorize restoration');
$s = freshSubject(); HesabfaApi::$receipts = array(receiptFixture()); HesabfaApi::$documents = array(documentFixture(366142)); $r = $s->submit();
check(!$r['success'] && count(HesabfaApi::$writes) === 0, 'Different existing fee income amount is blocked');
$s = freshSubject(); Db::$busy = true; $r = $s->submit();
check(!$r['success'] && count(HesabfaApi::$writes) === 0 && strpos($r['message'], 'being processed') !== false, 'Simultaneous form submission does not create another payment');
$s = freshSubject(); HesabfaApi::$unavailable = true; $s->submit();
check(!Db::$held, 'Invoice lock is released after a failed verification');
echo "All manual payment reconciliation checks passed.\n";
