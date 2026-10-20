<?php
if (!defined('_PS_VERSION_')) { exit; }

/** Reassign invoice freight first, then replace its owned expense/income adjustment. */
class HesabfaShipmentAccountingService
{
    private $module;
    private $repo;
    private $api;
    private $margin;

    public function __construct($module,$repo=null,$api=null,$margin=null)
    {
        $this->module=$module;
        $this->repo=$repo ?: new HesabfaFreightMarginRepository();
        $this->api=$api ?: new HesabfaApi();
        $this->margin=$margin ?: new HesabfaFreightMarginService($module,$this->repo,$this->api);
    }

    /** Queue storage only; safe inside the committed carrier transition transaction. */
    public function enqueue($shop,$order)
    {
        if (!$this->module->isHesabfaSyncEnabled()) { return false; }
        $row=$this->repo->order($shop,$order);
        if (!$row) { throw new InvalidArgumentException('Shipment order/shop does not match.'); }
        $state=$this->repo->state($shop,$order);
        foreach ($this->repo->carrierJobs($shop,$order) as $existing) {
            $old=json_decode($existing['payload'],true);
            if (empty($state['invoice_pending_json']) && empty($state['pending_json'])
                && (int)($old['id_carrier'] ?? 0)!==(int)$row['id_carrier']
                && in_array($existing['status'],array('needs_attention','dead'),true)) {
                HesabfaJobRepository::markFinished((int)$existing['id_ssb_hesabfa_job']);
                continue;
            }
            return (int)$existing['id_ssb_hesabfa_job'];
        }
        $job=HesabfaJobRepository::enqueue('sync_shipment_carrier',array('id_shop'=>(int)$shop,'id_order'=>(int)$order,'id_carrier'=>(int)$row['id_carrier']),'ShipmentCarrier',(int)$shop.':'.(int)$order);
        if (!$job) { throw new RuntimeException('Unable to queue the invoice carrier synchronization.'); }
        return $job;
    }

    public function enqueuePending($shop)
    {
        foreach ($this->repo->invoicePendingOrders($shop) as $row) { $this->enqueue($shop,(int)$row['id_order']); }
    }

