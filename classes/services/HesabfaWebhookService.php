<?php
if (!defined('_PS_VERSION_')) { exit; }
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

        foreach (HesabfaWebhookChangeRepository::getPending(200) as $row) {
            $id = (int) $row['change_id'];
            if (!HesabfaWebhookChangeRepository::markRunning($id)) {
                continue;
            }

            try {
                $change = json_decode($row['payload']);
                if (!is_object($change)) {
                    throw new Exception('Invalid webhook payload.');
                }
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

        return $this->finalizeResult($result);
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

    protected function processChange($change)
    {
        if (!empty($change->API)) return true;
        $api=new HesabfaApi(); $type=(string)$change->ObjectType; $action=(int)$change->Action;
        if ($type==='Product' && $action===53) { $id=Ssbhesabfa::getObjectIdByCode('product',$change->Extra); if ($id) { $m=new HesabfaModel($id); if (Validate::isLoadedObject($m)) $m->delete(); } return true; }
        if ($type==='Contact' && $action===33) { $id=Ssbhesabfa::getObjectIdByCode('customer',$change->Extra); if ($id) { $m=new HesabfaModel($id); if (Validate::isLoadedObject($m)) $m->delete(); } return true; }
        if ($type==='Invoice') {
            $r=$api->invoiceGetById(array($change->ObjectId)); if (!$r->Success) throw new Exception($r->ErrorMessage); foreach ((array)$r->Result as $o) if (!$this->handler->setInvoiceChanges($o)) throw new Exception('Invoice change could not be applied.');
            if (!empty($change->Extra)) {
                foreach (array_filter(explode(',', $change->Extra)) as $code) {
                    $itemResponse = $api->itemGet((int) $code);

                    if (!$itemResponse->Success) {
                        throw new Exception($itemResponse->ErrorMessage);
                    }

                    if (!$this->hasStoreTag($itemResponse->Result, 'id_product')) {
                        continue;
                    }

                    if (!$this->handler->setItemChanges($itemResponse->Result, false, true)) {
                        throw new Exception('Invoice item change could not be applied.');
                    }
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
                throw new Exception($response->ErrorMessage);
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
                throw new Exception($response->ErrorMessage);
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
