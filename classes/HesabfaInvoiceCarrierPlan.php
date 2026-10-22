<?php
if (!defined('_PS_VERSION_')) { exit; }
require_once dirname(__DIR__) . '/classes/HesabfaDeliveryBridge.php';

/** Compatibility only; shipment business logic and state belong to Delivery Manager. */
class HesabfaInvoiceCarrierPlan
{
    public static function __callStatic($method, $arguments)
    { return call_user_func_array(array(HesabfaDeliveryBridge::type('InvoicePlan'), $method), $arguments); }
}
