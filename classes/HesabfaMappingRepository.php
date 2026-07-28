<?php
/**
 * Data access helper for ssb_hesabfa object mappings.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class HesabfaMappingRepository
{
    public static function shouldEnforceUniqueHesabfaCode($type)
    {
        $type = (string) $type;

        return !in_array($type, array('order', 'returnOrder'), true);
    }

    public static function getObjectRowId($type, $idPs, $idPsAttribute = 0)
    {
        $type = (string) $type;
        $idPs = (int) $idPs;
        $idPsAttribute = (int) $idPsAttribute;

        if ($type === '' || $idPs <= 0) {
            return 0;
        }

        $query = new DbQuery();
        $query->select('`id_ssb_hesabfa`');
        $query->from('ssb_hesabfa');
        $query->where('`obj_type` = "' . pSQL($type) . '"');
        $query->where('`id_ps` = ' . (int) $idPs);
        $query->where('`id_ps_attribute` = ' . (int) $idPsAttribute);

        return (int) Db::getInstance()->getValue($query);
    }

    public static function getObjectRowIdByCode($type, $idHesabfa)
    {
        $type = (string) $type;
        $idHesabfa = (int) $idHesabfa;

        if ($type === '' || $idHesabfa <= 0) {
            return 0;
        }

        $query = new DbQuery();
        $query->select('`id_ssb_hesabfa`');
        $query->from('ssb_hesabfa');
        $query->where('`obj_type` = "' . pSQL($type) . '"');
        $query->where('`id_hesabfa` = ' . (int) $idHesabfa);

        return (int) Db::getInstance()->getValue($query);
    }

    public static function getHesabfaCode($type, $idPs, $idPsAttribute = 0)
    {
        $type = (string) $type;
        $idPs = (int) $idPs;
        $idPsAttribute = (int) $idPsAttribute;

        if ($type === '' || $idPs <= 0) {
            return null;
        }

        $query = new DbQuery();
        $query->select('`id_hesabfa`');
        $query->from('ssb_hesabfa');
        $query->where('`obj_type` = "' . pSQL($type) . '"');
        $query->where('`id_ps` = ' . (int) $idPs);
        $query->where('`id_ps_attribute` = ' . (int) $idPsAttribute);

        $value = Db::getInstance()->getValue($query);
        return ($value === false || $value === null || $value === '') ? null : (int) $value;
    }

    public static function getProductAttributeRowIds($idPs)
    {
        $idPs = (int) $idPs;
        if ($idPs <= 0) {
            return array();
        }

        $query = new DbQuery();
        $query->select('`id_ssb_hesabfa`');
        $query->from('ssb_hesabfa');
        $query->where('`obj_type` = "product"');
        $query->where('`id_ps` = ' . (int) $idPs);

        $rows = Db::getInstance()->executeS($query);
        if (!is_array($rows)) {
            return array();
        }

        $ids = array();
        foreach ($rows as $row) {
            $ids[] = (int) $row['id_ssb_hesabfa'];
        }

        return $ids;
    }

    public static function getProductMappingRow($idPs, $idPsAttribute = 0)
    {
        $idPs = (int) $idPs;
        $idPsAttribute = (int) $idPsAttribute;
        if ($idPs <= 0) {
            return null;
        }

        $query = new DbQuery();
        $query->select('*');
        $query->from('ssb_hesabfa');
        $query->where('`obj_type` = "product"');
        $query->where('`id_ps` = ' . (int) $idPs);
        $query->where('`id_ps_attribute` = ' . (int) $idPsAttribute);

        $row = Db::getInstance()->getRow($query);
        return is_array($row) ? $row : null;
    }

    public static function getProductMappingByHesabfaCode($hesabfaCode)
    {
        $hesabfaCode = (int) $hesabfaCode;
        if ($hesabfaCode <= 0) {
            return null;
        }

        $query = new DbQuery();
        $query->select('*');
        $query->from('ssb_hesabfa');
        $query->where('`obj_type` = "product"');
        $query->where('`id_hesabfa` = ' . (int) $hesabfaCode);

        $row = Db::getInstance()->getRow($query);
        return is_array($row) ? $row : null;
    }

    public static function updateHesabfaCode($idMapping, $hesabfaCode)
    {
        $idMapping = (int) $idMapping;
        $hesabfaCode = (int) $hesabfaCode;
        if ($idMapping <= 0 || $hesabfaCode <= 0) {
            return false;
        }

        return Db::getInstance()->update('ssb_hesabfa', array(
            'id_hesabfa' => (int) $hesabfaCode,
        ), '`id_ssb_hesabfa` = ' . (int) $idMapping);
    }
    public static function upsert($type, $idPs, $idHesabfa, $idPsAttribute = 0)
    {
        $type = (string) $type;
        $idPs = (int) $idPs;
        $idHesabfa = (int) $idHesabfa;
        $idPsAttribute = (int) $idPsAttribute;

        if ($type === '' || $idPs <= 0 || $idHesabfa <= 0) {
            return false;
        }

        $existing = self::getObjectRowId($type, $idPs, $idPsAttribute);
        $conflict = self::shouldEnforceUniqueHesabfaCode($type)
            ? self::getObjectRowIdByCode($type, $idHesabfa)
            : 0;

        if ($conflict && $conflict !== $existing) {
            return false;
        }

        // The unique obj_type/id_ps/id_ps_attribute index makes this atomic.
        // Concurrent responses can no longer race between SELECT and INSERT.
        $sql = 'INSERT INTO ' . _DB_PREFIX_ . 'ssb_hesabfa '
            . '(obj_type, id_hesabfa, id_ps, id_ps_attribute) VALUES ('
            . '"' . pSQL($type) . '", '
            . (int) $idHesabfa . ', '
            . (int) $idPs . ', '
            . (int) $idPsAttribute . ') '
            . 'ON DUPLICATE KEY UPDATE id_hesabfa = VALUES(id_hesabfa)';

        return (bool) Db::getInstance()->execute($sql);
    }

    public static function deleteProductMapping($idPs, $idPsAttribute = 0)
    {
        $idPs = (int) $idPs;
        $idPsAttribute = (int) $idPsAttribute;
        if ($idPs <= 0) {
            return false;
        }

        return Db::getInstance()->delete(
            'ssb_hesabfa',
            '`obj_type` = "product" AND `id_ps` = ' . (int) $idPs . ' AND `id_ps_attribute` = ' . (int) $idPsAttribute
        );
    }

}
