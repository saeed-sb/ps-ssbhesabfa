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
 *  @copyright 2007-2019 PrestaShop SA
 *  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 *  International Registered Trademark & Property of PrestaShop SA
 */

//include(dirname(__FILE__) . '/../../../config/config.inc.php');
//include(dirname(__FILE__) . '/../../../init.php');
//include(dirname(__FILE__) . '/hesabfaApi.php');

class HesabfaWebhook
{
    public function __construct()
    {
        $hesabfaApi = new HesabfaApi();
        $lastChange = Configuration::get('SSBHESABFA_LAST_LOG_CHECK_ID');
        $changes = $hesabfaApi->settingGetChanges($lastChange + 1);
        if ($changes->Success) {
            foreach ($changes->Result as $item) {
                switch ($item->ObjectType) {
                    case 'Invoice':
                        $this->setInvoiceChangesById($item->ObjectId);
                        break;
                    case 'Product':
                        //if Action was deleted
                        if ($item->Action == 53) {
                            $id_obj = Ssbhesabfa::getObjectIdByCode('product', $item->Extra);
                            $hesabfa = new HesabfaModel($id_obj);
                            $hesabfa->delete();
                        }
                        $this->setItemChangesById($item->ObjectId);
                        break;
                    case 'Contact':
                        //if Action was deleted
                        if ($item->Action == 33) {
                            $id_obj = Ssbhesabfa::getObjectIdByCode('customer', $item->Extra);
                            $hesabfa = new HesabfaModel($id_obj);
                            $hesabfa->delete();
                        }
                        $this->setContactChangesById($item->ObjectId);
                        break;
                }

                $lastChange = $item->Id;
            }

            //set LastChange ID
            Configuration::updateValue('SSBHESABFA_LAST_LOG_CHECK_ID', $lastChange);
        } else {
            PrestaShopLogger::addLog('ssbhesabfa - Cannot check last changes. Error Message: ' . $changes->ErrorMessage, 2, $changes->ErrorCode, null, null, true);
        }
    }

    // use in webhook call when invoice change
    public function setInvoiceChangesById($id)
    {
        $hesabfaApi = new HesabfaApi();
        $invoice = $hesabfaApi->invoiceGetById($id);
        if ($invoice->Success && !empty($invoice->Result)) {
            //1.set new Hesabfa Invoice Code if changes
            $number = $invoice->Result->Number;
            $json = json_decode($invoice->Result->Tag);
            if (is_object($json)) {
                $id_order = $json->id_order;
            } else {
                $id_order = 0;
            }

            if ($invoice->Result->InvoiceType == 0) {
                //check if Tag not set in hesabfa
                if ($id_order == 0) {
                    $msg = 'This invoice is not define in OnlineStore';
                    PrestaShopLogger::addLog('ssbhesabfa - ' . $msg, 2, null, 'Order', $number, true);
                } else {
                    //check if order exist in prestashop
                    $id_obj = Ssbhesabfa::getObjectId('order', $id_order);
                    if ($id_obj > 0) {
                        $hesabfa = new HesabfaModel($id_obj);
                        if ($hesabfa->id_hesabfa != $number) {
                            $id_hesabfa_old = $hesabfa->id_hesabfa;
                            //ToDo: number must int, what can i do
                            $hesabfa->id_hesabfa = $number;
                            $hesabfa->update();

                            $msg = 'Invoice Number changed. Old Number: ' . $id_hesabfa_old . '. New ID: ' . $number;
                            PrestaShopLogger::addLog('ssbhesabfa - ' . $msg, 1, null, 'order', $id_order, true);
                        }
                    }
                }
            }

            //2&3.check the change quantity and Price of Invoice items
            foreach ($invoice->Result->InvoiceItems as $invoiceItem) {
                $this->setItemChangesByCode($invoiceItem->Item->Code);
            }
        }
    }

    // use in webhook call when contact change
    public function setContactChangesById($id)
    {
        $hesabfaApi = new HesabfaApi();
        $contact = $hesabfaApi->contactGetById(array($id));

        if ($contact->Success && !empty($contact->Result)) {
            //1.set new Hesabfa Contact Code if changes
            $code = $contact->Result[0]->Code;

            $json = json_decode($contact->Result[0]->Tag);
            if (is_object($json)) {
                $id_customer = $json->id_customer;
            } else {
                $id_customer = 0;
            }

            //check if Tag not set in hesabfa
            if ($id_customer == 0) {
                $msg = 'This Customer is not define in OnlineStore';
                PrestaShopLogger::addLog('ssbhesabfa - ' . $msg, 2, null, 'customer', $code, true);

                return false;
            }

            //check if customer exist in prestashop
            $id_obj = Ssbhesabfa::getObjectId('customer', $id_customer);
            if ($id_obj > 0) {
                $hesabfa = new HesabfaModel($id_obj);
                if ($hesabfa->id_hesabfa != $code) {
                    $id_hesabfa_old = $hesabfa->id_hesabfa;

                    $hesabfa->id_hesabfa = (int)$code;
                    $hesabfa->update();

                    $msg = 'Contact Code changed. Old ID: ' . $id_hesabfa_old . '. New ID: ' . $code;
                    PrestaShopLogger::addLog('ssbhesabfa - ' . $msg, 1, null, 'customer', $id_customer, true);
                }
            }
        }
    }

