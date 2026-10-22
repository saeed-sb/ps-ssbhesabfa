<?php
/** Real accounting services; all database, queue and Hesabfa operations are isolated fixtures. */
ob_start();
require __DIR__.'/invoice_carrier_sync.php';
ob_end_clean();
require dirname(__DIR__,2).'/ssbdeliverymanager/src/ShippingCost/Input.php';
require dirname(__DIR__,2).'/ssbdeliverymanager/src/ShippingCost/AccountingParty.php';

class GrossDb extends FixtureDb {
    public $contact='0044'; public $delivery=false;
    public function executeS($sql,...$args) {
        if (strpos($sql,'ssbdm_managed_carrier')!==false) { return array(array('id_reference'=>132,'settings_json'=>json_encode(array('hesabfa_contact_code'=>$this->contact)))); }
        return parent::executeS($sql,...$args);
    }
    public function getValue($sql) { return 132; }
    public function getRow($sql,...$args) { return $this->delivery; }
}
function grossFixture($cost='165000') {
    Module::$enabled=false;
    list($oldDb,$repo,$api,$service,$source)=invoiceCarrierFixture($cost);
    $db=new GrossDb(); foreach (get_object_vars($oldDb) as $key=>$value) { $db->$key=$value; }
    Db::$fixture=$db; Module::$enabled=true;
    $source['source_key']='';
    $source['accounting_model']='gross_v2';
    $source['accounting_party']=\Ssb\DeliveryManager\ShippingCost\AccountingParty::resolve($db,1,40001,152);
    $source['accounting_contact_code']=$source['accounting_party']['code'];
    $repo->sourceRow=sourceRevision($source);
    return array($db,$repo,$api,$service,$source);
}
$checks=array();
$assert=static function($name,$pass) use (&$checks) { $checks[]=array('case'=>$name,'pass'=>(bool)$pass); if (!$pass) { throw new RuntimeException($name.' '.json_encode(HesabfaApiResponse::getLastResponse())); } };
foreach (array('100000','165000','110000','0','262180.55') as $cost) {
    list($db,$repo,$api,$service,$source)=grossFixture($cost); $before=$api->invoice;
    $assert('Gross synchronization for cost '.$cost,$service->process(1,40001)===true);
    $expected=$before; $expected['FreightPersonCode']='';
    $assert('Invoice money, payment, items and ownership preserved '.$cost,HesabfaInvoiceCarrierPlan::same($expected,$api->invoice));
    $expense=HesabfaFreightMarginPlan::tomanToRial($cost);
    $assert('Full actual sender expense, independent of customer freight '.$cost,$expense===0 ? $api->remote===null : $api->remote['Debit']===$expense && $api->remote['Credit']===$expense && count($api->remote['Transactions'])===2);
    if ($expense) { $assert('Owned journal identity preserved '.$cost,$api->remote['Number']===90204 && $api->remote['Id']===123456); }
    $count=count($api->writes);
    $assert('Retry creates no duplicate '.$cost,$service->process(1,40001)===true && count($api->writes)===$count);
}
foreach (array('invoiceBeforeTimeout','invoiceAfterTimeout','failAfter') as $failure) {
    list($db,$repo,$api,$service)=grossFixture(); $api->$failure=true;
    try { $service->process(1,40001); } catch (RuntimeException $error) {}
    $assert('Lost response recovers '.$failure,$service->process(1,40001)===true && $api->invoice['FreightPersonCode']==='' && $api->remote['Debit']===1650000);
    $assert('Accepted journal not duplicated '.$failure,numberWrites($api,'document/save')===1);
}
list($db,$repo,$api,$service)=grossFixture(); $db->contact='0055';
$assert('A changed contact blocks stale financial writes',$service->process(1,40001)===false && !$api->writes);
list($db,$repo,$api,$service,$source)=grossFixture(); $source['accounting_blocked']='SHIPMENT_NOT_DISPATCHED'; $repo->sourceRow=sourceRevision($source);
$assert('An assigned but uncollected parcel is deferred',$service->process(1,40001)===false && !$api->writes);
list($db,$repo,$api,$service,$source)=grossFixture(); $source['accounting_blocked']='ACCOUNTING_CONTACT_REQUIRED'; $repo->sourceRow=sourceRevision($source);
$assert('Missing accounting contact is deferred',$service->process(1,40001)===false && !$api->writes);
list($db,$repo,$api,$service,$source)=grossFixture(); $api->invoice['Paid']-=1; $api->remote['Description']='Manual edit';
$assert('Manual accounting changes are preserved',$service->process(1,40001)===false && !$api->writes);
list($db,$repo,$api,$service,$source)=grossFixture(); $source['payment_method']='postpaid'; $source['sender_cost_toman']='0'; $repo->costRow['payment_method']='postpaid'; $repo->sourceRow=sourceRevision($source);
$assert('Recipient-paid shipment has no sender expense',$service->process(1,40001)===true && $api->remote===null);
$partyClass='Ssb\\DeliveryManager\\ShippingCost\\AccountingParty';
$assert('Persian codes retain leading zeros',$partyClass::normalize(' ۰۰۴۴ ')==='0044');
$db->delivery=array('id_delivery'=>17,'id_employee_agent'=>9,'status'=>'assigned','picked_up_at'=>null);
$party=$partyClass::resolve($db,1,40001,152,'courier:17');
$assert('Internal employee cannot silently use carrier contact',$party['kind']==='agent' && $party['id_agent']===9 && $party['code']==='' && !$party['dispatched']);
$db->delivery['status']='picked_up'; $db->delivery['picked_up_at']='2026-10-21 12:00:00';
$assert('Pickup makes the internal shipment dispatchable',$partyClass::resolve($db,1,40001,152,'courier:17')['dispatched']);
$db->delivery=false;
$assert('Missing bound delivery is never assumed dispatched',!$partyClass::resolve($db,1,40001,152,'courier:17')['dispatched']);
echo json_encode(array('success'=>true,'checks'=>count($checks),'tests'=>$checks),JSON_PRETTY_PRINT),"\n";
