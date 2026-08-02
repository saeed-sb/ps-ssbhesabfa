<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_3_26($module)
{
    if (
        Configuration::get('SSBHESABFA_ITEM_CODE_AS_REFERENCE') === false
        && !Configuration::updateValue('SSBHESABFA_ITEM_CODE_AS_REFERENCE', 0)
    ) {
        return false;
    }

    if (
        Configuration::get('SSBHESABFA_ITEM_CODE_AS_REFERENCE')
        && class_exists('HesabfaMappingRepository')
    ) {
        return HesabfaMappingRepository::syncAllProductReferences();
    }

    return true;
}
