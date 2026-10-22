<?php
/** Fresh live service/plan/repository; all SQL, queues and remote API calls are fixtures. */
define('_PS_VERSION_','8.1.7'); define('_DB_PREFIX_','fixture_');
function pSQL($value,$html=false) { return addslashes((string)$value); }
class Module { public static $enabled=false; public static function isEnabled($name) { return self::$enabled; } }
class Configuration { public static function get($key) { return $key==='SSBHESABFA_LIVE_MODE'; } }
class Validate { public static function isLoadedObject($object) { return !empty($object->id); } }
class Carrier {
    public $id; public $id_reference;
    public function __construct($id) { $this->id=(int)$id; $this->id_reference=$id===138?128:132; }
}
class HesabfaLock {
    public static $held=array();
    public static function acquire($key) { if (!empty(self::$held[$key])) { return false; } self::$held[$key]=true; return true; }
    public static function release($key) { unset(self::$held[$key]); }
}
class HesabfaJobRepository {
    public static $enqueued=array(); public static $finished=array();
    public static function enqueue($type,$payload,$object,$id) { self::$enqueued[]=array($type,$payload,$object,$id); return 100+count(self::$enqueued); }
    public static function markFinished($id) { self::$finished[]=$id; return true; }
}
class FixtureDb {
    public $native=array(); public $config=array(); public $urban=array('provider_id'=>50001,'status'=>'delivered','delivery_status'=>'delivered','id_carrier'=>152,'id_shop'=>1); public $tipax=null; public $mahex=null;
    public function __construct() { $this->config=array('SSBHESABFA_FREIGHT_MARGIN_ENABLED'=>'1','SSBHESABFA_FREIGHT_PERSON_128'=>'0033','SSBHESABFA_FREIGHT_PERSON_132'=>'0044'); }
    public function executeS($sql,...$arguments) {
        if (preg_match("/FROM `fixture_configuration` WHERE name = '([^']+)' AND id_shop = 1/",$sql,$m)) { return isset($this->config[$m[1]])?array(array('id_configuration'=>1,'value'=>$this->config[$m[1]])):array(); }
        if (strpos($sql,'FROM `fixture_carrier` c')!==false) { return array(array('id_carrier'=>138,'id_reference'=>128,'name'=>'Fixture one','active'=>1),array('id_carrier'=>152,'id_reference'=>132,'name'=>'Fixture two','active'=>1)); }
        if (strpos($sql,'SELECT o.id_carrier,oc.id_order_carrier')===0) { return $this->native; }
        if (strpos($sql,'ssb_delivery_manager_alopeyk')!==false || strpos($sql,'ssb_delivery_manager_snapp')!==false) { return $this->urban ? array($this->urban) : array(); }
        if (strpos($sql,'FROM `fixture_ssbdm_tipax_booking`')!==false) { return $this->tipax ? array($this->tipax) : array(); }
        if (strpos($sql,'FROM `fixture_ssbdm_mahex_booking`')!==false) { return $this->mahex ? array($this->mahex) : array(); }
        throw new RuntimeException('Unexpected fixture SQL.');
    }
}
class Db { public static $fixture; public static function getInstance() { return self::$fixture; } }
require dirname(__DIR__).'/classes/HesabfaFreightMarginRepository.php';
require dirname(__DIR__).'/classes/HesabfaFreightMarginSettings.php';
require dirname(__DIR__).'/classes/HesabfaFreightPerson.php';
require dirname(__DIR__).'/classes/HesabfaFreightMarginPlan.php';
require dirname(__DIR__).'/classes/HesabfaApiResponse.php';
require dirname(__DIR__).'/classes/services/HesabfaFreightMarginService.php';
class FixtureRepository extends HesabfaFreightMarginRepository {
    public $stored; public $sourceRow; public $orderRow; public $costRow; public $events=array(); public $queue=array();
    public function ready() { return true; }
    public function source($shop,$id) { return $this->sourceRow; }
    public function order($shop,$id) { return $this->orderRow; }
    public function state($shop,$id) { return $this->stored; }
    public function ensure($shop,$id) { return $this->stored; }
    public function shippingCost($shop,$id) { return $this->costRow; }
    public function save($shop,$id,array $values) { $this->stored=array_replace($this->stored,$values); }
    public function audit($shop,$id,$event,array $details) { $this->events[]=array($event,$details); }
    public function jobs($shop,$id) { return $this->queue; }
}
class FixtureModule {
    public function isHesabfaSyncEnabled() { return true; }
    public function getInvoiceCodeByOrderId($id) { return 18001; }
}
function remoteDocument(array $planned,$number=90204,$id=123456) {
    $out=array(); foreach ($planned as $key=>$value) {
        if ($key==='transactions') { $value=array_map(static function($row) { $out=array(); foreach($row as $key=>$value) { $out[ucfirst($key)]=$value; } return $out; },$value); }
        $out[ucfirst($key)]=$value;
    }
    $out['Number']=$number; $out['Id']=$id;
    // The documented live response assigns Reference=Number on these saves.
    $out['Reference']=$number; return $out;
}
class FixtureApi {
    public $apiKey='offline-fixture-only'; public $invoice; public $remote; public $writes=array(); public $failBefore=false; public $failAfter=false;
    public $year=array('StartDate'=>'2026-01-01','EndDate'=>'2026-12-31');
    public function ok($result) { return array('Success'=>true,'Result'=>$result); }
    public function settingGetFiscalYear() { return $this->ok($this->year); }
    public function invoiceGet($number,$type) { return $this->ok($this->invoice); }
    public function documentGet($number) { if (!$this->remote || (int)$number!==(int)$this->remote['Number']) { return array('Success'=>false,'ErrorMessage'=>'Fixture document absent'); } return $this->ok($this->remote); }
    public function documentGetDocuments($filter) {
        $rule=$filter['filters'][0]; $match=$this->remote && (string)($this->remote[$rule['property']] ?? '')===(string)$rule['value'];
        return $this->ok(array('FilteredCount'=>$match?1:0,'List'=>$match?array(array('Number'=>$this->remote['Number'])):array()));
    }
    public function apiRequest($method,$payload) {
        $this->writes[]=array($method,$payload);
        if ($this->failBefore) { $this->failBefore=false; throw new RuntimeException('Fixture timeout before acceptance'); }
        if ($method==='document/save') { $this->remote=remoteDocument($payload['document']); }
        elseif ($method==='document/delete') { $this->remote=null; }
        else { throw new RuntimeException('Unexpected remote operation'); }
        if ($this->failAfter) { $this->failAfter=false; throw new RuntimeException('Fixture response lost after acceptance'); }
        return $this->ok($this->remote ? array('Number'=>$this->remote['Number'],'Id'=>$this->remote['Id']) : null);
    }
}
function sourceRevision(array $source) { return array('revision_hash'=>hash('sha256',json_encode($source)),'payload_json'=>json_encode($source)); }
function fixture($carrier=138) {
    $db=new FixtureDb(); Db::$fixture=$db;
    $db->native=array(array('id_carrier'=>$carrier,'id_order_carrier'=>49001,'shipment_carrier'=>$carrier,'tracking_number'=>'NEW40001'));
    $repo=new FixtureRepository($db); $api=new FixtureApi();
    $person=$carrier===138?'0033':'0044';
    $repo->orderRow=array('id_shop'=>1,'id_order'=>40001,'id_carrier'=>$carrier,'reference'=>'DEMOORDER');
    $api->invoice=array('Number'=>18001,'Reference'=>'DEMOORDER','Tag'=>array('id_order'=>40001),'InvoiceType'=>0,'Status'=>1,'Returned'=>false,'Currency'=>'IRR','CurrencyRate'=>1,'Freight'=>3000000,'FreightPersonCode'=>$person,'Project'=>'');
    $source=array('id_shop'=>1,'id_order'=>40001,'id_carrier'=>$carrier,'source'=>'provider_reconciliation','source_key'=>str_repeat('a',64),'tracking'=>array('OLD40001'),
        'cost_total_toman'=>'240497','sender_cost_toman'=>'240497','insurance_toman'=>'0','payment_method'=>'prepaid','accounting_blocked'=>'','captured_at'=>'2026-10-18 00:01:00');
    $settings=HesabfaFreightMarginSettings::getFormValues(1); $settings['person_code']=$person;
    $oldPlan=HesabfaFreightMarginPlan::build($repo->orderRow,$source,$api->invoice,$settings,'2026-10-18',90204);
    $api->remote=remoteDocument($oldPlan['document']);
    $repo->stored=array('account_fingerprint'=>hash('sha256',$api->apiKey.'|'.$api->year['StartDate'].'|'.$api->year['EndDate']),
        'source_revision'=>sourceRevision($source)['revision_hash'],'policy_hash'=>HesabfaFreightMarginService::policy(1),'document_number'=>'90204','document_id'=>123456,
        'verified_document'=>json_encode($api->remote),'margin_rial'=>595030,'version'=>1,'pending_json'=>null);
    $source['tracking']=array('NEW40001'); $source['tracking_source_key']=$repo->trackingSource(1,40001)['source_key'];
    $source['cost_total_toman']=$source['sender_cost_toman']='262180';
    $repo->sourceRow=sourceRevision($source); $repo->costRow=array('id_carrier'=>$carrier,'payment_method'=>'prepaid','cost'=>'262180','insurance'=>'0');
    return array($db,$repo,$api,new HesabfaFreightMarginService(new FixtureModule(),$repo,$api),$source);
}
$checks=array();
$assert=static function($name,$pass) use (&$checks) { $checks[]=array('case'=>$name,'pass'=>(bool)$pass); if (!$pass) { throw new RuntimeException($name); } };
list($db,$repo,$api,$service,$source)=fixture();
$assert('Changed cost and barcode update succeeds',$service->process(1,40001)===true);
$assert('Same owned document identity is retained',$api->remote['Number']===90204 && $api->remote['Id']===123456);
$assert('New margin is378200rial',$repo->stored['margin_rial']===378200 && $api->remote['Debit']===378200 && $api->remote['Credit']===378200);
$assert('New barcode appears and old barcode disappears',strpos($api->remote['Description'],'NEW40001')!==false && strpos($api->remote['Description'],'OLD40001')===false);
$assert('Exactly one journal save and verified local completion',count($api->writes)===1 && $api->writes[0][0]==='document/save' && $repo->stored['pending_json']===null && $repo->stored['source_revision']===$repo->sourceRow['revision_hash']);
$assert('Replaying verified work succeeds without another remote save',$service->process(1,40001)===true && count($api->writes)===1);
$db->native[0]['tracking_number']='SAMEPRICE40001'; $source['tracking']=array('SAMEPRICE40001'); $source['tracking_source_key']=$repo->trackingSource(1,40001)['source_key']; $repo->sourceRow=sourceRevision($source);
$assert('Same-price replacement still updates document tracking',$service->process(1,40001)===true && count($api->writes)===2 && strpos($api->remote['Description'],'SAMEPRICE40001')!==false && $repo->stored['margin_rial']===378200);
list($db,$repo,$api,$service,$source)=fixture(); $db->native[0]['tracking_number']='REPLACEDAGAIN';
$assert('Stale queued native identity is blocked before financial writes',$service->process(1,40001)===false && !$api->writes && $repo->stored['pending_json']===null);
list($db,$repo,$api,$service,$source)=fixture(); unset($source['tracking_source_key']); $source['tracking']=array('OLD40001'); $repo->sourceRow=sourceRevision($source);
$assert('Legacy provider source with stale codes is blocked',$service->process(1,40001)===false && !$api->writes);
foreach (array('cancel_unknown','cancel_pending_local','unknown','pending','cancelled') as $status) {
    list($db,$repo,$api,$service,$source)=fixture(); $db->tipax=array('status'=>$status,'tracking_codes'=>json_encode(array('NEW40001')));
    $assert('Matching Tipax '.$status.' booking blocks new financial writes',$service->process(1,40001)===false && !$api->writes);
}
list($db,$repo,$api,$service,$source)=fixture(); $db->mahex=array('status'=>'cancel_pending_local','waybill_number'=>'UNRELATED-WAYBILL','packages'=>json_encode(array(array('id'=>'NEW40001','weight'=>1))));
$assert('Matching Mahex parcel-ID cancellation blocks new financial writes',$service->process(1,40001)===false && !$api->writes);
list($db,$repo,$api,$service,$source)=fixture(); $db->mahex=array('status'=>'confirmed','waybill_number'=>'UNRELATED-WAYBILL','packages'=>json_encode(array(array('id'=>'NEW40001','weight'=>1))));
$assert('Confirmed Mahex parcel ID allows accounting independently of waybill number',$service->process(1,40001)===true && count($api->writes)===1);
list($db,$repo,$api,$service,$source)=fixture(); $db->tipax=array('status'=>'confirmed','tracking_codes'=>json_encode(array('NEW40001')));
$assert('Confirmed matching booking allows accounting',$service->process(1,40001)===true && count($api->writes)===1);
list($db,$repo,$api,$service,$source)=fixture(); $db->tipax=array('status'=>'cancel_unknown','tracking_codes'=>json_encode(array('NEW40001'))); $source['source']='manual'; $repo->sourceRow=sourceRevision($source);
$assert('Explicit manual actual expense remains permitted during cancellation',$service->process(1,40001)===true && count($api->writes)===1);
list($db,$repo,$api,$service,$source)=fixture(152); $db->native[0]['tracking_number']=''; $source['source_key']='alopeyk:50001'; $source['tracking']=array('50001'); $source['tracking_source_key']=$repo->trackingSource(1,40001)['source_key']; $repo->sourceRow=sourceRevision($source);
$assert('AloPeyk provider IDs remain distinct from empty native codes',$service->process(1,40001)===true && count($api->writes)===1);
list($db,$repo,$api,$service,$source)=fixture(152); $source['source_key']='alopeyk:50000'; $source['tracking']=array('50000'); $repo->sourceRow=sourceRevision($source);
$assert('Replaced urban provider ID is blocked despite unchanged native rows',$service->process(1,40001)===false && !$api->writes);
list($db,$repo,$api,$service,$source)=fixture(152); $source['source_key']='snapp:SNP-OLD50001'; $source['tracking']=array('SNP-OLD50001'); $db->urban['provider_id']='SNP-OLD50001'; $db->urban['status']='cancelled_by_customer'; $repo->sourceRow=sourceRevision($source);
$assert('Cancelled Snapp ride cannot feed a financial write',$service->process(1,40001)===false && !$api->writes);
list($db,$repo,$api,$service,$source)=fixture(); $api->remote['Description']='Externally edited';
$assert('Manual remote journal edits stop automatic updates',$service->process(1,40001)===false && !$api->writes);
list($db,$repo,$api,$service,$source)=fixture(); $repo->stored['account_fingerprint']=str_repeat('f',64);
$assert('Changed accounting business or fiscal year is blocked',$service->process(1,40001)===false && !$api->writes);
list($db,$repo,$api,$service,$source)=fixture(); $api->failAfter=true;
try { $service->process(1,40001); $assert('Lost-response fixture throws',false); } catch (RuntimeException $error) { $assert('Lost response keeps durable pending write',!empty($repo->stored['pending_json'])); }
$key=json_decode($repo->stored['pending_json'],true)['request_unique_id'];
$assert('Accepted timeout recovery uses readback without duplicate write',$service->process(1,40001)===true && count($api->writes)===1 && $repo->stored['pending_json']===null);
$assert('Accepted request UUID remains the original write identity',$api->writes[0][1]['requestUniqueId']===$key);
list($db,$repo,$api,$service,$source)=fixture(); $api->failAfter=true;
try { $service->process(1,40001); } catch (RuntimeException $error) {}
$source['cost_total_toman']=$source['sender_cost_toman']='270000'; $repo->sourceRow=sourceRevision($source); $repo->costRow['cost']='270000';
$assert('Accepted old request is recovered then latest fee corrected',$service->process(1,40001)===true && count($api->writes)===2 && $repo->stored['margin_rial']===300000 && $repo->stored['pending_json']===null);
$assert('New fee correction has a separate durable request UUID',$api->writes[0][1]['requestUniqueId']!==$api->writes[1][1]['requestUniqueId']);
list($db,$repo,$api,$service,$source)=fixture(); $api->failBefore=true;
try { $service->process(1,40001); } catch (RuntimeException $error) {}
$pending=$repo->stored['pending_json']; $db->native[0]['tracking_number']='CHANGEDAFTERTIMEOUT';
$assert('Unaccepted stale pending request is not resent',$service->process(1,40001)===false && count($api->writes)===1 && $repo->stored['pending_json']===$pending);
list($db,$repo,$api,$service,$source)=fixture(); $api->failBefore=true;
try { $service->process(1,40001); } catch (RuntimeException $error) {}
$pending=$repo->stored['pending_json']; $source['cost_total_toman']=$source['sender_cost_toman']='270000'; $repo->sourceRow=sourceRevision($source); $repo->costRow['cost']='270000';
$assert('Unaccepted pending old fee is not resent after same-barcode cost change',$service->process(1,40001)===false && count($api->writes)===1 && $repo->stored['pending_json']===$pending);
list($db,$repo,$api,$service,$source)=fixture();
$settings=HesabfaFreightMarginSettings::getFormValues(1); $settings['person_code']='0044'; $order=$repo->orderRow; $order['id_carrier']=152; $source['id_carrier']=152;
foreach (array('200000'=>1000000,'300000'=>0,'400000'=>-1000000,'0'=>3000000) as $cost=>$margin) {
    $source['sender_cost_toman']=$source['cost_total_toman']=(string)$cost;
    $plan=HesabfaFreightMarginPlan::build($order,$source,$api->invoice,$settings,'2026-10-18',90204);
    $assert('Cross-carrier margin '.$margin.' retains a journal',is_array($plan['document']) && $plan['margin_rial']===$margin && $plan['document']['number']===90204);
    $balances=array('0033'=>-3000000,'0044'=>0); $debit=0; $credit=0;
    foreach ($plan['document']['transactions'] as $row) {
        if ($row['type']===0) { $debit+=$row['amount']; } else { $credit+=$row['amount']; }
        if (isset($balances[$row['contactCode']])) { $balances[$row['contactCode']]+=$row['type']===0?$row['amount']:-$row['amount']; }
    }
    $assert('Cross-carrier journal '.$margin.' balances and clears old payable',$debit===$credit && $debit===$plan['document']['debit'] && $balances['0033']===0 && $balances['0044']===-((int)$cost*10));
}
$settings['person_code']='0033'; $source['id_carrier']=$order['id_carrier']=138; $source['sender_cost_toman']=$source['cost_total_toman']='300000';
$assert('Same-person zero margin requires no redundant journal',HesabfaFreightMarginPlan::build($order,$source,$api->invoice,$settings,'2026-10-18',90204)['document']===null);
list($db,$repo,$api,$service,$source)=fixture(); $repo->queue=array(array('id_ssb_hesabfa_job'=>91,'payload'=>json_encode(array('source_revision'=>$repo->sourceRow['revision_hash'],'policy_hash'=>HesabfaFreightMarginService::policy(1))),'status'=>'pending'));
$assert('Existing pending job is reused',$service->enqueue(1,40001)===91 && !HesabfaJobRepository::$enqueued);
$repo->queue[0]['status']='needs_attention'; $repo->queue[0]['payload']='{}';
$assert('Changed-source rejected preflight can be superseded',$service->enqueue(1,40001)===101 && HesabfaJobRepository::$finished===array(91));
$repo->stored['pending_json']='{"durable":"request"}'; $finished=count(HesabfaJobRepository::$finished); $enqueued=count(HesabfaJobRepository::$enqueued);
$assert('Outstanding ambiguous financial intent is never bypassed',$service->enqueue(1,40001,true)===91 && count(HesabfaJobRepository::$finished)===$finished && count(HesabfaJobRepository::$enqueued)===$enqueued);
// Hash compatibility includes row identities, Persian/Arabic digits, case and empty codes.
$db->native=array(array('id_carrier'=>138,'id_order_carrier'=>1,'shipment_carrier'=>138,'tracking_number'=>' ab۱۲٣ '),array('id_carrier'=>138,'id_order_carrier'=>2,'shipment_carrier'=>138,'tracking_number'=>''));
$identity=$repo->trackingSource(1,40001);
$assert('Native identity matches delivery hash contract',$identity['source_key']===hash('sha256',json_encode(array(138,array(1,138,'AB123'),array(2,138,'')))) && $identity['tracking']===array('AB123'));
echo json_encode(array('success'=>true,'checks'=>count($checks),'scope'=>'Real fresh-live freight service, plan, settings and native-identity repository; database, queue storage, module configuration and remote API are offline fixtures. No production writes.','tests'=>$checks),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),"\n";

