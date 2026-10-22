<?php
if (!defined('_PS_VERSION_')) { exit; }

function upgrade_module_2_3_43($module)
{
    // Generic API/runtime changes only; delivery owns migration and processing.
    return ($module->name ?? '') === 'ssbhesabfa';
}
