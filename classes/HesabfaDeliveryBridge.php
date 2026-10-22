<?php
if (!defined('_PS_VERSION_')) { exit; }

/** Legacy shipment entry points delegate without preparing or sending documents. */
final class HesabfaDeliveryBridge
{
    public static function type($name)
    {
        if (!Module::isEnabled('ssbdeliverymanager')) {
            throw new RuntimeException('Delivery Manager must be enabled to process shipment accounting.');
        }
        Module::getInstanceByName('ssbdeliverymanager');
        $class = 'Ssb\\DeliveryManager\\Accounting\\' . $name;
        if (!class_exists($class)) { throw new RuntimeException('Update Delivery Manager before processing shipment accounting.'); }
        return $class;
    }
}
