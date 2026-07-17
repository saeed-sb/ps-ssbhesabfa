<?php
/**
 * Product write service used by webhook/sync code.
 *
 * Price changes are applied through PrestaShop ObjectModel classes so that
 * the platform's official update hooks, multistore fields and cache handling
 * remain in the normal PrestaShop lifecycle.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class HesabfaProductService
{
    /**
     * Products currently being changed by an inbound Hesabfa update.
     * This request-scoped guard prevents the module's outbound product hooks
     * from sending the same change straight back to Hesabfa.
     *
     * @var array<int,int>
     */
    protected static $inboundProductDepth = array();

    public static function updateBaseProductPrice($idProduct, $price, $idShop = null)
    {
        $idProduct = (int) $idProduct;
        $price = (float) $price;
        $idShop = self::resolveShopId($idShop);

        if ($idProduct <= 0 || $price < 0) {
            return false;
        }

        $product = new Product($idProduct, false, null, $idShop > 0 ? $idShop : null);
        if (!Validate::isLoadedObject($product)) {
            return false;
        }

        if (self::pricesAreEqual($product->price, $price)) {
            return true;
        }

        self::beginInboundSync($idProduct);
        try {
            if ($idShop > 0 && property_exists($product, 'id_shop_list')) {
                $product->id_shop_list = array($idShop);
            }

            $product->price = $price;
            $result = (bool) $product->update();

            if ($result) {
                self::refreshProductRuntimeState($idProduct);
            }

            return $result;
        } catch (Exception $e) {
            return false;
        } finally {
            self::endInboundSync($idProduct);
        }
    }

    public static function updateCombinationImpactPrice($idProductAttribute, $impactPrice, $idShop = null)
    {
        $idProductAttribute = (int) $idProductAttribute;
        $impactPrice = (float) $impactPrice;
        $idShop = self::resolveShopId($idShop);

        if ($idProductAttribute <= 0) {
            return false;
        }

        $combination = new Combination(
            $idProductAttribute,
            null,
            $idShop > 0 ? $idShop : null
        );

        if (!Validate::isLoadedObject($combination) || (int) $combination->id_product <= 0) {
            return false;
        }

        $idProduct = (int) $combination->id_product;
        if (self::pricesAreEqual($combination->price, $impactPrice)) {
            return true;
        }

        self::beginInboundSync($idProduct);
        try {
            if ($idShop > 0 && property_exists($combination, 'id_shop_list')) {
                $combination->id_shop_list = array($idShop);
            }

            $combination->price = $impactPrice;
            $result = (bool) $combination->update();

            if ($result) {
                self::refreshProductRuntimeState($idProduct);
            }

            return $result;
        } catch (Exception $e) {
            return false;
        } finally {
            self::endInboundSync($idProduct);
        }
    }

    public static function isInboundSync($idProduct)
    {
        $idProduct = (int) $idProduct;
        return $idProduct > 0 && !empty(self::$inboundProductDepth[$idProduct]);
    }

    protected static function beginInboundSync($idProduct)
    {
        $idProduct = (int) $idProduct;
        if ($idProduct <= 0) {
            return;
        }

        if (!isset(self::$inboundProductDepth[$idProduct])) {
            self::$inboundProductDepth[$idProduct] = 0;
        }
        self::$inboundProductDepth[$idProduct]++;
    }

    protected static function endInboundSync($idProduct)
    {
        $idProduct = (int) $idProduct;
        if ($idProduct <= 0 || !isset(self::$inboundProductDepth[$idProduct])) {
            return;
        }

        self::$inboundProductDepth[$idProduct]--;
        if (self::$inboundProductDepth[$idProduct] <= 0) {
            unset(self::$inboundProductDepth[$idProduct]);
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

    protected static function pricesAreEqual($left, $right)
    {
        return abs((float) $left - (float) $right) < 0.000001;
    }

    protected static function refreshProductRuntimeState($idProduct)
    {
        $idProduct = (int) $idProduct;
        if ($idProduct <= 0) {
            return;
        }

        if (class_exists('Product') && method_exists('Product', 'flushPriceCache')) {
            Product::flushPriceCache();
        }
        if (class_exists('Product') && method_exists('Product', 'flushStaticCache')) {
            Product::flushStaticCache();
        }
        if (class_exists('Cache')) {
            Cache::clean('Product::getPriceStatic_*');
            Cache::clean('product_' . (int) $idProduct . '_*');
        }
    }
}
