<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_3_27($module)
{
    $query = new DbQuery();
    $query->select('`object_type`, `object_id`, `external_reference`');
    $query->from('ssb_hesabfa_operation');
    $query->where('`operation_type` = "invoice_save"');
    $query->where('`object_type` IN ("ReturnOrder", "returnOrder", "return_order")');
    $query->where('`status` = "success"');
    $query->orderBy('`date_upd` ASC, `id_ssb_hesabfa_operation` ASC');

    $rows = Db::getInstance()->executeS($query);
    if (!is_array($rows)) {
        return false;
    }

    $latestMappings = array();

    foreach ($rows as $row) {
        $objectType = strtolower(preg_replace('/[^a-z]/i', '', (string) $row['object_type']));
        $objectId = trim((string) $row['object_id']);
        $externalReference = trim((string) $row['external_reference']);

        if (
            $objectType !== 'returnorder'
            || !ctype_digit($objectId)
            || !ctype_digit($externalReference)
            || (int) $objectId <= 0
            || (int) $externalReference <= 0
        ) {
            continue;
        }

        $latestMappings[(int) $objectId] = (int) $externalReference;
    }

    foreach ($latestMappings as $idOrder => $hesabfaInvoiceNumber) {
        if (HesabfaMappingRepository::getHesabfaCode('returnOrder', $idOrder, 0) !== null) {
            continue;
        }

        if (!HesabfaMappingRepository::upsert('returnOrder', $idOrder, $hesabfaInvoiceNumber, 0)) {
            return false;
        }

        if (HesabfaMappingRepository::getHesabfaCode('returnOrder', $idOrder, 0) !== $hesabfaInvoiceNumber) {
            return false;
        }
    }

    return true;
}
