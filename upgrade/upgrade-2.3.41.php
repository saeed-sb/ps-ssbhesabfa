<?php
if (!defined('_PS_VERSION_')) { exit; }

// Delivery Manager now installs and upgrades shipping-owned storage.
// Keep this historical step inert, including when Delivery Manager is absent.
function upgrade_module_2_3_41($module)
{
    return true;
}
