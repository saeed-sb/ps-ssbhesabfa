<?php

trait HesabfaPaymentTrait
{
    public function getInvoiceNote($id_ppp) {
        $note = '';
        $features = PurchaseProcessFeatureValueModel::getProductFeaturesValue($id_ppp);
        foreach ($features as $feature) {
            if ($feature['use_for_invoice_note']) {
                $note .= $feature['name'] . ': ' . $feature['value'] . '
            ';
            }
        }

        return $note;
    }

    public function getPaymentMethodsName()
    {
        $payment_array = array();

        /*
         * Normal PrestaShop payment modules
         */
        $modules_list = Module::getPaymentModules();

        foreach ($modules_list as $module) {
            $module_obj = Module::getInstanceById((int) $module['id_module']);

            if (!Validate::isLoadedObject($module_obj)) {
                continue;
            }

            $moduleName = trim((string) $module_obj->name);
            $paymentName = $this->normalizePaymentName(
                $module_obj->displayName,
                $moduleName
            );

            if (empty($moduleName) || empty($paymentName)) {
                continue;
            }

            $payment_array[] = array(
                'name' => $paymentName,
                'module' => $moduleName,
                'id' => $this->getPaymentConfigName($moduleName, $paymentName),
            );
        }

        /*
         * Compatible with psy_paymenthelper methods
         */
        $paymentHelperModule = Module::getInstanceByName('psy_paymenthelper');

        if (
            Validate::isLoadedObject($paymentHelperModule)
            && method_exists($paymentHelperModule, 'getMethods')
        ) {
            $methods = $paymentHelperModule->getMethods(true);

            foreach ($methods as $key => $class) {
                if (!class_exists($class)) {
                    continue;
                }

                $method = new $class($paymentHelperModule);

                if (!method_exists($method, 'isActive') || !$method->isActive()) {
                    continue;
                }

                $displayName = null;

                $reflection = new ReflectionClass($method);

                if ($reflection->hasProperty('displayName')) {
                    $property = $reflection->getProperty('displayName');
                    $property->setAccessible(true);
                    $displayName = $property->getValue($method);
                }

                $moduleName = trim((string) $paymentHelperModule->name);
                $paymentName = $this->normalizePaymentName($displayName, $moduleName);

                if (empty($moduleName) || empty($paymentName)) {
                    continue;
                }

                $payment_array[] = array(
                    'name' => $paymentName,
                    'module' => $moduleName,
                    'id' => $this->getPaymentConfigName($moduleName, $paymentName),
                );
            }
        }

        return $payment_array;
    }

    private function getPaymentConfigName($moduleName, $paymentName)
    {
        $moduleName = trim((string) $moduleName);
        $paymentName = $this->normalizePaymentName($paymentName, $moduleName);

        return 'SSBHESABFA_PAYMENT_METHOD_' . md5($moduleName . '|' . $paymentName);
    }

    private function normalizePaymentName($paymentName, $moduleName = null)
    {
        $paymentName = trim((string) $paymentName);
        $moduleName = trim((string) $moduleName);

        if ($moduleName === 'psy_paymenthelper') {
            $paymentName = preg_replace('/^payment gateway\s*/i', '', $paymentName);
            $paymentName = trim($paymentName);
        }

        return $paymentName;
    }

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

        if (HesabfaPrestashopRepository::orderHasFreeShippingCartRule($id_order)) {
            $order_total_discount = $this->getOrderPriceInHesabfaDefaultCurrency($order->total_discounts - $order->total_shipping_tax_incl, $id_order);
            $shipping = 0;
        }

        //calculate discount split
        $order_total_products = $this->getOrderPriceInHesabfaDefaultCurrency($order->total_products, $id_order);
        $split = 0;
        if ($order_total_discount > 0) {
            $split = $order_total_discount / $order_total_products;
        }

        //Splitting total discount to each item
        $i = 0;
        $note = array();
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

