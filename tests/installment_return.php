<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('_PS_VERSION_', '8.2.9');
class Configuration {
    public static function get($key) { return $key === 'SSBHESABFA_INVOICE_PROJECT' ? 'فروش وب‌سایت' : false; }
}
class Module {
    public static $installed = true;
    public static function isInstalled($name) { return self::$installed && $name === 'wavendorpay'; }
}
class Validate { public static function isLoadedObject($obj) { return is_object($obj); } }
class HesabfaSecurity { public static function isOperational() { return true; } }
class HesabfaLock {
    public static $held = false;
    public static function acquire($key) { if(self::$held) { return false; } self::$held=true; return true; }
    public static function release($key) { self::$held=false; }
}
class HesabfaOperationRepository {
    public static $payments = array();
    public static function getInstallmentPayments($id) { return self::$payments; }
    public static function releaseOwned() {}
    public static function getSuccessfulInvoiceRecovery($type,$id) { return array('state'=>'none'); }
}
class HesabfaIssueRepository { public static function resolveByOperationKey($key) {} }
class HesabfaMappingRepository {
    public static $values = array('order'=>2080);
    public static function getHesabfaCode($type,$id,$attribute=0) { return self::$values[$type] ?? null; }
    public static function upsert($type,$id,$code,$attribute) { self::$values[$type]=(int)$code; return true; }
}
class HesabfaPrestashopRepository {
    public static $details = array(7);
    public static function getInstallmentOrderDetailIds($id) { return self::$details; }
    public static function orderHasFreeShippingCartRule($id) { return false; }
}
class HesabfaFreightPerson { public static function managedCarrier($carrier,$shop) { return true; } }
class Order {
    public $id, $reference='UXNLEYOVX', $id_customer=4, $id_address_invoice=1, $id_address_delivery=1, $id_carrier=0, $id_shop=1;
    public $total_paid_tax_incl=11000, $total_discounts=0, $total_products=11000, $total_shipping_tax_incl=0;
    public $total_wrapping_tax_excl=0, $date_add='2026-11-17 10:00:00', $date_upd='2026-11-20 10:00:00';
    public static $products = array();
    public function __construct($id) { $this->id=$id; }
    public function getProducts() { return self::$products; }
}
class HesabfaApiResponse { public static function normalize($r) { return $r; } }
class HesabfaApi {
    public static $sale, $returned, $receipts, $documents, $writes, $fail='', $missingNumber=false;
    public static function ok($r) { return json_decode(json_encode(array('Success'=>true,'Result'=>$r))); }
    private function reject($type) {
        self::$writes[]=$type;
        return self::$fail===$type ? (object)array('Success'=>false,'ErrorMessage'=>'fixture failure') : null;
    }
    public function invoiceGet($number,$type=0) {
        $invoice=$type===2 ? self::$returned : self::$sale;
        return $invoice && (int)$invoice->Number===$number ? self::ok($invoice) : (object)array('Success'=>false,'ErrorCode'=>112);
    }
    public function invoiceSave($data) {
        if ($data['InvoiceType'] === 2 && array_key_exists('Number', $data)) {
            throw new RuntimeException('Omit the invoice number when requesting an automatically numbered sales return.');
        }
        if ($r=$this->reject('invoice')) { return $r; }
        $sum=0;
        foreach($data['InvoiceItems'] as $row) { $sum+=$row['UnitPrice']*$row['Quantity']-$row['Discount']+$row['Tax']; }
        $number=$data['InvoiceType']===2 ? 900 : 2080;
        $invoice=json_decode(json_encode($data));
        $invoice->Number=$number;
        $invoice->Payable=$sum+($data['Freight']??0);
        $invoice->Paid=0;
        $invoice->Rest=$invoice->Payable;
        if($data['InvoiceType']===2) { self::$returned=$invoice; } else { self::$sale=$invoice; }
        return self::ok(self::$missingNumber ? array() : array('Number'=>$number));
    }
    public function invoiceSavePayment($number,$target,$date,$amount,$ref,$desc,$fee,$project,$type=0) {
        if($r=$this->reject('payment')) { return $r; }
        if($type!==2 || $fee!==0) { throw new RuntimeException('Refund must target the return invoice with zero extra fee'); }
        $id=3000;
        $receipt=array('Number'=>$id,'Invoice'=>array('Number'=>$number,'InvoiceType'=>$type),'Currency'=>'IRR',
            'DateTime'=>$date,'Description'=>$desc,'Amount'=>$amount,
            'Transactions'=>array(array('Account'=>$target['accountPath'],'Contact'=>array('Code'=>$target['contactCode']??''),
                'Reference'=>$ref,'Amount'=>$amount,'TransactionFee'=>$fee)));
        self::$receipts[2][$id]=json_decode(json_encode($receipt));
        self::$returned->Paid+=$amount;
        self::$returned->Rest-=$amount;
        return self::ok(array('Number'=>$id));
    }
    public function receiptGet($type,$number) {
        return isset(self::$receipts[$type][$number]) ? self::ok(self::$receipts[$type][$number]) : (object)array('Success'=>false,'ErrorCode'=>112);
    }
    public function documentGet($number) {
        return isset(self::$documents[$number]) ? self::ok(self::$documents[$number]) : (object)array('Success'=>false,'ErrorCode'=>112);
    }
    public function documentSave($data) {
        if($r=$this->reject('reversal')) { return $r; }
        $doc=array('Number'=>10000);
        foreach($data as $key=>$value) {
            if($key==='transactions') {
                $doc['Transactions']=array();
                foreach($value as $line) {
                    $row=array();
                    foreach($line as $field=>$v) { $row[ucfirst($field)]=$v; }
                    $doc['Transactions'][]=$row;
                }
            } elseif($key!=='number') { $doc[ucfirst($key)]=$value; }
        }
        self::$documents[10000]=json_decode(json_encode($doc));
        return self::ok(array('Number'=>10000));
    }
}
require dirname(__DIR__).'/classes/HesabfaDateHelper.php';
require dirname(__DIR__).'/classes/traits/HesabfaPaymentTrait.php';
require dirname(__DIR__).'/classes/traits/HesabfaReturnTrait.php';
class ReturnSubject {
    use HesabfaPaymentTrait, HesabfaReturnTrait;
    public $operations=array(), $issues=array(), $block=false;
    public function getInvoiceCodeByOrderId($id) { return HesabfaMappingRepository::$values['order'] ?? null; }
    public function getOrderPriceInHesabfaDefaultCurrency($price,$id) { return (float)$price*10; }
    public function getObjectId($type,$id) { return 1; }
    public function getContactCodeByCustomerId($id) { return '000099'; }
    public function getItemCodeByProductId($p,$a) { return '000041'; }
    public function l($s) { return $s; }
    public static function addModuleLog(...$args) {}
    public static function addLegacyLog(...$args) {}
    public function buildOperationKey($type,array $parts) { return substr($type.':'.implode(':',$parts),0,190); }
    public function getOperationByKey($key) { return $this->operations[$key] ?? false; }
    public function getCompletedOperation($key) { $r=$this->getOperationByKey($key); return ($r['status']??'')==='success' ? $r : false; }
    public function startOperation($key,$type,$object,$id,?array $plan=null) {
        if($this->block || !in_array($this->operations[$key]['status']??'failed',array('pending','failed'),true)) { return false; }
        $this->operations[$key]=array('status'=>'pending','payment_plan'=>json_encode($plan))+($this->operations[$key]??array());
        return true;
    }
    public function finishOperation($key,$status,$message,$ref=null) {
        $this->operations[$key]=array('status'=>$status,'external_reference'=>$ref)+($this->operations[$key]??array());
        return true;
    }
    public function normalizeHesabfaResponse($r,...$args) { return $r; }
    public function isHesabfaSuccess($r) { return !empty($r->Success); }
    public function getHesabfaErrorMessage($r) { return $r->ErrorMessage??''; }
    public function addFollowUpIssue($t,$message,...$args) { $this->issues[]=$message; }
    private function setOrderPaymentUnlocked($id) { return true; }
}
function fixture($fee=8470) {
    Module::$installed=true;
    HesabfaMappingRepository::$values=array('order'=>2080);
    HesabfaApi::$returned=null;
    HesabfaApi::$writes=array();
    HesabfaApi::$fail='';
    HesabfaApi::$missingNumber=false;
    $path='دارایی ها : دارایی های جاری : حساب های دریافتنی : حساب های دریافتنی';
    $plan=array('receivable'=>$path,'contact_code'=>'000421','amount'=>110000,'fee'=>$fee,
        'expense'=>'Expenses:Gateway','date'=>'2026-11-17 10:00:00','reference'=>'trx-original','currency'=>'IRR','project'=>'فروش وب‌سایت');
    $receipt=array('Number'=>2460,'Invoice'=>array('Number'=>2080,'InvoiceType'=>0),'Amount'=>110000,'Currency'=>'IRR',
        'DateTime'=>$plan['date'],'Transactions'=>array(array('Account'=>$path,'Contact'=>array('Code'=>'000421'),
            'Reference'=>'trx-original','Amount'=>110000,'TransactionFee'=>0)));
    HesabfaApi::$receipts=array(1=>array(2460=>json_decode(json_encode($receipt))),2=>array());
    $sale=array('Number'=>2080,'InvoiceType'=>0,'ContactCode'=>null,'Contact'=>array('Code'=>'000099'),
        'Payable'=>110000,'Freight'=>0,'Date'=>'2026-11-17','Currency'=>'IRR','CurrencyRate'=>1,'Project'=>'فروش وب‌سایت',
        'InvoiceItems'=>array(array('RowNumber'=>0,'ItemCode'=>null,'Item'=>array('Code'=>'000041'),
            'Description'=>'کالای آزمایشی','UnitPrice'=>110000,'Quantity'=>1,'Discount'=>0,'Tax'=>0)));
    HesabfaApi::$sale=json_decode(json_encode($sale));
    $line=array('Amount'=>$fee,'CurrencyAmount'=>$fee,'Currency'=>'IRR','Info'=>'trx-original');
    HesabfaApi::$documents=array(6070=>json_decode(json_encode(array('Number'=>6070,'Date'=>'2026-11-17','Debit'=>$fee,'Credit'=>$fee,
        'Transactions'=>array(array_merge($line,array('AccountPath'=>'Expenses:Gateway','Type'=>0)),
            array_merge($line,array('AccountPath'=>$path,'ContactCode'=>'000421','Type'=>1)))))));
    $sourceKey='invoice_payment:38166:2080:39054:trx-original';
    HesabfaOperationRepository::$payments=array(array('operation_key'=>$sourceKey,'status'=>'success','external_reference'=>'2460','payment_plan'=>json_encode($plan)));
    $subject=new ReturnSubject();
    $subject->operations['payment_fee_expense_document:38166:2080:39054:trx-original']=array('status'=>'success','external_reference'=>'6070');
    return $subject;
}
$checks=0;
function verify($ok,$message) { global $checks; ++$checks; if(!$ok) { throw new RuntimeException($message); } }
$date='2026-11-18 12:34:56';
$s=fixture();
verify($s->setOrder(38166,2,null,null,$date), 'A normal order return must complete all three entries: '.implode(' ',$s->issues));
verify(HesabfaApi::$writes===array('invoice','payment','reversal'), 'A return creates exactly one invoice, refund and reversal');
verify((float)HesabfaApi::$returned->Payable===110000.0 && (float)HesabfaApi::$returned->Rest===0.0, 'The return includes the installment markup and is fully paid');
$refund=HesabfaApi::$receipts[2][3000];
verify($refund->Transactions[0]->Contact->Code==='000421' && $refund->DateTime===$date, 'Refund uses the original contact and cancellation date');
$reverse=HesabfaApi::$documents[10000];
verify($reverse->Transactions[0]->Type===1 && $reverse->Transactions[1]->Type===0
    && $reverse->Transactions[1]->ContactCode==='000421' && $reverse->Debit===8470 && $reverse->Reference===6070, 'The reversal mirrors both fee lines and points to the original fee');
