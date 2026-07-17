<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Version 2.3.10 contains application-code fixes only:
 * - corrected the default Hesabfa bank account path;
 * - propagated payment and fee-document failures to the queue.
 *
 * No database schema or configuration migration is required.
 */
function upgrade_module_2_3_10($module)
{
    return true;
}
