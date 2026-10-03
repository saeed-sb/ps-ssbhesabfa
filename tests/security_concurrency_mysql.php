<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$dsn = getenv('SSBH_TEST_MYSQL_DSN');
if (!$dsn) { echo "SKIP: isolated MySQL DSN is required (mandatory in release CI).\n"; exit; }
if (!preg_match('/(?:^|;)dbname=(codex_ssbh_security_[a-zA-Z0-9_]+)(?:;|$)/', $dsn, $match)) { throw new RuntimeException('Only an isolated codex_ssbh_security_* database is allowed.'); }
define('_DB_NAME_', $match[1]); define('_DB_PREFIX_', 'fixture_'); define('_PS_VERSION_', '8.1.7'); define('_MYSQL_ENGINE_', 'InnoDB');
function check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
function pSQL($value, $html = false) { return addslashes((string)$value); }
class DbQuery {
    private $select=array(), $from='', $where=array(), $order='', $limit='';
    public function select($v) { $this->select[]=$v; return $this; }
    public function from($v) { $this->from='`'._DB_PREFIX_.$v.'`'; return $this; }
    public function where($v) { $this->where[]='('.$v.')'; return $this; }
    public function orderBy($v) { $this->order=$v; return $this; }
    public function limit($n,$offset=0) { $this->limit=(int)$offset.','.(int)$n; return $this; }
    public function __toString() { return 'SELECT '.implode(',',$this->select).' FROM '.$this->from.($this->where?' WHERE '.implode(' AND ',$this->where):'').($this->order?' ORDER BY '.$this->order:'').($this->limit?' LIMIT '.$this->limit:''); }
}
class Db {
    private static $instance;
    public $pdo, $affected=0, $failPersist=false;
    public static function getInstance() { if (!self::$instance) { self::$instance=new self(); } return self::$instance; }
    public function __construct() { $this->pdo=new PDO(getenv('SSBH_TEST_MYSQL_DSN'),getenv('SSBH_TEST_MYSQL_USER')?:'root',getenv('SSBH_TEST_MYSQL_PASSWORD')?:'',array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION)); }
    public function execute($sql) { $this->affected=$this->pdo->exec((string)$sql); return true; }
    public function getRow($sql,$cache=true) { check($cache===false,'Security row read used cache'); return $this->pdo->query((string)$sql)->fetch(PDO::FETCH_ASSOC); }
    public function getValue($sql,$cache=true) { check($cache===false,'Security scalar read used cache'); return $this->pdo->query((string)$sql)->fetchColumn(); }
    public function executeS($sql,$array=true,$cache=true) { check($cache===false,'Security list read used cache'); return $this->pdo->query((string)$sql)->fetchAll(PDO::FETCH_ASSOC); }
    private function value($v) { return $v===null?'NULL':$this->pdo->quote(stripslashes((string)$v)); }
    public function insert($table,$data) { return $this->execute('INSERT INTO `'._DB_PREFIX_.$table.'` (`'.implode('`,`',array_keys($data)).'`) VALUES ('.implode(',',array_map(array($this,'value'),$data)).')'); }
    public function update($table,$data,$where) { if ($this->failPersist && isset($data['request_unique_ids'])) { return false; } $set=array(); foreach($data as $k=>$v) { $set[]='`'.$k.'`='.$this->value($v); } return $this->execute('UPDATE `'._DB_PREFIX_.$table.'` SET '.implode(',',$set).' WHERE '.$where); }
    public function Insert_ID() { return $this->pdo->lastInsertId(); }
    public function Affected_Rows() { return $this->affected; }
}
class Configuration { public static function get($key) { return $key==='SSBHESABFA_JOB_MAX_ATTEMPTS'?5:1; } }
class HesabfaSecurity { public static function isOperational() { return true; } }
class Ssbhesabfa { public static function addModuleLog() {} public static function addLegacyLog() {} }
class Validate { public static function isLoadedObject($v) { return is_object($v); } }
require dirname(__DIR__).'/classes/HesabfaLock.php';
require dirname(__DIR__).'/classes/HesabfaRequestUniqueId.php';
require dirname(__DIR__).'/classes/HesabfaRetryPolicy.php';
require dirname(__DIR__).'/classes/HesabfaApiResponse.php';
require dirname(__DIR__).'/classes/HesabfaJobRepository.php';
require dirname(__DIR__).'/classes/HesabfaInternalApiRequestRepository.php';
require dirname(__DIR__).'/classes/HesabfaOperationRepository.php';
require dirname(__DIR__).'/classes/services/HesabfaQueueService.php';
require dirname(__DIR__).'/classes/traits/HesabfaInternalApiTrait.php';
require dirname(__DIR__).'/upgrade/upgrade-2.3.35.php';
class FixtureModule {
    public function l($v) { return $v; }
    public function isHesabfaSyncEnabled() { return true; }
    public function isHesabfaApiConfigured() { return true; }
}
function outboundWrite($id,$uuid) { Db::getInstance()->insert('outbox',array('logical_id'=>$id,'uuid'=>$uuid)); }
function holdWorker($barrier) { if($barrier) { file_put_contents($barrier,'ready'); usleep(500000); } }
class QueueFixture extends HesabfaQueueService {
    public $barrier;
    protected function executeJob(array $job,array $payload) {
        $uuid=HesabfaRequestUniqueId::generate('item/save',$payload);
        holdWorker($this->barrier);
        outboundWrite('job:'.$job['id_ssb_hesabfa_job'],$uuid); return true;
    }
}
class InternalFixture extends FixtureModule {
    use HesabfaInternalApiTrait;
    public $barrier;
    protected function executeInternalApiCall($method,array $arguments=array(),$requester=null,$type=null,$id=null) {
        $uuid=HesabfaRequestUniqueId::generate('document/save',$arguments);
        holdWorker($this->barrier); outboundWrite('internal:'.$id,$uuid); return array('success'=>true);
    }
}
if (isset($argv[1])) {
    $type=$argv[1]; $id=$argv[2]; $barrier=$argv[3];
    if ($type==='operation') {
        check(HesabfaOperationRepository::start($id,'invoice_payment','Order',1),'Child did not claim operation');
        $uuid=HesabfaRequestUniqueId::generate('invoice/savepayment',array('amount'=>100));
        holdWorker($barrier); outboundWrite('operation:'.$id,$uuid);
        check(HesabfaOperationRepository::finish($id,'success','done'),'Could not finish operation');
    } elseif ($type==='queue') {
        $service=new QueueFixture(new FixtureModule()); $service->barrier=$barrier;
        check($service->processRow(HesabfaJobRepository::getById($id)),'Child queue failed');
    } elseif ($type==='internal') {
        $service=new InternalFixture(); $service->barrier=$barrier;
        check($service->runInternalApiRequest($id)['success'],'Child internal queue failed');
    }
    echo "child completed\n"; exit;
}
$db=Db::getInstance();
foreach ($db->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
    check(strpos($table,'fixture_')===0,'Non-fixture table in test database'); $db->execute('DROP TABLE `'.$table.'`');
}
check((include dirname(__DIR__).'/sql/install.php')===true,'Fresh schema install failed');
$db->execute('CREATE TABLE fixture_outbox (logical_id VARCHAR(191) NOT NULL, uuid CHAR(36) NOT NULL)');
function spawn($type,$id) {
    $barrier=sys_get_temp_dir().'/ssbh-test-'.bin2hex(random_bytes(8));
    $process=proc_open(array(PHP_BINARY,'-d','display_errors=1','-d','disable_functions=',__FILE__,$type,(string)$id,$barrier),array(1=>array('pipe','w'),2=>array('pipe','w')),$pipes);
    for ($i=0;$i<100&&!is_file($barrier);$i++) { usleep(10000); }
    if (!is_file($barrier)) { throw new RuntimeException('Worker failed before taking lock: '.stream_get_contents($pipes[2])); }
    return array($process,$pipes,$barrier);
}
function joinWorker($worker) {
    list($process,$pipes,$barrier)=$worker; $output=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    foreach($pipes as $pipe) { fclose($pipe); } $exit=proc_close($process); unlink($barrier); check($exit===0,'Child failed: '.$output);
}
$worker=spawn('operation','financial-key');
check(!HesabfaOperationRepository::start('financial-key','invoice_payment','Order',1),'Two processes owned one financial write');
joinWorker($worker);
check(!HesabfaOperationRepository::start('financial-key','invoice_payment','Order',1),'Successful write replayed');
check((int)$db->getValue('SELECT COUNT(*) FROM fixture_outbox',false)===1,'Duplicate financial outbound write');
check(HesabfaOperationRepository::start('retry-key','invoice_payment','Order',1),'Initial financial claim failed');
$first=HesabfaRequestUniqueId::generate('invoice/savepayment',array('amount'=>100));
HesabfaOperationRepository::finish('retry-key','failed','timeout');
check(HesabfaOperationRepository::start('retry-key','invoice_payment','Order',1),'Retry claim failed');
check(HesabfaRequestUniqueId::generate('invoice/savepayment',array('amount'=>100))===$first,'Ambiguous retry changed UUID');
HesabfaOperationRepository::finish('retry-key','failed','timeout');
check(HesabfaOperationRepository::start('retry-key','invoice_payment','Order',1),'Changed-payload test claim failed');
$blocked=false; try { HesabfaRequestUniqueId::generate('invoice/savepayment',array('amount'=>101)); } catch (RuntimeException $e) { $blocked=true; }
HesabfaOperationRepository::releaseOwned(); check($blocked,'Changed ambiguous financial payload sent with new UUID');
$db->execute('UPDATE fixture_ssb_hesabfa_operation SET request_unique_ids_created_at=DATE_SUB(NOW(),INTERVAL 25 HOUR) WHERE operation_key="retry-key"');
check(!HesabfaOperationRepository::start('retry-key','invoice_payment','Order',1),'Expired UUID allowed new write');
check(HesabfaOperationRepository::getByKey('retry-key')['status']==='needs_attention','Expired operation not held for reconciliation');
check(HesabfaOperationRepository::start('persist-failure','invoice_payment','Order',1),'Persistence test claim failed');
$db->failPersist=true; $blocked=false; try { HesabfaRequestUniqueId::generate('invoice/savepayment',array('amount'=>1)); } catch (RuntimeException $e) { $blocked=true; }
$db->failPersist=false; HesabfaOperationRepository::releaseOwned(); check($blocked,'Persistence failure did not prevent write');