verify(mb_strpos($reverse->Description,'برگشت کارمزد')!==false && mb_strpos($reverse->Description,'قسط')===false, 'Accounting descriptions are Persian');
$writes=HesabfaApi::$writes;
Module::$installed=false;
verify($s->setOrder(38166,2,null,null,'2026-11-22') && HesabfaApi::$writes===$writes
    && HesabfaApi::$returned->Date===$date, 'A later retry uses the saved plan and creates no duplicates');
unset(HesabfaMappingRepository::$values['returnOrder']);
verify($s->setOrder(38166,2,null,null,'2026-11-22') && HesabfaMappingRepository::$values['returnOrder']===900
    && HesabfaApi::$writes===$writes, 'A lost return mapping is repaired without another invoice');
foreach(array('payment','reversal') as $failure) {
    $s=fixture();
    HesabfaApi::$fail=$failure;
    verify(!$s->setOrder(38166,2,null,null,$date), 'Partial failure remains retryable');
    $invoices=count(array_filter(HesabfaApi::$writes,static fn($v)=>$v==='invoice'));
    $paid=count(HesabfaApi::$receipts[2]);
    HesabfaApi::$fail='';
    verify($s->setOrder(38166,2,null,null,'2026-11-22'), 'Retry finishes the remaining stage');
    verify(count(array_filter(HesabfaApi::$writes,static fn($v)=>$v==='invoice'))===$invoices
        && count(HesabfaApi::$receipts[2])===1 && ($failure==='payment' || $paid===1), 'The invoice and successful refund are never repeated');
}
$s=fixture();
$source=json_decode(HesabfaOperationRepository::$payments[0]['payment_plan'],true);
$source['contact_code']='421';
HesabfaOperationRepository::$payments[0]['payment_plan']=json_encode($source);
verify($s->setOrder(38166,2,null,null,$date), 'Leading zero padding from Hesabfa does not change the gateway person');
$s=fixture(0);
verify($s->setOrder(38166,2,null,null,$date) && HesabfaApi::$writes===array('invoice','payment'), 'A zero-fee order has no reversal');
$s=fixture();
HesabfaApi::$sale->Payable=100000;
verify(!$s->setOrder(38166,2,null,null,$date) && !HesabfaApi::$writes, 'A source invoice amount mismatch is detected before any return write');
$s=fixture();
HesabfaApi::$documents[6070]->Transactions[1]->ContactCode='000999';
verify(!$s->setOrder(38166,2,null,null,$date) && !HesabfaApi::$writes, 'An edited source fee cannot authorize a reversal');
$s=fixture();
HesabfaApi::$missingNumber=true;
verify(!$s->setOrder(38166,2,null,null,$date), 'An ambiguous invoice response requires reconciliation');
$writes=HesabfaApi::$writes;
verify(!$s->setOrder(38166,2,null,null,$date) && HesabfaApi::$writes===$writes, 'An ambiguous success cannot create another invoice');
$s=fixture();
verify($s->setOrder(38166,2,null,null,$date), 'Fixture for receipt reconciliation');
HesabfaApi::$receipts[2][3000]->Transactions[0]->Contact->Code='000999';
$writes=HesabfaApi::$writes;
verify(!$s->setOrder(38166,2,null,null,$date) && HesabfaApi::$writes===$writes, 'An edited refund blocks another financial write');
$s=fixture();
HesabfaMappingRepository::$values=array();
Order::$products=array(7=>array('id_order_detail'=>7,'product_id'=>41,'product_attribute_id'=>0,'product_name'=>'کالای آزمایشی','product_quantity'=>1,
    'original_product_price'=>10000,'product_price'=>11000,'unit_price_tax_excl'=>11000,'unit_price_tax_incl'=>11000));
