<?php

trait HesabfaInternalApiTrait
{
    public function callInternalApi($method, array $arguments = array(), array $options = array())
    {
        $method = trim((string) $method);
        $requester = isset($options['requester']) ? (string) $options['requester'] : 'external_module';
        $objectType = isset($options['object_type']) ? (string) $options['object_type'] : null;
        $objectId = isset($options['object_id']) ? (string) $options['object_id'] : null;
        $queueRequest = array_key_exists('queue', $options) ? (bool) $options['queue'] : false;
        if ($queueRequest) {
            return $this->enqueueInternalApiRequest($method, $arguments, $requester, $objectType, $objectId);
        }

        $logRequest = array_key_exists('log_request', $options) ? (bool) $options['log_request'] : (bool) Configuration::get('SSBHESABFA_INTERNAL_API_USE_QUEUE');

        $requestId = null;
        if ($logRequest) {
            $requestId = HesabfaInternalApiRequestRepository::create(
                $requester,
                $method,
                $arguments,
                'running',
                $objectType,
                $objectId
            );
        }

        if ($requestId) {
            $requestRow = HesabfaInternalApiRequestRepository::getById((int) $requestId);
            $requestUniqueIds = array();
            if ($requestRow && !empty($requestRow['request_unique_ids'])) {
                $decodedRequestIds = json_decode($requestRow['request_unique_ids'], true);
                if (is_array($decodedRequestIds)) {
                    $requestUniqueIds = $decodedRequestIds;
                }
            }
            HesabfaRequestUniqueId::beginContext(
                (int) $requestId,
                $requestUniqueIds,
                array('HesabfaInternalApiRequestRepository', 'saveRequestUniqueIds')
            );
        }

        try {
            $response = $this->executeInternalApiCall($method, $arguments, $requester, $objectType, $objectId);
        } finally {
            if ($requestId) {
                HesabfaRequestUniqueId::endContext();
            }
        }

        if ($requestId) {
            if (!empty($response['success'])) {
                HesabfaInternalApiRequestRepository::markFinished($requestId, $response);
            } else {
                HesabfaInternalApiRequestRepository::markOutcome(
                    $requestId,
                    HesabfaRetryPolicy::classify(isset($response['error_code']) ? $response['error_code'] : '', isset($response['error']) ? $response['error'] : ''),
                    isset($response['error']) ? $response['error'] : 'Internal API request failed.',
                    isset($response['error_code']) ? $response['error_code'] : null,
                    $response
                );
            }
            $response['request_id'] = (int) $requestId;
        }

        return $response;
    }

    public function enqueueInternalApiRequest($method, array $arguments = array(), $requester = null, $objectType = null, $objectId = null)
    {
        if (!Configuration::get('SSBHESABFA_INTERNAL_API_USE_QUEUE')) {
            return $this->callInternalApi((string) $method, $arguments, array(
                'requester' => $requester ?: 'external_module',
                'object_type' => $objectType,
                'object_id' => $objectId,
                'log_request' => false,
            ));
        }

        $requestId = HesabfaInternalApiRequestRepository::create(
            $requester ?: 'external_module',
            (string) $method,
            $arguments,
            'pending',
            $objectType,
            $objectId
        );

        return array(
            'success' => $requestId ? true : false,
            'request_id' => $requestId ? (int) $requestId : 0,
            'status' => $requestId ? 'pending' : 'failed',
        );
    }

    public function getInternalApiRequest($idRequest)
    {
        return HesabfaInternalApiRequestRepository::getById((int) $idRequest);
    }

    public function runInternalApiRequest($idRequest)
    {
        return $this->processSingleInternalApiRequest((int) $idRequest);
    }

