<?php
/**
 * Stock update service. All stock writes go through PrestaShop stock APIs.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class HesabfaStockService
{
    public static function setQuantity($idProduct, $idProductAttribute, $quantity, $idShop = null)
    {
        $idProduct = (int) $idProduct;
        $idProductAttribute = (int) $idProductAttribute;
        $quantity = (int) $quantity;
        $idShop = self::resolveShopId($idShop);

        if ($idProduct <= 0) {
            return false;
        }

        $product = new Product($idProduct, false, null, $idShop > 0 ? $idShop : null);
        if (!Validate::isLoadedObject($product)) {
            return false;
        }

        if ($idProductAttribute > 0) {
            $combination = new Combination($idProductAttribute);
            if (!Validate::isLoadedObject($combination) || (int) $combination->id_product !== $idProduct) {
                return false;
            }
        }

        try {
            StockAvailable::setQuantity(
                $idProduct,
                $idProductAttribute,
                $quantity,
                $idShop > 0 ? $idShop : null
            );
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    protected static function resolveShopId($idShop = null)
    {
        $idShop = (int) $idShop;
        if ($idShop > 0) {
            return $idShop;
        }

        if (class_exists('Shop') && method_exists('Shop', 'getContextShopID')) {
            $contextShopId = (int) Shop::getContextShopID();
            if ($contextShopId > 0) {
                return $contextShopId;
            }
        }

        $context = Context::getContext();
        if ($context && isset($context->shop) && Validate::isLoadedObject($context->shop)) {
            return (int) $context->shop->id;
        }

        return (int) Configuration::get('PS_SHOP_DEFAULT');
    }
}
