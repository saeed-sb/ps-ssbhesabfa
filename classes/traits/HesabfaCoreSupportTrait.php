<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

trait HesabfaCoreSupportTrait
{
    public static function getLogLevelFromSeverity($severity)
    {
        return HesabfaLogService::getLogLevelFromSeverity($severity);
    }

    public static function getSeverityFromLogLevel($level)
    {
        return HesabfaLogService::getSeverityFromLogLevel($level);
    }

    public static function addLegacyLog($message, $severity = 1, $errorCode = null, $objectType = null, $objectId = null, $allowDuplicate = false, array $options = array())
    {
        self::addModuleLog($message, $severity, $errorCode, $objectType, $objectId, $options);
        return true;
    }

    public static function addModuleLog($message, $severity = 1, $errorCode = null, $objectType = null, $objectId = null, array $options = array())
    {
        return HesabfaLogService::addModuleLog($message, $severity, $errorCode, $objectType, $objectId, $options);
    }

    public static function normalizeLogMessage($message)
    {
        return HesabfaTextHelper::normalizeLogMessage($message);
    }



    public static function getObjectId($type, $id_ps, $id_ps_attribute = 0)
    {
        return HesabfaMappingRepository::getObjectRowId($type, $id_ps, $id_ps_attribute);
    }

    public static function getObjectIdByCode($type, $id_hesabfa)
    {
        return HesabfaMappingRepository::getObjectRowIdByCode($type, $id_hesabfa);
    }

    public static function getProductAttributesObjectId($id_ps)
    {
        return HesabfaMappingRepository::getProductAttributeRowIds($id_ps);
    }

    public static function getPriceInHesabfaDefaultCurrency($price)
    {
        if (!isset($price)) {
            return false;
        }

        $currency = new Currency(Configuration::get('SSBHESABFA_HESABFA_DEFAULT_CURRENCY'));
        if (!Validate::isLoadedObject($currency) || (float) $currency->conversion_rate == 0.0) {
            return (float) $price;
        }

        return (float) $price * (float) $currency->conversion_rate;
    }

    public static function getPriceInPrestashopDefaultCurrency($price)
    {
        if (!isset($price)) {
            return false;
        }

        $currency = new Currency(Configuration::get('SSBHESABFA_HESABFA_DEFAULT_CURRENCY'));
        if (!Validate::isLoadedObject($currency) || (float) $currency->conversion_rate == 0.0) {
            return (float) $price;
        }

        return (float) $price / (float) $currency->conversion_rate;
    }

    private function renderTemplateText($template, array $vars)
    {
        return HesabfaTextHelper::renderTemplate($template, $vars);
    }

    protected function normalizeHesabfaResponse($response, $context = null, $objectType = null, $objectId = null)
    {
        $normalized = HesabfaApiResponse::normalize($response);
        if ($context !== null && !HesabfaApiResponse::isSuccess($normalized)) {
            self::addModuleLog($context . ': ' . HesabfaApiResponse::getErrorMessage($normalized), 'ERROR', HesabfaApiResponse::getErrorCode($normalized), $objectType, $objectId);
        }
        return $normalized;
    }

    protected function isHesabfaSuccess($response)
    {
        return HesabfaApiResponse::isSuccess($response);
    }

    protected function getHesabfaErrorMessage($response)
    {
        return HesabfaApiResponse::getErrorMessage($response);
    }

    protected function buildOperationKey($type, array $parts)
    {
        $clean = array();
        foreach ($parts as $part) { $clean[] = preg_replace('/[^A-Za-z0-9_.:-]/', '_', (string) $part); }
        return substr($type . ':' . implode(':', $clean), 0, 190);
    }

    protected function getCompletedOperation($operationKey)
    {
        return HesabfaOperationRepository::getSuccessful($operationKey);
    }

    protected function getOperationByKey($operationKey)
    {
        return HesabfaOperationRepository::getByKey($operationKey);
    }

    protected function startOperation($operationKey, $operationType, $objectType = null, $objectId = null)
    {
        return HesabfaOperationRepository::start($operationKey, $operationType, $objectType, $objectId);
    }

