<?php
if (!defined('_PS_VERSION_')) { exit; }

function upgrade_module_2_3_47($module)
{
    // Code-only fiscal-year scope fix; preserve settings, journal and checkpoint.
    return ($module->name ?? '') === 'ssbhesabfa';
}