            // compatibility with ssbserialorder module
            if (!empty($serials) && !empty($product['id_order_detail'])) {
                foreach ($serials as $serial) {
                    if ((int) $serial['id_order_detail'] !== (int) $product['id_order_detail']) {
                        continue;
                    }
            
                    $serialNumber = trim((string) $serial['serial_number']);
            
                    if ($serialNumber === '') {
                        break;
                    }
            
                    // $item['serialNumbers'] = array($serialNumber);
            
                    if (!empty($item['description'])) {
                        $item['description'] .= "\n";
                    } elseif (!empty($item['Description'])) {
                        $item['description'] = $item['Description'] . "\n";
                    } else {
                        $item['description'] = '';
                    }
            
                    $item['description'] .= sprintf(
                        $this->l('Serial number: %s'),
                        $serialNumber
                    );
            
                    // $note[] = sprintf(
                    //     $this->l('Product row %d serial number: %s'),
                    //     ((int) $item['RowNumber']) + 1,
                    //     $serialNumber
                    // );
            
                    break;
                }
            }
            // end compatibility with ssbserialorder module

            //compatibility with Ssbpurchaseprocess module
            if (Module::isInstalled('Ssbpurchaseprocess') && Module::isEnabled('Ssbpurchaseprocess')) {
                require_once (_PS_MODULE_DIR_ . 'ssbpurchaseprocess/ssbpurchaseprocess.php');

                $note[] = Ssbpurchaseprocess::getInvoiceNoteByProductPsID($product['product_id']);
            }
            //end with Ssbpurchaseprocess module

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
            'project' => Configuration::get('SSBHESABFA_INVOICE_PROJECT'),
            'InvoiceItems' => $items,
            'Note' => empty($note) ? '' : implode(' - ', $note)
        );

        $salesmanCode = Configuration::get('SSBHESABFA_INVOICE_SALESMEN');
        if ($salesmanCode != false) {
            $data['SalesmanCode'] = $salesmanCode;
        }

        $invoicePayloadHash = md5(json_encode($data));
        $invoiceOperationKey = $this->buildOperationKey('invoice_save', array($id_order, $orderType, $number ? $number : 'new', $invoicePayloadHash));
        if ($this->getCompletedOperation($invoiceOperationKey)) {
            self::addModuleLog('Skipped duplicate Hesabfa invoice save operation.', 'INFO', null, $orderType == 2 ? 'ReturnOrder' : 'Order', $id_order);
            return true;
        }

        $this->startOperation($invoiceOperationKey, 'invoice_save', $orderType == 2 ? 'ReturnOrder' : 'Order', $id_order);
        $hesabfa = new HesabfaApi();
        $response = $this->normalizeHesabfaResponse($hesabfa->invoiceSave($data), 'invoiceSave', $orderType == 2 ? 'ReturnOrder' : 'Order', $id_order);
        if ($this->isHesabfaSuccess($response)) {
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
            $obj->id_ps_attribute = 0;
            $mappingSaved = false;

            if ($number == null) {
                $mappingSaved = $obj->add();
            } else {
                $obj->id_ssb_hesabfa = $this->getObjectId($orderType == 2 ? 'returnOrder' : 'order', $id_order);
                $obj->id = $obj->id_ssb_hesabfa;
                $mappingSaved = $obj->update();
            }

            if (!$mappingSaved) {
                $msg = 'Hesabfa invoice was saved, but the local invoice mapping could not be stored. Invoice number: ' . (int) $response->Result->Number;
                $this->finishOperation($invoiceOperationKey, 'failed', $msg, (int) $response->Result->Number);
                $this->addFollowUpIssue(
                    'invoice_mapping_save_failed',
                    $msg,
                    $orderType == 2 ? 'ReturnOrder' : 'Order',
                    $id_order,
                    $invoiceOperationKey,
                    'ERROR'
                );
                self::addLegacyLog(
                    $msg,
                    3,
                    'INVOICE_MAPPING_SAVE_FAILED',
                    $orderType == 2 ? 'ReturnOrder' : 'Order',
                    $id_order,
                    true,
                    array('hesabfa_code' => (string) $response->Result->Number)
                );
                return false;
            }

            if ($orderType == 2) {
                $msg = $number == null
                    ? 'Hesabfa return sales invoice was added successfully. Invoice number: ' . $response->Result->Number
                    : 'Hesabfa return sales invoice was updated successfully. Invoice number: ' . $response->Result->Number;
                self::addLegacyLog($msg, 1, null, 'ReturnOrder', $id_order, true, array(
                    'hesabfa_code' => (string) $response->Result->Number,
                ));
            } else {
                $msg = $number == null
                    ? 'Hesabfa invoice was added successfully. Invoice number: ' . $response->Result->Number
                    : 'Hesabfa invoice was updated successfully. Invoice number: ' . $response->Result->Number;
                self::addLegacyLog($msg, 1, null, 'Order', $id_order, true, array(
                    'hesabfa_code' => (string) $response->Result->Number,
                ));
            }

            $this->finishOperation($invoiceOperationKey, 'success', 'Hesabfa invoice save operation completed successfully.', (int)$response->Result->Number);
            return true;
        } else {
            $msg = 'Failed to add or update the Hesabfa invoice. Details: ' . $this->getHesabfaErrorMessage($response);
            $this->finishOperation($invoiceOperationKey, 'failed', $msg, null);
            $this->addFollowUpIssue('invoice_save_failed', $msg, $orderType == 2 ? 'ReturnOrder' : 'Order', $id_order, $invoiceOperationKey, 'ERROR');
            self::addLegacyLog($msg, 2, HesabfaApiResponse::getErrorCode($response), 'Order', $id_order, true);
            return false;
        }
    }

    public function setOrderPayment($id_order)
    {
        if (!isset($id_order) || !(int) $id_order) {
            return false;
        }

        $id_order = (int) $id_order;

        $order = new Order($id_order);

        if (!Validate::isLoadedObject($order)) {
            HesabfaApiResponse::normalize((object) array(
                'Success' => false,
                'ErrorCode' => 'ORDER_NOT_FOUND',
                'ErrorMessage' => 'The PrestaShop order was not found.',
            ));
            return false;
        }

        $hesabfa = new HesabfaApi();
        $number = $this->getInvoiceCodeByOrderId($id_order);

        if (empty($number)) {
            $msg = 'Failed to register Hesabfa invoice payment: invoice number was not found.';
            $failureResponse = HesabfaApiResponse::normalize((object) array(
                'Success' => false,
                'ErrorCode' => 'INVOICE_MAPPING_NOT_FOUND',
                'ErrorMessage' => $msg,
            ));
            self::addLegacyLog($msg, 2, 'INVOICE_MAPPING_NOT_FOUND', 'Order', $id_order, true);
            $this->addFollowUpIssue('invoice_mapping_not_found_for_payment', $msg, 'Order', $id_order, null, 'ERROR');
            return false;
        }

        $payments = OrderPayment::getByOrderReference($order->reference);
        $hasFailure = false;
        $lastFailureResponse = null;

        foreach ($payments as $payment) {
            if ($payment->amount <= 0) {
                continue;
            }

            $paymentConfig = $this->getPaymentConfigByOrderPayment(
                $id_order,
                $payment->payment_method
            );

            if (!$paymentConfig || empty($paymentConfig['configuration_name'])) {
                $msg = 'Failed to register Hesabfa invoice payment: payment method configuration was not found.';
                $lastFailureResponse = HesabfaApiResponse::normalize((object) array(
                    'Success' => false,
                    'ErrorCode' => 'PAYMENT_METHOD_CONFIG_NOT_FOUND',
                    'ErrorMessage' => $msg,
                ));
                $hasFailure = true;
                self::addLegacyLog($msg, 2, 'PAYMENT_METHOD_CONFIG_NOT_FOUND', 'Order', $id_order, true);
                $this->addFollowUpIssue('payment_method_config_not_found', $msg, 'Order', $id_order, null, 'ERROR');
                continue;
            }

            $paymentConfigName = $paymentConfig['configuration_name'];
            $bank_code = isset($paymentConfig['bank_code']) ? $paymentConfig['bank_code'] : false;

            if ($bank_code == -1) {
                continue;
            }

            if ($bank_code === false || $bank_code === null || $bank_code === '') {
                $msg = 'Failed to register Hesabfa invoice payment: bank code is not defined.';
                $lastFailureResponse = HesabfaApiResponse::normalize((object) array(
                    'Success' => false,
                    'ErrorCode' => 'BANK_CODE_NOT_DEFINED',
                    'ErrorMessage' => $msg,
                ));
                $hasFailure = true;
                self::addLegacyLog($msg, 2, 'BANK_CODE_NOT_DEFINED', 'Order', $id_order, true);
                $this->addFollowUpIssue('payment_bank_code_not_defined', $msg, 'Order', $id_order, null, 'ERROR');
                continue;
            }

            if ($payment->transaction_id == '') {
                $payment->transaction_id = 'None';
            }

            $paidAmount = $this->getOrderPriceInHesabfaDefaultCurrency(
                $payment->amount,
                $id_order
            );

            $feeBreakdown = $this->getPaymentFeeBreakdown(
                $paymentConfigName,
                $paidAmount
            );

            /*
             * Main invoice payment:
             * - merchant pays fee: full paid amount + transaction fee
             * - customer pays fee: invoice base amount + transaction fee = 0
             */
            $paymentId = isset($payment->id) ? (int) $payment->id : md5($payment->payment_method . '|' . $payment->transaction_id . '|' . $payment->amount . '|' . $payment->date_add);
            $paymentOperationKey = $this->buildOperationKey('invoice_payment', array($id_order, $number, $paymentId, $payment->transaction_id));
            if ($this->getCompletedOperation($paymentOperationKey)) {
                self::addModuleLog('Skipped duplicate Hesabfa invoice payment operation.', 'INFO', null, 'Order', $id_order);
            } else {
                $this->startOperation($paymentOperationKey, 'invoice_payment', 'Order', $id_order);
                $response = $this->normalizeHesabfaResponse($hesabfa->invoiceSavePayment(
                    $number,
                    array('bankCode' => $bank_code),
                    $payment->date_add,
                    $feeBreakdown['invoice_payment_amount'],
                    $payment->transaction_id,
                    null,
                    $feeBreakdown['transaction_fee'],
                    Configuration::get('SSBHESABFA_INVOICE_PROJECT')
                ), 'invoiceSavePayment', 'Order', $id_order);

                if ($this->isHesabfaSuccess($response)) {
                    $msg = 'Hesabfa invoice payment was registered successfully.';
                    $this->finishOperation($paymentOperationKey, 'success', $msg, $number);
                    self::addModuleLog($msg, 'INFO', null, 'Order', $id_order);
                } else {
                    $msg = 'Failed to register Hesabfa invoice payment. Details: ' . $this->getHesabfaErrorMessage($response);
                    $this->finishOperation($paymentOperationKey, 'failed', $msg, null);
                    $this->addFollowUpIssue('invoice_payment_failed', $msg, 'Order', $id_order, $paymentOperationKey, 'ERROR');
                    $hasFailure = true;
                    $lastFailureResponse = $response;
                    continue;
                }
            }

            /*
             * Customer paid extra and merchant has profit:
             * Create accounting document:
             *   Debit: Bank
             *   Credit: Income account path
             */
            if (
                isset($feeBreakdown['income_amount'])
                && $feeBreakdown['income_amount'] > 0
                && !empty($feeBreakdown['income_account_path'])
            ) {
                $description = $this->renderTemplateText(Configuration::get('SSBHESABFA_FEE_INCOME_DOCUMENT_DESCRIPTION_TEMPLATE'), array(
                    'order_id' => (int) $id_order,
                    'order_reference' => (string) $order->reference,
                    'invoice_number' => (int) $number,
                    'transaction_number' => $payment->transaction_id,
                ));

                $incomeOperationKey = $this->buildOperationKey('payment_fee_income_document', array($id_order, $number, $paymentId, $payment->transaction_id));
                if ($this->getCompletedOperation($incomeOperationKey)) {
                    self::addModuleLog('Skipped duplicate Hesabfa payment fee income document operation.', 'INFO', null, 'Order', $id_order);
                } else {
                    $this->startOperation($incomeOperationKey, 'payment_fee_income_document', 'Order', $id_order);
                    $incomeResponse = $this->normalizeHesabfaResponse($this->savePaymentFeeIncomeDocument(
                        $bank_code,
                        $feeBreakdown['income_account_path'],
                        $feeBreakdown['income_contact_code'],
                        $payment->date_add,
                        $feeBreakdown['income_amount'],
                        $description,
                        Configuration::get('SSBHESABFA_INVOICE_PROJECT')
                    ), 'savePaymentFeeIncomeDocument', 'Order', $id_order);

                    if ($this->isHesabfaSuccess($incomeResponse)) {
                        $msg = 'Hesabfa payment fee income document was added successfully.';
                        $this->finishOperation($incomeOperationKey, 'success', $msg, null);
                        self::addModuleLog($msg, 'INFO', null, 'Order', $id_order);
                    } else {
                        $msg = 'Failed to add the Hesabfa payment fee income document. Details: ' . $this->getHesabfaErrorMessage($incomeResponse);
                        $this->finishOperation($incomeOperationKey, 'failed', $msg, null);
                        $this->addFollowUpIssue('payment_fee_income_document_failed', $msg, 'Order', $id_order, $incomeOperationKey, 'ERROR');
                        $hasFailure = true;
                        $lastFailureResponse = $incomeResponse;
                    }
                }
            } elseif (
                isset($feeBreakdown['income_amount'])
                && $feeBreakdown['income_amount'] > 0
                && empty($feeBreakdown['income_account_path'])
            ) {
                $msg = 'Payment fee income was detected, but the income account path is not defined.';
                $lastFailureResponse = HesabfaApiResponse::normalize((object) array(
                    'Success' => false,
                    'ErrorCode' => 'FEE_INCOME_ACCOUNT_PATH_MISSING',
                    'ErrorMessage' => $msg,
                ));
                $hasFailure = true;
                self::addLegacyLog($msg, 2, 'FEE_INCOME_ACCOUNT_PATH_MISSING', 'Order', $id_order, true);
                $this->addFollowUpIssue('payment_fee_income_account_path_missing', $msg, 'Order', $id_order, null, 'ERROR');
            }
        }

        if ($hasFailure) {
            if ($lastFailureResponse !== null) {
                HesabfaApiResponse::normalize($lastFailureResponse);
            }
            return false;
        }

        return true;
    }

    private function getManualGatewayPaymentMethodOptions()
    {
        $options = array();

        foreach ($this->getPaymentMethodsName() as $item) {
            $paymentConfigName = $item['id'];

            if (Configuration::get($paymentConfigName . '_FEE_TYPE') !== 'percent') {
                continue;
            }

            $bankCode = Configuration::get($paymentConfigName);

            if ($bankCode === false || $bankCode === null || $bankCode === '' || (int) $bankCode <= 0) {
                continue;
            }

            $feePercent = (float) Configuration::get($paymentConfigName . '_FEE_PERCENT');
            $customerChargePercent = (float) Configuration::get($paymentConfigName . '_CUSTOMER_CHARGE_PERCENT');

            $label = $item['name'] . ' - ' . $this->l('Fee percent') . ': ' . $feePercent . '%';

            if ($customerChargePercent > 0) {
                $label .= ' - ' . $this->l('Customer extra charge percent') . ': ' . $customerChargePercent . '%';
            }

            $options[] = array(
                'id_option' => $paymentConfigName,
                'name' => $label,
            );
        }

        return $options;
    }

    private function normalizeManualAmount($amount)
    {
        $amount = trim((string) $amount);
        $amount = str_replace(array(',', '\xD9\xAC', '\xD8\x8C', ' '), array('', '', '', ''), $amount);
        $amount = str_replace('\xD9\xAB', '.', $amount);

        return (float) $amount;
    }

    private function processManualGatewayPayment()
    {
        $paymentConfigName = trim((string) Tools::getValue('SSBHESABFA_MANUAL_PAYMENT_METHOD'));
        $invoiceNumber = (int) Tools::getValue('SSBHESABFA_MANUAL_INVOICE_NUMBER');
        $manualPaidAmount = $this->normalizeManualAmount(Tools::getValue('SSBHESABFA_MANUAL_GATEWAY_PAID_AMOUNT'));
        $transactionNumber = trim((string) Tools::getValue('SSBHESABFA_MANUAL_TRANSACTION_NUMBER'));
        $orderReference = trim((string) Tools::getValue('SSBHESABFA_MANUAL_ORDER_REFERENCE'));
        $paymentDate = trim((string) Tools::getValue('SSBHESABFA_MANUAL_PAYMENT_DATE', date('Y-m-d')));

        if (empty($paymentConfigName)) {
            return array(
                'success' => false,
                'message' => $this->l('Please select a payment method.'),
            );
        }

        if ($invoiceNumber <= 0) {
            return array(
                'success' => false,
                'message' => $this->l('Please enter a valid Hesabfa invoice number.'),
            );
        }

        if ($manualPaidAmount <= 0) {
            return array(
                'success' => false,
                'message' => $this->l('Please enter a valid total gateway paid amount.'),
            );
        }

        if (!Validate::isDateFormat($paymentDate)) {
            return array(
                'success' => false,
                'message' => $this->l('Please enter a valid payment date.'),
            );
        }

        if (Configuration::get($paymentConfigName . '_FEE_TYPE') !== 'percent') {
            return array(
                'success' => false,
                'message' => $this->l('Selected payment method must use Percent of payment amount fee type.'),
            );
        }

        $bankCode = Configuration::get($paymentConfigName);

        if ($bankCode === false || $bankCode === null || $bankCode === '' || (int) $bankCode <= 0) {
            return array(
                'success' => false,
                'message' => $this->l('Selected payment method does not have a valid Hesabfa bank account.'),
            );
        }

        $paidAmount = round(self::getPriceInHesabfaDefaultCurrency($manualPaidAmount));
        $feeBreakdown = $this->getPaymentFeeBreakdown($paymentConfigName, $paidAmount);

        if ($feeBreakdown['invoice_payment_amount'] <= 0) {
            return array(
                'success' => false,
                'message' => $this->l('Calculated invoice payment amount is invalid.'),
            );
        }

        if ($transactionNumber === '') {
            $transactionNumber = 'Manual-' . $invoiceNumber . '-' . date('YmdHis');
        }

        $description = $this->renderTemplateText(Configuration::get('SSBHESABFA_MANUAL_PAYMENT_DESCRIPTION_TEMPLATE'), array(
            'order_reference' => $orderReference,
            'invoice_number' => (int) $invoiceNumber,
            'transaction_number' => $transactionNumber,
        ));

        $hesabfa = new HesabfaApi();
        $manualPaymentOperationKey = $this->buildOperationKey('manual_invoice_payment', array($invoiceNumber, $paymentConfigName, $transactionNumber));
        if (!$this->getCompletedOperation($manualPaymentOperationKey)) {
            $this->startOperation($manualPaymentOperationKey, 'manual_invoice_payment', 'Invoice', $invoiceNumber);
            $response = $this->normalizeHesabfaResponse($hesabfa->invoiceSavePayment(
                $invoiceNumber,
                array('bankCode' => $bankCode),
                $paymentDate,
                $feeBreakdown['invoice_payment_amount'],
                $transactionNumber,
                $description,
                $feeBreakdown['transaction_fee'],
                Configuration::get('SSBHESABFA_INVOICE_PROJECT')
            ), 'manualInvoiceSavePayment', 'Invoice', $invoiceNumber);

            if (!$this->isHesabfaSuccess($response)) {
                $msg = 'Failed to register the manual Hesabfa invoice payment. Invoice: ' . $invoiceNumber . '. Details: ' . $this->getHesabfaErrorMessage($response);
                $this->finishOperation($manualPaymentOperationKey, 'failed', $msg, null);
                $this->addFollowUpIssue('manual_invoice_payment_failed', $msg, 'Invoice', $invoiceNumber, $manualPaymentOperationKey, 'ERROR');

                return array(
                    'success' => false,
                    'message' => $this->l('Failed to register Hesabfa invoice payment. Details: ') . $this->getHesabfaErrorMessage($response),
                );
            }
            $this->finishOperation($manualPaymentOperationKey, 'success', 'Manual Hesabfa invoice payment was registered successfully.', $invoiceNumber);
        } else {
            self::addModuleLog('Skipped duplicate manual Hesabfa invoice payment operation.', 'INFO', null, 'Invoice', $invoiceNumber);
        }

        $incomeDocumentMessage = '';

        if (
            isset($feeBreakdown['income_amount'])
            && $feeBreakdown['income_amount'] > 0
            && !empty($feeBreakdown['income_account_path'])
        ) {
            $incomeDescription = $this->renderTemplateText(Configuration::get('SSBHESABFA_MANUAL_FEE_INCOME_DOCUMENT_DESCRIPTION_TEMPLATE'), array(
                'order_reference' => $orderReference,
                'invoice_number' => (int) $invoiceNumber,
                'transaction_number' => $transactionNumber,
            ));
            $manualIncomeOperationKey = $this->buildOperationKey('manual_payment_fee_income_document', array($invoiceNumber, $paymentConfigName, $transactionNumber));
            if (!$this->getCompletedOperation($manualIncomeOperationKey)) {
                $this->startOperation($manualIncomeOperationKey, 'manual_payment_fee_income_document', 'Invoice', $invoiceNumber);
                $incomeResponse = $this->normalizeHesabfaResponse($this->savePaymentFeeIncomeDocument(
                    $bankCode,
                    $feeBreakdown['income_account_path'],
                    $feeBreakdown['income_contact_code'],
                    $paymentDate,
                    $feeBreakdown['income_amount'],
                    $incomeDescription,
                    Configuration::get('SSBHESABFA_INVOICE_PROJECT')
                ), 'manualSavePaymentFeeIncomeDocument', 'Invoice', $invoiceNumber);

                if (!$this->isHesabfaSuccess($incomeResponse)) {
                    $msg = 'Manual invoice payment was registered, but the payment fee income document failed. Invoice: ' . $invoiceNumber . '. Details: ' . $this->getHesabfaErrorMessage($incomeResponse);
                    $this->finishOperation($manualIncomeOperationKey, 'failed', $msg, null);
                    $this->addFollowUpIssue('manual_payment_fee_income_document_failed', $msg, 'Invoice', $invoiceNumber, $manualIncomeOperationKey, 'ERROR');

                    return array(
                        'success' => false,
                        'message' => $this->l('Invoice payment registered, but income document failed. Details: ') . $this->getHesabfaErrorMessage($incomeResponse),
                    );
                }
                $this->finishOperation($manualIncomeOperationKey, 'success', 'Manual payment fee income document was registered successfully.', null);
            } else {
                self::addModuleLog('Skipped duplicate manual payment fee income document operation.', 'INFO', null, 'Invoice', $invoiceNumber);
            }

            $incomeDocumentMessage = ' ' . $this->l('Income document registered successfully.');
        } elseif (
            isset($feeBreakdown['income_amount'])
            && $feeBreakdown['income_amount'] > 0
            && empty($feeBreakdown['income_account_path'])
        ) {
            $msg = 'Manual gateway payment income was detected, but the income account path is not defined. Invoice: ' . $invoiceNumber;
            self::addLegacyLog($msg, 2, null, null, null, true);

            return array(
                'success' => false,
                'message' => $this->l('Invoice payment registered, but income account path is not defined for selected payment method.'),
            );
        }

        $logMessage = 'Manual gateway payment was registered successfully. Invoice: ' . $invoiceNumber
            . '. Gateway paid amount in PrestaShop currency: ' . $manualPaidAmount
            . '. Gateway paid amount in Hesabfa currency: ' . $paidAmount
            . '. Invoice payment amount: ' . $feeBreakdown['invoice_payment_amount']
            . '. Income amount: ' . $feeBreakdown['income_amount']
            . '. Transaction number: ' . $transactionNumber;
        self::addLegacyLog($logMessage, 1, null, null, null, true);

        return array(
            'success' => true,
            'message' => $this->l('Invoice payment registered successfully.') . $incomeDocumentMessage,
        );
    }

    private function savePaymentFeeIncomeDocument($bankCode, $incomeAccountPath, $contactCode, $date, $amount, $description = null, $project = null)
    {
        $bankCode = (int) $bankCode;
        $amount = round((float) $amount);

        $bankAccountPath = self::HESABFA_DEFAULT_BANK_ACCOUNT_PATH;
        $incomeAccountPath = trim((string) $incomeAccountPath);
        $contactCode = trim((string) $contactCode);

        if ($bankCode <= 0 || $amount <= 0 || empty($incomeAccountPath)) {
            return (object) array(
                'Success' => false,
                'ErrorCode' => 'INVALID_FEE_INCOME_DOCUMENT',
                'ErrorMessage' => 'Bank code, income account path or amount is invalid.',
            );
        }

        if (empty($description)) {
            $description = (string) Configuration::get('SSBHESABFA_FEE_INCOME_DOCUMENT_DESCRIPTION_TEMPLATE');
        }

        $defaultCurrency = new Currency(Configuration::get('SSBHESABFA_HESABFA_DEFAULT_CURRENCY'));
        $currency = $defaultCurrency->iso_code;

        $document = array(
            'number' => 0,
            'reference' => 0,
            'date' => $date,
            'description' => $description,
            'project' => $project,
            'debit' => $amount,
            'credit' => $amount,
            'status' => 1,
            'transactions' => array(
                array(
                    // Debit: bank
                    'accountPath' => $bankAccountPath,
                    'description' => $description,
                    'info' => '',
                    'amount' => $amount,
                    'currencyAmount' => $amount,
                    'currency' => $currency,
                    'type' => 0,
                    'productCode' => '',
                    'bankCode' => $bankCode,
                    'cashCode' => '',
                    'pettyCashCode' => '',
                ),
                array(
                    // Credit: income
                    'accountPath' => $incomeAccountPath,
                    'description' => $description,
                    'info' => '',
                    'amount' => $amount,
                    'currencyAmount' => $amount,
                    'currency' => $currency,
                    'type' => 1,
                    'productCode' => '',
                    'bankCode' => '',
                    'cashCode' => '',
                    'pettyCashCode' => '',
                ),
            ),
        );

        if (!empty($contactCode)) {
            $document['transactions'][1]['contactCode'] = $contactCode;
        }

        $hesabfa = new HesabfaApi();

        return $hesabfa->documentSave($document);
    }
    private function getPaymentTransactionFee($paymentConfigName, $amount)
    {
        return HesabfaPaymentFeeService::getTransactionFee($paymentConfigName,$amount);
    }
    private function getShaparakPurchaseTransactionFee($amount)
    {
        return HesabfaPaymentFeeService::getShaparakFee($amount);
    }
    private function getPercentTransactionFee($paymentConfigName, $amount)
    {
        return HesabfaPaymentFeeService::getPercentFee($paymentConfigName,$amount);
    }
    private function getFixedTransactionFee($paymentConfigName)
    {
        return HesabfaPaymentFeeService::getFixedFee($paymentConfigName);
    }
    private function getPaymentFeeBreakdown($paymentConfigName, $paidAmount)
    {
        return HesabfaPaymentFeeService::getBreakdown($paymentConfigName,$paidAmount);
    }


    public function getPaymentConfigByOrderPayment($id_order, $paymentMethod)
    {
        $id_order = (int) $id_order;
        $paymentMethod = trim((string) $paymentMethod);

        if (!$id_order || empty($paymentMethod)) {
            return false;
        }

        $moduleName = trim((string) HesabfaPrestashopRepository::getOrderModuleName($id_order));

        if ($moduleName === '') {
            return false;
        }

        $paymentName = $this->normalizePaymentName(
            $paymentMethod,
            $moduleName
        );

        $configurationName = $this->getPaymentConfigName(
            $moduleName,
            $paymentName
        );

        $bankCode = Configuration::get($configurationName);

        /*
         * psy_paymenthelper may save a short payment title on the order
         * (for example "تارا") while exposing a longer display title in
         * the module settings (for example "درگاه پرداخت تارا").
         * Keep the existing configuration keys intact and resolve the
         * matching configured method by a normalized lookup name.
         */
        if (
            $moduleName === 'psy_paymenthelper'
            && ($bankCode === false || $bankCode === null || $bankCode === '')
        ) {
            $lookupName = $this->normalizePaymentLookupName($paymentName, $moduleName);

            foreach ($this->getPaymentMethodsName() as $configuredMethod) {
                if (
                    empty($configuredMethod['module'])
                    || (string) $configuredMethod['module'] !== $moduleName
                    || empty($configuredMethod['id'])
                ) {
                    continue;
                }

                $configuredLookupName = $this->normalizePaymentLookupName(
                    isset($configuredMethod['name']) ? $configuredMethod['name'] : '',
                    $moduleName
                );

                if ($lookupName === '' || $configuredLookupName !== $lookupName) {
                    continue;
                }

                $configuredBankCode = Configuration::get($configuredMethod['id']);
                if (
                    $configuredBankCode === false
                    || $configuredBankCode === null
                    || $configuredBankCode === ''
                ) {
                    continue;
                }

                $configurationName = (string) $configuredMethod['id'];
                $bankCode = $configuredBankCode;
                $paymentName = isset($configuredMethod['name'])
                    ? (string) $configuredMethod['name']
                    : $paymentName;
                break;
            }
        }

        return array(
            'configuration_name' => $configurationName,
            'bank_code' => $bankCode,
            'module' => $moduleName,
            'payment' => $paymentName,
        );
    }

    private function normalizePaymentLookupName($paymentName, $moduleName = null)
    {
        $paymentName = $this->normalizePaymentName($paymentName, $moduleName);
        $paymentName = preg_replace('/^درگاه\s+پرداخت\s+/u', '', $paymentName);
        $paymentName = preg_replace('/\s+/u', ' ', trim((string) $paymentName));

        return function_exists('mb_strtolower')
            ? mb_strtolower($paymentName, 'UTF-8')
            : strtolower($paymentName);
    }

    public function getInvoiceCodeByOrderId($id_order)
    {
        return HesabfaMappingRepository::getHesabfaCode('order', $id_order);
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
}