verify($s->setOrder(38166) && (float)HesabfaApi::$sale->InvoiceItems[0]->UnitPrice===110000.0, 'New installment invoices use the frozen selling price');
$s=fixture();
HesabfaMappingRepository::$values=array();
HesabfaPrestashopRepository::$details=array();
verify($s->setOrder(38166) && (float)HesabfaApi::$sale->InvoiceItems[0]->UnitPrice===100000.0, 'An ordinary invoice retains its original pricing behavior');
$s=fixture();
HesabfaMappingRepository::$values=array();
HesabfaPrestashopRepository::$details=array(7);
Order::$products[7]['product_quantity']=2;
Order::$products[7]['unit_price_tax_incl']=12100;
verify($s->setOrder(38166) && (float)HesabfaApi::$sale->InvoiceItems[0]->Tax===22000.0
    && (float)HesabfaApi::$sale->Payable===242000.0, 'Installment line taxes scale with quantity and use the final recorded selling price');
$s=fixture();
HesabfaApi::$returned=clone HesabfaApi::$sale;
HesabfaApi::$returned->Number=900;
HesabfaApi::$returned->InvoiceType=2;
HesabfaApi::$returned->Reference='2080';
HesabfaApi::$returned->Date=$date;
HesabfaApi::$returned->Paid=110000;
HesabfaApi::$returned->Rest=0;
HesabfaMappingRepository::$values['returnOrder']=900;
verify(!$s->setOrder(38166,2,null,null,$date) && !HesabfaApi::$writes,
    'An existing payout outside this workflow cannot trigger a second refund');
echo json_encode(array('success'=>true,'checks'=>$checks,'external_operations'=>0)),PHP_EOL;
