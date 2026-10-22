<?php
if (!defined('_PS_VERSION_')) { exit; }

/** One owned adjustment per order; a durable pending request survives every retry. */
class HesabfaFreightMarginService
{
    private $module;
    private $repo;
    private $api;

    public function __construct($module, $repo = null, $api = null)
    {
        $this->module = $module;
        $this->repo = $repo ?: new HesabfaFreightMarginRepository();
        $this->api = $api ?: new HesabfaApi();
    }

    public static function policy($shop)
    {
        $values = array_merge(HesabfaFreightMarginSettings::getFormValues($shop), HesabfaFreightPerson::getFormValues($shop));
        $values['accounting_model'] = 'gross_v2';
        $values['delivery_agent_codes'] = Configuration::get('SSBDM_AGENT_HESABFA_CODES', null, null, $shop);
        ksort($values);
        return hash('sha256', json_encode($values));
    }

    /** Called inside the delivery cost transaction: local queue writes only. */
    public function enqueue($shop, $order, $force = false)
    {
        if (!$this->repo->ready() || !$this->module->isHesabfaSyncEnabled()) { return false; }
        $settings = HesabfaFreightMarginSettings::getFormValues($shop);
        if (empty($settings['SSBHESABFA_FREIGHT_MARGIN_ENABLED'])) { return false; }
        $source = $this->repo->source($shop, $order);
        if (!$source || !$this->repo->order($shop, $order)) { return false; }
        $policy = self::policy($shop);
        $state = $this->repo->state($shop, $order);
        foreach ($this->repo->jobs($shop, $order) as $job) {
            $old = json_decode($job['payload'], true);
            $changed = !is_array($old) || ($old['source_revision'] ?? '') !== $source['revision_hash'] || ($old['policy_hash'] ?? '') !== $policy;
            // A rejected preflight can be superseded after its source/config is fixed.
            // Never bypass an outstanding remote write or an ambiguous result.
            if (($changed || $force) && empty($state['pending_json']) && in_array($job['status'], array('needs_attention','dead'), true)) {
                HesabfaJobRepository::markFinished((int)$job['id_ssb_hesabfa_job']);
                continue;
            }
            return (int)$job['id_ssb_hesabfa_job'];
        }
        return HesabfaJobRepository::enqueue('sync_shipment_margin', array(
            'id_shop'=>(int)$shop, 'id_order'=>(int)$order,
            'source_revision'=>$source['revision_hash'], 'policy_hash'=>$policy,
        ), 'Shipment', (int)$shop.':'.(int)$order);
    }

    public function enqueuePending($shop, $limit = 100)
    {
        if (!$this->repo->ready() || !$this->module->isHesabfaSyncEnabled()) { return 0; }
        $settings = HesabfaFreightMarginSettings::getFormValues($shop);
        if (empty($settings['SSBHESABFA_FREIGHT_MARGIN_ENABLED'])) { return 0; }
        if (!class_exists('Ssb\\DeliveryManager\\ShippingCost\\AccountingSource') && Module::isEnabled('ssbdeliverymanager')) {
            Module::getInstanceByName('ssbdeliverymanager');
        }
        $count = 0;
        foreach ($this->repo->candidates($shop, self::policy($shop), max(1,min(500,(int)$limit))) as $row) {
            // Resolve edited carrier/agent settings in the delivery-owned outbox before queuing.
            if (class_exists('Ssb\\DeliveryManager\\ShippingCost\\AccountingSource')) {
                $order = $this->repo->order($row['id_shop'], $row['id_order']);
                $state = $this->repo->state($row['id_shop'], $row['id_order']);
                $outbox = $this->repo->source($row['id_shop'], $row['id_order']);
                $evidence = $outbox ? json_decode($outbox['payload_json'], true) : null;
                if ($order && ($evidence['accounting_model'] ?? '') === 'gross_v2' && empty($state['pending_json']) && empty($state['invoice_pending_json'])
                    && HesabfaFreightPerson::managedCarrier((int)$order['id_carrier'], (int)$row['id_shop'])) {
                    \Ssb\DeliveryManager\ShippingCost\AccountingSource::refreshIdentity(Db::getInstance(), (int)$row['id_shop'], (int)$row['id_order']);
                }
            }
            if ($this->enqueue($row['id_shop'], $row['id_order'])) { $count++; }
        }
        return $count;
    }