    protected function finishOperation($operationKey, $status, $message = null, $externalReference = null)
    {
        $result = HesabfaOperationRepository::finish($operationKey, $status, $message, $externalReference);
        if ($status === 'success') {
            HesabfaIssueRepository::resolveByOperationKey($operationKey);
        }
        return $result;
    }

    protected function addFollowUpIssue($issueType, $message, $objectType = null, $objectId = null, $operationKey = null, $severity = 'ERROR')
    {
        $level = self::getLogLevelFromSeverity($severity);
        HesabfaIssueRepository::add($issueType, $level, $message, $objectType, $objectId, $operationKey);
        self::addModuleLog($message, $level, null, $objectType, $objectId);
    }

    public function isHesabfaSyncEnabled()
    {
        return (bool) Configuration::get('SSBHESABFA_SYNC_ENABLED');
    }

    public function isHesabfaApiConfigured()
    {
        $apiKey = trim((string) Configuration::get('SSBHESABFA_ACCOUNT_API'));
        $loginToken = trim((string) Configuration::get('SSBHESABFA_ACCOUNT_TOKEN'));
        $userId = trim((string) Configuration::get('SSBHESABFA_ACCOUNT_USERNAME'));
        $password = trim((string) Configuration::get('SSBHESABFA_ACCOUNT_PASSWORD'));

        return $apiKey !== '' && ($loginToken !== '' || ($userId !== '' && $password !== ''));
    }

    public function canQueueHesabfaSync()
    {
        return $this->isHesabfaSyncEnabled() && $this->isHesabfaApiConfigured();
    }

    protected function addProductMappingNotices(array $result)
    {
        if (empty($result['messages']) && empty($result['errors'])) {
            return;
        }
        $payload = array(
            'messages' => isset($result['messages']) ? array_values($result['messages']) : array(),
            'errors' => isset($result['errors']) ? array_values($result['errors']) : array(),
        );
        $this->context->cookie->ssbhesabfa_product_mapping_notices = json_encode($payload);
        $this->context->cookie->write();
    }

    protected function consumeProductMappingNotices()
    {
        $notices = array('messages' => array(), 'errors' => array());
        if (!empty($this->context->cookie->ssbhesabfa_product_mapping_notices)) {
            $decoded = json_decode($this->context->cookie->ssbhesabfa_product_mapping_notices, true);
            if (is_array($decoded)) {
                $notices['messages'] = !empty($decoded['messages']) && is_array($decoded['messages']) ? $decoded['messages'] : array();
                $notices['errors'] = !empty($decoded['errors']) && is_array($decoded['errors']) ? $decoded['errors'] : array();
            }
            unset($this->context->cookie->ssbhesabfa_product_mapping_notices);
            $this->context->cookie->write();
        }
        return $notices;
    }

    public function renderAdminControllerContent($section)
    {
        if (Configuration::get('SSBHESABFA_LIVE_MODE') != 1 && $section !== 'Settings' && $section !== 'InternalApi' && $section !== 'Queue') {
            $section = 'Settings';
        }

        $_GET['ssb_admin_section'] = $section;
        $_GET['form_tab'] = $section;
        return $this->getContent();
    }

    protected function employeeCanAccessAdminController($controller)
    {
        $idTab = (int) Tab::getIdFromClassName($controller);
        if (!$idTab || !isset($this->context->employee) || !Validate::isLoadedObject($this->context->employee)) {
            return true;
        }

        try {
            if (class_exists('Profile')) {
                $access = Profile::getProfileAccess((int) $this->context->employee->id_profile, $idTab);
                if (is_array($access) && array_key_exists('view', $access)) {
                    return (bool) $access['view'];
                }
            }
            if (method_exists($this->context->employee, 'can')) {
                return (bool) $this->context->employee->can('view', $controller);
            }
        } catch (Exception $e) {
            return true;
        }

        return false;
    }