    protected function executeInternalApiCall($method, array $arguments = array(), $requester = null, $objectType = null, $objectId = null)
    {
        $method = trim((string) $method);
        if (!$this->isAllowedInternalApiMethod($method)) {
            return array(
                'success' => false,
                'error_code' => 'INVALID_INTERNAL_API_METHOD',
                'error' => 'Requested internal API method is not allowed.',
                'method' => $method,
            );
        }

        if (!Configuration::get('SSBHESABFA_LIVE_MODE')) {
            return array(
                'success' => false,
                'error_code' => 'HESABFA_NOT_CONNECTED',
                'error' => 'Hesabfa API is not connected.',
                'method' => $method,
            );
        }

        $api = new HesabfaApi();
        $response = HesabfaSafeApi::call(
            $api,
            $method,
            $arguments,
            'Internal API request failed. Requester: ' . (string) $requester . '.',
            $objectType,
            $objectId
        );

        if (HesabfaApiResponse::isSuccess($response)) {
            return array(
                'success' => true,
                'method' => $method,
                'result' => isset($response->Result) ? $response->Result : $response,
                'raw' => $response,
            );
        }

        return array(
            'success' => false,
            'method' => $method,
            'error_code' => HesabfaApiResponse::getErrorCode($response),
            'error' => HesabfaApiResponse::getErrorMessage($response),
            'raw' => $response,
        );
    }

    protected function getAllowedInternalApiMethods()
    {
        if (!class_exists('HesabfaApi')) {
            return array();
        }

        $blocked = $this->getBlockedInternalApiMethods();
        $methods = get_class_methods('HesabfaApi');
        $allowed = array();
        foreach ($methods as $method) {
            if (strpos($method, '__') === 0 || in_array($method, $blocked, true)) {
                continue;
            }
            $allowed[] = $method;
        }
        sort($allowed);
        return $allowed;
    }

    protected function getAllowedInternalApiMethodRows()
    {
        $methods = $this->getAllowedInternalApiMethods();
        $rows = array();
        foreach ($methods as $method) {
            $rows[] = array(
                'method' => $method,
                'inputs' => $this->getInternalApiMethodInputSignature($method),
                'example' => $this->getInternalApiMethodCallExample($method),
            );
        }
        return $rows;
    }

    protected function getInternalApiMethodInputSignature($method)
    {
        if (!class_exists('HesabfaApi') || !method_exists('HesabfaApi', $method)) {
            return '';
        }

        try {
            $reflection = new ReflectionMethod('HesabfaApi', $method);
        } catch (Exception $e) {
            return '';
        }

        $parts = array();
        foreach ($reflection->getParameters() as $parameter) {
            $name = '$' . $parameter->getName();
            if ($parameter->isArray()) {
                $name = 'array ' . $name;
            }
            if ($parameter->isOptional()) {
                $default = ' = null';
                if ($parameter->isDefaultValueAvailable()) {
                    $value = $parameter->getDefaultValue();
                    if (is_array($value)) {
                        $default = ' = array()';
                    } elseif (is_bool($value)) {
                        $default = ' = ' . ($value ? 'true' : 'false');
                    } elseif (is_int($value) || is_float($value)) {
                        $default = ' = ' . (string) $value;
                    } elseif ($value === null) {
                        $default = ' = null';
                    } else {
                        $default = " = '" . (string) $value . "'";
                    }
                }
                $name .= $default;
            }
            $parts[] = $name;
        }

        return implode(', ', $parts);
    }

    protected function getInternalApiMethodCallExample($method)
    {
        if (!class_exists('HesabfaApi') || !method_exists('HesabfaApi', $method)) {
            return '$hesabfa->callInternalApi(\'' . (string) $method . '\');';
        }

        try {
            $reflection = new ReflectionMethod('HesabfaApi', $method);
        } catch (Exception $e) {
            return '$hesabfa->callInternalApi(\'' . (string) $method . '\');';
        }

        $args = array();
        foreach ($reflection->getParameters() as $parameter) {
            $args[] = $this->getInternalApiExampleValue($parameter->getName(), $parameter->isArray());
        }

        return '$hesabfa->callInternalApi(\'' . (string) $method . '\', array(' . implode(', ', $args) . '));';
    }

