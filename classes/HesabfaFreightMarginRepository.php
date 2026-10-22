<?php
if (!defined('_PS_VERSION_')) { exit; }
require_once dirname(__DIR__) . '/classes/HesabfaDeliveryBridge.php';

/** Compatibility only; shipment business logic and state belong to Delivery Manager. */
class HesabfaFreightMarginRepository
{
    public function install() { return true; }
    private $delegate;
    public function __construct(...$arguments)
    { if (Module::isEnabled('ssbdeliverymanager')) { $type = HesabfaDeliveryBridge::type('Repository'); $this->delegate = new $type(...$arguments); } }
    public function __call($method, $arguments)
    { return call_user_func_array(array($this->delegate, $method), $arguments); }
    public static function __callStatic($method, $arguments)
    { return call_user_func_array(array(HesabfaDeliveryBridge::type('Repository'), $method), $arguments); }
}