    public function process($shop,$orderId,$carrierOnly=false)
    {
        if (!$this->module->isHesabfaSyncEnabled() || !Configuration::get('SSBHESABFA_LIVE_MODE')) { return $this->fail('HESABFA_SYNC_DISABLED','Automatic accounting or its live connection is disabled.'); }
        $financial='financial-order:'.(int)$orderId;
        $freight='freight-margin:'.(int)$shop.':'.(int)$orderId;
        if (!HesabfaLock::acquire($financial)) { return $this->fail('FINANCIAL_OPERATION_BUSY','The order accounting is already being processed.'); }
        $courier=false; $freightHeld=false;
        try {
            $courier=$this->repo->acquireCourierLock($orderId);
            if (!$courier) { return $this->fail('FINANCIAL_OPERATION_BUSY','A courier operation is running.'); }
            $order=$this->repo->order($shop,$orderId);
            if (!$order || !$this->repo->ready()) { throw new InvalidArgumentException('Shipment order/shop or accounting storage does not match.'); }
            $state=$this->repo->ensure($shop,$orderId);
            // An older uncertain journal request must be reconciled before moving its invoice.
            if (!empty($state['pending_json'])) {
                if (!$this->margin->process($shop,$orderId)) { return false; }
                $state=$this->repo->state($shop,$orderId);
            }
            $source=$this->repo->source($shop,$orderId);
            $evidence=$source ? json_decode($source['payload_json'],true) : null;
            $currentSource=is_array($evidence) && (int)($evidence['id_carrier'] ?? 0)===(int)$order['id_carrier'];
            if (!$currentSource && (!$carrierOnly || !empty($state['verified_document']))) {
                throw new InvalidArgumentException('Refresh the replacement carrier shipping cost before updating its accounting.');
            }
            $settings=HesabfaFreightMarginSettings::getFormValues($shop);
            $marginEnabled=!empty($settings[HesabfaFreightMarginSettings::ENABLED]);
            if (!$marginEnabled && !empty($state['verified_document'])) { throw new InvalidArgumentException('Enable shipment accounting before replacing an existing freight adjustment.'); }
            if ($currentSource && $marginEnabled) {
                try { list($source,$evidence)=$this->margin->verifiedSource($shop,$orderId); }
                catch (InvalidArgumentException $error) {
                    if (!$carrierOnly || !empty($state['verified_document'])) { throw $error; }
                    $currentSource=false;
                }
            }
            $carrier=new Carrier((int)$order['id_carrier']);
            if (!Validate::isLoadedObject($carrier)) { throw new InvalidArgumentException('The replacement carrier is unavailable.'); }
            $person=HesabfaFreightPerson::getCode((int)$carrier->id_reference,$shop);
            $number=$this->module->getInvoiceCodeByOrderId($orderId);
            if (!$number) { return $this->fail('INVOICE_MAPPING_NOT_FOUND','The carrier synchronization is waiting for the sales invoice.'); }
            $year=$this->read($this->api->settingGetFiscalYear());
            if (empty($year['StartDate']) || empty($year['EndDate'])) { throw new InvalidArgumentException('Unable to identify the accounting fiscal year.'); }
            $account=hash('sha256',(string)$this->api->apiKey.'|'.$year['StartDate'].'|'.$year['EndDate']);
            if ($state['account_fingerprint']!=='' && !hash_equals($state['account_fingerprint'],$account)) { throw new InvalidArgumentException('The accounting business or fiscal year changed.'); }
            if (!HesabfaLock::acquire($freight)) { return $this->fail('FINANCIAL_OPERATION_BUSY','Shipment accounting is already being processed.'); }
            $freightHeld=true;
            if (!empty($state['verified_document'])) {
                $remote=$this->read($this->api->documentGet((int)$state['document_number']));
                if ((int)($remote['Id'] ?? 0)!==(int)$state['document_id'] || (int)($remote['Number'] ?? 0)!==(int)$state['document_number'] || !HesabfaFreightMarginService::sameDocument(json_decode($state['verified_document'],true),$remote)) {
                    throw new InvalidArgumentException('The owned shipment adjustment was edited or replaced. Review it before changing its invoice.');
                }
            }
            $invoice=$this->read($this->api->invoiceGet($number,0));
            // Validate ownership and mapping even when no invoice write is necessary.
            if ((int)($invoice['Number'] ?? 0)!==(int)$number) { throw new InvalidArgumentException('The mapped sales invoice identity changed.'); }
            $invoicePlan=HesabfaInvoiceCarrierPlan::build($order,$invoice,$person);
            if ($currentSource && $marginEnabled) {
                $settings['person_code']=$person;
                $date=!empty($state['verified_document']) ? substr(json_decode($state['verified_document'],true)['Date'],0,10) : substr((string)($evidence['captured_at'] ?? ''),0,10);
                if ($date<substr($year['StartDate'],0,10) || $date>substr($year['EndDate'],0,10)) { throw new InvalidArgumentException('Shipping cost date is outside the current fiscal year.'); }
                HesabfaFreightMarginPlan::build($order,$evidence,$invoicePlan['after'],$settings,$date,(int)$state['document_number']);
            }
            if (substr($invoice['Date'] ?? '',0,10)<substr($year['StartDate'],0,10) || substr($invoice['Date'] ?? '',0,10)>substr($year['EndDate'],0,10)) { throw new InvalidArgumentException('Invoice date is outside the current fiscal year.'); }
            $pending=!empty($state['invoice_pending_json']) ? json_decode($state['invoice_pending_json'],true) : null;
            if (!empty($state['invoice_pending_json']) && (!is_array($pending) || !isset($pending['number'],$pending['id_carrier'],$pending['person_code'],$pending['account_fingerprint'],$pending['request_unique_id'])
                || !is_array($pending['before'] ?? null) || !is_array($pending['after'] ?? null) || !is_array($pending['payload'] ?? null))) {
                throw new InvalidArgumentException('Invalid pending invoice carrier request.');
            }
            if ($pending) {
                if (($pending['account_fingerprint'] ?? '')!==$account || (int)($pending['number'] ?? 0)!==(int)$number) { throw new InvalidArgumentException('Pending invoice accounting identity changed.'); }
                $invoice=$this->executeInvoice($shop,$orderId,$order,$invoice,$pending);
            }
            // Recover an accepted older carrier write, then use a fresh UUID for the latest carrier.
            if (ltrim((string)($invoice['FreightPersonCode'] ?? ''),'0')!==ltrim((string)$person,'0')) {
                $plan=HesabfaInvoiceCarrierPlan::build($order,$invoice,$person);
                $pending=array_merge($plan,array('number'=>(int)$number,'id_carrier'=>(int)$order['id_carrier'],'person_code'=>(string)$person,'account_fingerprint'=>$account,'request_unique_id'=>HesabfaFreightMarginService::uuid(),'sent_at'=>null));
                $this->savePending($shop,$orderId,$pending,$account);
                $this->repo->audit($shop,$orderId,'invoice_carrier_prepared',$pending);
                $invoice=$this->executeInvoice($shop,$orderId,$order,$invoice,$pending);
            }
            HesabfaLock::release($freight); $freightHeld=false;
            if ($currentSource && $marginEnabled) {
                if (!$this->margin->process($shop,$orderId)) { return false; }
            }
            $this->repo->save($shop,$orderId,array('account_fingerprint'=>$account,'invoice_pending_json'=>null));
            return true;
        } catch (InvalidArgumentException $error) {
            return $this->fail('FREIGHT_MARGIN_REVIEW_REQUIRED',$error->getMessage());
        } finally {
            if ($freightHeld) { HesabfaLock::release($freight); }
            if ($courier) { $this->repo->releaseCourierLock($orderId); }
            HesabfaLock::release($financial);
        }
    }