    protected function getInternalApiExampleValue($name, $isArray)
    {
        if ($isArray) {
            return 'array()';
        }

        $lower = strtolower((string) $name);
        if (strpos($lower, 'id') !== false || strpos($lower, 'number') !== false || strpos($lower, 'code') !== false) {
            return '12345';
        }
        if (strpos($lower, 'date') !== false) {
            return "'2026-06-25'";
        }
        if (strpos($lower, 'amount') !== false || strpos($lower, 'price') !== false || strpos($lower, 'quantity') !== false) {
            return '1000';
        }

        return "'value'";
    }

    protected function getBlockedInternalApiMethods()
    {
        return array(
            '__construct',
            'apiRequest',
            'maskSensitiveData',
            'setApiKey',
            'setUserId',
            'setPassword',
            'setLoginToken',
        );
    }

    protected function isAllowedInternalApiMethod($method)
    {
        $method = trim((string) $method);
        if ($method === '' || !class_exists('HesabfaApi') || !method_exists('HesabfaApi', $method)) {
            return false;
        }

        return !in_array($method, $this->getBlockedInternalApiMethods(), true);
    }

    protected function processSingleInternalApiRequest($idRequest)
    {
        $request = HesabfaInternalApiRequestRepository::getById((int) $idRequest);
        if (!$request) {
            return array('success' => false, 'message' => $this->l('Internal API request was not found.'));
        }


        if (in_array((string) $request['status'], array('done', 'dead', 'needs_attention', 'duplicate_check'), true)) {
            return array('success' => false, 'message' => $this->l('This internal API request requires a new operation before it can run again.'));
        }

        $payload = json_decode($request['payload'], true);
        if (!is_array($payload)) {
            $payload = array();
        }

        $payloadChanged = HesabfaInternalApiRequestRepository::syncPayloadHash((int) $idRequest, $payload);
        if ($payloadChanged) {
            $request['request_unique_ids'] = null;
            $request['request_unique_ids_created_at'] = null;
        }

        if (!empty($request['request_unique_ids'])
            && HesabfaRetryPolicy::isRequestIdExpired(isset($request['request_unique_ids_created_at']) ? $request['request_unique_ids_created_at'] : null)) {
            HesabfaInternalApiRequestRepository::markOutcome(
                (int) $idRequest,
                HesabfaRetryPolicy::STATUS_DUPLICATE_CHECK,
                'The persisted requestUniqueId is older than 24 hours. Reconciliation is required before a new operation is created.',
                'REQUEST_ID_EXPIRED',
                array()
            );
            return array('success' => false, 'message' => $this->l('The request requires duplicate checking because its request ID is older than 24 hours.'));
        }

        if (!$this->isHesabfaApiConfigured()) {
            HesabfaInternalApiRequestRepository::markOutcome((int) $idRequest, HesabfaRetryPolicy::STATUS_NEEDS_ATTENTION, 'Hesabfa API credentials are not configured.', 'HESABFA_NOT_CONFIGURED', array());
            return array('success' => false, 'message' => $this->l('Hesabfa API credentials are not configured.'));
        }

        if (!Configuration::get('SSBHESABFA_LIVE_MODE')) {
            HesabfaInternalApiRequestRepository::markWaitingForConnection((int) $idRequest);
            return array('success' => false, 'message' => $this->l('Hesabfa is not connected. The request remains queued for a later retry.'));
        }

        if (!HesabfaInternalApiRequestRepository::markRunning((int) $idRequest)) {
            return array('success' => false, 'message' => $this->l('Internal API request is not ready to run.'));
        }

        $requestUniqueIds = array();
        if (!empty($request['request_unique_ids'])) {
            $decodedRequestIds = json_decode($request['request_unique_ids'], true);
            if (is_array($decodedRequestIds)) {
                $requestUniqueIds = $decodedRequestIds;
            }
        }

        HesabfaRequestUniqueId::beginContext(
            (int) $idRequest,
            $requestUniqueIds,
            array('HesabfaInternalApiRequestRepository', 'saveRequestUniqueIds')
        );
        HesabfaApiResponse::resetLastResponse();

        try {
            $result = $this->executeInternalApiCall(
                $request['api_method'],
                $payload,
                $request['requester'],
                $request['object_type'],
                $request['object_id']
            );
        } finally {
            HesabfaRequestUniqueId::endContext();
        }

        if (!empty($result['success'])) {
            HesabfaInternalApiRequestRepository::markFinished((int) $idRequest, $result);
            return array('success' => true, 'message' => $this->l('Internal API request executed successfully.'));
        }

        $errorCode = isset($result['error_code']) ? (string) $result['error_code'] : 'UNKNOWN_HESABFA_API_ERROR';
        $errorMessage = isset($result['error']) ? (string) $result['error'] : 'Internal API request failed.';
        $status = HesabfaRetryPolicy::classify($errorCode, $errorMessage);
        HesabfaInternalApiRequestRepository::markOutcome((int) $idRequest, $status, $errorMessage, $errorCode, $result);

        return array(
            'success' => false,
            'status' => $status,
            'message' => $this->l('Internal API request was classified and updated. Review its status and error code.'),
        );
    }

