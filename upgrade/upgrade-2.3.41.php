<?php
if (!defined('_PS_VERSION_')) { exit; }

// Add durable invoice-edit recovery without enabling settings or replaying invoices.
function upgrade_module_2_3_41($module)
{
    $db=Db::getInstance();
    $columns=$db->executeS('SHOW COLUMNS FROM `'._DB_PREFIX_."ssb_hesabfa_freight_margin` LIKE 'invoice_pending_json'",true,false);
    if (!is_array($columns)) { return false; }
    if (!$columns && !$db->execute('ALTER TABLE `'._DB_PREFIX_.'ssb_hesabfa_freight_margin` ADD COLUMN invoice_pending_json MEDIUMTEXT NULL AFTER pending_json')) { return false; }
    return $module->registerHook('actionSsbDeliveryCarrierChanged');
}
