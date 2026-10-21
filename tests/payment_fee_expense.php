<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('_PS_VERSION_', '8.1.7');
define('_DB_NAME_', 'fee_expense_fixture');
class Db { public static function getInstance() { return new self(); } public function getValue($query, $cache) { return 1; } }
class Configuration {
    public static $values = array();
    public static function get($k) { return array_key_exists($k, self::$values) ? self::$values[$k] : false; }
    public static function updateValue($k, $v) { self::$values[$k] = $v; return true; }
}
class Tools {
    public static $values = array();
    public static function getValue($k, $d = false) { return array_key_exists($k, self::$values) ? self::$values[$k] : $d; }
    public static function substr($v, $s, $n) { return mb_substr($v, $s, $n); }
}
class Currency { public $iso_code = 'IRR'; public function __construct($id) {} }
class Validate {
    public static function isLoadedObject($v) { return is_object($v); }
    public static function isDateFormat($v) { return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $v); }
}
class Module { public static $hub; public static function getInstanceByName($v) { return self::$hub; } }
class HubFixture { public $snapshot; public function getBalancePaymentFeeSnapshot($p, $o) { return $this->snapshot; } }
class Order {
    public $id, $module = 'ssbpaymenthub', $reference = 'ORDER', $id_currency = 1;
    public function __construct($id) { $this->id = $id; }
}
class OrderPayment { public static $payment; public static function getByOrderReference($v) { return array(self::$payment); } }
class HesabfaLock { public static function acquire($v) { return true; } public static function release($v) {} }
class HesabfaSecurity { public static function isOperational() { return true; } }
class HesabfaOperationRepository { public static function releaseOwned() {} }
class HesabfaApiResponse { public static function normalize($r) { return $r; } }
class HesabfaApi {
    public static $documents = array(), $receipts = array(), $writes = array(), $fail = false, $readFail = false, $missingNumber = false;
    public static function ok($r) { return json_decode(json_encode(array('Success' => true, 'Result' => $r))); }
    public function invoiceSavePayment($invoice, $target, $date, $amount, $ref, $desc, $fee, $project) {
        self::$writes[] = 'payment';
        self::$receipts[] = array('Number' => 7001, 'Invoice' => array('Number' => $invoice, 'InvoiceType' => 0),
            'Transactions' => array(array('Bank' => array('Code' => $target['bankCode']), 'Reference' => $ref, 'Amount' => $amount, 'TransactionFee' => $fee)));
        return self::ok(array('Number' => 7001));
    }
    public function receiptGetReceipts($type, $query) { return self::ok(array('FilteredCount' => count(self::$receipts), 'List' => self::$receipts)); }
    public function documentSave($d) {
        self::$writes[] = 'expense';
        if (self::$fail) { return (object) array('Success' => false, 'ErrorCode' => 199, 'ErrorMessage' => 'fixture failure'); }
        $id = 9001 + count(self::$documents);
        $doc = array('Number' => $id, 'Date' => $d['date'], 'Debit' => $d['debit'], 'Credit' => $d['credit'], 'Transactions' => array());
        foreach ($d['transactions'] as $t) {
            $v = array('BankCode' => '', 'ContactCode' => '');
            foreach ($t as $key => $value) { $v[ucfirst($key)] = $value; }
            $doc['Transactions'][] = $v;
        }
        self::$documents[$id] = $doc;
        return self::ok(self::$missingNumber ? array() : array('Number' => $id));
    }
    public function documentGet($number) {
        if (self::$readFail) { return (object) array('Success' => false, 'ErrorCode' => 199); }
        return isset(self::$documents[$number]) ? self::ok(self::$documents[$number]) : (object) array('Success' => false, 'ErrorCode' => 112);
    }
}
require dirname(__DIR__) . '/classes/services/HesabfaPaymentFeeService.php';
require dirname(__DIR__) . '/classes/traits/HesabfaPaymentTrait.php';
require dirname(__DIR__) . '/classes/traits/HesabfaAdminUiTrait.php';
class FeeSubject {
    use HesabfaPaymentTrait, HesabfaAdminUiTrait;
    const HESABFA_DEFAULT_BANK_ACCOUNT_PATH = 'Assets:Bank';
    public $operations = array(), $claim = true, $issues = array();
    public function getPaymentConfigByOrderPayment($id, $method) { return array('configuration_name' => 'SSBHESABFA_PAYMENT_METHOD_fixture', 'bank_code' => '0019'); }
    public function getPaymentMethodsName() { return array(array('id' => 'SSBHESABFA_PAYMENT_METHOD_fixture', 'name' => 'DigiPay')); }
    public function getInvoiceCodeByOrderId($id) { return 2080; }
    public function getOrderPriceInHesabfaDefaultCurrency($v, $id) { return $v * 10; }
    public static function getPriceInHesabfaDefaultCurrency($v) { return $v * 10; }
    public function l($v) { return $v; }
    public function renderTemplateText($t, $values) { foreach ($values as $k => $v) { $t = str_replace('{'.$k.'}', (string) $v, $t); } return $t; }
    public function normalizeHesabfaResponse($r) { return $r; }
    public function isHesabfaSuccess($r) { return !empty($r->Success); }
    public function getHesabfaErrorMessage($r) { return isset($r->ErrorMessage) ? $r->ErrorMessage : ''; }
    public function buildOperationKey($type, array $parts) { return $type . ':' . implode(':', $parts); }
    public function getCompletedOperation($k) { return isset($this->operations[$k]) && $this->operations[$k]['status'] === 'success' ? $this->operations[$k] : false; }
    public function startOperation($k, $type, $objectType, $id) {
        if (!$this->claim || (isset($this->operations[$k]) && !in_array($this->operations[$k]['status'], array('failed', 'pending'), true))) { return false; }
        $this->operations[$k] = array('status' => 'pending'); return true;
    }
    public function finishOperation($k, $status, $message, $ref = null) { $this->operations[$k] = array('status' => $status, 'external_reference' => $ref); return true; }
    public function addFollowUpIssue($type, $message, $objectType, $id, $key, $severity) { $this->issues[] = $message; }
    public static function addModuleLog($m, $l, $c, $t, $id) {}
    public static function addLegacyLog($m, $l, $c, $t, $id, $force) {}
    public function manual() { return $this->processManualGatewayPayment(); }
    public function snapshot($paid) { return $this->getOrderPaymentFeeBreakdown('SSBHESABFA_PAYMENT_METHOD_fixture', $paid, OrderPayment::$payment, new Order(38015)); }
    public function bankValues() { return $this->getConfigFormValues('Bank'); }
    public function bankForm() { return $this->getBankForm(); }
    public function getCachedBanks() { return (object) array('Success' => true, 'Result' => array()); }
    public function saveBank() { return $this->setConfigFormsValues('Bank'); }
}
function fresh() {
    $p = 'SSBHESABFA_PAYMENT_METHOD_fixture';
    Configuration::$values = array($p => '0019', $p.'_FEE_TYPE' => 'percent', $p.'_FEE_PAYER' => 'customer', $p.'_FEE_PERCENT' => 7.7, $p.'_CUSTOMER_CHARGE_PERCENT' => 6,
        $p.'_EXPENSE_ACCOUNT_PATH' => 'Expenses:Gateway', $p.'_INCOME_ACCOUNT_PATH' => 'Income:Gateway');
    Tools::$values = array('SSBHESABFA_MANUAL_PAYMENT_METHOD' => $p, 'SSBHESABFA_MANUAL_INVOICE_NUMBER' => 2080, 'SSBHESABFA_MANUAL_GATEWAY_PAID_AMOUNT' => 14628000,
        'SSBHESABFA_MANUAL_TRANSACTION_NUMBER' => '18999147321791645864499', 'SSBHESABFA_MANUAL_PAYMENT_DATE' => '2026-10-10');
    HesabfaApi::$documents = HesabfaApi::$receipts = HesabfaApi::$writes = array();
    HesabfaApi::$fail = HesabfaApi::$readFail = HesabfaApi::$missingNumber = false;
    Module::$hub = new HubFixture();
    OrderPayment::$payment = (object) array('id' => 38901, 'amount' => 14628000, 'payment_method' => 'DigiPay', 'transaction_id' => '18999147321791645864499', 'date_add' => '2026-10-10 19:03:38', 'id_currency' => 1);
    return new FeeSubject();
}
$count = 0;
function check($condition, $label) { global $count; ++$count; if (!$condition) { throw new RuntimeException($label); } }
$s = fresh(); $p = 'SSBHESABFA_PAYMENT_METHOD_fixture';
$b = HesabfaPaymentFeeService::getBreakdown($p, 146280000);
check($b['invoice_payment_amount'] === 138000000.0 && $b['expense_amount'] === 2983560.0 && $b['income_amount'] === 0, '6% charge and 7.7% fee must create only the uncovered 2.162% expense');
Configuration::$values[$p.'_CUSTOMER_CHARGE_PERCENT'] = 10;
$b = HesabfaPaymentFeeService::getBreakdown($p, 110000);
check($b['income_amount'] === 1530.0 && $b['expense_amount'] === 0, 'Existing profitable payment must remain income only');
Configuration::$values[$p.'_CUSTOMER_CHARGE_PERCENT'] = 0;
$b = HesabfaPaymentFeeService::getBreakdown($p, 100000);
check($b['expense_amount'] === 7700.0, 'Customer-payer with zero surcharge still has a fee expense');
Configuration::$values[$p.'_FEE_PAYER'] = 'merchant';
$b = HesabfaPaymentFeeService::getBreakdown($p, 100000);
check($b['transaction_fee'] === 7700.0 && $b['expense_amount'] === 0, 'Merchant-payer must not charge the fee twice');
$s = fresh(); Configuration::$values[$p.'_FEE_TYPE'] = 'fixed'; Configuration::$values[$p.'_FEE_FIXED'] = 10000;
$b = HesabfaPaymentFeeService::getBreakdown($p, 106000);
check($b['expense_amount'] === 4000.0 && $b['income_amount'] === 0, 'Fixed fee must also subtract the collected surcharge');
$s = fresh(); Configuration::$values[$p.'_FEE_PERCENT'] = 50; Configuration::$values[$p.'_CUSTOMER_CHARGE_PERCENT'] = 100;
$b = HesabfaPaymentFeeService::getBreakdown($p, 200000);
check($b['expense_amount'] === 0 && $b['income_amount'] === 0, 'Break-even creates no adjustment');
$s = fresh(); check($s->setOrderPayment(38015), 'Automatic payment and fee expense must succeed');
check(HesabfaApi::$writes === array('payment', 'expense'), 'Automatic path must write one receipt and one expense');
$doc = HesabfaApi::$documents[9001];
check($doc['Debit'] === 2983560.0 && $doc['Transactions'][0]['AccountPath'] === 'Expenses:Gateway' && $doc['Transactions'][0]['Type'] === 0, 'Expense must debit its configured account');
check($doc['Transactions'][1]['BankCode'] === '0019' && $doc['Transactions'][1]['Type'] === 1 && $doc['Transactions'][1]['Info'] === OrderPayment::$payment->transaction_id, 'Bank credit and tracking reference must match the receipt');
check($doc['Date'] === OrderPayment::$payment->date_add, 'Automatic expense date must match the receipt');
HesabfaApi::$writes = array(); check($s->setOrderPayment(38015) && !HesabfaApi::$writes, 'Automatic replay must verify and reuse the expense');
Configuration::$values[$p.'_EXPENSE_ACCOUNT_PATH'] = 'Expenses:Changed';
check(!$s->setOrderPayment(38015) && !HesabfaApi::$writes, 'Changed account mapping must block another expense');
foreach (array('Amount', 'BankCode', 'Info', 'Currency', 'Date') as $field) {
    $s = fresh(); check($s->setOrderPayment(38015), 'Edited document fixture');
    if ($field === 'Date') { HesabfaApi::$documents[9001]['Date'] = '2026-10-11'; }
    else { HesabfaApi::$documents[9001]['Transactions'][1][$field] = $field === 'Amount' ? 1 : 'changed'; }
    HesabfaApi::$writes = array();
    check(!$s->setOrderPayment(38015) && !HesabfaApi::$writes, 'Edited expense details must not authorize a second adjustment: '.$field);
}
$s = fresh(); Configuration::$values[$p.'_EXPENSE_ACCOUNT_PATH'] = '';
check(!$s->setOrderPayment(38015) && HesabfaApi::$writes === array('payment'), 'Missing account must be visible without losing or duplicating the receipt');
Configuration::$values[$p.'_EXPENSE_ACCOUNT_PATH'] = 'Expenses:Gateway';
check($s->setOrderPayment(38015) && HesabfaApi::$writes === array('payment', 'expense'), 'Saving the missing path must recover only the expense');
$s = fresh(); HesabfaApi::$fail = true;
check(!$s->setOrderPayment(38015), 'Expense API failure must fail the job');
HesabfaApi::$fail = false; HesabfaApi::$writes = array();
check($s->setOrderPayment(38015) && HesabfaApi::$writes === array('expense'), 'Expense retry must preserve the original payment');
HesabfaApi::$readFail = true; HesabfaApi::$writes = array();
check(!$s->setOrderPayment(38015) && !HesabfaApi::$writes, 'An unavailable read must never authorize another expense');
$s = fresh(); HesabfaApi::$missingNumber = true;
check(!$s->setOrderPayment(38015), 'Ambiguous success without document number must require attention');
HesabfaApi::$missingNumber = false; HesabfaApi::$writes = array();
check(!$s->setOrderPayment(38015) && !HesabfaApi::$writes, 'Ambiguous result must not be resent');
$s = fresh(); check($s->manual()['success'] && HesabfaApi::$writes === array('payment', 'expense'), 'Manual payment must also register the fee expense');
check(HesabfaApi::$documents[9001]['Date'] === '2026-10-10', 'Manual expense must preserve the selected payment date');
HesabfaApi::$writes = array(); check($s->manual()['success'] && !HesabfaApi::$writes, 'Manual replay must reuse both records');
unset(HesabfaApi::$documents[9001]); check($s->manual()['success'] && HesabfaApi::$writes === array('expense'), 'Verified deleted manual expense can be restored without a second receipt');
$s = fresh(); check($s->setOrderPayment(38015), 'Fixture setup'); unset(HesabfaApi::$documents[9001]); HesabfaApi::$writes = array();
check(!$s->setOrderPayment(38015) && !HesabfaApi::$writes, 'Deleted automatic expense requires reconciliation rather than replay');
$s = fresh(); Module::$hub->snapshot = array('currency_id' => 1, 'principal_amount' => 13800000, 'customer_fee_amount' => 828000, 'gross_amount' => 14628000);
Configuration::$values[$p.'_CUSTOMER_CHARGE_PERCENT'] = 10;
$b = $s->snapshot(146280000);
check($b['invoice_payment_amount'] === 138000000.0 && $b['expense_amount'] === 2983560.0, 'Frozen balance receipt must preserve its actual principal and surcharge');
Module::$hub->snapshot['customer_fee_amount'] = 828001;
try { $s->snapshot(146280000); check(false, 'Invalid snapshot accepted'); } catch (RuntimeException $e) { check(true, 'Invalid snapshot rejected'); }
$s = fresh(); $values = $s->bankValues();
check(in_array($p.'_EXPENSE_ACCOUNT_PATH', array_column($s->bankForm()['form']['input'], 'name'), true), 'Payment settings must render the expense account input');
check(isset($values[$p.'_EXPENSE_ACCOUNT_PATH']), 'Expense mapping must load in bank settings');
Tools::$values = array($p => '0019', $p.'_EXPENSE_ACCOUNT_PATH' => 'Expenses:Another');
check($s->saveBank() && Configuration::get($p.'_EXPENSE_ACCOUNT_PATH') === 'Expenses:Another', 'Expense mapping must save independently from bank selection');
require dirname(__DIR__) . '/upgrade/upgrade-2.3.42.php';
Configuration::$values = array('SSBHESABFA_FEE_EXPENSE_DOCUMENT_DESCRIPTION_TEMPLATE' => 'Custom');
check(upgrade_module_2_3_42(null) && Configuration::get('SSBHESABFA_FEE_EXPENSE_DOCUMENT_DESCRIPTION_TEMPLATE') === 'Custom', 'Upgrade must preserve existing templates');
check(upgrade_module_2_3_42(null), 'Upgrade must be repeatable without financial operations');
echo json_encode(array('success' => true, 'checks' => $count, 'external_operations' => 0)), "\n";
