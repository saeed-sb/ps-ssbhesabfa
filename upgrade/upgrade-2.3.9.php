<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

function ssbhesabfaUpgrade239TableExists($table)
{
    $pattern = str_replace(
        array('\\', '_', '%'),
        array('\\\\', '\\_', '\\%'),
        (string) $table
    );
    $rows = Db::getInstance()->executeS('SHOW TABLES LIKE "' . pSQL($pattern) . '"');

    return is_array($rows) && !empty($rows);
}

function ssbhesabfaUpgrade239ColumnExists($table, $column)
{
    $rows = Db::getInstance()->executeS(
        'SHOW COLUMNS FROM `' . bqSQL($table) . '` LIKE "' . pSQL($column) . '"'
    );

    return is_array($rows) && !empty($rows);
}

function ssbhesabfaUpgrade239EnsureColumn($table, $column, $definition)
{
    if (ssbhesabfaUpgrade239ColumnExists($table, $column)) {
        return true;
    }

    return (bool) Db::getInstance()->execute(
        'ALTER TABLE `' . bqSQL($table) . '` ADD COLUMN `' . bqSQL($column) . '` ' . $definition
    );
}

function ssbhesabfaUpgrade239GetIndexes($table)
{
    $rows = Db::getInstance()->executeS('SHOW INDEX FROM `' . bqSQL($table) . '`');
    if (!is_array($rows)) {
        return array();
    }

    $indexes = array();
    foreach ($rows as $row) {
        $name = isset($row['Key_name']) ? (string) $row['Key_name'] : '';
        if ($name === '') {
            continue;
        }
        if (!isset($indexes[$name])) {
            $indexes[$name] = array(
                'non_unique' => isset($row['Non_unique']) ? (int) $row['Non_unique'] : 1,
                'columns' => array(),
            );
        }
        $sequence = isset($row['Seq_in_index'])
            ? (int) $row['Seq_in_index']
            : count($indexes[$name]['columns']) + 1;
        $indexes[$name]['columns'][$sequence] = isset($row['Column_name'])
            ? (string) $row['Column_name']
            : '';
    }

    foreach ($indexes as &$index) {
        ksort($index['columns']);
        $index['columns'] = array_values($index['columns']);
    }
    unset($index);

    return $indexes;
}

function ssbhesabfaUpgrade239DropIndex($table, $name)
{
    if ($name === '' || $name === 'PRIMARY') {
        return true;
    }

    return (bool) Db::getInstance()->execute(
        'ALTER TABLE `' . bqSQL($table) . '` DROP INDEX `' . bqSQL($name) . '`'
    );
}

function ssbhesabfaUpgrade239EnsureIndex($table, $name, array $columns, $unique = false)
{
    $indexes = ssbhesabfaUpgrade239GetIndexes($table);
    $expectedNonUnique = $unique ? 0 : 1;

    foreach ($indexes as $indexName => $index) {
        if ($indexName === 'PRIMARY') {
            continue;
        }
        if ($index['columns'] === $columns && (int) $index['non_unique'] === $expectedNonUnique) {
            return true;
        }
    }

    foreach ($indexes as $indexName => $index) {
        if ($indexName === 'PRIMARY') {
            continue;
        }
        if ($index['columns'] === $columns && (int) $index['non_unique'] !== $expectedNonUnique) {
            if (!ssbhesabfaUpgrade239DropIndex($table, $indexName)) {
                return false;
            }
        }
    }

    $indexes = ssbhesabfaUpgrade239GetIndexes($table);
    if (isset($indexes[$name])) {
        if (!ssbhesabfaUpgrade239DropIndex($table, $name)) {
            return false;
        }
    }

    $quotedColumns = array();
    foreach ($columns as $column) {
        $quotedColumns[] = '`' . bqSQL($column) . '`';
    }

    return (bool) Db::getInstance()->execute(
        'ALTER TABLE `' . bqSQL($table) . '` ADD '
        . ($unique ? 'UNIQUE ' : '') . 'INDEX `' . bqSQL($name) . '` ('
        . implode(', ', $quotedColumns) . ')'
    );
}

