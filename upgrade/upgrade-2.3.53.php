<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_3_53($module)
{
    return ($module->name ?? '') === 'ssbhesabfa';
}
