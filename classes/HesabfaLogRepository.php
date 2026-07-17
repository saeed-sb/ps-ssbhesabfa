<?php
/**
 * Data access helper for module logs.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class HesabfaLogRepository
{
    public static function clearAll()
    {
        return Db::getInstance()->execute('TRUNCATE TABLE `' . _DB_PREFIX_ . 'ssb_hesabfa_log`');
    }

    public static function getStats()
    {
        $totalQuery = new DbQuery();
        $totalQuery->select('COUNT(*)');
        $totalQuery->from('ssb_hesabfa_log');

        $errorsQuery = new DbQuery();
        $errorsQuery->select('COUNT(*)');
        $errorsQuery->from('ssb_hesabfa_log');
        $errorsQuery->where('`severity` >= 3');

        $warningsQuery = new DbQuery();
        $warningsQuery->select('COUNT(*)');
        $warningsQuery->from('ssb_hesabfa_log');
        $warningsQuery->where('`severity` = 2');

        return array(
            'total' => (int) Db::getInstance()->getValue($totalQuery),
            'errors' => (int) Db::getInstance()->getValue($errorsQuery),
            'warnings' => (int) Db::getInstance()->getValue($warningsQuery),
        );
    }

    protected static function applyFilters(DbQuery $query, array $filters)
    {
        if (isset($filters['severity']) && $filters['severity'] !== '' && Validate::isUnsignedInt($filters['severity'])) {
            $query->where('`severity` = ' . (int) $filters['severity']);
        }

        if (!empty($filters['area'])) {
            $query->where('`area` = "' . pSQL((string) $filters['area']) . '"');
        }

        if (!empty($filters['object_type'])) {
            $query->where('`object_type` = "' . pSQL((string) $filters['object_type']) . '"');
        }

        if (!empty($filters['date_from']) && Validate::isDateFormat($filters['date_from'])) {
            $query->where('`date_add` >= "' . pSQL((string) $filters['date_from']) . ' 00:00:00"');
        }

        if (!empty($filters['date_to']) && Validate::isDateFormat($filters['date_to'])) {
            $query->where('`date_add` <= "' . pSQL((string) $filters['date_to']) . ' 23:59:59"');
        }

        if (!empty($filters['keyword'])) {
            $keyword = pSQL((string) $filters['keyword']);
            $query->where('(`message` LIKE "%' . $keyword . '%" OR `error_code` LIKE "%' . $keyword . '%" OR `object_id` LIKE "%' . $keyword . '%" OR `area` LIKE "%' . $keyword . '%" OR `debug_endpoint` LIKE "%' . $keyword . '%")');
        }

        if (!empty($filters['prestashop_code'])) {
            $psCode = pSQL((string) $filters['prestashop_code']);
            $query->where('(`prestashop_code` LIKE "%' . $psCode . '%" OR `object_id` LIKE "%' . $psCode . '%")');
        }

        if (!empty($filters['hesabfa_code'])) {
            $hesabfaCode = pSQL((string) $filters['hesabfa_code']);
            $query->where('`hesabfa_code` LIKE "%' . $hesabfaCode . '%"');
        }
    }

    public static function countList(array $filters = array())
    {
        $query = new DbQuery();
        $query->select('COUNT(*)');
        $query->from('ssb_hesabfa_log');
        self::applyFilters($query, $filters);
        return (int) Db::getInstance()->getValue($query);
    }

    public static function getList(array $filters = array(), $limit = 50, $offset = 0)
    {
        $limit = max(1, min(200, (int) $limit));
        $offset = max(0, (int) $offset);
        $query = new DbQuery();
        $query->select('*');
        $query->from('ssb_hesabfa_log');
        self::applyFilters($query, $filters);
        $query->orderBy('`id_ssb_hesabfa_log` DESC');
        $query->limit($limit, $offset);

        $rows = Db::getInstance()->executeS($query);
        return is_array($rows) ? $rows : array();
    }
}
