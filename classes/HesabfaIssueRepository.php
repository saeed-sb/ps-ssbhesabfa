<?php
/**
 * Data access helper for actionable module issues.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class HesabfaIssueRepository
{
    public static function countByStatus(array $statuses)
    {
        $cleanStatuses = array();
        foreach ($statuses as $status) {
            if (in_array((string) $status, array('open', 'retrying', 'resolved'), true)) {
                $cleanStatuses[] = '"' . pSQL((string) $status) . '"';
            }
        }
        if (empty($cleanStatuses)) {
            return 0;
        }

        $query = new DbQuery();
        $query->select('COUNT(*)');
        $query->from('ssb_hesabfa_issue');
        $query->where('`status` IN (' . implode(',', array_unique($cleanStatuses)) . ')');

        return (int) Db::getInstance()->getValue($query);
    }

    public static function add($issueType, $severity, $message, $objectType = null, $objectId = null, $operationKey = null)
    {
        $issueType = (string) $issueType;
        $objectType = (string) $objectType;
        $objectId = (string) $objectId;
        $operationKey = (string) $operationKey;
        $normalizedMessage = HesabfaTextHelper::normalizeLogMessage($message);

        $query = new DbQuery();
        $query->select('`id_ssb_hesabfa_issue`');
        $query->from('ssb_hesabfa_issue');
        $query->where('`issue_type` = "' . pSQL($issueType) . '"');
        $query->where('`object_type` = "' . pSQL($objectType) . '"');
        $query->where('`object_id` = "' . pSQL($objectId) . '"');
        $query->where('`operation_key` = "' . pSQL($operationKey) . '"');
        $query->where('`status` IN ("open","retrying")');
        $query->orderBy('`id_ssb_hesabfa_issue` DESC');

        $existingId = (int) Db::getInstance()->getValue($query);
        if ($existingId > 0) {
            return Db::getInstance()->update('ssb_hesabfa_issue', array(
                'severity' => pSQL((string) $severity),
                'status' => pSQL('open'),
                'message' => pSQL($normalizedMessage),
                'date_upd' => date('Y-m-d H:i:s'),
            ), '`id_ssb_hesabfa_issue` = ' . $existingId);
        }

        return Db::getInstance()->insert('ssb_hesabfa_issue', array(
            'issue_type' => pSQL($issueType),
            'severity' => pSQL((string) $severity),
            'status' => pSQL('open'),
            'object_type' => pSQL($objectType),
            'object_id' => pSQL($objectId),
            'operation_key' => pSQL($operationKey),
            'message' => pSQL($normalizedMessage),
            'date_add' => date('Y-m-d H:i:s'),
            'date_upd' => date('Y-m-d H:i:s'),
        ));
    }

    public static function resolveByOperationKey($operationKey, $message = null)
    {
        $operationKey = (string) $operationKey;
        if ($operationKey === '') {
            return false;
        }

        $fields = array(
            'status' => pSQL('resolved'),
            'date_upd' => date('Y-m-d H:i:s'),
        );

        if ($message !== null) {
            $fields['message'] = pSQL((string) $message);
        }

        return Db::getInstance()->update('ssb_hesabfa_issue', $fields, '`operation_key` = "' . pSQL($operationKey) . '" AND `status` != "resolved"');
    }

    public static function resolveByObject($issueType, $objectType, $objectId, $message = null)
    {
        $fields = array(
            'status' => pSQL('resolved'),
            'date_upd' => date('Y-m-d H:i:s'),
        );

        if ($message !== null) {
            $fields['message'] = pSQL((string) $message);
        }

        return Db::getInstance()->update(
            'ssb_hesabfa_issue',
            $fields,
            '`issue_type` = "' . pSQL((string) $issueType) . '"'
            . ' AND `object_type` = "' . pSQL((string) $objectType) . '"'
            . ' AND `object_id` = "' . pSQL((string) $objectId) . '"'
            . ' AND `status` != "resolved"'
        );
    }

    public static function getOpen($limit = 50)
    {
        return self::getByStatus(array('open', 'retrying'), $limit);
    }

    public static function getByStatus(array $statuses, $limit = 50)
    {
        $limit = max(1, min(200, (int) $limit));
        $cleanStatuses = array();
        foreach ($statuses as $status) {
            $cleanStatuses[] = '"' . pSQL((string) $status) . '"';
        }
        if (empty($cleanStatuses)) {
            $cleanStatuses[] = '"open"';
        }

        $query = new DbQuery();
        $query->select('*');
        $query->from('ssb_hesabfa_issue');
        $query->where('`status` IN (' . implode(',', $cleanStatuses) . ')');
        $query->orderBy('`id_ssb_hesabfa_issue` DESC');
        $query->limit($limit);

        $rows = Db::getInstance()->executeS($query);
        return is_array($rows) ? $rows : array();
    }

    public static function markResolved($idIssue)
    {
        return Db::getInstance()->update('ssb_hesabfa_issue', array(
            'status' => pSQL('resolved'),
            'date_upd' => date('Y-m-d H:i:s'),
        ), '`id_ssb_hesabfa_issue` = ' . (int) $idIssue);
    }

    public static function markRetrying($idIssue)
    {
        return Db::getInstance()->update('ssb_hesabfa_issue', array(
            'status' => pSQL('retrying'),
            'date_upd' => date('Y-m-d H:i:s'),
        ), '`id_ssb_hesabfa_issue` = ' . (int) $idIssue);
    }
}
