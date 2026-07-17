<?php
if (!defined('_PS_VERSION_')) { exit; }
class HesabfaWebhookService
{
    protected $handler;
    public function __construct($handler) { $this->handler=$handler; }
    public function run()
    {
        $api=new HesabfaApi(); $last=(int)Configuration::get('SSBHESABFA_LAST_LOG_CHECK_ID'); $changes=$api->settingGetChanges($last+1);
        if (!$changes->Success) { Ssbhesabfa::addLegacyLog('Failed to check latest Hesabfa changes. '.$changes->ErrorMessage,2,$changes->ErrorCode,'Webhook',null,true); return false; }
        if (isset($changes->Result)) foreach ((array)$changes->Result as $change) HesabfaWebhookChangeRepository::save($change);
        foreach (HesabfaWebhookChangeRepository::getPending(200) as $row) {
            $id=(int)$row['change_id']; if (!HesabfaWebhookChangeRepository::markRunning($id)) continue;
            try {
                $change=json_decode($row['payload']); if (!is_object($change)) throw new Exception('Invalid webhook payload.');
                $this->processChange($change); HesabfaWebhookChangeRepository::markDone($id); Configuration::updateValue('SSBHESABFA_LAST_LOG_CHECK_ID',$id);
            } catch (Exception $e) { HesabfaWebhookChangeRepository::markFailed($id,$e->getMessage()); Ssbhesabfa::addLegacyLog('Webhook change '.$id.' failed and checkpoint was not advanced. '.$e->getMessage(),3,'WEBHOOK_CHANGE_FAILED','Webhook',$id,true); break; }
        }
        return true;
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
                        continue;
                    }

                    if (!$this->hasStoreTag($itemResponse->Result, 'id_product')) {
                        continue;
                    }

                    if (!$this->handler->setItemChanges($itemResponse->Result)) {
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

                if (!$this->handler->setItemChanges($item)) {
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
