<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('_PS_VERSION_', '8.1.7');
define('_DB_NAME_', 'fee_expense_fixture');
define('_DB_PREFIX_', 'fixture_');
class Db {
    public static $operationColumns = array(), $alterCount = 0, $configurationSnapshot = array();
    public static function getInstance() { return new self(); }
    public function getValue($query, $cache) { return 1; }
    public function executeS($query, $array, $cache) { return self::$operationColumns; }
    public function execute($query) {
        if ($query === 'START TRANSACTION') {
            self::$configurationSnapshot = Configuration::$values;
            return true;
        }
        if ($query === 'COMMIT') { return true; }
        if ($query === 'ROLLBACK') {
            Configuration::$values = self::$configurationSnapshot;
            return true;
        }
        if (strpos($query, 'ADD COLUMN `payment_plan`') === false) { return false; }
        self::$operationColumns[] = array('Field' => 'payment_plan');
        ++self::$alterCount;
        return true;
    }
}
class Configuration {
    public static $values = array(), $failedKey = null;
    public static function get($k) { return array_key_exists($k, self::$values) ? self::$values[$k] : false; }
    public static function updateValue($k, $v) {
        if ($k === self::$failedKey) { return false; }
        self::$values[$k] = $v;
        return true;
    }
    public static function resetStaticCache() {}
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
class Module {
    public static $hub, $qestyarInstalled = false;
    public static function getInstanceByName($v) { return self::$hub; }
    public static function isInstalled($name) { return $name === 'wavendorpay' && self::$qestyarInstalled; }
}
class HesabfaPrestashopRepository {
    public static $installmentMethods = array(), $verifiedProvider = '';
    public static function getInstallmentPaymentModules($order) { return self::$installmentMethods; }
    public static function getVerifiedCheckoutProvider($order, $payment) { return self::$verifiedProvider; }
}
class HubFixture { public $snapshot; public function getBalancePaymentFeeSnapshot($p, $o) { return $this->snapshot; } }
class Order {
    public static $total = 14628000;
    public $id, $module = 'ssbpaymenthub', $reference = 'ORDER', $id_currency = 1, $total_paid_tax_incl;
    public $id_cart = 71, $id_shop = 1;
    public function __construct($id) { $this->id = $id; $this->total_paid_tax_incl = self::$total; }
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
            'Transactions' => array(array(
                'Bank' => isset($target['bankCode']) ? array('Code' => $target['bankCode']) : null,
                'Account' => $target['accountPath'] ?? null,
                'Reference' => $ref, 'Amount' => $amount, 'TransactionFee' => $fee,
            )));
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
    public function getPaymentMethodsName() {
        return array(
            array('id' => 'SSBHESABFA_PAYMENT_METHOD_fixture', 'name' => 'DigiPay'),
            array('id' => 'SSBHESABFA_PAYMENT_METHOD_' . md5('ssbpaymenthub|@provider:tara'), 'name' => 'Tara'),
        );
    }
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
    public function getOperationByKey($k) { return $this->operations[$k] ?? false; }
    public function startOperation($k, $type, $objectType, $id, ?array $plan = null) {
        if (!$this->claim || (isset($this->operations[$k]) && !in_array($this->operations[$k]['status'], array('failed', 'pending'), true))) { return false; }
        $this->operations[$k] = array('status' => 'pending') + ($this->operations[$k] ?? array());
        if ($plan !== null) { $this->operations[$k]['payment_plan'] = json_encode($plan); }
        return true;
    }
    public function finishOperation($k, $status, $message, $ref = null) {
        $this->operations[$k] = array('status' => $status, 'external_reference' => $ref) + ($this->operations[$k] ?? array());
        return true;
    }
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
    Configuration::$failedKey = null;
    Configuration::$values = array($p => '0019', $p.'_FEE_TYPE' => 'percent', $p.'_FEE_PAYER' => 'customer', $p.'_FEE_PERCENT' => 7.7, $p.'_CUSTOMER_CHARGE_PERCENT' => 6,
        $p.'_EXPENSE_ACCOUNT_PATH' => 'Expenses:Gateway', $p.'_INCOME_ACCOUNT_PATH' => 'Income:Gateway');
    Tools::$values = array('SSBHESABFA_MANUAL_PAYMENT_METHOD' => $p, 'SSBHESABFA_MANUAL_INVOICE_NUMBER' => 2080, 'SSBHESABFA_MANUAL_GATEWAY_PAID_AMOUNT' => 14628000,
        'SSBHESABFA_MANUAL_TRANSACTION_NUMBER' => '18999147321791645864499', 'SSBHESABFA_MANUAL_PAYMENT_DATE' => '2026-10-10');
    HesabfaApi::$documents = HesabfaApi::$receipts = HesabfaApi::$writes = array();
    HesabfaApi::$fail = HesabfaApi::$readFail = HesabfaApi::$missingNumber = false;
    Module::$hub = new HubFixture();
    Module::$qestyarInstalled = false;
    HesabfaPrestashopRepository::$installmentMethods = array();
    HesabfaPrestashopRepository::$verifiedProvider = '';
    Order::$total = 14628000;
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
$mode = 'SSBHESABFA_QESTYAR_RECEIVABLE_FROM_PAYMENT';
$digipay = 'SSBHESABFA_PAYMENT_METHOD_' . md5('ssbpaymenthub|@provider:digipay');
function qestyarFixture($charge = 1100) {
    global $mode, $digipay;
    $subject = fresh();
    Module::$qestyarInstalled = true;
    HesabfaPrestashopRepository::$installmentMethods = array('ssbpaymenthub:digipay');
    HesabfaPrestashopRepository::$verifiedProvider = 'digipay';
    Order::$total = $charge;
    OrderPayment::$payment->amount = $charge;
    Configuration::$values[$mode] = OrderPayment::$payment->id;
    Configuration::$values[$digipay . '_RECEIVABLE_ACCOUNT_PATH'] = 'Assets:Receivables:DigiPay';
    Configuration::$values[$digipay . '_EXPENSE_ACCOUNT_PATH'] = 'Expenses:DigiPay';
    Configuration::$values[$digipay . '_FEE_TYPE'] = 'percent';
    Configuration::$values[$digipay . '_FEE_PERCENT'] = 7.7;
    Configuration::$values[$digipay . '_FEE_PAYER'] = 'customer';
    Configuration::$values[$digipay . '_CUSTOMER_CHARGE_PERCENT'] = 10;
    Configuration::$values[$digipay . '_INCOME_ACCOUNT_PATH'] = 'Income:DigiPay';
    return $subject;
}

$s = qestyarFixture();
check($s->setOrderPayment(38015) && HesabfaApi::$writes === array('payment', 'expense'), 'Qestyar posts a receipt and the whole fee');
$receipt = HesabfaApi::$receipts[0]['Transactions'][0];
check((float) $receipt['Amount'] === 11000.0 && $receipt['TransactionFee'] === 0 && $receipt['Account'] === 'Assets:Receivables:DigiPay' && $receipt['Bank'] === null, 'The full paid invoice amount goes to receivables, without a built-in fee');
$fee = HesabfaApi::$documents[9001];
check((float) $fee['Debit'] === 847.0 && (float) $fee['Credit'] === 847.0, 'The entire 7.7% fee is expensed');
check($fee['Transactions'][0]['AccountPath'] === 'Expenses:DigiPay' && $fee['Transactions'][0]['Type'] === 0, 'Expense account must be debited');
check($fee['Transactions'][1]['AccountPath'] === 'Assets:Receivables:DigiPay' && $fee['Transactions'][1]['Type'] === 1 && $fee['Transactions'][1]['BankCode'] === '', 'Gateway receivables must be credited');
check(count(HesabfaApi::$documents) === 1, 'The surcharge that was included in the sale creates no separate income');
HesabfaApi::$writes = array();
check($s->setOrderPayment(38015) && !HesabfaApi::$writes, 'A completed Qestyar payment does not create another receipt or expense');

$s = qestyarFixture(1050);
Configuration::$values[$digipay . '_CUSTOMER_CHARGE_PERCENT'] = 5;
check($s->setOrderPayment(38015) && (float) HesabfaApi::$receipts[0]['Transactions'][0]['Amount'] === 10500.0
    && abs(HesabfaApi::$documents[9001]['Debit'] - 808.5) < 0.0001, 'A 5% markup changes the invoice amount, with the fee still calculated at 7.7%');

$s = qestyarFixture(1130);
check($s->setOrderPayment(38015) && abs(HesabfaApi::$documents[9001]['Debit'] - 870.1) < 0.0001, 'The gateway fee is charged on the verified payment total including shipping');

$s = qestyarFixture();
HesabfaApi::$fail = true;
check(!$s->setOrderPayment(38015) && HesabfaApi::$writes === array('payment', 'expense'), 'A fee document failure leaves the receipt recorded');
HesabfaApi::$fail = false;
HesabfaApi::$writes = array();
Configuration::$values[$mode] = 0;
Configuration::$values[$digipay . '_QESTYAR_RECEIVABLE_FROM_PAYMENT'] = 0;
Configuration::$values[$digipay . '_FEE_PERCENT'] = 9;
Configuration::$values[$digipay . '_RECEIVABLE_ACCOUNT_PATH'] = 'Assets:Receivables:New';
Module::$qestyarInstalled = false;
check($s->setOrderPayment(38015) && HesabfaApi::$writes === array('expense')
    && (float) HesabfaApi::$documents[9001]['Debit'] === 847.0
    && HesabfaApi::$documents[9001]['Transactions'][1]['AccountPath'] === 'Assets:Receivables:DigiPay', 'A retry after switching modes uses the saved fee and account and only writes the expense');

$s = qestyarFixture();
Configuration::$values[$digipay . '_FEE_TYPE'] = 'none';
check($s->setOrderPayment(38015) && HesabfaApi::$writes === array('payment'), 'A zero gateway fee creates only the Qestyar receipt');

$s = qestyarFixture();
HesabfaPrestashopRepository::$verifiedProvider = 'tara';
check(!$s->setOrderPayment(38015) && !HesabfaApi::$writes, 'A mismatched verified gateway does not post the full Qestyar payment');
$s = qestyarFixture();
unset(Configuration::$values[$digipay . '_RECEIVABLE_ACCOUNT_PATH']);
check(!$s->setOrderPayment(38015) && !HesabfaApi::$writes, 'A missing account is reported before the receipt is written');

$s = qestyarFixture();
Configuration::$values[$mode] = OrderPayment::$payment->id + 1;
check($s->setOrderPayment(38015) && isset(HesabfaApi::$receipts[0]['Transactions'][0]['Bank']), 'Payments before mode activation use the existing accounting method');
$s = qestyarFixture();
HesabfaPrestashopRepository::$installmentMethods = array();
check($s->setOrderPayment(38015) && isset(HesabfaApi::$receipts[0]['Transactions'][0]['Bank']), 'A cash order uses the existing accounting method');
$s = qestyarFixture();
Configuration::$values[$mode] = -OrderPayment::$payment->id;
check($s->setOrderPayment(38015) && isset(HesabfaApi::$receipts[0]['Transactions'][0]['Bank']), 'Switching back routes new payments through the existing accounting method');

$tara = 'SSBHESABFA_PAYMENT_METHOD_' . md5('ssbpaymenthub|@provider:tara');
$modeSuffix = '_QESTYAR_RECEIVABLE_FROM_PAYMENT';
$s = qestyarFixture();
Configuration::$values[$digipay . $modeSuffix] = 0;
Configuration::$values[$tara . $modeSuffix] = OrderPayment::$payment->id;
Configuration::$values[$p . '_FEE_PAYER'] = 'merchant';
check($s->setOrderPayment(38015) && HesabfaApi::$writes === array('payment')
    && isset(HesabfaApi::$receipts[0]['Transactions'][0]['Bank']), 'An explicit bank route overrides the legacy setting regardless of another gateway');
$s = qestyarFixture();
Configuration::$values[$mode] = 0;
Configuration::$values[$digipay . $modeSuffix] = OrderPayment::$payment->id;
Configuration::$values[$tara . $modeSuffix] = 0;
check($s->setOrderPayment(38015) && HesabfaApi::$writes === array('payment', 'expense'), 'The verified gateway can use receivables with the global setting disabled');
$s = qestyarFixture();
Configuration::$values[$digipay . $modeSuffix] = OrderPayment::$payment->id + 1;
check($s->setOrderPayment(38015) && isset(HesabfaApi::$receipts[0]['Transactions'][0]['Bank']), 'Each gateway has an independent cutoff for older payments');

$s = fresh();
$formMode = $p . $modeSuffix;
$taraMode = $tara . $modeSuffix;
$path = $p . '_RECEIVABLE_ACCOUNT_PATH';
Tools::$values = array($formMode => '1', $path => 'Assets:Receivables:DigiPay');
check(!isset($s->bankValues()[$formMode]) && $s->saveBank() && Configuration::get($formMode) === false, 'Mode activation requires Qestyar even for a submitted value');

$s = fresh();
Module::$qestyarInstalled = true;
$inputs = array_column($s->bankForm()['form']['input'], null, 'name');
check($s->bankValues()[$formMode] === 0 && isset($inputs[$formMode], $inputs[$taraMode]) && !isset($inputs[$mode])
    && str_contains($inputs[$path]['form_group_class'], ' hide')
    && !str_contains($inputs[$p]['form_group_class'], ' hide'), 'Every gateway has an independent selector and initially shows only its saved mode fields');
Tools::$values = array($formMode => '1', $path => ' Assets:Receivables:DigiPay ', $p => '0042', $p . '_FEE_PAYER' => 'merchant');
check($s->saveBank() && Configuration::get($formMode) === 1 && Configuration::get($path) === 'Assets:Receivables:DigiPay'
    && Configuration::get($p . '_FEE_PERCENT') === 7.7 && Configuration::get($p) === '0019'
    && Configuration::get($p . '_FEE_PAYER') === 'customer' && $s->bankValues()[$taraMode] === 0, 'Activation saves only the current gateway and keeps its bank settings');
$inputs = array_column($s->bankForm()['form']['input'], null, 'name');
check(!str_contains($inputs[$path]['form_group_class'], ' hide')
    && !str_contains($inputs[$p . '_EXPENSE_ACCOUNT_PATH']['form_group_class'], ' hide')
    && str_contains($inputs[$p]['form_group_class'], ' hide')
    && str_contains($inputs[$p . '_INCOME_ACCOUNT_PATH']['form_group_class'], ' hide'), 'The saved receivables form hides the bank and income settings');

Configuration::$values[$mode] = 10;
Tools::$values = array($formMode => '0', $path => '');
check($s->saveBank() && Configuration::get($formMode) === 0 && Configuration::get($path) === 'Assets:Receivables:DigiPay'
    && $s->bankValues()[$formMode] === 0 && $s->bankValues()[$taraMode] === 1, 'Switching back keeps the hidden receivable account and does not override other gateways');

$s = fresh();
Module::$qestyarInstalled = true;
Tools::$values = array($formMode => '1', $tara => '1234');
try {
    $s->saveBank();
    check(false, 'Activation needs a receivable account');
} catch (InvalidArgumentException $e) {
    check(Configuration::get($formMode) === false && Configuration::get($tara) === false, 'Invalid activation leaves all settings intact');
}
Tools::$values = array($formMode => '1', $path => 'Assets:Receivables:DigiPay');
Configuration::$failedKey = $formMode;
check(!$s->saveBank() && Configuration::get($path) === false && Configuration::get($formMode) === false, 'A failed mode write rolls back its associated account settings');

require dirname(__DIR__) . '/upgrade/upgrade-2.3.48.php';
check(upgrade_module_2_3_48(null) && upgrade_module_2_3_48(null) && Db::$alterCount === 1, 'The operation journal schema upgrade is repeatable');

echo json_encode(array('success' => true, 'checks' => $count, 'external_operations' => 0)), "\n";
