<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_3_49($module)
{
    // Existing gateways read the previous cutoff until an individual choice is saved.
    return ($module->name ?? '') === 'ssbhesabfa';
}
