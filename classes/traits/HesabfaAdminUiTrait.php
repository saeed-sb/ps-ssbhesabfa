<?php

trait HesabfaAdminUiTrait
{
    protected function getQueueAlertHtml($queueUrl, $internalApiUrl)
    {
        $jobStats = HesabfaJobRepository::getAlertStats();
        $apiStats = HesabfaInternalApiRequestRepository::getAlertStats();

        $needsAttention = (int) $jobStats['needs_attention'] + (int) $apiStats['needs_attention'];
        $duplicateCheck = (int) $jobStats['duplicate_check'] + (int) $apiStats['duplicate_check'];
        $manualAttention = $needsAttention + $duplicateCheck;

        if ($manualAttention <= 0) {
            return '';
        }

        $alertClass = 'alert-danger';
        $parts = array(
            sprintf($this->l('%d queue jobs require manual attention.'), $manualAttention),
        );

        $html = '<div class="alert ' . $alertClass . ' ssb-queue-alert">';
        $html .= '<i class="icon-warning-sign"></i> <strong>' . htmlspecialchars($this->l('Queue attention required'), ENT_QUOTES, 'UTF-8') . '</strong> ';
        $html .= htmlspecialchars(implode(' ', $parts), ENT_QUOTES, 'UTF-8');
        $html .= '<div class="ssb-queue-alert-actions">';
        $html .= '<a class="btn btn-default btn-sm" href="' . htmlspecialchars((string) $queueUrl, ENT_QUOTES, 'UTF-8') . '"><i class="icon-tasks"></i> ' . htmlspecialchars($this->l('Review request queue'), ENT_QUOTES, 'UTF-8') . '</a> ';
        $html .= '<a class="btn btn-default btn-sm" href="' . htmlspecialchars((string) $internalApiUrl, ENT_QUOTES, 'UTF-8') . '"><i class="icon-exchange"></i> ' . htmlspecialchars($this->l('Review internal API queue'), ENT_QUOTES, 'UTF-8') . '</a>';
        $html .= '</div></div>';

        return $html;
    }

    protected function getModuleLogsHtml()
    {
        $severity = Tools::getValue('ssb_log_severity', '');
        $objectType = Tools::getValue('ssb_log_object_type', '');
        $areaFilter = Tools::getValue('ssb_log_area', '');
        $keyword = Tools::getValue('ssb_log_keyword', '');
        $dateFrom = Tools::getValue('ssb_log_date_from', '');
        $dateTo = Tools::getValue('ssb_log_date_to', '');
        $prestashopCodeFilter = Tools::getValue('ssb_log_prestashop_code', '');
        $hesabfaCodeFilter = Tools::getValue('ssb_log_hesabfa_code', '');
        $page = max(1, (int) Tools::getValue('ssb_log_page', 1));
        $perPage = 50;
        $offset = ($page - 1) * $perPage;

        $logFilters = array(
            'severity' => $severity,
            'object_type' => $objectType,
            'area' => $areaFilter,
            'keyword' => $keyword,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'prestashop_code' => $prestashopCodeFilter,
            'hesabfa_code' => $hesabfaCodeFilter,
        );
        $totalFilteredLogs = HesabfaLogRepository::countList($logFilters);
        $rows = HesabfaLogRepository::getList($logFilters, $perPage, $offset);
        $debugMode = (bool) Configuration::get('SSBHESABFA_DEBUG_MODE');

        $stats = HesabfaLogRepository::getStats();
        $total = (int) $stats['total'];
        $errors = (int) $stats['errors'];
        $warnings = (int) $stats['warnings'];

        $html = '<div class="ssb-card ssb-card-main">';
        $html .= '<div class="ssb-card-header"><div><h3><i class="icon-list"></i> ' . $this->l('Module Logs / Sync Issues') . '</h3><p>' . $this->l('Internal ssbhesabfa events, API errors, sync issues and repair actions are shown here.') . '</p></div><div class="ssb-card-actions"><form method="post" action="' . htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES, 'UTF-8') . '" class="ssb-confirm-form" data-confirm="' . htmlspecialchars($this->l('Clear all module logs?'), ENT_QUOTES, 'UTF-8') . '"><button type="submit" name="submitSsbhesabfaClearModuleLogs" class="btn btn-default"><i class="icon-trash"></i> ' . $this->l('Clear logs') . '</button></form></div></div>';
        $html .= '<div class="ssb-stats"><span><strong>' . (int) $total . '</strong>' . $this->l('Total') . '</span><span><strong>' . (int) $errors . '</strong>' . $this->l('Errors') . '</span><span><strong>' . (int) $warnings . '</strong>' . $this->l('Warnings') . '</span></div>';
        if ($debugMode) {
            $html .= '<div class="alert alert-warning"><i class="icon-warning-sign"></i> ' . $this->l('Debug mode is enabled from database. Debug columns are visible in this log table.') . '</div>';
        }

        $html .= '<form method="get" class="ssb-log-table-filter-form">';
        $html .= '<input type="hidden" name="tab" value="AdminModules" />';
        $html .= '<input type="hidden" name="configure" value="ssbhesabfa" />';
        $html .= '<input type="hidden" name="token" value="' . htmlspecialchars(Tools::getAdminTokenLite('AdminModules'), ENT_QUOTES, 'UTF-8') . '" />';
        $html .= '<input type="hidden" name="tab_module" value="' . htmlspecialchars($this->tab, ENT_QUOTES, 'UTF-8') . '" />';
        $html .= '<input type="hidden" name="module_name" value="ssbhesabfa" />';
        $html .= '<input type="hidden" name="form_tab" value="Logs" />';
        $html .= '<div class="table-responsive ssb-log-table-responsive' . ($debugMode ? ' ssb-log-table-debug-responsive' : '') . '"><table class="table ssb-log-table"><thead><tr>';
        $html .= '<th>ID</th><th>' . $this->l('Date / Time') . '</th><th>' . $this->l('Severity') . '</th><th>' . $this->l('Area') . '</th><th>' . $this->l('PrestaShop code') . '</th><th>' . $this->l('Hesabfa code') . '</th><th>' . $this->l('Context') . '</th><th>' . $this->l('Message') . '</th><th>' . $this->l('Error code') . '</th>';
        if ($debugMode) {
            $html .= '<th>' . $this->l('Endpoint') . '</th><th>' . $this->l('HTTP') . '</th><th>' . $this->l('Duration') . '</th><th>' . $this->l('Debug data') . '</th>';
        }
        $html .= '</tr>';
        $html .= '<tr class="filter"><td></td><td><input type="date" name="ssb_log_date_from" class="form-control input-sm" value="' . htmlspecialchars($dateFrom, ENT_QUOTES, 'UTF-8') . '" placeholder="' . htmlspecialchars($this->l('From'), ENT_QUOTES, 'UTF-8') . '" /><input type="date" name="ssb_log_date_to" class="form-control input-sm ssb-log-date-to" value="' . htmlspecialchars($dateTo, ENT_QUOTES, 'UTF-8') . '" placeholder="' . htmlspecialchars($this->l('To'), ENT_QUOTES, 'UTF-8') . '" /></td><td><select name="ssb_log_severity" class="form-control input-sm"><option value="">' . $this->l('All') . '</option>';
        foreach (array(0 => $this->l('Debug'), 1 => $this->l('Info'), 2 => $this->l('Warning'), 3 => $this->l('Error'), 4 => $this->l('Critical')) as $key => $label) {
            $html .= '<option value="' . (int) $key . '"' . ((string) $severity === (string) $key ? ' selected="selected"' : '') . '>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
        }
        $html .= '</select></td>';
        $areaOptions = array('', 'Admin', 'API', 'Cron', 'Internal API', 'Payment', 'Queue', 'Repair', 'Sync', 'Webhook', 'System');
        $html .= '<td><select name="ssb_log_area" class="form-control input-sm"><option value="">' . $this->l('All') . '</option>';
        foreach ($areaOptions as $areaOption) {
            if ($areaOption === '') {
                continue;
            }
            $html .= '<option value="' . htmlspecialchars($areaOption, ENT_QUOTES, 'UTF-8') . '"' . ((string) $areaFilter === (string) $areaOption ? ' selected="selected"' : '') . '>' . htmlspecialchars($areaOption, ENT_QUOTES, 'UTF-8') . '</option>';
        }
        $html .= '</select></td>';
        $html .= '<td><input type="text" name="ssb_log_prestashop_code" class="form-control input-sm" value="' . htmlspecialchars($prestashopCodeFilter, ENT_QUOTES, 'UTF-8') . '" placeholder="' . htmlspecialchars($this->l('PrestaShop code'), ENT_QUOTES, 'UTF-8') . '" /></td>';
        $html .= '<td><input type="text" name="ssb_log_hesabfa_code" class="form-control input-sm" value="' . htmlspecialchars($hesabfaCodeFilter, ENT_QUOTES, 'UTF-8') . '" placeholder="' . htmlspecialchars($this->l('Hesabfa code'), ENT_QUOTES, 'UTF-8') . '" /></td>';
        $html .= '<td><input type="text" name="ssb_log_object_type" class="form-control input-sm" value="' . htmlspecialchars($objectType, ENT_QUOTES, 'UTF-8') . '" placeholder="' . htmlspecialchars($this->l('Order / Product / Customer'), ENT_QUOTES, 'UTF-8') . '" /></td>';
        $html .= '<td><input type="text" name="ssb_log_keyword" class="form-control input-sm" value="' . htmlspecialchars($keyword, ENT_QUOTES, 'UTF-8') . '" placeholder="' . htmlspecialchars($this->l('Message, error code, object id'), ENT_QUOTES, 'UTF-8') . '" /></td>';
        $html .= '<td><button type="submit" class="btn btn-default btn-sm"><i class="icon-search"></i> ' . $this->l('Filter') . '</button></td>';
        if ($debugMode) {
            $html .= '<td></td><td></td><td></td><td></td>';
        }
        $html .= '</tr></thead><tbody>';

