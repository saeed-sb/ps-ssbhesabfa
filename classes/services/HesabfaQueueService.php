<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class HesabfaQueueService
{
    protected $module;

    public function __construct($module)
    {
        $this->module = $module;
    }

    public function processPending($limit = 20)
    {
        $processed = 0;
        foreach (HesabfaJobRepository::getPending($limit) as $job) {
            if ($this->processRow($job)) {
                $processed++;
            }
        }
        return $processed;
    }

    public function processSingle($idJob)
    {
        $job = HesabfaJobRepository::getById($idJob);
        if (!$job) {
            return array('success' => false, 'message' => $this->module->l('Job was not found.'));
        }

        if (in_array((string) $job['status'], array('needs_attention', 'duplicate_check', 'dead', 'done', 'running'), true)) {
            return array('success' => false, 'message' => $this->module->l('This job is not eligible for direct execution.'));
        }

        $ok = $this->processRow($job);
        $updated = HesabfaJobRepository::getById($idJob);
        return array(
            'success' => (bool) $ok,
            'message' => $ok
                ? $this->module->l('Job executed successfully.')
                : $this->module->l('Job was classified and updated. Review its status and error code.'),
            'status' => $updated ? $updated['status'] : null,
        );
    }

    public function processRow(array $job)
    {
        $id = (int) $job['id_ssb_hesabfa_job'];
        $payload = json_decode($job['payload'], true);
        if (!is_array($payload)) {
            $payload = array();
        }

        $payloadChanged = HesabfaJobRepository::syncPayloadHash($id, $payload);
        if ($payloadChanged) {
            $job['request_unique_ids'] = null;
            $job['request_unique_ids_created_at'] = null;
        }

        if (!empty($job['request_unique_ids'])
            && HesabfaRetryPolicy::isRequestIdExpired(isset($job['request_unique_ids_created_at']) ? $job['request_unique_ids_created_at'] : null)) {
            HesabfaJobRepository::markDuplicateCheck(
                $id,
                'The persisted requestUniqueId is older than 24 hours. Reconciliation is required before a new operation is created.',
                'REQUEST_ID_EXPIRED'
            );
            return false;
        }

        if (!$this->module->isHesabfaSyncEnabled()) {
            HesabfaJobRepository::markOutcome($id, HesabfaRetryPolicy::STATUS_NEEDS_ATTENTION, 'Automatic Hesabfa synchronization is disabled.', 'HESABFA_SYNC_DISABLED');
            return false;
        }

        if (!$this->module->isHesabfaApiConfigured()) {
            HesabfaJobRepository::markOutcome($id, HesabfaRetryPolicy::STATUS_NEEDS_ATTENTION, 'Hesabfa API credentials are not configured.', 'HESABFA_NOT_CONFIGURED');
            return false;
        }

        if (!Configuration::get('SSBHESABFA_LIVE_MODE')) {
            HesabfaJobRepository::markWaitingForConnection($id);
            return false;
        }

        if (!HesabfaJobRepository::markRunning($id)) {
            return false;
        }

        $requestIds = array();
        if (!empty($job['request_unique_ids'])) {
            $decodedRequestIds = json_decode($job['request_unique_ids'], true);
            if (is_array($decodedRequestIds)) {
                $requestIds = $decodedRequestIds;
            }
        }

        HesabfaRequestUniqueId::beginContext($id, $requestIds, array('HesabfaJobRepository', 'saveRequestUniqueIds'));
        HesabfaApiResponse::resetLastResponse();

        try {
            $ok = $this->executeJob($job, $payload);
            if ($ok) {
                HesabfaJobRepository::markFinished($id);

                if ((string) $job['job_type'] === 'set_order_payment' && class_exists('HesabfaIssueRepository')) {
                    HesabfaIssueRepository::resolveByObject(
                        'invoice_mapping_not_found_for_payment',
                        'Order',
                        (string) $job['object_id'],
                        'The Hesabfa invoice mapping is available and the payment job completed successfully.'
                    );
                }

                return true;
            }

            $response = HesabfaApiResponse::getLastResponse();
            if ($response === null) {
                $response = HesabfaApiResponse::normalize((object) array(
                    'Success' => false,
                    'ErrorCode' => 'UNKNOWN_HESABFA_API_ERROR',
                    'ErrorMessage' => 'The job returned false without a usable Hesabfa response.',
                ));
            }
            return $this->handleFailure($job, $payload, $response);
        } catch (Exception $e) {
            $response = HesabfaApiResponse::getLastResponse();
            if ($response && HesabfaApiResponse::isSuccess($response)) {
                $code = 'LOCAL_POST_PROCESSING_EXCEPTION';
                $message = $e->getMessage();
                $status = HesabfaRetryPolicy::STATUS_RETRY_WAIT;
            } else {
                $code = $response ? HesabfaApiResponse::getErrorCode($response) : 'QUEUE_EXCEPTION';
                $message = $response ? HesabfaApiResponse::getErrorMessage($response) : $e->getMessage();
                $status = $response ? HesabfaRetryPolicy::classifyResponse($response) : HesabfaRetryPolicy::classify($code, $message);
            }
            HesabfaJobRepository::markOutcome($id, $status, $message, $code, $response);
            $this->logFailure($job, $payload, $status, $code, $message);
            return false;
        } finally {
            HesabfaRequestUniqueId::endContext();
        }
    }

    protected function executeJob(array $job, array $payload)
    {
        switch ($job['job_type']) {
            case 'set_order':
                return $this->module->setOrder(
                    (int) $payload['id_order'],
                    isset($payload['order_type']) ? (int) $payload['order_type'] : 0,
                    isset($payload['reference']) ? $payload['reference'] : null
                );
            case 'set_order_payment':
                return $this->module->setOrderPayment((int) $payload['id_order']);
            case 'sync_product':
                return $this->module->setItems(array((int) $payload['id_product']));
            case 'sync_customer':
                return $this->module->setContact((int) $payload['id_customer']);
            case 'sync_customer_address':
                return $this->module->setContactAddress((int) $payload['id_customer'], (int) $payload['id_address']);
            case 'delete_customer':
                $response = (new HesabfaApi())->contactDelete((int) $payload['hesabfa_code']);
                $ok = (bool) $response->Success;
                if ($ok && !empty($payload['mapping_id'])) {
                    $mapping = new HesabfaModel((int) $payload['mapping_id']);
                    if (Validate::isLoadedObject($mapping)) {
                        $mapping->delete();
                    }
                }
                return $ok;
            case 'delete_product_item':
                $response = (new HesabfaApi())->itemDelete((int) $payload['hesabfa_code']);
                $ok = (bool) $response->Success;
                if ($ok && !empty($payload['mapping_id'])) {
                    $mapping = new HesabfaModel((int) $payload['mapping_id']);
                    if (Validate::isLoadedObject($mapping)) {
                        $mapping->delete();
                    }
                }
                return $ok;
            default:
                throw new Exception('Unsupported job type: ' . $job['job_type']);
        }
    }

    protected function handleFailure(array $job, array $payload, $response)
    {
        $id = (int) $job['id_ssb_hesabfa_job'];
        if (HesabfaApiResponse::isSuccess($response)) {
            $code = 'LOCAL_POST_PROCESSING_FAILED';
            $message = 'Hesabfa accepted the request, but local post-processing returned false. The same request UUID will be reused.';
            $status = HesabfaRetryPolicy::STATUS_RETRY_WAIT;
        } else {
            $code = HesabfaApiResponse::getErrorCode($response);
            $message = HesabfaApiResponse::getErrorMessage($response);
            $status = HesabfaRetryPolicy::classifyResponse($response);
        }
        HesabfaJobRepository::markOutcome($id, $status, $message, $code, $response);
        $this->logFailure($job, $payload, $status, $code, $message);
        return false;
    }

    protected function logFailure(array $job, array $payload, $status, $code, $message)
    {
        $options = array('area' => 'Queue');
        if (!empty($payload['hesabfa_code'])) {
            $options['hesabfa_code'] = (string) $payload['hesabfa_code'];
        }
        Ssbhesabfa::addLegacyLog(
            'Hesabfa async job changed to ' . $status . '. ' . $message,
            $status === HesabfaRetryPolicy::STATUS_RETRY_WAIT ? 2 : 3,
            $code,
            $job['object_type'],
            $job['object_id'],
            true,
            $options
        );
    }
}
