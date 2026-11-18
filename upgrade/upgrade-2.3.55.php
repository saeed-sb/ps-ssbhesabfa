<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_3_55($module)
{
    return ($module->name ?? '') === 'ssbhesabfa';
}
