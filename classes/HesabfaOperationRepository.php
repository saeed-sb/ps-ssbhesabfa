<?php
/**
 * Data access helper for financial operation idempotency records.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class HesabfaOperationRepository
{
    public static function getByKey($operationKey)
    {
        $operationKey = (string) $operationKey;
        if ($operationKey === '') {
            return false;
        }

        $query = new DbQuery();
        $query->select('*');
        $query->from('ssb_hesabfa_operation');
        $query->where('`operation_key` = "' . pSQL($operationKey) . '"');

        $row = Db::getInstance()->getRow($query, false);
        return is_array($row) ? $row : false;
    }

    public static function getSuccessful($operationKey)
    {
        $operationKey = (string) $operationKey;
        if ($operationKey === '') {
            return false;
        }

        $query = new DbQuery();
        $query->select('*');
        $query->from('ssb_hesabfa_operation');
        $query->where('`operation_key` = "' . pSQL($operationKey) . '"');
        $query->where('`status` = "success"');

        return Db::getInstance()->getRow($query, false);
    }

    public static function exists($operationKey)
    {
        $operationKey = (string) $operationKey;
        if ($operationKey === '') {
            return false;
        }

        $query = new DbQuery();
        $query->select('COUNT(*)');
        $query->from('ssb_hesabfa_operation');
        $query->where('`operation_key` = "' . pSQL($operationKey) . '"');

        return (bool) Db::getInstance()->getValue($query, false);
    }

    private static $owned = array();

    public static function start($operationKey, $operationType, $objectType = null, $objectId = null)
    {
        $operationKey = (string) $operationKey;
        if ($operationKey === '' || !$operationType || isset(self::$owned[$operationKey])
            || !HesabfaLock::acquire('operation:' . $operationKey)) { return false; }
        $keep = false;
        try {
            $row = self::getByKey($operationKey);
            if ($row && !in_array($row['status'], array('pending', 'failed'), true)) { return false; }
            if ($row && strpos($row['operation_type'], 'manual_') !== 0
                && !empty($row['external_reference']) && empty($row['request_unique_ids'])) {
                Db::getInstance()->update('ssb_hesabfa_operation', array('status'=>'needs_attention', 'message'=>'Remote reference without request IDs requires reconciliation.'), '`operation_key`="'.pSQL($operationKey).'"');
                return false;
            }
            if ($row && !empty($row['request_unique_ids']) && HesabfaRetryPolicy::isRequestIdExpired($row['request_unique_ids_created_at'])) {
                Db::getInstance()->update('ssb_hesabfa_operation', array('status'=>'needs_attention', 'message'=>'Request ID expired; external reconciliation required.'), '`operation_key`="'.pSQL($operationKey).'"');
                return false;
            }
            // A changed invoice payload cannot bypass an earlier ambiguous create.
            if ($operationType === 'invoice_save' && Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM `'._DB_PREFIX_.'ssb_hesabfa_operation` WHERE `operation_type`="invoice_save" AND `object_type`="'.pSQL((string)$objectType).'" AND `object_id`="'.pSQL((string)$objectId).'" AND `operation_key`<>"'.pSQL($operationKey).'" AND `status` IN ("pending","failed","needs_attention") AND `attempts`>0', false)) { return false; }
            $now = date('Y-m-d H:i:s');
            if (!$row) {
                $ok = Db::getInstance()->insert('ssb_hesabfa_operation', array(
                    'operation_key'=>pSQL($operationKey), 'operation_type'=>pSQL($operationType),
                    'object_type'=>pSQL((string)$objectType), 'object_id'=>pSQL((string)$objectId),
                    'status'=>'pending', 'attempts'=>1, 'date_add'=>$now, 'date_upd'=>$now,
                ));
            } else {
                $ok = Db::getInstance()->execute('UPDATE `'._DB_PREFIX_.'ssb_hesabfa_operation` SET `status`="pending", `attempts`=`attempts`+1, `date_upd`=NOW() WHERE `operation_key`="'.pSQL($operationKey).'" AND `status` IN ("pending","failed")')
                    && (int) Db::getInstance()->Affected_Rows() === 1;
            }
            if (!$ok) { return false; }
            $ids = $row && !empty($row['request_unique_ids']) ? json_decode($row['request_unique_ids'], true) : array();
            if (!is_array($ids)) { throw new RuntimeException('Invalid persisted financial request IDs.'); }
            self::$owned[$operationKey] = true;
            HesabfaRequestUniqueId::beginContext($operationKey, $ids, array(__CLASS__, 'saveRequestUniqueIds'));
            $keep = true;
            return true;
        } finally {
            if (!$keep) { HesabfaLock::release('operation:' . $operationKey); }
        }
    }

    public static function saveRequestUniqueIds($key, array $ids)
    {
        if (!isset(self::$owned[$key])) { return false; }
        $row = self::getByKey($key);
        if (!$row) { return false; }
        $old = !empty($row['request_unique_ids']) ? json_decode($row['request_unique_ids'], true) : array();
        // Financial operation keys represent one write. Changed data after an
        // ambiguous response must be reconciled, never sent with a fresh UUID.
        if ($old && $old !== $ids) { throw new RuntimeException('Financial payload changed; external reconciliation required.'); }
        return Db::getInstance()->update('ssb_hesabfa_operation', array(
            'request_unique_ids'=>pSQL(json_encode($ids), true),
            'request_unique_ids_created_at'=>!empty($row['request_unique_ids_created_at']) ? $row['request_unique_ids_created_at'] : date('Y-m-d H:i:s'),
        ), '`operation_key`="'.pSQL($key).'" AND `status`="pending"');
    }

    public static function finish($operationKey, $status, $message = null, $externalReference = null)
    {
        $owned = isset(self::$owned[$operationKey]);
        if (!$owned && !HesabfaLock::acquire('operation:' . $operationKey)) { return false; }
        try {
            $data = array('status'=>pSQL($status), 'message'=>pSQL((string)$message),
                'external_reference'=>pSQL((string)$externalReference), 'date_upd'=>date('Y-m-d H:i:s'));
            // Explicitly verified deletion permits a new logical manual write.
            if (!$owned) {
                $row = self::getByKey($operationKey);
                if (!$row || $row['status'] !== 'success' || $status !== 'failed') { return false; }
                if (strpos($row['operation_type'], 'manual_') === 0) {
                    $data['request_unique_ids'] = null;
                    $data['request_unique_ids_created_at'] = null;
                } else {
                    // A failed local mapping repair does not undo a completed
                    // remote invoice. Keep its success/reference/IDs so another
                    // attempt repairs the mapping instead of creating an invoice.
                    $data['status'] = 'success';
                    $data['external_reference'] = pSQL((string)$row['external_reference']);
                }
            }
            return Db::getInstance()->update('ssb_hesabfa_operation', $data, '`operation_key`="'.pSQL($operationKey).'"');
        } finally {
            if ($owned) {
                unset(self::$owned[$operationKey]);
                HesabfaRequestUniqueId::endContext();
            }
            HesabfaLock::release('operation:' . $operationKey);
        }
    }

    public static function releaseOwned()
    {
        foreach (array_reverse(array_keys(self::$owned)) as $key) {
            unset(self::$owned[$key]);
            HesabfaRequestUniqueId::endContext();
            HesabfaLock::release('operation:' . $key);
        }
    }

    public static function resetFailedToPending($operationKey)
    {
        $operationKey = (string) $operationKey;
        if ($operationKey === '') {
            return false;
        }

        return Db::getInstance()->update('ssb_hesabfa_operation', array(
            'status' => pSQL('pending'),
            'date_upd' => date('Y-m-d H:i:s'),
        ), '`operation_key` = "' . pSQL($operationKey) . '" AND `status` = "failed"');
    }
}
