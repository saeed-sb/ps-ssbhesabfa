<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_3_31($module)
{
    if (!Validate::isLoadedObject($module)) {
        return false;
    }

    $templates = array(
        'SSBHESABFA_FEE_INCOME_DOCUMENT_DESCRIPTION_TEMPLATE' => array(
            'Online payment fee income - order {order_id} - transaction {transaction_number}' => 'Online payment fee income - order {order_id} - transaction {transaction_number} - customer charge {customer_charge_percent}% - gateway fee {fee_percent}% - net income {income_percent}%',
            'درآمد کارمزد پرداخت آنلاین - فاکتور {invoice_number} - مرجع سفارش: {order_reference}' => 'درآمد کارمزد پرداخت آنلاین - فاکتور {invoice_number} - مرجع سفارش: {order_reference} - نرخ افزوده مشتری: {customer_charge_percent}% - نرخ کارمزد درگاه: {fee_percent}% - نرخ سود خالص: {income_percent}%',
        ),
        'SSBHESABFA_MANUAL_FEE_INCOME_DOCUMENT_DESCRIPTION_TEMPLATE' => array(
            'Manual gateway payment fee income - invoice {invoice_number} - transaction {transaction_number}' => 'Manual gateway payment fee income - invoice {invoice_number} - transaction {transaction_number} - customer charge {customer_charge_percent}% - gateway fee {fee_percent}% - net income {income_percent}%',
            'درآمد کارمزد پرداخت آفلاین - فاکتور {invoice_number} - مرجع سفارش: {order_reference}' => 'درآمد کارمزد پرداخت آفلاین - فاکتور {invoice_number} - مرجع سفارش: {order_reference} - نرخ افزوده مشتری: {customer_charge_percent}% - نرخ کارمزد درگاه: {fee_percent}% - نرخ سود خالص: {income_percent}%',
        ),
    );

    foreach ($templates as $configurationName => $replacements) {
        $currentValue = (string) Configuration::get($configurationName);
        if (!isset($replacements[$currentValue])) {
            continue;
        }
        if (!Configuration::updateValue($configurationName, $replacements[$currentValue])) {
            return false;
        }
    }

    return true;
}