    public function processPendingInternalApiRequests($limit = 20)
    {
        $requests = HesabfaInternalApiRequestRepository::getPending($limit);
        $processed = 0;
        foreach ($requests as $request) {
            $result = $this->processSingleInternalApiRequest((int) $request['id_ssb_hesabfa_api_request']);
            if (isset($result['success'])) {
                $processed++;
            }
        }
        return $processed;
    }

    protected function getInternalApiHtml()
    {
        $html = '<div class="ssb-card ssb-card-main"><div class="ssb-card-header"><div><h3><i class="icon-code"></i> ' . $this->l('Internal API') . '</h3><p>' . $this->l('Use ssbhesabfa as a safe bridge for other modules that need Hesabfa API access.') . '</p></div></div><div class="ssb-card-body">'
            . $this->getInternalApiGuideHtml()
            . '</div></div>';
        return $html;
    }

    protected function getInternalApiRequestQueueHtml($actionUrl = null)
    {
        if ($actionUrl === null) {
            $actionUrl = $this->getAdminSectionUrl('Queue');
        }

        $filters = array(
            'id' => trim((string) Tools::getValue('ssb_api_id', '')),
            'status' => trim((string) Tools::getValue('ssb_api_status', '')),
            'requester' => trim((string) Tools::getValue('ssb_api_requester', '')),
            'api_method' => trim((string) Tools::getValue('ssb_api_method', '')),
            'object_type' => trim((string) Tools::getValue('ssb_api_object_type', '')),
            'object_id' => trim((string) Tools::getValue('ssb_api_object_id', '')),
            'error_code' => trim((string) Tools::getValue('ssb_api_error_code', '')),
            'date_from' => trim((string) Tools::getValue('ssb_api_date_from', '')),
            'date_to' => trim((string) Tools::getValue('ssb_api_date_to', '')),
            'keyword' => trim((string) Tools::getValue('ssb_api_keyword', '')),
        );
        $perPage = 50;
        $totalRows = HesabfaInternalApiRequestRepository::countList($filters);
        $totalPages = max(1, (int) ceil($totalRows / $perPage));
        $page = max(1, min((int) Tools::getValue('ssb_api_page', 1), $totalPages));
        $requests = HesabfaInternalApiRequestRepository::getList($filters, $perPage, ($page - 1) * $perPage);

        $postActionFields = HesabfaAdminQueueRenderer::getRequestValuesByPrefix('ssb_api_');
        $postActionUrl = htmlspecialchars(HesabfaAdminQueueRenderer::buildUrl($actionUrl, $postActionFields), ENT_QUOTES, 'UTF-8');
        $filterHidden = array('ssb_api_page' => 1);
        $clearUrl = $actionUrl;

        $html = '<div class="ssb-card"><div class="ssb-card-header"><div><h3><i class="icon-code"></i> ' . $this->l('Internal API request queue') . '</h3><p>' . $this->l('Pending and failed internal API requests from other modules can be executed manually from this table.') . '</p></div><div class="ssb-card-actions"><form method="post" action="' . $postActionUrl . '" onsubmit="return confirm(\'' . htmlspecialchars($this->l('Run pending internal API requests now?'), ENT_QUOTES, 'UTF-8') . '\');"><button type="submit" class="btn btn-primary" name="submitSsbhesabfaRunPendingInternalApiRequests"><i class="icon-play"></i> ' . $this->l('Run pending internal API requests') . '</button></form></div></div><div class="ssb-card-body">';
        $html .= HesabfaAdminQueueRenderer::openGetForm($actionUrl, $filterHidden, 'ssb-queue-filter-form');
        $html .= '<div class="row">';
        $html .= '<div class="col-lg-1 col-md-2"><label>' . $this->l('ID') . '</label><input type="text" name="ssb_api_id" class="form-control" value="' . htmlspecialchars($filters['id'], ENT_QUOTES, 'UTF-8') . '" /></div>';
        $html .= '<div class="col-lg-2 col-md-3"><label>' . $this->l('Status') . '</label><select name="ssb_api_status" class="form-control"><option value="">' . $this->l('All') . '</option>';
        foreach (array('pending', 'running', 'retry_wait', 'needs_attention', 'duplicate_check', 'done', 'dead') as $status) {
            $html .= '<option value="' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '"' . ($filters['status'] === $status ? ' selected="selected"' : '') . '>' . htmlspecialchars(HesabfaAdminQueueRenderer::getStatusLabel($this, $status), ENT_QUOTES, 'UTF-8') . '</option>';
        }
        $html .= '</select></div>';
        $html .= '<div class="col-lg-2 col-md-3"><label>' . $this->l('Requester') . '</label><input type="text" name="ssb_api_requester" class="form-control" value="' . htmlspecialchars($filters['requester'], ENT_QUOTES, 'UTF-8') . '" /></div>';
        $html .= '<div class="col-lg-2 col-md-3"><label>' . $this->l('Method') . '</label><input type="text" name="ssb_api_method" class="form-control" value="' . htmlspecialchars($filters['api_method'], ENT_QUOTES, 'UTF-8') . '" /></div>';
        $html .= '<div class="col-lg-2 col-md-3"><label>' . $this->l('Object type') . '</label><input type="text" name="ssb_api_object_type" class="form-control" value="' . htmlspecialchars($filters['object_type'], ENT_QUOTES, 'UTF-8') . '" /></div>';
        $html .= '<div class="col-lg-1 col-md-2"><label>' . $this->l('Object ID') . '</label><input type="text" name="ssb_api_object_id" class="form-control" value="' . htmlspecialchars($filters['object_id'], ENT_QUOTES, 'UTF-8') . '" /></div>';
        $html .= '<div class="col-lg-2 col-md-3"><label>' . $this->l('Error code') . '</label><input type="text" name="ssb_api_error_code" class="form-control" value="' . htmlspecialchars($filters['error_code'], ENT_QUOTES, 'UTF-8') . '" /></div>';
        $html .= '</div><div class="row ssb-queue-filter-row">';
        $html .= '<div class="col-lg-2 col-md-3"><label>' . $this->l('From') . '</label><input type="date" name="ssb_api_date_from" class="form-control" value="' . htmlspecialchars($filters['date_from'], ENT_QUOTES, 'UTF-8') . '" /></div>';
        $html .= '<div class="col-lg-2 col-md-3"><label>' . $this->l('To') . '</label><input type="date" name="ssb_api_date_to" class="form-control" value="' . htmlspecialchars($filters['date_to'], ENT_QUOTES, 'UTF-8') . '" /></div>';
        $html .= '<div class="col-lg-5 col-md-6"><label>' . $this->l('Keyword') . '</label><input type="text" name="ssb_api_keyword" class="form-control" value="' . htmlspecialchars($filters['keyword'], ENT_QUOTES, 'UTF-8') . '" placeholder="' . htmlspecialchars($this->l('Search payload, response, UUID, hash or error text'), ENT_QUOTES, 'UTF-8') . '" /></div>';
        $html .= '<div class="col-lg-3 col-md-12 ssb-queue-filter-actions"><button type="submit" class="btn btn-primary"><i class="icon-search"></i> ' . $this->l('Filter') . '</button> <a class="btn btn-default" href="' . htmlspecialchars($clearUrl, ENT_QUOTES, 'UTF-8') . '"><i class="icon-remove"></i> ' . $this->l('Clear filters') . '</a></div>';
        $html .= '</div></form>';

        $html .= '<div class="table-responsive"><table class="table"><thead><tr>'
                . '<th>' . $this->l('ID') . '</th><th>' . $this->l('Date / Time') . '</th><th>' . $this->l('Requester') . '</th><th>' . $this->l('Method') . '</th><th>' . $this->l('Status') . '</th><th>' . $this->l('Context') . '</th><th>' . $this->l('Attempts') . '</th><th>' . $this->l('Next run') . '</th><th>' . $this->l('Error code') . '</th><th>' . $this->l('Request UUID') . '</th><th>' . $this->l('Payload hash') . '</th><th>' . $this->l('Last error') . '</th><th>' . $this->l('Action') . '</th>'
                . '</tr></thead><tbody>';
        if (empty($requests)) {
            $html .= '<tr><td colspan="13" class="text-center text-muted">' . $this->l('No internal API requests found.') . '</td></tr>';
        } else {
            foreach ($requests as $request) {
                $idRequest = (int) $request['id_ssb_hesabfa_api_request'];
                $requestStatus = isset($request['status']) ? (string) $request['status'] : '';
                $runConfirm = $this->l('Run this internal API request now?');
                $runLabel = $this->l('Run now');
                $requestUuid = '';
                $requestIds = !empty($request['request_unique_ids']) ? json_decode($request['request_unique_ids'], true) : array();
                if (is_array($requestIds)) {
                    foreach ($requestIds as $candidateUuid) {
                        if (HesabfaRequestUniqueId::isValidGuid($candidateUuid)) {
                            $requestUuid = $candidateUuid;
                            break;
                        }
                    }
                }
                $payloadHash = isset($request['request_payload_hash']) ? (string) $request['request_payload_hash'] : '';
                $context = trim((string) $request['object_type'] . ' ' . (string) $request['object_id']);
                $newOperationLabel = $requestStatus === 'duplicate_check' ? $this->l('Checked; start new operation') : $this->l('Start new operation');
                $newOperationConfirm = $requestStatus === 'duplicate_check' ? $this->l('Only continue after checking Hesabfa and confirming that the previous operation did not succeed. Start a new operation with a new UUID?') : $this->l('Only continue after correcting the underlying error. Start a new operation with a new UUID?');
                $html .= '<tr>'
                    . '<td>' . $idRequest . '</td>'
                    . '<td>' . htmlspecialchars($this->formatAdminDateTime($request['date_add']), ENT_QUOTES, 'UTF-8') . '</td>'
                    . '<td>' . htmlspecialchars($request['requester'], ENT_QUOTES, 'UTF-8') . '</td>'
                    . '<td>' . htmlspecialchars($request['api_method'], ENT_QUOTES, 'UTF-8') . '</td>'
                    . '<td><strong>' . htmlspecialchars(HesabfaAdminQueueRenderer::getStatusLabel($this, $requestStatus), ENT_QUOTES, 'UTF-8') . '</strong><br><small><code>' . htmlspecialchars($requestStatus, ENT_QUOTES, 'UTF-8') . '</code></small></td>'
                    . '<td>' . htmlspecialchars($context !== '' ? $context : '-', ENT_QUOTES, 'UTF-8') . '</td>'
                    . '<td>' . (int) $request['attempts'] . '</td>'
                    . '<td>' . htmlspecialchars((string) $request['next_run_at'], ENT_QUOTES, 'UTF-8') . '</td>'
                    . '<td>' . htmlspecialchars((string) $request['last_error_code'], ENT_QUOTES, 'UTF-8') . '</td>'
                    . '<td><code style="word-break:break-all">' . htmlspecialchars($requestUuid ?: '-', ENT_QUOTES, 'UTF-8') . '</code></td>'
                    . '<td><code>' . htmlspecialchars($payloadHash ? substr($payloadHash, 0, 12) : '-', ENT_QUOTES, 'UTF-8') . '</code></td>'
                    . '<td>' . htmlspecialchars((string) $request['last_error'], ENT_QUOTES, 'UTF-8') . '</td>'
                    . '<td>';

                $hasAction = false;
                if (in_array($requestStatus, array('dead', 'needs_attention', 'duplicate_check'), true)) {
                    $html .= '<form method="post" action="' . $postActionUrl . '" class="ssb-inline-action-form" onsubmit="return confirm(&quot;' . htmlspecialchars($newOperationConfirm, ENT_QUOTES, 'UTF-8') . '&quot;);"><input type="hidden" name="id_ssb_hesabfa_api_request" value="' . $idRequest . '" /><button type="submit" class="btn btn-warning btn-xs" name="submitSsbhesabfaRequeueInternalApiRequest"><i class="icon-refresh"></i> ' . $newOperationLabel . '</button></form>';
                    $hasAction = true;
                } elseif (in_array($requestStatus, array('pending', 'retry_wait'), true)) {
                    $html .= '<form method="post" action="' . $postActionUrl . '" class="ssb-inline-action-form" onsubmit="return confirm(&quot;' . htmlspecialchars($runConfirm, ENT_QUOTES, 'UTF-8') . '&quot;);"><input type="hidden" name="id_ssb_hesabfa_api_request" value="' . $idRequest . '" /><button type="submit" class="btn btn-default btn-xs" name="submitSsbhesabfaRunInternalApiRequest"><i class="icon-play"></i> ' . $runLabel . '</button></form>';
                    $hasAction = true;
                }

                if (in_array($requestStatus, array('pending', 'retry_wait', 'needs_attention', 'duplicate_check'), true)) {
                    $html .= '<form method="post" action="' . $postActionUrl . '" class="ssb-inline-action-form-last" onsubmit="return confirm(&quot;' . htmlspecialchars($this->l('Mark this internal API request as dead? It will no longer run automatically.'), ENT_QUOTES, 'UTF-8') . '&quot;);"><input type="hidden" name="id_ssb_hesabfa_api_request" value="' . $idRequest . '" /><button type="submit" class="btn btn-danger btn-xs" name="submitSsbhesabfaMarkInternalApiRequestDead"><i class="icon-ban-circle"></i> ' . $this->l('Mark as dead') . '</button></form>';
                    $hasAction = true;
                }

                if (!$hasAction) {
                    $html .= '<span class="text-muted">-</span>';
                }

                $html .= '</td></tr>';
            }
        }
        $html .= '</tbody></table></div>';

        $paginationFields = array(
            'ssb_api_id' => $filters['id'],
            'ssb_api_status' => $filters['status'],
            'ssb_api_requester' => $filters['requester'],
            'ssb_api_method' => $filters['api_method'],
            'ssb_api_object_type' => $filters['object_type'],
            'ssb_api_object_id' => $filters['object_id'],
            'ssb_api_error_code' => $filters['error_code'],
            'ssb_api_date_from' => $filters['date_from'],
            'ssb_api_date_to' => $filters['date_to'],
            'ssb_api_keyword' => $filters['keyword'],
        );
        $html .= HesabfaAdminQueueRenderer::renderCompactPagination($actionUrl, 'ssb_api_page', $page, $totalPages, $paginationFields);
        $html .= '</div></div>';
        return $html;
    }

