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
    $webhook = array();
    $webhookLimit = (int) Tools::getValue("webhook_limit", 80);
    $webhookLimit = max(0, min(100, $webhookLimit));
    if ($webhookLimit > 0 && Configuration::get("SSBHESABFA_SYNC_ENABLED")) {
        require_once dirname(__FILE__) . "/classes/HesabfaWebhook.php";
        $webhookHandler = new HesabfaWebhook(false);
        $webhook = (new HesabfaWebhookService($webhookHandler))->processPendingOnly($webhookLimit);
    }


    echo json_encode(array(
        'success' => true,
        'processed_jobs' => $jobs,
        'processed_internal_api_requests' => $internal,
        "processed_webhook_changes" => isset($webhook["processed_count"]) ? (int) $webhook["processed_count"] : 0,
        "webhook_pending_count" => isset($webhook["pending_count"]) ? (int) $webhook["pending_count"] : null,
        "webhook_failed_total" => isset($webhook["failed_total"]) ? (int) $webhook["failed_total"] : null,
        "webhook_last_checkpoint" => isset($webhook["last_checkpoint"]) ? (int) $webhook["last_checkpoint"] : null,
        "webhook_last_error" => isset($webhook["last_error"]) ? (string) $webhook["last_error"] : "",

    ));
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(array('success' => false, 'error' => $e->getMessage()));
}
