<?php
if (!defined('_PS_VERSION_')) { exit; }

function upgrade_module_2_3_44($module)
{
    if (($module->name ?? '') !== 'ssbhesabfa') {
        return false;
    }
    $db = Db::getInstance();
    $table = _DB_PREFIX_ . 'ssb_hesabfa_webhook_change';
    $columns = $db->executeS('SHOW COLUMNS FROM `' . $table . '` LIKE "invoice_item_codes"', true, false);
    if (!is_array($columns)) {
        return false;
    }
    if (empty($columns) && !$db->execute('ALTER TABLE `' . $table . '` ADD `invoice_item_codes` MEDIUMTEXT DEFAULT NULL AFTER `payload`')) {
        return false;
    }
    $indexes = $db->executeS('SHOW INDEX FROM `' . $table . '` WHERE Key_name="idx_invoice_history"', true, false);
    if (!is_array($indexes)) {
        return false;
    }

    // Add metadata only. Never replay events, rewind the cursor or write to Hesabfa.
    return !empty($indexes) || $db->execute(
        'ALTER TABLE `' . $table . '` ADD INDEX `idx_invoice_history` (`object_type`,`object_id`,`change_id`)'
    );
}