    protected function getInternalApiGuideHtml()
    {
        $moduleName = htmlspecialchars($this->name, ENT_QUOTES, 'UTF-8');
        $documentationUrl = Tools::getShopDomainSsl(true) . __PS_BASE_URI__ . 'modules/' . $this->name . '/docs/internal-api-guide.html';
        $html = '<div class="ssb-info-note">'
            . '<strong>' . $this->l('Purpose') . '</strong><br />'
            . $this->l('Use this internal API when another PrestaShop module needs to call Hesabfa without storing Hesabfa credentials itself. The call is executed through this module, logged, and can be queued when needed.')
            . '<div style="margin-top:12px"><a class="btn btn-primary" href="' . htmlspecialchars($documentationUrl, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener"><i class="icon-book"></i> ' . $this->l('Open complete internal API documentation') . '</a></div>'
            . '</div>';

        $html .= '<div class="alert alert-info"><strong>' . $this->l('Input and output contract') . '</strong><br />'
            . $this->l('Arguments are positional and must follow the selected HesabfaApi method signature. A synchronous success returns success, method, result, raw and optionally request_id. A queued acceptance returns success, request_id and status; it does not mean the Hesabfa operation has completed.')
            . '</div>';

        $html .= '<h4>' . $this->l('Synchronous request example') . '</h4>';
        $html .= '<pre><code>$hesabfa = Module::getInstanceByName(\'' . $moduleName . '\');
$result = $hesabfa->callInternalApi(\'invoiceGet\', array(12345), array(
    \'requester\' => \'mycustommodule\',
    \'object_type\' => \'Order\',
    \'object_id\' => 1001,
));</code></pre>';

        $html .= '<h4>' . $this->l('Queued request example') . '</h4>';
        $html .= '<pre><code>$hesabfa = Module::getInstanceByName(\'' . $moduleName . '\');
$queued = $hesabfa->enqueueInternalApiRequest(
    \'invoiceGet\',
    array(12345),
    \'mycustommodule\',
    \'Order\',
    1001
);</code></pre>';

        $html .= '<h4>' . $this->l('Sample success response') . '</h4>';
        $html .= '<pre><code>{
  "success": true,
  "method": "invoiceGet",
  "request_id": 25,
  "result": { }
}</code></pre>';

