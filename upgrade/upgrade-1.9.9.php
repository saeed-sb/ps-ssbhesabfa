<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

function ssbhesabfaUpgrade199ColumnExists($table, $column)
{
    $rows = Db::getInstance()->executeS(
        'SHOW COLUMNS FROM `' . bqSQL($table) . '` LIKE "' . pSQL($column) . '"'
    );

    return is_array($rows) && !empty($rows);
}

function ssbhesabfaUpgrade199RegisterHook($module, $hookName)
{
    if ($module->isRegisteredInHook($hookName)) {
        return true;
    }

    return (bool) $module->registerHook($hookName);
}

function upgrade_module_1_9_9($module)
{
    $db = Db::getInstance();
    $mappingTable = _DB_PREFIX_ . 'ssb_hesabfa';

    $tables = $db->executeS(
        'SHOW TABLES LIKE "' . pSQL(str_replace(array('_', '%'), array('\\_', '\\%'), $mappingTable)) . '"'
    );
    if (is_array($tables) && !empty($tables)
        && !ssbhesabfaUpgrade199ColumnExists($mappingTable, 'id_ps_attribute')) {
        if (!$db->execute(
            'ALTER TABLE `' . bqSQL($mappingTable) . '` '
            . 'ADD COLUMN `id_ps_attribute` INT(10) NOT NULL DEFAULT 0 AFTER `id_ps`'
        )) {
            return false;
        }
    }

    $logSql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'ssb_hesabfa_log` (
        `id_ssb_hesabfa_log` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
        `severity` tinyint(1) NOT NULL DEFAULT 1,
        `error_code` varchar(64) DEFAULT NULL,
        `object_type` varchar(64) DEFAULT NULL,
        `object_id` varchar(64) DEFAULT NULL,
        `message` text NOT NULL,
        `date_add` datetime NOT NULL,
        PRIMARY KEY (`id_ssb_hesabfa_log`),
        KEY `severity` (`severity`),
        KEY `object_type` (`object_type`),
        KEY `date_add` (`date_add`)
    ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8';
    if (!$db->execute($logSql)) {
        return false;
    }

    $defaults = array(
        'SSBHESABFA_INVOICE_RETURN_STATUS' => 6,
        'SSBHESABFA_INVOICE_REFERENCE_TYPE' => 1,
        'SSBHESABFA_MANUAL_PAYMENT_DESCRIPTION_TEMPLATE' => 'Manual gateway payment - invoice {invoice_number}',
        'SSBHESABFA_FEE_INCOME_DOCUMENT_DESCRIPTION_TEMPLATE' => 'Online payment fee income - order {order_id} - transaction {transaction_number}',
        'SSBHESABFA_MANUAL_FEE_INCOME_DOCUMENT_DESCRIPTION_TEMPLATE' => 'Manual gateway payment fee income - invoice {invoice_number} - transaction {transaction_number}',
    );
    foreach ($defaults as $key => $value) {
        $exists = (bool) $db->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` = "' . pSQL($key) . '"'
        );
        if (!$exists && !Configuration::updateValue($key, $value)) {
            return false;
        }
    }

    $hooks = array(
        'actionOrderStatusPostUpdate',
        'actionProductAttributeAdd',
        'actionProductAttributeUpdate',
        'actionProductAttributeDelete',
    );
    if (version_compare(_PS_VERSION_, '1.7.0.0', '>=')) {
        $hooks[] = 'displayAdminProductsExtra';
    }
    foreach ($hooks as $hookName) {
        if (!ssbhesabfaUpgrade199RegisterHook($module, $hookName)) {
            return false;
        }
    }

    $obsoleteFiles = array(
        _PS_MODULE_DIR_ . $module->name . '/classes/HesabfaApi.php',
        _PS_MODULE_DIR_ . $module->name . '/classes/hesabfaAPI.php',
    );
    foreach ($obsoleteFiles as $file) {
        if (file_exists($file)) {
            @unlink($file);
        }
    }

    return true;
}