        if (empty($rows)) {
            $html .= '<tr><td colspan="' . ($debugMode ? 13 : 9) . '" class="text-center text-muted">' . $this->l('No module logs found.') . '</td></tr>';
        } else {
            foreach ($rows as $row) {
                $sev = (int) $row['severity'];
                $badge = 'success';
                $label = $this->l('Info');
                if ($sev >= 4) {
                    $badge = 'danger';
                    $label = $this->l('Critical');
                } elseif ($sev == 3) {
                    $badge = 'danger';
                    $label = $this->l('Error');
                } elseif ($sev == 2) {
                    $badge = 'warning';
                    $label = $this->l('Warning');
                }

                $context = $this->formatLogContext($row['object_type']);
                $area = isset($row['area']) && $row['area'] !== '' ? $row['area'] : '-';
                $prestashopCode = (isset($row['prestashop_code']) && trim((string) $row['prestashop_code']) !== '') ? (string) $row['prestashop_code'] : $this->extractPrestashopCodeFromLogRow($row);
                $hesabfaCode = (isset($row['hesabfa_code']) && trim((string) $row['hesabfa_code']) !== '') ? (string) $row['hesabfa_code'] : $this->extractHesabfaCodeFromLogRow($row);
                $html .= '<tr>';
                $html .= '<td>' . (int) $row['id_ssb_hesabfa_log'] . '</td>';
                $html .= '<td>' . htmlspecialchars($this->formatAdminDateTime($row['date_add']), ENT_QUOTES, 'UTF-8') . '</td>';
                $html .= '<td><span class="label label-' . $badge . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span></td>';
                $html .= '<td>' . htmlspecialchars($area, ENT_QUOTES, 'UTF-8') . '</td>';
                $html .= '<td>' . htmlspecialchars($prestashopCode, ENT_QUOTES, 'UTF-8') . '</td>';
                $html .= '<td>' . htmlspecialchars($hesabfaCode, ENT_QUOTES, 'UTF-8') . '</td>';
                $html .= '<td>' . htmlspecialchars($context, ENT_QUOTES, 'UTF-8') . '</td>';
                $html .= '<td class="ssb-log-message">' . htmlspecialchars(self::normalizeLogMessage($row['message']), ENT_QUOTES, 'UTF-8') . '</td>';
                $html .= '<td>' . htmlspecialchars($row['error_code'], ENT_QUOTES, 'UTF-8') . '</td>';
                if ($debugMode) {
                    $html .= '<td>' . htmlspecialchars(isset($row['debug_endpoint']) ? (string) $row['debug_endpoint'] : '', ENT_QUOTES, 'UTF-8') . '</td>';
                    $html .= '<td>' . htmlspecialchars(isset($row['debug_http_code']) ? (string) $row['debug_http_code'] : '', ENT_QUOTES, 'UTF-8') . '</td>';
                    $html .= '<td>' . htmlspecialchars(isset($row['debug_duration_ms']) && $row['debug_duration_ms'] !== null ? (string) $row['debug_duration_ms'] . ' ms' : '', ENT_QUOTES, 'UTF-8') . '</td>';
                    $html .= '<td>' . $this->renderDebugLogDataCell($row) . '</td>';
                }
                $html .= '</tr>';
            }
        }

        $html .= '</tbody></table></div>';
        $html .= $this->renderModuleLogPagination($page, $perPage, $totalFilteredLogs);
        $html .= '</form>';
        $issues = HesabfaIssueRepository::getOpen(50);
        if (is_array($issues) && !empty($issues)) {
            $html .= '<h4 class="ssb-followup-title">' . $this->l('Issues requiring follow-up') . '</h4>';
            $html .= '<div class="table-responsive"><table class="table"><thead><tr><th>ID</th><th>' . $this->l('Date / Time') . '</th><th>' . $this->l('Type') . '</th><th>' . $this->l('Status') . '</th><th>' . $this->l('Context') . '</th><th>' . $this->l('Message') . '</th><th>' . $this->l('Actions') . '</th></tr></thead><tbody>';
            foreach ($issues as $issue) {
                $context = $this->formatLogContext($issue['object_type']);
                $idIssue = (int) $issue['id_ssb_hesabfa_issue'];
                $actionUrl = htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES, 'UTF-8');
                $html .= '<tr><td>' . $idIssue . '</td><td>' . htmlspecialchars($this->formatAdminDateTime($issue['date_add']), ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars($issue['issue_type'], ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars($issue['status'], ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars($context, ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars($issue['message'], ENT_QUOTES, 'UTF-8') . '</td><td>';
                $html .= '<form method="post" action="' . $actionUrl . '" class="ssb-inline-action-form"><input type="hidden" name="id_ssb_hesabfa_issue" value="' . $idIssue . '" /><button type="submit" name="submitSsbhesabfaResolveIssue" class="btn btn-default btn-xs"><i class="icon-check"></i> ' . $this->l('Resolve') . '</button></form>';
                $html .= '<form method="post" action="' . $actionUrl . '" class="ssb-inline-action-form-last"><input type="hidden" name="id_ssb_hesabfa_issue" value="' . $idIssue . '" /><button type="submit" name="submitSsbhesabfaRetryIssue" class="btn btn-default btn-xs"><i class="icon-refresh"></i> ' . $this->l('Retry later') . '</button></form>';
                $html .= '</td></tr>';
            }
            $html .= '</tbody></table></div>';
        }
        $html .= '</div>';

        return $html;
    }

    protected function renderDebugLogDataCell(array $row)
    {
        $chunks = array();
        foreach (array('debug_payload' => $this->l('Payload'), 'debug_request' => $this->l('Request'), 'debug_response' => $this->l('Response')) as $field => $label) {
            if (!empty($row[$field])) {
                $chunks[] = '<details class="ssb-debug-log-data"><summary>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</summary><pre>' . htmlspecialchars((string) $row[$field], ENT_QUOTES, 'UTF-8') . '</pre></details>';
            }
        }
        return !empty($chunks) ? implode('', $chunks) : '-';
    }

    protected function renderModuleLogPagination($page, $perPage, $totalRows)
    {
        $totalRows = (int) $totalRows;
        $perPage = max(1, (int) $perPage);
        $totalPages = (int) ceil($totalRows / $perPage);
        if ($totalPages <= 1) {
            return '';
        }
        $page = max(1, min((int) $page, $totalPages));
        $numbers = array(1, 2, 3, $page - 2, $page - 1, $page, $page + 1, $page + 2, $totalPages - 2, $totalPages - 1, $totalPages);
        $numbers = array_filter($numbers, function ($number) use ($totalPages) {
            return $number >= 1 && $number <= $totalPages;
        });
        $numbers = array_values(array_unique(array_map('intval', $numbers)));
        sort($numbers, SORT_NUMERIC);

        $html = '<div class="ssb-pagination text-center"><ul class="pagination">';
        $previous = null;
        foreach ($numbers as $number) {
            if ($previous !== null && $number > $previous + 1) {
                $html .= '<li class="disabled"><span>&hellip;</span></li>';
            }
            $html .= '<li' . ($number === $page ? ' class="active"' : '') . '><button type="submit" name="ssb_log_page" value="' . (int) $number . '" class="btn btn-link">' . (int) $number . '</button></li>';
            $previous = $number;
        }
        $html .= '</ul></div>';
        return $html;
    }

    protected function formatLogContext($objectType)
    {
        $objectType = trim((string) $objectType);
        if ($objectType === '') {
            return '-';
        }
        $map = array(
            'Order' => $this->l('Order'),
            'Product' => $this->l('Product'),
            'Customer' => $this->l('Customer'),
            'System' => $this->l('System'),
        );
        return isset($map[$objectType]) ? $map[$objectType] : $objectType;
    }

    protected function extractPrestashopCodeFromLogRow(array $row)
    {
        $objectId = isset($row['object_id']) ? trim((string) $row['object_id']) : '';
        return $objectId !== '' ? $objectId : '-';
    }

    protected function extractHesabfaCodeFromLogRow(array $row)
    {
        $message = isset($row['message']) ? (string) $row['message'] : '';
        $patterns = array(
            '/New Hesabfa code:\s*([0-9]+)/i',
            '/Old Hesabfa code:\s*([0-9]+)/i',
            '/Hesabfa code:\s*([0-9]+)/i',
            '/Item code:\s*([0-9]+)/i',
            '/Contact code:\s*([0-9]+)/i',
            '/Service code:\s*([0-9]+)/i',
            '/Invoice number:\s*([0-9]+)/i',
            '/Payment number:\s*([0-9]+)/i',
            '/Receipt number:\s*([0-9]+)/i',
            '/Current mapped code:\s*([0-9]+)/i',
            '/New code from Hesabfa Tag:\s*([0-9]+)/i'
        );

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message, $matches)) {
                return (string) $matches[1];
            }
        }

