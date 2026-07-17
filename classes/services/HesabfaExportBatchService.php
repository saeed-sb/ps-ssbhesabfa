<?php
if (!defined('_PS_VERSION_')) { exit; }
class HesabfaExportBatchService
{
    protected $module;
    public function __construct($module) { $this->module=$module; }
    protected function key($type) { return $type==='products'?'SSBHESABFA_EXPORT_PRODUCTS_LAST_ID':'SSBHESABFA_EXPORT_CUSTOMERS_LAST_ID'; }
    protected function stateKey($type) { return $type==='products'?'SSBHESABFA_EXPORT_PRODUCTS_STATE':'SSBHESABFA_EXPORT_CUSTOMERS_STATE'; }
    protected function getState($type)
    {
        $state=json_decode((string)Configuration::get($this->stateKey($type)),true);
        if (is_array($state)) return $state;
        $last=(int)Configuration::get($this->key($type));
        $total=$type==='products'?HesabfaPrestashopRepository::countProducts():HesabfaPrestashopRepository::countCustomers();
        $remaining=$type==='products'?HesabfaPrestashopRepository::countProductIdsAfter($last):HesabfaPrestashopRepository::countCustomerIdsAfter($last);
        return array('status'=>$last>0?'failed':'idle','last_id'=>$last,'processed'=>max(0,$total-$remaining),'total'=>$total,'failed_ids'=>array());
    }
    protected function saveState($type,array $state)
    {
        $state['updated_at']=date('Y-m-d H:i:s');
        Configuration::updateValue($this->stateKey($type),json_encode($state));
        Configuration::updateValue($this->key($type),(int)$state['last_id']);
    }
    public function runAjax($type,$reset=false)
    {
        if (!in_array($type,array('products','customers'),true)) return array('success'=>false,'fatal'=>true,'done'=>true,'message'=>$this->module->l('Invalid export type.'));
        if (!Configuration::get('SSBHESABFA_LIVE_MODE')) return array('success'=>false,'fatal'=>true,'done'=>true,'message'=>$this->module->l('The API Connection must be connected before export.'));
        $state=$this->getState($type);
        if ($reset || $state['status']==='completed') $state=array('status'=>'running','last_id'=>0,'processed'=>0,'total'=>0,'failed_ids'=>array());
        $state['status']='running';
        $result=$type==='products'?$this->processProducts($state):$this->processCustomers($state);
        $this->saveState($type,$result['state']);
        unset($result['state']);
        return $result;
    }
    public function processProducts(array $state)
    {
        $last=(int)$state['last_id']; $total=HesabfaPrestashopRepository::countProducts();
        $rows=HesabfaPrestashopRepository::getProductIdsAfter($last,Ssbhesabfa::HESABFA_BATCH_SIZE);
        if (!$rows) return $this->completed('products',$state,$total);
        $items=array(); $rowErrors=array(); $batchLast=$last;
        foreach ($rows as $row) {
            $id=(int)$row['id_product']; $batchLast=max($batchLast,$id);
            try {
                $product=new Product($id);
                if (!Validate::isLoadedObject($product)) throw new Exception('Product is not loaded.');
                if (!$this->module->getObjectId('product',$id,0)) {
                    $items[]=array('Name'=>mb_substr($product->name[$this->module->id_default_lang],0,99),'ItemType'=>$product->is_virtual?1:0,'SellPrice'=>$this->module->getPriceInHesabfaDefaultCurrency($product->price),'Tag'=>json_encode(array('id_product'=>$id,'id_attribute'=>0)),'Active'=>(bool)$product->active,'NodeFamily'=>$this->module->getCategoryPathForExport($product->id_category_default),'ProductCode'=>$id);
                }
                if ($product->hasAttributes()>0) {
                    $combinations=$product->getAttributesResume($this->module->id_default_lang);
                    if (is_array($combinations)) foreach ($combinations as $comb) {
                        $attr=(int)$comb['id_product_attribute'];
                        if (!$this->module->getObjectId('product',$id,$attr)) $items[]=array('Name'=>mb_substr($product->name[$this->module->id_default_lang].' - '.$comb['attribute_designation'],0,99),'ItemType'=>$product->is_virtual?1:0,'SellPrice'=>$this->module->getPriceInHesabfaDefaultCurrency($product->price+$comb['price']),'Tag'=>json_encode(array('id_product'=>$id,'id_attribute'=>$attr)),'Active'=>(bool)$product->active,'NodeFamily'=>$this->module->getCategoryPathForExport($product->id_category_default),'ProductCode'=>$id);
                    }
                }
            } catch (Exception $e) { $rowErrors[]=$id; Ssbhesabfa::addLegacyLog('Product export skipped product '.$id.'. '.$e->getMessage(),2,'PRODUCT_EXPORT_ROW_FAILED','Product',$id,true); }
        }
        if ($items) {
            $response=(new HesabfaApi())->itemBatchSave($items);
            if (!$response->Success) {
                $state['status']='failed';
                return $this->response(false,false,$state,$total,$last,'error','Product batch failed and will be retried from the same position. '.$response->ErrorMessage,true);
            }
            foreach ($response->Result as $item) { $tag=json_decode($item->Tag); if (is_object($tag)) HesabfaMappingRepository::upsert('product',(int)$tag->id_product,(int)$item->Code,(int)$tag->id_attribute); }
        }
        $state['last_id']=$batchLast; $state['processed']=min($total,(int)$state['processed']+count($rows)); $state['failed_ids']=array_values(array_unique(array_merge((array)$state['failed_ids'],$rowErrors)));
        $remaining=HesabfaPrestashopRepository::countProductIdsAfter($batchLast);
        if ($remaining<=0) return $this->completed('products',$state,$total);
        return $this->response(true,false,$state,$total,$batchLast,$rowErrors?'warning':'success',$rowErrors?'Product batch completed with skipped rows.':'Product batch exported.',false);
    }
    public function processCustomers(array $state)
    {
        $last=(int)$state['last_id']; $total=HesabfaPrestashopRepository::countCustomers();
        $rows=HesabfaPrestashopRepository::getCustomerIdsAfter($last,Ssbhesabfa::HESABFA_BATCH_SIZE);
        if (!$rows) return $this->completed('customers',$state,$total);
        $data=array(); $rowErrors=array(); $batchLast=$last;
        foreach ($rows as $row) {
            $id=(int)$row['id_customer']; $batchLast=max($batchLast,$id);
            try {
                if (!$this->module->getObjectId('customer',$id)) {
                    $customer=new Customer($id); if (!Validate::isLoadedObject($customer)) throw new Exception('Customer is not loaded.');
                    $name=trim($customer->firstname.' '.$customer->lastname); if ($name==='') $name='Guest Customer';
                    $data[]=array('Name'=>$name,'FirstName'=>$customer->firstname,'LastName'=>$customer->lastname,'ContactType'=>1,'NodeFamily'=>Configuration::get('SSBHESABFA_CONTACT_ROOT_NODE').' '.Configuration::get('SSBHESABFA_CONTACT_NODE_FAMILY'),'Email'=>$this->module->validEmail($customer->email)?$customer->email:null,'Tag'=>json_encode(array('id_customer'=>$id)),'Active'=>(bool)$customer->active,'Note'=>'Customer ID in OnlineStore: '.$id);
                }
            } catch (Exception $e) { $rowErrors[]=$id; Ssbhesabfa::addLegacyLog('Customer export skipped customer '.$id.'. '.$e->getMessage(),2,'CUSTOMER_EXPORT_ROW_FAILED','Customer',$id,true); }
        }
        if ($data) {
            $response=(new HesabfaApi())->contactBatchSave($data);
            if (!$response->Success) {
                $state['status']='failed';
                return $this->response(false,false,$state,$total,$last,'error','Customer batch failed and will be retried from the same position. '.$response->ErrorMessage,true);
            }
            foreach ($response->Result as $item) { $tag=json_decode($item->Tag); if (is_object($tag)) HesabfaMappingRepository::upsert('customer',(int)$tag->id_customer,(int)$item->Code,0); }
        }
        $state['last_id']=$batchLast; $state['processed']=min($total,(int)$state['processed']+count($rows)); $state['failed_ids']=array_values(array_unique(array_merge((array)$state['failed_ids'],$rowErrors)));
        $remaining=HesabfaPrestashopRepository::countCustomerIdsAfter($batchLast);
        if ($remaining<=0) return $this->completed('customers',$state,$total);
        return $this->response(true,false,$state,$total,$batchLast,$rowErrors?'warning':'success',$rowErrors?'Customer batch completed with skipped rows.':'Customer batch exported.',false);
    }
    protected function completed($type,array $state,$total)
    {
        $state['status']='completed'; $state['processed']=$total; $state['total']=$total;
        return $this->response(true,true,$state,$total,(int)$state['last_id'],'success',$type==='products'?'Product export is complete.':'Customer export is complete.',false);
    }
    protected function response($success,$done,array $state,$total,$last,$status,$message,$paused)
    {
        $state['total']=$total; $processed=(int)$state['processed']; $percent=$total>0?min(100,(int)floor(($processed/$total)*100)):100;
        return array('success'=>(bool)$success,'fatal'=>false,'done'=>(bool)$done,'paused'=>(bool)$paused,'continue'=>!$done&&!$paused,'status'=>$status,'processed'=>$processed,'remaining'=>max(0,$total-$processed),'total'=>(int)$total,'percent'=>$percent,'last_id'=>(int)$last,'message'=>$message,'failed_count'=>count((array)$state['failed_ids']),'state'=>$state);
    }
}
