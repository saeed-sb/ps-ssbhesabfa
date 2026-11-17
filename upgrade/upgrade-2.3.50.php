<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_3_50($module)
{
    // The title update keeps the existing payment configuration.
    return ($module->name ?? '') === 'ssbhesabfa';
}
