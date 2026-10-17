<?php
if (!defined('_PS_VERSION_')) { exit; }

/** Durable ownership and immutable in-flight writes for shipment accounting. */
class HesabfaFreightMarginRepository
{
    protected $db;
    public function __construct($db = null) { $this->db = $db ?: Db::getInstance(); }

    public function install()
    {
        return $this->db->execute('CREATE TABLE IF NOT EXISTS '._DB_PREFIX_."ssb_hesabfa_freight_margin (
            id_order INT UNSIGNED NOT NULL, id_shop INT UNSIGNED NOT NULL,
            account_fingerprint CHAR(64) NOT NULL DEFAULT '', source_revision CHAR(64) NOT NULL DEFAULT '',
            policy_hash CHAR(64) NOT NULL DEFAULT '', document_number VARCHAR(32) NOT NULL DEFAULT '',
            document_id BIGINT UNSIGNED NOT NULL DEFAULT 0, verified_document MEDIUMTEXT NULL,
            margin_rial BIGINT NOT NULL DEFAULT 0, version INT UNSIGNED NOT NULL DEFAULT 0,
            pending_json MEDIUMTEXT NULL, date_upd DATETIME NOT NULL,
            PRIMARY KEY(id_shop,id_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4")
        && $this->db->execute('CREATE TABLE IF NOT EXISTS '._DB_PREFIX_."ssb_hesabfa_freight_margin_audit (
            id_audit BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, id_order INT UNSIGNED NOT NULL,
            id_shop INT UNSIGNED NOT NULL, event VARCHAR(32) NOT NULL, details MEDIUMTEXT NOT NULL,
            date_add DATETIME NOT NULL, PRIMARY KEY(id_audit), KEY order_history(id_shop,id_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function ready()
    {
        $rows = $this->db->executeS("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('".pSQL(_DB_PREFIX_.'ssb_hesabfa_freight_margin')."','".pSQL(_DB_PREFIX_.'ssbdm_shipping_cost_accounting')."')", true, false);
        return is_array($rows) && count($rows) === 2;
    }

    public function source($shop, $order)
    {
        return $this->one('SELECT * FROM '._DB_PREFIX_.'ssbdm_shipping_cost_accounting WHERE id_shop='.(int)$shop.' AND id_order='.(int)$order);
    }

    public function order($shop, $order)
    {
        return $this->one('SELECT id_order,id_shop,id_carrier,reference FROM '._DB_PREFIX_.'orders WHERE id_shop='.(int)$shop.' AND id_order='.(int)$order);
    }

    public function state($shop, $order)
    {
        return $this->one('SELECT * FROM '._DB_PREFIX_.'ssb_hesabfa_freight_margin WHERE id_shop='.(int)$shop.' AND id_order='.(int)$order);
    }

    public function shippingCost($shop,$order)
    {
        return $this->one('SELECT c.* FROM '._DB_PREFIX_.'ssbdm_shipping_cost c INNER JOIN '._DB_PREFIX_.'orders o ON o.id_order=c.id_order WHERE o.id_shop='.(int)$shop.' AND c.id_order='.(int)$order);
    }

    /** Same native identity as Delivery Manager AccountingSource::trackingIdentity. */
    public function trackingSource($shop,$order)
    {
        $rows=$this->db->executeS('SELECT o.id_carrier,oc.id_order_carrier,oc.id_carrier AS shipment_carrier,oc.tracking_number FROM `'._DB_PREFIX_.'orders` o LEFT JOIN `'._DB_PREFIX_.'order_carrier` oc ON oc.id_order=o.id_order WHERE o.id_shop='.(int)$shop.' AND o.id_order='.(int)$order.' ORDER BY oc.id_order_carrier',true,false);
        if (!is_array($rows) || !$rows || (int)$rows[0]['id_carrier']<1) { throw new RuntimeException('Unable to read current shipment tracking.'); }
        $parts=array((int)$rows[0]['id_carrier']); $tracking=array();
        foreach ($rows as $row) {
            if (empty($row['id_order_carrier'])) { continue; }
            $code=self::trackingCode($row['tracking_number']);
            $parts[]=array((int)$row['id_order_carrier'],(int)$row['shipment_carrier'],$code);
            if ($code!=='') { $tracking[]=$code; }
        }
        $tracking=array_values(array_unique($tracking)); sort($tracking,SORT_STRING);
        return array('source_key'=>hash('sha256',json_encode($parts)),'id_carrier'=>(int)$rows[0]['id_carrier'],'tracking'=>$tracking);
    }

    public static function trackingCode($value)
    {
        $digits=json_decode('{"\u06f0":"0","\u06f1":"1","\u06f2":"2","\u06f3":"3","\u06f4":"4","\u06f5":"5","\u06f6":"6","\u06f7":"7","\u06f8":"8","\u06f9":"9","\u0660":"0","\u0661":"1","\u0662":"2","\u0663":"3","\u0664":"4","\u0665":"5","\u0666":"6","\u0667":"7","\u0668":"8","\u0669":"9"}',true);
        return strtoupper(trim(strtr((string)$value,$digits)));
    }

    /** Urban couriers identify a ride/order separately from native tracking rows. */
    public function providerCurrent($shop,$order,$sourceKey,$carrier)
    {
        if (strpos($sourceKey,'alopeyk:')===0) {
            $row=$this->one('SELECT p.alopeyk_order_id AS provider_id,p.status,d.status AS delivery_status,d.id_carrier,d.id_shop FROM `'._DB_PREFIX_.'ssb_delivery_manager_alopeyk` p INNER JOIN `'._DB_PREFIX_.'ssb_delivery_manager_delivery` d ON d.id_delivery=p.id_delivery AND d.id_order=p.id_order WHERE p.id_order='.(int)$order.' ORDER BY p.id_alopeyk DESC');
            $matches=$row && $sourceKey==='alopeyk:'.(int)$row['provider_id'];
        } elseif (strpos($sourceKey,'snapp:')===0) {
            $row=$this->one('SELECT p.snapp_ride_id AS provider_id,p.status,d.status AS delivery_status,d.id_carrier,d.id_shop FROM `'._DB_PREFIX_.'ssb_delivery_manager_snapp` p INNER JOIN `'._DB_PREFIX_.'ssb_delivery_manager_delivery` d ON d.id_delivery=p.id_delivery AND d.id_order=p.id_order WHERE p.id_order='.(int)$order.' ORDER BY p.id_snapp DESC');
            $matches=$row && is_string($row['provider_id']) && $row['provider_id']!=='' && $sourceKey==='snapp:'.$row['provider_id'];
        } else { return false; }
        return $matches && (int)$row['id_shop']===(int)$shop && (int)$row['id_carrier']===(int)$carrier
            && !in_array($row['delivery_status'],array('cancelled','manual_closed','auto_closed'),true)
            && !preg_match('/^(?:cancel|failed|error|nobody_accepted|could_not_calculate_price)/',strtolower((string)$row['status']));
    }

    /** Match only booking-owned codes; manually tracked orders need no booking row. */
    public function nativeBookingCurrent($shop,$order,array $tracking)
    {
        $tipax=$this->one('SELECT status,tracking_codes FROM `'._DB_PREFIX_.'ssbdm_tipax_booking` WHERE id_shop='.(int)$shop.' AND id_order='.(int)$order);
        $mahex=$this->one('SELECT status,packages,waybill_number FROM `'._DB_PREFIX_.'ssbdm_mahex_booking` WHERE id_shop='.(int)$shop.' AND id_order='.(int)$order);
        foreach (array($tipax,$mahex) as $row) {
            if (!$row) { continue; }
            if (isset($row['tracking_codes'])) { $codes=json_decode((string)$row['tracking_codes'],true); }
            else {
                // Mahex attach/cancel owns parcel IDs, independently of its waybill number.
                $packages=json_decode((string)($row['packages'] ?? ''),true);
                if (!is_array($packages)) { return false; }
                $codes=array_column($packages,'id');
            }
            if (!is_array($codes)) { return false; }
            $codes=array_values(array_unique(array_filter(array_map(array(__CLASS__,'trackingCode'),$codes),static function($code) { return $code!==''; })));
            sort($codes,SORT_STRING);
            if (array_intersect($codes,$tracking) && ($row['status']!=='confirmed' || $codes!==$tracking)) { return false; }
        }
        return true;
    }

    public function ensure($shop, $order)
    {
        $this->write('INSERT IGNORE INTO '._DB_PREFIX_."ssb_hesabfa_freight_margin (id_shop,id_order,date_upd) VALUES (".(int)$shop.','.(int)$order.',NOW())');
        return $this->state($shop, $order);
    }

    public function save($shop, $order, array $values)
    {
        $values['date_upd'] = date('Y-m-d H:i:s');
        foreach ($values as $key => $value) {
            if ($value !== null && !is_int($value)) { $values[$key] = pSQL((string)$value, true); }
        }
        if (!$this->db->update('ssb_hesabfa_freight_margin', $values, 'id_shop='.(int)$shop.' AND id_order='.(int)$order, 0, true)) {
            throw new RuntimeException('Unable to persist shipment accounting state.');
        }
    }

    public function audit($shop, $order, $event, array $details)
    {
        if (!$this->db->insert('ssb_hesabfa_freight_margin_audit', array(
            'id_shop'=>(int)$shop, 'id_order'=>(int)$order, 'event'=>pSQL($event),
            'details'=>pSQL(json_encode($details, JSON_UNESCAPED_UNICODE), true), 'date_add'=>date('Y-m-d H:i:s'),
        ))) { throw new RuntimeException('Unable to persist shipment accounting audit.'); }
    }

    public function candidates($shop, $policy, $limit)
    {
        $rows = $this->db->executeS('SELECT s.id_shop,s.id_order FROM '._DB_PREFIX_.'ssbdm_shipping_cost_accounting s LEFT JOIN '._DB_PREFIX_.'ssb_hesabfa_freight_margin m ON m.id_shop=s.id_shop AND m.id_order=s.id_order WHERE s.id_shop='.(int)$shop.
            " AND (m.id_order IS NULL OR m.source_revision<>s.revision_hash OR m.policy_hash<>'".pSQL($policy)."' OR m.pending_json IS NOT NULL)"
            ." AND NOT EXISTS (SELECT 1 FROM "._DB_PREFIX_."ssb_hesabfa_job j WHERE j.job_type='sync_shipment_margin' AND j.object_type='Shipment' AND j.object_id=CONCAT(s.id_shop,':',s.id_order) AND j.status<>'done' AND LOCATE(CONCAT('\"source_revision\":\"',s.revision_hash,'\"'),j.payload)>0 AND LOCATE('\"policy_hash\":\"".pSQL($policy)."\"',j.payload)>0)"
            .' ORDER BY s.date_upd ASC,s.id_order ASC LIMIT '.(int)$limit, true, false);
        if (!is_array($rows)) { throw new RuntimeException('Unable to read shipment accounting outbox.'); }
        return $rows;
    }

    public function jobs($shop, $order)
    {
        $rows = $this->db->executeS('SELECT * FROM '._DB_PREFIX_."ssb_hesabfa_job WHERE job_type='sync_shipment_margin' AND object_type='Shipment' AND object_id='".(int)$shop.':'.(int)$order."' AND status<>'done' ORDER BY id_ssb_hesabfa_job ASC", true, false);
        if (!is_array($rows)) { throw new RuntimeException('Unable to read shipment accounting jobs.'); }
        return $rows;
    }

    private function one($sql)
    {
        $rows = $this->db->executeS($sql.' LIMIT 1', true, false);
        if (!is_array($rows)) { throw new RuntimeException('Unable to read shipment accounting state.'); }
        return $rows ? $rows[0] : null;
    }

    private function write($sql)
    {
        if (!$this->db->execute($sql)) { throw new RuntimeException('Unable to persist shipment accounting state.'); }
    }
}
