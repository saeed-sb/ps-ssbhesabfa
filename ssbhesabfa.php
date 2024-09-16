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

include(dirname(__FILE__) . '/classes/HesabfaAPI.php');
include(dirname(__FILE__) . '/classes/HesabfaModel.php');

class Ssbhesabfa extends Module
{
    protected $config_form = false;
    public $id_default_lang;

    private $configurations = array(
        'SSBHESABFA_LIVE_MODE' => 0,
        'SSBHESABFA_DEBUG_MODE' => 0,
        'SSBHESABFA_ACCOUNT_USERNAME' => null,
        'SSBHESABFA_ACCOUNT_PASSWORD' => null,
        'SSBHESABFA_ACCOUNT_API' => null,
        'SSBHESABFA_ACCOUNT_TOKEN' => null,
        'SSBHESABFA_WEBHOOK_PASSWORD' => null,
        'SSBHESABFA_CONTACT_ADDRESS_STATUS' => 1,
        'SSBHESABFA_CONTACT_NODE_FAMILY' => 'Online Store Customer\'s',
        'SSBHESABFA_ITEM_GIFT_WRAPPING_ID' => 0,
        'SSBHESABFA_ITEM_BARCODE' => 2,
        'SSBHESABFA_ITEM_UPDATE_PRICE' => 0,
        'SSBHESABFA_ITEM_UPDATE_QUANTITY' => 0,
        'SSBHESABFA_LAST_LOG_CHECK_ID' => 0,
        'SSBHESABFA_INVOICE_RETURN_STATUE' => 6,
        'SSBHESABFA_INVOICE_REFERENCE_TYPE' => 1,
    );

    public function __construct()
    {
        $this->name = 'ssbhesabfa';
        $this->tab = 'billing_invoicing';
        $this->version = '1.0.2';
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
        $this->id_default_lang = Configuration::get('PS_LANG_DEFAULT');
    }

    public function install()
    {
        include(dirname(__FILE__).'/sql/install.php');

        foreach ($this->configurations as $key => $val) {
            if (!Configuration::updateValue($key, $val)) {
                return false;
            }
        }
        Configuration::updateValue('SSBHESABFA_WEBHOOK_PASSWORD', bin2hex(openssl_random_pseudo_bytes(16)));

        return parent::install() &&
            $this->registerHook('backOfficeHeader') &&
            $this->registerHook('displayAdminProductsExtra') &&

            $this->registerHook('actionObjectCustomerAddAfter') &&
            $this->registerHook('actionCustomerAccountUpdate') &&
            $this->registerHook('actionObjectCustomerDeleteBefore') &&
            $this->registerHook('actionObjectAddressAddAfter') &&

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
        include(dirname(__FILE__).'/sql/uninstall.php');

        $sql = "SELECT `name` FROM `" . _DB_PREFIX_ . "configuration`
                WHERE `name` LIKE '%SSBHESABFA_%'";
        $configurations = Db::getInstance()->ExecuteS($sql);

        foreach ($configurations as $configuration) {
            Configuration::deleteByName($configuration['name']);
        }

        return parent::uninstall();
    }

