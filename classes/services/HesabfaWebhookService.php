<?php
if (!defined('_PS_VERSION_')) { exit; }

if (!class_exists('HesabfaWebhookRetryableException', false)) {
    class HesabfaWebhookRetryableException extends Exception
    {
    }
}

class HesabfaWebhookService
{
    protected $handler;

    public function __construct($handler)
    {
        $this->handler = $handler;
    }

    protected function createResult($checkpoint)
    {
        return array(
            'success' => false,
            'api_success' => false,
            'received_count' => 0,
            'stored_count' => 0,
            'processed_count' => 0,
            'failed_count' => 0,
            'deferred_count' => 0,
            'superseded_count' => 0,
            'failed_change_id' => null,
            'last_error' => '',
            'last_checkpoint' => (int) $checkpoint,
            'pending_count' => 0,
            'running_count' => 0,
            'failed_total' => 0,
            'remaining_count' => 0,
        );
    }

    protected function finalizeResult($result)
    {
        $result['last_checkpoint'] = (int) Configuration::get('SSBHESABFA_LAST_LOG_CHECK_ID');
        $result['pending_count'] = HesabfaWebhookChangeRepository::countByStatuses('pending');
        $result['running_count'] = HesabfaWebhookChangeRepository::countByStatuses('running');
        $result['failed_total'] = HesabfaWebhookChangeRepository::countByStatuses('failed');
        $result['remaining_count'] = (int) $result['pending_count']
            + (int) $result['running_count']
            + (int) $result['failed_total'];
        $result['success'] = !empty($result['api_success'])
            && (int) $result['failed_count'] === 0
            && (int) $result['remaining_count'] === 0;

        return $result;
    }

    protected function failResult($result, $message, $changeId = null)
    {
        $result['failed_count'] = (int) $result['failed_count'] + 1;
        $result['failed_change_id'] = $changeId === null ? null : (int) $changeId;
        $result['last_error'] = trim((string) $message);

        return $result;
    }

    public function run()
    {
        $last = (int) Configuration::get('SSBHESABFA_LAST_LOG_CHECK_ID');
        $result = $this->createResult($last);

        try {
            $changes = (new HesabfaApi())->settingGetChanges($last + 1);
        } catch (Exception $e) {
            $result['last_error'] = $e->getMessage();
            Ssbhesabfa::addLegacyLog(
                'Failed to check latest Hesabfa changes. ' . $result['last_error'],
                2,
                'WEBHOOK_GET_CHANGES_FAILED',
                'Webhook',
                null,
                true
            );

            return $this->finalizeResult($result);
        }

        if (!is_object($changes) || empty($changes->Success)) {
            $errorMessage = is_object($changes) && isset($changes->ErrorMessage)
                ? trim((string) $changes->ErrorMessage)
                : 'Hesabfa returned an invalid GetChanges response.';
            $errorCode = is_object($changes) && isset($changes->ErrorCode)
                ? (string) $changes->ErrorCode
                : 'WEBHOOK_GET_CHANGES_FAILED';
            $result['last_error'] = $errorMessage;
            Ssbhesabfa::addLegacyLog(
                'Failed to check latest Hesabfa changes. ' . $errorMessage,
                2,
                $errorCode,
                'Webhook',
                null,
                true
            );

            return $this->finalizeResult($result);
        }

        $result['api_success'] = true;
        $receivedChanges = array();
        if (isset($changes->Result)) {
            if (is_array($changes->Result)) {
                $receivedChanges = $changes->Result;
            } elseif (is_object($changes->Result)) {
                $receivedChanges = array($changes->Result);
            }
        }
        $result['received_count'] = count($receivedChanges);

        foreach ($receivedChanges as $change) {
            if (HesabfaWebhookChangeRepository::save($change)) {
                $result['stored_count']++;
                continue;
            }

            $changeId = is_object($change) && isset($change->Id) ? (int) $change->Id : null;
            $message = $changeId
                ? 'Hesabfa change ' . $changeId . ' could not be stored in the webhook journal.'
                : 'A Hesabfa change could not be stored in the webhook journal.';
            $result = $this->failResult($result, $message, $changeId);
            Ssbhesabfa::addLegacyLog(
                $message . ' Checkpoint was not advanced.',
                3,
                'WEBHOOK_CHANGE_SAVE_FAILED',
                'Webhook',
                $changeId,
                true
            );

            // Do not process later changes when the ordered journal has a gap.
            return $this->finalizeResult($result);
        }

        return $this->finalizeResult($this->processPendingChanges($result, 80));
    }

    public function processPendingOnly($limit = 200)
    {
        $result = $this->createResult((int) Configuration::get('SSBHESABFA_LAST_LOG_CHECK_ID'));
        $result['api_success'] = true;

        return $this->finalizeResult($this->processPendingChanges($result, $limit));
    }

