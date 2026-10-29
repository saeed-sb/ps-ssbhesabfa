<?php
/**
 * Copyright (c) 2026 Saeed Sattar Beglou.
 * Licensed under the Academic Free License (AFL 3.0).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$shopId = 1;
$limit = 20;
$webhookLimit = 80;
$dryRun = false;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--dry-run') {
        $dryRun = true;
    } elseif ($argument === '--help') {
        fwrite(STDOUT, "Usage: php bin/cron.php [--shop=1] [--limit=20] [--webhook-limit=80] [--dry-run]\n");
        exit(0);
    } elseif (preg_match('/^--(shop|limit|webhook-limit)=([0-9]+)$/D', $argument, $match)
        && (string) (int) $match[2] === $match[2]) {
        $value = (int) $match[2];
        if ($match[1] === 'shop' && $value > 0) {
            $shopId = $value;
        } elseif ($match[1] === 'limit' && $value >= 1 && $value <= 50) {
            $limit = $value;
        } elseif ($match[1] === 'webhook-limit' && $value <= 100) {
            $webhookLimit = $value;
        } else {
            fwrite(STDERR, "Invalid shop or queue limit.\n");
            exit(2);
        }
    } else {
        fwrite(STDERR, "Invalid argument. Use --help for supported options.\n");
        exit(2);
    }
}

umask(0077);
$shopRoot = dirname(__DIR__, 3);
$runtimeDirectory = dirname($shopRoot) . '/ops/runtime/hesabfa-cron';
$lock = false;
$exitCode = 0;
try {
    if (!is_dir($runtimeDirectory) && !@mkdir($runtimeDirectory, 0700, true) && !is_dir($runtimeDirectory)) {
        throw new RuntimeException('Cron runtime directory is unavailable.');
    }
    // Share the existing scheduler's lock during migration and on subsequent runs.
    $lock = fopen($runtimeDirectory . '/cron.lock', 'c');
    if (!$lock) {
        throw new RuntimeException('Cron lock is unavailable.');
    }
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        fwrite(STDOUT, json_encode(array('success' => true, 'skipped' => 'ALREADY_RUNNING')) . "\n");
        exit(0);
    }

    require $shopRoot . '/config/config.inc.php';
    $shop = new Shop($shopId);
    if (!Validate::isLoadedObject($shop) || !$shop->active) {
        throw new RuntimeException('Selected shop is unavailable.');
    }
    Shop::setContext(Shop::CONTEXT_SHOP, $shopId);
    $context = Context::getContext();
    $context->shop = $shop;
    $context->language = new Language((int) Configuration::get('PS_LANG_DEFAULT', null, null, $shopId));
    $context->currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT', null, null, $shopId));
    $context->country = new Country((int) Configuration::get('PS_COUNTRY_DEFAULT', null, null, $shopId));
    $context->customer = new Customer();
    $context->cart = new Cart();
    $context->cart->id_shop = $shopId;
    $context->cart->id_lang = (int) $context->language->id;
    $context->cart->id_currency = (int) $context->currency->id;
    $timezone = (string) Configuration::get('PS_TIMEZONE', null, null, $shopId);
    if ($timezone !== '') {
        date_default_timezone_set($timezone);
    }

    require_once dirname(__DIR__) . '/classes/HesabfaSecurity.php';
    require_once dirname(__DIR__) . '/classes/HesabfaCronRunner.php';
    if (!HesabfaSecurity::isOperational()) {
        throw new RuntimeException('Module is disabled or the single-shop boundary is unsupported.');
    }
    $module = Module::getInstanceByName('ssbhesabfa');
    if (!$module || !Validate::isLoadedObject($module) || !$module->active) {
        throw new RuntimeException('Module is unavailable.');
    }

    // All API credentials and queue/synchronization switches remain module-owned.
    $result = $dryRun
        ? array('success' => true, 'dry_run' => true, 'runtime_ready' => true, 'limit' => $limit, 'webhook_limit' => $webhookLimit)
        : HesabfaCronRunner::run($module, $limit, $webhookLimit);
    $report = array('at' => date('c'), 'shop_id' => $shopId, 'result' => $result);
    $encoded = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($encoded === false || file_put_contents(
        $runtimeDirectory . ($dryRun ? '/last-dry-run-shop-' : '/last-result-shop-') . $shopId . '.json',
        $encoded . "\n",
        LOCK_EX
    ) === false) {
        throw new RuntimeException('Cannot write cron result.');
    }
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE) . "\n");
} catch (Throwable $error) {
    if (is_dir($runtimeDirectory)) {
        file_put_contents($runtimeDirectory . '/last-error-shop-' . $shopId . '.json', json_encode(array(
            'at' => date('c'), 'shop_id' => $shopId, 'error' => 'SSBHESABFA_CRON_FAILED', 'type' => get_class($error),
        )) . "\n", LOCK_EX);
    }
    fwrite(STDERR, 'SSBHESABFA_CRON_FAILED (' . get_class($error) . ")\n");
    $exitCode = 1;
} finally {
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
exit($exitCode);
