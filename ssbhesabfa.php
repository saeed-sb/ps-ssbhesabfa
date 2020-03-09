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

include ('classes/hesabfaAPI.php');

class Ssbhesabfa extends Module
{
    protected $config_form = true;

    public function __construct()
    {
        $this->name = 'ssbhesabfa';
        $this->tab = 'billing_invoicing';
        $this->version = '0.8.2';
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
        Configuration::updateValue('SSBHESABFA_INVOICE_SAVE_STATUS', 2);
        Configuration::updateValue('SSBHESABFA_INVOICE_PAYMENT_STATUS', 2);
        Configuration::updateValue('SSBHESABFA_INVOICE_NUMBER_TYPE', false);

        //Contact
        Configuration::updateValue('SSBHESABFA_CONTACT_SAVE_STATUS', 1);
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
            $this->setConfigFormValues('Config');
            $this->setChangeHook();
            $output .= $this->displayConfirmation($this->l('API Setting updated.'));
        } elseif (((bool)Tools::isSubmit('submitSsbhesabfaModuleBank')) == true) {
            $this->setConfigFormValues('Bank');
            $output .= $this->displayConfirmation($this->l('Payments Methods Setting updated.'));
        } elseif (((bool)Tools::isSubmit('submitSsbhesabfaModuleItem')) == true) {
            $this->setConfigFormValues('Item');
            $output .= $this->displayConfirmation($this->l('Catalog Setting updated.'));
        } elseif (((bool)Tools::isSubmit('submitSsbhesabfaModuleContact')) == true) {
            $this->setConfigFormValues('Contact');
            $output .= $this->displayConfirmation($this->l('Customers Setting updated.'));
        } elseif (((bool)Tools::isSubmit('submitSsbhesabfaModuleInvoice')) == true) {
            $this->setConfigFormValues('Invoice');
            $output .= $this->displayConfirmation($this->l('Orders Setting updated.'));
        }

        $this->context->smarty->assign('current_form_tab', Tools::getValue('form_tab'));

        $forms = array('Config', 'Bank', 'Item', 'Contact', 'Invoice');
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

