<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
define('_PS_VERSION_', '8.1.7');
function check($ok, $message) { if (!$ok) { throw new Exception($message); } }
class HesabfaSecurity { public static function isOperational() { return true; } }
class Configuration {
    public static $cursor=1000;
    public static function get($key) { return self::$cursor; }
}
class HesabfaWebhookChangeRepository {
    public static function getInvoiceItemCodes($invoiceId, $changeId) { return []; }
    public static function getLaterInvoiceDeletionId($invoiceId, $changeId, $action) {
        check(self::$locked, 'Deletion evidence read without processing lock');
        foreach (self::$rows as $row) {
            $change=json_decode($row['payload']);
            if ($row['change_id']>$changeId && $change->ObjectType==='Invoice'
                && $change->ObjectId===$invoiceId && $action===141 && $change->Action===143) {
                return $row['change_id'];
            }
        }
        return null;
    }
    public static function normalizeItemCodes($codes) { return array_values(array_unique(array_filter(array_map('trim', $codes), 'strlen'))); }
    public static function saveInvoiceItemCodes($changeId, $invoiceId, $codes) { self::$rows[$changeId]['invoice_item_codes']=$codes; return true; }
    public static $locked=false, $available=true, $rows=[], $recovered=0;
    public static function acquireProcessingLock() { return self::$locked=self::$available; }
    public static function releaseProcessingLock() { self::$locked=false; }
    public static function recoverStaleRunning() {
        check(self::$locked, 'Recovery without exclusive lock');
        self::$recovered++;
        foreach (self::$rows as &$r) { if ($r['status']==='running') { $r['status']='pending'; } }
        return true;
    }
    public static function getPending($limit) { return array_values(array_filter(self::$rows,function($r){return in_array($r['status'],['pending','failed']);})); }
    public static function getSupersededProductChangeIds($ids) { return []; }
    public static function markRunning($id) { self::$rows[$id]['status']='running'; return true; }
    public static function markDone($id) { self::$rows[$id]['status']='done'; return true; }
    public static function markFailed($id,$error) { self::$rows[$id]['status']='failed'; return true; }
    public static function advanceCheckpoint($id) { Configuration::$cursor=max(Configuration::$cursor,$id); return true; }
    public static function countByStatuses($status) { return count(array_filter(self::$rows,function($r)use($status){return $r['status']===$status;})); }
}
class HesabfaApi {
    public static $invoiceFetch=0, $itemFetch=0, $emptyInvoice=false;
    public function invoiceGetById($ids) {
        self::$invoiceFetch++;
        if(self::$emptyInvoice) { check($ids===[150924], 'Wrong invoice fetched'); return (object)['Success'=>true,'Result'=>[]]; }
        throw new Exception('Deleted invoice must not be fetched');
    }
    public function itemGetItemsByCodes($codes) {
        self::$itemFetch++;
        return (object)['Success'=>true,'Result'=>(object)['List'=>array_map(function($code){return (object)['Code'=>$code,'Tag'=>'{"id_product":7}'];},$codes)]];
    }
}
class Module { public static function isInstalled($name) { return false; } }
class Ssbhesabfa { public static function addLegacyLog(...$args) {} }
require dirname(__DIR__).'/classes/services/HesabfaWebhookService.php';
class Handler {
    public $items=0, $fail=false;
    public function setItemChanges(...$args) { if($this->fail) {throw new TypeError('test interrupted handler');} $this->items++; return true; }
}
function seed($id=5) {
    HesabfaWebhookChangeRepository::$rows=[$id=>['change_id'=>$id,'attempts'=>1,'status'=>'running','payload'=>json_encode(['Id'=>$id,'API'=>false,'ObjectType'=>'Invoice','Action'=>123,'ObjectId'=>99,'Extra'=>'123'])]];
}
$handler=new Handler(); $service=new HesabfaWebhookService($handler);
seed(); $result=$service->processPendingOnly(10);
check($result['processed_count']===1 && $handler->items===1,'Deleted invoice did not refresh affected items');
check(HesabfaApi::$invoiceFetch===0 && HesabfaApi::$itemFetch===1,'Wrong deletion API behavior');
check(Configuration::$cursor===1000,'Cursor rewound');
check(!HesabfaWebhookChangeRepository::$locked,'Lock leaked');
$service->processPendingOnly(10); check($handler->items===1,'Completed row replayed');
seed(); HesabfaWebhookChangeRepository::$available=false;
$before=HesabfaWebhookChangeRepository::$recovered; $service->processPendingOnly(10);
check(HesabfaWebhookChangeRepository::$recovered===$before,'Recovery ran without lock');
HesabfaWebhookChangeRepository::$available=true; $handler->fail=true;
$result=$service->processPendingOnly(10);
check($result['failed_count']===1 && HesabfaWebhookChangeRepository::$rows[5]['status']==='failed','Throwable left a running row');
check(!HesabfaWebhookChangeRepository::$locked,'Failure leaked lock');

// An empty invoice with a later deletion advances only the event actually completed.
Configuration::$cursor=2252535; HesabfaApi::$emptyInvoice=true;
HesabfaApi::$invoiceFetch=HesabfaApi::$itemFetch=0;
HesabfaWebhookChangeRepository::$rows=[];
foreach ([2252536=>141,2252537=>143] as $id=>$action) {
    HesabfaWebhookChangeRepository::$rows[$id]=[
        'change_id'=>$id,'attempts'=>0,'status'=>'pending','invoice_item_codes'=>null,
        'payload'=>json_encode(['Id'=>$id,'API'=>false,'ObjectType'=>'Invoice','Action'=>$action,'ObjectId'=>150924,'Extra'=>'009645'])
    ];
}
$handler=new Handler(); $service=new HesabfaWebhookService($handler);
$result=$service->processPendingOnly(1);
check($result['processed_count']===1 && $result['failed_count']===0,'Deleted predecessor did not complete');
check(Configuration::$cursor===2252536,'Fallback skipped ahead to the future deletion checkpoint');
check(HesabfaWebhookChangeRepository::$rows[2252536]['status']==='done'
    && HesabfaWebhookChangeRepository::$rows[2252537]['status']==='pending','Later deletion processed before its turn');
check(HesabfaWebhookChangeRepository::$rows[2252536]['invoice_item_codes']===['009645']
    && HesabfaWebhookChangeRepository::$rows[2252537]['invoice_item_codes']===null,'Fallback mutated the future snapshot');
$result=$service->processPendingOnly(1);
check($result['processed_count']===1 && Configuration::$cursor===2252537
    && HesabfaWebhookChangeRepository::$rows[2252537]['status']==='done','Later deletion did not complete in order');
check(HesabfaApi::$invoiceFetch===1 && HesabfaApi::$itemFetch===2 && $handler->items===2,'Deletion replay fetched invoice or skipped authoritative inventory');
check($result['remaining_count']===0 && !HesabfaWebhookChangeRepository::$locked,'Final queue state or lock was incorrect');
echo "PASS: interrupted replay, ordered deleted-before-replay recovery, deletion inventory refresh, unchanged cursor, no duplicate replay, lock contention, Throwable handling\n";
