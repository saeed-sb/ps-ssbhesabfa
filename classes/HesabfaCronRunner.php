<?php
/**
 * Copyright (c) 2026 Saeed Sattar Beglou.
 * Licensed under the Academic Free License (AFL 3.0).
 */
if (!defined('_PS_VERSION_')) { exit; }

/** Shared bounded queue orchestration for the authenticated HTTP and local CLI cron. */
class HesabfaCronRunner
{
    public static function run($module, $limit = 20, $webhookLimit = 80)
    {
        $limit = max(1, min(50, (int) $limit));
        $webhookLimit = max(0, min(100, (int) $webhookLimit));
        $jobs = (int) $module->processPendingHesabfaJobs($limit);
        $internal = 0;
        if (Configuration::get('SSBHESABFA_INTERNAL_API_USE_QUEUE')) {
            $remaining = max(0, $limit - $jobs);
            if ($remaining > 0) {
                $internal = (int) $module->processPendingInternalApiRequests($remaining);
            }
        }

        $webhook = array();
        if ($webhookLimit > 0 && Configuration::get('SSBHESABFA_SYNC_ENABLED')) {
            require_once __DIR__ . '/HesabfaWebhook.php';
            $webhookHandler = new HesabfaWebhook(false);
            $webhook = (new HesabfaWebhookService($webhookHandler))->processPendingOnly($webhookLimit);
        }

        return array(
            'success' => true,
            'processed_jobs' => $jobs,
            'processed_internal_api_requests' => $internal,
            'processed_webhook_changes' => isset($webhook['processed_count']) ? (int) $webhook['processed_count'] : 0,
            'webhook_pending_count' => isset($webhook['pending_count']) ? (int) $webhook['pending_count'] : null,
            'webhook_failed_total' => isset($webhook['failed_total']) ? (int) $webhook['failed_total'] : null,
            'webhook_last_checkpoint' => isset($webhook['last_checkpoint']) ? (int) $webhook['last_checkpoint'] : null,
            'webhook_last_error' => isset($webhook['last_error']) ? (string) $webhook['last_error'] : '',
        );
    }
}
