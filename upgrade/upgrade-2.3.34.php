<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_3_34($module)
{
    return Validate::isLoadedObject($module);
}
