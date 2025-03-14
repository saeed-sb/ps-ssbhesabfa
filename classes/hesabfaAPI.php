<?php
/**
 * 2007-2025 PrestaShop
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
 *  @copyright 2007-2025 PrestaShop SA
 *  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 *  International Registered Trademark & Property of PrestaShop SA
 */

class HesabfaApi
{
    public $apiKey;
    public $userId;
    public $password;
    public $loginToken;

    public function __construct($api = null){
        if (is_null($api)) {
            $this->setApiKey(Configuration::get('SSBHESABFA_ACCOUNT_API'));
            $this->setUserId(Configuration::get('SSBHESABFA_ACCOUNT_USERNAME'));
            $this->setPassword(Configuration::get('SSBHESABFA_ACCOUNT_PASSWORD'));
            $this->setLoginToken(Configuration::get('SSBHESABFA_ACCOUNT_TOKEN'));
        } else {
            $this->setApiKey($api['apiKey']);
            $this->setUserId($api['userId']);
            $this->setPassword($api['password']);
            $this->setLoginToken($api['loginToken']);
        }
    }

    public function setApiKey($apiKey){
        $this->apiKey = $apiKey;
    }

    public function setUserId($userId){
        $this->userId = $userId;
    }

    public function setPassword($password){
        $this->password = $password;
    }

    public function setLoginToken($loginToken){
        $this->loginToken = $loginToken;
    }

    public function apiRequest($method, $data = array())
    {
        if ($method == null) {
            return false;
        }

        if (empty($this->loginToken)) {
            $data = array_merge(array(
                'apiKey' => $this->apiKey,
                'userId' => $this->userId,
                'password' => $this->password,
            ), $data);
        } else {
            $data = array_merge(array(
                'apiKey' => $this->apiKey,
                'loginToken' => $this->loginToken,
            ), $data);
        }

        $data_string = json_encode($data);

        $debug = Configuration::get('SSBHESABFA_DEBUG_MODE');
        if ($debug) {
            PrestaShopLogger::addLog('ssbhesabfa - Method:' . $method . ' - DataString: ' . serialize($data_string), 1, null, null, null, true);
//            var_dump('ssbhesabfa - Method:' . $method . ' - DataString: ' .$data_string);
        }

        $url = 'https://api.hesabfa.com/v1/' . $method;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json'
            ));

        $result = curl_exec($ch);
        
        curl_close($ch);

        if ($debug) {
            PrestaShopLogger::addLog('ssbhesabfa - Result: ' . serialize($result), 1, null, null, null, true);
        }

        //Maximum request per minutes is 60 times,
