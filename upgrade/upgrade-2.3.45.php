<?php
if (!defined('_PS_VERSION_')) { exit; }

function upgrade_module_2_3_45($module)
{
    // Code-only compatibility fix; normal journal retries resume after deployment.
    return ($module->name ?? '') === 'ssbhesabfa';
}