    protected function getAdminControllerMap()
    {
        return array(
            'Dashboard' => 'AdminSsbHesabfaDashboard',
            'Settings' => 'AdminSsbHesabfaSettings',
            'Payments' => 'AdminSsbHesabfaPayments',
            'ManualPayment' => 'AdminSsbHesabfaManualPayment',
            'Sync' => 'AdminSsbHesabfaSync',
            'Queue' => 'AdminSsbHesabfaQueue',
            'InternalApi' => 'AdminSsbHesabfaInternalApi',
            'Logs' => 'AdminSsbHesabfaLogs',
        );
    }

    protected function getControllerBySection($section)
    {
        $map = $this->getAdminControllerMap();
        return isset($map[$section]) ? $map[$section] : $map['Dashboard'];
    }

    protected function getControllerByForm($form)
    {
        $map = array(
            'Config' => 'AdminSsbHesabfaSettings',
            'Item' => 'AdminSsbHesabfaSettings',
            'Contact' => 'AdminSsbHesabfaSettings',
            'Invoice' => 'AdminSsbHesabfaSettings',
            'AccountingText' => 'AdminSsbHesabfaSettings',
            'Bank' => 'AdminSsbHesabfaPayments',
            'ManualGatewayPayment' => 'AdminSsbHesabfaManualPayment',
        );

        return isset($map[$form]) ? $map[$form] : 'AdminSsbHesabfaDashboard';
    }

    protected function getAdminSectionUrl($section)
    {
        return $this->context->link->getAdminLink($this->getControllerBySection($section));
    }

    protected function getCachedApiResponse($cacheKey, $methodName, $ttl = self::HESABFA_CACHE_TTL, $forceRefresh = false)
    {
        $cacheKey = 'SSBHESABFA_CACHE_' . strtoupper($cacheKey);

        if (!$forceRefresh) {
            $cached = Configuration::get($cacheKey);
            if (!empty($cached)) {
                $payload = json_decode($cached);
                if (is_object($payload) && isset($payload->time) && isset($payload->response)) {
                    if ((time() - (int) $payload->time) <= (int) $ttl) {
                        return $payload->response;
                    }
                }
            }
        }

        if (!Configuration::get('SSBHESABFA_LIVE_MODE')) {
            return (object) array('Success' => false, 'ErrorCode' => 'NOT_CONNECTED', 'ErrorMessage' => 'Hesabfa API is not connected.');
        }

        try {
            $hesabfaApi = new HesabfaApi();
            if (!method_exists($hesabfaApi, $methodName)) {
                return (object) array('Success' => false, 'ErrorCode' => 'INVALID_CACHE_METHOD', 'ErrorMessage' => 'Invalid Hesabfa cache method.');
            }

            $response = $hesabfaApi->$methodName();
            if (is_object($response) && isset($response->Success) && $response->Success) {
                Configuration::updateValue($cacheKey, json_encode(array(
                    'time' => time(),
                    'response' => $response,
                )));
            }

            return $response;
        } catch (Exception $e) {
            self::addLegacyLog('Failed to refresh Hesabfa cached data. Method: ' . $methodName . '. Details: ' . $e->getMessage(), 2, 'CACHE_REFRESH_FAILED', 'System', null, true);
            return (object) array('Success' => false, 'ErrorCode' => 'CACHE_REFRESH_FAILED', 'ErrorMessage' => $e->getMessage());
        }
    }

    protected function formatAdminDate($date, $full = true)
    {
        return HesabfaDateHelper::formatAdminDate($date, $full);
    }

    protected function formatAdminTime($date)
    {
        return HesabfaDateHelper::formatAdminTime($date);
    }

    public function formatAdminDateTimePublic($date)
    {
        return $this->formatAdminDateTime($date);
    }

    protected function formatAdminDateTime($date)
    {
        $datePart = HesabfaDateHelper::formatAdminDate($date, false);
        $timePart = HesabfaDateHelper::formatAdminTime($date);
        return trim($datePart . ' ' . $timePart);
    }

    protected function getCachedBanks()
    {
        return $this->getCachedApiResponse('BANKS', 'settingGetBanks');
    }

    protected function getCachedSalesmen()
    {
        return $this->getCachedApiResponse('SALESMEN', 'settingGetSalesmen');
    }

