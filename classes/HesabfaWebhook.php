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
    public function __construct($autoProcess = true)
    {
        if ($autoProcess) {
            (new HesabfaWebhookService($this))->run();
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
                    Ssbhesabfa::addLegacyLog('ssbhesabfa - ' . $msg, 2, null, 'Order', $number, true);
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
                        Ssbhesabfa::addLegacyLog('ssbhesabfa - ' . $msg, 1, null, 'order', $id_order, true);
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
                Ssbhesabfa::addLegacyLog('ssbhesabfa - ' . $msg, 2, null, 'customer', $code, true);
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
                Ssbhesabfa::addLegacyLog('ssbhesabfa - ' . $msg, 1, null, 'customer', $id_customer, true);
            }
        }

        return true;
    }

    public static function setItemChanges($item, $allowCodeRemap = false, $ignoreUnlinkedItem = false)
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

        // Read the PrestaShop relation from Hesabfa Tag when it exists.
        $json = isset($item->Tag) ? json_decode($item->Tag) : null;
        if (is_object($json) && isset($json->id_product)) {
            $id_product = (int) $json->id_product;
            if (isset($json->id_attribute)) {
                $id_attribute = (int) $json->id_attribute;
            }
        }

        // Items without an online-store relation are valid in Hesabfa invoices.
        // Invoice processing may skip them, while a direct product-change event
        // must still fail so a broken relation is not silently ignored.
        if ($id_product <= 0) {
            $itemCode = isset($item->Code) ? (int) $item->Code : 0;
            if ($ignoreUnlinkedItem) {
                Ssbhesabfa::addLegacyLog(
                    'Hesabfa invoice item is not linked to an online-store product and was skipped. Item code: ' . $itemCode,
                    1,
                    'WEBHOOK_UNLINKED_INVOICE_ITEM_SKIPPED',
                    'product',
                    $itemCode,
                    true,
                    array('hesabfa_code' => $itemCode, 'area' => 'Webhook')
                );
                return true;
            }

            if (Configuration::get('SSBHESABFA_DEBUG_MODE')) {
                $msg = 'Item with code: ' . $itemCode . ' is not defined in OnlineStore';
                Ssbhesabfa::addLegacyLog('ssbhesabfa - ' . $msg, 2, 'WEBHOOK_ITEM_NOT_LINKED', 'product', $itemCode, true, array('hesabfa_code' => $itemCode, 'area' => 'Webhook'));
            }

            return false;
        }

        $id_obj = Ssbhesabfa::getObjectId('product', $id_product, $id_attribute);
        if ($id_obj > 0) {
            $hesabfa = new HesabfaModel($id_obj);
            $product = new Product($id_product);
            if (!Validate::isLoadedObject($product)) {
                Ssbhesabfa::addLegacyLog(
                    'Hesabfa item Tag points to a missing PrestaShop product. Item code: ' . (int) $item->Code . '. Product ID: ' . (int) $id_product,
                    3,
                    'WEBHOOK_LINKED_PRODUCT_NOT_FOUND',
                    'product',
                    $id_product . '-' . $id_attribute,
                    true,
                    array('hesabfa_code' => isset($item->Code) ? (int) $item->Code : null, 'area' => 'Webhook')
                );
                return false;
            }
            $itemStock = isset($item->Stock) && is_numeric($item->Stock) ? (int) $item->Stock : null;
            if (Configuration::get('SSBHESABFA_ITEM_UPDATE_QUANTITY') && $itemStock === null) {
                Ssbhesabfa::addLegacyLog('Invalid stock value received from Hesabfa. Product: ' . (int)$id_product . '-' . (int)$id_attribute . '. Stock: ' . print_r(isset($item->Stock) ? $item->Stock : null, true), 3, null, 'product', $id_product.'-'.$id_attribute, true);
                return false;
            }

            //1. Detect Hesabfa Item Code changes. Price/stock sync must not remap automatically.
            if ($hesabfa->id_hesabfa != (int)$item->Code) {
                if (!$allowCodeRemap) {
                    $msg = 'Hesabfa item code mismatch detected. Current mapped code: ' . (int)$hesabfa->id_hesabfa . '. New code from Hesabfa Tag: ' . (int)$item->Code . '. Mapping was not changed during price/stock sync.';
                    Ssbhesabfa::addLegacyLog('ssbhesabfa - ' . $msg, 2, null, 'product', $id_product.'-'.$id_attribute, true);
                    return true;
                } else {
                    Ssbhesabfa::addLegacyLog('Automatic Hesabfa item code remapping is disabled. Use the manual mismatch review before changing mappings.', 2, null, 'product', $id_product.'-'.$id_attribute, true);
                    return true;
                }
            }

            //2.set new Price
            if (Configuration::get('SSBHESABFA_ITEM_UPDATE_PRICE')) {
                if ($id_attribute != 0) {
                    $combination = new Combination($id_attribute);
                    if (!Validate::isLoadedObject($combination) || (int) $combination->id_product !== (int) $id_product) {
                        Ssbhesabfa::addLegacyLog(
                            'Hesabfa item Tag points to a missing or mismatched PrestaShop combination. Item code: ' . (int) $item->Code . '. Product: ' . (int) $id_product . '-' . (int) $id_attribute,
                            3,
                            'WEBHOOK_LINKED_COMBINATION_NOT_FOUND',
                            'product',
                            $id_product . '-' . $id_attribute,
                            true,
                            array('hesabfa_code' => isset($item->Code) ? (int) $item->Code : null, 'area' => 'Webhook')
                        );
                        return false;
                    }
                    
                    $price = Ssbhesabfa::getPriceInHesabfaDefaultCurrency($product->price + $combination->price);
                    if ($item->SellPrice != $price) {
                        $old_price = $price;
                        $newCombinationPrice = Ssbhesabfa::getPriceInPrestashopDefaultCurrency($item->SellPrice) - $product->price;
                        if (!is_numeric($newCombinationPrice)) {
                            Ssbhesabfa::addLegacyLog('Invalid combination price received from Hesabfa. Product: ' . (int)$id_product . '-' . (int)$id_attribute . '. SellPrice: ' . print_r($item->SellPrice, true), 3, null, 'product', $id_product.'-'.$id_attribute, true);
                            return false;
                        }
                        if (!HesabfaProductService::updateCombinationImpactPrice($id_attribute, $newCombinationPrice)) {
                            Ssbhesabfa::addLegacyLog('Failed to update combination price through PrestaShop ObjectModel. Product: ' . (int) $id_product . '-' . (int) $id_attribute, 3, 'COMBINATION_PRICE_UPDATE_FAILED', 'product', $id_product . '-' . $id_attribute, true);
                            return false;
                        }

                        $msg = "Item $id_product-$id_attribute price changed. Old Price: $old_price. New Price: $item->SellPrice";
                        Ssbhesabfa::addLegacyLog('ssbhesabfa - ' . $msg, 1, null, 'product', $id_product, true);
                    }
                } else {
                    $price = Ssbhesabfa::getPriceInHesabfaDefaultCurrency($product->price);
                    if ($item->SellPrice != $price) {
                        $old_price = $price;
                        $newProductPrice = Ssbhesabfa::getPriceInPrestashopDefaultCurrency($item->SellPrice);
                        if (!is_numeric($newProductPrice) || $newProductPrice < 0) {
                            Ssbhesabfa::addLegacyLog('Invalid product price received from Hesabfa. Product: ' . (int)$id_product . '. SellPrice: ' . print_r($item->SellPrice, true), 3, null, 'product', $id_product, true);
                            return false;
                        }
                        if (!HesabfaProductService::updateBaseProductPrice($id_product, $newProductPrice)) {
                            Ssbhesabfa::addLegacyLog('Failed to update product price through PrestaShop ObjectModel. Product: ' . (int) $id_product, 3, 'PRODUCT_PRICE_UPDATE_FAILED', 'product', $id_product, true);
                            return false;
                        }

                        $msg = "Item $id_product price changed. Old Price: $old_price. New Price: $item->SellPrice";
                        Ssbhesabfa::addLegacyLog('ssbhesabfa - ' . $msg, 1, null, 'product', $id_product, true);
                    }
                }
            }

            //3.set new Quantity
            if (Configuration::get('SSBHESABFA_ITEM_UPDATE_QUANTITY')) {
                if ($id_attribute != 0) {
                    $current_quantity = StockAvailable::getQuantityAvailableByProduct($id_product, $id_attribute);
                    if ($itemStock != $current_quantity) {
                        if (!HesabfaStockService::setQuantity($id_product, $id_attribute, $itemStock)) {
                            Ssbhesabfa::addLegacyLog('Failed to update combination quantity through PrestaShop stock API. Product: ' . (int) $id_product . '-' . (int) $id_attribute, 3, 'COMBINATION_STOCK_UPDATE_FAILED', 'product', $id_product . '-' . $id_attribute, true);
                            return false;
                        }
//                        StockAvailable::updateQuantity($id_product, $id_attribute, $itemStock);

                        //TODO: Check why this object not update the quantity
//                        $combination = new Combination($id_attribute);
//                        $combination->quantity = $itemStock;
//                        $combination->update();
                        // Stock is updated through PrestaShop stock APIs only. Direct stock table updates are intentionally avoided for PrestaShop 8, multistore and advanced stock compatibility.

                        $msg = "Item $id_product-$id_attribute quantity changed. Old qty: $current_quantity. New qty: $itemStock";
                        Ssbhesabfa::addLegacyLog('ssbhesabfa - ' . $msg, 1, null, 'product', $id_product, true);
                    }
                } else {
                    $current_quantity = StockAvailable::getQuantityAvailableByProduct($id_product);
                    if ($itemStock != $current_quantity) {
                        if (!HesabfaStockService::setQuantity($id_product, 0, $itemStock)) {
                            Ssbhesabfa::addLegacyLog('Failed to update product quantity through PrestaShop stock API. Product: ' . (int) $id_product, 3, 'PRODUCT_STOCK_UPDATE_FAILED', 'product', $id_product, true);
                            return false;
                        }
//                        StockAvailable::updateQuantity($id_product, null, $itemStock);

                        //TODO: Check why this object not update the quantity
//                    $product->quantity = $itemStock;
//                    $product->update();

                        // Stock is updated through PrestaShop stock APIs only. Direct product quantity updates are intentionally avoided.

                        $msg = "Item $id_product quantity changed. Old qty: $current_quantity. New qty: $itemStock";
                        Ssbhesabfa::addLegacyLog('ssbhesabfa - ' . $msg, 1, null, 'product', $id_product, true);
                    }
                }
            }
            return true;
        }

        Ssbhesabfa::addLegacyLog(
            'Hesabfa item Tag points to a PrestaShop product without a valid local mapping. Item code: ' . (isset($item->Code) ? (int) $item->Code : 0) . '. Product: ' . (int) $id_product . '-' . (int) $id_attribute,
            3,
            'WEBHOOK_LINKED_ITEM_MAPPING_NOT_FOUND',
            'product',
            $id_product . '-' . $id_attribute,
            true,
            array('hesabfa_code' => isset($item->Code) ? (int) $item->Code : null, 'area' => 'Webhook')
        );
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
