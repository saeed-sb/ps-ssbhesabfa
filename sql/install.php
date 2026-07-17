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

$query = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'ssb_hesabfa` (
    `id_ssb_hesabfa` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `obj_type` varchar(32) NOT NULL,
    `id_hesabfa` int(11) UNSIGNED NOT NULL,
    `id_ps` int(11) UNSIGNED NOT NULL,
    `id_ps_attribute` INT(10) NOT NULL DEFAULT 0,
    PRIMARY KEY  (`id_ssb_hesabfa`),
    UNIQUE KEY `uniq_obj_ps_attr` (`obj_type`, `id_ps`, `id_ps_attribute`),
    KEY `idx_obj_hesabfa` (`obj_type`, `id_hesabfa`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';


if (Db::getInstance()->execute($query) == false) {
    return false;
}


$query = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'ssb_hesabfa_log` (
    `id_ssb_hesabfa_log` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `severity` tinyint(1) NOT NULL DEFAULT 1,
    `level` varchar(16) NOT NULL DEFAULT "INFO",
    `area` varchar(64) DEFAULT NULL,
    `error_code` varchar(64) DEFAULT NULL,
    `object_type` varchar(64) DEFAULT NULL,
    `object_id` varchar(64) DEFAULT NULL,
    `prestashop_code` varchar(128) DEFAULT NULL,
    `hesabfa_code` varchar(128) DEFAULT NULL,
    `debug_endpoint` varchar(255) DEFAULT NULL,
    `debug_http_code` int(11) DEFAULT NULL,
    `debug_duration_ms` int(11) DEFAULT NULL,
    `debug_payload` mediumtext DEFAULT NULL,
    `debug_request` mediumtext DEFAULT NULL,
    `debug_response` mediumtext DEFAULT NULL,
    `message` text NOT NULL,
    `date_add` datetime NOT NULL,
    PRIMARY KEY (`id_ssb_hesabfa_log`),
    KEY `severity` (`severity`),
    KEY `object_type` (`object_type`),
    KEY `area` (`area`),
    KEY `prestashop_code` (`prestashop_code`),
    KEY `hesabfa_code` (`hesabfa_code`),
    KEY `date_add` (`date_add`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';

if (Db::getInstance()->execute($query) == false) {
    return false;
}

$query = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'ssb_hesabfa_operation` (
    `id_ssb_hesabfa_operation` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `operation_key` varchar(191) NOT NULL,
    `operation_type` varchar(64) NOT NULL,
    `object_type` varchar(64) DEFAULT NULL,
    `object_id` varchar(64) DEFAULT NULL,
    `status` varchar(32) NOT NULL DEFAULT "pending",
    `attempts` int(10) UNSIGNED NOT NULL DEFAULT 0,
    `external_reference` varchar(128) DEFAULT NULL,
    `message` text DEFAULT NULL,
    `date_add` datetime NOT NULL,
    `date_upd` datetime NOT NULL,
    PRIMARY KEY (`id_ssb_hesabfa_operation`),
    UNIQUE KEY `uniq_operation_key` (`operation_key`),
    KEY `idx_status` (`status`),
    KEY `idx_object` (`object_type`, `object_id`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';

if (Db::getInstance()->execute($query) == false) {
    return false;
}

$query = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'ssb_hesabfa_issue` (
    `id_ssb_hesabfa_issue` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `issue_type` varchar(64) NOT NULL,
    `severity` varchar(16) NOT NULL DEFAULT "ERROR",
    `status` varchar(32) NOT NULL DEFAULT "open",
    `object_type` varchar(64) DEFAULT NULL,
    `object_id` varchar(64) DEFAULT NULL,
    `operation_key` varchar(191) DEFAULT NULL,
    `message` text NOT NULL,
    `date_add` datetime NOT NULL,
    `date_upd` datetime NOT NULL,
    PRIMARY KEY (`id_ssb_hesabfa_issue`),
    KEY `idx_status` (`status`),
    KEY `idx_object` (`object_type`, `object_id`),
    KEY `idx_operation_key` (`operation_key`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';

if (Db::getInstance()->execute($query) == false) {
    return false;
}


$query = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'ssb_hesabfa_job` (
    `id_ssb_hesabfa_job` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `job_type` varchar(64) NOT NULL,
    `status` varchar(32) NOT NULL DEFAULT "pending",
    `payload` mediumtext NOT NULL,
    `request_payload_hash` char(40) DEFAULT NULL,
    `request_unique_ids` mediumtext DEFAULT NULL,
    `request_unique_ids_created_at` datetime DEFAULT NULL,
    `object_type` varchar(64) DEFAULT NULL,
    `object_id` varchar(64) DEFAULT NULL,
    `attempts` int(10) UNSIGNED NOT NULL DEFAULT 0,
    `last_error` text DEFAULT NULL,
    `last_error_code` varchar(64) DEFAULT NULL,
    `last_response` mediumtext DEFAULT NULL,
    `next_run_at` datetime DEFAULT NULL,
    `locked_at` datetime DEFAULT NULL,
    `finished_at` datetime DEFAULT NULL,
    `date_add` datetime NOT NULL,
    `date_upd` datetime NOT NULL,
    PRIMARY KEY (`id_ssb_hesabfa_job`),
    KEY `idx_status` (`status`),
    KEY `idx_type_status` (`job_type`, `status`),
    KEY `idx_status_next_run` (`status`, `next_run_at`),
    KEY `idx_object` (`object_type`, `object_id`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';

if (Db::getInstance()->execute($query) == false) {
    return false;
}


$query = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'ssb_hesabfa_api_request` (
    `id_ssb_hesabfa_api_request` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `requester` varchar(128) NOT NULL DEFAULT "external_module",
    `api_method` varchar(128) NOT NULL,
    `payload` mediumtext NOT NULL,
    `request_payload_hash` char(40) DEFAULT NULL,
    `request_unique_ids` mediumtext DEFAULT NULL,
    `request_unique_ids_created_at` datetime DEFAULT NULL,
    `response` mediumtext DEFAULT NULL,
    `status` varchar(32) NOT NULL DEFAULT "pending",
    `object_type` varchar(64) DEFAULT NULL,
    `object_id` varchar(64) DEFAULT NULL,
    `attempts` int(10) UNSIGNED NOT NULL DEFAULT 0,
    `last_error` text DEFAULT NULL,
    `last_error_code` varchar(64) DEFAULT NULL,
    `last_response` mediumtext DEFAULT NULL,
    `next_run_at` datetime DEFAULT NULL,
    `locked_at` datetime DEFAULT NULL,
    `finished_at` datetime DEFAULT NULL,
    `date_add` datetime NOT NULL,
    `date_upd` datetime NOT NULL,
    PRIMARY KEY (`id_ssb_hesabfa_api_request`),
    KEY `idx_status` (`status`),
    KEY `idx_method_status` (`api_method`, `status`),
    KEY `idx_api_status_next_run` (`status`, `next_run_at`),
    KEY `idx_object` (`object_type`, `object_id`),
    KEY `idx_requester` (`requester`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';

if (Db::getInstance()->execute($query) == false) {
    return false;
}


$query = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'ssb_hesabfa_rate_limit` (
    `window_start` datetime NOT NULL,
    `request_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
    `date_upd` datetime NOT NULL,
    PRIMARY KEY (`window_start`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';
if (!Db::getInstance()->execute($query)) return false;

$query = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'ssb_hesabfa_webhook_change` (
    `id_ssb_hesabfa_webhook_change` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `change_id` int(11) UNSIGNED NOT NULL,
    `object_type` varchar(64) DEFAULT NULL,
    `object_id` varchar(64) DEFAULT NULL,
    `action_code` int(11) DEFAULT NULL,
    `payload` mediumtext NOT NULL,
    `status` varchar(32) NOT NULL DEFAULT "pending",
    `attempts` int(10) UNSIGNED NOT NULL DEFAULT 0,
    `last_error` text DEFAULT NULL,
    `date_add` datetime NOT NULL,
    `date_upd` datetime NOT NULL,
    PRIMARY KEY (`id_ssb_hesabfa_webhook_change`),
    UNIQUE KEY `uniq_change_id` (`change_id`),
    KEY `idx_status_change` (`status`,`change_id`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';
if (!Db::getInstance()->execute($query)) return false;

return true;