        return '-';
    }

    protected function getDismissedItemCodeMismatchKeys()
    {
        $raw = (string) Configuration::get('SSBHESABFA_DISMISSED_ITEM_CODE_MISMATCHES');
        $items = json_decode($raw, true);
        return is_array($items) ? $items : array();
    }

    protected function getItemCodeMismatchKey($idProduct, $idAttribute, $currentCode, $proposedCode)
    {
        return (int) $idProduct . '-' . (int) $idAttribute . '-' . (int) $currentCode . '-' . (int) $proposedCode;
    }

    protected function dismissHesabfaItemCodeMismatch($idProduct, $idAttribute, $currentCode, $newCode)
    {
        $idProduct = (int) $idProduct;
        $idAttribute = (int) $idAttribute;
        $currentCode = (int) $currentCode;
        $newCode = (int) $newCode;
        if ($idProduct <= 0 || $newCode <= 0) {
            return array('success' => false, 'message' => $this->l('Invalid item code repair request.'));
        }

        $key = $this->getItemCodeMismatchKey($idProduct, $idAttribute, $currentCode, $newCode);
        $keys = $this->getDismissedItemCodeMismatchKeys();
        if (!in_array($key, $keys, true)) {
            $keys[] = $key;
        }
        Configuration::updateValue('SSBHESABFA_DISMISSED_ITEM_CODE_MISMATCHES', json_encode(array_values($keys)));
        self::addModuleLog('Hesabfa item code mismatch was dismissed manually. PrestaShop item: ' . $idProduct . '-' . $idAttribute . '. Current Hesabfa code: ' . $currentCode . '. Proposed Hesabfa code: ' . $newCode, 'INFO', null, 'Product', $idProduct . '-' . $idAttribute);
        return array('success' => true, 'message' => $this->l('Item code mismatch dismissed.'));
    }

    protected function getItemCodeMismatchHtml()
    {
        $rows = $this->getHesabfaItemCodeMismatches();
        $actionUrl = htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES, 'UTF-8');
        $html = '<div class="ssb-card ssb-card-main"><div class="ssb-card-header"><div><h3><i class="icon-random"></i> ' . $this->l('Item code mismatches') . '</h3><p>' . $this->l('Review Hesabfa item code differences and apply only the mappings you approve.') . '</p></div></div><div class="ssb-card-body">';
        $html .= '<div class="table-responsive"><table class="table"><thead><tr><th>' . $this->l('PrestaShop code') . '</th><th>' . $this->l('Current Hesabfa code') . '</th><th>' . $this->l('Proposed Hesabfa code') . '</th><th>' . $this->l('PrestaShop product') . '</th><th>' . $this->l('Hesabfa item') . '</th><th>' . $this->l('Status') . '</th><th>' . $this->l('Actions') . '</th></tr></thead><tbody>';

        if (empty($rows)) {
            $html .= '<tr><td colspan="7" class="text-center text-muted">' . $this->l('No item code mismatches found in the current scan batch.') . '</td></tr>';
        } else {
            foreach ($rows as $row) {
                $statusLabel = $row['can_apply'] ? $this->l('Ready for manual approval') : $this->l('Blocked');
                $statusClass = $row['can_apply'] ? 'label-warning' : 'label-danger';
                $psCode = (int) $row['id_product'] . '-' . (int) $row['id_product_attribute'];
                $html .= '<tr>';
                $html .= '<td>' . htmlspecialchars($psCode, ENT_QUOTES, 'UTF-8') . '</td>';
                $html .= '<td>' . htmlspecialchars((string) $row['current_hesabfa_code'], ENT_QUOTES, 'UTF-8') . '</td>';
                $html .= '<td>' . htmlspecialchars((string) $row['proposed_hesabfa_code'], ENT_QUOTES, 'UTF-8') . '</td>';
                $html .= '<td>' . htmlspecialchars($row['product_name'], ENT_QUOTES, 'UTF-8') . '</td>';
                $html .= '<td>' . htmlspecialchars($row['hesabfa_item_name'], ENT_QUOTES, 'UTF-8') . '</td>';
                $html .= '<td><span class="label ' . $statusClass . '">' . htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') . '</span><br /><small>' . htmlspecialchars($row['note'], ENT_QUOTES, 'UTF-8') . '</small></td>';
                $html .= '<td>';
                if ($row['can_apply']) {
                    $html .= '<form method="post" action="' . $actionUrl . '" class="ssb-inline-action-form" data-confirm="' . htmlspecialchars($this->l('Apply this Hesabfa item code mapping?'), ENT_QUOTES, 'UTF-8') . '">';
                    $html .= '<input type="hidden" name="id_product" value="' . (int) $row['id_product'] . '" />';
                    $html .= '<input type="hidden" name="id_product_attribute" value="' . (int) $row['id_product_attribute'] . '" />';
                    $html .= '<input type="hidden" name="new_hesabfa_code" value="' . (int) $row['proposed_hesabfa_code'] . '" />';
                    $html .= '<button type="submit" name="submitSsbhesabfaApplyItemCodeMismatch" class="btn btn-warning btn-xs"><i class="icon-check"></i> ' . $this->l('Apply') . '</button></form>';
                }
                $html .= '<form method="post" action="' . $actionUrl . '" class="ssb-inline-action-form-last" data-confirm="' . htmlspecialchars($this->l('Dismiss this mismatch without changing the mapping?'), ENT_QUOTES, 'UTF-8') . '">';
                $html .= '<input type="hidden" name="id_product" value="' . (int) $row['id_product'] . '" />';
                $html .= '<input type="hidden" name="id_product_attribute" value="' . (int) $row['id_product_attribute'] . '" />';
                $html .= '<input type="hidden" name="current_hesabfa_code" value="' . (int) $row['current_hesabfa_code'] . '" />';
                $html .= '<input type="hidden" name="new_hesabfa_code" value="' . (int) $row['proposed_hesabfa_code'] . '" />';
                $html .= '<button type="submit" name="submitSsbhesabfaDismissItemCodeMismatch" class="btn btn-default btn-xs"><i class="icon-remove"></i> ' . $this->l('Dismiss') . '</button></form>';
                $html .= '</td></tr>';
            }
        }

        $html .= '</tbody></table></div></div></div>';
        return $html;
    }

    protected function getHesabfaItemCodeMismatches()
    {
        if (!Configuration::get('SSBHESABFA_LIVE_MODE')) {
            return array();
        }

        $skip = (int) Configuration::get('SSBHESABFA_REPAIR_ITEMS_SKIP');
        $hesabfa = new HesabfaApi();
        $response = $hesabfa->itemGetItems(array('Take' => self::HESABFA_BATCH_SIZE, 'Skip' => $skip));
        if (!is_object($response) || empty($response->Success) || empty($response->Result->List) || !is_array($response->Result->List)) {
            return array();
        }

        $rows = array();
        $dismissedKeys = $this->getDismissedItemCodeMismatchKeys();
        foreach ($response->Result->List as $item) {
            if (!is_object($item) || empty($item->Tag)) {
                continue;
            }

            $tag = json_decode($item->Tag);
            if (!is_object($tag) || empty($tag->id_product)) {
                continue;
            }

            $idProduct = (int) $tag->id_product;
            $idAttribute = isset($tag->id_attribute) ? (int) $tag->id_attribute : 0;
            $proposedCode = isset($item->Code) ? (int) $item->Code : 0;
            if ($idProduct <= 0 || $proposedCode <= 0) {
                continue;
            }

            $mapping = HesabfaMappingRepository::getProductMappingRow($idProduct, $idAttribute);
            if (!is_array($mapping) || empty($mapping)) {
                continue;
            }

            $currentCode = (int) $mapping['id_hesabfa'];
            if ($currentCode === $proposedCode) {
                continue;
            }

            $mismatchKey = $this->getItemCodeMismatchKey($idProduct, $idAttribute, $currentCode, $proposedCode);
            if (in_array($mismatchKey, $dismissedKeys, true)) {
                continue;
            }

            $canApply = true;
            $note = $this->l('The proposed code is available for this mapping.');
            $existing = HesabfaMappingRepository::getProductMappingByHesabfaCode($proposedCode);
            if (is_array($existing) && !empty($existing) && (int) $existing['id_ssb_hesabfa'] !== (int) $mapping['id_ssb_hesabfa']) {
                $canApply = false;
                $note = $this->l('The proposed Hesabfa code is already mapped to another PrestaShop item.');
            }

            $product = new Product($idProduct, false, (int) $this->context->language->id);
            $productName = Validate::isLoadedObject($product) ? (string) $product->name : $this->l('Unknown product');
            $hesabfaItemName = isset($item->Name) && trim((string) $item->Name) !== '' ? (string) $item->Name : $this->l('Unknown Hesabfa item');
            $rows[] = array(
                'id_product' => $idProduct,
                'id_product_attribute' => $idAttribute,
                'current_hesabfa_code' => $currentCode,
                'proposed_hesabfa_code' => $proposedCode,
                'product_name' => $productName,
                'hesabfa_item_name' => $hesabfaItemName,
                'can_apply' => $canApply,
                'note' => $note,
            );
        }

        return $rows;
    }

    protected function applyHesabfaItemCodeMismatch($idProduct, $idAttribute, $newCode)
    {
        $idProduct = (int) $idProduct;
        $idAttribute = (int) $idAttribute;
        $newCode = (int) $newCode;
        if ($idProduct <= 0 || $newCode <= 0) {
            return array('success' => false, 'message' => $this->l('Invalid item code repair request.'));
        }

        $mapping = HesabfaMappingRepository::getProductMappingRow($idProduct, $idAttribute);
        if (!is_array($mapping) || empty($mapping)) {
            return array('success' => false, 'message' => $this->l('The PrestaShop item mapping was not found.'));
        }

        $existing = HesabfaMappingRepository::getProductMappingByHesabfaCode($newCode);
        if (is_array($existing) && !empty($existing) && (int) $existing['id_ssb_hesabfa'] !== (int) $mapping['id_ssb_hesabfa']) {
            return array('success' => false, 'message' => $this->l('The proposed Hesabfa code is already mapped to another PrestaShop item.'));
        }

        $oldCode = (int) $mapping['id_hesabfa'];
        if (!HesabfaMappingRepository::updateHesabfaCode((int) $mapping['id_ssb_hesabfa'], $newCode)) {
            return array('success' => false, 'message' => $this->l('Could not update the item mapping.'));
        }

        self::addModuleLog('Hesabfa item code mapping was manually repaired. PrestaShop item: ' . (int) $idProduct . '-' . (int) $idAttribute . '. Old Hesabfa code: ' . (int) $oldCode . '. New Hesabfa code: ' . (int) $newCode, 'INFO', null, 'Product', (int) $idProduct . '-' . (int) $idAttribute);
        return array('success' => true, 'message' => $this->l('Item code mapping updated successfully.'));
    }

    protected function getQueueCronUrl()
    {
        $token = (string) Configuration::get('SSBHESABFA_QUEUE_CRON_TOKEN');
        if ($token === '') {
            $token = bin2hex(openssl_random_pseudo_bytes(16));
            Configuration::updateValue('SSBHESABFA_QUEUE_CRON_TOKEN', $token);
        }
        return Tools::getShopDomainSsl(true, true) . __PS_BASE_URI__ . 'modules/' . $this->name . '/ssbhesabfa-cron.php?token=' . rawurlencode($token);
    }

    protected function formatQueueContext($objectType, $objectId)
    {
        $objectType = trim((string) $objectType);
        $objectId = trim((string) $objectId);
        if ($objectType === '') {
            return '-';
        }
        return $objectId !== '' ? $objectType . ' ' . $objectId : $objectType;
    }

    protected function getQueueControllerHtml()
    {
        $internalApiQueueEnabled = (bool) Configuration::get('SSBHESABFA_INTERNAL_API_USE_QUEUE');
        $activeTab = (string) Tools::getValue('ssb_queue_tab', 'jobs');
        if (!in_array($activeTab, array('jobs', 'internal_api'), true) || (!$internalApiQueueEnabled && $activeTab === 'internal_api')) {
            $activeTab = 'jobs';
        }

        $baseUrl = $this->getAdminSectionUrl('Queue');
        $jobActionUrl = HesabfaAdminQueueRenderer::buildUrl($baseUrl, array('ssb_queue_tab' => 'jobs'));
        $apiActionUrl = HesabfaAdminQueueRenderer::buildUrl($baseUrl, array('ssb_queue_tab' => 'internal_api'));
        $jobTabUrl = HesabfaAdminQueueRenderer::buildUrl($jobActionUrl, HesabfaAdminQueueRenderer::getRequestValuesByPrefix('ssb_job_'));
        $apiTabUrl = HesabfaAdminQueueRenderer::buildUrl($apiActionUrl, HesabfaAdminQueueRenderer::getRequestValuesByPrefix('ssb_api_'));

        $html = '<div class="ssb-queue-tabs-wrap">';
        $html .= '<ul class="nav nav-tabs ssb-queue-tabs" role="tablist">';
        $html .= '<li role="presentation"' . ($activeTab === 'jobs' ? ' class="active"' : '') . '><a href="' . htmlspecialchars($jobTabUrl, ENT_QUOTES, 'UTF-8') . '"><i class="icon-tasks"></i> ' . $this->l('Hesabfa request queue') . '</a></li>';
        if ($internalApiQueueEnabled) {
            $html .= '<li role="presentation"' . ($activeTab === 'internal_api' ? ' class="active"' : '') . '><a href="' . htmlspecialchars($apiTabUrl, ENT_QUOTES, 'UTF-8') . '"><i class="icon-code"></i> ' . $this->l('Internal API request queue') . '</a></li>';
        }
        $html .= '</ul><div class="tab-content ssb-queue-tab-content">';
        if ($activeTab === 'internal_api' && $internalApiQueueEnabled) {
            $html .= '<div class="tab-pane active" role="tabpanel">' . $this->getInternalApiRequestQueueHtml($apiActionUrl) . '</div>';
        } else {
            $html .= '<div class="tab-pane active" role="tabpanel">' . $this->getJobQueueHtml($jobActionUrl) . '</div>';
        }
        $html .= '</div></div>';

        return $html;
    }

    protected function getJobQueueHtml($actionUrl = null)
    {
        if ($actionUrl === null) {
            $actionUrl = HesabfaAdminQueueRenderer::buildUrl($this->getAdminSectionUrl('Queue'), array('ssb_queue_tab' => 'jobs'));
        }

        return HesabfaAdminQueueRenderer::render($this, $actionUrl, $this->getQueueCronUrl());
    }


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
        $controllerName = $this->getControllerByForm($form);
        $helper->currentIndex = $this->context->link->getAdminLink($controllerName, false);
        $helper->token = Tools::getAdminTokenLite($controllerName);
        $languages = (isset($this->context->controller) && is_object($this->context->controller))
            ? $this->context->controller->getLanguages()
            : Language::getLanguages(false);
        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFormValues($form), /* Add values for your inputs */
            'languages' => $languages,
            'id_language' => $this->context->language->id,
        );
        $function_name = 'get'.$form.'Form';
        return $helper->generateForm(array($this->$function_name()));
    }

    protected function getConfigForm()
    {
        return array(
            'form' => array(
                'input' => array(
                    array(
                        'col' => 3,
                        'type' => 'text',
                        'prefix' => '<i class="icon icon-envelope"></i>',
                        'desc' => $this->l('Enter a Hesabfa email account'),
                        'name' => 'SSBHESABFA_ACCOUNT_USERNAME',
                        'label' => $this->l('Email'),
                    ),
                    array(
                        'col' => 3,
                        'type' => 'password',
                        'desc' => $this->l('Enter a Hesabfa password'),
                        'name' => 'SSBHESABFA_ACCOUNT_PASSWORD',
                        'label' => $this->l('Password'),
                    ),
                    array(
                        'col' => 6,
                        'type' => 'text',
                        'desc' => $this->l('Find API key in Setting->Financial Settings->API Menu'),
                        'name' => 'SSBHESABFA_ACCOUNT_API',
                        'label' => $this->l('API Key'),
                    ),
                    array(
                        'col' => 6,
                        'type' => 'text',
                        'desc' => $this->l('Find Login Token in Setting->Financial Settings->API Menu'),
                        'name' => 'SSBHESABFA_ACCOUNT_TOKEN',
                        'label' => $this->l('Login Token'),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Delete module data on uninstall'),
                        'name' => 'SSBHESABFA_DELETE_DATA_ON_UNINSTALL',
                        'is_bool' => true,
                        'desc' => $this->l('When enabled, uninstall will permanently delete Hesabfa module tables, logs and module configuration. Keep disabled if you only want to reinstall or upgrade.'),
                        'values' => array(
                            array('id' => 'delete_data_on', 'value' => 1, 'label' => $this->l('Yes')),
                            array('id' => 'delete_data_off', 'value' => 0, 'label' => $this->l('No')),
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Enable automatic Hesabfa synchronization'),
                        'name' => 'SSBHESABFA_SYNC_ENABLED',
                        'is_bool' => true,
                        'desc' => $this->l('Master switch for automatic customer, product, order and deletion hooks. Existing queued jobs remain visible when disabled.'),
                        'values' => array(
                            array('id' => 'sync_enabled_on', 'value' => 1, 'label' => $this->l('Yes')),
                            array('id' => 'sync_enabled_off', 'value' => 0, 'label' => $this->l('No')),
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Queue order/payment sync from hooks'),
                        'name' => 'SSBHESABFA_ASYNC_ORDER_SYNC',
                        'is_bool' => true,
                        'desc' => $this->l('Experimental. When enabled, checkout hooks only create internal jobs and do not call Hesabfa immediately. Keep disabled in production until the job runner is configured.'),
                        'values' => array(
                            array('id' => 'async_order_on', 'value' => 1, 'label' => $this->l('Yes')),
                            array('id' => 'async_order_off', 'value' => 0, 'label' => $this->l('No')),
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Queue product sync from hooks'),
                        'name' => 'SSBHESABFA_ASYNC_PRODUCT_SYNC',
                        'is_bool' => true,
                        'desc' => $this->l('Experimental. When enabled, product hooks create internal jobs instead of syncing products immediately.'),
                        'values' => array(
                            array('id' => 'async_product_on', 'value' => 1, 'label' => $this->l('Yes')),
                            array('id' => 'async_product_off', 'value' => 0, 'label' => $this->l('No')),
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Queue customer sync from hooks'),
                        'name' => 'SSBHESABFA_ASYNC_CUSTOMER_SYNC',
                        'is_bool' => true,
                        'desc' => $this->l('Customer and address hooks are queued so account creation is not delayed by Hesabfa API calls.'),
                        'values' => array(
                            array('id' => 'async_customer_on', 'value' => 1, 'label' => $this->l('Yes')),
                            array('id' => 'async_customer_off', 'value' => 0, 'label' => $this->l('No')),
                        ),
                    ),
                    array(
                        'col' => 2,
                        'type' => 'text',
                        'label' => $this->l('API safe requests per minute'),
                        'name' => 'SSBHESABFA_RATE_LIMIT_PER_MINUTE',
                        'desc' => $this->l('Maximum local request budget per minute. Hesabfa allows 240; 200 is recommended.'),
                    ),
                    array(
                        'col' => 2,
                        'type' => 'text',
                        'label' => $this->l('Queue max attempts'),
                        'name' => 'SSBHESABFA_JOB_MAX_ATTEMPTS',
                        'desc' => $this->l('Maximum number of attempts before a failed queue job is no longer picked up automatically.'),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Queue internal API requests'),
                        'name' => 'SSBHESABFA_INTERNAL_API_USE_QUEUE',
                        'is_bool' => true,
                        'desc' => $this->l('When enabled, requests from other modules can be stored in the internal API request queue and executed later. When disabled, queued helper calls execute immediately and the internal API queue list is hidden.'),
                        'values' => array(
                            array('id' => 'internal_api_queue_on', 'value' => 1, 'label' => $this->l('Yes')),
                            array('id' => 'internal_api_queue_off', 'value' => 0, 'label' => $this->l('No')),
                        ),
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            ),
        );
    }

    protected function getBankForm()
    {
        $input_array = array();

        $bank_options = array(
            array(
                'id_option' => -1,
                'name' => $this->l('No need to set!'),
            )
        );

        $fee_type_options = array(
            array(
                'id_option' => 'none',
                'name' => $this->l('No fee'),
            ),
            array(
                'id_option' => 'shaparak_purchase',
                'name' => $this->l('Shaparak purchase fee'),
            ),
            array(
                'id_option' => 'percent',
                'name' => $this->l('Percent of payment amount'),
            ),
            array(
                'id_option' => 'fixed',
                'name' => $this->l('Fixed amount'),
            ),
        );

        $fee_payer_options = array(
            array(
                'id_option' => 'merchant',
                'name' => $this->l('Merchant pays fee'),
            ),
            array(
                'id_option' => 'customer',
                'name' => $this->l('Customer pays fee'),
            ),
        );

        $banks = $this->getCachedBanks();

        if (is_object($banks) && $banks->Success) {
            $default_currency = new Currency(
                Configuration::get('SSBHESABFA_HESABFA_DEFAULT_CURRENCY')
            );

            foreach ($banks->Result as $bank) {
                // Show only bank with default currency in Hesabfa
                if ($bank->Currency == $default_currency->iso_code) {
                    $bank_options[] = array(
                        'id_option' => $bank->Code,
                        'name' => $bank->Name . ' - ' . $bank->Branch . ' - ' . $bank->AccountNumber,
                    );
                }
            }

            foreach ($this->getPaymentMethodsName() as $item) {
                $paymentConfigId = $item['id'];

                /*
                 * Payment method title / separator
                 * Payment method visual separator.
                 */
                $input_array[] = array(
                    'type' => 'free',
                    'name' => $paymentConfigId . '_TITLE',
                    'label' => '',
                    'html_content' => '
                    <div class="ssbhesabfa-payment-block-title">
                        <strong>' . htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') . '</strong>
                        <span>' . $this->l('Bank and fee settings') . '</span>
                    </div>
                ',
                    'form_group_class' => 'ssbhesabfa-payment-block-start',
                );

                /*
                 * Bank select
                 */
                $input_array[] = array(
                    'col' => 4,
                    'type' => 'select',
                    'name' => $paymentConfigId,
                    'label' => $item['name'],
                    'desc' => $this->l('Select Hesabfa bank account for this payment method.'),
                    'form_group_class' => 'ssbhesabfa-payment-row ssbhesabfa-payment-bank-row',
                    'options' => array(
                        'query' => $bank_options,
                        'id' => 'id_option',
                        'name' => 'name'
                    )
                );

                /*
                 * Fee type
                 */
                $input_array[] = array(
                    'col' => 4,
                    'type' => 'select',
                    'name' => $paymentConfigId . '_FEE_TYPE',
                    'label' => $this->l('Fee type'),
                    'desc' => $this->l('Select how transaction fee should be calculated.'),
                    'form_group_class' => 'ssbhesabfa-payment-row ssbhesabfa-fee-type-row',
                    'options' => array(
                        'query' => $fee_type_options,
                        'id' => 'id_option',
                        'name' => 'name'
                    )
                );

                /*
                 * Fee payer
                 */
                $input_array[] = array(
                    'col' => 4,
                    'type' => 'select',
                    'name' => $paymentConfigId . '_FEE_PAYER',
                    'label' => $this->l('Fee payer'),
                    'desc' => $this->l('Who pays the transaction fee?'),
                    'form_group_class' => 'ssbhesabfa-payment-row ssbhesabfa-fee-payer-row',
                    'options' => array(
                        'query' => $fee_payer_options,
                        'id' => 'id_option',
                        'name' => 'name'
                    )
                );

                /*
                 * Fee percent
                 * Used when FEE_TYPE = percent
                 * Example: 7 means 7%
                 */
                $input_array[] = array(
                    'col' => 4,
                    'type' => 'text',
                    'name' => $paymentConfigId . '_FEE_PERCENT',
                    'label' => $this->l('Fee percent'),
                    'desc' => $this->l('Example: enter 7 for 7 percent. Used only when fee type is Percent.'),
                    'form_group_class' => 'ssbhesabfa-payment-row ssbhesabfa-fee-percent-row ssbhesabfa-fee-dependent',
                );

                /*
                 * Fixed fee
                 * Used when FEE_TYPE = fixed
                 */
                $input_array[] = array(
                    'col' => 4,
                    'type' => 'text',
                    'name' => $paymentConfigId . '_FEE_FIXED',
                    'label' => $this->l('Fixed fee amount'),
                    'desc' => $this->l('Used only when fee type is Fixed amount.'),
                    'form_group_class' => 'ssbhesabfa-payment-row ssbhesabfa-fee-fixed-row ssbhesabfa-fee-dependent',
                );

                /*
                 * Customer extra charge percent
                 *
                 * If the customer pays an extra charge over the invoice amount, enter the charge percent here.
                 */
                $input_array[] = array(
                    'col' => 4,
                    'type' => 'text',
                    'name' => $paymentConfigId . '_CUSTOMER_CHARGE_PERCENT',
                    'label' => $this->l('Customer extra charge percent'),
                    'desc' => $this->l('Example: if customer pays 10% more than invoice amount, enter 10. Used when fee payer is Customer.'),
                    'form_group_class' => 'ssbhesabfa-payment-row ssbhesabfa-customer-fee-row',
                );

                /*
                 * Income account path
                 *
                 * Income account path used when the customer extra charge is greater than the gateway transaction fee.
                 */
                $input_array[] = array(
                    'col' => 6,
                    'type' => 'text',
                    'name' => $paymentConfigId . '_INCOME_ACCOUNT_PATH',
                    'label' => $this->l('Income account path'),
                    'desc' => $this->l('Used when customer extra charge is more than transaction fee. Example: Income: Payment fee income'),
                    'form_group_class' => 'ssbhesabfa-payment-row ssbhesabfa-customer-fee-row',
                );


                /*
                 * Optional contact code for fee income accounting document.
                 * This is configured per payment method/gateway.
                 */
                $input_array[] = array(
                    'col' => 4,
                    'type' => 'text',
                    'name' => $paymentConfigId . '_FEE_INCOME_CONTACT_CODE',
                    'label' => $this->l('Fee income contact code'),
                    'desc' => $this->l('Optional. Used in payment fee income accounting document for this payment method. If empty, contactCode will not be sent to Hesabfa.'),
                    'form_group_class' => 'ssbhesabfa-payment-row ssbhesabfa-customer-fee-row',
                );
            }
        } else {
            Configuration::updateValue('SSBHESABFA_LIVE_MODE', false);
        }

        return array(
            'form' => array(
                'input' => $input_array,
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            ),
        );
    }

    protected function getAccountingTextForm()
    {
        return array(
            'form' => array(
                'description' => $this->l('These templates are used in Hesabfa payment descriptions and accounting documents. Available placeholders: {order_id}, {order_reference}, {invoice_number}, {transaction_number}.'),
                'input' => array(
                    array(
                        'col' => 6,
                        'type' => 'text',
                        'name' => 'SSBHESABFA_MANUAL_PAYMENT_DESCRIPTION_TEMPLATE',
                        'label' => $this->l('Manual payment description template'),
                    ),
                    array(
                        'col' => 6,
                        'type' => 'text',
                        'name' => 'SSBHESABFA_FEE_INCOME_DOCUMENT_DESCRIPTION_TEMPLATE',
                        'label' => $this->l('Fee income document description template'),
                    ),
                    array(
                        'col' => 6,
                        'type' => 'text',
                        'name' => 'SSBHESABFA_MANUAL_FEE_INCOME_DOCUMENT_DESCRIPTION_TEMPLATE',
                        'label' => $this->l('Manual fee income document description template'),
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            ),
        );
    }

    protected function getManualGatewayPaymentForm()
    {
        $defaultCurrency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));
        $currencyLabel = trim($defaultCurrency->name . ' (' . $defaultCurrency->iso_code . ')');

        $paymentMethodOptions = $this->getManualGatewayPaymentMethodOptions();

        if (empty($paymentMethodOptions)) {
            $paymentMethodOptions[] = array(
                'id_option' => '',
                'name' => $this->l('No eligible payment method found. Please configure a payment method with Percent of payment amount fee type.'),
            );
        }

        return array(
            'form' => array(
                'description' => $this->l('Use this form to manually register a Hesabfa invoice payment when the customer paid an extra charge through a gateway.'),
                'input' => array(
                    array(
                        'type' => 'select',
                        'label' => $this->l('Payment Method'),
                        'name' => 'SSBHESABFA_MANUAL_PAYMENT_METHOD',
                        'required' => true,
                        'desc' => $this->l('Only payment methods with Percent of payment amount fee type are shown.'),
                        'options' => array(
                            'query' => $paymentMethodOptions,
                            'id' => 'id_option',
                            'name' => 'name'
                        )
                    ),
                    array(
                        'col' => 3,
                        'type' => 'text',
                        'label' => $this->l('Hesabfa invoice number'),
                        'name' => 'SSBHESABFA_MANUAL_INVOICE_NUMBER',
                        'required' => true,
                    ),
                    array(
                        'col' => 4,
                        'type' => 'text',
                        'label' => $this->l('Order reference'),
                        'name' => 'SSBHESABFA_MANUAL_ORDER_REFERENCE',
                        'desc' => $this->l('Optional. Used for the {order_reference} placeholder in manual payment descriptions.'),
                    ),
                    array(
                        'col' => 5,
                        'type' => 'text',
                        'label' => $this->l('Total gateway paid amount'),
                        'name' => 'SSBHESABFA_MANUAL_GATEWAY_PAID_AMOUNT',
                        'required' => true,
                        'suffix' => $currencyLabel,
                        'desc' => $this->l('Enter the amount in PrestaShop default currency. The module will convert it to Hesabfa currency before sending.'),
                    ),
                    array(
                        'col' => 3,
                        'type' => 'text',
                        'label' => $this->l('Transaction number'),
                        'name' => 'SSBHESABFA_MANUAL_TRANSACTION_NUMBER',
                        'desc' => $this->l('Optional. Leave empty if there is no transaction number.'),
                    ),
                    array(
                        'col' => 4,
                        'type' => 'date',
                        'label' => $this->l('Payment date'),
                        'name' => 'SSBHESABFA_MANUAL_PAYMENT_DATE',
                        'required' => true,
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Submit payment and income document'),
                ),
            ),
        );
    }

    protected function getInvoiceForm()
    {
        // get Order States
        $options = array();
        $order_states = OrderState::getOrderStates(Context::getContext()->language->id);
        foreach ($order_states as $order_state) {
            array_push($options, array(
                'id_option' => $order_state['id_order_state'],
                'name' => $order_state['name'],
            ));
        }

        // get Salesmen
        $options2 = array();
        $salesmen_list = $this->getCachedSalesmen();

        if ($salesmen_list->Success) {
            foreach ($salesmen_list->Result as $salesmen) {
                array_push($options2, array(
                    'id_option' => $salesmen->Code,
                    'name' => $salesmen->Name,
                ));
            }
        }

        // get projects
        $options3 = array();
        $projects = $this->getCachedProjects();

        if ($projects->Success) {
            foreach ($projects->Result as $project) {
                if ($project->Active){
                    array_push($options3, array(
                        'id_option' => $project->Id,
                        'name' => $project->Title,
                    ));
                }
            }
        }

        return array(
            'form' => array(
                'input' => array(
                    array(
                        'type' => 'select',
                        'label' => $this->l('Invoice reference'),
                        'desc' => $this->l('Choose invoice reference source'),
                        'name' => 'SSBHESABFA_INVOICE_REFERENCE_TYPE',
                        'options' => array(
                            'query' => array(
                                array(
                                    'id_option' => 0,
                                    'name' => $this->l('Order ID'),
                                ),
                                array(
                                    'id_option' => 1,
                                    'name' => $this->l('Order Reference'),
                                ),
                            ),
                            'id' => 'id_option',
                            'name' => 'name'
                        )
                    ),
                    array (
                        'type' => 'select',
                        'name' => 'SSBHESABFA_INVOICE_RETURN_STATUS',
                        'label' => $this->l('Return sale invoice status'),
                        'desc' => $this->l('In what custom status should the return invoice be issued?'),
                        'options' => array(
                            'query' => $options,
                            'id' => 'id_option',
                            'name' => 'name'
                        )
                    ),
                    array (
                        'type' => 'select',
                        'name' => 'SSBHESABFA_INVOICE_SALESMEN',
                        'label' => $this->l('OnlineStore salesman code'),
                        'options' => array(
                            'query' => $options2,
                            'id' => 'id_option',
                            'name' => 'name'
                        )
                    ),
                    array (
                        'type' => 'select',
                        'name' => 'SSBHESABFA_INVOICE_PROJECT',
                        'label' => $this->l('Projects'),
                        'options' => array(
                            'query' => $options3,
                            'id' => 'name',
                            'name' => 'name'
                        )
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
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
                        'type' => 'switch',
                        'label' => $this->l('Use Hesabfa item code as product reference'),
                        'name' => 'SSBHESABFA_ITEM_CODE_AS_REFERENCE',
                        'is_bool' => true,
                        'desc' => $this->l('When enabled, existing product and combination references are overwritten with their mapped Hesabfa item codes. Saving this section repairs all existing mappings.'),
                        'values' => array(
                            array(
                                'id' => 'item_code_reference_on',
                                'value' => true,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id' => 'item_code_reference_off',
                                'value' => false,
                                'label' => $this->l('Disabled')
                            )
                        ),
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->l('Barcode:'),
                        'desc' => $this->l('Choose which data field selected for Barcode'),
                        'name' => 'SSBHESABFA_ITEM_BARCODE',
                        'options' => array(
                            'query' => array(
                                array(
                                    'id_option' => 0,
                                    'name' => $this->l('Do not use barcode'),
                                ),
                                array(
                                    'id_option' => 1,
                                    'name' => $this->l('Reference'),
                                ),
                                array(
                                    'id_option' => 2,
                                    'name' => $this->l('UPC barcode'),
                                ),
                                array(
                                    'id_option' => 3,
                                    'name' => $this->l('EAN-13 or JAN barcode'),
                                ),
                                array(
                                    'id_option' => 4,
                                    'name' => $this->l('ISBN'),
                                ),
                            ),
                            'id' => 'id_option',
                            'name' => 'name'
                        )
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
                        'label' => $this->l('Update Customer Address:'),
                        'desc' => $this->l('Choose when update Customer address in Hesabfa'),
                        'name' => 'SSBHESABFA_CONTACT_ADDRESS_STATUS',
                        'options' => array(
                            'query' => array(
                                array(
                                    'id_option' => 1,
                                    'name' => $this->l('Use first customer address'),
                                ),
                                array(
                                    'id_option' => 2,
                                    'name' => $this->l('update address with Invoice address'),
                                ),
                                array(
                                    'id_option' => 3,
                                    'name' => $this->l('update address with Delivery address'),
                                ),
                            ),
                            'id' => 'id_option',
                            'name' => 'name'
                        )
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
                ),
            ),
        );
    }

    protected function getBankFormStyle()
    {
        return '';
    }

    protected function getBankFeeToggleScript()
    {
        return '';
    }

    protected function getConfigFormValues($form = null)
    {
        switch ($form) {
            case 'Config':
                $keys =  array(
                    'SSBHESABFA_ACCOUNT_USERNAME' => Configuration::get('SSBHESABFA_ACCOUNT_USERNAME'),
                    'SSBHESABFA_ACCOUNT_PASSWORD' => Configuration::get('SSBHESABFA_ACCOUNT_PASSWORD'),
                    'SSBHESABFA_ACCOUNT_API' => Configuration::get('SSBHESABFA_ACCOUNT_API'),
                    'SSBHESABFA_ACCOUNT_TOKEN' => Configuration::get('SSBHESABFA_ACCOUNT_TOKEN'),
                    'SSBHESABFA_DELETE_DATA_ON_UNINSTALL' => (int) Configuration::get('SSBHESABFA_DELETE_DATA_ON_UNINSTALL'),
                    'SSBHESABFA_SYNC_ENABLED' => (int) Configuration::get('SSBHESABFA_SYNC_ENABLED'),
                    'SSBHESABFA_ASYNC_ORDER_SYNC' => (int) Configuration::get('SSBHESABFA_ASYNC_ORDER_SYNC'),
                    'SSBHESABFA_ASYNC_PRODUCT_SYNC' => (int) Configuration::get('SSBHESABFA_ASYNC_PRODUCT_SYNC'),
                    'SSBHESABFA_ASYNC_CUSTOMER_SYNC' => (int) Configuration::get('SSBHESABFA_ASYNC_CUSTOMER_SYNC'),
                    'SSBHESABFA_RATE_LIMIT_PER_MINUTE' => (int) Configuration::get('SSBHESABFA_RATE_LIMIT_PER_MINUTE'),
                    'SSBHESABFA_INTERNAL_API_USE_QUEUE' => (int) Configuration::get('SSBHESABFA_INTERNAL_API_USE_QUEUE'),
                    'SSBHESABFA_JOB_MAX_ATTEMPTS' => (int) Configuration::get('SSBHESABFA_JOB_MAX_ATTEMPTS'),
                );
                break;
            case 'Item':
                $keys =  array(
                    'SSBHESABFA_ITEM_BARCODE' => Configuration::get('SSBHESABFA_ITEM_BARCODE'),
                    'SSBHESABFA_ITEM_CODE_AS_REFERENCE' => Configuration::get('SSBHESABFA_ITEM_CODE_AS_REFERENCE'),
                    'SSBHESABFA_ITEM_UPDATE_PRICE' => Configuration::get('SSBHESABFA_ITEM_UPDATE_PRICE'),
                    'SSBHESABFA_ITEM_UPDATE_QUANTITY' => Configuration::get('SSBHESABFA_ITEM_UPDATE_QUANTITY'),
                );
                break;
            case 'Contact':
                $keys =  array(
                    'SSBHESABFA_CONTACT_ADDRESS_STATUS' => Configuration::get('SSBHESABFA_CONTACT_ADDRESS_STATUS'),
                    'SSBHESABFA_CONTACT_NODE_FAMILY' => Configuration::get('SSBHESABFA_CONTACT_NODE_FAMILY'),
                );
                break;
            case 'Invoice':
                $keys =  array(
                    'SSBHESABFA_INVOICE_RETURN_STATUS' => Configuration::get('SSBHESABFA_INVOICE_RETURN_STATUS'),
                    'SSBHESABFA_INVOICE_REFERENCE_TYPE' => Configuration::get('SSBHESABFA_INVOICE_REFERENCE_TYPE'),
                    'SSBHESABFA_INVOICE_SALESMEN' => Configuration::get('SSBHESABFA_INVOICE_SALESMEN'),
                    'SSBHESABFA_INVOICE_PROJECT' => Configuration::get('SSBHESABFA_INVOICE_PROJECT'),
                );
                break;
            case 'Bank':
                $keys = array();

                foreach ($this->getPaymentMethodsName() as $item) {
                    $keys[$item['id'] . '_TITLE'] = '';
                    $keys[$item['id']] = Configuration::get($item['id']);

                    $keys[$item['id'] . '_FEE_TYPE'] = Configuration::get($item['id'] . '_FEE_TYPE');
                    $keys[$item['id'] . '_FEE_PERCENT'] = Configuration::get($item['id'] . '_FEE_PERCENT');
                    $keys[$item['id'] . '_FEE_FIXED'] = Configuration::get($item['id'] . '_FEE_FIXED');

                    $keys[$item['id'] . '_FEE_PAYER'] = Configuration::get($item['id'] . '_FEE_PAYER');
                    $keys[$item['id'] . '_CUSTOMER_CHARGE_PERCENT'] = Configuration::get($item['id'] . '_CUSTOMER_CHARGE_PERCENT');
                    $keys[$item['id'] . '_INCOME_ACCOUNT_PATH'] = Configuration::get($item['id'] . '_INCOME_ACCOUNT_PATH');
                    $keys[$item['id'] . '_FEE_INCOME_CONTACT_CODE'] = Configuration::get($item['id'] . '_FEE_INCOME_CONTACT_CODE');
                }
                break;
            case 'AccountingText':
                $keys = array(
                    'SSBHESABFA_MANUAL_PAYMENT_DESCRIPTION_TEMPLATE' => Configuration::get('SSBHESABFA_MANUAL_PAYMENT_DESCRIPTION_TEMPLATE'),
                    'SSBHESABFA_FEE_INCOME_DOCUMENT_DESCRIPTION_TEMPLATE' => Configuration::get('SSBHESABFA_FEE_INCOME_DOCUMENT_DESCRIPTION_TEMPLATE'),
                    'SSBHESABFA_MANUAL_FEE_INCOME_DOCUMENT_DESCRIPTION_TEMPLATE' => Configuration::get('SSBHESABFA_MANUAL_FEE_INCOME_DOCUMENT_DESCRIPTION_TEMPLATE'),
                );
                break;
            case 'ManualGatewayPayment':
                $keys = array(
                    'SSBHESABFA_MANUAL_PAYMENT_METHOD' => Tools::getValue('SSBHESABFA_MANUAL_PAYMENT_METHOD'),
                    'SSBHESABFA_MANUAL_INVOICE_NUMBER' => Tools::getValue('SSBHESABFA_MANUAL_INVOICE_NUMBER'),
                    'SSBHESABFA_MANUAL_GATEWAY_PAID_AMOUNT' => Tools::getValue('SSBHESABFA_MANUAL_GATEWAY_PAID_AMOUNT'),
                    'SSBHESABFA_MANUAL_TRANSACTION_NUMBER' => Tools::getValue('SSBHESABFA_MANUAL_TRANSACTION_NUMBER'),
                    'SSBHESABFA_MANUAL_ORDER_REFERENCE' => Tools::getValue('SSBHESABFA_MANUAL_ORDER_REFERENCE'),
                    'SSBHESABFA_MANUAL_PAYMENT_DATE' => Tools::getValue('SSBHESABFA_MANUAL_PAYMENT_DATE', date('Y-m-d')),
                );
                break;

            default:
                $keys =  array(
                    'SSBHESABFA_ACCOUNT_USERNAME' => Configuration::get('SSBHESABFA_ACCOUNT_USERNAME'),
                    'SSBHESABFA_ACCOUNT_PASSWORD' => Configuration::get('SSBHESABFA_ACCOUNT_PASSWORD'),
                    'SSBHESABFA_ACCOUNT_API' => Configuration::get('SSBHESABFA_ACCOUNT_API'),
                    'SSBHESABFA_ACCOUNT_TOKEN' => Configuration::get('SSBHESABFA_ACCOUNT_TOKEN'),
                    'SSBHESABFA_DELETE_DATA_ON_UNINSTALL' => (int) Configuration::get('SSBHESABFA_DELETE_DATA_ON_UNINSTALL'),
                    'SSBHESABFA_SYNC_ENABLED' => (int) Configuration::get('SSBHESABFA_SYNC_ENABLED'),
                    'SSBHESABFA_ASYNC_ORDER_SYNC' => (int) Configuration::get('SSBHESABFA_ASYNC_ORDER_SYNC'),
                    'SSBHESABFA_ASYNC_PRODUCT_SYNC' => (int) Configuration::get('SSBHESABFA_ASYNC_PRODUCT_SYNC'),
                    'SSBHESABFA_ASYNC_CUSTOMER_SYNC' => (int) Configuration::get('SSBHESABFA_ASYNC_CUSTOMER_SYNC'),
                    'SSBHESABFA_RATE_LIMIT_PER_MINUTE' => (int) Configuration::get('SSBHESABFA_RATE_LIMIT_PER_MINUTE'),
                    'SSBHESABFA_INTERNAL_API_USE_QUEUE' => (int) Configuration::get('SSBHESABFA_INTERNAL_API_USE_QUEUE'),

                    'SSBHESABFA_ITEM_BARCODE' => Configuration::get('SSBHESABFA_ITEM_BARCODE'),
                    'SSBHESABFA_ITEM_CODE_AS_REFERENCE' => Configuration::get('SSBHESABFA_ITEM_CODE_AS_REFERENCE'),
                    'SSBHESABFA_ITEM_UPDATE_PRICE' => Configuration::get('SSBHESABFA_ITEM_UPDATE_PRICE'),
                    'SSBHESABFA_ITEM_UPDATE_QUANTITY' => Configuration::get('SSBHESABFA_ITEM_UPDATE_QUANTITY'),

                    'SSBHESABFA_CONTACT_ADDRESS_STATUS' => Configuration::get('SSBHESABFA_CONTACT_ADDRESS_STATUS'),
                    'SSBHESABFA_CONTACT_NODE_FAMILY' => Configuration::get('SSBHESABFA_CONTACT_NODE_FAMILY'),

                    'SSBHESABFA_INVOICE_RETURN_STATUS' => Configuration::get('SSBHESABFA_INVOICE_RETURN_STATUS'),
                    'SSBHESABFA_INVOICE_REFERENCE_TYPE' => Configuration::get('SSBHESABFA_INVOICE_REFERENCE_TYPE'),
                    'SSBHESABFA_INVOICE_SALESMEN' => Configuration::get('SSBHESABFA_INVOICE_SALESMEN'),
                    'SSBHESABFA_INVOICE_PROJECT' => Configuration::get('SSBHESABFA_INVOICE_PROJECT'),
                    'SSBHESABFA_MANUAL_PAYMENT_DESCRIPTION_TEMPLATE' => Configuration::get('SSBHESABFA_MANUAL_PAYMENT_DESCRIPTION_TEMPLATE'),
                    'SSBHESABFA_FEE_INCOME_DOCUMENT_DESCRIPTION_TEMPLATE' => Configuration::get('SSBHESABFA_FEE_INCOME_DOCUMENT_DESCRIPTION_TEMPLATE'),
                    'SSBHESABFA_MANUAL_FEE_INCOME_DOCUMENT_DESCRIPTION_TEMPLATE' => Configuration::get('SSBHESABFA_MANUAL_FEE_INCOME_DOCUMENT_DESCRIPTION_TEMPLATE'),
                );

                //Get config form value in Payment Method's tab
                $paymentsName = $this->getPaymentMethodsName();
                foreach ($paymentsName as $item) {
                    $keys[$item['id'] . '_TITLE'] = '';
                    $keys[$item['id']] = Configuration::get($item['id']);
                    $keys[$item['id'] . '_FEE_TYPE'] = Configuration::get($item['id'] . '_FEE_TYPE');
                    $keys[$item['id'] . '_FEE_PERCENT'] = Configuration::get($item['id'] . '_FEE_PERCENT');
                    $keys[$item['id'] . '_FEE_FIXED'] = Configuration::get($item['id'] . '_FEE_FIXED');
                    $keys[$item['id'] . '_FEE_PAYER'] = Configuration::get($item['id'] . '_FEE_PAYER');
                    $keys[$item['id'] . '_CUSTOMER_CHARGE_PERCENT'] = Configuration::get($item['id'] . '_CUSTOMER_CHARGE_PERCENT');
                    $keys[$item['id'] . '_INCOME_ACCOUNT_PATH'] = Configuration::get($item['id'] . '_INCOME_ACCOUNT_PATH');
                    $keys[$item['id'] . '_FEE_INCOME_CONTACT_CODE'] = Configuration::get($item['id'] . '_FEE_INCOME_CONTACT_CODE');
                }
        }
        return $keys;
    }

    protected function setConfigFormsValues($form = null)
    {
        $form_values = $this->getConfigFormValues($form);
        $success = true;

        foreach (array_keys($form_values) as $key) {
            $value = Tools::getValue($key);

            // Don't replace password with null if password not entered
            $control1 = $key == 'SSBHESABFA_ACCOUNT_PASSWORD' && $value == null;

            // Don't add bank map if bank is not defined in Hesabfa
            // Only the bank field, not fee-related fields.
            $isPaymentBankField =
                strpos($key, 'SSBHESABFA_PAYMENT_METHOD_') !== false
                && strpos($key, '_TITLE') === false
                && strpos($key, '_FEE_TYPE') === false
                && strpos($key, '_FEE_PERCENT') === false
                && strpos($key, '_FEE_FIXED') === false
                && strpos($key, '_FEE_PAYER') === false
                && strpos($key, '_CUSTOMER_CHARGE_PERCENT') === false
                && strpos($key, '_INCOME_ACCOUNT_PATH') === false
                && strpos($key, '_FEE_INCOME_CONTACT_CODE') === false;

            $control2 = $isPaymentBankField && ($value === '0' || $value === 0);

            if ($control1 || $control2 || strpos($key, '_TITLE') !== false) {
                continue;
            }

            // Default fee type
            if (strpos($key, '_FEE_TYPE') !== false && ($value === null || $value === '')) {
                $value = 'none';
            }

            // Normalize percent and fixed fee values
            if (
                strpos($key, '_FEE_PERCENT') !== false
                || strpos($key, '_FEE_FIXED') !== false
                || strpos($key, '_CUSTOMER_CHARGE_PERCENT') !== false
            ) {
                $value = str_replace(',', '.', (string) $value);
                $value = trim($value);

                if ($value === '' || !is_numeric($value)) {
                    $value = 0;
                }
            }

            if (strpos($key, '_FEE_PAYER') !== false && ($value === null || $value === '')) {
                $value = 'merchant';
            }

            if (!Configuration::updateValue($key, $value)) {
                $success = false;
            }
        }

        if (
            $form === 'Item'
            && Configuration::get('SSBHESABFA_ITEM_CODE_AS_REFERENCE')
            && !HesabfaMappingRepository::syncAllProductReferences()
        ) {
            $success = false;
        }

        return $success;
    }
}