//        sleep(1);

        if ($result == null) {
            return 'No response from Hesabfa';
        } else {
            $result = json_decode($result);

            if (!isset($result->Success)) {
                switch ($result->ErrorCode) {
                    case '100':
                        return 'InternalServerError';
                    case '101':
                        return 'TooManyRequests';
                    case '103':
                        return 'MissingData';
                    case '104':
                        return 'MissingParameter' . '. ErrorMessage: ' . $result->ErrorMessage;
                    case '105':
                        return 'ApiDisabled';
                    case '106':
                        return 'UserIsNotOwner';
                    case '107':
                        return 'BusinessNotFound';
                    case '108':
                        return 'BusinessExpired';
                    case '110':
                        return 'IdMustBeZero';
                    case '111':
                        return 'IdMustNotBeZero';
                    case '112':
                        return 'ObjectNotFound' . '. ErrorMessage: ' . $result->ErrorMessage;
                    case '113':
                        return 'MissingApiKey';
                    case '114':
                        return 'ParameterIsOutOfRange' . '. ErrorMessage: ' . $result->ErrorMessage;
                    case '190':
                        return 'ApplicationError' . '. ErrorMessage: ' . $result->ErrorMessage;
                }
            } else {
                return $result;
            }
        }
        return false;
    }

    //Contact functions
    public function contactGet($code)
    {
        $method = 'contact/get';
        $data = array(
            'code' => $code,
        );

        return $this->apiRequest($method, $data);
    }

    public function contactGetById($idList)
    {
        $method = 'contact/getById';
        $data = array(
            'idList' => $idList,
        );

        return $this->apiRequest($method, $data);
    }

    public function contactGetContacts($queryInfo)
    {
        $method = 'contact/getcontacts';
        $data = array(
            'queryInfo' => $queryInfo,
        );

        return $this->apiRequest($method, $data);
    }

    public function contactSave($contact)
    {
        $method = 'contact/save';
        $data = array(
            'contact' => $contact,
        );

        return $this->apiRequest($method, $data);
    }

    public function contactBatchSave($contacts)
    {
        $method = 'contact/batchsave';
        $data = array(
            'contacts' => $contacts,
        );

        return $this->apiRequest($method, $data);
    }

    public function contactDelete($code)
    {
        $method = 'contact/delete';
        $data = array(
            'code' => $code,
        );

        return $this->apiRequest($method, $data);
    }

    //Items functions
    public function itemGet($code)
    {
        $method = 'item/get';
        $data = array(
            'code' => $code,
        );

        return $this->apiRequest($method, $data);
    }

    public function itemGetByBarcode($barcode)
    {
        $method = 'item/getByBarcode';
        $data = array(
            'barcode' => $barcode,
        );

        return $this->apiRequest($method, $data);
    }

    public function itemGetById($idList)
    {
        $method = 'item/getById';
        $data = array(
            'idList' => $idList,
        );

        return $this->apiRequest($method, $data);
    }

    public function itemGetItems($queryInfo = null)
    {
        $method = 'item/getitems';
        $data = array(
            'queryInfo' => $queryInfo,
        );

        return $this->apiRequest($method, $data);
    }

    public function itemSave($item)
    {
        $method = 'item/save';
        $data = array(
            'item' => $item,
        );

        return $this->apiRequest($method, $data);
    }

    public function itemBatchSave($items)
    {
        $method = 'item/batchsave';
        $data = array(
            'items' => $items,
        );

        return $this->apiRequest($method, $data);
    }

    public function itemDelete($code)
    {
        $method = 'item/delete';
        $data = array(
            'code' => $code,
        );

        return $this->apiRequest($method, $data);
    }

    public function itemUpdateOpeningQuantity($items)
    {
        $method = 'item/UpdateOpeningQuantity';
        $data = array(
            'items' => $items,
        );

        return $this->apiRequest($method, $data);
    }

    //Invoice functions
    public function invoiceGet($number, $type = 0)
    {
        $method = 'invoice/get';
        $data = array(
            'number' => $number,
            'type' => $type,
        );

        return $this->apiRequest($method, $data);
    }

    public function invoiceGetById($idList)
    {
        $method = 'invoice/getById';
        $data = array(
            'idList' => $idList,
        );

        return $this->apiRequest($method, $data);
    }

    public function invoiceGetInvoices($queryinfo, $type = 0)
    {
        $method = 'invoice/getinvoices';
        $data = array(
            'type' => $type,
            'queryInfo' => $queryinfo,
        );

        return $this->apiRequest($method, $data);
    }

    public function invoiceSave($invoice)
    {
        $method = 'invoice/save';
        $data = array(
            'invoice' => $invoice,
        );

        return $this->apiRequest($method, $data);
    }

    public function invoiceDelete($number, $type = 0)
    {
        $method = 'invoice/delete';
        $data = array(
            'code' => $number,
            'type' => $type,
        );

        return $this->apiRequest($method, $data);
    }

    public function invoiceSavePayment($number, $bankCode, $date, $amount, $transactionNumber = null, $description = null, $transactionFee = 0, $project = null)
    {
        $method = 'invoice/savepayment';
        $data = array(
            'number' => (int)$number,
            'bankCode' => (int)$bankCode,
            'date' => $date,
            'amount' => $amount,
            'transactionNumber' => $transactionNumber,
            'description' => $description,
            'transactionFee' => $transactionFee,
            'project' => $project,
        );

        return $this->apiRequest($method, $data);
    }

    public function invoiceGetOnlineInvoiceURL($number, $type = 0)
    {
        $method = 'invoice/getonlineinvoiceurl';
        $data = array(
            'number' => $number,
            'type' => $type,
        );

        return $this->apiRequest($method, $data);
    }

    //Settings functions
    public function settingSetChangeHook($url, $hookPassword)
    {
        $method = 'setting/SetChangeHook';
        $data = array(
            'url' => $url,
            'hookPassword' => $hookPassword,
        );

        return $this->apiRequest($method, $data);
    }

    public function settingGetChanges($start = 0)
    {
        $method = 'setting/GetChanges';
        $data = array(
            'start' => $start,
        );

        return $this->apiRequest($method, $data);
    }

    public function settingGetBanks()
    {
        $method = 'setting/getBanks';

        return $this->apiRequest($method);
    }

    public function settingGetCurrency()
    {
        $method = 'setting/getCurrency';

        return $this->apiRequest($method);
    }

    public function settingGetFiscalYear()
    {
        $method = 'setting/GetFiscalYear';

        return $this->apiRequest($method);
    }
    
    public function settingGetSalesmen()
    {
        $method = 'setting/GetSalesmen';

        return $this->apiRequest($method);
    }

    public function settingGetProjects()
    {
        $method = 'setting/GetProjects';

        return $this->apiRequest($method);
    }
    
    public function inquiryNationalIdentity()
    {
        $method = 'inquiry/nationalIdentity';

        return $this->apiRequest($method);
    }
    
    public function inquiryCheckMobileAndNationalCode($nationalCode, $mobile)
    {
        $method = 'inquiry/checkMobileAndNationalCode';
        $data = array(
            'nationalCode' => $nationalCode,
            'mobile' => $mobile,
        );

        return $this->apiRequest($method, $data);
    }
    
    public function receiptSave2($type, $items, $transactions, $number = null, $dateTime = null, $description = null, $project = null, $currency = null, $currencyRate = null)
    {
        $method = 'receipt/save2';
        $data = array(
            'type' => $type,
            'items' => $items,
            'transactions' => $transactions,
        );

        if (!is_null($number)) {
            $data['number'] = $number;
        }
        if (!is_null($dateTime)) {
            $data['dateTime'] = $dateTime;
        }
        if (!is_null($description)) {
            $data['description'] = $description;
        }
        if (!is_null($project)) {
            $data['project'] = $project;
        }
        if (!is_null($currency)) {
            $data['currency'] = $currency;
        }
        if (!is_null($currencyRate)) {
            $data['currencyRate'] = $currencyRate;
        }

        return $this->apiRequest($method, $data);
    }
    
    public function documentSave($document)
    {
        $method = 'document/save';
        $data = array(
            'document' => $document,
        );

        return $this->apiRequest($method, $data);
    }
}