function ssbhesabfaUpgrade239RepairMappingIndexes($table)
{
    $db = Db::getInstance();
    $indexes = ssbhesabfaUpgrade239GetIndexes($table);

    foreach ($indexes as $name => $index) {
        if ($name === 'PRIMARY') {
            continue;
        }
        if ($index['columns'] === array('obj_type', 'id_hesabfa')
            && (int) $index['non_unique'] === 0) {
            if (!ssbhesabfaUpgrade239DropIndex($table, $name)) {
                return false;
            }
        }
    }

    if (!$db->execute(
        'DELETE duplicate_mapping FROM `' . bqSQL($table) . '` duplicate_mapping '
        . 'INNER JOIN `' . bqSQL($table) . '` kept_mapping '
        . 'ON duplicate_mapping.`obj_type` = kept_mapping.`obj_type` '
        . 'AND duplicate_mapping.`id_ps` = kept_mapping.`id_ps` '
        . 'AND duplicate_mapping.`id_ps_attribute` = kept_mapping.`id_ps_attribute` '
        . 'AND duplicate_mapping.`id_ssb_hesabfa` > kept_mapping.`id_ssb_hesabfa`'
    )) {
        return false;
    }

    return ssbhesabfaUpgrade239EnsureIndex(
        $table,
        'uniq_obj_ps_attr',
        array('obj_type', 'id_ps', 'id_ps_attribute'),
        true
    ) && ssbhesabfaUpgrade239EnsureIndex(
        $table,
        'idx_obj_hesabfa',
        array('obj_type', 'id_hesabfa'),
        false
    );
}

function ssbhesabfaUpgrade239ConfigurationExists($name)
{
    return (bool) Db::getInstance()->getValue(
        'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` = "' . pSQL($name) . '"'
    );
}

function ssbhesabfaUpgrade239SetDefault($name, $value)
{
    if (ssbhesabfaUpgrade239ConfigurationExists($name)) {
        return true;
    }

    return (bool) Configuration::updateValue($name, $value);
}

function ssbhesabfaUpgrade239GenerateToken($bytes = 32)
{
    $bytes = max(16, (int) $bytes);
    if (function_exists('random_bytes')) {
        return bin2hex(random_bytes($bytes));
    }
    if (function_exists('openssl_random_pseudo_bytes')) {
        $token = openssl_random_pseudo_bytes($bytes);
        if ($token !== false) {
            return bin2hex($token);
        }
    }

    return Tools::passwdGen($bytes * 2);
}

function ssbhesabfaUpgrade239RegisterHook($module, $hookName)
{
    if ($module->isRegisteredInHook($hookName)) {
        return true;
    }

    return (bool) $module->registerHook($hookName);
}

function ssbhesabfaUpgrade239EnsureTab($module, $className, $name, $parentClassName, $visible)
{
    $idParent = (int) Tab::getIdFromClassName($parentClassName);
    if (!$idParent && $parentClassName !== 'ShopParameters') {
        $idParent = (int) Tab::getIdFromClassName('ShopParameters');
    }

    $idTab = (int) Tab::getIdFromClassName($className);
    $tab = $idTab ? new Tab($idTab) : new Tab();
    $tab->class_name = $className;
    $tab->module = $module->name;
    $tab->id_parent = $idParent;
    $tab->active = $visible ? 1 : 0;

    foreach (Language::getLanguages(false) as $language) {
        $tab->name[(int) $language['id_lang']] = $name;
    }

    return $idTab ? (bool) $tab->update() : (bool) $tab->add();
}

