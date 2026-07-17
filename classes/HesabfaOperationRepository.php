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

        $row = Db::getInstance()->getRow($query);
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

        return Db::getInstance()->getRow($query);
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

        return (bool) Db::getInstance()->getValue($query);
    }

    public static function start($operationKey, $operationType, $objectType = null, $objectId = null)
    {
        $operationKey = (string) $operationKey;
        $operationType = (string) $operationType;

        if ($operationKey === '' || $operationType === '') {
            return false;
        }

        if (self::getSuccessful($operationKey)) {
            return false;
        }

        if (!self::exists($operationKey)) {
            return Db::getInstance()->insert('ssb_hesabfa_operation', array(
                'operation_key' => pSQL($operationKey),
                'operation_type' => pSQL($operationType),
                'object_type' => pSQL((string) $objectType),
                'object_id' => pSQL((string) $objectId),
                'status' => pSQL('pending'),
                'attempts' => 1,
                'date_add' => date('Y-m-d H:i:s'),
                'date_upd' => date('Y-m-d H:i:s'),
            ));
        }

        return Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . 'ssb_hesabfa_operation` SET `status` = "pending", `attempts` = `attempts` + 1, `date_upd` = NOW() WHERE `operation_key` = "' . pSQL($operationKey) . '" AND `status` != "success"'
        );
    }

    public static function finish($operationKey, $status, $message = null, $externalReference = null)
    {
        $operationKey = (string) $operationKey;
        $status = (string) $status;
        if ($operationKey === '' || $status === '') {
            return false;
        }

        return Db::getInstance()->update('ssb_hesabfa_operation', array(
            'status' => pSQL($status),
            'message' => pSQL((string) $message),
            'external_reference' => pSQL((string) $externalReference),
            'date_upd' => date('Y-m-d H:i:s'),
        ), '`operation_key` = "' . pSQL($operationKey) . '"');
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

