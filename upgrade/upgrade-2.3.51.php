<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_3_51($module)
{
    // New receipt plans use a gateway contact code; existing plans remain readable.
    return ($module->name ?? '') === 'ssbhesabfa';
}
