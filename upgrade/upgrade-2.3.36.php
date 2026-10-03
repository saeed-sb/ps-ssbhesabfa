<?php
if (!defined('_PS_VERSION_')) { exit; }

function upgrade_module_2_3_36($module)
{
    require_once __DIR__ . '/upgrade-2.3.35.php';
    // Recheck the schema and quarantine unfinished ID-less writes even when
    // upgrading from 2.3.35. This adds no fields beyond that version's schema.
    return upgrade_module_2_3_35($module);
}