$id=HesabfaJobRepository::enqueue('sync_product',array('id_product'=>7),'Product',7); $snapshot=HesabfaJobRepository::getById($id);
$worker=spawn('queue',$id); $running=HesabfaJobRepository::getById($id);
check(!HesabfaJobRepository::markDeadManually($id) && !HesabfaJobRepository::requeue($id,false), 'Administrative mutation bypassed active worker lock');
$service=new QueueFixture(new FixtureModule()); check(!$service->processRow($snapshot),'Stale snapshot ran concurrently');
$successor=HesabfaJobRepository::enqueue('sync_product',array('id_product'=>7,'price'=>2),'Product',7);
check($successor!==$id && $successor>0,'Enqueue rewrote a running job');
check(HesabfaJobRepository::getById($id)['request_unique_ids']===$running['request_unique_ids'],'Enqueue erased running UUID');
check(!$service->processRow(HesabfaJobRepository::getById($successor)),'Successor bypassed earlier live job');
$db->execute('UPDATE fixture_ssb_hesabfa_job SET locked_at=DATE_SUB(NOW(),INTERVAL 20 MINUTE) WHERE id_ssb_hesabfa_job='.(int)$id);
HesabfaJobRepository::recoverStaleRunningJobs(); check(HesabfaJobRepository::getById($id)['status']==='running','Live slow worker reclaimed');
joinWorker($worker); check(!$service->processRow($snapshot),'Completed stale snapshot replayed');
check($service->processRow(HesabfaJobRepository::getById($successor)),'Successor did not run after predecessor completed');
$claim=HesabfaJobRepository::enqueue('sync_product',array('id_product'=>8),'Product',8);
check(HesabfaJobRepository::markRunning($claim) && !HesabfaJobRepository::markRunning($claim),'Affected-row claim accepted second owner');
$pending=HesabfaJobRepository::enqueue('sync_customer',array('id_customer'=>9),'Customer',9);
$merged=HesabfaJobRepository::enqueue('sync_customer',array('id_customer'=>9,'source_hook'=>'new'),'Customer',9);
check($pending===$merged,'Unattempted pending job no longer merges');
$db->execute('UPDATE fixture_ssb_hesabfa_job SET status="retry_wait", attempts=1, request_unique_ids="persisted" WHERE id_ssb_hesabfa_job='.(int)$pending);
$new=HesabfaJobRepository::enqueue('sync_customer',array('id_customer'=>9,'source_hook'=>'later'),'Customer',9);
check($new!==$pending && HesabfaJobRepository::getById($pending)['request_unique_ids']==='persisted','Retry merge lost persisted request IDs');

