<?php
class AdminSsbHesabfaSyncController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    public function initContent()
    {
        if ((int) Tools::getValue('ajax') === 1 && Tools::getValue('action') === 'ssbhesabfaAjaxExport') {
            $this->ajaxProcessSsbhesabfaAjaxExport();
        }

        parent::initContent();
        $module = Module::getInstanceByName('ssbhesabfa');
        $this->content .= $module->renderAdminControllerContent('Sync');
        $this->context->smarty->assign(array('content' => $this->content));
    }

    protected function ajaxProcessSsbhesabfaAjaxExport()
    {
        $module = Module::getInstanceByName('ssbhesabfa');
        $type = Tools::getValue('export_type');
        $reset = ((int) Tools::getValue('reset') === 1);
        $response = $module->ajaxExportBatch($type, $reset);

        header('Content-Type: application/json; charset=utf-8');
        die(Tools::jsonEncode($response));
    }
}
