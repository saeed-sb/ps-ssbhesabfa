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
    protected $config_form = false;

    public function __construct()
    {
        $this->name = 'ssbhesabfa';
        $this->tab = 'billing_invoicing';
        $this->version = '1.1.0';
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
        Configuration::updateValue('SSBHESABFA_LIVE_MODE', false);
        Configuration::updateValue('SSBHESABFA_ACCOUNT_USERNAME', null);
        Configuration::updateValue('SSBHESABFA_ACCOUNT_PASSWORD', null);
        Configuration::updateValue('SSBHESABFA_ACCOUNT_API', null);

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
        Configuration::deleteByName('SSBHESABFA_LIVE_MODE');
        Configuration::deleteByName('SSBHESABFA_ACCOUNT_USERNAME');
        Configuration::deleteByName('SSBHESABFA_ACCOUNT_PASSWORD');
        Configuration::deleteByName('SSBHESABFA_ACCOUNT_API');

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
        if (((bool)Tools::isSubmit('submitSsbhesabfaSaveSettings')) == true) {
            $this->postProcess();
            $output .= $this->displayConfirmation($this->l('Setting Updated.'));
        } elseif (((bool)Tools::getValue('submitSaveProduct')) == true) {
            if (Tools::getValue('SSBHESABFA_PRODUCT_ID') != null) {
                $this->saveProductProcess(Tools::getValue('SSBHESABFA_PRODUCT_ID'));
                $output .= $this->displayConfirmation($this->l('Product Added/Updated successfuly.'));
            } else {
                $output .= $this->displayError($this->l('Enter Product ID'));
            }
        } elseif (((bool)Tools::getValue('submitSaveCustomer')) == true) {
            if (Tools::getValue('SSBHESABFA_CUSTOMER_ID') != null) {
                $this->saveCustomerProcess(Tools::getValue('SSBHESABFA_CUSTOMER_ID'));
                $output .= $this->displayConfirmation($this->l('Customer Added/Updated successfuly.'));
            } else {
                $output .= $this->displayError($this->l('Enter Customer ID'));
            }
        }

        $this->context->smarty->assign('module_dir', $this->_path);

        $output .= $this->context->smarty->fetch($this->local_path.'views/templates/admin/configure.tpl');

        return $output.$this->renderForm();
    }

    /**
     * Create the form that will be displayed in the configuration of your module.
     */
    protected function renderForm()
    {
        $helper = new HelperForm();

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitSsbhesabfaModule';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            .'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFormValues(), /* Add values for your inputs */
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );

        return $helper->generateForm(array($this->getConfigForm(), $this->getItemForm(), $this->getContactForm()));
    }

    protected function getConfigForm()
    {
        return array(
            'form' => array(
                'legend' => array(
                'title' => $this->l('Settings'),
                'icon' => 'icon-cogs',
                ),
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
                    'name' => 'submitSsbhesabfaSaveSettings',
                    'id' => 'submitSsbhesabfaSaveSettings',
                ),
            ),
        );
    }

    protected function getItemForm()
    {
        return array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Add Product'),
                    'icon' => 'icon-product',
                ),
                'input' => array(
                    array(
                        'col' => 3,
                        'type' => 'text',
                        'desc' => $this->l('Enter a Product ID'),
                        'name' => 'SSBHESABFA_PRODUCT_ID',
                        'label' => $this->l('Product ID'),
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Add/Update'),
                    'name' => 'submitSaveProduct',
                    'id' => 'submitSaveProduct',
                ),
            ),
        );
    }

    protected function getContactForm()
    {
        return array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Add Customer'),
                    'icon' => 'icon-person',
                ),
                'input' => array(
                    array(
                        'col' => 3,
                        'type' => 'text',
                        'desc' => $this->l('Enter a Customer ID'),
                        'name' => 'SSBHESABFA_CUSTOMER_ID',
                        'label' => $this->l('Customer ID'),
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Add/Update'),
                    'name' => 'submitSaveCustomer',
                    'id' => 'submitSaveCustomer',
                ),
            ),
        );
    }

    protected function getConfigFormValues()
    {
        return array(
            'SSBHESABFA_LIVE_MODE' => Configuration::get('SSBHESABFA_LIVE_MODE'),
            'SSBHESABFA_ACCOUNT_USERNAME' => Configuration::get('SSBHESABFA_ACCOUNT_USERNAME'),
            'SSBHESABFA_ACCOUNT_PASSWORD' => Configuration::get('SSBHESABFA_ACCOUNT_PASSWORD'),
            'SSBHESABFA_ACCOUNT_API' => Configuration::get('SSBHESABFA_ACCOUNT_API'),

            'SSBHESABFA_PRODUCT_ID' => null,
            'SSBHESABFA_CUSTOMER_ID' => null,

        );
    }

    protected function postProcess()
    {
        $form_values = $this->getConfigFormValues();

        foreach (array_keys($form_values) as $key) {
            Configuration::updateValue($key, Tools::getValue($key));
        }
    }

    //
    public function saveProductProcess($id_product){
        $id_lang = Language::getIdByIso('fa');
        $product = new Product($id_product);
        $data = array (
            'Code' => $id_product,
            'Name' => $product->name[$id_lang],
            'ItemType' => 0,
            'Barcode' => $product->reference,
            'SellPrice' => $product->price,
        );

        $obj = new hesabfaAPI();
        $obj->itemSave($data);
    }

    public function saveCustomerProcess($id_customer){
        $customer = new Customer($id_customer);
        $data = array (
            'Code' => $id_customer,
            'Name' => $customer->firstname . ' ' . $customer->lastname,
            'FirstName' => $customer->firstname,
            'LastName' => $customer->lastname,
            'ContactType' => 1,
            'Email' => $customer->email,
        );

        $obj = new hesabfaAPI();
        $obj->contactSave($data);
    }

    //Hooks
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

    public function hookActionOrderReturn()
    {
        /* Place your code here. */
    }

    public function hookActionPaymentConfirmation()
    {
        /* Place your code here. */
    }

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

    public function hookActionValidateOrder()
    {
        /* Place your code here. */
    }

    public function hookDisplayPaymentReturn()
    {
        /* Place your code here. */
    }
}
