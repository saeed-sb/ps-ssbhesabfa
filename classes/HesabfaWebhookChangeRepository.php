<?php
if (!defined('_PS_VERSION_')) { exit; }
class HesabfaWebhookChangeRepository
{
    const PROCESSING_LOCK_NAME = 'ssbhesabfa_webhook_processing';

    public static function acquireProcessingLock()
    {
        $rows = Db::getInstance()->executeS(
            'SELECT GET_LOCK("' . self::PROCESSING_LOCK_NAME . '", 0) AS acquired'
        , true, false);

        return is_array($rows)
            && isset($rows[0]['acquired'])
            && (int) $rows[0]['acquired'] === 1;
    }

    public static function releaseProcessingLock()
    {
        Db::getInstance()->executeS(
            'SELECT RELEASE_LOCK("' . self::PROCESSING_LOCK_NAME . '") AS released', true, false
        );
    }

    public static function save($change)
    {
        if (!is_object($change) || empty($change->Id)) return false;
        $payload=json_encode($change); $now=date('Y-m-d H:i:s');
        $sql='INSERT INTO `'._DB_PREFIX_.'ssb_hesabfa_webhook_change` (`change_id`,`object_type`,`object_id`,`action_code`,`payload`,`status`,`attempts`,`last_error`,`date_add`,`date_upd`) VALUES ('
            .(int)$change->Id.',"'.pSQL(isset($change->ObjectType)?$change->ObjectType:'').'","'.pSQL(isset($change->ObjectId)?$change->ObjectId:''). '",' .(int)(isset($change->Action)?$change->Action:0).',"'.pSQL($payload,true).'","pending",0,NULL,"'.$now.'","'.$now.'") '
            .'ON DUPLICATE KEY UPDATE `payload`=VALUES(`payload`),`date_upd`=VALUES(`date_upd`)';
        return Db::getInstance()->execute($sql);
    }
    /** Called only while the exclusive processing lock is held. */
    public static function recoverStaleRunning()
    {
        return Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . 'ssb_hesabfa_webhook_change`'
            . ' SET `status`="pending", `last_error`="Recovered interrupted webhook processing.", `date_upd`=NOW()'
            . ' WHERE `status`="running" AND `date_upd`<DATE_SUB(NOW(), INTERVAL 15 MINUTE)'
        );
    }

    public static function advanceCheckpoint($changeId)
    {
        // Read fresh under the processing lock; recovery must never rewind the cursor.
        $current = (int) Db::getInstance()->getValue(
            'SELECT MAX(CAST(`value` AS UNSIGNED)) FROM `' . _DB_PREFIX_ . 'configuration`'
            . ' WHERE `name`="SSBHESABFA_LAST_LOG_CHECK_ID"',
            false
        );
        return Configuration::updateValue('SSBHESABFA_LAST_LOG_CHECK_ID', max($current, (int) $changeId));
    }

    public static function getPending($limit=100)
    {
        $q=new DbQuery(); $q->select('*'); $q->from('ssb_hesabfa_webhook_change'); $q->where('`status` IN ("pending","failed")'); $q->orderBy('`change_id` ASC'); $q->limit(max(1,min(500,(int)$limit))); $r=Db::getInstance()->executeS($q); return is_array($r)?$r:array();
    }

    /** Called under the processing lock; evidence does not complete the later change. */
    public static function getLaterInvoiceDeletionId($invoiceId, $changeId, $action)
    {
        foreach (array($invoiceId, $changeId) as $identity) {
            if ((!is_int($identity) && !is_string($identity))
                || !ctype_digit((string) $identity) || (int) $identity <= 0) {
                throw new Exception('Invalid invoice webhook identity.');
            }
        }
        $deletionActions = array(121 => 123, 122 => 123, 131 => 133, 132 => 133,
            141 => 143, 142 => 143, 151 => 153, 152 => 153);
        if ((!is_int($action) && !is_string($action)) || !ctype_digit((string) $action)
            || !isset($deletionActions[(int) $action])) {
            return null;
        }
        $deletionAction = $deletionActions[(int) $action];
        $rows = Db::getInstance()->executeS(
            'SELECT `change_id`, `object_id`, `action_code`, `payload`'
            . ' FROM `' . _DB_PREFIX_ . 'ssb_hesabfa_webhook_change`'
            . ' WHERE `object_type`="Invoice" AND `object_id`="' . (int) $invoiceId . '"'
            . ' AND `change_id`>' . (int) $changeId . ' AND `action_code`=' . $deletionAction
            . ' AND `status` IN ("pending","failed","running","done")'
            . ' ORDER BY `change_id` ASC LIMIT 1',
            true,
            false
        );
        if (!is_array($rows)) {
            throw new Exception('Later invoice deletion could not be read.');
        }
        if (empty($rows)) {
            return null;
        }
        if (count($rows) !== 1 || !isset($rows[0]) || !is_array($rows[0])) {
            throw new Exception('Invalid later invoice deletion in the webhook journal.');
        }
        $row = $rows[0];
        foreach (array('change_id', 'object_id', 'action_code') as $field) {
            if (!isset($row[$field]) || (!is_int($row[$field]) && !is_string($row[$field]))
                || !ctype_digit((string) $row[$field])) {
                throw new Exception('Invalid later invoice deletion in the webhook journal.');
            }
        }
        $deletion = isset($row['payload']) && is_string($row['payload'])
            ? json_decode($row['payload']) : null;
        if (!is_object($deletion) || json_last_error() !== JSON_ERROR_NONE
            || !isset($deletion->Id, $deletion->ObjectId, $deletion->ObjectType, $deletion->Action)
            || !is_string($deletion->ObjectType) || $deletion->ObjectType !== 'Invoice') {
            throw new Exception('Invalid later invoice deletion in the webhook journal.');
        }
        foreach (array('Id' => 'change_id', 'ObjectId' => 'object_id', 'Action' => 'action_code') as $field => $column) {
            if ((!is_int($deletion->$field) && !is_string($deletion->$field))
                || !ctype_digit((string) $deletion->$field)
                || (string) $deletion->$field !== (string) $row[$column]) {
                throw new Exception('Invalid later invoice deletion in the webhook journal.');
            }
        }
        if ((int) $row['change_id'] <= (int) $changeId
            || (int) $row['object_id'] !== (int) $invoiceId
            || (int) $row['action_code'] !== $deletionAction) {
            throw new Exception('Invalid later invoice deletion in the webhook journal.');
        }

        return (int) $row['change_id'];
    }

    public static function normalizeItemCodes(array $codes)
    {
        $result = array();
        foreach ($codes as $code) {
            if (!is_string($code) && !is_int($code)) {
                throw new Exception('Invalid invoice item code in the webhook journal.');
            }
            $code = trim((string) $code);
            if ($code !== '' && !in_array($code, $result, true)) {
                $result[] = $code;
            }
        }

        return $result;
    }

    public static function getInvoiceItemCodes($invoiceId, $changeId)
    {
        if ((int) $invoiceId <= 0 || (int) $changeId <= 0) {
            throw new Exception('Invalid invoice webhook identity.');
        }
        // The newest snapshot is cumulative. Older journals bootstrap invoices
        // first observed before snapshots existed. Never include future events.
        $rows = Db::getInstance()->executeS(
            'SELECT `payload`, `invoice_item_codes` FROM `' . _DB_PREFIX_ . 'ssb_hesabfa_webhook_change`'
            . ' WHERE `object_type`="Invoice" AND `object_id`="' . (int) $invoiceId . '"'
            . ' AND `change_id`<=' . (int) $changeId . ' ORDER BY `change_id` DESC',
            true,
            false
        );
        if (!is_array($rows)) {
            throw new Exception('Invoice item history could not be read.');
        }
        $codes = array();
        foreach ($rows as $row) {
            if ($row['invoice_item_codes'] !== null) {
                $snapshot = json_decode($row['invoice_item_codes'], true);
                if (!is_array($snapshot) || json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception('Invalid invoice item snapshot in the webhook journal.');
                }
                $codes = array_merge($codes, $snapshot);
                break;
            }
            $change = json_decode($row['payload']);
            if (!is_object($change) || (isset($change->Extra) && !is_string($change->Extra))) {
                throw new Exception('Invalid invoice history in the webhook journal.');
            }
            $codes = array_merge($codes, explode(',', isset($change->Extra) ? $change->Extra : ''));
        }

        return self::normalizeItemCodes($codes);
    }

    public static function saveInvoiceItemCodes($changeId, $invoiceId, array $codes)
    {
        $json = json_encode(self::normalizeItemCodes($codes));
        if ($json === false) {
            return false;
        }

        return Db::getInstance()->update(
            'ssb_hesabfa_webhook_change',
            array('invoice_item_codes' => pSQL($json, true)),
            '`change_id`=' . (int) $changeId . ' AND `object_type`="Invoice" AND `object_id`="' . (int) $invoiceId . '"'
        );
    }

    public static function getSupersededProductChangeIds(array $changeIds)
    {
        $changeIds = array_values(array_unique(array_filter(array_map('intval', $changeIds))));
        if (empty($changeIds)) {
            return array();
        }

        $rows = Db::getInstance()->executeS(
            'SELECT current_change.`change_id`'
            . ' FROM `' . _DB_PREFIX_ . 'ssb_hesabfa_webhook_change` current_change'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'ssb_hesabfa_webhook_change` later_change'
            . ' ON later_change.`object_type` = current_change.`object_type`'
            . ' AND later_change.`object_id` = current_change.`object_id`'
            . ' AND later_change.`action_code` = 52'
            . ' AND later_change.`change_id` > current_change.`change_id`'
            . ' AND later_change.`status` IN ("pending","failed","running")'
            . ' WHERE current_change.`change_id` IN (' . implode(',', $changeIds) . ')'
            . ' AND current_change.`object_type` = "Product"'
            . ' AND current_change.`action_code` = 52'
            . ' GROUP BY current_change.`change_id`'
        );

        $result = array();
        foreach ((array) $rows as $row) {
            $result[(int) $row['change_id']] = true;
        }

        return $result;
    }

    public static function markRunning($id) { return Db::getInstance()->execute('UPDATE `'._DB_PREFIX_.'ssb_hesabfa_webhook_change` SET `status`="running",`attempts`=`attempts`+1,`date_upd`=NOW() WHERE `change_id`='.(int)$id.' AND `status` IN ("pending","failed")'); }
    public static function markDone($id) { return Db::getInstance()->update('ssb_hesabfa_webhook_change',array('status'=>'done','last_error'=>null,'date_upd'=>date('Y-m-d H:i:s')),'`change_id`='.(int)$id); }
    public static function markPending($id,$error) { return Db::getInstance()->update('ssb_hesabfa_webhook_change',array('status'=>'pending','last_error'=>pSQL((string)$error),'date_upd'=>date('Y-m-d H:i:s')),'`change_id`='.(int)$id); }
    public static function markFailed($id,$error) { return Db::getInstance()->update('ssb_hesabfa_webhook_change',array('status'=>'failed','last_error'=>pSQL((string)$error),'date_upd'=>date('Y-m-d H:i:s')),'`change_id`='.(int)$id); }
    public static function isDone($id) { return (string)Db::getInstance()->getValue('SELECT `status` FROM `'._DB_PREFIX_.'ssb_hesabfa_webhook_change` WHERE `change_id`='.(int)$id)==='done'; }

    public static function countByStatuses($statuses)
    {
        if (!is_array($statuses)) {
            $statuses = array($statuses);
        }

        $quotedStatuses = array();
        foreach ($statuses as $status) {
            $status = trim((string) $status);
            if ($status !== '') {
                $quotedStatuses[] = '"' . pSQL($status) . '"';
            }
        }

        if (empty($quotedStatuses)) {
            return 0;
        }

        return (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'ssb_hesabfa_webhook_change`'
            . ' WHERE `status` IN (' . implode(',', $quotedStatuses) . ')'
        );
    }
}
