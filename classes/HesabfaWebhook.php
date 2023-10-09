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

class HesabfaWebhook
{
    public $invoicesObjectId = array();
    public $invoiceItemsCode = array();
    public $itemsObjectId = array();
    public $contactsObjectId = array();

    public function __construct()
    {
        $hesabfaApi = new HesabfaApi();
        $lastChange = Configuration::get('SSBHESABFA_LAST_LOG_CHECK_ID');
        $changes = $hesabfaApi->settingGetChanges($lastChange + 1);
        if ($changes->Success) {
            foreach ($changes->Result as $item) {
                if (!$item->API) {
                    switch ($item->ObjectType) {
                        case 'Invoice':
                            $this->invoicesObjectId[] = $item->ObjectId;
                            foreach (explode(',', $item->Extra) as $invoiceItem) {
                                if ($invoiceItem != ''){
                                    $this->invoiceItemsCode[] = $invoiceItem;
                                }
                            }
                            // Added for ssbprofitloyalty module
                            if (Module::isInstalled('ssbprofitloyalty') && Module::isEnabled('ssbprofitloyalty')) {
                                if ($item->Action == 121 || $item->Action == 122 || $item->Action == 123) {
                                    require_once(_PS_MODULE_DIR_.'/ssbprofitloyalty/ssbprofitloyalty.php');
                                    $ssbprofitloyalty = new Ssbprofitloyalty();
                                    switch ($item->Action) {
                                        case 121:
                                            $ssbprofitloyalty->addInvoiceById($item->ObjectId);
                                            break;

                                        case 122:
                                            $ssbprofitloyalty->updateInvoiceById($item->ObjectId);
                                            break;

                                        case 123:
                                            $ssbprofitloyalty->deleteInvoiceById($item->ObjectId);
                                            break;
                                    }
                                }
                            }
                            
                            break;
                        case 'Product':
                            //if Action was deleted
                            if ($item->Action == 53) {
                                $id_obj = Ssbhesabfa::getObjectIdByCode('product', $item->Extra);
                                $hesabfa = new HesabfaModel($id_obj);
                                $hesabfa->delete();

                                break;
                            }

                            $this->itemsObjectId[] = $item->ObjectId;
                            break;
                        case 'Contact':
                            //if Action was deleted
                            if ($item->Action == 33) {
                                $id_obj = Ssbhesabfa::getObjectIdByCode('customer', $item->Extra);
                                $hesabfa = new HesabfaModel($id_obj);
                                $hesabfa->delete();

                                break;
                            }

                            $this->contactsObjectId[] = $item->ObjectId;
                            break;
                    }
                }
            }

            //remove duplicate values
            $this->invoiceItemsCode = array_unique($this->invoiceItemsCode);
            $this->contactsObjectId = array_unique($this->contactsObjectId);
            $this->itemsObjectId = array_unique($this->itemsObjectId);
            $this->invoicesObjectId = array_unique($this->invoicesObjectId);

            $this->setChanges();
            //set LastChange ID
            $lastChange = end($changes->Result);
            if (is_object($lastChange)) {
                Configuration::updateValue('SSBHESABFA_LAST_LOG_CHECK_ID', $lastChange->Id);
            }

        } else {
            PrestaShopLogger::addLog('ssbhesabfa - Cannot check last changes. Error Message: ' . $changes->ErrorMessage, 2, $changes->ErrorCode, null, null, true);
        }
    }

    public function setChanges() {
        //Invoices
        if (!empty($this->invoicesObjectId)) {
            $invoices = $this->getObjectsByIdList($this->invoicesObjectId, 'invoice');
            if ($invoices != false) {
                foreach ($invoices as $invoice) {
                    $this->setInvoiceChanges($invoice);
                }
            }
        }

        //Contacts
        if (!empty($this->contactsObjectId)) {
            $contacts = $this->getObjectsByIdList($this->contactsObjectId, 'contact');
            if ($contacts != false) {
                foreach ($contacts as $contact) {
                    $this->setContactChanges($contact);
                }
            }
        }

        //Items
        $items = array();
        if (!empty($this->itemsObjectId)) {
            $objects = $this->getObjectsByIdList($this->itemsObjectId, 'item');
            if ($objects != false) {
                foreach ($objects as $object) {
                    array_push($items, $object);
                }
            }
        }

        if (!empty($this->invoiceItemsCode)) {
            $objects = $this->getObjectsByCodeList($this->invoiceItemsCode);
            if ($objects != false) {
                foreach ($objects as $object) {
                    array_push($items, $object);
                }
            }
        }

        if (!empty($items)) {
            foreach ($items as $item) {
                $this->setItemChanges($item);
            }
        }

        return true;
    }