    public function getContent()
    {
        if (!extension_loaded('curl')) {
            return $this->displayError($this->l('cURL is not enabled. You should enable it before using thes module.'));
        }

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
        } elseif (((bool)Tools::isSubmit('submitSsbhesabfaModuleInvoice')) == true) {
            $this->setConfigFormsValues('Invoice');
            $output .= $this->displayConfirmation($this->l('Invoice Setting updated.'));
        } elseif (((bool)Tools::isSubmit('submitSsbhesabfaExportProducts')) == true) {
            if (Configuration::get('SSBHESABFA_LIVE_MODE')) {
                $exportProducts = $this->exportProducts();
                if ($exportProducts) {
                    $output .= $this->displayConfirmation($this->l('Products exported to Hesabfa successfully.'));
                } else {
                    $output .= $this->displayError($exportProducts);
                }
            } else {
                $output .= $this->displayWarning($this->l('The API Connection must be connected before export Products.'));
            }
        } elseif (((bool)Tools::isSubmit('submitSsbhesabfaSetOpeningQuantity')) == true) {
            if (Configuration::get('SSBHESABFA_LIVE_MODE')) {
                $setOpeningQuantity = $this->setOpeningQuantity();
                if ($setOpeningQuantity) {
                    $output .= $this->displayConfirmation($this->l('Products Opening Quantity exported to Hesabfa successfully.'));
                } else {
                    $output .= $this->displayError($setOpeningQuantity);
                }
            } else {
                $output .= $this->displayWarning($this->l('The API Connection must be connected before export Products.'));
            }
        } elseif (((bool)Tools::isSubmit('submitSsbhesabfaExportCustomers')) == true) {
            if (Configuration::get('SSBHESABFA_LIVE_MODE')) {
                $exportCustomer = $this->exportCustomers();
                if ($exportCustomer) {
                    $output .= $this->displayConfirmation($this->l('Customers exported to Hesabfa successfully.'));
                } else {
                    $output .= $this->displayError($exportCustomer);
                }
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
                    $orders_id = $this->syncOrders($from_date);
                    if (is_array($orders_id) && empty($orders_id)) {
                        $output .= $this->displayConfirmation($this->l('No orders synced.'));
                    } elseif (is_array($orders_id) && !empty($orders_id)) {
                        $output .= $this->displayConfirmation($this->l('Orders synced with Hesabfa successfully. Orders ID: ') . implode(' - ', $orders_id));
                    } elseif ($orders_id != false) {
                        $output .= $this->displayError($orders_id);
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
        } elseif (((bool)Tools::isSubmit('submitSsbhesabfaSyncProducts')) == true) {
            if (Configuration::get('SSBHESABFA_LIVE_MODE')) {
                $this->syncProducts();
                $output .= $this->displayConfirmation($this->l('Products synced with Hesabfa successfully.'));
            } else {
                $output .= $this->displayWarning($this->l('The API Connection must be connected before sync Products.'));
            }
        }

        //assign smarty vars
        $forms = array('Bank', 'Config', 'Item', 'Contact', 'Invoice');
        foreach ($forms as $form) {
            $html = $this->renderForm($form);
            $this->context->smarty->assign($form, $html);
        }

        $this->context->smarty->assign(array(
            'current_form_tab' => Tools::getValue('form_tab'),
            'export_action_url' => './index.php?tab=AdminModules&configure=ssbhesabfa&token=' . Tools::getAdminTokenLite('AdminModules') . '&tab_module=' . $this->tab . '&module_name=ssbhesabfa&form_tab=Export',
            'sync_action_url' => './index.php?tab=AdminModules&configure=ssbhesabfa&token=' . Tools::getAdminTokenLite('AdminModules') . '&tab_module=' . $this->tab . '&module_name=ssbhesabfa&form_tab=Sync',
            'update_action_url' => './index.php?tab=AdminModules&configure=ssbhesabfa&token=' . Tools::getAdminTokenLite('AdminModules') . '&tab_module=' . $this->tab . '&module_name=ssbhesabfa&form_tab=Home',
            'live_mode' => Configuration::get('SSBHESABFA_LIVE_MODE'),
            'module_ver' => $this->version,
        ));

        //Show error when connection not stabilised
        if (Configuration::get('SSBHESABFA_LIVE_MODE') != 1) {
            $output .= $this->displayError($this->l('Connecting to Hesabfa fail. Please open the API tab and check your API Settings.'));
        }

        if (Configuration::get('SSBHESABFA_LIVE_MODE') == 1) {
            //Show error when current date not in Fiscal year
            if (!$this->isDateInFiscalYear(date('Y-m-d H:i:s'))) {
                $output .= $this->displayError($this->l('The fiscal year has passed or not arrived. Please check the fiscal year settings in Hesabfa'));
                Configuration::updateValue('SSBHESABFA_LIVE_MODE', false);
            }

            //Show error when Banks not mapped
            $payment_methods = $this->getPaymentMethodsName();
            foreach ($payment_methods as $method) {
                if (!Configuration::get($method['id'])) {
                    $output .= $this->displayError($this->l('Payment methods are not mapped with Banks. Please check setting in Payment Methods tab.'));
                    break;
                }
            }
        }

        // To load form inside your template
        $output .= $this->context->smarty->fetch($this->local_path.'views/templates/admin/configure.tpl');

        // To return form html only
        return $output;
    }

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
                    array(
                        'col' => 6,
                        'type' => 'text',
                        'desc' => $this->l('Find Login Token in Setting->Financial Settings->API Menu'),
                        'name' => 'SSBHESABFA_ACCOUNT_TOKEN',
                        'label' => $this->l('Login Token'),
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

        $bank_options = array(array(
                'id_option' => -1,
                'name' => $this->l('No need to set!'),
            ));
        $hesabfaApi = new HesabfaApi();
        $banks = $hesabfaApi->settingGetBanks();

        if (is_object($banks) && $banks->Success) {
            foreach ($banks->Result as $bank) {
                //show only bank with default currency in hesabfa
                $default_currency = new Currency(Configuration::get('SSBHESABFA_HESABFA_DEFAULT_CURRENCY'));
                if ($bank->Currency == $default_currency->iso_code) {
                    array_push($bank_options, array(
                        'id_option' => $bank->Code,
                        'name' => $bank->Name . ' - ' . $bank->Branch . ' - ' . $bank->AccountNumber,
                    ));
                }
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

    protected function getInvoiceForm()
    {
        $options = array();
        $order_states = OrderState::getOrderStates(Context::getContext()->language->id);
        foreach ($order_states as $order_state) {
            array_push($options, array(
                'id_option' => $order_state['id_order_state'],
                'name' => $order_state['name'],
            ));
        }

        $options2 = array();
        $hesabfaApi = new HesabfaApi();
        $salesmen_list = $hesabfaApi->settingGetSalesmen();

        if ($salesmen_list->Success) {
            foreach ($salesmen_list->Result as $salesmen) {
                array_push($options2, array(
                    'id_option' => $salesmen->Code,
                    'name' => $salesmen->Name,
                ));
            }
        }

        return array(
            'form' => array(
                'input' => array(
                    array(
                        'type' => 'select',
                        'label' => $this->l('Invoice reference'),
                        'desc' => $this->l('Choose invoice reference source'),
                        'name' => 'SSBHESABFA_INVOICE_REFERENCE_TYPE',
                        'options' => array(
                            'query' => array(
                                array(
                                    'id_option' => 0,
                                    'name' => $this->l('Order ID'),
                                ),
                                array(
                                    'id_option' => 1,
                                    'name' => $this->l('Order Reference'),
                                ),
                            ),
                            'id' => 'id_option',
                            'name' => 'name'
                        )
                    ),
                    array (
                        'type' => 'select',
                        'name' => 'SSBHESABFA_INVOICE_RETURN_STATUE',
                        'label' => $this->l('Return sale invoice status'),
                        'desc' => $this->l('In what custom status should the return invoice be issued?'),
                        'options' => array(
                            'query' => $options,
                            'id' => 'id_option',
                            'name' => 'name'
                        )
                    ),

                    array (
                        'type' => 'select',
                        'name' => 'SSBHESABFA_INVOICE_SALESMEN',
                        'label' => $this->l('OnlineStore salesman code'),
                        'options' => array(
                            'query' => $options2,
                            'id' => 'id_option',
                            'name' => 'name'
                        )
                    ),
                ),
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
                        'type' => 'select',
                        'label' => $this->l('Barcode:'),
                        'desc' => $this->l('Choose which data field selected for Barcode'),
                        'name' => 'SSBHESABFA_ITEM_BARCODE',
                        'options' => array(
                            'query' => array(
                                array(
                                    'id_option' => 1,
                                    'name' => $this->l('Reference'),
                                ),
                                array(
                                    'id_option' => 2,
                                    'name' => $this->l('UPC barcode'),
                                ),
                                array(
                                    'id_option' => 3,
                                    'name' => $this->l('EAN-13 or JAN barcode'),
                                ),
                                array(
                                    'id_option' => 4,
                                    'name' => $this->l('ISBN'),
                                ),
                            ),
                            'id' => 'id_option',
                            'name' => 'name'
                        )
                    ),
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

    //Configuration form Values
    protected function getConfigFormValues($form = null)
    {
        switch ($form) {
            case 'Config':
                $keys =  array(
                    'SSBHESABFA_ACCOUNT_USERNAME' => Configuration::get('SSBHESABFA_ACCOUNT_USERNAME'),
                    'SSBHESABFA_ACCOUNT_PASSWORD' => Configuration::get('SSBHESABFA_ACCOUNT_PASSWORD'),
                    'SSBHESABFA_ACCOUNT_API' => Configuration::get('SSBHESABFA_ACCOUNT_API'),
                    'SSBHESABFA_ACCOUNT_TOKEN' => Configuration::get('SSBHESABFA_ACCOUNT_TOKEN'),
                );
                break;
            case 'Item':
                $keys =  array(
                    'SSBHESABFA_ITEM_BARCODE' => Configuration::get('SSBHESABFA_ITEM_BARCODE'),
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
            case 'Invoice':
                $keys =  array(
                    'SSBHESABFA_INVOICE_RETURN_STATUE' => Configuration::get('SSBHESABFA_INVOICE_RETURN_STATUE'),
                    'SSBHESABFA_INVOICE_REFERENCE_TYPE' => Configuration::get('SSBHESABFA_INVOICE_REFERENCE_TYPE'),
                    'SSBHESABFA_INVOICE_SALESMEN' => Configuration::get('SSBHESABFA_INVOICE_SALESMEN'),
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
                    'SSBHESABFA_ACCOUNT_TOKEN' => Configuration::get('SSBHESABFA_ACCOUNT_TOKEN'),

                    'SSBHESABFA_ITEM_BARCODE' => Configuration::get('SSBHESABFA_ITEM_BARCODE'),
                    'SSBHESABFA_ITEM_UPDATE_PRICE' => Configuration::get('SSBHESABFA_ITEM_UPDATE_PRICE'),
                    'SSBHESABFA_ITEM_UPDATE_QUANTITY' => Configuration::get('SSBHESABFA_ITEM_UPDATE_QUANTITY'),

                    'SSBHESABFA_CONTACT_ADDRESS_STATUS' => Configuration::get('SSBHESABFA_CONTACT_ADDRESS_STATUS'),
                    'SSBHESABFA_CONTACT_NODE_FAMILY' => Configuration::get('SSBHESABFA_CONTACT_NODE_FAMILY'),

                    'SSBHESABFA_INVOICE_RETURN_STATUE' => Configuration::get('SSBHESABFA_INVOICE_RETURN_STATUE'),
                    'SSBHESABFA_INVOICE_REFERENCE_TYPE' => Configuration::get('SSBHESABFA_INVOICE_REFERENCE_TYPE'),
                    'SSBHESABFA_INVOICE_SALESMEN' => Configuration::get('SSBHESABFA_INVOICE_SALESMEN'),
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
            $control1 = $key == 'SSBHESABFA_ACCOUNT_PASSWORD' && Tools::getValue($key) == null;
            //don't add bank map if bank is not define in hesabfa
            $control2 = strpos($key, 'SSBHESABFA_PAYMENT_METHOD_') !== false && Tools::getValue($key) == 0;

            if (!($control1 || $control2)){
                Configuration::updateValue($key, Tools::getValue($key));
            }
        }
    }

    //Functions
    public static function getObjectId($type, $id_ps, $id_ps_attribute = 0)
    {
        if (!isset($type) || !isset($id_ps)) {
            return false;
        }

        $sql = 'SELECT `id_ssb_hesabfa` 
                    FROM `' . _DB_PREFIX_ . 'ssb_hesabfa`
                    WHERE `id_ps` = '. $id_ps .' AND `id_ps_attribute` = \''. $id_ps_attribute .'\' AND `obj_type` = \''. $type .'\'
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

        //ToDo: Compatible with PrestaPay plugins
//        if (Module::isInstalled('psf_prestapay')) {
//            $prestapay = Module::getInstanceByName('psf_prestapay');
//
//            /* Check if the module is enabled */
//            if ($prestapay->active) {
//                $psf_prestapay = new psf_prestapay();
//                $plugins = $psf_prestapay->getModulePlugins(1);
//                foreach ($plugins as $plugin) {
//                    $plugin;
//                }
//            }
//        }

        return $payment_array;
    }

    public function setChangeHook()
    {
        $store_url = $this->context->link->getBaseLink();
        $url = $store_url . 'modules/ssbhesabfa/ssbhesabfa-webhook.php?token=' . Tools::substr(Tools::encrypt('ssbhesabfa/webhook'), 0, 10);
        $hookPassword = Configuration::get('SSBHESABFA_WEBHOOK_PASSWORD');

        $hesabfa = new HesabfaApi();
        $response = $hesabfa->settingSetChangeHook($url, $hookPassword);

        //ToDo: implement with try and catch
        if (is_object($response)) {
            if ($response->Success) {
                Configuration::updateValue('SSBHESABFA_LIVE_MODE', 1);

                //set the last log ID
                $lastChange = Configuration::get('SSBHESABFA_LAST_LOG_CHECK_ID');
                $changes = $hesabfa->settingGetChanges($lastChange);
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
                    } elseif (_PS_VERSION_ > 1.7) {
                        $currency = new Currency();
                        $currency->iso_code = $default_currency->Result->Currency;

                        if ($currency->add()) {
                            Configuration::updateValue('SSBHESABFA_HESABFA_DEFAULT_CURRENCY', $currency->id);

                            $msg = 'ssbhesabfa - Hesabfa default currency('. $default_currency->Result->Currency .') added to Online Store';
                            PrestaShopLogger::addLog($msg, 1, null, null, null, true);
                        }
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
                        'Tag' => json_encode(array('id_product' => 0, 'id_attribute' => 0)),
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

    public function isDateInFiscalYear($date)
    {
        $hesabfaApi = new HesabfaApi();
        $fiscalYear = $hesabfaApi->settingGetFiscalYear();

        if ($fiscalYear->Success) {
            $fiscalYearStartTimeStamp = strtotime($fiscalYear->Result->StartDate);
            $fiscalYearEndTimeStamp = strtotime($fiscalYear->Result->EndDate);
            $dateTimeStamp = strtotime($date);

            if ($dateTimeStamp >= $fiscalYearStartTimeStamp && $dateTimeStamp <= $fiscalYearEndTimeStamp) {
                return true;
            }
            return false;
        }
        return false;
    }

    //Items
    public function setItems($id_product_array)
    {
        //ToDo: why key 0 must be null?????
        if (!isset($id_product_array) || $id_product_array[0] == null) {
            return false;
        }

        if (is_array($id_product_array) && empty($id_product_array)) {
            return true;
        }

        $items = array();
        foreach ($id_product_array as $id_product) {
            $product = new Product($id_product);
            $itemType = ($product->is_virtual == 1 ? 1 : 0);

            //add base product
            $code = $this->getItemCodeByProductId($id_product, 0);
            $item = array(
                'Code' => $code,
                'Name' => mb_substr($product->name[$this->id_default_lang], 0, 99),
                'ItemType' => $itemType,
                'Barcode' => $this->getBarcode($id_product),
                'SellPrice' => $product->price * 10,
                'Tag' => json_encode(array('id_product' => $id_product, 'id_attribute' => 0)),
                'Active' => $product->active ? true : false,
                'NodeFamily' => $this->getCategoryPath($product->id_category_default),
                'ProductCode' => $id_product,
            );

            if (!Configuration::get('SSBHESABFA_ITEM_UPDATE_PRICE')) {
                $item['SellPrice'] = $this->getPriceInHesabfaDefaultCurrency($product->price);
            }

            $items[] = $item;

            if ($product->hasAttributes() > 0) {
                //Combinations
                $combinations = $product->getAttributesResume($this->id_default_lang);
                foreach ($combinations as $combination) {
                    $code = $this->getItemCodeByProductId($id_product, $combination['id_product_attribute']);
                    $item = array(
                        'Code' => $code,
                        'Name' => mb_substr($product->name[$this->id_default_lang].' - '. $combination['attribute_designation'], 0, 99),
                        'ItemType' => $itemType,
                        'Barcode' => $this->getBarcode($id_product, $combination['id_product_attribute']),
                        'Tag' => json_encode(array('id_product' => $id_product, 'id_attribute' => $combination['id_product_attribute'])),
                        'Active' => $product->active ? true : false,
                        'NodeFamily' => $this->getCategoryPath($product->id_category_default),
                        'ProductCode' => $id_product,
                    );

                    if (!Configuration::get('SSBHESABFA_ITEM_UPDATE_PRICE')) {
                        $item['SellPrice'] = $this->getPriceInHesabfaDefaultCurrency($product->price + $combination['price']);
                    }

                    $items[] = $item;
                }
            }
        }

        if (!$this->saveItems($items)) {
            return false;
        }
        return true;
    }

    private function saveItems($items)
    {
        $hesabfa = new HesabfaApi();
        $response = $hesabfa->itemBatchSave($items);
        if ($response->Success) {
            foreach ($response->Result as $item) {
                $json = json_decode($item->Tag);
                $id_ssb_hesabfa = $this->getObjectId('product', (int)$json->id_product, (int)$json->id_attribute);

                if ($id_ssb_hesabfa == 0) {
                    $obj = new HesabfaModel();
                    $obj->id_hesabfa = (int)$item->Code;
                    $obj->obj_type = 'product';
                    $obj->id_ps = (int)$json->id_product;
                    $obj->id_ps_attribute = (int)$json->id_attribute;

                    $obj->add();
                    $msg = 'ssbhesabfa - Item successfully added. Item Code: ' . $item->Code;
                    PrestaShopLogger::addLog($msg, 1, null, 'Product', $json->id_product, true);
                } else {
                    $obj = new HesabfaModel($id_ssb_hesabfa);
                    $obj->id_hesabfa = (int)$item->Code;
                    $obj->obj_type = 'product';
                    $obj->id_ps = (int)$json->id_product;
                    $obj->id_ps_attribute = (int)$json->id_attribute;

                    $obj->update();
                    $msg = 'ssbhesabfa - Item successfully updated. Item Code: ' . $item->Code;
                    PrestaShopLogger::addLog($msg, 1, null, 'Product', $json->id_product, true);
                }
            }
            return true;
        } else {
            $msg = 'ssbhesabfa - Cannot add/update Hesabfa items. Error Message: ' . $response->ErrorMessage;
            PrestaShopLogger::addLog($msg, 2, $response->ErrorCode, 'Products', null, true);
        }

        return false;
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

    private function getBarcode($id_product, $id_attribute = 0)
    {
        if (!isset($id_product)) {
            return false;
        }

        $product = new Product($id_product);

        if ($id_attribute == 0) {
            switch (Configuration::get('SSBHESABFA_ITEM_BARCODE')) {
                case 1:
                    return $product->reference;
                case 2:
                    return $product->upc;
                case 3:
                    return $product->ean13;
                case 4:
                    return $product->isbn;
            }
        } else {
            $product_attribute = $product->getAttributeCombinationsById($id_attribute, $this->id_default_lang);
            switch (Configuration::get('SSBHESABFA_ITEM_BARCODE')) {
                case 1:
                    return $product_attribute[0]['reference'];
                case 2:
                    return $product_attribute[0]['upc'];
                case 3:
                    return $product_attribute[0]['ean13'];
                case 4:
                    return $product_attribute[0]['isbn'];
            }
        }

        return false;
    }

    public function getItemCodeByProductId($id_product, $id_attribute = 0)
    {
        $obj_id = $this->getObjectId('product', $id_product, $id_attribute);
        if ($obj_id > 0) {
            $obj = new HesabfaModel($obj_id);
            if (is_object($obj)) {
                return $obj->id_hesabfa;
            }
        }
        return null;
    }

    public static function getProductAttributesObjectId($id_ps)
    {
        if (!isset($id_ps)) {
            return false;
        }

        $sql = 'SELECT `id_ssb_hesabfa` 
                    FROM `' . _DB_PREFIX_ . 'ssb_hesabfa`
                    WHERE `id_ps` = '. $id_ps .'
                    ';
        $results = Db::getInstance()->executeS($sql);

        $obj_ids = array();
        foreach ($results as $item) {
            $obj_ids[] = $item['id_ssb_hesabfa'];
        }

        return $obj_ids;
    }

    //Contact
    public function getContactCodeByCustomerId($id_customer)
    {
        if (!isset($id_customer)) {
            return false;
        }

        $obj_id = $this->getObjectId('customer', $id_customer);
        if ($obj_id > 0) {
            $obj = new HesabfaModel($obj_id);
            return $obj->id_hesabfa;
        }

        return false;
    }

    public function setContact($id_customer)
    {
        if (!isset($id_customer)) {
            return false;
        }

        $code = null;
        $tmp = $this->getContactCodeByCustomerId($id_customer);
        if ($tmp != false) {
            $code = $tmp;
        }

        $customer = new Customer($id_customer);

        //check if customer name is null
        $name = $customer->firstname . ' ' . $customer->lastname;
        if (empty($customer->firstname) && empty($customer->lastname)) {
            $name = 'Guest Customer';
        }

        $data = array (
            array(
                'Code' => $code,
                'Name' => $name,
                'FirstName' => $customer->firstname,
                'LastName' => $customer->lastname,
                'ContactType' => 1,
                'NodeFamily' => 'اشخاص :' . Configuration::get('SSBHESABFA_CONTACT_NODE_FAMILY'),
                'Email' => $this->validEmail($customer->email) ? $customer->email : null,
                'Tag' => json_encode(array('id_customer' => $id_customer)),
                'Active' => $customer->active ? true : false,
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
            return $response->Result[0]->Code;
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

        $PostalCode = mb_substr(preg_replace("/[^0-9]/", '', $address->postcode), 0, 9);
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
                'PostalCode' => $PostalCode,
                'Phone' => preg_replace("/[^0-9]/", "", $address->phone),
                'Mobile' => preg_replace("/[^0-9]/", "", $address->phone_mobile),
                'Email' => $this->validEmail($customer->email) ? $customer->email : null,
                'Tag' => json_encode(array('id_customer' => $id_customer)),
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

    public function validEmail($email)
    {
        $isValid = true;
        $atIndex = strrpos($email, "@");
        if (is_bool($atIndex) && !$atIndex) {
            $isValid = false;
        } else {
            $domain = Tools::substr($email, $atIndex+1);
            $local = Tools::substr($email, 0, $atIndex);
            $localLen = Tools::strlen($local);
            $domainLen = Tools::strlen($domain);
            if ($localLen < 1 || $localLen > 64) {
                // local part length exceeded
                $isValid = false;
            } else if ($domainLen < 1 || $domainLen > 255) {
                // domain part length exceeded
                $isValid = false;
            } else if ($local[0] == '.' || $local[$localLen-1] == '.') {
                // local part starts or ends with '.'
                $isValid = false;
            } else if (preg_match('/\\.\\./', $local)) {
                // local part has two consecutive dots
                $isValid = false;
            } else if (!preg_match('/^[A-Za-z0-9\\-\\.]+$/', $domain)) {
                // character not valid in domain part
                $isValid = false;
            } else if (preg_match('/\\.\\./', $domain)) {
                // domain part has two consecutive dots
                $isValid = false;
            } else if (!preg_match('/^(\\\\.|[A-Za-z0-9!#%&`_=\\/$\'*+?^{}|~.-])+$/', str_replace("\\\\", "", $local))) {
                // character not valid in local part unless
                // local part is quoted
                if (!preg_match('/^"(\\\\"|[^"])+"$/', str_replace("\\\\", "", $local))) {
                    $isValid = false;
                }
            }
//            if ($isValid && !(checkdnsrr($domain,"MX") || checkdnsrr($domain,"A")))
//            {
//                // domain not found in DNS
//                $isValid = false;
//            }
        }
        return $isValid;
    }

    //Invoice
    public function setOrder($id_order, $orderType = 0, $reference = null, $serials = null)
    {
        if (!isset($id_order)) {
            return false;
        }

        $number = $this->getInvoiceCodeByOrderId($id_order);

        //return if saleInvoice not set before
        if ($number == null && $orderType == 2) {
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
        //ToDo: if customer define with export function, then need to set address
        if (Configuration::get('SSBHESABFA_CONTACT_ADDRESS_STATUS') == 2) {
            $this->setContactAddress($order->id_customer, $order->id_address_invoice);
        } elseif (Configuration::get('SSBHESABFA_CONTACT_ADDRESS_STATUS') == 3) {
            $this->setContactAddress($order->id_customer, $order->id_address_delivery);
        }

        // add product before insert invoice
        $items = array();
        $products = $order->getProducts();
        foreach ($products as $product) {
            $code = $this->getItemCodeByProductId($product['product_id'], $product['product_attribute_id']);
            if ($code == null) {
                $items[] = $product['product_id'];
            }
        }
        if (!empty($items)) {
            if (!$this->setItems($items)) {
                return false;
            }
        }

        //skip free shipping discount amount
        $order_total_discount = $this->getOrderPriceInHesabfaDefaultCurrency($order->total_discounts, $id_order);
        $shipping = $this->getOrderPriceInHesabfaDefaultCurrency($order->total_shipping_tax_incl, $id_order);

        $sql = 'SELECT `free_shipping` 
                    FROM `' . _DB_PREFIX_ . 'order_cart_rule`
                    WHERE `id_order` = '. $id_order;
        $result = Db::getInstance()->executeS($sql);

        foreach ($result as $item) {
            if ($item['free_shipping']) {
                $order_total_discount = $this->getOrderPriceInHesabfaDefaultCurrency($order->total_discounts - $order->total_shipping_tax_incl, $id_order);
                $shipping = 0;
            }
        }

        //calculate discount split
        $order_total_products = $this->getOrderPriceInHesabfaDefaultCurrency($order->total_products, $id_order);
        $split = 0;
        if ($order_total_discount > 0) {
            $split = $order_total_discount / $order_total_products;
        }

        //Splitting total discount to each item
        $i = 0;
        $total_discounts = 0;
        foreach ($products as $key => $product) {
            $code = $this->getItemCodeByProductId($product['product_id'], $product['product_attribute_id']);

            //fix remaining discount amount on last item
            $array_key = array_keys($products);
            $product_price = $this->getOrderPriceInHesabfaDefaultCurrency($product['original_product_price'], $id_order);

            if (end($array_key) == $key) {
                $discount = $order_total_discount - $total_discounts;
            } else {
                $discount = ($product_price * $split * $product['product_quantity']);
                $total_discounts += $discount;
            }

            $reduction_amount = $this->getOrderPriceInHesabfaDefaultCurrency($product['original_product_price'] - $product['product_price'], $id_order);
            $discount += $reduction_amount * $product['product_quantity'];

            //fix if total discount greater than product price
            if ($discount > $product_price * $product['product_quantity']) {
                $discount = $product_price * $product['product_quantity'];
            }

            if ($discount < 0) {
                $discount = 0;
            }

            $item = array (
                'RowNumber' => $i,
                'ItemCode' => (int)$code,
                'Description' => mb_substr($product['product_name'], 0, 249),
                'Quantity' => (int)$product['product_quantity'],
                'UnitPrice' => (float)$product_price,
                'Discount' => (float)$discount,
                'Tax' => (float)$this->getOrderPriceInHesabfaDefaultCurrency(($product['unit_price_tax_incl'] - $product['unit_price_tax_excl']), $id_order),
            );

            //compatibility with ssborderserial module
            if (!empty($serials)) {
                foreach ($serials as $serial) {
                    if ($serial['product_id'] == $product['product_id']) {
                        $item['serialNumbers'] = array($serial['serial_number']);
                    }
                }
            }
            //end with ssborderserial module

            $items[] = $item;
            $i++;
        }

        if ($order->total_wrapping_tax_excl > 0) {
            $items[] = array(
                'RowNumber' => $i + 1,
                'ItemCode' => Configuration::get('SSBHESABFA_ITEM_GIFT_WRAPPING_ID'),
                'Description' => $this->l('Gift wrapping Service'),
                'Quantity' => 1,
                'UnitPrice' => $this->getOrderPriceInHesabfaDefaultCurrency(($order->total_wrapping), $id_order),
                'Discount' => 0,
                'Tax' => $this->getOrderPriceInHesabfaDefaultCurrency(($order->total_wrapping_tax_incl - $order->total_wrapping_tax_excl), $id_order),
            );
        }

        switch ($orderType) {
            case 0:
                $date = $order->date_add;
                break;
            case 2:
                $date = $order->date_upd;
                break;
            default:
                $date = $order->date_add;
        }

        if ($reference === null) {
            $reference = Configuration::get('SSBHESABFA_INVOICE_REFERENCE_TYPE') ? $order->reference : $id_order;
        }

        $data = array (
            'Number' => $number,
            'InvoiceType' => $orderType,
            'ContactCode' => $this->getContactCodeByCustomerId($order->id_customer),
            'Date' => $date,
            'DueDate' => $date,
            'Reference' => $reference,
            'Status' => 2,
            'Tag' => json_encode(array('id_order' => $id_order)),
            'Freight' => $shipping,
            'SalesmanCode' => null,
            'InvoiceItems' => $items,
        );

        $salesmanCode = Configuration::get('SSBHESABFA_INVOICE_SALESMEN');
        if ($salesmanCode != false) {
            $data['SalesmanCode'] = $salesmanCode;
        }

        $hesabfa = new HesabfaApi();
        $response = $hesabfa->invoiceSave($data);
        if ($response->Success) {
            $obj = new HesabfaModel();
            $obj->id_hesabfa = (int)$response->Result->Number;

            switch ($orderType) {
                case 0:
                    $obj->obj_type = 'order';
                    break;
                case 2:
                    $obj->obj_type = 'returnOrder';
                    break;
            }

            $obj->id_ps = $id_order;
            if ($number == null) {
                $obj->add();
                switch ($orderType) {
                    case 0:
                        $msg = 'ssbhesabfa - Invoice successfully added. Invoice number: ' . $response->Result->Number;
                        PrestaShopLogger::addLog($msg, 1, null, 'Order', $id_order, true);
                        break;
                    case 2:
                        $msg = 'ssbhesabfa - Return sale Invoice successfully added. Invoice number: ' . $response->Result->Number;
                        PrestaShopLogger::addLog($msg, 1, null, 'ReturnOrder', $id_order, true);
                        break;
                }
            } else {
                switch ($orderType) {
                    case 0:
                        $obj->id_ssb_hesabfa = $this->getObjectId('order', $id_order);
                        $obj->update();
                        $msg = 'ssbhesabfa - Invoice successfully updated. Invoice number: ' . $response->Result->Number;
                        PrestaShopLogger::addLog($msg, 1, null, 'Order', $id_order, true);
                        break;
                    case 2:
                        $obj->id_ssb_hesabfa = $this->getObjectId('returnOrder', $id_order);
                        $obj->update();
                        $msg = 'ssbhesabfa - Return sale Invoice successfully updated. Invoice number: ' . $response->Result->Number;
                        PrestaShopLogger::addLog($msg, 1, null, 'ReturnOrder', $id_order, true);
                        break;
                }
            }

            return true;
        } else {
            $msg = 'ssbhesabfa - Cannot add/update Invoice. Error Message: ' . $response->ErrorMessage;
            PrestaShopLogger::addLog($msg, 2, $response->ErrorCode, 'Order', $id_order, true);
            return false;
        }
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
            if ($bank_code == -1) {
                return true;
            } elseif ($bank_code != false) {
                //fix Hesabfa API error
                if ($payment->transaction_id == '') {
                    $payment->transaction_id = 'None';
                }

                // Added for ZarinPal TransactionFee
                if ((int)$bank_code == 12) {
                    $transactionFee = $this->getOrderPriceInHesabfaDefaultCurrency($payment->amount, $id_order) * 0.025;
                    if ($transactionFee >= 100000) {
                        $transactionFee = 100000;
                    }
                } else {
                    $transactionFee = 0;
                }

                $response = $hesabfa->invoiceSavePayment($number, $bank_code, $payment->date_add, $this->getOrderPriceInHesabfaDefaultCurrency($payment->amount, $id_order), $payment->transaction_id, null, $transactionFee);

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

    public function getInvoiceCodeByOrderId($id_order)
    {
        $obj_id = $this->getObjectId('order', $id_order);
        if ($obj_id > 0) {
            $obj = new HesabfaModel($obj_id);
            return $obj->id_hesabfa;
        }

        return null;
    }

    public function getOrderPriceInHesabfaDefaultCurrency($price, $id_order)
    {
        if (!isset($price) || !isset($id_order)) {
            return false;
        }

        $order = new Order($id_order);
        $price = $price * (int)$order->conversion_rate;
        $price = $this->getPriceInHesabfaDefaultCurrency($price);

        return $price;
    }

    public static function getPriceInHesabfaDefaultCurrency($price)
    {
        if (!isset($price)) {
            return false;
        }

        $currency = new Currency(Configuration::get('SSBHESABFA_HESABFA_DEFAULT_CURRENCY'));
        $price *= $currency->conversion_rate;
        return $price;
    }

    public static function getPriceInPrestashopDefaultCurrency($price)
    {
        if (!isset($price)) {
            return false;
        }

        $currency = new Currency(Configuration::get('SSBHESABFA_HESABFA_DEFAULT_CURRENCY'));
        $price /= $currency->conversion_rate;
        return $price;
    }

    public function getBankCodeByPaymentName($paymentName)
    {
        $sql = 'SELECT `module` FROM `' . _DB_PREFIX_ . 'orders` 
                WHERE `payment` = \''. $paymentName .'\' ORDER BY `id_order` DESC
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
    public function exportProducts()
    {
        $products = Product::getProducts($this->context->language->id, 1, 0, 'id_product', 'ASC');
        $items = array();

        foreach ($products as $item) {
            //do if product not exists in hesabfa
            $id_product = $item['id_product'];
            $product = new Product($id_product);

            $id_obj = $this->getObjectId('product', $id_product, 0);
            if (!$id_obj) {
                array_push($items, array(
                    'Name' => mb_substr($product->name[$this->id_default_lang], 0, 99),
                    'ItemType' => ($product->is_virtual == 1 ? 1 : 0),
                    'Barcode' => $this->getBarcode($id_product),
                    'SellPrice' => $this->getPriceInHesabfaDefaultCurrency($product->price),
                    'Tag' => json_encode(array('id_product' => $id_product, 'id_attribute' => 0)),
                    'Active' => $product->active ? true : false,
                    'NodeFamily' => $this->getCategoryPath($product->id_category_default),
                    'ProductCode' => $id_product,
                ));
            }

            //add combinations
            if ($product->hasAttributes() > 0) {
                //Combinations
                $combinations = $product->getAttributesResume($this->id_default_lang);

                foreach ($combinations as $combination) {
                    $id_obj = $this->getObjectId('product', $id_product, $combination['id_product_attribute']);
                    if (!$id_obj) {
                        array_push($items, array(
                            'Name' => mb_substr($product->name[$this->id_default_lang] .' - '. $combination['attribute_designation'], 0, 99),
                            'ItemType' => ($product->is_virtual == 1 ? 1 : 0),
                            'Barcode' => $this->getBarcode($id_product, $combination['id_product_attribute']),
                            'SellPrice' => $this->getPriceInHesabfaDefaultCurrency($product->price + $combination['price']),
                            'Tag' => json_encode(array('id_product' => $id_product, 'id_attribute' => $combination['id_product_attribute'])),
                            'NodeFamily' => $this->getCategoryPath($product->id_category_default),
                            'ProductCode' => $id_product,
                        ));
                    }
                }
            }
        }

        if (!empty($items)) {
            $hesabfa = new HesabfaApi();
            $response = $hesabfa->itemBatchSave($items);
            if ($response->Success) {
                foreach ($response->Result as $item) {
                    $obj = new HesabfaModel();
                    $obj->id_hesabfa = (int)$item->Code;
                    $obj->obj_type = 'product';
                    $json = json_decode($item->Tag);
                    $obj->id_ps = (int)$json->id_product;
                    $obj->id_ps_attribute = (int)$json->id_attribute;
                    $obj->add();
                    $msg = 'ssbhesabfa - Item successfully added. Item Code: ' . $item->Code;
                    PrestaShopLogger::addLog($msg, 1, null, 'Product', $json->id_product, true);
                }

                return true;
            } else {
                $msg = 'ssbhesabfa - Cannot add bulk item. Error Message: ' . $response->ErrorMessage;
                PrestaShopLogger::addLog($msg, 2, $response->ErrorCode, 'Product', null, true);
                return $msg . ' Error Code: ' . $response->ErrorCode;
            }
        } else {
            $msg = 'ssbhesabfa - No product available for export.';
            PrestaShopLogger::addLog($msg, 2, null, 'Product', null, true);
            return $msg;
        }
    }

    public function setOpeningQuantity()
    {
        $products = Product::getProducts($this->context->language->id, 1, 0, 'id_product', 'ASC');
        $items = array();

        foreach ($products as $item) {
            $product = new Product($item['id_product']);

            if ($product->hasAttributes() == 0) {
                //do if product exists in hesabfa
                $id_obj = $this->getObjectId('product', $item['id_product'], 0);
                if ($id_obj > 0) {
                    $obj = new HesabfaModel($id_obj);
                    $quantity = StockAvailable::getQuantityAvailableByProduct($item['id_product']);

                    if (is_object($product) && is_object($obj) && $quantity > 0 && $product->price > 0) {
                        array_push($items, array(
                            'Code' => $obj->id_hesabfa,
                            'Quantity' => $quantity,
                            'UnitPrice' => $this->getPriceInHesabfaDefaultCurrency($product->price),
                        ));
                    }
                }
            } else {
                //Combinations
                $combinations = $product->getAttributesResume($this->id_default_lang);

                foreach ($combinations as $combination) {
                    $id_obj = $this->getObjectId('product', $item['id_product'], $combination['id_product_attribute']);
                    if ($id_obj > 0) {
                        $obj = new HesabfaModel($id_obj);
                        $quantity = StockAvailable::getQuantityAvailableByProduct($item['id_product'], $combination['id_product_attribute']);

                        if (is_object($obj) && $quantity > 0 && $product->price + $combination['price'] > 0) {
                            array_push($items, array(
                                'Code' => $obj->id_hesabfa,
                                'Quantity' => $quantity,
                                'UnitPrice' => $this->getPriceInHesabfaDefaultCurrency($product->price + $combination['price']),
                            ));
                        }
                    }
                }
            }
        }

        //call API when at least one product exists
        if (!empty($items)) {
            $hesabfa = new HesabfaApi();
            $response = $hesabfa->itemUpdateOpeningQuantity($items);
            if ($response->Success) {
                $msg = 'ssbhesabfa - Opening quantity successfully added.';
                PrestaShopLogger::addLog($msg, 1, null, 'Product', null, true);

                return true;
            } else {
                $msg = 'ssbhesabfa - Cannot set Opening quantity. Error Message: ' . $response->ErrorMessage;
                PrestaShopLogger::addLog($msg, 2, $response->ErrorCode, 'Product', null, true);

                return $msg . ' Error Code: ' . $response->ErrorCode;
            }
        } else {
            $msg = 'ssbhesabfa - No product available for set Opening quantity.';
            PrestaShopLogger::addLog($msg, 2, null, 'Product', null, true);

            return $msg;
        }
    }

    public function exportCustomers()
    {
        $customers = Customer::getCustomers();
        $data = array();
        foreach ($customers as $item) {
            //do if customer not exists in hesabfa
            $id_customer = $item['id_customer'];
            $id_obj = $this->getObjectId('customer', $id_customer);
            if (!$id_obj) {
                $customer = new Customer($id_customer);

                //check if customer name is null
                $name = $customer->firstname . ' ' . $customer->lastname;
                if (empty($customer->firstname) && empty($customer->lastname)) {
                    $name = 'Guest Customer';
                }

                array_push($data, array(
                    'Name' => $name,
                    'FirstName' => $customer->firstname,
                    'LastName' => $customer->lastname,
                    'ContactType' => 1,
                    'NodeFamily' => 'اشخاص :' . Configuration::get('SSBHESABFA_CONTACT_NODE_FAMILY'),
                    'Email' => $this->validEmail($customer->email) ? $customer->email : null,
                    'Tag' => json_encode(array('id_customer' => $id_customer)),
                    'Active' => $customer->active ? true : false,
                    'Note' => 'Customer ID in OnlineStore: ' . $id_customer,
                ));
            }
        }

        //call API when at least one customer exists
        if (!empty($data)) {
            $hesabfa = new HesabfaApi();
            $response = $hesabfa->contactBatchSave($data);
            if ($response->Success) {
                foreach ($response->Result as $item) {
                    $obj = new HesabfaModel();
                    $obj->id_hesabfa = (int)$item->Code;
                    $obj->obj_type = 'customer';
                    $json = json_decode($item->Tag);
                    $obj->id_ps = (int)$json->id_customer;
                    $obj->add();
                    $msg = 'ssbhesabfa - Contact successfully added. Contact Code: ' . $item->Code;
                    PrestaShopLogger::addLog($msg, 1, null, 'Customer', $json->id_customer, true);
                }
                return true;
            } else {
                $msg = $this->l('ssbhesabfa - Cannot add bulk contacts. Error Message: ') . $response->ErrorMessage;
                PrestaShopLogger::addLog($msg, 2, $response->ErrorCode, 'Customer', null, true);
                return $msg . ' Error Code: ' . $response->ErrorCode;
            }
        } else {
            $msg = $this->l('ssbhesabfa - No customer exists for export.');
            PrestaShopLogger::addLog($msg, 2, null, 'Customer', null, true);
            return $msg;
        }
    }

    public function syncOrders($from_date)
    {
        if (!isset($from_date)) {
            return false;
        }

        if (!$this->isDateInFiscalYear($from_date)) {
            return $this->l('The date entered is not within the fiscal year.');
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

                $order = new Order($id_order);
                if ($order->current_state == Configuration::get('SSBHESABFA_INVOICE_RETURN_STATUE')) {
                    $obj = new HesabfaModel($id_obj);
                    $this->setOrder($id_order, 2, $obj->id_hesabfa);
                }
            }
        }

        return $id_orders;
    }

    public function syncProducts()
    {
        $hesabfa = new HesabfaApi();
        $response = $hesabfa->itemGetItems(array('Take' => 99999999));
        if ($response->Success) {
            $products = $response->Result->List;
            require_once(dirname(__FILE__) . '/classes/HesabfaWebhook.php');
            foreach ($products as $item) {
                HesabfaWebhook::setItemChanges($item);
            }
        } else {
            $msg = 'ssbhesabfa - Cannot get bulk item. Error Message: ' . $response->ErrorMessage;
            PrestaShopLogger::addLog($msg, 2, $response->ErrorCode, 'Product', null, true);
            return false;
        }
    }


    //Hooks
    public function hookBackOfficeHeader()
    {
        if (Tools::getValue('module_name') == $this->name) {
            $this->context->controller->addJqueryUI('ui.datepicker');
        }
    }

    public function hookDisplayAdminProductsExtra($params)
    {
        $code = $this->getItemCodeByProductId($params['id_product'], 0);
        $this->context->smarty->assign(array(
            'hesabfa_item_code' => $code,
        ));

        $product = new Product($params['id_product']);

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
        $obj_id = $this->getObjectId('customer', $params['object']->id);
        if ($obj_id > 0) {
            $hesabfa = new HesabfaModel($obj_id);

            $hesabfaApi = new HesabfaApi();
            $response = $hesabfaApi->contactDelete($hesabfa->id_hesabfa);
            if ($response->Success) {
                $msg = 'ssbhesabfa - Contact successfully deleted.';
                PrestaShopLogger::addLog($msg, 1, null, 'Customer', $params['object']->id, true);
            } else {
                $msg = 'ssbhesabfa - Cannot delete item in hesabfa. Error Message: ' . $response->ErrorMessage;
                PrestaShopLogger::addLog($msg, 2, $response->ErrorCode, 'Customer', $params['object']->id, true);
            }

            $hesabfa->delete();
        }
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

    public function hookActionOrderStatusPostUpdate($params)
    {
        if ($params['newOrderStatus']->id == Configuration::get('SSBHESABFA_INVOICE_RETURN_STATUE')) {
            $obj_id = $this->getObjectId('order', $params['id_order']);
            if ($obj_id > 0) {
                $obj = new HesabfaModel($obj_id);
                $this->setOrder($params['id_order'], 2, $obj->id_hesabfa);
            }
        }
    }

    //Item
    public function hookActionProductAdd($params)
    {
        if (Configuration::get('SSBHESABFA_LIVE_MODE')) {
            if (!$this->setItems(array($params['product']->id))) {
                return false;
            }
        }
    }

    public function hookActionProductUpdate($params)
    {
        if (Configuration::get('SSBHESABFA_LIVE_MODE')) {
            $base_item_code = Tools::getValue('ssbhesabfa_hesabfa_item_code_0');
            if (ValidateCore::isUnsignedInt($base_item_code) != false && $base_item_code !== 0 && $base_item_code != '') {
                $obj_id = $this->getObjectId('product', $params['product']->id, 0);
                if ($obj_id > 0) {
                    $obj = new HesabfaModel($obj_id);
                    $obj->id_hesabfa = $base_item_code;
                    $obj->update();
                } else {
                	$obj = new HesabfaModel();
	                $obj->obj_type = 'product';
	                $obj->id_hesabfa = $base_item_code;
	                $obj->id_ps = $params['product']->id;
                	$obj->id_ps_attribute = 0;

                	$obj->add();
                }
            }

            if ($params['product']->hasAttributes() > 0) {
                //Combinations
                $combinations = $params['product']->getAttributesResume($this->id_default_lang);
                foreach ($combinations as $combination) {
                    $attribute_item_code = Tools::getValue('ssbhesabfa_hesabfa_item_code_' . $combination['id_product_attribute']);
                    if (ValidateCore::isUnsignedInt($attribute_item_code) != false && $attribute_item_code !== 0 && $attribute_item_code != '') {
                        $obj_id = $this->getObjectId('product', $params['product']->id, $combination['id_product_attribute']);
                        if ($obj_id > 0) {
                            $obj = new HesabfaModel($obj_id);
                            $obj->id_hesabfa = $attribute_item_code;
                            $obj->update();
                        } else {
	                        $obj = new HesabfaModel();
	                        $obj->obj_type = 'product';
	                        $obj->id_hesabfa = $attribute_item_code;
	                        $obj->id_ps = $params['product']->id;
	                        $obj->id_ps_attribute = $combination['id_product_attribute'];

	                        $obj->add();
                        }
                    }
                }
            }

            $this->hookActionProductAdd($params);
        }
    }

    public function hookActionProductDelete($params)
    {
        $obj_ids = $this->getProductAttributesObjectId($params['product']->id);
        foreach ($obj_ids as $obj_id) {
            $hesabfa = new HesabfaModel($obj_id);

            $hesabfaApi = new HesabfaApi();
            $response = $hesabfaApi->itemDelete($hesabfa->id_hesabfa);
            if ($response->Success) {
                $msg = 'ssbhesabfa - Item successfully deleted.';
                PrestaShopLogger::addLog($msg, 1, null, 'Product', $params['product']->id.'-'.$hesabfa->id_ps_attribute, true);
            } else {
                $msg = 'ssbhesabfa - Cannot delete Item in hesabfa. Error Message: ' . $response->ErrorMessage;
                PrestaShopLogger::addLog($msg, 2, $response->ErrorCode, 'Product', $params['product']->id.'-'.$hesabfa->id_ps_attribute, true);
            }

            $hesabfa->delete();
        }
    }

    public function hookActionProductAttributeAdd($params)
    {
        //ToDo: not working this hook
        if (Configuration::get('SSBHESABFA_LIVE_MODE')) {
            if (!$this->setItems(array($params['product']->id))) {
                return false;
            }
        }
    }

    public function hookActionProductAttributeUpdate($params)
    {
        if (Configuration::get('SSBHESABFA_LIVE_MODE')) {
            $this->hookActionProductAttributeAdd($params);
        }
    }

    public function hookActionProductAttributeDelete($params)
    {
        $obj_id = $this->getObjectId('product', $params['id_product'], $params['id_product_attribute']);
        if ($obj_id > 0) {
            $hesabfa = new HesabfaModel($obj_id);

            $hesabfaApi = new HesabfaApi();
            $response = $hesabfaApi->itemDelete($hesabfa->id_hesabfa);
            if ($response->Success) {
                $msg = 'ssbhesabfa - Item (Combination) successfully deleted.';
                PrestaShopLogger::addLog($msg, 1, null, 'Product', $params['id_product'], true);
            } else {
                $msg = 'ssbhesabfa - Cannot delete Item (Combination) in hesabfa. Error Message: ' . $response->ErrorMessage;
                PrestaShopLogger::addLog($msg, 2, $response->ErrorCode, 'Product', $params['id_product'], true);
            }

            $hesabfa->delete();
        }
    }
}