    protected function getCachedProjects()
    {
        return $this->getCachedApiResponse('PROJECTS', 'settingGetProjects');
    }

    protected function getCachedFiscalYear()
    {
        return $this->getCachedApiResponse('FISCAL_YEAR', 'settingGetFiscalYear');
    }

    protected function getCachedBusinessInfo()
    {
        return $this->getCachedApiResponse('BUSINESS_INFO', 'settingGetBusinessInfo');
    }

    protected function getHesabfaBusinessHeaderInfo()
    {
        if (!Configuration::get('SSBHESABFA_LIVE_MODE')) {
            return array();
        }

        $response = $this->getCachedBusinessInfo();
        if (!is_object($response) || empty($response->Success) || !isset($response->Result) || !is_object($response->Result)) {
            return array();
        }

        $result = $response->Result;
        return array(
            'name' => isset($result->Name) ? (string) $result->Name : '',
            'legal_name' => isset($result->LegalName) ? (string) $result->LegalName : '',
            'calendar' => isset($result->Calendar) ? (string) $result->Calendar : '',
            'currency' => isset($result->Currency) ? (string) $result->Currency : '',
            'subscription' => isset($result->Subscription) ? (string) $result->Subscription : '',
            'credit' => isset($result->Credit) ? (string) $result->Credit : '',
            'expire_date' => isset($result->ExpireDate) ? $this->formatAdminDate((string) $result->ExpireDate, false) : '',
            'expire_date_raw' => isset($result->ExpireDate) ? (string) $result->ExpireDate : '',
        );
    }

    protected function getActiveAdminSection()
    {
        $activeSection = Tools::getValue('ssb_admin_section', Tools::getValue('form_tab', 'Dashboard'));
        $legacyMap = array(
            'Home' => 'Dashboard',
            'Config' => 'Settings',
            'Item' => 'Settings',
            'Contact' => 'Settings',
            'Invoice' => 'Settings',
            'AccountingText' => 'Settings',
            'Bank' => 'Payments',
            'ManualGatewayPayment' => 'ManualPayment',
            'Export' => 'Sync',
            'Queue' => 'Queue',
            'InternalApi' => 'InternalApi',
        );

        if (isset($legacyMap[$activeSection])) {
            $activeSection = $legacyMap[$activeSection];
        }

        if (Configuration::get('SSBHESABFA_LIVE_MODE') != 1 && $activeSection !== 'Settings' && $activeSection !== 'InternalApi' && $activeSection !== 'Queue') {
            $activeSection = 'Settings';
        }

        return $activeSection;
    }

    protected function getFormsForSection($section)
    {
        if ($section === 'Settings') {
            $forms = array('Config');
            if (Configuration::get('SSBHESABFA_LIVE_MODE') == 1) {
                $forms = array_merge($forms, array('Item', 'Contact', 'Invoice', 'AccountingText'));
            }
            return $forms;
        }

        if ($section === 'Payments') {
            return array('Bank');
        }

        if ($section === 'ManualPayment') {
            return array('ManualGatewayPayment');
        }

        return array();
    }