    public function setInvoiceChanges($invoice)
    {
        if (!is_object($invoice)) {
            return false;
        }

        //1.set new Hesabfa Invoice Code if changes
        $number = $invoice->Number;
        $json = json_decode($invoice->Tag);
        if (is_object($json)) {
            $id_order = $json->id_order;
        } else {
            $id_order = 0;
        }

        if ($invoice->InvoiceType == 0) {
            //check if Tag not set in hesabfa
            if ($id_order == 0) {
                if (Configuration::get('SSBHESABFA_DEBUG_MODE')) {
                    $msg = 'This invoice is not define in OnlineStore';
                    PrestaShopLogger::addLog('ssbhesabfa - ' . $msg, 2, null, 'Order', $number, true);
                }
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

        return true;
    }

    public function setContactChanges($contact)
    {
        if (!is_object($contact)) {
            return false;
        }

        //1.set new Hesabfa Contact Code if changes
        $code = $contact->Code;

        $json = json_decode($contact->Tag);
        if (is_object($json)) {
            $id_customer = $json->id_customer;
        } else {
            $id_customer = 0;
        }

        //check if Tag not set in hesabfa
        if ($id_customer == 0) {
            if (Configuration::get('SSBHESABFA_DEBUG_MODE')) {
                $msg = 'This Customer is not define in OnlineStore';
                PrestaShopLogger::addLog('ssbhesabfa - ' . $msg, 2, null, 'customer', $code, true);
            }

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

        return true;
    }

    public static function setItemChanges($item)
    {
        if (!is_object($item)) {
            return false;
        }

        //do nothing if product is GiftWrapping item
        if (Configuration::get('SSBHESABFA_ITEM_GIFT_WRAPPING_ID') == $item->Code) {
            return true;
        }

        $id_product = 0;
        $id_attribute = 0;

        //set ids if set
        $json = json_decode($item->Tag);
        if (is_object($json)) {
            $id_product = $json->id_product;
            if (isset($json->id_attribute)) {
                $id_attribute = $json->id_attribute;
            }
        }

        //check if Tag not set in hesabfa
        if ($id_product == 0) {
            if (Configuration::get('SSBHESABFA_DEBUG_MODE')) {
                $msg = 'Item with code: '. $item->Code .' is not define in OnlineStore';
                PrestaShopLogger::addLog('ssbhesabfa - ' . $msg, 2, null, 'product', $item->Code, true);
            }

            return false;
        }

        $id_obj = Ssbhesabfa::getObjectId('product', $id_product, $id_attribute);
        if ($id_obj > 0) {
            $hesabfa = new HesabfaModel($id_obj);
            //ToDo: if product not exists in PS then return
            $product = new Product($id_product);

            //1.set new Hesabfa Item Code if changes
            if ($hesabfa->id_hesabfa != (int)$item->Code) {
                $id_hesabfa_old = $hesabfa->id_hesabfa;
                $hesabfa->id_hesabfa = (int)$item->Code;
                $hesabfa->update();

                $msg = 'Item Code changed. Old ID: ' . $id_hesabfa_old . '. New ID: ' . (int)$item->Code;
                PrestaShopLogger::addLog('ssbhesabfa - ' . $msg, 1, null, 'product', $id_product.'-'.$id_attribute, true);
            }

            //2.set new Price
            if (Configuration::get('SSBHESABFA_ITEM_UPDATE_PRICE')) {
                if ($id_attribute != 0) {
                    $combination = new Combination($id_attribute);
                    if (empty($combination->id_product)) {
                        return;
                    }
                    
                    $price = Ssbhesabfa::getPriceInHesabfaDefaultCurrency($product->price + $combination->price);
                    if ($item->SellPrice != $price) {
                        $old_price = $price;
                        $combination->price = Ssbhesabfa::getPriceInPrestashopDefaultCurrency($item->SellPrice) - $product->price;
                        $combination->update();

                        $msg = "Item $id_product-$id_attribute price changed. Old Price: $old_price. New Price: $item->SellPrice";
                        PrestaShopLogger::addLog('ssbhesabfa - ' . $msg, 1, null, 'product', $id_product, true);
                    }
                } else {
                    $price = Ssbhesabfa::getPriceInHesabfaDefaultCurrency($product->price);
                    if ($item->SellPrice != $price) {
                        $old_price = $price;
                        $product->price = Ssbhesabfa::getPriceInPrestashopDefaultCurrency($item->SellPrice);
                        $product->update();

                        $msg = "Item $id_product price changed. Old Price: $old_price. New Price: $item->SellPrice";
                        PrestaShopLogger::addLog('ssbhesabfa - ' . $msg, 1, null, 'product', $id_product, true);
                    }
                }
            }

            //3.set new Quantity
            if (Configuration::get('SSBHESABFA_ITEM_UPDATE_QUANTITY')) {
                if ($id_attribute != 0) {
                    $current_quantity = StockAvailable::getQuantityAvailableByProduct($id_product, $id_attribute);
                    if ($item->Stock != $current_quantity) {
                        StockAvailable::setQuantity($id_product, $id_attribute, $item->Stock);
//                        StockAvailable::updateQuantity($id_product, $id_attribute, $item->Stock);

                        //TODO: Check why this object not update the quantity
//                        $combination = new Combination($id_attribute);
//                        $combination->quantity = $item->Stock;
//                        $combination->update();
                        if ($item->Stock > $current_quantity ) {
                            $sql = 'UPDATE `' . _DB_PREFIX_ . 'stock_available`
                                SET `quantity` = `quantity` + '. $item->Stock - $current_quantity . '
                                WHERE `id_product` = ' . $id_product . ' AND `id_product_attribute` = 0';
                            Db::getInstance()->execute($sql);
                        } else {
                            $sql = 'UPDATE `' . _DB_PREFIX_ . 'stock_available`
                                SET `quantity` = `quantity` - '. $item->Stock - $current_quantity . '
                                WHERE `id_product` = ' . $id_product . ' AND `id_product_attribute` = 0';
                            Db::getInstance()->execute($sql);
                        }

                        $sql = 'UPDATE `' . _DB_PREFIX_ . 'product_attribute`
                                SET `quantity` = '. $item->Stock . '
                                WHERE `id_product` = ' . $id_product . ' AND `id_product_attribute` = ' . $id_attribute;
                        Db::getInstance()->execute($sql);

                        $msg = "Item $id_product-$id_attribute quantity changed. Old qty: $current_quantity. New qty: $item->Stock";
                        PrestaShopLogger::addLog('ssbhesabfa - ' . $msg, 1, null, 'product', $id_product, true);
                    }
                } else {
                    $current_quantity = StockAvailable::getQuantityAvailableByProduct($id_product);
                    if ($item->Stock != $current_quantity) {
                        StockAvailable::setQuantity($id_product, null, $item->Stock);
//                        StockAvailable::updateQuantity($id_product, null, $item->Stock);

                        //TODO: Check why this object not update the quantity
//                    $product->quantity = $item->Stock;
//                    $product->update();

                        $sql = 'UPDATE `' . _DB_PREFIX_ . 'product`
                                SET `quantity` = '. $item->Stock . '
                                WHERE `id_product` = ' . $id_product;
                        Db::getInstance()->execute($sql);

                        $msg = "Item $id_product quantity changed. Old qty: $current_quantity. New qty: $item->Stock";
                        PrestaShopLogger::addLog('ssbhesabfa - ' . $msg, 1, null, 'product', $id_product, true);
                    }
                }
            }
            return true;
        }
        return false;
    }

    public function getObjectsByIdList($idList, $type) {
        $hesabfaApi = new HesabfaApi();
        switch ($type) {
            case 'item':
                $result = $hesabfaApi->itemGetById($idList);
                break;
            case 'contact':
                $result = $hesabfaApi->contactGetById($idList);
                break;
            case 'invoice':
                $result = $hesabfaApi->invoiceGetById($idList);
                break;
            default:
                return false;
        }

        if (is_object($result) && $result->Success) {
            return $result->Result;
        }

        return false;
    }

    public function getObjectsByCodeList($codeList) {
        $queryInfo = array(
            'Filters' => array(array(
                'Property' => 'Code',
                'Operator' => 'in',
                'Value' => $codeList,
            ))
        );

        $hesabfaApi = new HesabfaApi();
        $result = $hesabfaApi->itemGetItems($queryInfo);

        if (is_object($result) && $result->Success) {
            return $result->Result->List;
        }

        return false;
    }
}
