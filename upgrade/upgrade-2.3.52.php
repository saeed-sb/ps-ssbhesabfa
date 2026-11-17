<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_3_52($module)
{
    // Fee descriptions are saved in each new receipt plan.
    return ($module->name ?? '') === 'ssbhesabfa';
}
