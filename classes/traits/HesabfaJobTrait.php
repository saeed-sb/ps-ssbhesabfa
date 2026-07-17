<?php

trait HesabfaJobTrait
{
    protected function safeSetOrderFromHook($idOrder, $orderType = 0, $reference = null, $hookName = '')
    {
        if (!$this->isHesabfaSyncEnabled()) {
            return false;
        }

        if (Configuration::get('SSBHESABFA_ASYNC_ORDER_SYNC')) {
            if (!$this->isHesabfaApiConfigured()) {
                self::addLegacyLog('Order sync was not queued because Hesabfa API credentials are not configured.', 2, 'HESABFA_NOT_CONFIGURED', 'Order', (int) $idOrder, true);
                return false;
            }
            HesabfaJobRepository::enqueue('set_order', array(
                'id_order' => (int) $idOrder,
                'order_type' => (int) $orderType,
                'reference' => $reference,
                'source_hook' => (string) $hookName,
            ), 'Order', (int) $idOrder);
            self::addModuleLog('Hesabfa order sync job was queued from hook.', 'INFO', null, 'Order', (int) $idOrder);
            return true;
        }

        if (!Configuration::get('SSBHESABFA_LIVE_MODE')) {
            return false;
        }

        try {
            return $this->setOrder((int) $idOrder, $orderType, $reference);
        } catch (Exception $e) {
            self::addLegacyLog('Hesabfa order registration failed during PrestaShop hook execution. Hook: ' . $hookName . '. Order ID: ' . (int) $idOrder . '. Details: ' . $e->getMessage(), 3, 'HOOK_SET_ORDER_EXCEPTION', 'Order', (int) $idOrder, true);
        }
        return false;
    }

    protected function safeSetOrderPaymentFromHook($idOrder, $hookName = '')
    {
        if (!$this->isHesabfaSyncEnabled()) {
            return false;
        }

        if (Configuration::get('SSBHESABFA_ASYNC_ORDER_SYNC')) {
            if (!$this->isHesabfaApiConfigured()) {
                self::addLegacyLog('Payment sync was not queued because Hesabfa API credentials are not configured.', 2, 'HESABFA_NOT_CONFIGURED', 'Order', (int) $idOrder, true);
                return false;
            }
            HesabfaJobRepository::enqueue('set_order_payment', array('id_order' => (int) $idOrder, 'source_hook' => (string) $hookName), 'Order', (int) $idOrder);
            self::addModuleLog('Hesabfa payment sync job was queued from hook.', 'INFO', null, 'Order', (int) $idOrder);
            return true;
        }

        if (!Configuration::get('SSBHESABFA_LIVE_MODE')) {
            return false;
        }

        try {
            return $this->setOrderPayment((int) $idOrder);
        } catch (Exception $e) {
            self::addLegacyLog('Hesabfa payment registration failed during PrestaShop hook execution. Hook: ' . $hookName . '. Order ID: ' . (int) $idOrder . '. Details: ' . $e->getMessage(), 3, 'HOOK_SET_PAYMENT_EXCEPTION', 'Order', (int) $idOrder, true);
        }
        return false;
    }
    public function processPendingHesabfaJobs($limit = 20)
    {
        return (new HesabfaQueueService($this))->processPending($limit);
    }
    protected function processSingleHesabfaJob($idJob)
    {
        return (new HesabfaQueueService($this))->processSingle($idJob);
    }
    protected function processHesabfaJobRow(array $job)
    {
        return (new HesabfaQueueService($this))->processRow($job);
    }


    protected function queueCustomerSync($idCustomer, $sourceHook = '')
    {
        return HesabfaJobRepository::enqueue('sync_customer',array('id_customer'=>(int)$idCustomer,'source_hook'=>(string)$sourceHook),'Customer',(int)$idCustomer);
    }

    protected function queueCustomerAddressSync($idCustomer, $idAddress, $sourceHook = '')
    {
        return HesabfaJobRepository::enqueue('sync_customer_address',array('id_customer'=>(int)$idCustomer,'id_address'=>(int)$idAddress,'source_hook'=>(string)$sourceHook),'Customer',(int)$idCustomer);
    }

    protected function queueCustomerDelete($idCustomer, $mappingId, $hesabfaCode)
    {
        return HesabfaJobRepository::enqueue('delete_customer',array('id_customer'=>(int)$idCustomer,'mapping_id'=>(int)$mappingId,'hesabfa_code'=>(int)$hesabfaCode),'Customer',(int)$idCustomer);
    }


