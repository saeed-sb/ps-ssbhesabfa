<?php
require_once dirname(__FILE__) . '/../../config/config.inc.php';
require_once dirname(__FILE__) . '/../../init.php';

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/classes/HesabfaSecurity.php';
$token = HesabfaSecurity::cronToken($_SERVER);
$expected = (string) Configuration::get('SSBHESABFA_QUEUE_CRON_TOKEN');
if (!HesabfaSecurity::matchesSecret($expected, $token)) {
    http_response_code(403);
    echo json_encode(array('success' => false, 'error' => 'Invalid token.'));
    exit;
}

if (!HesabfaSecurity::isOperational()) {
    http_response_code(503);
    echo json_encode(array('success' => false, 'error' => 'Module is disabled or the shop configuration is unsupported.'));
    exit;
}
$module = Module::getInstanceByName('ssbhesabfa');
if (!$module || !Validate::isLoadedObject($module) || !$module->active) {
    http_response_code(500);
    echo json_encode(array('success' => false, 'error' => 'Module is not available.'));
    exit;
}

$limit = (int) Tools::getValue('limit', 20);
$limit = max(1, min(50, $limit));

require_once __DIR__ . '/classes/HesabfaCronRunner.php';
try {
    $webhookLimit = (int) Tools::getValue('webhook_limit', 80);
    echo json_encode(HesabfaCronRunner::run($module, $limit, $webhookLimit));
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(array('success' => false, 'error' => $e->getMessage()));
}