    // use in webhook call when product change
    public function setItemChangesById($id)
    {
        $hesabfaApi = new HesabfaApi();
        $item = $hesabfaApi->itemGetById(array($id));
        if ($item->Success && !empty($item->Result)) {
            $code = $item->Result[0]->Code;

            $json = json_decode($item->Result[0]->Tag);
            if (is_object($json)) {
                $id_product = $json->id_product;
            } else {
                $id_product = 0;
            }

            //check if Tag not set in hesabfa
            if ($id_product == 0) {
                $msg = 'This Item is not define in OnlineStore';
                PrestaShopLogger::addLog('ssbhesabfa - ' . $msg, 2, null, 'product', $code, true);

                return false;
            }

            //check if product exist in prestashop
            $id_obj = Ssbhesabfa::getObjectId('product', $id_product);
            if ($id_obj > 0) {
                $hesabfa = new HesabfaModel($id_obj);
                $product = new Product($id_product);

                //1.set new Hesabfa Item Code if changes
                if ($hesabfa->id_hesabfa != $code) {
                    $id_hesabfa_old = $hesabfa->id_hesabfa;
                    $hesabfa->id_hesabfa = (int)$code;
                    $hesabfa->update();

                    $msg = 'Item Code changed. Old ID: ' . $id_hesabfa_old . '. New ID: ' . $code;
                    PrestaShopLogger::addLog('ssbhesabfa - ' . $msg, 1, null, 'product', $id_product, true);
                }

                //2.set new Price
                if (Configuration::get('SSBHESABFA_ITEM_UPDATE_PRICE')) {
                    //ToDo check currency calculate
                    $price = Ssbhesabfa::getPriceInHesabfaDefaultCurrency($product->price);
                    if ($item->Result[0]->SellPrice != $price) {
                        $old_price = $product->price;
                        $product->price = $item->Result[0]->SellPrice;
                        $product->update();

                        $msg = 'Item Price changed. Old Price: ' . $old_price . '. New Price: ' . $item->Result[0]->SellPrice;
                        PrestaShopLogger::addLog('ssbhesabfa - ' . $msg, 1, null, 'product', $id_product, true);
                    }
                }
            }
        }
    }

    // use in webhook call (in setInvoiceChangesById function) when invoice change
    public function setItemChangesByCode($code)
    {
        $hesabfaApi = new HesabfaApi();
        $item = $hesabfaApi->itemGet($code);
        if ($item->Success && !empty($item->Result)) {
            $json = json_decode($item->Result->Tag);
            if (is_object($json)) {
                $id_product = $json->id_product;
            } else {
                $id_product = 0;
            }
            //check if Tag not set in hesabfa
            if ($id_product == 0) {
                $msg = 'Item with code: '. $code .' is not define in OnlineStore';
                PrestaShopLogger::addLog('ssbhesabfa - ' . $msg, 2, null, 'product', $code, true);

                return false;
            }

            //check if product exist in prestashop
            $id_obj = Ssbhesabfa::getObjectId('product', $id_product);
            if ($id_obj > 0) {
                $hesabfa = new HesabfaModel($id_obj);
                $product = new Product($id_product);

                //1.set new Hesabfa Item Code if changes
                if ($hesabfa->id_hesabfa != $code) {
                    $id_hesabfa_old = $hesabfa->id_hesabfa;
                    $hesabfa->id_hesabfa = (int)$code;
                    $hesabfa->update();

                    $msg = 'Item Code changed. Old ID: ' . $id_hesabfa_old . '. New ID: ' . $code;
                    PrestaShopLogger::addLog('ssbhesabfa - ' . $msg, 1, null, 'product', $id_product, true);
                }

                //2.set new Price
                if (Configuration::get('SSBHESABFA_ITEM_UPDATE_PRICE')) {
                    //ToDo check currency calculate
                    $price = Ssbhesabfa::getPriceInHesabfaDefaultCurrency($product->price);
                    if ($item->Result->SellPrice != $price) {
                        $old_price = $product->price;
                        $product->price = $item->Result->SellPrice;
                        $product->update();

                        $msg = 'Item Price changed. Old Price: ' . $old_price . '. New Price: ' . $item->Result->SellPrice;
                        PrestaShopLogger::addLog('ssbhesabfa - ' . $msg, 1, null, 'product', $id_product, true);
                    }
                }

                //3.set new Quantity
                if (Configuration::get('SSBHESABFA_ITEM_UPDATE_QUANTITY')) {
                    if ($item->Result->Stock != $product->quantity) {
                        $old_quantity = $product->quantity;
                        $product->quantity = $item->Result->Stock;
                        $product->update();

                        StockAvailable::setQuantity($id_product, null, $item->Result->Stock);

                        $msg = 'Item Quantity changed. Old qty: ' . $old_quantity . '. New qty: ' . $item->Result->Stock;
                        PrestaShopLogger::addLog('ssbhesabfa - ' . $msg, 1, null, 'product', $id_product, true);
                    }
                }
            }
        }
    }
}
