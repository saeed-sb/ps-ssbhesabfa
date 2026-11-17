<?php
/**
 * Small read-only repository for PrestaShop entities used by batch jobs.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class HesabfaPrestashopRepository
{
    public static function getProductIdsAfter($lastProductId, $limit)
    {
        $lastProductId = (int) $lastProductId;
        $limit = max(1, min(500, (int) $limit));

        $query = new DbQuery();
        $query->select('`id_product`');
        $query->from('product');
        $query->where('`id_product` > ' . (int) $lastProductId);
        $query->orderBy('`id_product` ASC');
        $query->limit($limit);

        $rows = Db::getInstance()->executeS($query);
        return is_array($rows) ? $rows : array();
    }

    public static function getCustomerIdsAfter($lastCustomerId, $limit)
    {
        $lastCustomerId = (int) $lastCustomerId;
        $limit = max(1, min(500, (int) $limit));

        $query = new DbQuery();
        $query->select('`id_customer`');
        $query->from('customer');
        $query->where('`id_customer` > ' . (int) $lastCustomerId);
        $query->orderBy('`id_customer` ASC');
        $query->limit($limit);

        $rows = Db::getInstance()->executeS($query);
        return is_array($rows) ? $rows : array();
    }


    public static function countProducts()
    {
        return (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'product`');
    }

    public static function countCustomers()
    {
        return (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'customer`');
    }

    public static function countProductIdsAfter($lastProductId)
    {
        $lastProductId = (int) $lastProductId;
        $query = new DbQuery();
        $query->select('COUNT(*)');
        $query->from('product');
        $query->where('`id_product` > ' . (int) $lastProductId);
        return (int) Db::getInstance()->getValue($query);
    }

    public static function countCustomerIdsAfter($lastCustomerId)
    {
        $lastCustomerId = (int) $lastCustomerId;
        $query = new DbQuery();
        $query->select('COUNT(*)');
        $query->from('customer');
        $query->where('`id_customer` > ' . (int) $lastCustomerId);
        return (int) Db::getInstance()->getValue($query);
    }

    public static function orderHasFreeShippingCartRule($idOrder)
    {
        $idOrder = (int) $idOrder;
        if ($idOrder <= 0) {
            return false;
        }

        $query = new DbQuery();
        $query->select('COUNT(*)');
        $query->from('order_cart_rule');
        $query->where('`id_order` = ' . (int) $idOrder);
        $query->where('`free_shipping` = 1');

        return (bool) Db::getInstance()->getValue($query);
    }

    public static function getInstallmentPaymentModules($idOrder)
    {
        // Vendor Pay records the selected vendor and gateway on the order lines.
        $query = new DbQuery();
        $query->select('DISTINCT `payment_module`');
        $query->from('installment_order_detail');
        $query->where('`id_order` = ' . (int) $idOrder);
        $query->where("`vendor_type` = 'installment'");
        $rows = Db::getInstance()->executeS($query, true, false);
        if (!is_array($rows)) {
            throw new RuntimeException('The Qestyar order details could not be read.');
        }

        return array_column($rows, 'payment_module');
    }

    public static function getVerifiedCheckoutProvider($order, $payment)
    {
        $reference = pSQL((string) $payment->transaction_id);
        $query = new DbQuery();
        $query->select('DISTINCT `provider`');
        $query->from('ssbph_transaction');
        $query->where('`id_shop` = ' . (int) $order->id_shop);
        $query->where('`id_cart` = ' . (int) $order->id_cart);
        // The confirmation hook can run before Payment Hub attaches the order.
        $query->where('(`id_order` IS NULL OR `id_order` = ' . (int) $order->id . ')');
        $query->where('`flow_version` = 2 AND `verified_at` IS NOT NULL');
        $query->where('`id_currency` = ' . (int) $payment->id_currency);
        $query->where('ABS(`cart_amount` - ' . (float) $payment->amount . ') <= 0.01');
        $query->where("COALESCE(NULLIF(`tracking_code`, ''), `ref_num`) = '" . $reference . "'");
        $rows = Db::getInstance()->executeS($query, true, false);
        if (!is_array($rows)) {
            throw new RuntimeException('The verified Payment Hub transaction could not be read.');
        }

        return count($rows) === 1 ? (string) $rows[0]['provider'] : '';
    }

    public static function getOrderModuleName($idOrder)
    {
        $idOrder = (int) $idOrder;
        if ($idOrder <= 0) {
            return false;
        }

        $query = new DbQuery();
        $query->select('`module`');
        $query->from('orders');
        $query->where('`id_order` = ' . (int) $idOrder);

        $moduleName = Db::getInstance()->getValue($query);
        return $moduleName === false ? false : (string) $moduleName;
    }
}
