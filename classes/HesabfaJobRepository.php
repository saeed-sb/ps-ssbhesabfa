<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class HesabfaJobRepository
{
    const DEFAULT_MAX_ATTEMPTS = 5;
    const STALE_RUNNING_MINUTES = 15;

    public static function enqueue($jobType, array $payload, $objectType = null, $objectId = null)
    {
        if (!$jobType) {
            return false;
        }

        if (in_array($jobType, array('sync_product', 'sync_customer', 'sync_customer_address'), true)) {
            $existing = self::findMergeable($jobType, $objectType, $objectId);
            if ($existing) {
                $old = json_decode($existing['payload'], true);
                if (!is_array($old)) {
                    $old = array();
                }
                $payload = array_merge($old, $payload, array('merged_at' => date('Y-m-d H:i:s')));
                return Db::getInstance()->update('ssb_hesabfa_job', array(
                    'payload' => pSQL(json_encode($payload), true),
                    'request_payload_hash' => pSQL(HesabfaRequestUniqueId::payloadHash($payload)),
                    'request_unique_ids' => null,
                    'request_unique_ids_created_at' => null,
                    'status' => 'pending',
                    'attempts' => 0,
                    'last_error' => null,
                    'last_error_code' => null,
                    'last_response' => null,
                    'next_run_at' => date('Y-m-d H:i:s'),
                    'locked_at' => null,
                    'finished_at' => null,
                    'date_upd' => date('Y-m-d H:i:s'),
                ), '`id_ssb_hesabfa_job`=' . (int) $existing['id_ssb_hesabfa_job'])
                    ? (int) $existing['id_ssb_hesabfa_job']
                    : false;
            }
        }

        return self::insertJob($jobType, $payload, $objectType, $objectId);
    }

    protected static function findMergeable($type, $objectType, $objectId)
    {
        $query = new DbQuery();
        $query->select('*');
        $query->from('ssb_hesabfa_job');
        $query->where('`job_type`="' . pSQL($type) . '"');
        $query->where('`object_type`="' . pSQL((string) $objectType) . '"');
        $query->where('`object_id`="' . pSQL((string) $objectId) . '"');
        $query->where('`status` IN ("pending","retry_wait")');
        $query->orderBy('`id_ssb_hesabfa_job` DESC');
        $row = Db::getInstance()->getRow($query);
        return is_array($row) ? $row : null;
    }

    protected static function insertJob($type, array $payload, $objectType, $objectId)
    {
        $now = date('Y-m-d H:i:s');
        $ok = Db::getInstance()->insert('ssb_hesabfa_job', array(
            'job_type' => pSQL($type),
            'status' => 'pending',
            'payload' => pSQL(json_encode($payload), true),
            'request_payload_hash' => pSQL(HesabfaRequestUniqueId::payloadHash($payload)),
            'request_unique_ids' => null,
            'request_unique_ids_created_at' => null,
            'object_type' => pSQL((string) $objectType),
            'object_id' => pSQL((string) $objectId),
            'attempts' => 0,
            'last_error' => null,
            'last_error_code' => null,
            'last_response' => null,
            'next_run_at' => $now,
            'locked_at' => null,
            'finished_at' => null,
            'date_add' => $now,
            'date_upd' => $now,
        ));
        return $ok ? (int) Db::getInstance()->Insert_ID() : false;
    }

    public static function getAlertStats()
    {
        $query = new DbQuery();
        $query->select("SUM(CASE WHEN `status` = 'retry_wait' THEN 1 ELSE 0 END) AS retry_wait");
        $query->select("SUM(CASE WHEN `status` = 'needs_attention' THEN 1 ELSE 0 END) AS needs_attention");
        $query->select("SUM(CASE WHEN `status` = 'duplicate_check' THEN 1 ELSE 0 END) AS duplicate_check");
        $query->select("SUM(CASE WHEN `status` = 'dead' THEN 1 ELSE 0 END) AS dead");
        $query->from('ssb_hesabfa_job');

        $row = Db::getInstance()->getRow($query);
        if (!is_array($row)) {
            $row = array();
        }

        return array(
            'retry_wait' => isset($row['retry_wait']) ? (int) $row['retry_wait'] : 0,
            'needs_attention' => isset($row['needs_attention']) ? (int) $row['needs_attention'] : 0,
            'duplicate_check' => isset($row['duplicate_check']) ? (int) $row['duplicate_check'] : 0,
            'dead' => isset($row['dead']) ? (int) $row['dead'] : 0,
        );
    }

    public static function getPending($limit = 20)
    {
        self::recoverStaleRunningJobs();
        $limit = max(1, min(100, (int) $limit));
        $query = new DbQuery();
        $query->select('*');
        $query->from('ssb_hesabfa_job');
        $query->where('`status` IN ("pending","retry_wait")');
        $query->where('`attempts`<' . self::getMaxAttempts());
        $query->where('`next_run_at` IS NULL OR `next_run_at`<=NOW()');
        $query->orderBy('`next_run_at` ASC,`id_ssb_hesabfa_job` ASC');
        $query->limit($limit);
        $rows = Db::getInstance()->executeS($query);
        return is_array($rows) ? $rows : array();
    }

    public static function saveRequestUniqueIds($id, array $requestIds)
    {
        $row = self::getById($id);
        $data = array(
            'request_unique_ids' => pSQL(json_encode($requestIds), true),
            'date_upd' => date('Y-m-d H:i:s'),
        );
        if (!$row || empty($row['request_unique_ids_created_at'])) {
            $data['request_unique_ids_created_at'] = date('Y-m-d H:i:s');
        }
        return Db::getInstance()->update('ssb_hesabfa_job', $data, '`id_ssb_hesabfa_job`=' . (int) $id);
    }

    public static function syncPayloadHash($id, array $payload)
    {
        $row = self::getById($id);
        if (!$row) {
            return false;
        }
        $hash = HesabfaRequestUniqueId::payloadHash($payload);
        if (!empty($row['request_payload_hash']) && hash_equals((string) $row['request_payload_hash'], $hash)) {
            return false;
        }
        Db::getInstance()->update('ssb_hesabfa_job', array(
            'request_payload_hash' => pSQL($hash),
            'request_unique_ids' => null,
            'request_unique_ids_created_at' => null,
            'date_upd' => date('Y-m-d H:i:s'),
        ), '`id_ssb_hesabfa_job`=' . (int) $id);
        return true;
    }

    public static function getMaxAttempts()
    {
        $value = (int) Configuration::get('SSBHESABFA_JOB_MAX_ATTEMPTS');
        return $value > 0 ? $value : self::DEFAULT_MAX_ATTEMPTS;
    }

    public static function getRetryDelay($attempts)
    {
        $schedule = array(120, 600, 1800, 7200, 21600);
        $index = max(0, min(count($schedule) - 1, (int) $attempts - 1));
        return $schedule[$index];
    }

    public static function recoverStaleRunningJobs()
    {
        return Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . 'ssb_hesabfa_job` SET `status`="retry_wait",`last_error`="Recovered stale running job.",`last_error_code`="STALE_RUNNING",`next_run_at`=DATE_ADD(NOW(),INTERVAL 10 MINUTE),`locked_at`=NULL,`date_upd`=NOW() WHERE `status`="running" AND ((`locked_at` IS NULL AND `date_upd`<DATE_SUB(NOW(),INTERVAL ' . self::STALE_RUNNING_MINUTES . ' MINUTE)) OR `locked_at`<DATE_SUB(NOW(),INTERVAL ' . self::STALE_RUNNING_MINUTES . ' MINUTE))'
        );
    }

    public static function getList($filters = array(), $limit = 50, $offset = 0)
    {
        if (!is_array($filters)) {
            $limit = (int) $filters;
            $filters = array();
            $offset = 0;
        }

        $limit = max(1, min(200, (int) $limit));
        $offset = max(0, (int) $offset);
        $query = new DbQuery();
        $query->select('*');
        $query->from('ssb_hesabfa_job');
        self::applyListFilters($query, $filters);
        $query->orderBy('`id_ssb_hesabfa_job` DESC');
        $query->limit($limit, $offset);
        $rows = Db::getInstance()->executeS($query);
        return is_array($rows) ? $rows : array();
    }

    public static function countList($filters = array())
    {
        $query = new DbQuery();
        $query->select('COUNT(*)');
        $query->from('ssb_hesabfa_job');
        self::applyListFilters($query, is_array($filters) ? $filters : array());
        return (int) Db::getInstance()->getValue($query);
    }

    protected static function applyListFilters(DbQuery $query, array $filters)
    {
        if (!empty($filters['id'])) {
            $query->where('`id_ssb_hesabfa_job`=' . (int) $filters['id']);
        }
        if (!empty($filters['status'])) {
            $query->where('`status`="' . pSQL((string) $filters['status']) . '"');
        }
        if (!empty($filters['job_type'])) {
            $query->where('`job_type` LIKE "%' . pSQL((string) $filters['job_type']) . '%"');
        }
        if (!empty($filters['object_type'])) {
            $query->where('`object_type` LIKE "%' . pSQL((string) $filters['object_type']) . '%"');
        }
        if (!empty($filters['object_id'])) {
            $query->where('`object_id` LIKE "%' . pSQL((string) $filters['object_id']) . '%"');
        }
        if (!empty($filters['error_code'])) {
            $query->where('`last_error_code` LIKE "%' . pSQL((string) $filters['error_code']) . '%"');
        }
        if (!empty($filters['date_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $filters['date_from'])) {
            $query->where('`date_add` >= "' . pSQL((string) $filters['date_from']) . ' 00:00:00"');
        }
        if (!empty($filters['date_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $filters['date_to'])) {
            $query->where('`date_add` <= "' . pSQL((string) $filters['date_to']) . ' 23:59:59"');
        }
        if (!empty($filters['keyword'])) {
            $keyword = pSQL((string) $filters['keyword']);
            $query->where('(`payload` LIKE "%' . $keyword . '%" OR `last_error` LIKE "%' . $keyword . '%" OR `request_unique_ids` LIKE "%' . $keyword . '%" OR `request_payload_hash` LIKE "%' . $keyword . '%")');
        }
    }

    public static function getById($id)
    {
        $query = new DbQuery();
        $query->select('*');
        $query->from('ssb_hesabfa_job');
        $query->where('`id_ssb_hesabfa_job`=' . (int) $id);
        $row = Db::getInstance()->getRow($query);
        return is_array($row) ? $row : null;
    }

    public static function markRunning($id)
    {
        return Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . 'ssb_hesabfa_job` SET `status`="running",`attempts`=`attempts`+1,`locked_at`=NOW(),`date_upd`=NOW() WHERE `id_ssb_hesabfa_job`=' . (int) $id . ' AND `status` IN ("pending","retry_wait") AND `attempts`<' . self::getMaxAttempts()
        );
    }

    public static function markDeferred($id, $message, $errorCode, $delay = 600)
    {
        return Db::getInstance()->update('ssb_hesabfa_job', array(
            'status' => 'retry_wait',
            'last_error' => pSQL((string) $message),
            'last_error_code' => pSQL((string) $errorCode),
            'next_run_at' => date('Y-m-d H:i:s', time() + max(60, (int) $delay)),
            'locked_at' => null,
            'finished_at' => null,
            'date_upd' => date('Y-m-d H:i:s'),
        ), '`id_ssb_hesabfa_job`=' . (int) $id);
    }

    public static function markWaitingForConnection($id, $message = 'Hesabfa API is not connected.', $delay = 600)
    {
        return self::markDeferred($id, $message, 'HESABFA_NOT_CONNECTED', $delay);
    }

    public static function markFinished($id)
    {
        return Db::getInstance()->update('ssb_hesabfa_job', array(
            'status' => 'done',
            'last_error' => null,
            'last_error_code' => null,
            'locked_at' => null,
            'next_run_at' => null,
            'finished_at' => date('Y-m-d H:i:s'),
            'date_upd' => date('Y-m-d H:i:s'),
        ), '`id_ssb_hesabfa_job`=' . (int) $id);
    }

    public static function markOutcome($id, $status, $error, $errorCode = null, $response = null)
    {
        $row = self::getById($id);
        $attempts = $row ? (int) $row['attempts'] : 1;
        $status = (string) $status;
        $nextRunAt = null;
        $finishedAt = null;
        $storedAttempts = $attempts;

        if ($status === HesabfaRetryPolicy::STATUS_RETRY_WAIT) {
            $httpCode = null;
            if (is_object($response) && isset($response->HttpCode)) {
                $httpCode = (int) $response->HttpCode;
            } elseif (is_array($response) && isset($response['http_code'])) {
                $httpCode = (int) $response['http_code'];
            }

            $retryUntilSuccess = HesabfaRetryPolicy::shouldRetryUntilSuccess($errorCode, $error, $httpCode);
            if ($attempts >= self::getMaxAttempts() && !$retryUntilSuccess) {
                $status = HesabfaRetryPolicy::STATUS_DEAD;
                $finishedAt = date('Y-m-d H:i:s');
            } else {
                $delay = HesabfaRetryPolicy::getRetryAfter($response, self::getRetryDelay($attempts));
                $nextRunAt = date('Y-m-d H:i:s', time() + $delay);
                if ($attempts >= self::getMaxAttempts()) {
                    $storedAttempts = max(0, self::getMaxAttempts() - 1);
                }
            }
        } else {
            $finishedAt = date('Y-m-d H:i:s');
        }

        return Db::getInstance()->update('ssb_hesabfa_job', array(
            'status' => pSQL($status),
            'attempts' => (int) $storedAttempts,
            'last_error' => pSQL((string) $error),
            'last_error_code' => pSQL((string) $errorCode),
            'last_response' => $response === null ? null : pSQL(json_encode($response), true),
            'next_run_at' => $nextRunAt,
            'locked_at' => null,
            'finished_at' => $finishedAt,
            'date_upd' => date('Y-m-d H:i:s'),
        ), '`id_ssb_hesabfa_job`=' . (int) $id);
    }

    public static function markDuplicateCheck($id, $error, $errorCode = 'DUPLICATE_CHECK_REQUIRED')
    {
        return self::markOutcome($id, HesabfaRetryPolicy::STATUS_DUPLICATE_CHECK, $error, $errorCode);
    }

    public static function markDeadManually($id)
    {
        $row = self::getById($id);
        if (!$row || !in_array((string) $row['status'], array('pending', 'retry_wait', 'needs_attention', 'duplicate_check'), true)) {
            return false;
        }

        return Db::getInstance()->update('ssb_hesabfa_job', array(
            'status' => HesabfaRetryPolicy::STATUS_DEAD,
            'last_error' => pSQL('Manually marked as dead by an administrator.'),
            'last_error_code' => 'MANUALLY_MARKED_DEAD',
            'next_run_at' => null,
            'locked_at' => null,
            'finished_at' => date('Y-m-d H:i:s'),
            'date_upd' => date('Y-m-d H:i:s'),
        ), '`id_ssb_hesabfa_job`=' . (int) $id . ' AND `status` IN ("pending","retry_wait","needs_attention","duplicate_check")');
    }

    public static function requeueAsNew($id)
    {
        $row = self::getById($id);
        if (!$row || !in_array((string) $row['status'], array('dead', 'needs_attention', 'duplicate_check'), true)) {
            return false;
        }
        $payload = json_decode($row['payload'], true);
        if (!is_array($payload)) {
            $payload = array();
        }
        return Db::getInstance()->update('ssb_hesabfa_job', array(
            'status' => 'pending',
            'attempts' => 0,
            'request_payload_hash' => pSQL(HesabfaRequestUniqueId::payloadHash($payload)),
            'request_unique_ids' => null,
            'request_unique_ids_created_at' => null,
            'last_error' => null,
            'last_error_code' => null,
            'last_response' => null,
            'next_run_at' => date('Y-m-d H:i:s'),
            'locked_at' => null,
            'finished_at' => null,
            'date_upd' => date('Y-m-d H:i:s'),
        ), '`id_ssb_hesabfa_job`=' . (int) $id);
    }

    public static function requeue($id, $resetAttempts = true)
    {
        if ($resetAttempts) {
            return self::requeueAsNew($id);
        }
        return Db::getInstance()->update('ssb_hesabfa_job', array(
            'status' => 'retry_wait',
            'next_run_at' => date('Y-m-d H:i:s'),
            'locked_at' => null,
            'finished_at' => null,
            'date_upd' => date('Y-m-d H:i:s'),
        ), '`id_ssb_hesabfa_job`=' . (int) $id);
    }

    public static function resetToPending($id)
    {
        return self::requeue($id, false);
    }
}
