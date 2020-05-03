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

class HesabfaUpdate
{
    private static $instance;
    private $data_url = 'https://hesabfa.com/file/prestashop_module_info.json';
    public $ssbhesabfa;
    public $token;

    public function __construct($ssbhesabfa)
    {
        $this->ssbhesabfa = $ssbhesabfa;
    }

    public static function getInstance($ssbhesabfa)
    {
        if (!self::$instance) {
            self::$instance = new HesabfaUpdate($ssbhesabfa);
        }
        return self::$instance;
    }

    public function makeCall()
    {
        try {
            $curl_connection = curl_init($this->data_url);
            curl_setopt($curl_connection, CURLOPT_CONNECTTIMEOUT, 60);
            curl_setopt($curl_connection, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl_connection, CURLOPT_SSL_VERIFYPEER, false);

            $data = json_decode(curl_exec($curl_connection), true);
            curl_close($curl_connection);
            if ($data) {
                return $data;
            }
            //ToDo:write log
            return false;
        } catch (Exception $e) {
            //ToDo:write log
            return false;
        }
    }

    public function getInfoByKey($key)
    {
        $data = $this->makeCall();
        if(!$data || !is_array($data))
            return false;
        return key_exists($key, $data) ? $data[$key] : false;
    }

    public function checkUpdate()
    {
        if (Configuration::get('SSBHESABFA_LAST_CHECK_UPDATE') === false || (time() - Configuration::get('SSBHESABFA_LAST_CHECK_UPDATE')) > 86400){
            Configuration::updateValue('SSBHESABFA_LAST_CHECK_UPDATE', time());
        }

        $remote_version = $this->getInfoByKey('latest_version');
        if (!$remote_version || strpos($remote_version, '.') === false) {
            return;
        }
        $arr = explode('.', $remote_version);
        $arr2 = explode('.', $this->ssbhesabfa->version);
        $primary = array_shift($arr2);
        // Must ensure the primary version is same.
        if ($arr[0] == $primary) {
            if (Tools::version_compare($this->ssbhesabfa->version, $remote_version)) {
                // If current version is lower than remote version, need update.
                return $remote_version;
            }
        }
        return false;
    }

    public function getNotice()
    {
        $html = '';
        $remote_version = $this->checkUpdate();
        if ($remote_version === null) {
            $html .= $this->ssbhesabfa->displayError(
                $this->ssbhesabfa->l('Unable to get information from Hesabfa.')
            );
        }
        if ($remote_version) {
            $html .= $this->ssbhesabfa->displayConfirmation(
                sprintf($this->ssbhesabfa->l('A new version %s is available.'), $remote_version)
            );
        }
        if ($remote_version === false) {
            $html .= $this->ssbhesabfa->displayConfirmation(
                sprintf($this->ssbhesabfa->l('Your module is already the latest version.'), $remote_version)
            );
        }
        $notices = $this->getInfoByKey('notice');
        if ($notices) {
            foreach($notices AS $val) {
                if (!isset($val['text']) || !$val['text']) {
                    continue;
                }
                if ($val['type'] == 'error') {
                    $html .= $this->ssbhesabfa->displayError($val['text']);
                } elseif ($val['type'] == 'info') {
                    $html .= $this->ssbhesabfa->displayConfirmation($val['text']);
                } else{
                    $html .= $val['text'];
                }
            }
        }
        return $html;
    }

    public function getAd()
    {
        $html = '';
        $ads = $this->getInfoByKey('ad');
        if($ads){
            foreach($ads AS $val) {
                if (isset($val['html']) && $val['html']) {
                    $html .= $val['html'];
                }
            }
        }
        return $html;
    }

    /**
     * Update the module from server.
     */
    public function upgrade()
    {
        // Need update ?
        $remote_version = $this->checkUpdate();
        if ($remote_version === null) {
            return $this->ssbhesabfa->displayError($this->ssbhesabfa->l('Unable to check update.'));
        }
        if ($remote_version === false) {
            return $this->ssbhesabfa->displayConfirmation($this->ssbhesabfa->l('Your module is already the latest version.'));
        }
        $sandbox = _PS_CACHE_DIR_.'sandbox/';
        // Test sandbox is writeable ?
        if (!$tmpfile = tempnam($sandbox, 'TMP0')) {
            return sprintf(
                $this->ssbhesabfa->displayError($this->ssbhesabfa->l('Please ensure the %s folder is writable.')), $sandbox
            );
        }
        @unlink($tmpfile);

        // Get access
        if ($data = $this->makeCall()) {
            $md5 = $data['md5'];
            // Download .zip file.
            $fp = fopen($tmpfile, 'w');
            $ch = curl_init($data['latest_file_url']);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 360);
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_exec($ch);
            curl_close($ch);
            fclose($fp);
            // test file & check md5
            if (!Tools::ZipTest($tmpfile) || $md5 != md5_file($tmpfile)) {
                @unlink($tmpfile);
                return $this->ssbhesabfa->displayError($this->ssbhesabfa->l('Package is broken.'));
            } elseif (!Tools::ZipExtract($tmpfile, _PS_MODULE_DIR_)) {
                @unlink($tmpfile);
                return $this->ssbhesabfa->displayError($this->ssbhesabfa->l('Unable to unzip package.'));
            } else {
                // Delete temp file.
                @unlink($tmpfile);
                return $this->ssbhesabfa->displayConfirmation($this->ssbhesabfa->l('Hesabfa Module upgraded successfully, Please go to Module manager and click on Upgrade button'));
            }
        } else {
            return $this->ssbhesabfa->displayError($this->ssbhesabfa->l('Unable to download.'));
        }
    }
}