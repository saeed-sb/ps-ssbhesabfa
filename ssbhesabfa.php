<?php
/**
* 2007-2020 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author    PrestaShop SA <contact@prestashop.com>
*  @copyright 2007-2020 PrestaShop SA
*  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

if (!defined('_PS_VERSION_')) {
    exit;
}

include(_PS_MODULE_DIR_ . 'ssbhesabfa/classes/HesabfaDateHelper.php');
include(_PS_MODULE_DIR_ . 'ssbhesabfa/classes/HesabfaTextHelper.php');
include(_PS_MODULE_DIR_ . 'ssbhesabfa/classes/HesabfaLogService.php');
include(_PS_MODULE_DIR_ . 'ssbhesabfa/classes/HesabfaApiResponse.php');
include(_PS_MODULE_DIR_ . 'ssbhesabfa/classes/HesabfaRequestUniqueId.php');
include(_PS_MODULE_DIR_ . 'ssbhesabfa/classes/HesabfaRetryPolicy.php');
include(_PS_MODULE_DIR_ . 'ssbhesabfa/classes/HesabfaRateLimitException.php');
include(_PS_MODULE_DIR_ . 'ssbhesabfa/classes/HesabfaRateLimiter.php');
include(_PS_MODULE_DIR_ . 'ssbhesabfa/classes/HesabfaHttpClient.php');
include(_PS_MODULE_DIR_ . 'ssbhesabfa/classes/HesabfaWebhookChangeRepository.php');
include(_PS_MODULE_DIR_ . 'ssbhesabfa/classes/HesabfaSafeApi.php');
include(_PS_MODULE_DIR_ . 'ssbhesabfa/classes/HesabfaStockService.php');
include(_PS_MODULE_DIR_ . 'ssbhesabfa/classes/HesabfaProductService.php');
include(_PS_MODULE_DIR_ . 'ssbhesabfa/classes/HesabfaJobRepository.php');
include(_PS_MODULE_DIR_ . 'ssbhesabfa/classes/HesabfaInternalApiRequestRepository.php');
include(_PS_MODULE_DIR_ . 'ssbhesabfa/classes/HesabfaLogRepository.php');
include(_PS_MODULE_DIR_ . 'ssbhesabfa/classes/HesabfaIssueRepository.php');
include(_PS_MODULE_DIR_ . 'ssbhesabfa/classes/HesabfaOperationRepository.php');
include(_PS_MODULE_DIR_ . 'ssbhesabfa/classes/HesabfaMappingRepository.php');
include(_PS_MODULE_DIR_ . 'ssbhesabfa/classes/HesabfaPrestashopRepository.php');
include(_PS_MODULE_DIR_ . 'ssbhesabfa/classes/HesabfaAPI.php');
include(_PS_MODULE_DIR_ . 'ssbhesabfa/classes/HesabfaModel.php');

include(_PS_MODULE_DIR_ . 'ssbhesabfa/classes/services/HesabfaExportBatchService.php');
include(_PS_MODULE_DIR_ . 'ssbhesabfa/classes/services/HesabfaQueueService.php');
include(_PS_MODULE_DIR_ . 'ssbhesabfa/classes/services/HesabfaWebhookService.php');
include(_PS_MODULE_DIR_ . 'ssbhesabfa/classes/services/HesabfaPaymentFeeService.php');
include(_PS_MODULE_DIR_ . 'ssbhesabfa/classes/services/HesabfaAdminQueueRenderer.php');
include(_PS_MODULE_DIR_ . 'ssbhesabfa/classes/services/HesabfaProductMappingService.php');

include(_PS_MODULE_DIR_ . 'ssbhesabfa/classes/traits/HesabfaInternalApiTrait.php');
include(_PS_MODULE_DIR_ . 'ssbhesabfa/classes/traits/HesabfaAdminUiTrait.php');
include(_PS_MODULE_DIR_ . 'ssbhesabfa/classes/traits/HesabfaPaymentTrait.php');
include(_PS_MODULE_DIR_ . 'ssbhesabfa/classes/traits/HesabfaSyncTrait.php');
include(_PS_MODULE_DIR_ . 'ssbhesabfa/classes/traits/HesabfaJobTrait.php');
include(_PS_MODULE_DIR_ . 'ssbhesabfa/classes/traits/HesabfaCoreSupportTrait.php');

class Ssbhesabfa extends Module
{
    use HesabfaCoreSupportTrait;
    use HesabfaInternalApiTrait;
    use HesabfaAdminUiTrait;
    use HesabfaPaymentTrait;
    use HesabfaSyncTrait;
    use HesabfaJobTrait;

    const HESABFA_DEFAULT_BANK_ACCOUNT_PATH = 'دارایی ها : دارایی های جاری : موجودی نقد و بانک : بانک';
    const HESABFA_CACHE_TTL = 3600;
    const HESABFA_BATCH_SIZE = 100;

    protected $config_form = false;
    public $id_default_lang;

    public $tabs = array(
        array(
            'class_name' => 'AdminSsbHesabfaDashboard',
            'name' => 'Hesabfa',
            'parent_class_name' => 'ShopParameters',
            'visible' => true,
        ),
        array(
            'class_name' => 'AdminSsbHesabfaSettings',
            'name' => 'API Connection',
            'parent_class_name' => 'AdminSsbHesabfaDashboard',
            'visible' => false,
        ),
        array(
            'class_name' => 'AdminSsbHesabfaPayments',
            'name' => 'Payment Methods',
            'parent_class_name' => 'AdminSsbHesabfaDashboard',
            'visible' => false,
        ),
        array(
            'class_name' => 'AdminSsbHesabfaManualPayment',
            'name' => 'Manual Gateway Payment',
            'parent_class_name' => 'AdminSsbHesabfaDashboard',
            'visible' => false,
        ),
        array(
            'class_name' => 'AdminSsbHesabfaSync',
            'name' => 'Sync / Repair',
            'parent_class_name' => 'AdminSsbHesabfaDashboard',
            'visible' => false,
        ),
        array(
            'class_name' => 'AdminSsbHesabfaQueue',
            'name' => 'Request Queue',
            'parent_class_name' => 'AdminSsbHesabfaDashboard',
            'visible' => false,
        ),
        array(
            'class_name' => 'AdminSsbHesabfaInternalApi',
            'name' => 'Internal API',
            'parent_class_name' => 'AdminSsbHesabfaDashboard',
            'visible' => false,
        ),
        array(
            'class_name' => 'AdminSsbHesabfaLogs',
            'name' => 'Logs / Issues',
            'parent_class_name' => 'AdminSsbHesabfaDashboard',
            'visible' => false,
        ),
    );

    private $configurations = array(
        'SSBHESABFA_LIVE_MODE' => 0,
        'SSBHESABFA_SYNC_ENABLED' => 1,
        'SSBHESABFA_DEBUG_MODE' => 0,
        'SSBHESABFA_DELETE_DATA_ON_UNINSTALL' => 0,
        'SSBHESABFA_ASYNC_ORDER_SYNC' => 0,
        'SSBHESABFA_ASYNC_PRODUCT_SYNC' => 0,
        'SSBHESABFA_ASYNC_CUSTOMER_SYNC' => 1,
        'SSBHESABFA_RATE_LIMIT_PER_MINUTE' => 200,
        'SSBHESABFA_INTERNAL_API_USE_QUEUE' => 1,
        'SSBHESABFA_QUEUE_CRON_TOKEN' => null,
        'SSBHESABFA_JOB_MAX_ATTEMPTS' => 5,
        'SSBHESABFA_ENABLE_REQUEST_UNIQUE_ID' => 1,
        'SSBHESABFA_ACCOUNT_USERNAME' => null,
        'SSBHESABFA_ACCOUNT_PASSWORD' => null,
        'SSBHESABFA_ACCOUNT_API' => null,
        'SSBHESABFA_ACCOUNT_TOKEN' => null,
        'SSBHESABFA_WEBHOOK_PASSWORD' => null,
        'SSBHESABFA_WEBHOOK_TOKEN' => null,
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

    public function __construct()
    {
        $this->name = 'ssbhesabfa';
        $this->tab = 'billing_invoicing';
        $this->version = '2.3.22';
        $this->author = 'Saeed Sattar Beglou';
        $this->need_instance = 0;

        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Hesabfa Online Accounting');
        $this->description = $this->l('Connect Hesabfa Online Accounting to Prestashop');

        $live_mode = Configuration::get('SSBHESABFA_LIVE_MODE');
        if (isset($live_mode) && $live_mode == false) {
            $this->warning = $this->l('The API Connection must be connected before using this module.');
        }

        $this->ps_versions_compliancy = array('min' => '1.7.0.0', 'max' => _PS_VERSION_);
        $this->id_default_lang = Configuration::get('PS_LANG_DEFAULT');
    }

    public function install()
    {
        include(_PS_MODULE_DIR_ . 'ssbhesabfa/sql/install.php');

        foreach ($this->configurations as $key => $val) {
            if (!$this->configurationExists($key) && !Configuration::updateValue($key, $val)) {
                return false;
            }
        }

        if (!$this->configurationExists('SSBHESABFA_WEBHOOK_PASSWORD') || !Configuration::get('SSBHESABFA_WEBHOOK_PASSWORD')) {
            Configuration::updateValue('SSBHESABFA_WEBHOOK_PASSWORD', $this->generateSecureToken(32));
        }

        if (!$this->configurationExists('SSBHESABFA_WEBHOOK_TOKEN') || !Configuration::get('SSBHESABFA_WEBHOOK_TOKEN')) {
            Configuration::updateValue('SSBHESABFA_WEBHOOK_TOKEN', $this->generateSecureToken(32));
        }

        if (!$this->configurationExists('SSBHESABFA_QUEUE_CRON_TOKEN') || !Configuration::get('SSBHESABFA_QUEUE_CRON_TOKEN')) {
            Configuration::updateValue('SSBHESABFA_QUEUE_CRON_TOKEN', $this->generateSecureToken(32));
        }

        return parent::install() &&
            $this->registerHook('displayBackOfficeHeader') &&
            $this->registerHook('displayAdminProductsExtra') &&
            $this->registerHook('displayAdminOrderSide') &&

            $this->registerHook('actionObjectCustomerAddAfter') &&
            $this->registerHook('actionCustomerAccountUpdate') &&
            $this->registerHook('actionObjectCustomerDeleteBefore') &&
            $this->registerHook('actionObjectAddressAddAfter') &&
            $this->registerHook('actionObjectAddressUpdateAfter') &&

            $this->registerHook('actionProductAdd') &&
            $this->registerHook('actionProductUpdate') &&
            $this->registerHook('actionProductDelete') &&

            $this->registerHook('actionProductAttributeAdd') &&
            $this->registerHook('actionProductAttributeUpdate') &&
            $this->registerHook('actionProductAttributeDelete') &&

            $this->registerHook('actionValidateOrder') &&
            $this->registerHook('actionPaymentConfirmation') &&
            $this->registerHook('actionOrderStatusPostUpdate');
    }

    public function uninstall()
    {
        $deleteData = (bool) Configuration::get('SSBHESABFA_DELETE_DATA_ON_UNINSTALL');

        if ($deleteData) {
            include(_PS_MODULE_DIR_ . 'ssbhesabfa/sql/uninstall.php');

            $sql = "SELECT `name` FROM `" . _DB_PREFIX_ . "configuration`
                    WHERE `name` LIKE '%SSBHESABFA_%'";
            $configurations = Db::getInstance()->ExecuteS($sql);

            foreach ($configurations as $configuration) {
                Configuration::deleteByName($configuration['name']);
            }
        }

        return parent::uninstall();
    }

    protected function configurationExists($name)
    {
        return (bool) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` = "' . pSQL($name) . '"'
        );
    }

    protected function generateSecureToken($bytes = 32)
    {
        $bytes = max(16, (int) $bytes);
        if (function_exists('random_bytes')) {
            return bin2hex(random_bytes($bytes));
        }
        if (function_exists('openssl_random_pseudo_bytes')) {
            return bin2hex(openssl_random_pseudo_bytes($bytes));
        }
        return Tools::passwdGen($bytes * 2);
    }

    public function hookDisplayBackOfficeHeader()
    {
        $controller = Tools::getValue('controller');
        if (Tools::getValue('module_name') == $this->name || Tools::getValue('configure') == $this->name || strpos((string) $controller, 'AdminSsbHesabfa') === 0) {
            $this->context->controller->addJqueryUI('ui.datepicker');
            $this->context->controller->addCSS($this->_path . 'views/css/admin.css');
            $this->context->controller->addJS($this->_path . 'views/js/admin.js');
        }
    }

    public function hookActionObjectCustomerAddAfter($params)
    {
        if (!$this->isHesabfaSyncEnabled() || !isset($params['object']) || !Validate::isLoadedObject($params['object'])) { return; }
        if (Configuration::get('SSBHESABFA_ASYNC_CUSTOMER_SYNC')) {
            if ($this->isHesabfaApiConfigured()) $this->queueCustomerSync((int) $params['object']->id, 'actionObjectCustomerAddAfter');
        } elseif (Configuration::get('SSBHESABFA_LIVE_MODE')) {
            $this->setContact((int) $params['object']->id);
        }
    }

    public function hookActionCustomerAccountUpdate($params)
    {
        if (!$this->isHesabfaSyncEnabled() || !isset($params['customer']) || !Validate::isLoadedObject($params['customer'])) { return; }
        if (Configuration::get('SSBHESABFA_ASYNC_CUSTOMER_SYNC')) {
            if ($this->isHesabfaApiConfigured()) $this->queueCustomerSync((int) $params['customer']->id, 'actionCustomerAccountUpdate');
        } elseif (Configuration::get('SSBHESABFA_LIVE_MODE')) {
            $this->setContact((int) $params['customer']->id);
        }
    }

    public function hookActionObjectCustomerDeleteBefore($params)
    {
        if (!$this->isHesabfaSyncEnabled() || !isset($params['object']) || !Validate::isLoadedObject($params['object'])) {
            return;
        }
        $idCustomer = (int) $params['object']->id;
        $mappingId = $this->getObjectId('customer', $idCustomer);
        if ($mappingId <= 0) {
            return;
        }
        $mapping = new HesabfaModel($mappingId);
        if (!Validate::isLoadedObject($mapping)) {
            return;
        }
        if (Configuration::get('SSBHESABFA_ASYNC_CUSTOMER_SYNC') || !Configuration::get('SSBHESABFA_LIVE_MODE')) {
            $this->queueCustomerDelete($idCustomer, $mappingId, (int) $mapping->id_hesabfa);
            return;
        }
        $response = (new HesabfaApi())->contactDelete((int) $mapping->id_hesabfa);
        if ($response->Success) {
            $mapping->delete();
        }
    }

    public function hookActionObjectAddressAddAfter($params)
    {
        if (!$this->isHesabfaSyncEnabled() || !Configuration::get('SSBHESABFA_CONTACT_ADDRESS_STATUS') || !isset($params['object']) || !Validate::isLoadedObject($params['object'])) { return; }
        if (Configuration::get('SSBHESABFA_ASYNC_CUSTOMER_SYNC')) {
            if ($this->isHesabfaApiConfigured()) $this->queueCustomerAddressSync((int) $params['object']->id_customer, (int) $params['object']->id, 'actionObjectAddressAddAfter');
        } elseif (Configuration::get('SSBHESABFA_LIVE_MODE')) {
            $this->setContactAddress((int) $params['object']->id_customer, (int) $params['object']->id);
        }
    }

    public function hookActionObjectAddressUpdateAfter($params)
    {
        if (!$this->isHesabfaSyncEnabled() || !Configuration::get('SSBHESABFA_CONTACT_ADDRESS_STATUS') || !isset($params['object']) || !Validate::isLoadedObject($params['object'])) { return; }
        if (Configuration::get('SSBHESABFA_ASYNC_CUSTOMER_SYNC')) {
            if ($this->isHesabfaApiConfigured()) $this->queueCustomerAddressSync((int) $params['object']->id_customer, (int) $params['object']->id, 'actionObjectAddressUpdateAfter');
        } elseif (Configuration::get('SSBHESABFA_LIVE_MODE')) {
            $this->setContactAddress((int) $params['object']->id_customer, (int) $params['object']->id);
        }
    }

    public function hookActionValidateOrder($params)
    {
        if ($this->isHesabfaSyncEnabled() && isset($params['order']) && Validate::isLoadedObject($params['order'])) {
            $this->safeSetOrderFromHook((int) $params['order']->id, 0, null, 'actionValidateOrder');
        }
    }

    public function hookActionPaymentConfirmation($params)
    {
        if ($this->isHesabfaSyncEnabled() && isset($params['id_order'])) {
            $this->safeSetOrderPaymentFromHook((int) $params['id_order'], 'actionPaymentConfirmation');
        }
    }

    public function hookActionOrderStatusPostUpdate($params)
    {
        if ($params['newOrderStatus']->id == Configuration::get('SSBHESABFA_INVOICE_RETURN_STATUS')) {
            $obj_id = $this->getObjectId('order', $params['id_order']);
            if ($obj_id > 0) {
                $obj = new HesabfaModel($obj_id);
                $this->safeSetOrderFromHook((int) $params['id_order'], 2, $obj->id_hesabfa, 'actionOrderStatusPostUpdate');
            }
        }
    }

    public function hookDisplayAdminOrder($params)
    {
        return '';
    }

    public function hookDisplayAdminOrderMain($params)
    {
        return '';
    }

    public function hookDisplayAdminOrderSide($params)
    {
        return $this->renderAdminOrderHesabfaBox($params);
    }

    public function hookDisplayAdminProductsExtra($params)
    {
        $code = $this->getItemCodeByProductId($params['id_product'], 0);
        $this->context->smarty->assign(array(
            'hesabfa_item_code' => $code,
            'ssbhesabfa_mapping_notices' => $this->consumeProductMappingNotices(),
        ));

        $product = new Product($params['id_product']);
        $combinations = false;

        if ($product->hasAttributes() > 0) {
            $combinations = array();
            $attributes = $product->getAttributesResume($this->id_default_lang);

            foreach ($attributes as $attribute) {
                $code = $this->getItemCodeByProductId($params['id_product'], $attribute['id_product_attribute']);
                $tmp = array(
                    'name' => $attribute['attribute_designation'],
                    'hesabfa_item_code' => $code,
                    'id_hesabfa_item_code' => "ssbhesabfa_hesabfa_item_code_" . $attribute['id_product_attribute'],
                );
                array_push($combinations, $tmp);
            }
        }

        $this->context->smarty->assign(array(
            'combinations' => $combinations,
        ));

        return $this->display(__FILE__, 'views/templates/hook/AdminProductsExtra.tpl');
    }

    public function hookActionProductAdd($params)
    {
        if (!isset($params['product']) || !Validate::isLoadedObject($params['product'])) {
            return false;
        }
        $idProduct = (int) $params['product']->id;
        $mappingResult = (new HesabfaProductMappingService($this))->syncFromAdminRequest($idProduct, $params['product']);
        $this->addProductMappingNotices($mappingResult);
        if (!$mappingResult['success']) {
            self::addLegacyLog('Product Hesabfa mapping validation failed during product add.', 2, 'PRODUCT_MAPPING_VALIDATION_FAILED', 'Product', $idProduct, true);
            return true;
        }
        if (!empty($mappingResult['removed'])) {
            return true;
        }
        if (!$this->isHesabfaSyncEnabled()) {
            return true;
        }
        if (Configuration::get('SSBHESABFA_ASYNC_PRODUCT_SYNC')) {
            if (!$this->isHesabfaApiConfigured()) {
                self::addLegacyLog('Product sync was not queued because Hesabfa API credentials are not configured.', 2, 'HESABFA_NOT_CONFIGURED', 'Product', $idProduct, true);
                return true;
            }
            HesabfaJobRepository::enqueue('sync_product', array('id_product' => $idProduct, 'source_hook' => 'actionProductAdd'), 'Product', $idProduct);
            self::addModuleLog('Hesabfa product sync job was queued from product add hook.', 'INFO', null, 'Product', $idProduct);
            return true;
        }
        if (!Configuration::get('SSBHESABFA_LIVE_MODE')) {
            return true;
        }
        try {
            return (bool) $this->setItems(array($idProduct));
        } catch (Exception $e) {
            self::addLegacyLog('Product sync failed during product add hook. Details: ' . $e->getMessage(), 2, 'PRODUCT_ADD_HOOK_FAILED', 'Product', $idProduct, true);
        }
        return true;
    }

    public function hookActionProductUpdate($params)
    {
        $idProduct = isset($params['product']) && Validate::isLoadedObject($params['product'])
            ? (int) $params['product']->id
            : (int) (isset($params['id_product']) ? $params['id_product'] : 0);

        if ($idProduct <= 0 || HesabfaProductService::isInboundSync($idProduct)) {
            return true;
        }

        $productObject = isset($params['product']) && Validate::isLoadedObject($params['product']) ? $params['product'] : new Product($idProduct);
        if (!Validate::isLoadedObject($productObject)) {
            return false;
        }

        $mappingResult = (new HesabfaProductMappingService($this))->syncFromAdminRequest($idProduct, $productObject);
        $this->addProductMappingNotices($mappingResult);
        if (!$mappingResult['success']) {
            self::addLegacyLog('Product Hesabfa mapping validation failed during product update.', 2, 'PRODUCT_MAPPING_VALIDATION_FAILED', 'Product', $idProduct, true);
            return true;
        }
        if (!empty($mappingResult['removed'])) {
            return true;
        }

        if (!$this->isHesabfaSyncEnabled()) {
            return true;
        }

        if (Configuration::get('SSBHESABFA_ASYNC_PRODUCT_SYNC')) {
            if (!$this->isHesabfaApiConfigured()) {
                self::addLegacyLog('Product sync was not queued because Hesabfa API credentials are not configured.', 2, 'HESABFA_NOT_CONFIGURED', 'Product', $idProduct, true);
                return true;
            }
            HesabfaJobRepository::enqueue('sync_product', array('id_product' => $idProduct, 'source_hook' => 'actionProductUpdate'), 'Product', $idProduct);
            self::addModuleLog('Hesabfa product sync job was queued from product update hook.', 'INFO', null, 'Product', $idProduct);
            return true;
        }

        if (!Configuration::get('SSBHESABFA_LIVE_MODE')) {
            return true;
        }

        try {
            return (bool) $this->setItems(array($idProduct));
        } catch (Exception $e) {
            self::addLegacyLog('Product sync failed during product update hook. Details: ' . $e->getMessage(), 2, 'PRODUCT_UPDATE_HOOK_FAILED', 'Product', $idProduct, true);
        }
        return true;
    }

    public function hookActionProductDelete($params)
    {
        if (!$this->isHesabfaSyncEnabled() || !isset($params['product']) || !Validate::isLoadedObject($params['product'])) {
            return;
        }
        $idProduct = (int) $params['product']->id;
        try {
            foreach ($this->getProductAttributesObjectId($idProduct) as $mappingId) {
                $mapping = new HesabfaModel((int) $mappingId);
                if (!Validate::isLoadedObject($mapping)) {
                    continue;
                }
                $this->queueProductItemDelete($idProduct, (int) $mapping->id_ps_attribute, (int) $mapping->id, (int) $mapping->id_hesabfa, 'actionProductDelete');
            }
        } catch (Exception $e) {
            self::addLegacyLog('Product delete sync could not be queued. Details: ' . $e->getMessage(), 2, 'PRODUCT_DELETE_QUEUE_FAILED', 'Product', $idProduct, true);
        }
    }

    protected function getProductIdFromAttributeHookParams($params)
    {
        if (isset($params['product']) && Validate::isLoadedObject($params['product'])) {
            return (int) $params['product']->id;
        }

        if (!empty($params['id_product'])) {
            return (int) $params['id_product'];
        }

        if (!empty($params['id_product_attribute'])) {
            $combination = new Combination((int) $params['id_product_attribute']);
            if (Validate::isLoadedObject($combination)) {
                return (int) $combination->id_product;
            }
        }

        return 0;
    }

    public function hookActionProductAttributeAdd($params)
    {
        $idProduct = $this->getProductIdFromAttributeHookParams($params);
        if ($idProduct <= 0) {
            self::addLegacyLog('Combination add hook did not provide a resolvable product ID.', 2, 'PRODUCT_ATTRIBUTE_PRODUCT_NOT_FOUND', 'Product', null, true);
            return false;
        }

        if (HesabfaProductService::isInboundSync($idProduct)) {
            return true;
        }

        if (!$this->isHesabfaSyncEnabled()) { return true; }
        if (Configuration::get('SSBHESABFA_ASYNC_PRODUCT_SYNC')) {
            if (!$this->isHesabfaApiConfigured()) { self::addLegacyLog('Product sync was not queued because Hesabfa API credentials are not configured.', 2, 'HESABFA_NOT_CONFIGURED', 'Product', $idProduct, true); return true; }
            HesabfaJobRepository::enqueue('sync_product', array('id_product' => $idProduct, 'source_hook' => 'actionProductAttributeAdd'), 'Product', $idProduct);
            self::addModuleLog('Hesabfa product sync job was queued from combination add hook.', 'INFO', null, 'Product', $idProduct);
            return true;
        }
        if (!Configuration::get('SSBHESABFA_LIVE_MODE')) { return true; }
        try { return (bool) $this->setItems(array($idProduct)); }
        catch (Exception $e) { self::addLegacyLog('Product sync failed during combination add hook. Details: ' . $e->getMessage(), 2, 'PRODUCT_ATTRIBUTE_ADD_HOOK_FAILED', 'Product', $idProduct, true); }
        return true;
    }

    public function hookActionProductAttributeUpdate($params)
    {
        $idProduct = $this->getProductIdFromAttributeHookParams($params);
        if ($idProduct <= 0) {
            self::addLegacyLog('Combination update hook did not provide a resolvable product ID.', 2, 'PRODUCT_ATTRIBUTE_PRODUCT_NOT_FOUND', 'Product', null, true);
            return false;
        }

        if (HesabfaProductService::isInboundSync($idProduct)) {
            return true;
        }

        return $this->hookActionProductAttributeAdd($params);
    }

    public function hookActionProductAttributeDelete($params)
    {
        if (!$this->isHesabfaSyncEnabled()) {
            return;
        }
        $idProduct = isset($params['id_product']) ? (int) $params['id_product'] : 0;
        $idAttribute = isset($params['id_product_attribute']) ? (int) $params['id_product_attribute'] : 0;
        if ($idProduct <= 0 || $idAttribute <= 0) {
            return;
        }
        try {
            $mappingId = $this->getObjectId('product', $idProduct, $idAttribute);
            if ($mappingId > 0) {
                $mapping = new HesabfaModel($mappingId);
                if (Validate::isLoadedObject($mapping)) {
                    $this->queueProductItemDelete($idProduct, $idAttribute, $mappingId, (int) $mapping->id_hesabfa, 'actionProductAttributeDelete');
                }
            }
        } catch (Exception $e) {
            self::addLegacyLog('Combination delete sync could not be queued. Details: ' . $e->getMessage(), 2, 'PRODUCT_ATTRIBUTE_DELETE_QUEUE_FAILED', 'Product', $idProduct, true);
        }
    }
}
