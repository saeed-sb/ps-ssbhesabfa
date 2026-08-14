<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_3_29($module)
{
    return method_exists($module, 'isMcpCompliant') && $module->isMcpCompliant();
}
