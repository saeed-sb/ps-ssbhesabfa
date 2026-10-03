<?php
/**
 * 2007-2020 PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 *
 *  @author    PrestaShop SA <contact@prestashop.com>
 *  @copyright 2007-2020 PrestaShop SA
 *  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 *  International Registered Trademark & Property of PrestaShop SA
 */

include(dirname(__FILE__) . '/../../config/config.inc.php');
include(dirname(__FILE__) . '/../../init.php');


require_once __DIR__ . '/classes/HesabfaSecurity.php';
// Never persist unauthenticated traffic, including debug bodies, to module logs.
if (!Module::isInstalled('ssbhesabfa') || !HesabfaSecurity::isOperational()) {
    http_response_code(503); exit('Module unavailable.');
}
if (!HesabfaSecurity::matchesSecret(Configuration::get('SSBHESABFA_WEBHOOK_TOKEN'), Tools::getValue('token'))) {
    http_response_code(403); exit('Invalid token.');
}
if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); header('Allow: POST'); exit('POST required.');
}
$stream = fopen('php://input', 'rb');
$body = HesabfaSecurity::readWebhookBody($stream, isset($_SERVER['CONTENT_LENGTH']) ? $_SERVER['CONTENT_LENGTH'] : null);
if (is_resource($stream)) { fclose($stream); }
if ($body === false) { http_response_code(413); exit('Request too large.'); }
if (!HesabfaSecurity::validWebhookBody($body, Configuration::get('SSBHESABFA_WEBHOOK_PASSWORD'))) {
    http_response_code(403); exit('Invalid request.');
}
$ssbHesabfa = Module::getInstanceByName('ssbhesabfa');
if (!$ssbHesabfa || !$ssbHesabfa->active) { http_response_code(503); exit('Module unavailable.'); }
Ssbhesabfa::addLegacyLog('Authenticated webhook notification received.', 1, null, 'Webhook', null, true);
require_once __DIR__ . '/classes/HesabfaWebhook.php';
new HesabfaWebhook();
