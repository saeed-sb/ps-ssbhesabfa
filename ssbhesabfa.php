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
        $this->version = '0.8.3';
        $this->author = 'Saeed Sattar Beglou';
        $this->need_instance = 0;

        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Hesabfa Online Accounting');
        $this->description = $this->l('Connect "Hesabfa Online Accounting" to Prestashop');

        $this->ps_versions_compliancy = array('min' => '1.6', 'max' => _PS_VERSION_);
    }

    public function install()
    {

        //Setting
        Configuration::updateValue('SSBHESABFA_LIVE_MODE', false);
        Configuration::updateValue('SSBHESABFA_ACCOUNT_USERNAME', null);
        Configuration::updateValue('SSBHESABFA_ACCOUNT_PASSWORD', null);
        Configuration::updateValue('SSBHESABFA_ACCOUNT_API', null);
        Configuration::updateValue('SSBHESABFA_WEBHOOK_PASSWORD', bin2hex(openssl_random_pseudo_bytes(16)));
        Configuration::updateValue('SSBHESABFA_LAST_LOG_CHECK_ID', 0);

        //Invoice
        $default_currency = Currency::getDefaultCurrency();
        Configuration::updateValue('SSBHESABFA_INVOICE_SAVE_STATUS', 2);
        Configuration::updateValue('SSBHESABFA_INVOICE_PAYMENT_STATUS', 2);
        Configuration::updateValue('SSBHESABFA_INVOICE_NUMBER_TYPE', false);
        Configuration::updateValue('SSBHESABFA_INVOICE_DEFAULT_CURRENCY', $default_currency->id);

        //Contact
        Configuration::updateValue('SSBHESABFA_CONTACT_SAVE_STATUS', 1);
        Configuration::updateValue('SSBHESABFA_CONTACT_ADDRESS_STATUS', 1);
        Configuration::updateValue('SSBHESABFA_CONTACT_NODE_FAMILY', 'Online Store Customer\'s');
        Configuration::updateValue('SSBHESABFA_CONTACT_NUMBER_TYPE', 1);
        Configuration::updateValue('SSBHESABFA_CONTACT_NUMBER_PREFIX', 0);

        //Item
        Configuration::updateValue('SSBHESABFA_ITEM_SAVE_STATUS', 1);
        Configuration::updateValue('SSBHESABFA_ITEM_NUMBER_TYPE', 1);
        Configuration::updateValue('SSBHESABFA_ITEM_NUMBER_PREFIX', 0);
        Configuration::updateValue('SSBHESABFA_ITEM_UNKNOWN_ID', 0);
        Configuration::updateValue('SSBHESABFA_ITEM_GIFT_WRAPPING_ID', 0);
        Configuration::updateValue('SSBHESABFA_ITEM_UPDATE_PRICE', 0);
        Configuration::updateValue('SSBHESABFA_ITEM_UPDATE_QUANTITY', 0);

        return parent::install() &&
            $this->registerHook('actionCustomerAccountAdd') &&
            $this->registerHook('actionCustomerAccountUpdate') &&
            $this->registerHook('actionCustomerAccountAddAfter') &&

            $this->registerHook('actionObjectAddressAddAfter') &&
            $this->registerHook('actionObjectAddressUpdateAfter') &&
            $this->registerHook('actionProductAdd') &&
            $this->registerHook('actionProductDelete') &&
            $this->registerHook('actionProductListOverride') &&
            $this->registerHook('actionProductUpdate') &&
            $this->registerHook('actionPaymentConfirmation') &&
            $this->registerHook('actionValidateOrder') &&
            $this->registerHook('actionOrderStatusUpdate') &&
            $this->registerHook('actionPaymentConfirmation') &&
            $this->registerHook('displayPaymentReturn');
    }

    public function uninstall()
    {
        $sql = "SELECT `name` FROM `" . _DB_PREFIX_ . "configuration` 
                WHERE `name` LIKE '%SSBHESABFA_%'";
        $configurations = Db::getInstance()->ExecuteS($sql);

        foreach ($configurations as $configuration) {
            Configuration::deleteByName($configuration['name']);
        }

        return parent::uninstall();
    }

    /**
     * Load the configuration form
     */
    public function getContent()
    {
        $output = '';
        /**
         * If values have been submitted in the form, process.
         */
        if (((bool)Tools::isSubmit('submitSsbhesabfaModuleConfig')) == true) {
            $this->setConfigFormsValues('Config');
            $this->setChangeHook();
            $output .= $this->displayConfirmation($this->l('API Setting updated.'));
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
            $output .= $this->displayConfirmation($this->l('Orders Setting updated.'));
        } elseif (((bool)Tools::isSubmit('submitSsbhesabfaModuleTest')) == true) {
            if ($this->testConnection()) {
                $output .= $this->displayConfirmation($this->l('Test Successfully'));
            } else {
                $output .= $this->displayError($this->l('Connecting to Hesabfa fail, Please check the Credential and API Key.'));
            }
        }

        $this->context->smarty->assign('current_form_tab', Tools::getValue('form_tab'));

        $forms = array('Config', 'Bank', 'Item', 'Contact', 'Invoice', 'Test');
        foreach ($forms as $form) {
            $html = $this->renderForm($form);
            $this->context->smarty->assign($form, $html);
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
                        'type' => 'switch',
                        'label' => $this->l('Live mode'),
                        'name' => 'SSBHESABFA_LIVE_MODE',
                        'is_bool' => true,
                        'desc' => $this->l('Use this module in live mode'),
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->l('Disabled')
                            )
                        ),
                    ),
                    array(
                        'col' => 3,
                        'top' => $this->l('Text to display before the fieldset'),
                        'title' => 'test title',
                        'type' => 'text',
                        'prefix' => '<i class="icon icon-envelope"></i>',
                        'desc' => $this->l('Enter a username'),
                        'name' => 'SSBHESABFA_ACCOUNT_USERNAME',
                        'label' => $this->l('Email'),
                    ),
                    array(
                        'type' => 'password',
                        'name' => 'SSBHESABFA_ACCOUNT_PASSWORD',
                        'label' => $this->l('Password'),
                    ),
                    array(
                        'col' => 6,
                        'type' => 'text',
                        'name' => 'SSBHESABFA_ACCOUNT_API',
                        'label' => $this->l('API Key'),
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                    //'name' => 'submitSsbhesabfaModuleSaveSetting',
                    //'id' => 'submitSsbhesabfaModuleSaveSetting',
                ),
            ),
        );
    }

    protected function getTestForm()
    {
        return array(
            'form' => array(
                'submit' => array(
                    'title' => $this->l('Test Connection'),
                ),
            ),
        );
    }

    protected function getBankForm()
    {
        $input_array = array();

        foreach ($this->getPaymentMethodsName() as $item)
        {
            $input = array(
                'col' => 3,
                'type' => 'text',
                'name' => $item['id'],
                'label' => $item['name'],
            );

            array_push($input_array, $input);
        }

        return array(
            'form' => array(
                'input' => $input_array,
                'submit' => array(
                    'title' => $this->l('Save'),
                    //'name' => 'submitSsbhesabfaModuleSaveSetting',
                    //'id' => 'submitSsbhesabfaModuleSaveSetting',
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
                        'label' => $this->l('Save Product:'),
                        'desc' => $this->l('Choose how to add Product into Hesabfa'),
                        'name' => 'SSBHESABFA_ITEM_SAVE_STATUS',
                        'options' => array(
                            'query' => array(
                                array(
                                    'id_option' => 0,
                                    'name' => $this->l('Manually'),
                                ),
                                array(
                                    'id_option' => 1,
                                    'name' => $this->l('Automatically after Add/Update Product'),
                                ),
                                array(
                                    'id_option' => 2,
                                    'name' => $this->l('Automatically before Save Invoice'),
                                ),
                            ),
                            'id' => 'id_option',
                            'name' => 'name'
                        )
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->l('Product Code'),
                        'desc' => $this->l('Choose Product Code type in Hesabfa'),
                        'name' => 'SSBHESABFA_ITEM_NUMBER_TYPE',
                        'options' => array(
                            'query' => array(
                                array(
                                    'id_option' => 1,
                                    'name' => $this->l('Same as Product ID in Online Store'),
                                ),
                                array(
                                    'id_option' => 2,
                                    'name' => $this->l('Add prefix to Product ID in Online Store'),
                                ),
                                array(
                                    'id_option' => 3,
                                    'name' => $this->l('Choose Product Reference code'),
                                ),
                            ),
                            'id' => 'id_option',
                            'name' => 'name'
                        )
                    ),
                    array(
                        'col' => 3,
                        'type' => 'text',
                        'desc' => $this->l('Enter a prefix of Product ID, Maximum 999'),
                        'name' => 'SSBHESABFA_ITEM_NUMBER_PREFIX',
                        'label' => $this->l('Product ID prefix'),
                    ),
                    array(
                        'col' => 3,
                        'type' => 'text',
                        'required' => true,
                        'desc' => $this->l('Enter a Unknown product ID, it\'s use when product not define in Hesabfa'),
                        'name' => 'SSBHESABFA_ITEM_UNKNOWN_ID',
                        'label' => $this->l('Unknown Product ID'),
                    ),
                    array(
                        'col' => 3,
                        'type' => 'text',
                        'desc' => $this->l('Enter a Gift Wrapping product ID in Hesabfa'),
                        'name' => 'SSBHESABFA_ITEM_GIFT_WRAPPING_ID',
                        'label' => $this->l('Gift Wrapping Service ID'),
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
                    //'name' => 'submitSsbhesabfaModuleSaveSetting',
                    //'id' => 'submitSsbhesabfaModuleSaveSetting',
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
                        'label' => $this->l('Save Customer:'),
                        'desc' => $this->l('Choose how to add Customer into Hesabfa'),
                        'name' => 'SSBHESABFA_CONTACT_SAVE_STATUS',
                        'options' => array(
                            'query' => array(
                                array(
                                    'id_option' => 1,
                                    'name' => $this->l('After Register/Update Customer'),
                                ),
                                array(
                                    'id_option' => 2,
                                    'name' => $this->l('Before insert Invoice'),
                                ),
                            ),
                            'id' => 'id_option',
                            'name' => 'name'
                        )
                    ),
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
                        'type' => 'select',
                        'label' => $this->l('Customer Code'),
                        'desc' => $this->l('Choose "Customer Code" type in Hesabfa'),
                        'name' => 'SSBHESABFA_CONTACT_NUMBER_TYPE',
                        'options' => array(
                            'query' => array(
                                array(
                                    'id_option' => 1,
                                    'name' => $this->l('Same as Customer ID in Online Store'),
                                ),
                                array(
                                    'id_option' => 2,
                                    'name' => $this->l('Add prefix to Customer ID in Online Store'),
                                ),
                            ),
                            'id' => 'id_option',
                            'name' => 'name'
                        )
                    ),
                    array(
                        'col' => 3,
                        'type' => 'text',
                        'desc' => $this->l('Enter a prefix of Customer ID, Maximum 999'),
                        'name' => 'SSBHESABFA_CONTACT_NUMBER_PREFIX',
                        'label' => $this->l('Customer ID prefix'),
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
                    //'name' => 'submitSsbhesabfaModuleSaveSetting',
                    //'id' => 'submitSsbhesabfaModuleSaveSetting',
                ),
            ),
        );
    }

    protected function getInvoiceForm()
    {
        $status_options = array();
        $states = new OrderState();

        foreach ($states->getOrderStates($this->context->language->id) as $item)
        {
            array_push($status_options, array(
                'id_option' => $item['id_order_state'],
                'name' => $item['name'],
            ));
        }

        $currency_options = array();
        foreach (Currency::getCurrencies() as $currency)
        {
            array_push($currency_options, array(
                'id_option' => $currency['id_currency'],
                'name' => $currency['name'],
            ));
        }

        return array(
            'form' => array(
                'input' => array(
                    array(
                        'type' => 'select',
                        'label' => $this->l('Save Order State:'),
                        'desc' => $this->l('At what "Order State" should the invoice be save in Hesabfa?'),
                        'name' => 'SSBHESABFA_INVOICE_SAVE_STATUS',
                        'options' => array(
                            'query' => $status_options,
                            'id' => 'id_option',
                            'name' => 'name'
                        )
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->l('Save Payment State'),
                        'desc' => $this->l('At what "Order State" should the payment be save in Hesabfa?'),
                        'name' => 'SSBHESABFA_INVOICE_PAYMENT_STATUS',
                        'options' => array(
                            'query' => $status_options,
                            'id' => 'id_option',
                            'name' => 'name'
                        )
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Serialized Invoice Number?'),
                        'name' => 'SSBHESABFA_INVOICE_NUMBER_TYPE',
                        'is_bool' => true,
                        'desc' => $this->l('Select "Yes" for the invoice number to be identical between Prestashop and Hesabfa.
Select "No" to ignore the number of Prestashop invoices.'),
                        'values' => array(
                            array(
                                'id' => 'invoice_no_on',
                                'value' => true,
                                'label' => $this->l('Yes')
                            ),
                            array(
                                'id' => 'invoice_no_off',
                                'value' => false,
                                'label' => $this->l('No')
                            )
                        ),
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->l('Hesabfa Default Currency:'),
                        'desc' => $this->l('if it\'s not available in dropdown, add it in Localization.'),
                        'name' => 'SSBHESABFA_INVOICE_DEFAULT_CURRENCY',
                        'options' => array(
                            'query' => $currency_options,
                            'id' => 'id_option',
                            'name' => 'name'
                        )
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                    //'name' => 'submitSsbhesabfaModuleSaveSetting',
                    //'id' => 'submitSsbhesabfaModuleSaveSetting',
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
                    'SSBHESABFA_LIVE_MODE' => Configuration::get('SSBHESABFA_LIVE_MODE'),
                    'SSBHESABFA_ACCOUNT_USERNAME' => Configuration::get('SSBHESABFA_ACCOUNT_USERNAME'),
                    'SSBHESABFA_ACCOUNT_PASSWORD' => Configuration::get('SSBHESABFA_ACCOUNT_PASSWORD'),
                    'SSBHESABFA_ACCOUNT_API' => Configuration::get('SSBHESABFA_ACCOUNT_API'),
                );
                break;
            case 'Item':
                $keys =  array(
                    'SSBHESABFA_ITEM_SAVE_STATUS' => Configuration::get('SSBHESABFA_ITEM_SAVE_STATUS'),
                    'SSBHESABFA_ITEM_NUMBER_TYPE' => Configuration::get('SSBHESABFA_ITEM_NUMBER_TYPE'),
                    'SSBHESABFA_ITEM_NUMBER_PREFIX' => Configuration::get('SSBHESABFA_ITEM_NUMBER_PREFIX'),
                    'SSBHESABFA_ITEM_UNKNOWN_ID' => Configuration::get('SSBHESABFA_ITEM_UNKNOWN_ID'),
                    'SSBHESABFA_ITEM_GIFT_WRAPPING_ID' => Configuration::get('SSBHESABFA_ITEM_GIFT_WRAPPING_ID'),
                    'SSBHESABFA_ITEM_UPDATE_PRICE' => Configuration::get('SSBHESABFA_ITEM_UPDATE_PRICE'),
                    'SSBHESABFA_ITEM_UPDATE_QUANTITY' => Configuration::get('SSBHESABFA_ITEM_UPDATE_QUANTITY'),
                );
                break;
            case 'Contact':
                $keys =  array(
                    'SSBHESABFA_CONTACT_SAVE_STATUS' => Configuration::get('SSBHESABFA_CONTACT_SAVE_STATUS'),
                    'SSBHESABFA_CONTACT_ADDRESS_STATUS' => Configuration::get('SSBHESABFA_CONTACT_ADDRESS_STATUS'),
                    'SSBHESABFA_CONTACT_NUMBER_TYPE' => Configuration::get('SSBHESABFA_CONTACT_NUMBER_TYPE'),
                    'SSBHESABFA_CONTACT_NUMBER_PREFIX' => Configuration::get('SSBHESABFA_CONTACT_NUMBER_PREFIX'),
                    'SSBHESABFA_CONTACT_NODE_FAMILY' => Configuration::get('SSBHESABFA_CONTACT_NODE_FAMILY'),
                );
                break;
            case 'Invoice':
                $keys =  array(
                    'SSBHESABFA_INVOICE_SAVE_STATUS' => Configuration::get('SSBHESABFA_INVOICE_SAVE_STATUS'),
                    'SSBHESABFA_INVOICE_PAYMENT_STATUS' => Configuration::get('SSBHESABFA_INVOICE_PAYMENT_STATUS'),
                    'SSBHESABFA_INVOICE_NUMBER_TYPE' => Configuration::get('SSBHESABFA_INVOICE_NUMBER_TYPE'),
                    'SSBHESABFA_INVOICE_DEFAULT_CURRENCY' => Configuration::get('SSBHESABFA_INVOICE_DEFAULT_CURRENCY'),
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
                    'SSBHESABFA_LIVE_MODE' => Configuration::get('SSBHESABFA_LIVE_MODE'),
                    'SSBHESABFA_ACCOUNT_USERNAME' => Configuration::get('SSBHESABFA_ACCOUNT_USERNAME'),
                    'SSBHESABFA_ACCOUNT_PASSWORD' => Configuration::get('SSBHESABFA_ACCOUNT_PASSWORD'),
                    'SSBHESABFA_ACCOUNT_API' => Configuration::get('SSBHESABFA_ACCOUNT_API'),

                    'SSBHESABFA_PRODUCT_ID' => null,
                    'SSBHESABFA_CUSTOMER_ID' => null,

                    'SSBHESABFA_ITEM_SAVE_STATUS' => Configuration::get('SSBHESABFA_ITEM_SAVE_STATUS'),
                    'SSBHESABFA_ITEM_NUMBER_TYPE' => Configuration::get('SSBHESABFA_ITEM_NUMBER_TYPE'),
                    'SSBHESABFA_ITEM_NUMBER_PREFIX' => Configuration::get('SSBHESABFA_ITEM_NUMBER_PREFIX'),
                    'SSBHESABFA_ITEM_UNKNOWN_ID' => Configuration::get('SSBHESABFA_ITEM_UNKNOWN_ID'),
                    'SSBHESABFA_ITEM_GIFT_WRAPPING_ID' => Configuration::get('SSBHESABFA_ITEM_GIFT_WRAPPING_ID'),
                    'SSBHESABFA_ITEM_UPDATE_PRICE' => Configuration::get('SSBHESABFA_ITEM_UPDATE_PRICE'),
                    'SSBHESABFA_ITEM_UPDATE_QUANTITY' => Configuration::get('SSBHESABFA_ITEM_UPDATE_QUANTITY'),

                    'SSBHESABFA_CONTACT_SAVE_STATUS' => Configuration::get('SSBHESABFA_CONTACT_SAVE_STATUS'),
                    'SSBHESABFA_CONTACT_ADDRESS_STATUS' => Configuration::get('SSBHESABFA_CONTACT_ADDRESS_STATUS'),
                    'SSBHESABFA_CONTACT_NUMBER_TYPE' => Configuration::get('SSBHESABFA_CONTACT_NUMBER_TYPE'),
                    'SSBHESABFA_CONTACT_NUMBER_PREFIX' => Configuration::get('SSBHESABFA_CONTACT_NUMBER_PREFIX'),
                    'SSBHESABFA_CONTACT_NODE_FAMILY' => Configuration::get('SSBHESABFA_CONTACT_NODE_FAMILY'),

                    'SSBHESABFA_INVOICE_SAVE_STATUS' => Configuration::get('SSBHESABFA_INVOICE_SAVE_STATUS'),
                    'SSBHESABFA_INVOICE_PAYMENT_STATUS' => Configuration::get('SSBHESABFA_INVOICE_PAYMENT_STATUS'),
                    'SSBHESABFA_INVOICE_NUMBER_TYPE' => Configuration::get('SSBHESABFA_INVOICE_NUMBER_TYPE'),
                    'SSBHESABFA_INVOICE_DEFAULT_CURRENCY' => Configuration::get('SSBHESABFA_INVOICE_DEFAULT_CURRENCY'),
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
            Configuration::updateValue($key, Tools::getValue($key));
        }
    }

    //General submit form action
    protected function postProcess()
    {
    }

    protected function testConnection()
    {
        $connection = $this->setChangeHook();
        if (isset($connection) && $connection->Success)
            return true;
        else
            return false;
    }

    //Functions
    //Return Payment methods Name and ID
    public function getPaymentMethodsName()
    {
        $payment_array = array();
        $modules_list = Module::getPaymentModules();

        foreach ($modules_list as $module)
        {
            $module_obj = Module::getInstanceById($module['id_module']);
            $module_Id = str_replace(' ', '_', 'SSBHESABFA_BANK_' . Tools::strtoupper($module_obj->displayName));

            array_push($payment_array, array(
                'name' => $module_obj->displayName,
                'id' => $module_Id,
            ));
        }

        if (Module::isInstalled('psf_prestapay')) {
            $prestapay = Module::getInstanceByName('psf_prestapay');

            /* Check if the module is enabled */
            if ($prestapay->active) {
                //die(var_dump($prestapay->getModulePlugins(1)));
                $psf_prestapay = new psf_prestapay();
                $plugins = $psf_prestapay->getModulePlugins(1);
                //var_dump($plugins->gateway);
                //$plugins = $prestapay->getModulePlugins(1);
                foreach ($plugins as $key => $plugin) {
                    //echo '<pre>';
                    //echo $key;
                    //var_dump(get_class($plugin));
                    //var_dump($plugin);
                    //echo '</pre>';
                }
            }
        }

        return $payment_array;
    }

    public function setChangeHook()
    {
        $store_url = $this->context->link->getBaseLink();
        //TODO: if store installed in local -> show error

        //$url = $store_url . 'modules/ssbhesabfa/ssbhesabfa-webhook.php?token=' . Tools::substr(Tools::encrypt('ssbhesabfa/webhook'), 0, 10);
        $url = 'https://webhook.site/2c4b65ef-723d-4ab7-ba49-895ba8c39b99';
        $hookPassword = Configuration::get('SSBHESABFA_WEBHOOK_PASSWORD');

        $hesabfa = new hesabfaApi();

        return $hesabfa->settingSetChangeHook($url, $hookPassword);
    }

    //Items
    public function getItemCodeByProductId($id_product)
    {
        if (!isset($id_product))
            return false;

        $product = new Product($id_product);

        switch (Configuration::get('SSBHESABFA_ITEM_NUMBER_TYPE'))
        {
            case 1:
                $code = $id_product;
                break;
            case 2:
                $code = Configuration::get('SSBHESABFA_ITEM_NUMBER_PREFIX') * 1000 + $id_product;
                break;
            case 3:
                if(isset($product->reference))
                    $code = $product->reference;
                else
                    $code = Configuration::get('SSBHESABFA_ITEM_UNKNOWN_ID');
                break;
        }

        return $code;
    }

    public function setItem($id_product)
    {
        if (!isset($id_product))
            return false;

        $id_default_lang = Configuration::get('PS_LANG_DEFAULT');
        $code = $this->getItemCodeByProductId($id_product);

        $hesabfa = new hesabfaApi();
        $result = $hesabfa->itemGet($code);

        if ($result->Success == false && $result->ErrorCode == 112)
        {
            $product = new Product($id_product);

            $itemType = ($product->is_virtual == 1 ? 1 : 0);
            $item = array (
                'Code' => $code,
                'Name' => $product->name[$id_default_lang],
                'ItemType' => $itemType,
                'Barcode' => $product->upc,
            );

            return $hesabfa->itemSave($item);
        }
        return false;
    }

    //Contact
    public function getContactCodeByCustomerId($id_customer)
    {
        if (!isset($id_customer))
            return false;

        switch (Configuration::get('SSBHESABFA_CONTACT_NUMBER_TYPE'))
        {
            case 1:
                $code = $id_customer;
                break;
            case 2:
                $code = Configuration::get('SSBHESABFA_CONTACT_NUMBER_PREFIX') * 1000 + $id_customer;
                break;
        }

        return $code;
    }

    public function setContact($id_customer)
    {
        if (!isset($id_customer))
            return false;

        $code = $this->getContactCodeByCustomerId($id_customer);

        $hesabfa = new hesabfaApi();
        $result = $hesabfa->contactGet($code);

        if (($result->Success == false && $result->ErrorCode == 112))
        {
            $customer = new Customer($id_customer);
            $data = array (
                'Code' => $code,
                'Name' => $customer->firstname . ' ' . $customer->lastname,
                'FirstName' => $customer->firstname,
                'LastName' => $customer->lastname,
                'ContactType' => 1,
                'Email' => $customer->email,
            );
            return $hesabfa->contactSave($data);
        } elseif ($result->Success == true) {
            return $result;
        }
        return false;
    }

    public function setContactAddress($id_customer, $id_address)
    {
        if (!isset($id_customer) || !isset($id_address))
            return false;

        $code = $this->getContactCodeByCustomerId($id_customer);

        $customer = new Customer($id_customer);
        $address = new Address($id_address);

        $data = array (
            'Code' => $code,
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
            'PostalCode' => $address->postcode,
            'Phone' => $address->phone,
            'Mobile' => $address->phone_mobile,
            'Email' => $customer->email,
        );

        $hesabfa = new hesabfaApi();
        return $hesabfa->contactSave($data);
    }

    //Invoice
    public function getInvoiceCodeByOrderId($id_order)
    {
        if (!isset($id_order))
            return false;

        if (Configuration::get('SSBHESABFA_INVOICE_NUMBER_TYPE') == true){
            $code = $id_order;
        } else {
            $order = new Order($id_order);

            $hesabfa = new hesabfaApi();
            $data = array(
                'SortBy' => 'Number',
                'SortDesc' => false,
                'Take' => 100,
                'Skip' => 0,
                'Filters' => array(
                    array(
                        'Property' => 'Reference',
                        'Operator' => '=',
                        'Value' => $order->reference,
                    ),
                ),
            );

            $invoices = $hesabfa->invoiceGetInvoices($data);

            if ($invoices->Success && $invoices->Result->FilteredCount == 1) {
                $code = $invoices->Result->List[0]->Number;
            } else {
                $code = null;
            }
        }
        return $code;
    }

    public function setOrder($id_order)
    {
        if (!isset($id_order))
            return false;

        $order = new Order($id_order);
        $hesabfa = new hesabfaApi();

        $items = array();
        $i = 0;
        $products = $order->getProducts();
        foreach ($products as $key => $product) {
            $code = $this->getItemCodeByProductId($product['product_id']);

            // add product before insert invoice
            $result = $hesabfa->itemGet($code);
            if (!$result->Success && $result->ErrorCode == 112) {
                if (Configuration::get('SSBHESABFA_ITEM_SAVE_STATUS') == 2) {
                    $this->setItem($product['product_id']);
                } else {
                    $code = Configuration::get('SSBHESABFA_ITEM_UNKNOWN_ID');
                }
            }

            $item = array (
                'RowNumber' => $i,
                'ItemCode' => (int)$code,
                'Description' => $product['product_name'],
                'Quantity' => (int)$product['product_quantity'],
                'UnitPrice' => (int)$this->getOrderPriceInDefaultCurrency($product['product_price'], $id_order),
                'Discount' => $this->getOrderPriceInDefaultCurrency($product['reduction_amount_tax_excl'], $id_order),
                'Tax' => $this->getOrderPriceInDefaultCurrency(($product['unit_price_tax_incl'] - $product['unit_price_tax_excl']), $id_order),
            );
            array_push($items, $item);
            $i++;
        }

        if ($order->total_wrapping_tax_excl > 0)
        {
            array_push($items, array (
                'RowNumber' => $i+1,
                'ItemCode' => $this->getItemCodeByProductId(Configuration::get('SSBHESABFA_ITEM_GIFT_WRAPPING_ID')),
                'Description' => $this->l('Gift wrapping Service'),
                'Quantity' => 1,
                'UnitPrice' => $this->getOrderPriceInDefaultCurrency(($order->total_wrapping), $id_order),
                'Discount' => 0,
                'Tax' => $this->getOrderPriceInDefaultCurrency(($order->total_wrapping_tax_incl - $order->total_wrapping_tax_excl), $id_order),
            ));
        }

        $data = array (
            'Number' => $this->getInvoiceCodeByOrderId($id_order),
            'InvoiceType' => 0,
            'ContactCode' => $this->getContactCodeByCustomerId($order->id_customer),
            'Date' => $order->date_add,
            'DueDate' => $order->date_add,
            'Reference' => $order->reference,
            'Status' => 2,
            'Freight' => $this->getOrderPriceInDefaultCurrency($order->total_shipping_tax_incl, $id_order),
            'InvoiceItems' => $items,
        );

        return $hesabfa->invoiceSave($data);
    }

    public function getOrderPriceInDefaultCurrency($price, $id_order)
    {
        if (!isset($price) || !isset($id_order))
            return false;

        $order = new Order($id_order);
        $currency = new Currency(Configuration::get('SSBHESABFA_INVOICE_DEFAULT_CURRENCY'));
        $price = $price / $order->conversion_rate / $currency->getConversionRate();

        return $price;
    }

    public function setOrderPayment($id_order)
    {
        if (!isset($id_order))
            return false;

        $code = $this->getInvoiceCodeByOrderId($id_order);
        $hesabfa = new hesabfaApi();
        $result = $hesabfa->invoiceGet($code,0);

        if (($result->Success == false && $result->ErrorCode == 112))
        {
            $order = new Order($id_order);
            $payments = $order->getOrderPaymentCollection();

            foreach ($payments as $payment) {
                $orderPayment = new OrderPayment($payment['id_order_payment']);

                return $hesabfa->invoiceSavePayment($code, $this->getBankCodeByName($orderPayment->payment_method), $orderPayment->date_add, $orderPayment->amount, $orderPayment->transaction_id, $orderPayment->card_number);
            }
        }
        return false;
    }

    public function getBankCodeByPaymentName($paymentName)
    {
        $configurationName = str_replace(' ', '_', 'SSBHESABFA_BANK_' . Tools::strtoupper($paymentName));

        $sql = 'SELECT `value` FROM `' . _DB_PREFIX_ . 'configuration` 
                WHERE `name` LIKE `'. $configurationName .'`
        ';
        $bankCode = Db::getInstance()->ExecuteS($sql);

        return $bankCode[0];
    }

    //Hooks
    //Contact
    public function hookActionCustomerAccountAdd($params)
    {
        if (Configuration::get('SSBHESABFA_CONTACT_SAVE_STATUS') == 1)
        {
            $this->setContact($params['newCustomer']->id);
        }
    }

    public function hookActionCustomerAccountUpdate($params)
    {
        $this->hookActionCustomerAccountAdd($params);
    }

    public function hookActionObjectAddressAddAfter($params)
    {
        $this->setContactAddress($params['object']->id_customer, $params['object']->id);
    }

    //Invoice
    public function hookActionValidateOrder($params)
    {
        /* Place your code here. */
        //$params['order']->total_paid;
        //$params['id_order'];
        //$params['customer']->id;
    }

    public function hookActionOrderStatusUpdate($params)
    {
        if ((int)$params['newOrderStatus']->id == Configuration::get('SSBHESABFA_INVOICE_SAVE_STATUS'))
        {
            if (Configuration::get('SSBHESABFA_CONTACT_SAVE_STATUS') == 2)
            {
                $order = new Order((int)$params['id_order']);
                //set customer if not exists
                $contact = $this->setContact($order->id_customer);
                if ($contact->Success)
                {
                    // add Contact Address
                    if (Configuration::get('SSBHESABFA_CONTACT_ADDRESS_STATUS') == 2) {
                        $this->setContactAddress($order->id_customer, $order->id_address_invoice);
                    } elseif (Configuration::get('SSBHESABFA_CONTACT_ADDRESS_STATUS') == 3) {
                        $this->setContactAddress($order->id_customer, $order->id_address_delivery);
                    }

                    // add invoice
                    $this->setOrder((int)$params['id_order']);
                }
            }
        }

        //ToDo: add payment checks
        if ((int)$params['newOrderStatus']->id == Configuration::get('SSBHESABFA_INVOICE_PAYMENT_STATUS'))
        {
            $this->setOrderPayment((int)$params['id_order']);
        }

        //haminjoori
        //Validate::isLoadedObject($customer);
    }

    public function hookActionPaymentConfirmation()
    {
        /* Place your code here. */
    }

    //Item
    public function hookActionProductAdd($params)
    {
        if (Configuration::get('SSBHESABFA_ITEM_SAVE_STATUS') == 1)
            return $this->setItem($params['product']->id);

        return false;
    }

    public function hookActionProductUpdate($params)
    {
        return $this->hookActionProductAdd($params);
    }
}