function ssbhesabfaUpgrade239BackfillQueueMetadata($table, $idColumn)
{
    if (!class_exists('HesabfaRequestUniqueId')) {
        require_once dirname(__DIR__) . '/classes/HesabfaRequestUniqueId.php';
    }

    $rows = Db::getInstance()->executeS(
        'SELECT `' . bqSQL($idColumn) . '`, `payload`, `request_payload_hash`, '
        . '`request_unique_ids`, `request_unique_ids_created_at`, `date_upd`, `date_add` '
        . 'FROM `' . bqSQL($table) . '` '
        . 'WHERE `request_payload_hash` IS NULL OR `request_payload_hash` = "" '
        . 'OR (`request_unique_ids` IS NOT NULL AND `request_unique_ids` <> "" '
        . 'AND `request_unique_ids_created_at` IS NULL)'
    );
    if (!is_array($rows)) {
        return true;
    }

    foreach ($rows as $row) {
        $payload = json_decode($row['payload'], true);
        if (!is_array($payload)) {
            $payload = array();
        }
        $data = array();
        if (empty($row['request_payload_hash'])) {
            $data['request_payload_hash'] = pSQL(HesabfaRequestUniqueId::payloadHash($payload));
        }
        if (!empty($row['request_unique_ids']) && empty($row['request_unique_ids_created_at'])) {
            $data['request_unique_ids_created_at'] = !empty($row['date_upd'])
                ? $row['date_upd']
                : $row['date_add'];
        }
        if (!empty($data) && !Db::getInstance()->update(
            str_replace(_DB_PREFIX_, '', $table),
            $data,
            '`' . bqSQL($idColumn) . '` = ' . (int) $row[$idColumn]
        )) {
            return false;
        }
    }

    return true;
}

