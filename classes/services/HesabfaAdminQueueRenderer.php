<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class HesabfaAdminQueueRenderer
{
    public static function render($module, $actionUrl, $cronUrl)
    {
        $filters = array(
            'id' => trim((string) Tools::getValue('ssb_job_id', '')),
            'status' => trim((string) Tools::getValue('ssb_job_status', '')),
            'job_type' => trim((string) Tools::getValue('ssb_job_type', '')),
            'object_type' => trim((string) Tools::getValue('ssb_job_object_type', '')),
            'object_id' => trim((string) Tools::getValue('ssb_job_object_id', '')),
            'error_code' => trim((string) Tools::getValue('ssb_job_error_code', '')),
            'date_from' => trim((string) Tools::getValue('ssb_job_date_from', '')),
            'date_to' => trim((string) Tools::getValue('ssb_job_date_to', '')),
            'keyword' => trim((string) Tools::getValue('ssb_job_keyword', '')),
        );
        $perPage = 50;
        $totalRows = HesabfaJobRepository::countList($filters);
        $totalPages = max(1, (int) ceil($totalRows / $perPage));
        $page = max(1, min((int) Tools::getValue('ssb_job_page', 1), $totalPages));
        $jobs = HesabfaJobRepository::getList($filters, $perPage, ($page - 1) * $perPage);

        $postActionFields = self::getRequestValuesByPrefix('ssb_job_');
        $postActionUrl = htmlspecialchars(self::buildUrl($actionUrl, $postActionFields), ENT_QUOTES, 'UTF-8');
        $filterHidden = array('ssb_job_page' => 1);
        $clearUrl = $actionUrl;

        $html = '<div class="ssb-card ssb-card-main"><div class="ssb-card-header"><div><h3><i class="icon-tasks"></i> ' . $module->l('Hesabfa request queue') . '</h3><p>' . $module->l('Queued requests are classified as retryable, manual attention, duplicate check, done, or dead.') . '</p></div><div class="ssb-card-actions"><form method="post" action="' . $postActionUrl . '"><button type="submit" name="submitSsbhesabfaRunPendingJobs" class="btn btn-primary"><i class="icon-play"></i> ' . $module->l('Run pending jobs') . '</button></form></div></div><div class="ssb-card-body">';
        $html .= '<div class="alert alert-info"><strong>' . $module->l('Cron URL:') . '</strong> <code>' . htmlspecialchars($cronUrl, ENT_QUOTES, 'UTF-8') . '</code></div>';
        $html .= self::renderJobFilterForm($module, $actionUrl, $filters, $filterHidden, $clearUrl);
        $html .= '<div class="table-responsive"><table class="table"><thead><tr><th>' . $module->l('ID') . '</th><th>' . $module->l('Date / Time') . '</th><th>' . $module->l('Type') . '</th><th>' . $module->l('Status') . '</th><th>' . $module->l('Context') . '</th><th>' . $module->l('Attempts') . '</th><th>' . $module->l('Next run') . '</th><th>' . $module->l('Error code') . '</th><th>' . $module->l('Request UUID') . '</th><th>' . $module->l('Payload hash') . '</th><th>' . $module->l('Last error') . '</th><th>' . $module->l('Actions') . '</th></tr></thead><tbody>';
        if (!$jobs) {
            $html .= '<tr><td colspan="12" class="text-center text-muted">' . $module->l('No queued requests found.') . '</td></tr>';
        }
        foreach ($jobs as $job) {
            $id = (int) $job['id_ssb_hesabfa_job'];
            $status = (string) $job['status'];
            $context = trim($job['object_type'] . ' ' . $job['object_id']);
            $uuid = self::getFirstUuid(isset($job['request_unique_ids']) ? $job['request_unique_ids'] : null);
            $hash = isset($job['request_payload_hash']) ? (string) $job['request_payload_hash'] : '';
            $html .= '<tr><td>' . $id . '</td><td>' . htmlspecialchars($module->formatAdminDateTimePublic($job['date_add']), ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars($job['job_type'], ENT_QUOTES, 'UTF-8') . '</td><td><strong>' . htmlspecialchars(self::getStatusLabel($module, $status), ENT_QUOTES, 'UTF-8') . '</strong><br><small><code>' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '</code></small></td><td>' . htmlspecialchars($context ?: '-', ENT_QUOTES, 'UTF-8') . '</td><td>' . (int) $job['attempts'] . '</td><td>' . htmlspecialchars((string) $job['next_run_at'], ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars((string) $job['last_error_code'], ENT_QUOTES, 'UTF-8') . '</td><td><code style="word-break:break-all">' . htmlspecialchars($uuid ?: '-', ENT_QUOTES, 'UTF-8') . '</code></td><td><code>' . htmlspecialchars($hash ? substr($hash, 0, 12) : '-', ENT_QUOTES, 'UTF-8') . '</code></td><td>' . htmlspecialchars((string) $job['last_error'], ENT_QUOTES, 'UTF-8') . '</td><td>';

            $hasAction = false;
            if (in_array($status, array('dead', 'needs_attention', 'duplicate_check'), true)) {
                $newOperationLabel = $status === 'duplicate_check' ? $module->l('Checked; start new operation') : $module->l('Start new operation');
                $newOperationConfirm = $status === 'duplicate_check'
                    ? $module->l('Only continue after checking Hesabfa and confirming that the previous operation did not succeed. Start a new operation with a new UUID?')
                    : $module->l('Only continue after correcting the underlying error. Start a new operation with a new UUID?');
                $html .= '<form method="post" action="' . $postActionUrl . '" class="ssb-inline-action-form" data-confirm="' . htmlspecialchars($newOperationConfirm, ENT_QUOTES, 'UTF-8') . '"><input type="hidden" name="id_ssb_hesabfa_job" value="' . $id . '"><button type="submit" name="submitSsbhesabfaRequeueJob" class="btn btn-warning btn-xs"><i class="icon-refresh"></i> ' . $newOperationLabel . '</button></form>';
                $hasAction = true;
            } elseif (in_array($status, array('pending', 'retry_wait'), true)) {
                $html .= '<form method="post" action="' . $postActionUrl . '" class="ssb-inline-action-form"><input type="hidden" name="id_ssb_hesabfa_job" value="' . $id . '"><button type="submit" name="submitSsbhesabfaRunJob" class="btn btn-default btn-xs"><i class="icon-play"></i> ' . $module->l('Run now') . '</button></form>';
                $hasAction = true;
            }

            if (in_array($status, array('pending', 'retry_wait', 'needs_attention', 'duplicate_check'), true)) {
                $html .= '<form method="post" action="' . $postActionUrl . '" class="ssb-inline-action-form-last" data-confirm="' . htmlspecialchars($module->l('Mark this job as dead? It will no longer run automatically.'), ENT_QUOTES, 'UTF-8') . '"><input type="hidden" name="id_ssb_hesabfa_job" value="' . $id . '"><button type="submit" name="submitSsbhesabfaMarkJobDead" class="btn btn-danger btn-xs"><i class="icon-ban-circle"></i> ' . $module->l('Mark as dead') . '</button></form>';
                $hasAction = true;
            }

            if (!$hasAction) {
                $html .= '<span class="text-muted">-</span>';
            }
            $html .= '</td></tr>';
        }
        $html .= '</tbody></table></div>';

        $paginationFields = array(
            'ssb_job_id' => $filters['id'],
            'ssb_job_status' => $filters['status'],
            'ssb_job_type' => $filters['job_type'],
            'ssb_job_object_type' => $filters['object_type'],
            'ssb_job_object_id' => $filters['object_id'],
            'ssb_job_error_code' => $filters['error_code'],
            'ssb_job_date_from' => $filters['date_from'],
            'ssb_job_date_to' => $filters['date_to'],
            'ssb_job_keyword' => $filters['keyword'],
        );
        $html .= self::renderCompactPagination($actionUrl, 'ssb_job_page', $page, $totalPages, $paginationFields);
        return $html . '</div></div>';
    }

    protected static function renderJobFilterForm($module, $actionUrl, array $filters, array $hiddenFields, $clearUrl)
    {
        $html = self::openGetForm($actionUrl, $hiddenFields, 'ssb-queue-filter-form');
        $html .= '<div class="row">';
        $html .= '<div class="col-lg-1 col-md-2"><label>' . $module->l('ID') . '</label><input type="text" name="ssb_job_id" class="form-control" value="' . htmlspecialchars($filters['id'], ENT_QUOTES, 'UTF-8') . '" /></div>';
        $html .= '<div class="col-lg-2 col-md-3"><label>' . $module->l('Status') . '</label><select name="ssb_job_status" class="form-control"><option value="">' . $module->l('All') . '</option>';
        foreach (array('pending', 'running', 'retry_wait', 'needs_attention', 'duplicate_check', 'done', 'dead') as $status) {
            $html .= '<option value="' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '"' . ($filters['status'] === $status ? ' selected="selected"' : '') . '>' . htmlspecialchars(self::getStatusLabel($module, $status), ENT_QUOTES, 'UTF-8') . '</option>';
        }
        $html .= '</select></div>';
        $html .= '<div class="col-lg-2 col-md-3"><label>' . $module->l('Job type') . '</label><input type="text" name="ssb_job_type" class="form-control" value="' . htmlspecialchars($filters['job_type'], ENT_QUOTES, 'UTF-8') . '" /></div>';
        $html .= '<div class="col-lg-2 col-md-3"><label>' . $module->l('Object type') . '</label><input type="text" name="ssb_job_object_type" class="form-control" value="' . htmlspecialchars($filters['object_type'], ENT_QUOTES, 'UTF-8') . '" /></div>';
        $html .= '<div class="col-lg-2 col-md-3"><label>' . $module->l('Object ID') . '</label><input type="text" name="ssb_job_object_id" class="form-control" value="' . htmlspecialchars($filters['object_id'], ENT_QUOTES, 'UTF-8') . '" /></div>';
        $html .= '<div class="col-lg-3 col-md-4"><label>' . $module->l('Error code') . '</label><input type="text" name="ssb_job_error_code" class="form-control" value="' . htmlspecialchars($filters['error_code'], ENT_QUOTES, 'UTF-8') . '" /></div>';
        $html .= '</div><div class="row ssb-queue-filter-row">';
        $html .= '<div class="col-lg-2 col-md-3"><label>' . $module->l('From') . '</label><input type="date" name="ssb_job_date_from" class="form-control" value="' . htmlspecialchars($filters['date_from'], ENT_QUOTES, 'UTF-8') . '" /></div>';
        $html .= '<div class="col-lg-2 col-md-3"><label>' . $module->l('To') . '</label><input type="date" name="ssb_job_date_to" class="form-control" value="' . htmlspecialchars($filters['date_to'], ENT_QUOTES, 'UTF-8') . '" /></div>';
        $html .= '<div class="col-lg-5 col-md-6"><label>' . $module->l('Keyword') . '</label><input type="text" name="ssb_job_keyword" class="form-control" value="' . htmlspecialchars($filters['keyword'], ENT_QUOTES, 'UTF-8') . '" placeholder="' . htmlspecialchars($module->l('Search payload, UUID, hash or error text'), ENT_QUOTES, 'UTF-8') . '" /></div>';
        $html .= '<div class="col-lg-3 col-md-12 ssb-queue-filter-actions"><button type="submit" class="btn btn-primary"><i class="icon-search"></i> ' . $module->l('Filter') . '</button> <a class="btn btn-default" href="' . htmlspecialchars($clearUrl, ENT_QUOTES, 'UTF-8') . '"><i class="icon-remove"></i> ' . $module->l('Clear filters') . '</a></div>';
        $html .= '</div></form>';
        return $html;
    }

    public static function getStatusLabel($module, $status)
    {
        $labels = array(
            'pending' => $module->l('Pending'),
            'running' => $module->l('Running'),
            'retry_wait' => $module->l('Waiting for retry'),
            'needs_attention' => $module->l('Needs attention'),
            'duplicate_check' => $module->l('Duplicate check required'),
            'done' => $module->l('Done'),
            'dead' => $module->l('Dead'),
        );
        return isset($labels[$status]) ? $labels[$status] : (string) $status;
    }

    public static function renderCompactPagination($actionUrl, $pageField, $page, $totalPages, array $hiddenFields = array())
    {
        $totalPages = max(1, (int) $totalPages);
        if ($totalPages <= 1) {
            return '';
        }
        $page = max(1, min((int) $page, $totalPages));
        $pages = self::getCompactPageNumbers($page, $totalPages);
        $html = self::openGetForm($actionUrl, $hiddenFields, 'ssb-pagination-form');
        $html .= '<div class="ssb-pagination text-center"><ul class="pagination">';
        $previous = null;
        foreach ($pages as $number) {
            if ($previous !== null && $number > $previous + 1) {
                $html .= '<li class="disabled"><span>&hellip;</span></li>';
            }
            $html .= '<li' . ($number === $page ? ' class="active"' : '') . '><button type="submit" name="' . htmlspecialchars($pageField, ENT_QUOTES, 'UTF-8') . '" value="' . (int) $number . '" class="btn btn-link">' . (int) $number . '</button></li>';
            $previous = $number;
        }
        $html .= '</ul></div></form>';
        return $html;
    }

    public static function openGetForm($actionUrl, array $hiddenFields = array(), $class = '')
    {
        list($action, $baseFields) = self::parseActionUrl($actionUrl);
        $fields = array_merge($baseFields, $hiddenFields);
        $html = '<form method="get" action="' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . '"' . ($class !== '' ? ' class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '"' : '') . '>';
        foreach ($fields as $name => $value) {
            if ($value === null || is_array($value) || is_object($value)) {
                continue;
            }
            $html .= '<input type="hidden" name="' . htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '" />';
        }
        return $html;
    }

    public static function buildUrl($actionUrl, array $extraFields = array())
    {
        list($action, $baseFields) = self::parseActionUrl($actionUrl);
        $fields = array_merge($baseFields, $extraFields);
        foreach ($fields as $key => $value) {
            if ($value === null || $value === '' || is_array($value) || is_object($value)) {
                unset($fields[$key]);
            }
        }
        return $action . (!empty($fields) ? '?' . http_build_query($fields) : '');
    }

    public static function getRequestValuesByPrefix($prefix)
    {
        $result = array();
        foreach ($_GET as $key => $value) {
            if (strpos((string) $key, (string) $prefix) !== 0 || !is_scalar($value)) {
                continue;
            }
            $result[(string) $key] = (string) $value;
        }
        return $result;
    }

    protected static function parseActionUrl($actionUrl)
    {
        $url = html_entity_decode((string) $actionUrl, ENT_QUOTES, 'UTF-8');
        $parts = parse_url($url);
        if ($parts === false) {
            return array($url, array());
        }
        $action = '';
        if (isset($parts['scheme'])) {
            $action .= $parts['scheme'] . '://';
            if (isset($parts['user'])) {
                $action .= $parts['user'];
                if (isset($parts['pass'])) {
                    $action .= ':' . $parts['pass'];
                }
                $action .= '@';
            }
            $action .= isset($parts['host']) ? $parts['host'] : '';
            if (isset($parts['port'])) {
                $action .= ':' . (int) $parts['port'];
            }
        }
        $action .= isset($parts['path']) ? $parts['path'] : '';
        if ($action === '') {
            $action = $url;
        }
        $queryFields = array();
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $queryFields);
        }
        return array($action, $queryFields);
    }

    protected static function getCompactPageNumbers($page, $totalPages)
    {
        $numbers = array(1, 2, 3, $page - 2, $page - 1, $page, $page + 1, $page + 2, $totalPages - 2, $totalPages - 1, $totalPages);
        $numbers = array_filter($numbers, function ($number) use ($totalPages) {
            return $number >= 1 && $number <= $totalPages;
        });
        $numbers = array_values(array_unique(array_map('intval', $numbers)));
        sort($numbers, SORT_NUMERIC);
        return $numbers;
    }

    private static function getFirstUuid($json)
    {
        if (!$json) {
            return null;
        }
        $values = json_decode($json, true);
        if (!is_array($values)) {
            return null;
        }
        foreach ($values as $value) {
            if (HesabfaRequestUniqueId::isValidGuid($value)) {
                return (string) $value;
            }
        }
        return null;
    }
}
