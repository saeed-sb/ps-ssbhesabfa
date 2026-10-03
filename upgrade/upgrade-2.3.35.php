<?php
if (!defined('_PS_VERSION_')) { exit; }

function upgrade_module_2_3_35($module)
{
    if (!Validate::isLoadedObject($module)) { return false; }
    $db = Db::getInstance();
    $table = _DB_PREFIX_ . 'ssb_hesabfa_operation';
    $columns = $db->executeS('SHOW COLUMNS FROM `' . $table . '`', true, false);
    if (!is_array($columns)) { return false; }
    $existing = array_column($columns, 'Field');
    foreach (array('request_unique_ids'=>'MEDIUMTEXT DEFAULT NULL', 'request_unique_ids_created_at'=>'DATETIME DEFAULT NULL') as $name=>$definition) {
        if (!in_array($name, $existing, true) && !$db->execute('ALTER TABLE `' . $table . '` ADD `' . $name . '` ' . $definition)) { return false; }
    }
    // Old ambiguous writes have no reusable ID. An upgrade must not replay them.
    return $db->execute('UPDATE `' . $table . '` SET `status`="needs_attention",`message`="Legacy financial write requires external reconciliation before retry." WHERE `status` IN ("pending","failed") AND `attempts`>0 AND `request_unique_ids` IS NULL');
}
