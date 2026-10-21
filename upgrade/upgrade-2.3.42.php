<?php
if (!defined('_PS_VERSION_')) { exit; }

function upgrade_module_2_3_42($module)
{
    $defaults = array(
        'SSBHESABFA_FEE_EXPENSE_DOCUMENT_DESCRIPTION_TEMPLATE' => 'Gateway fee expense - order {order_id} - invoice {invoice_number} - transaction {transaction_number} - customer charge {customer_charge_percent}% - gateway fee {fee_percent}% - expense {expense_percent}%',
        'SSBHESABFA_MANUAL_FEE_EXPENSE_DOCUMENT_DESCRIPTION_TEMPLATE' => 'Gateway fee expense - invoice {invoice_number} - transaction {transaction_number} - customer charge {customer_charge_percent}% - gateway fee {fee_percent}% - expense {expense_percent}%',
    );
    foreach ($defaults as $key => $value) {
        if (Configuration::get($key) === false && !Configuration::updateValue($key, $value)) { return false; }
    }
    // Account paths remain per gateway and must be selected by the merchant.
    // No payment replay, financial writes or schema changes occur on upgrade.
    return true;
}
