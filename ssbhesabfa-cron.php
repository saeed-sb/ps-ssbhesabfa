<?php
require_once dirname(__FILE__) . '/../../config/config.inc.php';
require_once dirname(__FILE__) . '/../../init.php';

header('Content-Type: application/json; charset=utf-8');

$token = Tools::getValue('token');
$expected = (string) Configuration::get('SSBHESABFA_QUEUE_CRON_TOKEN');
if ($expected === '' || !hash_equals($expected, (string) $token)) {
    http_response_code(403);
    echo json_encode(array('success' => false, 'error' => 'Invalid token.'));
    exit;
}

$module = Module::getInstanceByName('ssbhesabfa');
if (!$module || !Validate::isLoadedObject($module)) {
    http_response_code(500);
    echo json_encode(array('success' => false, 'error' => 'Module is not available.'));
    exit;
}

$limit = (int) Tools::getValue('limit', 20);
$limit = max(1, min(50, $limit));

try {
    $jobs = (int) $module->processPendingHesabfaJobs($limit);
    $internal = 0;
    if (Configuration::get('SSBHESABFA_INTERNAL_API_USE_QUEUE')) {
        $remaining = max(0, $limit - $jobs);
        if ($remaining > 0) {
            $internal = (int) $module->processPendingInternalApiRequests($remaining);
        }
    }

    echo json_encode(array(
        'success' => true,
        'processed_jobs' => $jobs,
        'processed_internal_api_requests' => $internal,
    ));
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(array('success' => false, 'error' => $e->getMessage()));
}
