<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/** Install only owned storage and the shipping-cost event; never replay work. */
function upgrade_module_2_3_39($module)
{
    require_once dirname(__DIR__) . '/classes/HesabfaFreightMarginRepository.php';

    return (new HesabfaFreightMarginRepository())->install()
        && $module->registerHook('actionSsbDeliveryShippingCostSaved');
}
