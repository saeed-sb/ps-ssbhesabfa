<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('_PS_VERSION_', '8.1.7');
function check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
class Shop { public static $shops = array(1); public static function getShops($active, $group, $ids) { return self::$shops; } }
class Module { public static $enabled = true; public static function isEnabled($name) { return self::$enabled; } }
class Validate { public static function isLoadedObject($v) { return is_object($v) && !empty($v->id); } }
class Tab { public static $missing = false, $names = array(); public static function getIdFromClassName($name) { if (self::$missing) { return 0; } if (!in_array($name, self::$names, true)) { self::$names[]=$name; } return array_search($name,self::$names,true)+1; } }
class Profile {
    public static $access = array(), $fail = false;
    public static function getProfileAccess($profile, $tab) { if (self::$fail) { throw new RuntimeException('unavailable'); } $name=Tab::$names[$tab-1]; return isset(self::$access[$name]) ? self::$access[$name] : array(); }
}
class Tools {
    public static $values = array();
    public static function getValue($key, $default = false) { return array_key_exists($key, self::$values) ? self::$values[$key] : $default; }
    public static function isSubmit($key) { return array_key_exists($key, self::$values); }
    public static function getAdminTokenLite($controller) { return 'csrf-' . $controller; }
}
class Configuration { public static $updates=array(); public static function updateValue($key,$value) { self::$updates[$key]=$value; return true; } public static function get($key) { return $key === 'SSBHESABFA_LIVE_MODE' ? 1 : 'canary-stored-secret'; } }
require dirname(__DIR__).'/classes/HesabfaSecurity.php';
require dirname(__DIR__).'/classes/HesabfaLogService.php';
require dirname(__DIR__).'/classes/HesabfaRequestUniqueId.php';
require dirname(__DIR__).'/classes/traits/HesabfaCoreSupportTrait.php';
require dirname(__DIR__).'/classes/traits/HesabfaAdminUiTrait.php';
class AdminProbe {
    use HesabfaCoreSupportTrait, HesabfaAdminUiTrait;
    public $context;
    public function __construct() { $this->context = (object)array('employee'=>(object)array('id'=>1, 'id_profile'=>2),'controller'=>(object)array('controller_name'=>'AdminSsbHesabfaDashboard')); }
    public function authorize() { return $this->authorizeAdminRequest(); }
    public function values() { return $this->getConfigFormValues('Config'); }
    public function save() { return $this->setConfigFormsValues('Config'); }
    public function l($v) { return $v; }
    public function displayError($v) { return $v; }
}
$probe = new AdminProbe();
Profile::$access['AdminSsbHesabfaDashboard'] = array('view'=>1,'edit'=>0);
Profile::$access['AdminSsbHesabfaSettings'] = array('view'=>0,'edit'=>0);
Tools::$values = array('ssb_admin_section'=>'Settings');
check(!$probe->authorize(), 'Sibling section disclosed settings');
Tools::$values = array('ssb_admin_section'=>'Dashboard','submitSsbhesabfaModuleConfig'=>1,'token'=>'csrf-AdminSsbHesabfaDashboard');
$_SERVER['REQUEST_METHOD'] = 'POST';
check(!$probe->authorize(), 'Dashboard POST bypassed target settings permission');
Profile::$access['AdminSsbHesabfaSettings'] = array('view'=>1,'edit'=>0);
check(!$probe->authorize(), 'Read-only user changed settings');
Profile::$access['AdminSsbHesabfaSettings']['edit'] = 1;
check($probe->authorize(), 'Authorized POST rejected');
$_SERVER['REQUEST_METHOD'] = 'GET'; check(!$probe->authorize(), 'GET mutation accepted');
$_SERVER['REQUEST_METHOD'] = 'POST'; Tools::$values['token']='wrong'; check(!$probe->authorize(), 'Invalid CSRF accepted');
Tools::$values['token']='csrf-AdminSsbHesabfaDashboard';
Tab::$missing=true; check(!$probe->authorize(), 'Missing tab failed open'); Tab::$missing=false;
Profile::$fail=true; check(!$probe->authorize(), 'Permission failure failed open'); Profile::$fail=false;
$probe->context->employee=null; check(!$probe->authorize(), 'Missing employee failed open');
check($probe->getContent()==='Access denied.', 'Denied request reached rendering/submit dispatcher');
$probe->context->employee=(object)array('id'=>1,'id_profile'=>2);
Profile::$access=array('AdminSsbHesabfaDashboard'=>array('view'=>0), 'AdminSsbHesabfaSettings'=>array('view'=>1,'edit'=>1));
Tools::$values=array('ssb_admin_section'=>'Settings');
check($probe->renderAdminControllerContent('Dashboard')==='Access denied.', 'POST section selector overrode canonical controller');
Profile::$access=array('AdminSsbHesabfaDashboard'=>array('view'=>1));
$source=file_get_contents(dirname(__DIR__).'/classes/traits/HesabfaCoreSupportTrait.php');
preg_match_all('/isSubmit\(\x27(submitSsbhesabfa[^\x27]+)\x27\)/',$source,$submits);
check(count($submits[1])>=26,'Mutation dispatcher coverage incomplete');
foreach ($submits[1] as $submit) {
    Tools::$values=array('ssb_admin_section'=>'Dashboard','token'=>'csrf-AdminSsbHesabfaDashboard',$submit=>1);
    check(!$probe->authorize(),'Unauthorized action accepted: '.$submit);
}
foreach (array('SSBHESABFA_ACCOUNT_PASSWORD','SSBHESABFA_ACCOUNT_API','SSBHESABFA_ACCOUNT_TOKEN') as $key) { check($probe->values()[$key] === '', 'Stored secret prepopulated form'); }
Tools::$values=array('SSBHESABFA_ACCOUNT_PASSWORD'=>'','SSBHESABFA_ACCOUNT_API'=>'','SSBHESABFA_ACCOUNT_TOKEN'=>'');
$probe->save();
foreach (array('SSBHESABFA_ACCOUNT_PASSWORD','SSBHESABFA_ACCOUNT_API','SSBHESABFA_ACCOUNT_TOKEN') as $key) { check(!array_key_exists($key,Configuration::$updates), 'Blank secret erased existing credential'); }
Tools::$values['SSBHESABFA_ACCOUNT_PASSWORD']='replacement'; $probe->save();
check(Configuration::$updates['SSBHESABFA_ACCOUNT_PASSWORD']==='replacement', 'Replacement secret not saved');
Tools::$values=array('SSBHESABFA_ACCOUNT_TOKEN'=>'','SSBHESABFA_ACCOUNT_TOKEN_CLEAR'=>'1'); $probe->save();
check(Configuration::$updates['SSBHESABFA_ACCOUNT_TOKEN']==='', 'Explicit secret removal failed');
check(!array_key_exists('SSBHESABFA_ACCOUNT_TOKEN_CLEAR',Configuration::$updates), 'Transient clear flag persisted');
Tools::$values['SSBHESABFA_ACCOUNT_TOKEN']='new-token'; $probe->save();
check(Configuration::$updates['SSBHESABFA_ACCOUNT_TOKEN']==='new-token', 'Clear flag overrode replacement secret');

