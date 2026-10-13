<?php
if (!defined('_PS_VERSION_')) { exit; }

// Pagination is a code-only fix; do not replay completed changes or send API requests.
function upgrade_module_2_3_38($module)
{
    return true;
}
