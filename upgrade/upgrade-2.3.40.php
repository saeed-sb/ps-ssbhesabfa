<?php
if (!defined('_PS_VERSION_')) { exit; }

// Shipment-accounting guards are code-only; never enable or replay financial work.
function upgrade_module_2_3_40($module)
{
    return true;
}
