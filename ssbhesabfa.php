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

        /**
         * Set $this->bootstrap to true if your module is compliant with bootstrap (PrestaShop 1.6)
         */
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Hesabfa Online Accounting ');
        $this->description = $this->l('Hesabfa Online Accounting ');

        $this->ps_versions_compliancy = array('min' => '1.6', 'max' => _PS_VERSION_);
    }

    /**
     * Don't forget to create update methods if needed:
     * http://doc.prestashop.com/display/PS16/Enabling+the+Auto-Update
     */
    public function install()
    {
        Configuration::updateValue('SSBHESABFA_LIVE_MODE', false);
        Configuration::updateValue('SSBHESABFA_ACCOUNT_USERNAME', false);
        Configuration::updateValue('SSBHESABFA_ACCOUNT_PASSWORD', false);
        Configuration::updateValue('SSBHESABFA_ACCOUNT_API', false);

        return parent::install() &&
            $this->registerHook('header') &&
            $this->registerHook('backOfficeHeader') &&
            $this->registerHook('actionCategoryAdd') &&
            $this->registerHook('actionCategoryDelete') &&
            $this->registerHook('actionCategoryUpdate') &&
            $this->registerHook('actionCustomerAccountAdd') &&
            $this->registerHook('actionOrderReturn') &&
            $this->registerHook('actionPaymentConfirmation') &&
            $this->registerHook('actionProductAdd') &&
            $this->registerHook('actionProductDelete') &&
            $this->registerHook('actionProductListOverride') &&
            $this->registerHook('actionProductUpdate') &&
            $this->registerHook('actionValidateOrder') &&
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

            include ('classes/hesabfaAPI.php');
            $api = new hesabfaAPI();
            $result = $api->api_request(array('code' => '000001'), 'contact/getcontacts');

            echo '<pre>';
            var_dump($result);
            echo '</pre>';

            if (!is_object($result)) {
                $output .= $this->displayError($result);
            } else {
                $output .= $this->displayConfirmation('done');
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
    * Add the CSS & JavaScript files you want to be loaded in the BO.
    */
    public function hookBackOfficeHeader()
    {
    }

    /**
     * Add the CSS & JavaScript files you want to be added on the FO.
     */
    public function hookHeader()
    {
    }

    public function hookActionCategoryAdd()
    {
        /* Place your code here. */
    }

    public function hookActionCategoryDelete()
    {
        /* Place your code here. */
    }

    public function hookActionCategoryUpdate()
    {
        /* Place your code here. */
    }

    public function hookActionCustomerAccountAdd()
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