$internal=HesabfaInternalApiRequestRepository::create('fixture','documentSave',array('amount'=>1),'pending','Order',10);
$worker=spawn('internal',$internal); $internalService=new InternalFixture();
check(!HesabfaInternalApiRequestRepository::markDeadManually($internal), 'Internal admin mutation bypassed worker lock');
check(!$internalService->runInternalApiRequest($internal)['success'],'Internal request executed concurrently');
check(!HesabfaInternalApiRequestRepository::markRunning($internal),'Internal affected-row claim failed');
$db->execute('UPDATE fixture_ssb_hesabfa_api_request SET locked_at=DATE_SUB(NOW(),INTERVAL 20 MINUTE) WHERE id_ssb_hesabfa_api_request='.(int)$internal);
HesabfaInternalApiRequestRepository::recoverStaleRunning(); check(HesabfaInternalApiRequestRepository::getById($internal)['status']==='running','Live internal worker reclaimed');
joinWorker($worker); check(!$internalService->runInternalApiRequest($internal)['success'],'Completed internal request replayed');
$changed=HesabfaJobRepository::enqueue('sync_product',array('id_product'=>11),'Product',11);
check(HesabfaJobRepository::markRunning($changed),'Changed-payload fixture claim failed');
HesabfaJobRepository::saveRequestUniqueIds($changed,array('slot'=>'persisted'));
HesabfaJobRepository::markDeferred($changed,'timeout','NO_RESPONSE',60);
HesabfaJobRepository::syncPayloadHash($changed,array('id_product'=>11,'price'=>99));
check(HesabfaJobRepository::getById($changed)['status']==='duplicate_check' && strpos(HesabfaJobRepository::getById($changed)['request_unique_ids'],'persisted')!==false,'Changed attempted payload erased IDs or failed to hold');

$db->execute('ALTER TABLE fixture_ssb_hesabfa_operation DROP COLUMN request_unique_ids, DROP COLUMN request_unique_ids_created_at');
$db->execute('INSERT INTO fixture_ssb_hesabfa_operation (operation_key,operation_type,status,attempts,date_add,date_upd) VALUES ("legacy","invoice_payment","failed",1,NOW(),NOW())');
check(upgrade_module_2_3_35((object)array('id'=>1)),'Upgrade failed');
check(HesabfaOperationRepository::getByKey('legacy')['status']==='needs_attention','Upgrade replayed ambiguous legacy operation');
check(upgrade_module_2_3_35((object)array('id'=>1)),'Upgrade not repeatable');
echo "PASS: real MySQL cross-process financial/queue locks, atomic claims, stale snapshots, safe merges, persisted retries/expiry, persistence failure, legacy migration\n";
