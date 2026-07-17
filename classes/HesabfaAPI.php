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

if (!class_exists('HesabfaRequestUniqueId')) {
    include_once _PS_MODULE_DIR_ . 'ssbhesabfa/classes/HesabfaRequestUniqueId.php';
}

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
        if ($method === null || $method === '') {
            return HesabfaApiResponse::normalize((object) array('Success'=>false,'ErrorCode'=>'INVALID_METHOD','ErrorMessage'=>'Hesabfa API method is empty.'),$method);
        }
        if (Configuration::get('SSBHESABFA_ENABLE_REQUEST_UNIQUE_ID') && HesabfaRequestUniqueId::isWriteMethod($method) && !isset($data['requestUniqueId'])) {
            $data['requestUniqueId']=HesabfaRequestUniqueId::generate($method,$data);
        }
        if (empty($this->loginToken)) {
            $data=array_merge(array('apiKey'=>$this->apiKey,'userId'=>$this->userId,'password'=>$this->password),$data);
        } else {
            $data=array_merge(array('apiKey'=>$this->apiKey,'loginToken'=>$this->loginToken),$data);
        }
        return (new HesabfaHttpClient())->post($method,$data);
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
            'number' => $number,
            'type' => $type,
        );

        return $this->apiRequest($method, $data);
    }

    public function invoiceSavePayment(
        $number,
        $paymentTarget,
        $date,
        $amount,
        $transactionNumber = null,
        $description = null,
        $transactionFee = 0,
        $project = null
    ) {
        $method = 'invoice/savepayment';

        $data = array(
            'number' => (int) $number,
            'date' => $date,
            'amount' => $amount,
            'transactionNumber' => $transactionNumber,
            'description' => $description,
            'transactionFee' => $transactionFee,
            'project' => $project,
        );

        /*
         * $paymentTarget can be:
         *
         * array('bankCode' => 12)
         * array('accountPath' => 'Income: Payment fee income')
         * array('contactCode' => '10001')
         * array('cashCode' => 1)
         * array('pettyCashCode' => 2)
         *
         * For backward compatibility, if a number/string is passed,
         * it will be treated as bankCode.
         */

        if (is_array($paymentTarget)) {
            $allowedTargets = array(
                'bankCode',
                'accountPath',
                'contactCode',
                'cashCode',
                'pettyCashCode',
            );

            $selectedTargetKey = null;
            $selectedTargetValue = null;

            foreach ($allowedTargets as $targetKey) {
                if (
                    isset($paymentTarget[$targetKey])
                    && $paymentTarget[$targetKey] !== ''
                    && $paymentTarget[$targetKey] !== null
                    && $paymentTarget[$targetKey] !== false
                ) {
                    if ($selectedTargetKey !== null) {
                        return (object) array(
                            'Success' => false,
                            'ErrorCode' => 'INVALID_PAYMENT_TARGET',
                            'ErrorMessage' => 'Only one of bankCode, accountPath, contactCode, cashCode, pettyCashCode can be set.',
                        );
                    }

                    $selectedTargetKey = $targetKey;
                    $selectedTargetValue = $paymentTarget[$targetKey];
                }
            }

            if ($selectedTargetKey !== null) {
                if (in_array($selectedTargetKey, array('bankCode', 'cashCode', 'pettyCashCode'))) {
                    $data[$selectedTargetKey] = (int) $selectedTargetValue;
                } else {
                    $data[$selectedTargetKey] = (string) $selectedTargetValue;
                }
            }
        } else {
            /*
             * Backward compatibility:
             * old usage: invoiceSavePayment($number, $bankCode, ...)
             */
            if (
                $paymentTarget !== null
                && $paymentTarget !== false
                && $paymentTarget !== ''
            ) {
                $data['bankCode'] = (int) $paymentTarget;
            }
        }

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

    public function settingGetBusinessInfo()
    {
        $method = 'setting/GetBusinessInfo';

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
    
    public function inquiryNationalIdentity($nationalCode = null, $birthDate = null)
    {
        $method = 'inquiry/nationalIdentity';
        $data = $this->filterNullValues(array(
            'nationalCode' => $nationalCode,
            'birthDate' => $birthDate,
        ));

        return $this->apiRequest($method, $data);
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
    // Additional Hesabfa API wrappers
    private function requestWithData($method, array $data = array())
    {
        return $this->apiRequest($method, $data);
    }

    private function filterNullValues(array $data)
    {
        foreach ($data as $key => $value) {
            if ($value === null) {
                unset($data[$key]);
            }
        }

        return $data;
    }

    public function contactFindByPhoneOrEmail($mobile = null, $phone = null, $email = null)
    {
        return $this->requestWithData('contact/findByPhoneOrEmail', $this->filterNullValues(array(
            'mobile' => $mobile,
            'phone' => $phone,
            'email' => $email,
        )));
    }

    public function contactGetContactLink($code, $showAllAccounts = true, $days = 3)
    {
        return $this->requestWithData('contact/getContactLink', array(
            'code' => $code,
            'showAllAccounts' => (bool) $showAllAccounts,
            'days' => (int) $days,
        ));
    }

    public function itemGetQuantity($codes, $warehouseCode = null)
    {
        return $this->requestWithData('item/GetQuantity', $this->filterNullValues(array(
            'codes' => $codes,
            'warehouseCode' => $warehouseCode,
        )));
    }

    public function itemGetQuantity2($codes)
    {
        return $this->requestWithData('item/GetQuantity2', array(
            'codes' => $codes,
        ));
    }

    public function invoiceChangePaidStatus($number, $paid, $type = 0)
    {
        return $this->requestWithData('invoice/changePaidStatus', array(
            'number' => $number,
            'type' => $type,
            'paid' => (bool) $paid,
        ));
    }

    public function invoiceChangeSentStatus($number, $sent, $type = 0)
    {
        return $this->requestWithData('invoice/changeSentStatus', array(
            'number' => $number,
            'type' => $type,
            'sent' => (bool) $sent,
        ));
    }

    public function invoiceSaveWarehouseReceipt($receipt, $deleteOldReceipts = true)
    {
        return $this->requestWithData('invoice/SaveWarehouseReceipt', array(
            'deleteOldReceipts' => (bool) $deleteOldReceipts,
            'receipt' => $receipt,
        ));
    }

    public function receiptGet($type, $number)
    {
        return $this->requestWithData('receipt/get', array(
            'type' => $type,
            'number' => $number,
        ));
    }

    public function receiptGetById($type, $id = null, $idList = null)
    {
        return $this->requestWithData('receipt/GetById', $this->filterNullValues(array(
            'type' => $type,
            'id' => $id,
            'idList' => $idList,
        )));
    }

    public function receiptGetReceipts($type, $queryInfo = null)
    {
        return $this->requestWithData('receipt/getReceipts', $this->filterNullValues(array(
            'type' => $type,
            'queryInfo' => $queryInfo,
        )));
    }

    public function receiptSave($type, $description, $amount, $contactCode = null, $bankCode = null, $cashCode = null, $pettyCashCode = null, $currency = null, $currencyRate = null)
    {
        return $this->requestWithData('receipt/save', $this->filterNullValues(array(
            'type' => $type,
            'description' => $description,
            'amount' => $amount,
            'contactCode' => $contactCode,
            'bankCode' => $bankCode,
            'cashCode' => $cashCode,
            'pettyCashCode' => $pettyCashCode,
            'currency' => $currency,
            'currencyRate' => $currencyRate,
        )));
    }

    public function receiptDelete($type, $number)
    {
        return $this->requestWithData('receipt/delete', array(
            'type' => $type,
            'number' => $number,
        ));
    }

    public function documentGet($number)
    {
        return $this->requestWithData('document/get', array(
            'number' => $number,
        ));
    }

    public function documentGetDocuments($queryInfo = null)
    {
        return $this->requestWithData('document/getdocuments', $this->filterNullValues(array(
            'queryInfo' => $queryInfo,
        )));
    }

    public function documentDelete($number)
    {
        return $this->requestWithData('document/delete', array(
            'number' => $number,
        ));
    }

    public function settingGetFiscalYears()
    {
        return $this->apiRequest('setting/GetFiscalYears');
    }

    public function settingGetCashes()
    {
        return $this->apiRequest('setting/GetCashes');
    }

    public function settingGetPettyCashes()
    {
        return $this->apiRequest('setting/GetPettyCashes');
    }

    public function settingGetWarehouses()
    {
        return $this->apiRequest('setting/getWarehouses');
    }

    public function settingGetProductCategories()
    {
        return $this->apiRequest('setting/getProductCategories');
    }

    public function settingGetServiceCategories()
    {
        return $this->apiRequest('setting/getServiceCategories');
    }

    public function settingGetContactCategories()
    {
        return $this->apiRequest('setting/getContactCategories');
    }

    public function settingGetCurrencyTable()
    {
        return $this->apiRequest('setting/GetCurrencyTable');
    }

    public function settingSetCurrencyTable($table)
    {
        return $this->requestWithData('setting/SetCurrencyTable', array(
            'Table' => $table,
        ));
    }

    public function settingGetAccounts()
    {
        return $this->apiRequest('setting/GetAccounts');
    }

    public function settingGetDefaultPriceList()
    {
        return $this->apiRequest('setting/getDefaultPriceList');
    }

    public function settingGetChangeHook()
    {
        return $this->apiRequest('setting/GetChangeHook');
    }

    public function reportBalanceSheet($startDate, $endDate, $project = null)
    {
        return $this->requestWithData('report/balancesheet', $this->filterNullValues(array(
            'startDate' => $startDate,
            'endDate' => $endDate,
            'project' => $project,
        )));
    }

    public function reportDebtorsCreditors($startDate, $endDate, $project = null)
    {
        return $this->requestWithData('report/debtorscreditors', $this->filterNullValues(array(
            'startDate' => $startDate,
            'endDate' => $endDate,
            'project' => $project,
        )));
    }

    public function reportInventory($startDate, $endDate, $project = null)
    {
        return $this->requestWithData('report/inventory', $this->filterNullValues(array(
            'startDate' => $startDate,
            'endDate' => $endDate,
            'project' => $project,
        )));
    }

    public function reportBank($startDate, $endDate, $code, $project = null)
    {
        return $this->requestWithData('report/bank', $this->filterNullValues(array(
            'startDate' => $startDate,
            'endDate' => $endDate,
            'project' => $project,
            'code' => $code,
        )));
    }

    public function reportCash($startDate, $endDate, $code, $project = null)
    {
        return $this->requestWithData('report/cash', $this->filterNullValues(array(
            'startDate' => $startDate,
            'endDate' => $endDate,
            'project' => $project,
            'code' => $code,
        )));
    }

    public function reportPettyCash($startDate, $endDate, $code, $project = null)
    {
        return $this->requestWithData('report/pettyCash', $this->filterNullValues(array(
            'startDate' => $startDate,
            'endDate' => $endDate,
            'project' => $project,
            'code' => $code,
        )));
    }

    public function reportJournal($startDate, $endDate, $project = null, $take = null, $skip = null)
    {
        return $this->requestWithData('report/journal', $this->filterNullValues(array(
            'startDate' => $startDate,
            'endDate' => $endDate,
            'project' => $project,
            'take' => $take,
            'skip' => $skip,
        )));
    }

    public function reportProfitAndLossStatement($startDate, $endDate, $project = null)
    {
        return $this->requestWithData('report/profitandlossstatement', $this->filterNullValues(array(
            'startDate' => $startDate,
            'endDate' => $endDate,
            'project' => $project,
        )));
    }

    public function reportTrialBalance($startDate, $endDate, $project = null)
    {
        return $this->requestWithData('report/trialbalance', $this->filterNullValues(array(
            'startDate' => $startDate,
            'endDate' => $endDate,
            'project' => $project,
        )));
    }

    public function reportTrialBalanceItems($startDate, $endDate, $project = null, $accountPath = null)
    {
        return $this->requestWithData('report/trialbalanceitems', $this->filterNullValues(array(
            'startDate' => $startDate,
            'endDate' => $endDate,
            'project' => $project,
            'accountPath' => $accountPath,
        )));
    }

    public function warehouseGet($number)
    {
        return $this->requestWithData('warehouse/get', array(
            'number' => $number,
        ));
    }

    public function warehouseGetById($id = null, $idList = null)
    {
        return $this->requestWithData('warehouse/GetById', $this->filterNullValues(array(
            'id' => $id,
            'idList' => $idList,
        )));
    }

    public function warehouseGetReceipts($type, $queryInfo = null)
    {
        return $this->requestWithData('warehouse/GetReceipts', $this->filterNullValues(array(
            'type' => $type,
            'queryInfo' => $queryInfo,
        )));
    }

    public function warehouseSave($receipt, $deleteOldReceipts = true)
    {
        return $this->requestWithData('warehouse/save', array(
            'deleteOldReceipts' => (bool) $deleteOldReceipts,
            'receipt' => $receipt,
        ));
    }

    public function warehouseDelete($number, $type = null)
    {
        return $this->requestWithData('warehouse/delete', $this->filterNullValues(array(
            'number' => $number,
            'type' => $type,
        )));
    }

    public function discountItemGet($contactCode = null, $productCode = null, $contactPath = null, $productPath = null, $tag = null)
    {
        return $this->requestWithData('disCountItem/get', $this->filterNullValues(array(
            'contactCode' => $contactCode,
            'productCode' => $productCode,
            'contactPath' => $contactPath,
            'productPath' => $productPath,
            'tag' => $tag,
        )));
    }

    public function discountItemGetById($idList)
    {
        return $this->requestWithData('disCountItem/getById', array(
            'idList' => $idList,
        ));
    }

    public function discountItemGetItems($queryInfo = null)
    {
        return $this->requestWithData('disCountItem/getItems', $this->filterNullValues(array(
            'queryInfo' => $queryInfo,
        )));
    }

    public function discountItemSave($item)
    {
        return $this->requestWithData('disCountItem/save', array(
            'item' => $item,
        ));
    }

    public function discountItemBatchSave($items)
    {
        return $this->requestWithData('disCountItem/batchSave', array(
            'items' => $items,
        ));
    }

    public function discountItemDelete($idList)
    {
        return $this->requestWithData('disCountItem/delete', array(
            'idList' => $idList,
        ));
    }

    public function bankTransferGet($number)
    {
        return $this->requestWithData('banktransfer/get', array(
            'number' => $number,
        ));
    }

    public function bankTransferGetTransfers($queryInfo = null)
    {
        return $this->requestWithData('banktransfer/getTransfers', $this->filterNullValues(array(
            'queryInfo' => $queryInfo,
        )));
    }

    public function bankTransferSave($transfer)
    {
        return $this->requestWithData('banktransfer/save', array(
            'transfer' => $transfer,
        ));
    }

    public function bankTransferDelete($number)
    {
        return $this->requestWithData('banktransfer/delete', array(
            'number' => $number,
        ));
    }

    public function inquiryCredit()
    {
        return $this->apiRequest('inquiry/credit');
    }

    public function inquiryCheckCardAndNationalCode($nationalCode, $cardNumber, $birthDate)
    {
        return $this->requestWithData('inquiry/checkCardAndNationalCode', array(
            'nationalCode' => $nationalCode,
            'cardNumber' => $cardNumber,
            'birthDate' => $birthDate,
        ));
    }

    public function inquiryCheckIbanAndNationalCode($nationalCode, $iban, $birthDate = null)
    {
        return $this->requestWithData('inquiry/checkIbanAndNationalCode', $this->filterNullValues(array(
            'nationalCode' => $nationalCode,
            'iban' => $iban,
            'birthDate' => $birthDate,
        )));
    }

    public function inquiryIban($iban)
    {
        return $this->requestWithData('inquiry/iban', array(
            'iban' => $iban,
        ));
    }

    public function inquiryCard($cardNumber)
    {
        return $this->requestWithData('inquiry/card', array(
            'cardNumber' => $cardNumber,
        ));
    }

    public function inquiryCardToIban($cardNumber)
    {
        return $this->requestWithData('inquiry/cardToIban', array(
            'cardNumber' => $cardNumber,
        ));
    }

    public function inquiryAccountToIban($account, $bank)
    {
        return $this->requestWithData('inquiry/accountToIBAN', array(
            'account' => $account,
            'bank' => $bank,
        ));
    }

    public function inquiryPostalCode($postalCode)
    {
        return $this->requestWithData('inquiry/postalCode', array(
            'postalCode' => $postalCode,
        ));
    }

}
