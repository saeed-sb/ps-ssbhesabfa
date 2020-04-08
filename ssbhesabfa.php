<?php
/**
* 2007-2019 PrestaShop
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
*  @copyright 2007-2019 PrestaShop SA
*  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

if (!defined('_PS_VERSION_')) {
    exit;
}

include('classes/hesabfaApi.php');
include('classes/HesabfaModel.php');

class Ssbhesabfa extends Module
{
    protected $config_form = false;

    public function __construct()
    {
        $this->name = 'ssbhesabfa';
        $this->tab = 'billing_invoicing';
        $this->version = '0.8.9';
        $this->author = 'Hesabfa Co - Saeed Sattar Beglou';
        $this->need_instance = 0;

        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Hesabfa Online Accounting');
        $this->description = $this->l('Connect Hesabfa Online Accounting to Prestashop');


        $live_mode = Configuration::get('SSBHESABFA_LIVE_MODE');
        if (isset($live_mode) && $live_mode == false) {
            $this->warning = $this->l('The API Connection must be connected before using this module.');
        }

        $this->ps_versions_compliancy = array('min' => '1.6', 'max' => _PS_VERSION_);
    }

    public function install()
    {
        include(dirname(__FILE__).'/sql/install.php');

        foreach (array(
                     'SSBHESABFA_LIVE_MODE' => false,
                     'SSBHESABFA_DEBUG_MODE' => false,
                     'SSBHESABFA_ACCOUNT_USERNAME' => null,
                     'SSBHESABFA_ACCOUNT_PASSWORD' => null,
                     'SSBHESABFA_ACCOUNT_API' => null,
                     'SSBHESABFA_WEBHOOK_PASSWORD' => bin2hex(openssl_random_pseudo_bytes(16)),
                     'SSBHESABFA_CONTACT_ADDRESS_STATUS' => 1,
                     'SSBHESABFA_CONTACT_NODE_FAMILY' => 'Online Store Customer\'s',
                     'SSBHESABFA_ITEM_GIFT_WRAPPING_ID' => '0',
                     'SSBHESABFA_ITEM_UPDATE_PRICE' => '0',
                     'SSBHESABFA_ITEM_UPDATE_QUANTITY' => '0',
                 ) as $key => $val) {
            if (!Configuration::updateValue($key, $val)) {
                return false;
            }
        }

        return parent::install() &&
            $this->registerHook('backOfficeHeader') &&

            $this->registerHook('actionObjectCustomerAddAfter') &&
            $this->registerHook('actionCustomerAccountUpdate') &&
            $this->registerHook('actionObjectCustomerDeleteBefore') &&
            $this->registerHook('actionObjectAddressAddAfter') &&

            $this->registerHook('actionProductAdd') &&
            $this->registerHook('actionProductUpdate') &&
            $this->registerHook('actionProductDelete') &&

            $this->registerHook('actionValidateOrder') &&
            $this->registerHook('actionPaymentConfirmation');
    }

    public function uninstall()
    {
//        include(dirname(__FILE__).'/sql/uninstall.php');
//
//        $sql = "SELECT `name` FROM `" . _DB_PREFIX_ . "configuration`
//                WHERE `name` LIKE '%SSBHESABFA_%'";
//        $configurations = Db::getInstance()->ExecuteS($sql);
//
//        foreach ($configurations as $configuration) {
//            Configuration::deleteByName($configuration['name']);
//        }

        return parent::uninstall();
    }

    /**
     * Load the configuration form
     */
    public function getContent()
    {
        $output = '';

        //show error if store installed in local
        $shop_domain = Configuration::get('PS_SHOP_DOMAIN');
        if ($shop_domain === '127.0.0.1' || $shop_domain === 'localhost') {
            $output .= $this->displayWarning($this->l('Your store is installed on localhost, Hesabfa changes will not be applied to the store.'));
        }

        require_once(_PS_MODULE_DIR_.$this->name.'/classes/HesabfaUpdate.php');
        $update = HesabfaUpdate::getInstance($this);

        //Submits
        if (((bool)Tools::isSubmit('submitSsbhesabfaModuleConfig')) == true) {
            $this->setConfigFormsValues('Config');
            $connection = $this->setChangeHook();
            //check if internet connection fail
            if (is_object($connection)) {
                if ($connection->Success) {
                    $output .= $this->displayConfirmation($this->l('API Setting updated. Test Successfully'));
                } else {
                    $output .= $this->displayError($this->l('Connecting to Hesabfa fail.') .' '. $this->l('Error Code: ') . $connection->ErrorCode .'. '. $this->l('Error Message: ') . $connection->ErrorMessage);
                }
            } else {
                $output .= $this->displayError($this->l('Connecting to Hesabfa fail. Please check your Internet connection.'));
            }

        } elseif (((bool)Tools::isSubmit('submitSsbhesabfaModuleUpdate')) == true) {
            $this->context->smarty->assign(array(
                'notices' => $update->getNotice(),
                'need_update' => $update->checkUpdate(),
            ));
        } elseif (((bool)Tools::isSubmit('submitSsbhesabfaModuleUpgrade')) == true) {
            $this->context->smarty->assign(array(
                'upgrade' => $update->upgrade(),
            ));
        } elseif (((bool)Tools::isSubmit('submitSsbhesabfaModuleBank')) == true) {
            $this->setConfigFormsValues('Bank');
            $output .= $this->displayConfirmation($this->l('Payments Methods Setting updated.'));
        } elseif (((bool)Tools::isSubmit('submitSsbhesabfaModuleItem')) == true) {
            $this->setConfigFormsValues('Item');
            $output .= $this->displayConfirmation($this->l('Catalog Setting updated.'));
        } elseif (((bool)Tools::isSubmit('submitSsbhesabfaModuleContact')) == true) {
            $this->setConfigFormsValues('Contact');
            $output .= $this->displayConfirmation($this->l('Customers Setting updated.'));
        } elseif (((bool)Tools::isSubmit('submitSsbhesabfaExportProducts')) == true) {
            if (Configuration::get('SSBHESABFA_LIVE_MODE')) {
                $this->exportProducts();
                $output .= $this->displayConfirmation($this->l('Products exported to Hesabfa successfully.'));
            } else {
                $output .= $this->displayWarning($this->l('The API Connection must be connected before export Products.'));
            }
        } elseif (((bool)Tools::isSubmit('submitSsbhesabfaExportProductsWithQuantity')) == true) {
            if (Configuration::get('SSBHESABFA_LIVE_MODE')) {
                $this->exportProducts(1);
                $output .= $this->displayConfirmation($this->l('Products exported to Hesabfa successfully.'));
            } else {
                $output .= $this->displayWarning($this->l('The API Connection must be connected before export Products.'));
            }
        } elseif (((bool)Tools::isSubmit('submitSsbhesabfaExportCustomers')) == true) {
            if (Configuration::get('SSBHESABFA_LIVE_MODE')) {
                $this->exportCustomers();
                $output .= $this->displayConfirmation($this->l('Customers exported to Hesabfa successfully.'));
            } else {
                $output .= $this->displayWarning($this->l('The API Connection must be connected before export Customers.'));
            }
        } elseif (((bool)Tools::isSubmit('submitSsbhesabfaExportInvoices')) == true) {
            if (Configuration::get('SSBHESABFA_LIVE_MODE')) {
                $from_date = Tools::getValue('SSBHESABFA_SYNC_ORDER_FROM');
                if ($from_date == null) {
                    $output .= $this->displayError($this->l('Enter date from'));
                } elseif (!Validate::isDateFormat($from_date)) {
                    $output .= $this->displayError($this->l('Enter correct date format.'));
                } else {
                    $output .= $this->displayConfirmation($this->l('Orders synced with Hesabfa successfully.'));
                    $orders_id = $this->syncOrders($from_date);
                    if (!empty($orders_id)) {
                        $output .= $this->displayConfirmation($this->l('Orders ID: ') . implode(' - ', $orders_id));
                    }
                }
            } else {
                $output .= $this->displayWarning($this->l('The API Connection must be connected before sync Invoices.'));
            }
        } elseif (((bool)Tools::isSubmit('submitSsbhesabfaSyncChanges')) == true) {
            if (Configuration::get('SSBHESABFA_LIVE_MODE')) {
                include(dirname(__FILE__) . '/classes/HesabfaWebhook.php');
                new HesabfaWebhook();
                $output .= $this->displayConfirmation($this->l('Changes synced with Hesabfa successfully.'));
            } else {
                $output .= $this->displayWarning($this->l('The API Connection must be connected before sync Changes.'));
            }
        }

        //assign smarty vars
        $forms = array('Bank', 'Config', 'Item', 'Contact');
        foreach ($forms as $form) {
            $html = $this->renderForm($form);
            $this->context->smarty->assign($form, $html);
        }

        $this->context->smarty->assign(array(
            'current_form_tab' => Tools::getValue('form_tab'),
            'export_action_url' => './index.php?tab=AdminModules&configure=ssbhesabfa&token=' . Tools::getAdminTokenLite('AdminModules') . '&tab_module=' . $this->tab . '&module_name=ssbhesabfa&form_tab=Export',
            'update_action_url' => './index.php?tab=AdminModules&configure=ssbhesabfa&token=' . Tools::getAdminTokenLite('AdminModules') . '&tab_module=' . $this->tab . '&module_name=ssbhesabfa&form_tab=Home',
            'live_mode' => Configuration::get('SSBHESABFA_LIVE_MODE'),
            'module_ver' => $this->version,
        ));

        //Show error when connection not stabilised
        if (Configuration::get('SSBHESABFA_LIVE_MODE') != 1) {
            $output .= $this->displayError($this->l('Connecting to Hesabfa fail. Please open the API tab and check your API Settings.'));
        }

        //Show error when Banks not mapped
        if (Configuration::get('SSBHESABFA_LIVE_MODE') == 1) {
            $payment_methods = $this->getPaymentMethodsName();
            foreach ($payment_methods as $method) {
                if (!Configuration::get($method['id'])) {
                    $output .= $this->displayError($this->l('Payment methods not mapped with Banks. Please check setting in Payment Methods tab.'));
                    break;
                }
            }
        }

        // To load form inside your template
        $output .= $this->context->smarty->fetch($this->local_path.'views/templates/admin/configure.tpl');

        // To return form html only
        return $output;
    }

    /**
     * Create the form that will be displayed in the configuration of module.
     */
    protected function renderForm($form = null)
    {
        $helper = new HelperForm();

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

        $helper->identifier = $this->identifier;
        //$helper->submit_action = 'submitSsbhesabfaModuleSaveSetting';
        $helper->submit_action = 'submitSsbhesabfaModule'.$form;
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            .'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name.'&form_tab='.$form;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFormValues($form), /* Add values for your inputs */
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );

        $function_name = 'get'.$form.'Form';
        return $helper->generateForm(array($this->$function_name()));
    }

    /**
     * Configuration Tab's form
     * @return array
     */
    protected function getConfigForm()
    {
        return array(
            'form' => array(
                'input' => array(
                    array(
                        'col' => 3,
                        'type' => 'text',
                        'prefix' => '<i class="icon icon-envelope"></i>',
                        'desc' => $this->l('Enter a Hesabfa email account'),
                        'name' => 'SSBHESABFA_ACCOUNT_USERNAME',
                        'label' => $this->l('Email'),
                    ),
                    array(
                        'col' => 3,
                        'type' => 'password',
                        'desc' => $this->l('Enter a Hesabfa password'),
                        'name' => 'SSBHESABFA_ACCOUNT_PASSWORD',
                        'label' => $this->l('Password'),
                    ),
                    array(
                        'col' => 6,
                        'type' => 'text',
                        'desc' => $this->l('Find API key in Setting->Financial Settings->API Menu'),
                        'name' => 'SSBHESABFA_ACCOUNT_API',
                        'label' => $this->l('API Key'),
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            ),
        );
    }

    protected function getBankForm()
    {
        $input_array = array();

        $bank_options = array();
        $hesabfaApi = new HesabfaApi();
        $banks = $hesabfaApi->settingGetBanks();

        if (is_object($banks) && $banks->Success) {
            foreach ($banks->Result as $bank) {
                //show only bank with default currency in hesabfa
                $default_currency = new Currency(Configuration::get('SSBHESABFA_HESABFA_DEFAULT_CURRENCY'));
                if ($bank->Currency == $default_currency->getSign()) {
                    array_push($bank_options, array(
                        'id_option' => $bank->Code,
                        'name' => $bank->Name . ' - ' . $bank->Branch . ' - ' . $bank->AccountNumber,
                    ));
                }
            }
            if (empty($bank_options)) {
                $bank_options = array(
                    'id_option' => 0,
                    'name' => $this->l('Define at least one bank in Hesabfa'),
                );
            }

            foreach ($this->getPaymentMethodsName() as $item) {
                $input = array(
                    'col' => 3,
                    'type' => 'select',
                    'name' => $item['id'],
                    'label' => $item['name'],
                    'options' => array(
                        'query' => $bank_options,
                        'id' => 'id_option',
                        'name' => 'name'
                    )
                );

                array_push($input_array, $input);
            }
        } else {
            Configuration::updateValue('SSBHESABFA_LIVE_MODE', false);
        }

        return array(
            'form' => array(
                'input' => $input_array,
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            ),
        );
    }

    protected function getItemForm()
    {
        return array(
            'form' => array(
                'input' => array(
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Update Price'),
                        'name' => 'SSBHESABFA_ITEM_UPDATE_PRICE',
                        'is_bool' => true,
                        'desc' => $this->l('Update Price after change in Hesabfa'),
                        'values' => array(
                            array(
                                'id' => 'price_on',
                                'value' => true,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id' => 'price_off',
                                'value' => false,
                                'label' => $this->l('Disabled')
                            )
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Update Quantity'),
                        'name' => 'SSBHESABFA_ITEM_UPDATE_QUANTITY',
                        'is_bool' => true,
                        'desc' => $this->l('Update Quantity after change in Hesabfa'),
                        'values' => array(
                            array(
                                'id' => 'quantity_on',
                                'value' => true,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id' => 'quantity_off',
                                'value' => false,
                                'label' => $this->l('Disabled')
                            )
                        ),
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            ),
        );
    }

    protected function getContactForm()
    {
        return array(
            'form' => array(
                'input' => array(
                    array(
                        'type' => 'select',
                        'label' => $this->l('Update Customer Address:'),
                        'desc' => $this->l('Choose when update Customer address in Hesabfa'),
                        'name' => 'SSBHESABFA_CONTACT_ADDRESS_STATUS',
                        'options' => array(
                            'query' => array(
                                array(
                                    'id_option' => 1,
                                    'name' => $this->l('Use first customer address'),
                                ),
                                array(
                                    'id_option' => 2,
                                    'name' => $this->l('update address with Invoice address'),
                                ),
                                array(
                                    'id_option' => 3,
                                    'name' => $this->l('update address with Delivery address'),
                                ),
                            ),
                            'id' => 'id_option',
                            'name' => 'name'
                        )
                    ),
                    array(
                        'col' => 3,
                        'type' => 'text',
                        'desc' => $this->l('Enter a Customer\'s Group in Hesabfa'),
                        'name' => 'SSBHESABFA_CONTACT_NODE_FAMILY',
                        'label' => $this->l('Customer\'s Group'),
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            ),
        );
    }

    protected function getInvoiceForm()
    {
        $currency_options = array();
        foreach (Currency::getCurrencies() as $currency) {
            array_push($currency_options, array(
                'id_option' => $currency['id_currency'],
                'name' => $currency['name'],
            ));
        }

        return array(
            'form' => array(
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            ),
        );
    }

    //Configuration form Values
    protected function getConfigFormValues($form = null)
    {
        switch ($form) {
            case 'Config':
                $keys =  array(
                    'SSBHESABFA_ACCOUNT_USERNAME' => Configuration::get('SSBHESABFA_ACCOUNT_USERNAME'),
                    'SSBHESABFA_ACCOUNT_PASSWORD' => Configuration::get('SSBHESABFA_ACCOUNT_PASSWORD'),
                    'SSBHESABFA_ACCOUNT_API' => Configuration::get('SSBHESABFA_ACCOUNT_API'),
                );
                break;
            case 'Item':
                $keys =  array(
                    'SSBHESABFA_ITEM_UPDATE_PRICE' => Configuration::get('SSBHESABFA_ITEM_UPDATE_PRICE'),
                    'SSBHESABFA_ITEM_UPDATE_QUANTITY' => Configuration::get('SSBHESABFA_ITEM_UPDATE_QUANTITY'),
                );
                break;
            case 'Contact':
                $keys =  array(
                    'SSBHESABFA_CONTACT_ADDRESS_STATUS' => Configuration::get('SSBHESABFA_CONTACT_ADDRESS_STATUS'),
                    'SSBHESABFA_CONTACT_NODE_FAMILY' => Configuration::get('SSBHESABFA_CONTACT_NODE_FAMILY'),
                );
                break;
            case 'Bank':
                $keys = array();
                $paymentsName = $this->getPaymentMethodsName();
                foreach ($paymentsName as $item) {
                    $keys[$item['id']] = Configuration::get($item['id']);
                }
                break;
            default:
                $keys =  array(
                    'SSBHESABFA_ACCOUNT_USERNAME' => Configuration::get('SSBHESABFA_ACCOUNT_USERNAME'),
                    'SSBHESABFA_ACCOUNT_PASSWORD' => Configuration::get('SSBHESABFA_ACCOUNT_PASSWORD'),
                    'SSBHESABFA_ACCOUNT_API' => Configuration::get('SSBHESABFA_ACCOUNT_API'),

                    'SSBHESABFA_ITEM_UPDATE_PRICE' => Configuration::get('SSBHESABFA_ITEM_UPDATE_PRICE'),
                    'SSBHESABFA_ITEM_UPDATE_QUANTITY' => Configuration::get('SSBHESABFA_ITEM_UPDATE_QUANTITY'),

                    'SSBHESABFA_CONTACT_ADDRESS_STATUS' => Configuration::get('SSBHESABFA_CONTACT_ADDRESS_STATUS'),
                    'SSBHESABFA_CONTACT_NODE_FAMILY' => Configuration::get('SSBHESABFA_CONTACT_NODE_FAMILY'),
                );

                //Get config form value in Payment Method's tab
                $paymentsName = $this->getPaymentMethodsName();
                foreach ($paymentsName as $item) {
                    $keys[$item['id']] = Configuration::get($item['id']);
                }
        }
        return $keys;
    }

    protected function setConfigFormsValues($form = null)
    {
        $form_values = $this->getConfigFormValues($form);
        foreach (array_keys($form_values) as $key) {
            //don't replace password with null if password not entered
            if ($key == 'SSBHESABFA_ACCOUNT_PASSWORD' && Tools::getValue($key) == null) {
                break;
            }

            Configuration::updateValue($key, Tools::getValue($key));
        }
    }

    //Functions
    //Return Payment methods Name and ID
    public function getPaymentMethodsName()
    {
        $payment_array = array();
        $modules_list = Module::getPaymentModules();

        foreach ($modules_list as $module) {
            $module_obj = Module::getInstanceById($module['id_module']);
            array_push($payment_array, array(
                'name' => $module_obj->displayName,
                'id' => 'SSBHESABFA_PAYMENT_METHOD_' . $module['id_module'],
            ));
        }

        //ToDo: PrestaPay plugins name
        if (Module::isInstalled('psf_prestapay')) {
            $prestapay = Module::getInstanceByName('psf_prestapay');

            /* Check if the module is enabled */
            if ($prestapay->active) {
                //die(var_dump($prestapay->getModulePlugins(1)));
                $psf_prestapay = new psf_prestapay();
                $plugins = $psf_prestapay->getModulePlugins(1);
                foreach ($plugins as $plugin) {
                    $plugin;
                }
            }
        }

        return $payment_array;
    }

    public function setChangeHook()
    {
        $store_url = $this->context->link->getBaseLink();
        $url = $store_url . 'modules/ssbhesabfa/ssbhesabfa-webhook.php?token=' . Tools::substr(Tools::encrypt('ssbhesabfa/webhook'), 0, 10);
        //$url = 'https://webhook.site/52c66398-281d-4049-941a-478352271969';
        $hookPassword = Configuration::get('SSBHESABFA_WEBHOOK_PASSWORD');

        $hesabfa = new HesabfaApi();
        $response = $hesabfa->settingSetChangeHook($url, $hookPassword);

        if (is_object($response)) {
            if ($response->Success) {
                Configuration::updateValue('SSBHESABFA_LIVE_MODE', 1);

                //set the last log ID
                $changes = $hesabfa->settingGetChanges();
                if ($changes->Success) {
                    if (Configuration::get('SSBHESABFA_LAST_LOG_CHECK_ID') == 0) {
                        $lastChange = end($changes->Result);
                        Configuration::updateValue('SSBHESABFA_LAST_LOG_CHECK_ID', $lastChange->Id);
                    }
                } else {
                    $msg = 'ssbhesabfa - Cannot check the last change ID. Error Message: ' . $changes->ErrorMessage;
                    PrestaShopLogger::addLog($msg, 2, $changes->ErrorCode, null, null, true);
                }

                //set the Hesabfa default currency
                $default_currency = $hesabfa->settingGetCurrency();
                if ($default_currency->Success) {
                    $id_currency = Currency::getIdByIsoCode($default_currency->Result->Currency);
                    if ($id_currency > 0) {
                        Configuration::updateValue('SSBHESABFA_HESABFA_DEFAULT_CURRENCY', $id_currency);
                    } else {
                        $currency = new Currency();
                        $currency->iso_code = $default_currency->Result->Currency;

                        if ($currency->add()) {
                            Configuration::updateValue('SSBHESABFA_HESABFA_DEFAULT_CURRENCY', $currency->id);
                        }

                        $msg = 'ssbhesabfa - Hesabfa default currency('. $default_currency->Result->Currency .') added to Online Store';
                        PrestaShopLogger::addLog($msg, 1, null, null, null, true);
                    }
                } else {
                    $msg = 'ssbhesabfa - Cannot check the Hesabfa default currency. Error Message: ' . $default_currency->ErrorMessage;
                    PrestaShopLogger::addLog($msg, 2, $default_currency->ErrorCode, null, null, true);
                }

                //set the Gift wrapping service id
                if (Configuration::get('SSBHESABFA_ITEM_GIFT_WRAPPING_ID') == 0) {
                    $hesabfa = new HesabfaApi();
                    $gift_wrapping = $hesabfa->itemSave(array(
                        'Name' => 'Gift wrapping service',
                        'ItemType' => 1,
                        'Tag' => '{"id_product": 0}',
                    ));

                    if ($gift_wrapping->Success) {
                        Configuration::updateValue('SSBHESABFA_ITEM_GIFT_WRAPPING_ID', $gift_wrapping->Result->Code);

                        $msg = 'ssbhesabfa - Hesabfa Giftwrapping service added successfully. Service Code: ' . $gift_wrapping->Result->Code;
                        PrestaShopLogger::addLog($msg, 1, null, null, null, true);
                    } else {
                        $msg = 'ssbhesabfa - Cannot set Giftwrapping service code. Error Message: ' . $gift_wrapping->ErrorMessage;
                        PrestaShopLogger::addLog($msg, 2, $gift_wrapping->ErrorCode, null, null, true);
                    }
                }

                $msg = 'ssbhesabfa - Hesabfa webHook successfully Set. URL: ' . (string)$response->Result->url;
                PrestaShopLogger::addLog($msg, 1, null, null, null, true);
            } else {
                Configuration::updateValue('SSBHESABFA_LIVE_MODE', 0);

                $msg = 'ssbhesabfa - Cannot set Hesabfa webHook. Error Message: ' . $response->ErrorMessage;
                PrestaShopLogger::addLog($msg, 2, $response->ErrorCode, null, null, true);
            }
        } else {
            $msg = 'ssbhesabfa - Cannot set Hesabfa webHook. Please check your Internet connection';
            PrestaShopLogger::addLog($msg, 2, null, null, null, true);
        }

        return $response;
    }

    public static function getObjectId($type, $id_ps)
    {
        if (!isset($type) || !isset($id_ps)) {
            return false;
        }

        $sql = 'SELECT `id_ssb_hesabfa` 
                    FROM `' . _DB_PREFIX_ . 'ssb_hesabfa`
                    WHERE `id_ps` = '. $id_ps .' AND `obj_type` = \''. $type .'\'
                    ';

        return (int)Db::getInstance()->getValue($sql);
    }

    public static function getObjectIdByCode($type, $id_hesabfa)
    {
        if (!isset($type) || !isset($id_hesabfa)) {
            return false;
        }

        $sql = 'SELECT `id_ssb_hesabfa` 
                    FROM `' . _DB_PREFIX_ . 'ssb_hesabfa`
                    WHERE `id_hesabfa` = '. $id_hesabfa .' AND `obj_type` = \''. $type .'\'
                    ';

        return (int)Db::getInstance()->getValue($sql);
    }

    //Items
    public function getItemCodeByProductId($id_product)
    {
        if (!isset($id_product)) {
            return false;
        }

        $obj = new HesabfaModel($this->getObjectId('product', $id_product));
        if (is_object($obj)) {
            return $obj->id_hesabfa;
        } else {
            return false;
        }
    }

    public function setItem($id_product, $setQuantity = 0)
    {
        if (!isset($id_product)) {
            return false;
        }

        $id_default_lang = Configuration::get('PS_LANG_DEFAULT');
        $code = null;
        if ($this->getItemCodeByProductId($id_product) != false) {
            $code = $this->getItemCodeByProductId($id_product);
        }

        $product = new Product($id_product);
        $itemType = ($product->is_virtual == 1 ? 1 : 0);
        $quantity = $setQuantity ? $product->quantity : null;

        $item = array(
            'Code' => $code,
            'Name' => $product->name[$id_default_lang],
            'ItemType' => $itemType,
            'Barcode' => $product->upc,
            'SellPrice' => $this->getPriceInHesabfaDefaultCurrency($product->price),
            'Quantity' => $quantity,
            'Tag' => '{"id_product": '.$id_product.'}',
            'NodeFamily' => $this->getCategoryPath($product->id_category_default),
            'ProductCode' => $id_product,
        );

        $hesabfa = new HesabfaApi();
        $response = $hesabfa->itemSave($item);
        if ($response->Success) {
            $obj = new HesabfaModel();
            $obj->id_hesabfa = (int)$response->Result->Code;
            $obj->obj_type = 'product';
            $obj->id_ps = $id_product;
            if ($code == null) {
                $obj->add();
                $msg = 'ssbhesabfa - Item successfully added. Item code: ' . $response->Result->Code;
                PrestaShopLogger::addLog($msg, 1, null, 'Product', $id_product, true);
            } else {
                $obj->id_ssb_hesabfa = $this->getObjectId('product', $id_product);
                $obj->update();
                $msg = 'ssbhesabfa -  Item successfully updated. Item code: ' . $response->Result->Code;
                PrestaShopLogger::addLog($msg, 1, null, 'Product', $id_product, true);
            }
            return $response->Result->Code;
        } else {
            $msg = 'ssbhesabfa - Cannot add/update Hesabfa item. Error Message: ' . $response->ErrorMessage;
            PrestaShopLogger::addLog($msg, 2, $response->ErrorCode, 'Product', $id_product, true);

            return false;
        }
    }

    private function getCategoryPath($id_category)
    {
        if ($id_category < 2) {
            $sign = ' : '; // You can customize your sign which splits categories
            //array_pop($this->categoryArray);
            $categoryArray = array_reverse($this->categoryArray);
            $categoryPath = '';
            foreach ($categoryArray as $categoryName) {
                $categoryPath .= $categoryName.$sign;
            }
            $this->categoryArray = array();
            return Tools::substr($categoryPath, 0, -Tools::strlen($sign));
        } else {
            $category = new Category($id_category, Context::getContext()->language->id);
            $this->categoryArray[] = $category->name;
            return $this->getCategoryPath($category->id_parent);
        }
    }

    //Contact
    public function getContactCodeByCustomerId($id_customer)
    {
        if (!isset($id_customer)) {
            return false;
        }

        $obj = new HesabfaModel($this->getObjectId('customer', $id_customer));
        return $obj->id_hesabfa;
    }

    public function setContact($id_customer)
    {
        if (!isset($id_customer)) {
            return false;
        }

        $code = null;
        if ($this->getContactCodeByCustomerId($id_customer) != false) {
            $code = $this->getContactCodeByCustomerId($id_customer);
        }

        $customer = new Customer($id_customer);
        $data = array (
            array(
                'Code' => $code,
                'Name' => $customer->firstname . ' ' . $customer->lastname,
                'FirstName' => $customer->firstname,
                'LastName' => $customer->lastname,
                'ContactType' => 1,
                'NodeFamily' => 'اشخاص :' . Configuration::get('SSBHESABFA_CONTACT_NODE_FAMILY'),
                'Email' => $customer->email,
                'Tag' => '{"id_customer": '.$id_customer.'}',
                'Note' => 'Customer ID in OnlineStore: ' . $id_customer,
            )
        );

        $hesabfa = new HesabfaApi();
        $response = $hesabfa->contactBatchSave($data);

        if ($response->Success) {
            $obj = new HesabfaModel();
            $obj->id_hesabfa = (int)$response->Result[0]->Code;
            $obj->obj_type = 'customer';
            $obj->id_ps = $id_customer;
            if ($code == null) {
                $obj->add();
                $msg = 'ssbhesabfa - Contact successfully added. Contact Code: ' . $response->Result[0]->Code;
                PrestaShopLogger::addLog($msg, 1, null, 'Customer', $id_customer, true);
            } else {
                $obj->id_ssb_hesabfa = $this->getObjectId('customer', $id_customer);
                $obj->update();
                $msg = 'ssbhesabfa - Contact successfully updated. Contact Code: ' . $response->Result[0]->Code;
                PrestaShopLogger::addLog($msg, 1, null, 'Customer', $id_customer, true);
            }
            return true;
        } else {
            $msg = 'ssbhesabfa - Cannot add/update item. Error Message: ' . $response->ErrorMessage;
            PrestaShopLogger::addLog($msg, 2, $response->ErrorCode, 'Customer', $id_customer, true);
            return false;
        }
    }

    public function setContactAddress($id_customer, $id_address)
    {
        if (!isset($id_customer) || !isset($id_address)) {
            return false;
        }

        $code = $this->getContactCodeByCustomerId($id_customer);

        $customer = new Customer($id_customer);
        $address = new Address($id_address);

        $data = array (
            array(
                'Code' => (int)$code,
                'Name' => $customer->firstname . ' ' . $customer->lastname,
                'FirstName' => $customer->firstname,
                'LastName' => $customer->lastname,
                'ContactType' => 1,
                'NationalCode' => $address->dni,
                'EconomicCode' => $address->vat_number,
                'Address' => $address->address1 . ' ' . $address->address2,
                'City' => $address->city,
                'State' => State::getNameById($address->id_state)  == false ? null : State::getNameById($address->id_state),
                'Country' => Country::getNameById($this->context->language->id, $address->id_country) == false ? null : Country::getNameById($this->context->language->id, $address->id_country),
                'PostalCode' => preg_replace("/[^0-9]/", '', $address->postcode),
                'Phone' => preg_replace("/[^0-9]/", "", $address->phone),
                'Mobile' => preg_replace("/[^0-9]/", "", $address->phone_mobile),
                'Email' => $customer->email,
                'Tag' => '{"id_customer": '.$id_customer.'}',
            )
        );

        $hesabfa = new HesabfaApi();
        $response = $hesabfa->contactBatchSave($data);

        if ($response->Success) {
            $msg = 'ssbhesabfa - Contact address successfully updated. Contact Code: ' . $response->Result[0]->Code;
            PrestaShopLogger::addLog($msg, 1, null, 'Customer', $id_customer);
            return true;
        } else {
            $msg = 'ssbhesabfa - Cannot add/update contact address. Error Message: ' . $response->ErrorMessage;
            PrestaShopLogger::addLog($msg, 2, $response->ErrorCode, 'Customer', $id_customer);
            return false;
        }
    }

    //Invoice
    public function getInvoiceCodeByOrderId($id_order)
    {
        $obj = new HesabfaModel($this->getObjectId('order', $id_order));
        return $obj->id_hesabfa;
    }

    public function setOrder($id_order)
    {
        if (!isset($id_order)) {
            return false;
        }

        $order = new Order($id_order);

        //set customer if not exists
        $contactCode = $this->getObjectId('customer', $order->id_customer);

        if ($contactCode == 0) {
            $this->setContact($order->id_customer);
            $this->setContactAddress($order->id_customer, $order->id_address_invoice);
        }

        // add Contact Address
        if (Configuration::get('SSBHESABFA_CONTACT_ADDRESS_STATUS') == 2) {
            $this->setContactAddress($order->id_customer, $order->id_address_invoice);
        } elseif (Configuration::get('SSBHESABFA_CONTACT_ADDRESS_STATUS') == 3) {
            $this->setContactAddress($order->id_customer, $order->id_address_delivery);
        }

        $items = array();
        $i = 0;

        //Splitting total discount to each item
        $split = 0;
        $total_discounts = 0;
        if ($order->total_discounts > 0) {
            $split = $order->total_discounts / $order->total_products;
        }

        $products = $order->getProducts();
        foreach ($products as $key => $product) {
            $code = $this->getItemCodeByProductId($product['product_id']);

            // add product before insert invoice
            if ($code == null) {
                $code = $this->setItem($product['product_id']);
                //$code = $this->getItemCodeByProductId($product['product_id']);
            }

            //fix remaining discount amount on last item
            $array_key = array_keys($products);
            if (end($array_key) == $key) {
                $discount = $order->total_discounts - $total_discounts;
            } else {
                $discount = ($product['product_price'] * $split * $product['product_quantity']);
                $total_discounts += $discount;
                $discount += $product['reduction_amount'];
            }

            $item = array (
                'RowNumber' => $i,
                'ItemCode' => (int)$code,
                'Description' => $product['product_name'],
                'Quantity' => (int)$product['product_quantity'],
                'UnitPrice' => (float)$this->getOrderPriceInHesabfaDefaultCurrency($product['product_price'], $id_order),
                'Discount' => (float)$this->getOrderPriceInHesabfaDefaultCurrency($discount, $id_order),
                'Tax' => (float)$this->getOrderPriceInHesabfaDefaultCurrency(($product['unit_price_tax_incl'] - $product['unit_price_tax_excl']), $id_order),
            );
            array_push($items, $item);
            $i++;
        }

        if ($order->total_wrapping_tax_excl > 0) {
            array_push($items, array (
                'RowNumber' => $i+1,
                'ItemCode' => Configuration::get('SSBHESABFA_ITEM_GIFT_WRAPPING_ID'),
                'Description' => $this->l('Gift wrapping Service'),
                'Quantity' => 1,
                'UnitPrice' => $this->getOrderPriceInHesabfaDefaultCurrency(($order->total_wrapping), $id_order),
                'Discount' => 0,
                'Tax' => $this->getOrderPriceInHesabfaDefaultCurrency(($order->total_wrapping_tax_incl - $order->total_wrapping_tax_excl), $id_order),
            ));
        }

        $number = $this->getInvoiceCodeByOrderId($id_order);
        $data = array (
            'Number' => $number,
            'InvoiceType' => 0,
            'ContactCode' => $this->getContactCodeByCustomerId($order->id_customer),
            'Date' => $order->date_add,
            'DueDate' => $order->date_add,
            'Reference' => $order->reference,
            'Status' => 2,
            'Tag' => '{"id_order": '.$id_order.'}',
            'Freight' => $this->getOrderPriceInHesabfaDefaultCurrency($order->total_shipping_tax_incl, $id_order),
            'InvoiceItems' => $items,
        );

        $hesabfa = new HesabfaApi();
        $response = $hesabfa->invoiceSave($data);
        if ($response->Success) {
            $obj = new HesabfaModel();
            $obj->id_hesabfa = (int)$response->Result->Number;
            $obj->obj_type = 'order';
            $obj->id_ps = $id_order;
            if ($number == null) {
                $obj->add();
                $msg = 'ssbhesabfa - Invoice successfully added. Invoice number: ' . $response->Result->Number;
                PrestaShopLogger::addLog($msg, 1, null, 'Order', $id_order, true);
            } else {
                $obj->id_ssb_hesabfa = $this->getObjectId('order', $id_order);
                $obj->update();
                $msg = 'ssbhesabfa - Invoice successfully updated. Invoice number: ' . $response->Result->Number;
                PrestaShopLogger::addLog($msg, 1, null, 'Order', $id_order, true);
            }

            return true;
        } else {
            $msg = 'ssbhesabfa - Cannot add/update Invoice. Error Message: ' . $response->ErrorMessage;
            PrestaShopLogger::addLog($msg, 2, $response->ErrorCode, 'Order', $id_order, true);
            return false;
        }
    }

    public function getOrderPriceInHesabfaDefaultCurrency($price, $id_order)
    {
        if (!isset($price) || !isset($id_order)) {
            return false;
        }

        $order = new Order($id_order);
        $price = $price / (int)$order->conversion_rate;
        $price = $this->getPriceInHesabfaDefaultCurrency($price);

        return $price;
    }

    public static function getPriceInHesabfaDefaultCurrency($price)
    {
        if (!isset($price)) {
            return false;
        }

        $currency = new Currency(Configuration::get('SSBHESABFA_HESABFA_DEFAULT_CURRENCY'));
        $price = $price / (int)$currency->conversion_rate;
        return $price;
    }

    public function setOrderPayment($id_order)
    {
        if (!isset($id_order)) {
            return false;
        }

        $hesabfa = new HesabfaApi();
        $number = $this->getInvoiceCodeByOrderId((int)$id_order);

        $payments = OrderPayment::getByOrderId($id_order);
        foreach ($payments as $payment) {
            //Skip free order payment
            if ($payment->amount <= 0) {
                return true;
            }


            $bank_code = $this->getBankCodeByPaymentName($payment->payment_method);
            if ($bank_code != false) {
                //fix Hesabfa API error
                if ($payment->transaction_id == '') {
                    $payment->transaction_id = 'None';
                }

                $response = $hesabfa->invoiceSavePayment($number, $bank_code, $payment->date_add, $payment->amount, $payment->transaction_id, $payment->card_number);

                if ($response->Success) {
                    $msg = 'ssbhesabfa - Hesabfa invoice payment added.';
                    PrestaShopLogger::addLog($msg, 1, null, 'Order', $id_order, true);
                } else {
                    $msg = 'ssbhesabfa - Cannot add Hesabfa Invoice payment. Error Message: ' . $response->ErrorMessage;
                    PrestaShopLogger::addLog($msg, 2, $response->ErrorCode, 'Order', $id_order, true);
                }
            } else {
                $msg = 'ssbhesabfa - Cannot add Hesabfa Invoice payment - Bank Code not define.';
                PrestaShopLogger::addLog($msg, 2, null, 'Order', $id_order, true);
            }
        }
    }

    public function getBankCodeByPaymentName($paymentName)
    {
        $sql = 'SELECT `module` FROM `' . _DB_PREFIX_ . 'orders` 
                WHERE `payment` = \''. $paymentName .'\'
        ';
        $result = Db::getInstance()->ExecuteS($sql);

        $modules_list = Module::getPaymentModules();
        if (isset($result[0])) {
            foreach ($modules_list as $module) {
                $module_obj = Module::getInstanceById($module['id_module']);
                if ($module_obj->name == $result[0]['module']) {
                    $configurationName = 'SSBHESABFA_PAYMENT_METHOD_' . $module['id_module'];
                }
            }
            return Configuration::get($configurationName);
        } else {
            return false;
        }
    }

    //Export
    public function exportProducts($setQuantity = 0)
    {
        $products = Product::getProducts($this->context->language->id, 1, 0, 'name', 'ASC', false, true);
        foreach ($products as $key) {
            $this->setItem($key['id_product'], $setQuantity);
        }
    }

    public function exportCustomers()
    {
        $customers = Customer::getCustomers();
        foreach ($customers as $customer) {
            $this->setContact($customer['id_customer']);
        }
    }

    public function syncOrders($from_date)
    {
        if (!isset($from_date)) {
            return false;
        }
        $orders = Order::getOrdersIdByDate($from_date, date('Y-m-d h:i:s'));
        $id_orders = array();
        foreach ($orders as $id_order) {
            $id_obj = $this->getObjectId('order', $id_order);
            if (!$id_obj) {
                if ($this->setOrder($id_order)) {
                    $this->setOrderPayment($id_order);
                    array_push($id_orders, $id_order);
                }
            }
        }

        return $id_orders;
    }

    //Hooks
    public function hookBackOfficeHeader()
    {
        if (Tools::getValue('module_name') == $this->name) {
            $this->context->controller->addJqueryUI('ui.datepicker');
        }
    }

    //Contact
    public function hookActionObjectCustomerAddAfter($params)
    {
        if (Configuration::get('SSBHESABFA_LIVE_MODE')) {
            $this->setContact($params['object']->id);
        }
    }

    public function hookActionCustomerAccountUpdate($params)
    {
        if (Configuration::get('SSBHESABFA_LIVE_MODE')) {
            $this->setContact($params['customer']->id);
        }
    }

    public function hookActionObjectCustomerDeleteBefore($params)
    {
        $hesabfa = new HesabfaModel($this->getObjectId('customer', $params['customer']->id));
        $hesabfa->delete();
    }

    public function hookActionObjectAddressAddAfter($params)
    {
        if (Address::getFirstCustomerAddressId($params['object']->id_customer) == 0 && Configuration::get('SSBHESABFA_LIVE_MODE')) {
            $this->setContactAddress($params['object']->id_customer, $params['object']->id);
        }
    }

    //Invoice
    public function hookActionValidateOrder($params)
    {
        if (Configuration::get('SSBHESABFA_LIVE_MODE')) {
            $this->setOrder((int)$params['order']->id);
        }
    }

    public function hookActionPaymentConfirmation($params)
    {
        if (Configuration::get('SSBHESABFA_LIVE_MODE')) {
            $this->setOrderPayment($params['id_order']);
        }
    }

    //Item
    public function hookActionProductAdd($params)
    {
        if (Configuration::get('SSBHESABFA_LIVE_MODE')) {
            $this->setItem($params['product']->id);
        }
    }

    public function hookActionProductUpdate($params)
    {
        if (Configuration::get('SSBHESABFA_LIVE_MODE')) {
            $this->hookActionProductAdd($params);
        }
    }

    public function hookActionProductDelete($params)
    {
        $hesabfa = new HesabfaModel($this->getObjectId('product', $params['product']->id));
        $hesabfa->delete();
    }
}
