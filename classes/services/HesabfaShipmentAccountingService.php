<?php
if (!defined('_PS_VERSION_')) { exit; }
require_once dirname(__DIR__, 2) . '/classes/HesabfaDeliveryBridge.php';

/** Compatibility only; shipment business logic and state belong to Delivery Manager. */
class HesabfaShipmentAccountingService
{
    private $delegate;
    public function __construct(...$arguments)
    { $type = HesabfaDeliveryBridge::type('ShipmentService'); $this->delegate = new $type(...$arguments); }
    public function __call($method, $arguments)
    { return call_user_func_array(array($this->delegate, $method), $arguments); }
    public static function __callStatic($method, $arguments)
    { return call_user_func_array(array(HesabfaDeliveryBridge::type('ShipmentService'), $method), $arguments); }
}
