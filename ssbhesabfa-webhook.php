<?php
/**
 * 2007-2019 PrestaShop
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
 *  @copyright 2007-2019 PrestaShop SA
 *  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 *  International Registered Trademark & Property of PrestaShop SA
 */

include(dirname(__FILE__) . '/../../config/config.inc.php');
include(dirname(__FILE__) . '/../../init.php');


/* Check security token */
if (!Tools::isPHPCLI()) {
    if (Tools::substr(Tools::encrypt('ssbhesabfa/webhook'), 0, 10) != Tools::getValue('token') || !Module::isInstalled('ssbhesabfa')) {
        PrestaShopLogger::addLog('Bad token');
        die('Bad token');
    }
}
$ssbHesabfa = Module::getInstanceByName('ssbhesabfa');

/* Check if the module is enabled */
if ($ssbHesabfa->active) {
    $post = Tools::file_get_contents('php://input');
    $result = json_decode($post);

    if (!isset($result)) {
        PrestaShopLogger::addLog('ssbhesabfa: Invalid Webhook request.');
        die('Invalid request.');
    }

    PrestaShopLogger::addLog($result);



    //file_put_contents('php://stdout', 'Webhook event received: ' . print_r($result, true) . "\r\n");

    //$file = fopen('debug.txt', 'w');
    //fwrite($file, $result);
    //fclose($file);
}
