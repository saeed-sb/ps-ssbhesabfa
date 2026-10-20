<?php
define('_PS_VERSION_','8.1.7'); define('_DB_PREFIX_','fixture_');
class Db {
    public static $instance; public $column=false; public $alters=0; public $fail=false;
    public static function getInstance() { return self::$instance; }
    public function executeS($sql,...$rest) { if (strpos($sql,'SHOW COLUMNS FROM `fixture_ssb_hesabfa_freight_margin`')!==0) { throw new RuntimeException('Unexpected schema read.'); } return $this->column?array(array('Field'=>'invoice_pending_json')):array(); }
    public function execute($sql) { if (strpos($sql,'ALTER TABLE `fixture_ssb_hesabfa_freight_margin` ADD COLUMN invoice_pending_json MEDIUMTEXT NULL AFTER pending_json')!==0) { throw new RuntimeException('Unexpected schema mutation.'); } ++$this->alters; if ($this->fail) { return false; } $this->column=true; return true; }
}
class Module { public $hooks=array(); public function registerHook($name) { $this->hooks[]=$name; return true; } }
require dirname(__DIR__).'/upgrade/upgrade-2.3.41.php';
$db=Db::$instance=new Db(); $module=new Module();
if (!upgrade_module_2_3_41($module) || !$db->column || $db->alters!==1 || $module->hooks!==array('actionSsbDeliveryCarrierChanged')) { throw new RuntimeException('Initial upgrade failed.'); }
if (!upgrade_module_2_3_41($module) || $db->alters!==1) { throw new RuntimeException('Repeated upgrade is not idempotent.'); }
$db=Db::$instance=new Db(); $db->fail=true; $module=new Module();
if (upgrade_module_2_3_41($module)!==false || $module->hooks) { throw new RuntimeException('Schema failures must not register hooks.'); }
echo json_encode(array('success'=>true,'checks'=>3,'external_operations'=>0)),"\n";