    protected function getBankForm()
    {
        $input_array = array();

        foreach($this->getPaymentMethodsName() as $item)
        {
            $input = array(
                'col' => 3,
                'type' => 'text',
                'name' => $item['ID'],
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
        $options = array();
        $states = new OrderState();

        foreach($states->getOrderStates($this->context->language->id) as $item)
        {
            array_push($options, array(
                'id_option' => $item['id_order_state'],
                'name' => $item['name'],
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
                            'query' => $options,
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
                            'query' => $options,
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
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                    //'name' => 'submitSsbhesabfaModuleSaveSetting',
                    //'id' => 'submitSsbhesabfaModuleSaveSetting',
                ),
            ),
        );
    }

    //
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
                    'SSBHESABFA_ITEM_UNKNOWN_ID' => Configuration::get('SSBHESABFA_ITEM_NUMBER_PREFIX'),
                    'SSBHESABFA_ITEM_GIFT_WRAPPING_ID' => Configuration::get('SSBHESABFA_ITEM_GIFT_WRAPPING_ID'),
                    'SSBHESABFA_ITEM_UPDATE_PRICE' => Configuration::get('SSBHESABFA_ITEM_UPDATE_PRICE'),
                    'SSBHESABFA_ITEM_UPDATE_QUANTITY' => Configuration::get('SSBHESABFA_ITEM_UPDATE_QUANTITY'),
                );
                break;
            case 'Contact':
                $keys =  array(
                    'SSBHESABFA_CONTACT_SAVE_STATUS' => Configuration::get('SSBHESABFA_CONTACT_SAVE_STATUS'),
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
                );
                break;
            case 'Bank':
                $keys = array();
                $paymentsName = $this->getPaymentMethodsName();
                foreach ($paymentsName as $item) {
                    $keys[$item['ID']] = Configuration::get($item['ID']);
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
                    'SSBHESABFA_ITEM_UNKNOWN_ID' => Configuration::get('SSBHESABFA_ITEM_NUMBER_PREFIX'),
                    'SSBHESABFA_ITEM_GIFT_WRAPPING_ID' => Configuration::get('SSBHESABFA_ITEM_GIFT_WRAPPING_ID'),
                    'SSBHESABFA_ITEM_UPDATE_PRICE' => Configuration::get('SSBHESABFA_ITEM_UPDATE_PRICE'),
                    'SSBHESABFA_ITEM_UPDATE_QUANTITY' => Configuration::get('SSBHESABFA_ITEM_UPDATE_QUANTITY'),

                    'SSBHESABFA_CONTACT_SAVE_STATUS' => Configuration::get('SSBHESABFA_CONTACT_SAVE_STATUS'),
                    'SSBHESABFA_CONTACT_NUMBER_TYPE' => Configuration::get('SSBHESABFA_CONTACT_NUMBER_TYPE'),
                    'SSBHESABFA_CONTACT_NUMBER_PREFIX' => Configuration::get('SSBHESABFA_CONTACT_NUMBER_PREFIX'),
                    'SSBHESABFA_CONTACT_NODE_FAMILY' => Configuration::get('SSBHESABFA_CONTACT_NODE_FAMILY'),

                    'SSBHESABFA_INVOICE_SAVE_STATUS' => Configuration::get('SSBHESABFA_INVOICE_SAVE_STATUS'),
                    'SSBHESABFA_INVOICE_PAYMENT_STATUS' => Configuration::get('SSBHESABFA_INVOICE_PAYMENT_STATUS'),
                    'SSBHESABFA_INVOICE_NUMBER_TYPE' => Configuration::get('SSBHESABFA_INVOICE_NUMBER_TYPE'),
                );

                //Get config form value in Payment Method's tab
                $paymentsName = $this->getPaymentMethodsName();
                foreach ($paymentsName as $item) {
                    $keys[$item['ID']] = Configuration::get($item['ID']);
                }
        }
        return $keys;
    }

    //
    protected function setConfigFormValues($form = null){
        $form_values = $this->getConfigFormValues($form);
        foreach (array_keys($form_values) as $key) {
            Configuration::updateValue($key, Tools::getValue($key));
        }
    }

    //General submit form action
    protected function postProcess(){

    }

    //Functions
    //Return Payment methods Name and ID
    public function getPaymentMethodsName() {
        $payment_array = array();
        $modules_list = Module::getPaymentModules();

        foreach($modules_list as $module)
        {
            $module_obj = Module::getInstanceById($module['id_module']);
            $module_Id = str_replace(' ','_', 'SSBHESABFA_BANK_' . strtoupper($module_obj->displayName));

            array_push($payment_array, array(
                'name' => $module_obj->displayName,
                'ID' => $module_Id,
            ));
        }

        return $payment_array;
    }

    public function setChangeHook(){
        $store_url = $this->context->link->getBaseLink();
        //TODO: if store installed in local -> show error

        $data = array (
            'url' => $store_url . 'modules/ssbhesabfa/hesabfa-webhook.php?token=' . Tools::substr(Tools::encrypt('ssbhesabfa/webhook'), 0, 10),
            'hookPassword' => Configuration::get('SSBHESABFA_WEBHOOK_PASSWORD'),
        );

        $obj = new hesabfaAPI();
        $obj->settingSetChangeHook($data);
    }


    //Hooks
    //Contact
    public function hookActionCustomerAccountAdd($params)
    {
        // ToDo: check if customer exists
        $data = array (
            'Code' => $params['newCustomer']->id,
            'Name' => $params['newCustomer']->firstname . ' ' . $params['newCustomer']->lastname,
            'FirstName' => $params['newCustomer']->firstname,
            'LastName' => $params['newCustomer']->lastname,
            'ContactType' => 1,
            'Email' => $params['newCustomer']->email,
        );

        $obj = new hesabfaAPI();
        $obj->contactSave($data);
    }

    public function hookActionCustomerAccountUpdate($params)
    {
        $this->hookActionCustomerAccountAdd($params);
    }

    public function hookActionObjectAddressAddAfter($params)
    {
        $customer = new Customer($params['object']->id_customer);

        $data = array (
            'Code' => $params['object']->id_customer,
            'Name' => $customer->firstname . ' ' . $customer->lastname,
            'ContactType' => 1,
            'NationalCode' => $params['object']->dni,
            'EconomicCode' => $params['object']->vat_number,
            'Address' => $params['object']->address1 . ' ' . $params['object']->address2,
            'City' => $params['object']->city,
            'State' => State::getNameById($params['object']->id_state),
            'PostalCode' => $params['object']->postcode,
            'Phone' => $params['object']->phone,
            'Mobile' => $params['object']->phone_mobile,
        );
        $obj = new hesabfaAPI();
        $obj->contactSave($data);
    }

    public function hookActionObjectAddressUpdateAfter($params)
    {
        $this->hookActionObjectAddressAddAfter($params);
    }

    //Invoice
    public function hookActionValidateOrder()
    {
        /* Place your code here. */
        //$params['order']->total_paid;
        //$params['id_order'];
        //$params['customer']->id;
    }

    public function hookActionOrderStatusUpdate()
    {
        /* Place your code here. */
    }

    public function hookDisplayPaymentReturn()
    {
        /* Place your code here. */
    }

    public function hookActionOrderReturn()
    {
        /* Place your code here. */
    }

    public function hookActionPaymentConfirmation()
    {
        /* Place your code here. */
    }

    //Item
    public function hookActionProductAdd($params)
    {
        $data = array (
            'Code' => $params['product']->id,
            'Name' => $params['product']->name,
            'ItemType' => 0,
            'Barcode' => $params['product']->reference,
            'SellPrice' => $params['product']->price,
        );

        $obj = new hesabfaAPI();
        $obj->itemSave($data);
    }

    public function hookActionProductUpdate($params)
    {
        $this->hookActionProductAdd($params);
    }

    public function hookActionProductDelete($params)
    {
        $data = array (
            'Code' => $params['id_product'],
        );

        $obj = new hesabfaAPI();
        $obj->itemDelete($data);
    }

    public function hookActionProductListOverride()
    {
        /* Place your code here. */
    }
}