function upgrade_module_2_3_9($module)
{
    $db = Db::getInstance();

    $installResult = include dirname(__DIR__) . '/sql/install.php';
    if ($installResult === false) {
        return false;
    }

    $tables = array(
        'mapping' => _DB_PREFIX_ . 'ssb_hesabfa',
        'log' => _DB_PREFIX_ . 'ssb_hesabfa_log',
        'operation' => _DB_PREFIX_ . 'ssb_hesabfa_operation',
        'issue' => _DB_PREFIX_ . 'ssb_hesabfa_issue',
        'job' => _DB_PREFIX_ . 'ssb_hesabfa_job',
        'api' => _DB_PREFIX_ . 'ssb_hesabfa_api_request',
        'rate' => _DB_PREFIX_ . 'ssb_hesabfa_rate_limit',
        'webhook' => _DB_PREFIX_ . 'ssb_hesabfa_webhook_change',
    );
    foreach ($tables as $table) {
        if (!ssbhesabfaUpgrade239TableExists($table)) {
            return false;
        }
    }

    $columnDefinitions = array(
        $tables['mapping'] => array(
            'id_ps_attribute' => 'INT(10) NOT NULL DEFAULT 0 AFTER `id_ps`',
        ),
        $tables['log'] => array(
            'level' => 'VARCHAR(16) NOT NULL DEFAULT "INFO" AFTER `severity`',
            'area' => 'VARCHAR(64) DEFAULT NULL AFTER `level`',
            'prestashop_code' => 'VARCHAR(128) DEFAULT NULL AFTER `object_id`',
            'hesabfa_code' => 'VARCHAR(128) DEFAULT NULL AFTER `prestashop_code`',
            'debug_endpoint' => 'VARCHAR(255) DEFAULT NULL AFTER `hesabfa_code`',
            'debug_http_code' => 'INT(11) DEFAULT NULL AFTER `debug_endpoint`',
            'debug_duration_ms' => 'INT(11) DEFAULT NULL AFTER `debug_http_code`',
            'debug_payload' => 'MEDIUMTEXT DEFAULT NULL AFTER `debug_duration_ms`',
            'debug_request' => 'MEDIUMTEXT DEFAULT NULL AFTER `debug_payload`',
            'debug_response' => 'MEDIUMTEXT DEFAULT NULL AFTER `debug_request`',
        ),
        $tables['job'] => array(
            'request_payload_hash' => 'CHAR(40) DEFAULT NULL AFTER `payload`',
            'request_unique_ids' => 'MEDIUMTEXT DEFAULT NULL AFTER `request_payload_hash`',
            'request_unique_ids_created_at' => 'DATETIME DEFAULT NULL AFTER `request_unique_ids`',
            'last_error_code' => 'VARCHAR(64) DEFAULT NULL AFTER `last_error`',
            'last_response' => 'MEDIUMTEXT DEFAULT NULL AFTER `last_error_code`',
            'next_run_at' => 'DATETIME DEFAULT NULL AFTER `last_response`',
            'locked_at' => 'DATETIME DEFAULT NULL AFTER `next_run_at`',
            'finished_at' => 'DATETIME DEFAULT NULL AFTER `locked_at`',
        ),
        $tables['api'] => array(
            'request_payload_hash' => 'CHAR(40) DEFAULT NULL AFTER `payload`',
            'request_unique_ids' => 'MEDIUMTEXT DEFAULT NULL AFTER `request_payload_hash`',
            'request_unique_ids_created_at' => 'DATETIME DEFAULT NULL AFTER `request_unique_ids`',
            'last_error_code' => 'VARCHAR(64) DEFAULT NULL AFTER `last_error`',
            'last_response' => 'MEDIUMTEXT DEFAULT NULL AFTER `last_error_code`',
            'next_run_at' => 'DATETIME DEFAULT NULL AFTER `last_response`',
            'locked_at' => 'DATETIME DEFAULT NULL AFTER `next_run_at`',
            'finished_at' => 'DATETIME DEFAULT NULL AFTER `locked_at`',
        ),
    );
    foreach ($columnDefinitions as $table => $columns) {
        foreach ($columns as $column => $definition) {
            if (!ssbhesabfaUpgrade239EnsureColumn($table, $column, $definition)) {
                return false;
            }
        }
    }

    if (!ssbhesabfaUpgrade239RepairMappingIndexes($tables['mapping'])) {
        return false;
    }

    $indexDefinitions = array(
        array($tables['log'], 'severity', array('severity'), false),
        array($tables['log'], 'object_type', array('object_type'), false),
        array($tables['log'], 'idx_ssb_hesabfa_log_area', array('area'), false),
        array($tables['log'], 'idx_ssb_hesabfa_log_ps_code', array('prestashop_code'), false),
        array($tables['log'], 'idx_ssb_hesabfa_log_hesabfa_code', array('hesabfa_code'), false),
        array($tables['log'], 'date_add', array('date_add'), false),
        array($tables['operation'], 'uniq_operation_key', array('operation_key'), true),
        array($tables['operation'], 'idx_status', array('status'), false),
        array($tables['operation'], 'idx_object', array('object_type', 'object_id'), false),
        array($tables['issue'], 'idx_status', array('status'), false),
        array($tables['issue'], 'idx_object', array('object_type', 'object_id'), false),
        array($tables['issue'], 'idx_operation_key', array('operation_key'), false),
        array($tables['job'], 'idx_status', array('status'), false),
        array($tables['job'], 'idx_type_status', array('job_type', 'status'), false),
        array($tables['job'], 'idx_status_next_run', array('status', 'next_run_at'), false),
        array($tables['job'], 'idx_object', array('object_type', 'object_id'), false),
        array($tables['api'], 'idx_status', array('status'), false),
        array($tables['api'], 'idx_method_status', array('api_method', 'status'), false),
        array($tables['api'], 'idx_api_status_next_run', array('status', 'next_run_at'), false),
        array($tables['api'], 'idx_object', array('object_type', 'object_id'), false),
        array($tables['api'], 'idx_requester', array('requester'), false),
        array($tables['webhook'], 'uniq_change_id', array('change_id'), true),
        array($tables['webhook'], 'idx_status_change', array('status', 'change_id'), false),
    );
    foreach ($indexDefinitions as $indexDefinition) {
        if (!ssbhesabfaUpgrade239EnsureIndex(
            $indexDefinition[0],
            $indexDefinition[1],
            $indexDefinition[2],
            $indexDefinition[3]
        )) {
            return false;
        }
    }

    if (!ssbhesabfaUpgrade239BackfillQueueMetadata($tables['job'], 'id_ssb_hesabfa_job')
        || !ssbhesabfaUpgrade239BackfillQueueMetadata($tables['api'], 'id_ssb_hesabfa_api_request')) {
        return false;
    }

    if (!$db->execute(
        'UPDATE `' . bqSQL($tables['api']) . '` SET `last_response` = `response` '
        . 'WHERE (`last_response` IS NULL OR `last_response` = "") '
        . 'AND `response` IS NOT NULL AND `response` <> ""'
    )) {
        return false;
    }
    if (!$db->execute(
        'UPDATE `' . bqSQL($tables['job']) . '` SET `status` = "retry_wait" WHERE `status` = "failed"'
    ) || !$db->execute(
        'UPDATE `' . bqSQL($tables['api']) . '` SET `status` = "retry_wait" WHERE `status` = "failed"'
    )) {
        return false;
    }
    if (!$db->execute(
        'UPDATE `' . bqSQL($tables['job']) . '` SET `next_run_at` = NOW() '
        . 'WHERE `next_run_at` IS NULL AND `status` IN ("pending", "retry_wait")'
    ) || !$db->execute(
        'UPDATE `' . bqSQL($tables['api']) . '` SET `next_run_at` = NOW() '
        . 'WHERE `next_run_at` IS NULL AND `status` IN ("pending", "retry_wait")'
    )) {
        return false;
    }

    Configuration::deleteByName('SSBHESABFA_BANK_ACCOUNT_PATH');

    $oldReturnStatus = Configuration::get('SSBHESABFA_INVOICE_RETURN_STATUE');
    if ($oldReturnStatus !== false && $oldReturnStatus !== null && $oldReturnStatus !== '') {
        if (!Configuration::updateValue('SSBHESABFA_INVOICE_RETURN_STATUS', (int) $oldReturnStatus)) {
            return false;
        }
        Configuration::deleteByName('SSBHESABFA_INVOICE_RETURN_STATUE');
    }

    $defaults = array(
        'SSBHESABFA_LIVE_MODE' => 0,
        'SSBHESABFA_SYNC_ENABLED' => 1,
        'SSBHESABFA_DEBUG_MODE' => 0,
        'SSBHESABFA_DELETE_DATA_ON_UNINSTALL' => 0,
        'SSBHESABFA_ASYNC_ORDER_SYNC' => 0,
        'SSBHESABFA_ASYNC_PRODUCT_SYNC' => 0,
        'SSBHESABFA_ASYNC_CUSTOMER_SYNC' => 1,
        'SSBHESABFA_RATE_LIMIT_PER_MINUTE' => 200,
        'SSBHESABFA_INTERNAL_API_USE_QUEUE' => 1,
        'SSBHESABFA_JOB_MAX_ATTEMPTS' => 5,
        'SSBHESABFA_ENABLE_REQUEST_UNIQUE_ID' => 1,
        'SSBHESABFA_ACCOUNT_USERNAME' => '',
        'SSBHESABFA_ACCOUNT_PASSWORD' => '',
        'SSBHESABFA_ACCOUNT_API' => '',
        'SSBHESABFA_ACCOUNT_TOKEN' => '',
        'SSBHESABFA_CONTACT_ADDRESS_STATUS' => 1,
        'SSBHESABFA_CONTACT_NODE_FAMILY' => 'Online Store Customers',
        'SSBHESABFA_CONTACT_ROOT_NODE' => 'Contacts:',
        'SSBHESABFA_ITEM_ROOT_NODE' => 'Products:',
        'SSBHESABFA_ITEM_GIFT_WRAPPING_ID' => 0,
        'SSBHESABFA_ITEM_BARCODE' => 2,
        'SSBHESABFA_ITEM_UPDATE_PRICE' => 0,
        'SSBHESABFA_ITEM_UPDATE_QUANTITY' => 0,
        'SSBHESABFA_LAST_LOG_CHECK_ID' => 0,
        'SSBHESABFA_INVOICE_RETURN_STATUS' => 6,
        'SSBHESABFA_INVOICE_REFERENCE_TYPE' => 1,
        'SSBHESABFA_INVOICE_PROJECT' => '1',
        'SSBHESABFA_MANUAL_PAYMENT_DESCRIPTION_TEMPLATE' => 'Manual gateway payment - invoice {invoice_number}',
        'SSBHESABFA_FEE_INCOME_DOCUMENT_DESCRIPTION_TEMPLATE' => 'Online payment fee income - order {order_id} - transaction {transaction_number}',
        'SSBHESABFA_MANUAL_FEE_INCOME_DOCUMENT_DESCRIPTION_TEMPLATE' => 'Manual gateway payment fee income - invoice {invoice_number} - transaction {transaction_number}',
    );
    foreach ($defaults as $key => $value) {
        if (!ssbhesabfaUpgrade239SetDefault($key, $value)) {
            return false;
        }
    }

    $tokens = array(
        'SSBHESABFA_WEBHOOK_PASSWORD' => 32,
        'SSBHESABFA_WEBHOOK_TOKEN' => 32,
        'SSBHESABFA_QUEUE_CRON_TOKEN' => 16,
    );
    foreach ($tokens as $key => $bytes) {
        if (!Configuration::get($key)
            && !Configuration::updateValue($key, ssbhesabfaUpgrade239GenerateToken($bytes))) {
            return false;
        }
    }

    $hooks = array(
        'displayBackOfficeHeader',
        'displayAdminProductsExtra',
        'displayAdminOrderSide',
        'actionObjectCustomerAddAfter',
        'actionCustomerAccountUpdate',
        'actionObjectCustomerDeleteBefore',
        'actionObjectAddressAddAfter',
        'actionObjectAddressUpdateAfter',
        'actionProductAdd',
        'actionProductUpdate',
        'actionProductDelete',
        'actionProductAttributeAdd',
        'actionProductAttributeUpdate',
        'actionProductAttributeDelete',
        'actionValidateOrder',
        'actionPaymentConfirmation',
        'actionOrderStatusPostUpdate',
    );
    foreach ($hooks as $hookName) {
        if (!ssbhesabfaUpgrade239RegisterHook($module, $hookName)) {
            return false;
        }
    }
    if ($module->isRegisteredInHook('displayAdminOrder')) {
        $module->unregisterHook('displayAdminOrder');
    }
    if ($module->isRegisteredInHook('displayAdminOrderMain')) {
        $module->unregisterHook('displayAdminOrderMain');
    }

    $tabs = array(
        array('AdminSsbHesabfaDashboard', 'Hesabfa', 'ShopParameters', true),
        array('AdminSsbHesabfaSettings', 'API Connection', 'AdminSsbHesabfaDashboard', false),
        array('AdminSsbHesabfaPayments', 'Payment Methods', 'AdminSsbHesabfaDashboard', false),
        array('AdminSsbHesabfaManualPayment', 'Manual Gateway Payment', 'AdminSsbHesabfaDashboard', false),
        array('AdminSsbHesabfaSync', 'Sync / Repair', 'AdminSsbHesabfaDashboard', false),
        array('AdminSsbHesabfaQueue', 'Request Queue', 'AdminSsbHesabfaDashboard', false),
        array('AdminSsbHesabfaInternalApi', 'Internal API', 'AdminSsbHesabfaDashboard', false),
        array('AdminSsbHesabfaLogs', 'Logs / Issues', 'AdminSsbHesabfaDashboard', false),
    );
    foreach ($tabs as $tab) {
        if (!ssbhesabfaUpgrade239EnsureTab($module, $tab[0], $tab[1], $tab[2], $tab[3])) {
            return false;
        }
    }

    return true;
}
