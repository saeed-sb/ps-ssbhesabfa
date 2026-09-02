<?php
if (!defined('_PS_VERSION_')) { exit; }
class HesabfaWebhookChangeRepository
{
    const PROCESSING_LOCK_NAME = 'ssbhesabfa_webhook_processing';

    public static function acquireProcessingLock()
    {
        $rows = Db::getInstance()->executeS(
            'SELECT GET_LOCK("' . self::PROCESSING_LOCK_NAME . '", 0) AS acquired'
        );

        return is_array($rows)
            && isset($rows[0]['acquired'])
            && (int) $rows[0]['acquired'] === 1;
    }

    public static function releaseProcessingLock()
    {
        Db::getInstance()->executeS(
            'SELECT RELEASE_LOCK("' . self::PROCESSING_LOCK_NAME . '") AS released'
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
    public static function getPending($limit=100)
    {
        $q=new DbQuery(); $q->select('*'); $q->from('ssb_hesabfa_webhook_change'); $q->where('`status` IN ("pending","failed")'); $q->orderBy('`change_id` ASC'); $q->limit(max(1,min(500,(int)$limit))); $r=Db::getInstance()->executeS($q); return is_array($r)?$r:array();
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