    protected function processPendingChanges($result, $limit)
    {
        if (!HesabfaWebhookChangeRepository::acquireProcessingLock()) {
            return $result;
        }

        try {
            $workCount = 0;
            $scanLimit = min(500, max((int) $limit, (int) $limit * 5));
            $pendingRows = HesabfaWebhookChangeRepository::getPending($scanLimit);
            $supersededChangeIds = HesabfaWebhookChangeRepository::getSupersededProductChangeIds(
                array_column($pendingRows, 'change_id')
            );

            foreach ($pendingRows as $row) {
                $id = (int) $row['change_id'];
                $change = json_decode($row['payload']);
                $isValidChange = is_object($change);
                $isSuperseded = $isValidChange
                    && $this->isSupersededProductChange($change, $id, $supersededChangeIds);

                if (!$isSuperseded && $workCount >= (int) $limit) {
                    break;
                }
                if (!HesabfaWebhookChangeRepository::markRunning($id)) {
                    continue;
                }

                try {
                    if (!$isValidChange) {
                        throw new Exception('Invalid webhook payload.');
                    }

                    if ($isSuperseded) {
                        if (!HesabfaWebhookChangeRepository::markDone($id)) {
                            throw new Exception('Superseded webhook change could not be marked as completed.');
                        }
                        if (!Configuration::updateValue('SSBHESABFA_LAST_LOG_CHECK_ID', $id)) {
                            throw new Exception('Webhook checkpoint could not be saved.');
                        }
                        $result['processed_count']++;
                        $result['superseded_count']++;
                        continue;
                    }

                    $workCount++;
                    if (!$this->processChange($change)) {
                        throw new Exception('Webhook change could not be applied.');
                    }
                    if (!HesabfaWebhookChangeRepository::markDone($id)) {
                        throw new Exception('Webhook change could not be marked as completed.');
                    }
                    if (!Configuration::updateValue('SSBHESABFA_LAST_LOG_CHECK_ID', $id)) {
                        throw new Exception('Webhook checkpoint could not be saved.');
                    }
                    $result['processed_count']++;
                } catch (HesabfaWebhookRetryableException $e) {
                    $attempts = (int) $row['attempts'] + 1;
                    $result['last_error'] = $e->getMessage();

                    if ($attempts < 5) {
                        HesabfaWebhookChangeRepository::markPending($id, $e->getMessage());
                        $result['deferred_count']++;
                        break;
                    }

                    HesabfaWebhookChangeRepository::markFailed($id, $e->getMessage());
                    $result = $this->failResult($result, $e->getMessage(), $id);

                    if ($attempts === 5 || $attempts % 60 === 0) {
                        Ssbhesabfa::addLegacyLog(
                            'Webhook change ' . $id . ' still fails after ' . $attempts . ' attempts and checkpoint was not advanced. ' . $e->getMessage(),
                            3,
                            'WEBHOOK_CHANGE_FAILED',
                            'Webhook',
                            $id,
                            true
                        );
                    }
                    break;
                } catch (Exception $e) {
                    HesabfaWebhookChangeRepository::markFailed($id, $e->getMessage());
                    $result = $this->failResult($result, $e->getMessage(), $id);
                    Ssbhesabfa::addLegacyLog(
                        'Webhook change ' . $id . ' failed and checkpoint was not advanced. ' . $e->getMessage(),
                        3,
                        'WEBHOOK_CHANGE_FAILED',
                        'Webhook',
                        $id,
                        true
                    );
                    break;
                }
            }
        } finally {
            HesabfaWebhookChangeRepository::releaseProcessingLock();
        }

        return $result;
    }

    protected function isSupersededProductChange($change, $changeId, array $supersededChangeIds)
    {
        return empty($change->API)
            && isset($change->ObjectType)
            && (string) $change->ObjectType === 'Product'
            && isset($change->Action)
            && (int) $change->Action === 52
            && isset($change->ObjectId)
            && isset($supersededChangeIds[(int) $changeId]);
    }

    protected function hasStoreTag($object, $idField)
    {
        if (!is_object($object) || !isset($object->Tag) || trim((string) $object->Tag) === '') {
            return false;
        }

        $tag = json_decode((string) $object->Tag);

        return is_object($tag)
            && isset($tag->$idField)
            && (int) $tag->$idField > 0;
    }

    protected function getApiErrorMessage($response, $fallback)
    {
        $message = is_object($response) && isset($response->ErrorMessage)
            ? trim((string) $response->ErrorMessage)
            : '';
        $code = is_object($response) && isset($response->ErrorCode)
            ? trim((string) $response->ErrorCode)
            : '';

        if ($message === '') {
            $message = (string) $fallback;
        }
        if ($code !== '' && $code !== '0') {
            $message .= ' Hesabfa error code: ' . $code . '.';
        }

        return $message;
    }

    protected function throwApiResponseException($response, $fallback)
    {
        $message = $this->getApiErrorMessage($response, $fallback);
        $errorCode = is_object($response) && isset($response->ErrorCode)
            ? (string) $response->ErrorCode
            : '';
        $httpCode = is_object($response) && isset($response->HttpCode)
            ? (int) $response->HttpCode
            : null;

        if (HesabfaRetryPolicy::shouldRetryUntilSuccess($errorCode, $message, $httpCode)) {
            throw new HesabfaWebhookRetryableException($message);
        }

        throw new Exception($message);
    }

