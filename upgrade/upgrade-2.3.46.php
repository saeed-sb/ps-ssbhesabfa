<?php
if (!defined('_PS_VERSION_')) { exit; }

function upgrade_module_2_3_46($module)
{
    // Code-only replay fix; preserve journal entries and their checkpoint.
    return ($module->name ?? '') === 'ssbhesabfa';
}
