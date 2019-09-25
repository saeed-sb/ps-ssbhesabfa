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
        $this->version = '1.0.0';
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
        if (((bool)Tools::isSubmit('submitSsbhesabfaModule')) == true) {
            $this->postProcess();

            //test environment
            $data = array('code' => '000001');
            $method = 'contact/getcontacts';

            include ('classes/hesabfaAPI.php');
            $api = new hesabfaAPI();
            $result = $api->api_request($data, $method);

            //$paymentModules = PaymentModule::getInstalledPaymentModules();
            //$paymentModules = PaymentOptionsFinderCore::present();

            $test = new PaymentOptionsFinder;
            $paymentModules = $test->find();

            echo '<pre>';
            //var_dump($test);
            var_dump($paymentModules);
            //var_dump($result);
            echo '</pre>';

            if (!is_object($result)) {
                $output .= $this->displayError($result);
            } else {
                $output .= $this->displayConfirmation($this->l('Done'));
            }

            //end test environment
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

        return $helper->generateForm(array($this->getConfigForm()));
    }

    /**
     * Create the structure of your form.
     */
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
                ),
            ),
        );
    }

    /**
     * Set values for the inputs.
     */
    protected function getConfigFormValues()
    {
        return array(
            'SSBHESABFA_LIVE_MODE' => Configuration::get('SSBHESABFA_LIVE_MODE'),
            'SSBHESABFA_ACCOUNT_USERNAME' => Configuration::get('SSBHESABFA_ACCOUNT_USERNAME'),
            'SSBHESABFA_ACCOUNT_PASSWORD' => Configuration::get('SSBHESABFA_ACCOUNT_PASSWORD'),
            'SSBHESABFA_ACCOUNT_API' => Configuration::get('SSBHESABFA_ACCOUNT_API'),
        );
    }

    /**
     * Save form data.
     */
    protected function postProcess()
    {
        $form_values = $this->getConfigFormValues();

        foreach (array_keys($form_values) as $key) {
            Configuration::updateValue($key, Tools::getValue($key));
        }
    }

    /**
     * @param $data
     * @param $method
     */
    public function runAPI($data, $method)
    {
        $api = new hesabfaAPI();
        $result = $api->api_request($data, $method);

        // ToDo add log $result
        //Tools::dieObject($result);
        //if (is_object($result)) {
        //    $msg = $result->Success . $result->ErrorCode . $result->ErrorMessage;
        //    PrestaShopLogger::addLog($msg, 1,  null, null, null, true, null);
        //} else {
        //    PrestaShopLogger::addLog($result, 1,  null, null, null, true, null);
        //}
    }

    /**
     * @param $params
     */
    public function hookActionCustomerAccountAdd($params)
    {
        // ToDo: check if customer exists

        $method = 'contact/save';
        $data = array (
            'contact' => array (
                'Code' => $params['newCustomer']->id,
                'Name' => $params['newCustomer']->firstname . ' ' . $params['newCustomer']->lastname,
                'FirstName' => $params['newCustomer']->firstname,
                'LastName' => $params['newCustomer']->lastname,
                'ContactType' => 1,
                'Email' => $params['newCustomer']->email,
            )
        );

        $this->runAPI($data, $method);
    }

    public function hookActionObjectAddressAddAfter($params)
    {
        $customer = new Customer($params['object']->id_customer);

        $method = 'contact/save';
        $data = array (
            'contact' => array (
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
            )
        );

        $this->runAPI($data, $method);
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

    public function hookActionProductAdd()
    {
        /* Place your code here. */
    }

    public function hookActionProductDelete()
    {
        /* Place your code here. */
    }

    public function hookActionProductListOverride()
    {
        /* Place your code here. */
    }

    public function hookActionProductUpdate()
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