    protected function processChange($change)
    {
        if (!empty($change->API)) return true;
        $api=new HesabfaApi(); $type=(string)$change->ObjectType; $action=(int)$change->Action;
        if ($type==='Product' && $action===53) { $id=Ssbhesabfa::getObjectIdByCode('product',$change->Extra); if ($id) { $m=new HesabfaModel($id); if (Validate::isLoadedObject($m)) $m->delete(); } return true; }
        if ($type==='Contact' && $action===33) { $id=Ssbhesabfa::getObjectIdByCode('customer',$change->Extra); if ($id) { $m=new HesabfaModel($id); if (Validate::isLoadedObject($m)) $m->delete(); } return true; }
        if ($type==='Invoice') {
            $r=$api->invoiceGetById(array($change->ObjectId)); if (!$r->Success) $this->throwApiResponseException($r, 'Hesabfa invoice could not be fetched.'); foreach ((array)$r->Result as $o) if (!$this->handler->setInvoiceChanges($o)) throw new Exception('Invoice change could not be applied.');
            if (!empty($change->Extra)) {
                $itemCodes = array_values(array_unique(array_filter(
                    array_map('trim', explode(',', (string) $change->Extra)),
                    'strlen'
                )));
                $queryInfo = array(
                    'Filters' => array(array(
                        'Property' => 'Code',
                        'Operator' => 'in',
                        'Value' => $itemCodes,
                    )),
                );
                $itemResponse = $api->itemGetItems($queryInfo);

                if (!is_object($itemResponse) || empty($itemResponse->Success)) {
                    $errorMessage = is_object($itemResponse) && isset($itemResponse->ErrorMessage)
                        ? trim((string) $itemResponse->ErrorMessage)
                        : '';
                    $errorCode = is_object($itemResponse) && isset($itemResponse->ErrorCode)
                        ? trim((string) $itemResponse->ErrorCode)
                        : '';
                    if ($errorMessage === '') {
                        $errorMessage = 'Hesabfa invoice items could not be fetched.';
                    }
                    if ($errorCode !== '' && $errorCode !== '0') {
                        $errorMessage .= ' Hesabfa error code: ' . $errorCode . '.';
                    }
                    $this->throwApiResponseException($itemResponse, 'Hesabfa invoice items could not be fetched.');
                }

                $items = isset($itemResponse->Result->List) && is_array($itemResponse->Result->List)
                    ? $itemResponse->Result->List
                    : array();
                $returnedCodes = array();

                foreach ($items as $item) {
                    if (isset($item->Code)) {
                        $returnedCodes[] = (string) $item->Code;
                    }
                    if (!$this->hasStoreTag($item, 'id_product')) {
                        continue;
                    }
                    if (!$this->handler->setItemChanges($item, false, true)) {
                        throw new Exception('Invoice item change could not be applied.');
                    }
                }

                $missingCodes = array_values(array_diff($itemCodes, $returnedCodes));
                if (!empty($missingCodes)) {
                    Ssbhesabfa::addLegacyLog(
                        'Invoice webhook referenced item codes that no longer exist in Hesabfa and were skipped: ' . implode(', ', $missingCodes),
                        2,
                        'WEBHOOK_INVOICE_ITEMS_MISSING',
                        'Webhook',
                        isset($change->Id) ? (int) $change->Id : null,
                        true
                    );
                }
            }
            if (Module::isInstalled('ssbprofitloyalty') && Module::isEnabled('ssbprofitloyalty') && in_array((int)$change->Action,array(121,122,123),true)) {
                require_once _PS_MODULE_DIR_ . 'ssbprofitloyalty/ssbprofitloyalty.php'; $loyalty=new Ssbprofitloyalty();
                if ((int)$change->Action===121) $loyalty->addInvoiceById($change->ObjectId);
                elseif ((int)$change->Action===122) $loyalty->updateInvoiceById($change->ObjectId);
                else $loyalty->deleteInvoiceById($change->ObjectId);
            }
            return true;
        }
        if ($type === 'Product') {
            $response = $api->itemGetById(array($change->ObjectId));
            if (!$response->Success) {
                $this->throwApiResponseException($response, 'Hesabfa object could not be fetched.');
            }

            foreach ((array) $response->Result as $item) {
                if (!$this->hasStoreTag($item, 'id_product')) {
                    continue;
                }

                if (!$this->handler->setItemChanges($item, false, true)) {
                    throw new Exception('Product change could not be applied.');
                }
            }

            return true;
        }
        if ($type === 'Contact') {
            $response = $api->contactGetById(array($change->ObjectId));
            if (!$response->Success) {
                $this->throwApiResponseException($response, 'Hesabfa object could not be fetched.');
            }

            foreach ((array) $response->Result as $contact) {
                if (!$this->hasStoreTag($contact, 'id_customer')) {
                    continue;
                }

                if (!$this->handler->setContactChanges($contact)) {
                    throw new Exception('Contact change could not be applied.');
                }
            }

            return true;
        }
        return true;
    }
}
