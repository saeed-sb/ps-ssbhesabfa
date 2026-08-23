<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_3_32($module)
{
    return Validate::isLoadedObject($module);
}