foreach (array(true,false,0,1,null,array(),(object)array(),'','wrong') as $password) {
    check(!HesabfaSecurity::validWebhookBody(json_encode(array('Password'=>$password)), 'expected'), 'Webhook type juggling accepted');
}
check(HesabfaSecurity::validWebhookBody('{"Password":"expected"}', 'expected'), 'Valid webhook rejected');
check(!HesabfaSecurity::validWebhookBody('{"Password":""}', ''), 'Empty password accepted');
check(!HesabfaSecurity::validWebhookBody('[]', 'expected'), 'Array envelope accepted');
check(!HesabfaSecurity::validWebhookBody('{invalid', 'expected'), 'Malformed JSON accepted');
$stream=fopen('php://temp','w+b'); fwrite($stream,str_repeat('x',65537)); rewind($stream);
check(HesabfaSecurity::readWebhookBody($stream)===false, 'Oversize streamed body accepted');
rewind($stream); check(HesabfaSecurity::readWebhookBody($stream,'65537')===false, 'Oversize Content-Length accepted'); fclose($stream);
$stream=fopen('php://temp','w+b'); fwrite($stream,str_repeat('x',65536)); rewind($stream);
check(strlen(HesabfaSecurity::readWebhookBody($stream,'65536'))===65536, 'Exact size limit rejected'); fclose($stream);
check(HesabfaSecurity::cronToken(array('QUERY_STRING'=>'token=expected'))===null, 'Cron query credential accepted');
check(HesabfaSecurity::cronToken(array('HTTP_X_SSB_HESABFA_TOKEN'=>'expected'))==='expected', 'Cron header rejected');
check(HesabfaSecurity::isOperational(), 'Single shop rejected'); Shop::$shops=array(1,2); check(!HesabfaSecurity::isOperational(), 'Multiple shops accepted');
Shop::$shops=array(1); Module::$enabled=false; check(!HesabfaSecurity::isOperational(), 'Disabled module accepted');

