<?php
if (!defined('_PS_VERSION_')) { exit; }

// The shared-class bootstrap fix requires no schema changes or API requests.
function upgrade_module_2_3_37($module)
{
    return true;
}
