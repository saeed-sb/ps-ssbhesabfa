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

class hesabfaAPI
{
    public function api_request($data = array(), $method)
    {
        if (!isset($method))
            return false;

        $data = array_merge(array(
            'apiKey' => Configuration::get('SSBHESABFA_ACCOUNT_API'),
            'userId' => Configuration::get('SSBHESABFA_ACCOUNT_USERNAME'),
            'password' => Configuration::get('SSBHESABFA_ACCOUNT_PASSWORD')
        ), $data);

        $data_string = json_encode($data);

        $url = 'https://api.hesabfa.com/v1/' . $method;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Accept: application/json'
        ));

        $result = curl_exec($ch);
        curl_close($ch);

        if ($result == null) {
            return $this->l('No response from Hesabfa');
        } else {
            $result = json_decode($result);

            if ($result->Success == false) {
                switch ($result->ErrorCode) {
                    case '100':
                        return $this->l('InternalServerError');
                        break;
                    case '101':
                        return $this->l('TooManyRequests');
                        break;
                    case '103':
                        return $this->l('MissingData');
                        break;
                    case '104':
                        return $this->l('MissingParameter') . '. ErrorMessage: ' . $result->ErrorMessage;
                        break;
                    case '105':
                        return $this->l('ApiDisabled');
                        break;
                    case '106':
                        return $this->l('UserIsNotOwner');
                        break;
                    case '107':
                        return $this->l('BusinessNotFound');
                        break;
                    case '108':
                        return $this->l('BusinessExpired');
                        break;
                    case '110':
                        return $this->l('IdMustBeZero');
                        break;
                    case '111':
                        return $this->l('IdMustNotBeZero');
                        break;
                    case '112':
                        return $this->l('ObjectNotFound') . '. ErrorMessage: ' . $result->ErrorMessage;
                        break;
                    case '113':
                        return $this->l('MissingApiKey');
                        break;
                    case '114':
                        return $this->l('ParameterIsOutOfRange') . '. ErrorMessage: ' . $result->ErrorMessage;
                        break;
                    case '190':
                        return $this->l('ApplicationError') . '. ErrorMessage: ' . $result->ErrorMessage;
                        break;
                }
            } else {
                return $result;
            }
        }
        return false;
    }
}