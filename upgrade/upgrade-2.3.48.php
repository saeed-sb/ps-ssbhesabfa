<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_3_48($module)
{
    $db = Db::getInstance();
    $table = _DB_PREFIX_ . 'ssb_hesabfa_operation';
    $columns = $db->executeS('SHOW COLUMNS FROM `' . $table . '`', true, false);
    if (!is_array($columns)) {
        return false;
    }

    return in_array('payment_plan', array_column($columns, 'Field'), true)
        || $db->execute('ALTER TABLE `' . $table . '` ADD COLUMN `payment_plan` TEXT DEFAULT NULL');
}
