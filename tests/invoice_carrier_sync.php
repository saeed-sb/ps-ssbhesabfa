<?php
/** Uses the real carrier, margin and journal services with isolated SQL/API fixtures. */
ob_start();
require __DIR__.'/freight-replacement-sync.php';
ob_end_clean();
require dirname(__DIR__).'/classes/HesabfaInvoiceCarrierPlan.php';
require dirname(__DIR__).'/classes/services/HesabfaShipmentAccountingService.php';

class InvoiceCarrierRepository extends FixtureRepository {
    public $busy=false; public $courierHeld=false; public $carrierQueue=array();
    public function acquireCourierLock($id) { if ($this->busy) { return false; } return $this->courierHeld=true; }
    public function releaseCourierLock($id) { $this->courierHeld=false; }
    public function invoicePendingOrders($shop) { return empty($this->stored['invoice_pending_json'])?array():array(array('id_order'=>40001)); }
    public function carrierJobs($shop,$id) { return $this->carrierQueue; }
}
class InvoiceCarrierApi extends FixtureApi {
    public $invoiceBeforeTimeout=false; public $invoiceAfterTimeout=false; public $changePayment=false;
    public function apiRequest($method,$payload) {
        if ($method!=='invoice/save') { return parent::apiRequest($method,$payload); }
        $this->writes[]=array($method,$payload);
        if ($this->invoiceBeforeTimeout) { $this->invoiceBeforeTimeout=false; throw new RuntimeException('Invoice response lost before acceptance'); }
        $this->invoice=array_replace($this->invoice,$payload['invoice']);
        if ($this->changePayment) { $this->invoice['Paid']+=1; }
        if ($this->invoiceAfterTimeout) { $this->invoiceAfterTimeout=false; throw new RuntimeException('Invoice response lost after acceptance'); }
        return $this->ok($this->invoice);
    }
}
function invoiceCarrierFixture($cost='165000') {
    list($db,$oldRepo,$oldApi,$oldService,$source)=fixture(152);
    $repo=new InvoiceCarrierRepository($db);
    foreach (array('stored','sourceRow','orderRow','costRow') as $key) { $repo->$key=$oldRepo->$key; }
    $api=new InvoiceCarrierApi(); $api->invoice=$oldApi->invoice;
    $api->invoice=array_replace($api->invoice,array('Id'=>88001,'Freight'=>1100000,'FreightPersonCode'=>'0033','Date'=>'2026-10-19T00:00:00','DueDate'=>'2026-10-19T00:00:00','ContactCode'=>'0007','ContactTitle'=>'Customer','Sum'=>405000000,'Payable'=>406100000,'Paid'=>406100000,'Rest'=>0,'Note'=>'Preserve note','Sent'=>true,'WarehouseReceiptStatus'=>2,'SalesmanCode'=>'','SalesmanPercent'=>0,'Others'=>array(),'TaxId'=>null,
        'InvoiceItems'=>array(array('Id'=>8,'RowNumber'=>0,'Description'=>'Preserve serial and discount','ItemCode'=>'0009','Quantity'=>1,'Unit'=>'Each','UnitPrice'=>405000000,'Discount'=>0,'Tax'=>0,'SerialNumbers'=>array('SERIAL-1')))));
    $source['source']='manual'; $source['source_key']='courier:17';
    $source['cost_total_toman']=$source['sender_cost_toman']=$cost;
    $repo->sourceRow=sourceRevision($source); $repo->costRow['cost']=$cost;
    $settings=HesabfaFreightMarginSettings::getFormValues(1); $settings['person_code']='0044';
    $plan=HesabfaFreightMarginPlan::build($repo->orderRow,$source,$api->invoice,$settings,'2026-10-20',90204);
    $api->remote=remoteDocument($plan['document']);
    $repo->stored=array_replace($repo->stored,array('verified_document'=>json_encode($api->remote),'source_revision'=>$repo->sourceRow['revision_hash'],'margin_rial'=>$plan['margin_rial'],'invoice_pending_json'=>null));
    $service=new HesabfaShipmentAccountingService(new FixtureModule(),$repo,$api);
    return array($db,$repo,$api,$service,$source);
}
function numberWrites($api,$method) { return count(array_filter($api->writes,static function($write) use ($method) {return $write[0]===$method;})); }
$checks=array();
$assert=static function($name,$pass) use (&$checks) { $checks[]=array('case'=>$name,'pass'=>(bool)$pass); if (!$pass) { throw new RuntimeException($name); } };
list($db,$repo,$api,$service)=invoiceCarrierFixture();
$before=$api->invoice;
$assert('Carrier and expense synchronization succeeds',$service->process(1,40001)===true);
$expected=$before; $expected['FreightPersonCode']='0044';
$assert('Only invoice freight person changes, including preserved payment and serials',HesabfaInvoiceCarrierPlan::same($expected,$api->invoice));
$assert('Invoice is updated before its journal',$api->writes[0][0]==='invoice/save' && $api->writes[1][0]==='document/save');
$assert('Expense is550000rial and current carrier payable totals1650000rial',$repo->stored['margin_rial']===-550000 && count($api->remote['Transactions'])===2 && $api->remote['Debit']===550000);
$assert('Reclassification rows no longer clear the original invoice carrier',!array_filter($api->remote['Transactions'],static function($row) {return ltrim($row['ContactCode'],'0')==='33';}));
$assert('Both durable stages complete and locks release',$repo->stored['pending_json']===null && $repo->stored['invoice_pending_json']===null && !$repo->courierHeld && !HesabfaLock::$held);
$assert('A repeated synchronization creates no duplicate invoice or journal',$service->process(1,40001)===true && count($api->writes)===2);

