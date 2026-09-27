<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
define('_PS_VERSION_', '8.1.7');
function check($ok, $message) { if (!$ok) { throw new Exception($message); } }
class Configuration {
    public static $cursor=1000;
    public static function get($key) { return self::$cursor; }
}
class HesabfaWebhookChangeRepository {
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
    public static $invoiceFetch=0, $itemFetch=0;
    public function invoiceGetById($ids) { self::$invoiceFetch++; throw new Exception('Deleted invoice must not be fetched'); }
    public function itemGetItems($query) { self::$itemFetch++; return (object)['Success'=>true,'Result'=>(object)['List'=>[(object)['Code'=>'123','Tag'=>'{"id_product":7}']]]]; }
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
echo "PASS: interrupted replay, deletion inventory refresh, unchanged cursor, no duplicate replay, lock contention, Throwable handling\n";