    private function executeInvoice($shop,$orderId,array $order,array $invoice,array $pending)
    {
        if (HesabfaInvoiceCarrierPlan::same($pending['after'],$invoice)) { return $invoice; }
        if (!HesabfaInvoiceCarrierPlan::same($pending['before'],$invoice)) { throw new InvalidArgumentException('The sales invoice changed while its carrier reassignment was pending.'); }
        $carrier=new Carrier((int)$order['id_carrier']);
        $person=HesabfaFreightPerson::getCode((int)$carrier->id_reference,$shop);
        if ((int)$pending['id_carrier']!==(int)$order['id_carrier'] || ltrim($pending['person_code'],'0')!==ltrim($person,'0')) { throw new InvalidArgumentException('The pending invoice carrier request is stale and will not be resent.'); }
        if (!empty($pending['sent_at']) && time()-strtotime($pending['sent_at'])>=86400) { throw new InvalidArgumentException('An expired invoice carrier request requires reconciliation.'); }
        $pending['sent_at']=$pending['sent_at'] ?: date('c');
        $this->savePending($shop,$orderId,$pending,$pending['account_fingerprint']);
        $response=HesabfaApiResponse::normalize($this->api->apiRequest('invoice/save',array('invoice'=>$pending['payload'],'requestUniqueId'=>$pending['request_unique_id'])));
        if (!$response->Success) {
            $pending['last_error']=array('code'=>$response->ErrorCode,'message'=>$response->ErrorMessage,'at'=>date('c'));
            $this->savePending($shop,$orderId,$pending,$pending['account_fingerprint']);
        }
        $result=$this->read($response);
        if ((int)($result['Number'] ?? 0)!==(int)$pending['number']) { throw new InvalidArgumentException('Invoice carrier update returned a different invoice.'); }
        $verified=$this->read($this->api->invoiceGet($pending['number'],0));
        if (!HesabfaInvoiceCarrierPlan::same($pending['after'],$verified)) { throw new InvalidArgumentException('Invoice carrier update failed financial and identity read-back verification.'); }
        $this->repo->audit($shop,$orderId,'invoice_carrier_verified',array('request'=>$pending,'invoice'=>$verified));
        return $verified;
    }

    private function savePending($shop,$order,array $pending,$account)
    {
        $this->repo->save($shop,$order,array('account_fingerprint'=>$account,'invoice_pending_json'=>json_encode($pending,JSON_UNESCAPED_UNICODE)));
    }
    private function read($response)
    {
        $response=HesabfaApiResponse::normalize($response);
        if (!$response->Success) { throw new RuntimeException((string)$response->ErrorMessage); }
        $result=json_decode(json_encode($response->Result),true);
        if (!is_array($result)) { throw new RuntimeException('Hesabfa returned no usable invoice result.'); }
        return $result;
    }
    private function fail($code,$message)
    {
        HesabfaApiResponse::normalize((object)array('Success'=>false,'ErrorCode'=>$code,'ErrorMessage'=>$message));
        return false;
    }
}