foreach (array('100000'=>100000,'165000'=>-550000,'110000'=>0,'0'=>1100000) as $cost=>$margin) {
    list($db,$repo,$api,$service)=invoiceCarrierFixture((string)$cost);
    $assert('Margin is recalculated after invoice reassignment for cost '.$cost,$service->process(1,40001)===true && $repo->stored['margin_rial']===$margin);
    $assert('Zero margin removes the owned transfer instead of retaining an old payable for cost '.$cost,$margin===0 ? $api->remote===null : count($api->remote['Transactions'])===2);
}
foreach (array('invoiceBeforeTimeout','invoiceAfterTimeout') as $failure) {
    list($db,$repo,$api,$service)=invoiceCarrierFixture(); $api->$failure=true;
    try { $service->process(1,40001); throw new LogicException('Expected timeout'); } catch (RuntimeException $error) {}
    $pending=json_decode($repo->stored['invoice_pending_json'],true); $uuid=$pending['request_unique_id'];
    $assert($failure.' retains a durable request and leaves the journal untouched',!empty($pending) && numberWrites($api,'document/save')===0);
    $assert($failure.' recovers to matching invoice and expense',$service->process(1,40001)===true && $repo->stored['margin_rial']===-550000);
    $writes=array_values(array_filter($api->writes,static function($row) {return $row[0]==='invoice/save';}));
    $assert($failure.' uses one stable UUID and never repeats an accepted invoice',count($writes)===($failure==='invoiceBeforeTimeout'?2:1) && $writes[count($writes)-1][1]['requestUniqueId']===$uuid);
}
list($db,$repo,$api,$service)=invoiceCarrierFixture(); $api->failAfter=true;
try { $service->process(1,40001); throw new LogicException('Expected journal timeout'); } catch (RuntimeException $error) {}
$assert('A lost journal response retains both recovery stages',!empty($repo->stored['invoice_pending_json']) && !empty($repo->stored['pending_json']));
$assert('Journal recovery does not resend the invoice',$service->process(1,40001)===true && numberWrites($api,'invoice/save')===1 && numberWrites($api,'document/save')===1);
list($db,$repo,$api,$service)=invoiceCarrierFixture(); $api->invoiceBeforeTimeout=true;
try { $service->process(1,40001); } catch (RuntimeException $error) {}
$api->invoice['Paid']-=100;
$assert('Commercial or payment changes block replay',$service->process(1,40001)===false && numberWrites($api,'invoice/save')===1);
list($db,$repo,$api,$service)=invoiceCarrierFixture(); $api->changePayment=true;
$assert('An unexpected payment change is rejected after readback',$service->process(1,40001)===false && !empty($repo->stored['invoice_pending_json']) && numberWrites($api,'document/save')===0);
list($db,$repo,$api,$service)=invoiceCarrierFixture(); $api->remote['Description']='Manual journal edit';
$assert('An edited journal blocks invoice writes',$service->process(1,40001)===false && !$api->writes);
list($db,$repo,$api,$service)=invoiceCarrierFixture(); $repo->stored['account_fingerprint']=str_repeat('f',64);
$assert('Fiscal or business changes block invoice writes',$service->process(1,40001)===false && !$api->writes);
list($db,$repo,$api,$service)=invoiceCarrierFixture(); $db->config['SSBHESABFA_FREIGHT_PERSON_132']='';
$assert('Missing carrier mapping blocks invoice writes',$service->process(1,40001)===false && !$api->writes);
list($db,$repo,$api,$service)=invoiceCarrierFixture(); $repo->busy=true;
$assert('Concurrent courier operations stop safely and release the financial lock',$service->process(1,40001)===false && !$api->writes && !HesabfaLock::$held);
list($db,$repo,$api,$service)=invoiceCarrierFixture(); $repo->sourceRow=null; $repo->costRow=null; $repo->stored['verified_document']=null; $repo->stored['document_number']=''; $repo->stored['document_id']=0;
$assert('A carrier change updates the invoice before a courier cost is known',$service->process(1,40001,true)===true && numberWrites($api,'invoice/save')===1 && numberWrites($api,'document/save')===0);
list($db,$repo,$api,$service)=invoiceCarrierFixture(); $repo->sourceRow=null;
$assert('An existing expense cannot be stranded when source evidence is missing',$service->process(1,40001,true)===false && !$api->writes);
list($db,$repo,$api,$service)=invoiceCarrierFixture(); $api->invoice['Tag']='{"id_order":999}';
$assert('Invoice ownership must match the order',$service->process(1,40001)===false && !$api->writes);
list($db,$repo,$api,$service)=invoiceCarrierFixture(); $api->invoice['Number']=18002;
$assert('The response must match the mapped invoice number',$service->process(1,40001)===false && !$api->writes);
list($db,$repo,$api,$service)=invoiceCarrierFixture(); $repo->stored['invoice_pending_json']='not-json';
$assert('Malformed invoice recovery state cannot be discarded',$service->process(1,40001)===false && !$api->writes);
list($db,$repo,$api,$service)=invoiceCarrierFixture(); $api->invoiceBeforeTimeout=true;
try { $service->process(1,40001); } catch (RuntimeException $error) {}
$db->config['SSBHESABFA_FREIGHT_PERSON_132']='0055';
$assert('A pending unaccepted mapping is never resent under a new carrier contact',$service->process(1,40001)===false && numberWrites($api,'invoice/save')===1);
list($db,$repo,$api,$service)=invoiceCarrierFixture(); $api->invoiceBeforeTimeout=true;
try { $service->process(1,40001); } catch (RuntimeException $error) {}
$pending=json_decode($repo->stored['invoice_pending_json'],true); $pending['sent_at']='2020-01-01T00:00:00Z'; $repo->stored['invoice_pending_json']=json_encode($pending);
$assert('Expired uncertain invoice writes are not resent',$service->process(1,40001)===false && numberWrites($api,'invoice/save')===1);
list($db,$repo,$api,$service)=invoiceCarrierFixture();
$repo->carrierQueue=array(array('id_ssb_hesabfa_job'=>990,'payload'=>'{"id_carrier":152}','status'=>'retry_wait'));
$queued=count(HesabfaJobRepository::$enqueued);
$assert('A queued or retrying invoice carrier job is reused',$service->enqueue(1,40001)===990 && count(HesabfaJobRepository::$enqueued)===$queued);
list($db,$repo,$api,$service)=invoiceCarrierFixture();
$api->invoice['InvoiceItems'][0]['ItemCode']=null;
$api->invoice['InvoiceItems'][0]['Item']=array('Code'=>'0009');
$assert('Nested live Item.Code is retained when ItemCode is null',$service->process(1,40001)===true && $api->writes[0][1]['invoice']['InvoiceItems'][0]['ItemCode']==='0009');
list($db,$repo,$api,$service)=invoiceCarrierFixture();
$api->invoice['InvoiceItems'][0]['ItemCode']=null;
$assert('Unknown item identity blocks invoice writes',$service->process(1,40001)===false && !$api->writes);
echo json_encode(array('success'=>true,'checks'=>count($checks),'tests'=>$checks),JSON_PRETTY_PRINT),"\n";
