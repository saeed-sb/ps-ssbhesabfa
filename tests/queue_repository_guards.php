<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
define('_PS_VERSION_','8.1.7'); define('_DB_PREFIX_','ps_');
function check($ok,$message){if(!$ok)throw new Exception($message);}
function pSQL($s){return addslashes($s);}
class Db {
 public static $sql='', $cache;
 public static function getInstance(){return new self();}
 public function execute($sql){self::$sql=$sql;return true;}
 public function getValue($sql,$cache=true){self::$sql=$sql;self::$cache=$cache;return 2000;}
}
class Configuration {
 public static $value;
 public static function updateValue($key,$value){self::$value=$value;return true;}
}
class DbQuery {public $where=[];public function where($v){$this->where[]=$v;return $this;}}
require dirname(__DIR__).'/classes/HesabfaWebhookChangeRepository.php';
require dirname(__DIR__).'/classes/HesabfaJobRepository.php';
require dirname(__DIR__).'/classes/HesabfaInternalApiRequestRepository.php';
require dirname(__DIR__).'/classes/traits/HesabfaJobTrait.php';
class QueueProbe {use HesabfaJobTrait;public function address($c,$a){return $this->queueCustomerAddressSync($c,$a);}}
check((new QueueProbe())->address(0,42425)===false,'Supplier address was enqueued');
check((new QueueProbe())->address(10,0)===false,'Invalid address was enqueued');
HesabfaWebhookChangeRepository::recoverStaleRunning();
check(strpos(Db::$sql,'`status`="running"')!==false && strpos(Db::$sql,'INTERVAL 15 MINUTE')!==false,'Recovery includes completed/dead or recent rows');
HesabfaWebhookChangeRepository::advanceCheckpoint(10);
check(Configuration::$value===2000 && Db::$cache===false,'Recovery rewinds checkpoint or reads cache');
HesabfaWebhookChangeRepository::advanceCheckpoint(2010);check(Configuration::$value===2010,'Cursor cannot advance');
foreach(['HesabfaJobRepository','HesabfaInternalApiRequestRepository'] as $class){
 $m=new ReflectionMethod($class,'applyListFilters');$m->setAccessible(true);
 $q=new DbQuery();$m->invoke(null,$q,['status'=>'active']);
 check(count($q->where)===1 && strpos($q->where[0],'retry_wait')!==false && strpos($q->where[0],'done')===false,'Active filter includes historical rows');
 $q=new DbQuery();$m->invoke(null,$q,['status'=>'']);check(count($q->where)===0,'All filter no longer shows history');
}
echo "PASS: non-customer address rejection, stale-only recovery, monotonic fresh cursor, active/all filters\n";
