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

        $query = new DbQuery();
        $query->select('`obj_type`, `id_ps`, `id_ps_attribute`');
        $query->from('ssb_hesabfa');
        $query->where('`id_ssb_hesabfa` = ' . (int) $idMapping);

        $mapping = Db::getInstance()->getRow($query);
        if (!is_array($mapping) || empty($mapping)) {
            return false;
        }

        return self::upsert(
            (string) $mapping['obj_type'],
            (int) $mapping['id_ps'],
            (int) $hesabfaCode,
            (int) $mapping['id_ps_attribute']
        );
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

        if (!Db::getInstance()->execute($sql)) {
            return false;
        }

        if (
            $type === 'product'
            && self::isProductReferenceSyncEnabled()
            && !self::syncProductReference($idPs, $idPsAttribute, $idHesabfa)
        ) {
            self::logProductReferenceSyncFailure($idPs, $idPsAttribute, $idHesabfa);
        }

        // The mapping is the source of truth. A reference write failure is
        // logged for repair, but must not make callers create another item.
        return true;
    }

    public static function syncAllProductReferences()
    {
        $db = Db::getInstance();

        $productSql = 'UPDATE `' . _DB_PREFIX_ . 'product` p '
            . 'INNER JOIN `' . _DB_PREFIX_ . 'ssb_hesabfa` h '
            . 'ON p.`id_product` = h.`id_ps` '
            . 'AND h.`id_ps_attribute` = 0 '
            . "AND h.`obj_type` = 'product' "
            . 'SET p.`reference` = h.`id_hesabfa`';

        if (!$db->execute($productSql)) {
            self::logProductReferenceSyncFailure(0, 0, 0, true);
            return false;
        }

        $combinationSql = 'UPDATE `' . _DB_PREFIX_ . 'product_attribute` pa '
            . 'INNER JOIN `' . _DB_PREFIX_ . 'ssb_hesabfa` h '
            . 'ON pa.`id_product` = h.`id_ps` '
            . 'AND pa.`id_product_attribute` = h.`id_ps_attribute` '
            . "AND h.`obj_type` = 'product' "
            . 'SET pa.`reference` = h.`id_hesabfa`';

        if (!$db->execute($combinationSql)) {
            self::logProductReferenceSyncFailure(0, 0, 0, true);
            return false;
        }

        return true;
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

    private static function isProductReferenceSyncEnabled()
    {
        return (bool) Configuration::get('SSBHESABFA_ITEM_CODE_AS_REFERENCE');
    }

    private static function syncProductReference($idPs, $idPsAttribute, $idHesabfa)
    {
        $idPs = (int) $idPs;
        $idPsAttribute = (int) $idPsAttribute;
        $idHesabfa = (int) $idHesabfa;

        if ($idPs <= 0 || $idHesabfa <= 0) {
            return false;
        }

        if ($idPsAttribute === 0) {
            return (bool) Db::getInstance()->update(
                'product',
                array('reference' => (string) $idHesabfa),
                '`id_product` = ' . (int) $idPs
            );
        }

        return (bool) Db::getInstance()->update(
            'product_attribute',
            array('reference' => (string) $idHesabfa),
            '`id_product` = ' . (int) $idPs
            . ' AND `id_product_attribute` = ' . (int) $idPsAttribute
        );
    }

    private static function logProductReferenceSyncFailure($idPs, $idPsAttribute, $idHesabfa, $bulk = false)
    {
        if (!class_exists('HesabfaLogService')) {
            return;
        }

        $message = $bulk
            ? 'Could not synchronize existing Hesabfa item codes with PrestaShop product references.'
            : 'Could not synchronize a Hesabfa item code with the PrestaShop product reference.';

        HesabfaLogService::addModuleLog(
            $message,
            'ERROR',
            'PRODUCT_REFERENCE_SYNC_FAILED',
            'Product',
            $bulk ? null : (int) $idPs . '-' . (int) $idPsAttribute,
            array(
                'prestashop_code' => $bulk ? null : (int) $idPs . '-' . (int) $idPsAttribute,
                'hesabfa_code' => $bulk ? null : (string) (int) $idHesabfa,
            )
        );
    }

}
