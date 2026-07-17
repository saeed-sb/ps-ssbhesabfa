<?php
class AdminSsbHesabfaPaymentsController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    public function initContent()
    {
        parent::initContent();
        $module = Module::getInstanceByName('ssbhesabfa');
        $this->content .= $module->renderAdminControllerContent('Payments');
        $this->context->smarty->assign(array('content' => $this->content));
    }
}
