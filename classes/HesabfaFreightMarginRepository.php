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
