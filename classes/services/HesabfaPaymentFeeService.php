<?php
if (!defined('_PS_VERSION_')) { exit; }
class HesabfaPaymentFeeService
{
    public static function getTransactionFee($configName, $amount)
    {
        $amount=(float)$amount;
        if (!$configName || $amount<=0) return 0;
        switch ((string) Configuration::get($configName.'_FEE_TYPE')) {
            case 'shaparak_purchase': return self::getShaparakFee($amount);
            case 'percent': return self::getPercentFee($configName,$amount);
            case 'fixed': return self::getFixedFee($configName);
            default: return 0;
        }
    }
    public static function getShaparakFee($amount)
    {
        $amount=(float)$amount;
        if ($amount<=0) return 0;
        if ($amount<6000000) return 1200;
        if ($amount<=800000000) return $amount*0.0002;
        return 160000;
    }
    public static function getPercentFee($configName,$amount)
    {
        $percent=(float)Configuration::get($configName.'_FEE_PERCENT');
        return ($percent>0 && $amount>0) ? $amount*($percent/100) : 0;
    }
    public static function getFixedFee($configName)
    {
        $fee=(float)Configuration::get($configName.'_FEE_FIXED');
        return $fee>0?$fee:0;
    }
    public static function getBreakdown($configName,$paidAmount)
    {
        $paidAmount=round((float)$paidAmount);
        $result=array(
            'invoice_payment_amount'=>$paidAmount,
            'transaction_fee'=>0,
            'income_amount'=>0,
            'income_account_path'=>'',
            'income_contact_code'=>'',
            'fee_payer'=>'merchant',
            'fee_percent'=>0,
            'customer_charge_percent'=>0,
            'income_percent'=>0,
        );
        if (!$configName || $paidAmount<=0) return $result;
        $payer=(string)Configuration::get($configName.'_FEE_PAYER');
        if (!$payer) $payer='merchant';
        $result['fee_payer']=$payer;
        $result['fee_percent']=(float)Configuration::get($configName.'_FEE_PERCENT');
        $result['customer_charge_percent']=(float)Configuration::get($configName.'_CUSTOMER_CHARGE_PERCENT');
        if ($payer==='merchant') {
            $result['transaction_fee']=round(self::getTransactionFee($configName,$paidAmount));
            return $result;
        }
        if ($payer==='customer') {
            $charge=$result['customer_charge_percent'];
            $feePercent=$result['fee_percent'];
            $base=round($charge>0 ? $paidAmount/(1+($charge/100)) : $paidAmount);
            $gatewayFee=$feePercent>0 ? round($paidAmount*($feePercent/100)) : 0;
            $income=($paidAmount-$gatewayFee)-$base;
            $roundedIncome=$income>0?round($income):0;
            $result['invoice_payment_amount']=$base;
            $result['income_amount']=$roundedIncome;
            $result['income_percent']=($base>0 && $roundedIncome>0)
                ? round(($roundedIncome/$base)*100,6)
                : 0;
            $result['income_account_path']=trim((string)Configuration::get($configName.'_INCOME_ACCOUNT_PATH'));
            $result['income_contact_code']=trim((string)Configuration::get($configName.'_FEE_INCOME_CONTACT_CODE'));
        }
        return $result;
    }
}