    protected function queueProductItemDelete($idProduct, $idProductAttribute, $mappingId, $hesabfaCode, $sourceHook = '')
    {
        return HesabfaJobRepository::enqueue(
            'delete_product_item',
            array(
                'id_product' => (int) $idProduct,
                'id_product_attribute' => (int) $idProductAttribute,
                'mapping_id' => (int) $mappingId,
                'hesabfa_code' => (int) $hesabfaCode,
                'source_hook' => (string) $sourceHook,
            ),
            'Product',
            (int) $idProduct . '-' . (int) $idProductAttribute
        );
    }

    protected function getAdminOrderIdFromHookParams($params)
    {
        if (isset($params['id_order']) && (int) $params['id_order'] > 0) {
            return (int) $params['id_order'];
        }

        if (isset($params['order']) && Validate::isLoadedObject($params['order'])) {
            return (int) $params['order']->id;
        }

        if (isset($params['order']) && is_array($params['order']) && isset($params['order']['id_order'])) {
            return (int) $params['order']['id_order'];
        }

        $idOrder = (int) Tools::getValue('id_order');
        if ($idOrder > 0) {
            return $idOrder;
        }

        return 0;
    }

    protected function getAdminOrderHesabfaToken()
    {
        $controller = Tools::getValue('controller');
        if (!$controller) {
            $controller = 'AdminOrders';
        }

        return Tools::getAdminTokenLite($controller);
    }

    protected function processAdminOrderHesabfaSubmit($idOrder)
    {
        if (!Tools::isSubmit('submitSsbhesabfaRegisterOrder')) {
            return null;
        }

        $submittedOrderId = (int) Tools::getValue('ssbhesabfa_id_order');
        if ($submittedOrderId !== (int) $idOrder) {
            return array('type' => 'error', 'message' => $this->l('Invalid order request.'));
        }

        $token = (string) Tools::getValue('ssbhesabfa_order_token');
        if ($token !== $this->getAdminOrderHesabfaToken()) {
            return array('type' => 'error', 'message' => $this->l('Invalid security token.'));
        }

        if (Configuration::get('SSBHESABFA_LIVE_MODE') != 1) {
            return array('type' => 'error', 'message' => $this->l('Please configure and connect the Hesabfa API before registering this order.'));
        }

        $existingInvoiceNumber = $this->getInvoiceCodeByOrderId($idOrder);
        if (!empty($existingInvoiceNumber)) {
            return array('type' => 'success', 'message' => $this->l('This order is already registered in Hesabfa.'));
        }

        if ($this->setOrder($idOrder)) {
            return array('type' => 'success', 'message' => $this->l('The order was registered in Hesabfa successfully.'));
        }

        return array('type' => 'error', 'message' => $this->l('Could not register this order in Hesabfa. Please check the module logs for details.'));
    }

    protected function renderAdminOrderHesabfaBox($params)
    {
        $idOrder = $this->getAdminOrderIdFromHookParams($params);
        if (!$idOrder) {
            return '';
        }

        $order = new Order($idOrder);
        if (!Validate::isLoadedObject($order)) {
            return '';
        }

        $result = $this->processAdminOrderHesabfaSubmit($idOrder);
        $invoiceNumber = $this->getInvoiceCodeByOrderId($idOrder);
        $isConnected = (Configuration::get('SSBHESABFA_LIVE_MODE') == 1);

        $this->context->smarty->assign(array(
            'ssbhesabfa_order_id' => (int) $idOrder,
            'ssbhesabfa_invoice_number' => $invoiceNumber,
            'ssbhesabfa_is_connected' => (bool) $isConnected,
            'ssbhesabfa_result' => $result,
            'ssbhesabfa_token' => $this->getAdminOrderHesabfaToken(),
            'ssbhesabfa_action_url' => htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES, 'UTF-8'),
        ));

        // Do not use __FILE__ here: this code lives inside a trait, and PrestaShop
        // may resolve the template against classes/traits instead of the module root.
        $moduleFile = _PS_MODULE_DIR_ . 'ssbhesabfa/ssbhesabfa.php';
        return $this->display($moduleFile, 'views/templates/hook/admin_order_hesabfa.tpl');
    }
}