    public function process($shop, $orderId)
    {
        if (!$this->module->isHesabfaSyncEnabled() || !Configuration::get('SSBHESABFA_LIVE_MODE')) { return $this->fail('HESABFA_SYNC_DISABLED','Automatic accounting or the live connection is disabled.'); }
        $scope = 'freight-margin:'.(int)$shop.':'.(int)$orderId;
        if (!HesabfaLock::acquire($scope)) { return $this->fail('FINANCIAL_OPERATION_BUSY', 'Shipment accounting is already being processed.'); }
        try {
            if (!$this->repo->ready()) { return $this->fail('FREIGHT_MARGIN_SCHEMA', 'Shipment accounting storage is unavailable.'); }
            $settings = HesabfaFreightMarginSettings::getFormValues($shop);
            if (empty($settings['SSBHESABFA_FREIGHT_MARGIN_ENABLED'])) { return $this->fail('FREIGHT_MARGIN_DISABLED', 'Automatic shipment accounting is disabled.'); }
            $order = $this->repo->order($shop, $orderId);
            if (!$order) { throw new InvalidArgumentException('Shipment order/shop does not match.'); }
            $year = $this->read($this->api->settingGetFiscalYear());
            if (empty($year['StartDate']) || empty($year['EndDate'])) { throw new InvalidArgumentException('Unable to identify the accounting fiscal year.'); }
            $account = hash('sha256', (string)$this->api->apiKey.'|'.$year['StartDate'].'|'.$year['EndDate']);
            $state = $this->repo->ensure($shop, $orderId);
            if ($state['account_fingerprint'] !== '' && !hash_equals($state['account_fingerprint'], $account)) {
                throw new InvalidArgumentException('The accounting business or fiscal year changed. Reconcile the existing shipment document first.');
            }
            if (!empty($state['pending_json'])) {
                $pending = json_decode($state['pending_json'], true);
                if (!is_array($pending) || ($pending['account_fingerprint'] ?? '') !== $account) { throw new InvalidArgumentException('Invalid pending shipment accounting request.'); }
                if (!$this->executePending($shop, $orderId, $state, $pending)) { return false; }
                $state = $this->repo->state($shop, $orderId);
            }
            list($sourceRow,$source) = $this->verifiedSource($shop,$orderId);
            $invoiceNumber = $this->module->getInvoiceCodeByOrderId($orderId);
            if (!$invoiceNumber) { return $this->fail('INVOICE_MAPPING_NOT_FOUND', 'The shipping adjustment is waiting for the sales invoice.'); }
            $invoice = $this->read($this->api->invoiceGet($invoiceNumber, 0));
            $carrier = new Carrier((int)($source['id_carrier'] ?? 0));
            if (!Validate::isLoadedObject($carrier)) { throw new InvalidArgumentException('The shipping carrier is unavailable.'); }
            $settings['person_code'] = ($source['accounting_model'] ?? '') === 'gross_v2' ? HesabfaFreightPerson::normalizeCode($source['accounting_contact_code'] ?? '') : HesabfaFreightPerson::getCode((int)$carrier->id_reference, $shop);
            $date = !empty($state['verified_document']) ? substr(json_decode($state['verified_document'],true)['Date'],0,10) : substr((string)($source['captured_at'] ?? ''),0,10);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date) || $date<substr($year['StartDate'],0,10) || $date>substr($year['EndDate'],0,10)) {
                throw new InvalidArgumentException('Shipping cost date is outside the current fiscal year.');
            }
            $plan = HesabfaFreightMarginPlan::build($order,$source,$invoice,$settings,$date,(int)$state['document_number']);
            $policy = self::policy($shop);
            $previous = !empty($state['verified_document']) ? json_decode($state['verified_document'],true) : null;
            if ($previous) {
                $remote = $this->read($this->api->documentGet((int)$state['document_number']));
                if ((int)($remote['Id'] ?? 0)!==(int)$state['document_id'] || (int)($remote['Number'] ?? 0)!==(int)$state['document_number'] || !self::sameDocument($previous,$remote)) {
                    throw new InvalidArgumentException('The owned shipment document was edited, renumbered, or replaced. Review it before updating.');
                }
                if ($plan['document'] && self::samePlannedDocument($plan['document'],$remote)) {
                    $this->repo->save($shop,$orderId,array('source_revision'=>$sourceRow['revision_hash'],'policy_hash'=>$policy));
                    return true;
                }
            } elseif ((int)$state['document_id']>0 || $state['document_number']!=='') {
                throw new InvalidArgumentException('Shipment document ownership data is incomplete.');
            }
            if (!$plan['document'] && !$previous) {
                $this->repo->save($shop,$orderId,array('account_fingerprint'=>$account,'source_revision'=>$sourceRow['revision_hash'],'policy_hash'=>$policy,'margin_rial'=>0));
                return true;
            }
            $pending = array(
                'version'=>(int)$state['version']+1, 'action'=>$plan['document'] ? 'save' : 'delete',
                'request_unique_id'=>self::uuid(), 'created_at'=>date('c'), 'sent_at'=>null,
                'account_fingerprint'=>$account, 'source_revision'=>$sourceRow['revision_hash'], 'policy_hash'=>$policy,
                'source_identity'=>array_intersect_key($source,array_flip(array('id_shop','id_order','id_carrier','source','source_key','tracking','tracking_source_key'))),
                'document'=>$plan['document'], 'previous'=>$previous, 'previous_id'=>(int)$state['document_id'],
                'number'=>(int)$state['document_number'], 'plan'=>$plan,
            );
            $this->repo->save($shop,$orderId,array('account_fingerprint'=>$account,'pending_json'=>json_encode($pending,JSON_UNESCAPED_UNICODE)));
            $this->repo->audit($shop,$orderId,'prepared',$pending);
            return $this->executePending($shop,$orderId,$state,$pending);
        } catch (InvalidArgumentException $e) {
            return $this->fail('FREIGHT_MARGIN_REVIEW_REQUIRED',$e->getMessage());
        } finally { HesabfaLock::release($scope); }
    }

    /** Read-only preflight shared by invoice reassignment and margin updates. */
    public function verifiedSource($shop,$orderId)
    {
        $sourceRow = $this->repo->source($shop, $orderId);
        if (!$sourceRow) { throw new InvalidArgumentException('No verified shipping cost has been saved.'); }
        $source = json_decode($sourceRow['payload_json'], true);
        if (!is_array($source)) { throw new InvalidArgumentException('Invalid shipping cost evidence.'); }
        $costRow = $this->repo->shippingCost($shop,$orderId);
        if (!$costRow || (int)$costRow['id_carrier'] !== (int)($source['id_carrier'] ?? 0)
            || (string)$costRow['payment_method'] !== (string)($source['payment_method'] ?? '')
            || self::decimal($costRow['cost']) !== self::decimal($source['cost_total_toman'] ?? '')
            || self::decimal($costRow['insurance']) !== self::decimal($source['insurance_toman'] ?? '')) {
            throw new InvalidArgumentException('The saved shipping cost changed without verified accounting evidence.');
        }
        $this->assertCurrentSource($shop,$orderId,$source);
        if (($source['accounting_model'] ?? '') === 'gross_v2') {
            if (!class_exists('Ssb\\DeliveryManager\\ShippingCost\\AccountingParty') && Module::isEnabled('ssbdeliverymanager')) {
                Module::getInstanceByName('ssbdeliverymanager');
            }
            if (!class_exists('Ssb\\DeliveryManager\\ShippingCost\\AccountingParty')) {
                throw new InvalidArgumentException('Delivery accounting identity validation is unavailable.');
            }
            $party = \Ssb\DeliveryManager\ShippingCost\AccountingParty::resolve(Db::getInstance(), (int)$shop, (int)$orderId, (int)$source['id_carrier'], (string)($source['source_key'] ?? ''));
            if (($source['accounting_party'] ?? null) !== $party) { throw new InvalidArgumentException('The delivery accounting contact changed; refresh the shipping source.'); }
            if (array_key_exists('expense_account_path', $source)) {
                $settings = HesabfaFreightMarginSettings::getFormValues($shop);
                if (HesabfaFreightMarginSettings::normalize(HesabfaFreightMarginSettings::EXPENSE_ACCOUNT, $source['expense_account_path']) !== $settings[HesabfaFreightMarginSettings::EXPENSE_ACCOUNT]) {
                    throw new InvalidArgumentException('The shipping expense account changed; refresh the shipping source.');
                }
            }
        }
        return array($sourceRow,$source);
    }

    private function executePending($shop,$order,array $state,array $pending)
    {
        if ($pending['action']==='delete') { return $this->deletePending($shop,$order,$pending); }
        $document = $pending['document'];
        $pinnedNumber = !empty($pending['result_number']) ? $pending['result_number'] : $pending['number'];
        $existing = $this->findDocument($pinnedNumber ? 'Number' : 'Description', $pinnedNumber ?: $document['description']);
        if ($existing) {
            if (!empty($pending['result_id']) && (int)$existing['Id']!==(int)$pending['result_id']) { throw new InvalidArgumentException('The accepted shipment document identity changed.'); }
            if ($pending['number'] && (int)$existing['Id']!==(int)$pending['previous_id']) { throw new InvalidArgumentException('Shipment document identity conflict.'); }
            if (self::samePlannedDocument($document,$existing)) { return $this->complete($shop,$order,$pending,$existing); }
            if (!$pending['previous'] || !self::sameDocument($pending['previous'],$existing)) { throw new InvalidArgumentException('Shipment document contents conflict with the saved request.'); }
        } elseif ($pinnedNumber) {
            throw new InvalidArgumentException('The owned shipment document is missing; automatic recreation was stopped.');
        }
        if (!empty($pending['sent_at']) && time()-strtotime($pending['sent_at'])>=86400) {
            throw new InvalidArgumentException('A shipment accounting request is over 24 hours old and its result could not be verified.');
        }
        $this->assertPendingSource($shop,$order,$pending);
        $pending['sent_at'] = $pending['sent_at'] ?: date('c');
        $this->repo->save($shop,$order,array('pending_json'=>json_encode($pending,JSON_UNESCAPED_UNICODE)));
        $result = $this->read($this->api->apiRequest('document/save',array('document'=>$document,'requestUniqueId'=>$pending['request_unique_id'])));
        if (empty($result['Number']) || empty($result['Id'])) { throw new RuntimeException('Hesabfa did not return the shipment document identity.'); }
        $pending['result_number'] = $result['Number']; $pending['result_id']=$result['Id'];
        $this->repo->save($shop,$order,array('pending_json'=>json_encode($pending,JSON_UNESCAPED_UNICODE)));
        $verified = $this->read($this->api->documentGet($result['Number']));
        if ((int)$verified['Id']!==(int)$result['Id'] || ($pending['number'] && ((int)$verified['Id']!==(int)$pending['previous_id'] || (int)$verified['Number']!==(int)$pending['number'])) || !self::samePlannedDocument($document,$verified)) {
            throw new InvalidArgumentException('Saved shipment document did not pass read-back verification.');
        }
        return $this->complete($shop,$order,$pending,$verified);
    }

    private function deletePending($shop,$order,array $pending)
    {
        $existing = $this->findDocument('Number',$pending['number']);
        if (!$existing) {
            if (empty($pending['sent_at'])) { throw new InvalidArgumentException('Shipment document disappeared before cancellation.'); }
            if ($this->findDocument('Id',$pending['previous_id'])) { throw new InvalidArgumentException('The shipment document was renumbered; its cancellation requires review.'); }
            return $this->complete($shop,$order,$pending,null);
        }
        if ((int)$existing['Id']!==(int)$pending['previous_id'] || !self::sameDocument($pending['previous'],$existing)) {
            throw new InvalidArgumentException('Only an unchanged owned shipment document can be cancelled.');
        }
        if (!empty($pending['sent_at']) && time()-strtotime($pending['sent_at'])>=86400) { throw new InvalidArgumentException('Shipment cancellation requires reconciliation after an expired request.'); }
        $this->assertPendingSource($shop,$order,$pending);
        $pending['sent_at']=$pending['sent_at'] ?: date('c');
        $this->repo->save($shop,$order,array('pending_json'=>json_encode($pending,JSON_UNESCAPED_UNICODE)));
        $this->read($this->api->apiRequest('document/delete',array('number'=>$pending['number'],'requestUniqueId'=>$pending['request_unique_id'])),false);
        if ($this->findDocument('Number',$pending['number']) || $this->findDocument('Id',$pending['previous_id'])) { throw new InvalidArgumentException('Shipment cancellation could not be verified.'); }
        return $this->complete($shop,$order,$pending,null);
    }

    private function complete($shop,$order,array $pending,$document)
    {
        $this->repo->audit($shop,$order,$document ? 'verified' : 'cancelled',array('request'=>$pending,'document'=>$document));
        $this->repo->save($shop,$order,array(
            'account_fingerprint'=>$pending['account_fingerprint'],'source_revision'=>$pending['source_revision'],'policy_hash'=>$pending['policy_hash'],
            'document_number'=>$document ? (string)$document['Number'] : '', 'document_id'=>$document ? (int)$document['Id'] : 0,
            'verified_document'=>$document ? json_encode($document,JSON_UNESCAPED_UNICODE) : null,
            'margin_rial'=>(int)$pending['plan']['margin_rial'],'version'=>(int)$pending['version'],'pending_json'=>null,
        ));
        if (class_exists('HesabfaIssueRepository')) { HesabfaIssueRepository::resolveByObject('freight_margin_sync_failed','Shipment',(int)$shop.':'.(int)$order,'Shipment adjustment verified.'); }
        return true;
    }

    /** A queued old waybill must not become a new accounting write after replacement. */
    private function assertCurrentSource($shop,$order,array $source)
    {
        if ((isset($source['id_shop']) && (int)$source['id_shop']!==(int)$shop)
            || (isset($source['id_order']) && (int)$source['id_order']!==(int)$order)) {
            throw new InvalidArgumentException('Shipping cost evidence does not belong to this order.');
        }
        $hasKey=array_key_exists('tracking_source_key',$source);
        $providerIds=preg_match('/^(?:alopeyk|snapp):/',(string)($source['source_key'] ?? ''))===1;
        $legacyProvider=($source['source'] ?? '')==='provider_reconciliation' && !$providerIds;
        if (!$hasKey && !$legacyProvider && !$providerIds) { return; }
        $current=$this->repo->trackingSource($shop,$order);
        if ((isset($source['id_carrier']) && (int)$source['id_carrier']!==(int)$current['id_carrier'])
            || ($hasKey && (!is_string($source['tracking_source_key']) || !preg_match('/^[a-f0-9]{64}$/D',$source['tracking_source_key'])
                || !hash_equals($current['source_key'],$source['tracking_source_key'])))) {
            throw new InvalidArgumentException('The shipment tracking changed; verified shipping costs must be refreshed before accounting.');
        }
        if ($providerIds && !$this->repo->providerCurrent($shop,$order,(string)$source['source_key'],$current['id_carrier'])) {
            throw new InvalidArgumentException('The courier request changed; verified shipping costs must be refreshed before accounting.');
        }
        if ($legacyProvider) {
            if (!is_array($source['tracking'] ?? null)) { throw new InvalidArgumentException('Invalid shipping cost evidence.'); }
            foreach ($source['tracking'] as $code) { if ((!is_string($code) && !is_int($code)) || trim((string)$code)==='') { throw new InvalidArgumentException('Invalid shipping cost evidence.'); } }
            $codes=array_map(array('HesabfaFreightMarginRepository','trackingCode'),$source['tracking']);
            $codes=array_values(array_unique($codes)); sort($codes,SORT_STRING);
            if (!$codes || in_array('',$codes,true) || $codes!==$current['tracking']) {
                throw new InvalidArgumentException('The shipment tracking changed; verified shipping costs must be refreshed before accounting.');
            }
            if (!$this->repo->nativeBookingCurrent($shop,$order,$current['tracking'])) {
                throw new InvalidArgumentException('The carrier booking is not confirmed; refresh verified shipping costs before accounting.');
            }
        }
    }

    /** Accepted remote writes may be recovered above; stale pending writes are never resent. */
    private function assertPendingSource($shop,$order,array $pending)
    {
        $row=$this->repo->source($shop,$order);
        if (!$row || ($pending['source_revision'] ?? '')!==$row['revision_hash']) {
            throw new InvalidArgumentException('The pending shipment accounting source changed; reconcile the existing request before retrying.');
        }
        $current = json_decode($row['payload_json'], true);
        if (is_array($current) && ($current['accounting_model'] ?? '') === 'gross_v2') {
            $this->verifiedSource($shop, $order);
            if (($pending['action'] ?? '') === 'save') {
                $settings = HesabfaFreightMarginSettings::getFormValues($shop);
                if (($pending['document']['transactions'][0]['accountPath'] ?? '') !== $settings[HesabfaFreightMarginSettings::EXPENSE_ACCOUNT]) {
                    throw new InvalidArgumentException('The pending shipping expense account changed; reconcile the existing request before retrying.');
                }
            }
        }
        if (is_array($pending['source_identity'] ?? null)) {
            $this->assertCurrentSource($shop,$order,$pending['source_identity']);
            return;
        }
        $source=json_decode($row['payload_json'],true);
        if (!is_array($source)) { throw new InvalidArgumentException('Invalid shipping cost evidence.'); }
        $this->assertCurrentSource($shop,$order,$source);
    }

    private function findDocument($property,$value)
    {
        $result=$this->read($this->api->documentGetDocuments(array('take'=>2,'skip'=>0,'filters'=>array(array('property'=>$property,'operator'=>'=','value'=>$value)))));
        if (!isset($result['FilteredCount'],$result['List']) || !is_array($result['List'])) { throw new RuntimeException('Cannot verify shipment accounting document lookup.'); }
        if ((int)$result['FilteredCount']===0 && count($result['List'])===0) { return null; }
        if ((int)$result['FilteredCount']!==1 || count($result['List'])!==1) { throw new InvalidArgumentException('Multiple shipment accounting documents matched; review is required.'); }
        $document=$this->read($this->api->documentGet($result['List'][0]['Number']));
        if ((string)$document[$property] !== (string)$value) { throw new InvalidArgumentException('Shipment accounting lookup returned a different document.'); }
        return $document;
    }

    private function read($response,$requireResult=true)
    {
        $response=HesabfaApiResponse::normalize($response);
        if (!$response->Success) { throw new RuntimeException((string)$response->ErrorMessage); }
        $result=isset($response->Result) ? json_decode(json_encode($response->Result),true) : null;
        if ($requireResult && !is_array($result)) { throw new RuntimeException('Hesabfa returned no usable shipment accounting result.'); }
        return $result;
    }

    private function fail($code,$message)
    {
        HesabfaApiResponse::normalize((object)array('Success'=>false,'ErrorCode'=>$code,'ErrorMessage'=>$message));
        return false;
    }

    public static function uuid()
    {
        $b=random_bytes(16); $b[6]=chr((ord($b[6])&15)|64); $b[8]=chr((ord($b[8])&63)|128);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($b),4));
    }

    private static function decimal($value)
    {
        if ((!is_string($value) && !is_int($value)) || !preg_match('/^([0-9]+)(?:\.([0-9]+))?$/D',(string)$value,$m)) { throw new InvalidArgumentException('Invalid saved shipment amount.'); }
        return (ltrim($m[1],'0') ?: '0').'.'.rtrim($m[2] ?? '','0');
    }

    public static function sameDocument(array $expected,array $actual)
    {
        return self::canonical($expected)===self::canonical($actual);
    }

    /** Hesabfa may assign Reference=Number when saving a lowercase API request. */
    public static function samePlannedDocument(array $planned, array $actual)
    {
        // This exception is solely for request read-back. Persisted PascalCase
        // snapshots always use sameDocument, including their exact Reference.
        if (!array_key_exists('reference', $planned) || array_key_exists('Reference', $planned)
            || !array_key_exists('transactions', $planned) || array_key_exists('Transactions', $planned)) {
            return false;
        }
        if (isset($planned['number']) && (!isset($actual['Number'])
            || (string)$planned['number'] !== (string)$actual['Number'])) { return false; }
        $expected = self::canonical($planned);
        $observed = self::canonical($actual);
        if ($expected === $observed) { return true; }
        if (!isset($actual['Number'], $actual['Reference'])
            || (!is_int($actual['Number']) && !is_string($actual['Number']))
            || !ctype_digit((string)$actual['Number']) || ltrim((string)$actual['Number'], '0') === ''
            || (!is_int($actual['Reference']) && !is_string($actual['Reference']))
            || (string)$actual['Reference'] !== (string)$actual['Number']) { return false; }
        $expected['Reference'] = $observed['Reference'];
        return $expected === $observed;
    }

    public static function canonical(array $document)
    {
        $get=function($row,$key,$default='') { return array_key_exists($key,$row) ? $row[$key] : ($row[lcfirst($key)] ?? $default); };
        $money=function($v) { return rtrim(rtrim(number_format((float)$v,6,'.',''),'0'),'.'); };
        $code=function($v) { return ltrim((string)$v,'0'); };
        $result=array('Reference'=>(string)$get($document,'Reference'),'Date'=>substr((string)$get($document,'Date'),0,10),'Description'=>(string)$get($document,'Description'),'Project'=>(string)$get($document,'Project'),'Debit'=>$money($get($document,'Debit',0)),'Credit'=>$money($get($document,'Credit',0)),'Status'=>(int)$get($document,'Status'));
        $result['Transactions']=array();
        foreach ($get($document,'Transactions',array()) as $row) {
            $r=array('AccountPath'=>preg_replace('/\s*:\s*/u',':',trim((string)$get($row,'AccountPath'))),'Description'=>(string)$get($row,'Description'),'Info'=>(string)$get($row,'Info'),'Amount'=>$money($get($row,'Amount',0)),'CurrencyAmount'=>$money($get($row,'CurrencyAmount',0)),'Currency'=>(string)$get($row,'Currency'),'Type'=>(int)$get($row,'Type'));
            foreach(array('ContactCode','ProductCode','BankCode','CashCode','PettyCashCode') as $key){$r[$key]=$code($get($row,$key));}
            $result['Transactions'][]=$r;
        }
        usort($result['Transactions'],function($a,$b){return strcmp(json_encode($a),json_encode($b));});
        return $result;
    }
}