    public function getContent()
    {
        // $orders = array(29012);
        
        // foreach ($orders as $id_order) {
        //     $this->setOrderPayment($id_order);
        // }
                    
                    
        if (!extension_loaded('curl')) {
            return $this->displayError($this->l('cURL is not enabled. You should enable it before using this module.'));
        }


        $output = '';
        $output .= $this->getBankFormStyle();

        //show error if store installed in local
        $shop_domain = Configuration::get('PS_SHOP_DOMAIN');
        if ($shop_domain === '127.0.0.1' || $shop_domain === 'localhost') {
            $output .= $this->displayWarning($this->l('Your store is installed on localhost, Hesabfa changes will not be applied to the store.'));
        }


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
        } elseif (((bool)Tools::isSubmit('submitSsbhesabfaModuleBank')) == true) {
            $this->setConfigFormsValues('Bank');
            $output .= $this->displayConfirmation($this->l('Payments Methods Setting updated.'));
        } elseif (((bool)Tools::isSubmit('submitSsbhesabfaModuleManualGatewayPayment')) == true) {
            if (Configuration::get('SSBHESABFA_LIVE_MODE')) {
                $manualPaymentResult = $this->processManualGatewayPayment();

                if ($manualPaymentResult['success']) {
                    $output .= $this->displayConfirmation($manualPaymentResult['message']);
                } else {
                    $output .= $this->displayError($manualPaymentResult['message']);
                }
            } else {
                $output .= $this->displayWarning($this->l('The API Connection must be connected before registering manual gateway payments.'));
            }
        } elseif (((bool)Tools::isSubmit('submitSsbhesabfaModuleAccountingText')) == true) {
            $this->setConfigFormsValues('AccountingText');
            $output .= $this->displayConfirmation($this->l('Accounting text settings updated.'));
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
                include(_PS_MODULE_DIR_ . 'ssbhesabfa/classes/HesabfaWebhook.php');
                new HesabfaWebhook();
                $output .= $this->displayConfirmation($this->l('Changes synced with Hesabfa successfully.'));
            } else {
                $output .= $this->displayWarning($this->l('The API Connection must be connected before sync Changes.'));
            }
        } elseif (((bool)Tools::isSubmit('submitSsbhesabfaRepairItemCodes')) == true) {
            if (Configuration::get('SSBHESABFA_LIVE_MODE')) {
                $mismatches = $this->getHesabfaItemCodeMismatches();
                $output .= $this->displayConfirmation($this->l('Item code mismatch scan completed. Found: ') . count($mismatches));
            } else {
                $output .= $this->displayWarning($this->l('The API Connection must be connected before scanning item code mismatches.'));
            }
        } elseif (((bool)Tools::isSubmit('submitSsbhesabfaApplyItemCodeMismatch')) == true) {
            if (Configuration::get('SSBHESABFA_LIVE_MODE')) {
                $applyResult = $this->applyHesabfaItemCodeMismatch((int) Tools::getValue('id_product'), (int) Tools::getValue('id_product_attribute'), (int) Tools::getValue('new_hesabfa_code'));
                if ($applyResult['success']) {
                    $output .= $this->displayConfirmation($applyResult['message']);
                } else {
                    $output .= $this->displayError($applyResult['message']);
                }
            } else {
                $output .= $this->displayWarning($this->l('The API Connection must be connected before applying item code changes.'));
            }
        } elseif (((bool)Tools::isSubmit('submitSsbhesabfaDismissItemCodeMismatch')) == true) {
            $dismissResult = $this->dismissHesabfaItemCodeMismatch(
                (int) Tools::getValue('id_product'),
                (int) Tools::getValue('id_product_attribute'),
                (int) Tools::getValue('current_hesabfa_code'),
                (int) Tools::getValue('new_hesabfa_code')
            );
            if ($dismissResult['success']) {
                $output .= $this->displayConfirmation($dismissResult['message']);
            } else {
                $output .= $this->displayError($dismissResult['message']);
            }
        } elseif (((bool)Tools::isSubmit('submitSsbhesabfaRequeueJob')) == true) {
            $idJob=(int)Tools::getValue('id_ssb_hesabfa_job');
            if (HesabfaJobRepository::requeue($idJob,true)) $output.=$this->displayConfirmation($this->l('A new job operation was created with fresh request UUIDs.'));
            else $output.=$this->displayError($this->l('Job could not be requeued.'));
        } elseif (((bool)Tools::isSubmit('submitSsbhesabfaMarkJobDead')) == true) {
            $idJob = (int) Tools::getValue('id_ssb_hesabfa_job');
            if (HesabfaJobRepository::markDeadManually($idJob)) {
                $output .= $this->displayConfirmation($this->l('Job marked as dead.'));
            } else {
                $output .= $this->displayError($this->l('Job could not be marked as dead.'));
            }
        } elseif (((bool)Tools::isSubmit('submitSsbhesabfaRunJob')) == true) {
            $idJob = (int) Tools::getValue('id_ssb_hesabfa_job');
            $jobResult = $this->processSingleHesabfaJob($idJob);
            if ($jobResult['success']) {
                $output .= $this->displayConfirmation($jobResult['message']);
            } else {
                $output .= $this->displayError($jobResult['message']);
            }
        } elseif (((bool)Tools::isSubmit('submitSsbhesabfaRunPendingJobs')) == true) {
            $processed = $this->processPendingHesabfaJobs(20);
            $output .= $this->displayConfirmation($this->l('Pending Hesabfa jobs processed. Count: ') . (int) $processed);
        } elseif (((bool)Tools::isSubmit('submitSsbhesabfaRequeueInternalApiRequest')) == true) {
            $idRequest=(int)Tools::getValue('id_ssb_hesabfa_api_request');
            if (HesabfaInternalApiRequestRepository::requeue($idRequest)) $output.=$this->displayConfirmation($this->l('A new internal API operation was created with fresh request UUIDs.'));
            else $output.=$this->displayError($this->l('Internal API request could not be requeued.'));
        } elseif (((bool)Tools::isSubmit('submitSsbhesabfaMarkInternalApiRequestDead')) == true) {
            $idRequest = (int) Tools::getValue('id_ssb_hesabfa_api_request');
            if (HesabfaInternalApiRequestRepository::markDeadManually($idRequest)) {
                $output .= $this->displayConfirmation($this->l('Internal API request marked as dead.'));
            } else {
                $output .= $this->displayError($this->l('Internal API request could not be marked as dead.'));
            }
        } elseif (((bool)Tools::isSubmit('submitSsbhesabfaRunInternalApiRequest')) == true) {
            $idRequest = (int) Tools::getValue('id_ssb_hesabfa_api_request');
            $apiRequestResult = $this->processSingleInternalApiRequest($idRequest);
            if (!empty($apiRequestResult['success'])) {
                $output .= $this->displayConfirmation($apiRequestResult['message']);
            } else {
                $output .= $this->displayError($apiRequestResult['message']);
            }
        } elseif (((bool)Tools::isSubmit('submitSsbhesabfaRunPendingInternalApiRequests')) == true) {
            $processed = $this->processPendingInternalApiRequests(20);
            $output .= $this->displayConfirmation($this->l('Pending internal API requests processed. Count: ') . (int) $processed);
        } elseif (((bool)Tools::isSubmit('submitSsbhesabfaClearModuleLogs')) == true) {
            HesabfaLogRepository::clearAll();
            $output .= $this->displayConfirmation($this->l('Module logs cleared.'));
        } elseif (((bool)Tools::isSubmit('submitSsbhesabfaResolveIssue')) == true) {
            $idIssue = (int) Tools::getValue('id_ssb_hesabfa_issue');
            if ($idIssue > 0 && HesabfaIssueRepository::markResolved($idIssue)) {
                $output .= $this->displayConfirmation($this->l('Issue marked as resolved.'));
            } else {
                $output .= $this->displayError($this->l('Could not update the issue status.'));
            }
        } elseif (((bool)Tools::isSubmit('submitSsbhesabfaRetryIssue')) == true) {
            $idIssue = (int) Tools::getValue('id_ssb_hesabfa_issue');
            if ($idIssue > 0 && HesabfaIssueRepository::markRetrying($idIssue)) {
                $output .= $this->displayConfirmation($this->l('Issue marked for retry. Run the related sync or payment action again.'));
            } else {
                $output .= $this->displayError($this->l('Could not update the issue status.'));
            }
        } elseif (((bool)Tools::isSubmit('submitSsbhesabfaSyncProducts')) == true) {
            if (Configuration::get('SSBHESABFA_LIVE_MODE')) {
                $this->syncProducts();
                $output .= $this->displayConfirmation($this->l('Products synced with Hesabfa successfully.'));
            } else {
                $output .= $this->displayWarning($this->l('The API Connection must be connected before sync Products.'));
            }
        }

        $activeSection = $this->getActiveAdminSection();
        $isHesabfaConnected = (Configuration::get('SSBHESABFA_LIVE_MODE') == 1);

        // Render only the active section forms. This avoids unnecessary Hesabfa API calls while browsing admin pages.
        foreach (array('Bank', 'ManualGatewayPayment', 'Config', 'Item', 'Contact', 'Invoice', 'AccountingText') as $form) {
            $this->context->smarty->assign($form, '');
        }
        foreach ($this->getFormsForSection($activeSection) as $form) {
            $this->context->smarty->assign($form, $this->renderForm($form));
        }

        $adminBaseUrl = $this->getAdminSectionUrl('Dashboard');
        $sectionUrls = array(
            'Dashboard' => $this->getAdminSectionUrl('Dashboard'),
            'Settings' => $this->getAdminSectionUrl('Settings'),
            'Payments' => $this->getAdminSectionUrl('Payments'),
            'ManualPayment' => $this->getAdminSectionUrl('ManualPayment'),
            'Sync' => $this->getAdminSectionUrl('Sync'),
            'Queue' => $this->getAdminSectionUrl('Queue'),
            'InternalApi' => $this->getAdminSectionUrl('InternalApi'),
            'Logs' => $this->getAdminSectionUrl('Logs'),
        );
        $sectionAllowed = array(
            'Dashboard' => $this->employeeCanAccessAdminController('AdminSsbHesabfaDashboard'),
            'Settings' => $this->employeeCanAccessAdminController('AdminSsbHesabfaSettings'),
            'Payments' => $this->employeeCanAccessAdminController('AdminSsbHesabfaPayments'),
            'ManualPayment' => $this->employeeCanAccessAdminController('AdminSsbHesabfaManualPayment'),
            'Sync' => $this->employeeCanAccessAdminController('AdminSsbHesabfaSync'),
            'Queue' => $this->employeeCanAccessAdminController('AdminSsbHesabfaQueue'),
            'InternalApi' => $this->employeeCanAccessAdminController('AdminSsbHesabfaInternalApi'),
            'Logs' => $this->employeeCanAccessAdminController('AdminSsbHesabfaLogs'),
        );

        if (!$isHesabfaConnected) {
            $sectionAllowed['Dashboard'] = false;
            $sectionAllowed['Payments'] = false;
            $sectionAllowed['ManualPayment'] = false;
            $sectionAllowed['Sync'] = false;
            $sectionAllowed['Logs'] = false;
        }

        $this->context->smarty->assign(array(
            'current_form_tab' => $activeSection,
            'admin_base_url' => $adminBaseUrl,
            'section_urls' => $sectionUrls,
            'section_allowed' => $sectionAllowed,
            'export_action_url' => $sectionUrls['Sync'],
            'sync_action_url' => $sectionUrls['Sync'],
            'logs_action_url' => $sectionUrls['Logs'],
            'internal_api_action_url' => $sectionUrls['Queue'],
            'queue_alert_html' => $this->getQueueAlertHtml($sectionUrls['Queue'], $sectionUrls['InternalApi']),
            'module_logs_html' => ($activeSection === 'Logs' ? $this->getModuleLogsHtml() : ''),
            'repair_mismatches_html' => ($activeSection === 'Sync' ? $this->getItemCodeMismatchHtml() : ''),
            'job_queue_html' => ($activeSection === 'Queue' ? $this->getQueueControllerHtml() : ''),
            'internal_api_html' => ($activeSection === 'InternalApi' ? $this->getInternalApiHtml() : ''),
            'hesabfa_business_info' => $this->getHesabfaBusinessHeaderInfo(),
            'live_mode' => Configuration::get('SSBHESABFA_LIVE_MODE'),
            'debug_mode' => Configuration::get('SSBHESABFA_DEBUG_MODE'),
            'module_ver' => $this->version,
            'module_version_info' => 'v' . $this->version,
            'is_rtl' => (bool) (isset($this->context->language->is_rtl) ? $this->context->language->is_rtl : false),
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
        $output .= $this->getBankFeeToggleScript();

        // To return form html only
        return $output;
    }

    public function hookDisplayBackOfficeHeader()
    {
        if (Tools::getValue('module_name') == $this->name) {
            $this->context->controller->addJqueryUI('ui.datepicker');
        }
    }

}