        $html .= '<h4>' . $this->l('Sample error response') . '</h4>';
        $html .= '<pre><code>{
  "success": false,
  "method": "invoiceGet",
  "error_code": "HESABFA_RESPONSE_ERROR",
  "error": "Hesabfa returned an incomplete response.",
  "request_id": 25
}</code></pre>';

        $methods = $this->getAllowedInternalApiMethodRows();
        $html .= '<h4>' . $this->l('Allowed internal API methods') . '</h4>';
        $html .= '<div class="table-responsive"><table class="table"><thead><tr><th>' . $this->l('Method') . '</th><th>' . $this->l('Inputs') . '</th><th>' . $this->l('Call example') . '</th></tr></thead><tbody>';
        foreach ($methods as $allowedMethod) {
            $html .= '<tr><td><code>' . htmlspecialchars($allowedMethod['method'], ENT_QUOTES, 'UTF-8') . '</code></td><td><code>' . htmlspecialchars($allowedMethod['inputs'], ENT_QUOTES, 'UTF-8') . '</code></td><td><code>' . htmlspecialchars($allowedMethod['example'], ENT_QUOTES, 'UTF-8') . '</code></td></tr>';
        }
        $html .= '</tbody></table></div>';

        $html .= '<div class="alert alert-info">'
            . $this->l('Method names match Hesabfa API wrapper methods in classes/HesabfaAPI.php. For official endpoint fields and meanings, refer to Hesabfa official API documentation.')
            . ' <a href="https://www.hesabfa.com/help/api" target="_blank" rel="noopener">https://www.hesabfa.com/help/api</a>'
            . '</div>';

        return $html;
    }
}