$payload=array('Password'=>'canary-password','hookPassword'=>'canary-hook','AUTHORIZATION'=>'Bearer canary-auth','Email'=>'customer@example.com','nested'=>array('Login_Token'=>'canary-login','FirstName'=>'CanaryPerson','Address'=>'CanaryAddress'), 'Success'=>true,'Result'=>array('Number'=>42));
$masked=json_encode(HesabfaLogService::maskSensitiveData($payload));
check(strpos($masked,'canary-')===false && strpos($masked,'customer@')===false && strpos($masked,'CanaryPerson')===false, 'Recursive redaction leaked secret/PII');
$metadata=HesabfaLogService::normalizeDebugValue(json_encode($payload));
check(strpos($metadata,'Canary')===false && strpos($metadata,'canary')===false && strpos($metadata,'42')!==false, 'Debug metadata leaked contents or lost result identifier');
check(HesabfaLogService::safeEndpoint('https://user:pass@api.example.com/path?token=canary#secret')==='https://api.example.com/path','Endpoint retained credentials');
$message=HesabfaLogService::maskSensitiveData('failure canary-stored-secret hookPassword="canary-hook" TOKEN=canary-query Authorization: Bearer canary-auth');
check(strpos($message,'canary')===false,'Ordinary message retained credentials');

$saved=array();
$persist=function($id,$ids)use(&$saved){$saved[$id]=$ids;return true;};
HesabfaRequestUniqueId::beginContext('outer',array(),$persist); $outer=HesabfaRequestUniqueId::generate('invoice/save',array('amount'=>1));
HesabfaRequestUniqueId::beginContext('inner',array(),$persist); $inner=HesabfaRequestUniqueId::generate('document/save',array('amount'=>2)); HesabfaRequestUniqueId::endContext();
check(HesabfaRequestUniqueId::hasContext(),'Nested financial context discarded queue context'); HesabfaRequestUniqueId::endContext();
HesabfaRequestUniqueId::beginContext('outer',$saved['outer'],$persist); check(HesabfaRequestUniqueId::generate('invoice/save',array('amount'=>1))===$outer,'Retry changed UUID'); HesabfaRequestUniqueId::endContext();
HesabfaRequestUniqueId::beginContext('failure',array(),function(){return false;}); $failed=false;
try { HesabfaRequestUniqueId::generate('invoice/save',array()); } catch (RuntimeException $e) { $failed=true; } finally { HesabfaRequestUniqueId::endContext(); }
check($failed,'Failed persistence allowed outbound write');
echo "PASS: admin ACL/CSRF, blank secrets, strict webhook authentication/size, cron header, lifecycle/tenancy, log redaction, nested/persisted request IDs\n";
