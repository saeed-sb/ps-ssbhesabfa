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


/* Check security token */
if (!Tools::isPHPCLI()) {
    if (!Module::isInstalled('ssbhesabfa')) {
        die('Module not installed');
    }

    $expectedToken = (string) Configuration::get('SSBHESABFA_WEBHOOK_TOKEN');
    $providedToken = (string) Tools::getValue('token');
    if ($expectedToken === '' || $providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
        if (class_exists('Ssbhesabfa')) { Ssbhesabfa::addLegacyLog('Bad webhook token', 2, null, 'Webhook', null, true); }
        die('Bad token');
    }
}

$ssbHesabfa = Module::getInstanceByName('ssbhesabfa');

/* Check if the module is enabled */
if ($ssbHesabfa->active) {
    $post = Tools::file_get_contents('php://input');
    $result = json_decode($post);

    if (Configuration::get('SSBHESABFA_DEBUG_MODE')) {
        Ssbhesabfa::addLegacyLog('Webhook request received: ' . serialize($result), 1, null, 'Webhook', null, true);
    }

    if (!is_object($result)) {
        Ssbhesabfa::addLegacyLog('Invalid webhook request: missing or invalid token.', 2, null, 'Webhook', null, true);
        die('Invalid request.');
    }

    if ($result->Password != Configuration::get('SSBHESABFA_WEBHOOK_PASSWORD')) {
        Ssbhesabfa::addLegacyLog('Invalid webhook request: password mismatch.', 2, null, 'Webhook', null, true);
        die('Invalid password.');
    }

    Ssbhesabfa::addLegacyLog('Webhook request received from Hesabfa.', 1, null, 'Webhook', null, true);
    include(_PS_MODULE_DIR_ . 'ssbhesabfa/classes/HesabfaWebhook.php');
    new HesabfaWebhook();
}
