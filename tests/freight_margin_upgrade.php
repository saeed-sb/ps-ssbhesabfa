<?php
/** Older Hesabfa upgrade steps must not touch delivery-owned storage or events. */
define('_PS_VERSION_', '8.1.7');
class Db { public static function getInstance() { throw new RuntimeException('Shipping database access is forbidden.'); } }
class RetiredShippingUpgradeModule { public function __call($name, $args) { throw new RuntimeException('Unexpected module operation: '.$name); } }
require dirname(__DIR__).'/upgrade/upgrade-2.3.39.php';
$module = new RetiredShippingUpgradeModule();
for ($attempt = 0; $attempt < 2; ++$attempt) {
    if (upgrade_module_2_3_39($module) !== true) { throw new RuntimeException('Retired upgrade must succeed without delivery.'); }
}
echo json_encode(['success'=>true, 'checks'=>2, 'external_operations'=>0]), "\n";
