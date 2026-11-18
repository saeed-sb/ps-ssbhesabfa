<?php

trait HesabfaReturnTrait
{
    private function setInstallmentOrderReturn($order, $saleNumber, $returnDate)
    {
        $idOrder = (int) $order->id;
        $key = $this->buildOperationKey('installment_return', array($idOrder, $saleNumber));
        try {
            $operation = $this->getOperationByKey($key);
            $plan = !empty($operation['payment_plan']) ? json_decode($operation['payment_plan'], true) : null;
            if ($plan === null) {
                $payments = HesabfaOperationRepository::getInstallmentPayments($idOrder);
                if (!$payments && !$this->hasUnregisteredInstallmentPayment($order, $saleNumber)) {
                    return null;
                }
                // Complete a pending original fee before reversing it.
                if (!$this->setOrderPaymentUnlocked($idOrder)) {
                    throw new RuntimeException('ثبت دریافت یا کارمزد اصلی سفارش هنوز کامل نشده است.');
                }
                $payments = HesabfaOperationRepository::getInstallmentPayments($idOrder);
                $plan = $this->buildInstallmentReturnPlan($order, $saleNumber, $returnDate, $payments);
            }
            if (!is_array($plan) || !isset($plan['invoice'], $plan['payments'], $plan['amount'], $plan['date'], $plan['currency'])) {
                throw new RuntimeException('اطلاعات ذخیره‌شدهٔ برگشت سفارش معتبر نیست.');
            }

            $api = new HesabfaApi();
            $mappedReturn = HesabfaMappingRepository::getHesabfaCode('returnOrder', $idOrder);
            if ($mappedReturn) {
                $this->checkInstallmentReturnInvoice($api, (int) $mappedReturn, $plan);
            }
            $returnNumber = $this->saveInstallmentReturnWrite(
                $key, 'invoice_save', $idOrder, $plan,
                static function ($saved) use ($api, $mappedReturn) {
                    return $mappedReturn ? $api->invoiceGet((int) $mappedReturn, 2) : $api->invoiceSave($saved['invoice']);
                }
            );
            $returned = $this->checkInstallmentReturnInvoice($api, $returnNumber, $plan);
            if (!$this->persistInvoiceMapping('returnOrder', $idOrder, $returnNumber)) {
                throw new RuntimeException('فاکتور برگشت ثبت شد، اما ذخیرهٔ شمارهٔ آن در سفارش کامل نشد.');
            }

            $posted = 0;
            $inFlight = 0;
            foreach ($plan['payments'] as $payment) {
                $refundKey = $this->buildOperationKey('return_payment', array($idOrder, $saleNumber, $payment['source_key']));
                $refundOperation = $this->getOperationByKey($refundKey);
                if (($refundOperation['status'] ?? '') === 'success') {
                    $posted += $payment['receipt']['amount'];
                } elseif ($refundOperation && in_array($refundOperation['status'], array('pending', 'failed'), true)) {
                    $inFlight += $payment['receipt']['amount'];
                }
            }
            if ((float) $returned->Paid < $posted - 0.01 || (float) $returned->Paid > $posted + $inFlight + 0.01) {
                throw new RuntimeException('فاکتور برگشت پرداخت دیگری دارد که باید پیش از ثبت بازپرداخت تطبیق داده شود.');
            }

            foreach ($plan['payments'] as $payment) {
                $refundKey = $this->buildOperationKey('return_payment', array($idOrder, $saleNumber, $payment['source_key']));
                $target = array('accountPath' => $payment['receipt']['receivable']);
                if (isset($payment['receipt']['contact_code'])) {
                    $target['contactCode'] = $payment['receipt']['contact_code'];
                }
                $description = sprintf('بازپرداخت سفارش %s بابت فاکتور برگشت %d', $plan['order_reference'], $returnNumber);
                $refund = array(
                    'invoice' => $returnNumber, 'target' => $target, 'date' => $plan['date'],
                    'amount' => $payment['receipt']['amount'], 'reference' => $payment['refund_reference'],
                    'description' => $description, 'project' => $plan['invoice']['Project'],
                );
                $receiptNumber = $this->saveInstallmentReturnWrite(
                    $refundKey, 'return_payment', $idOrder, $refund,
                    static function ($saved) use ($api) {
                        return $api->invoiceSavePayment(
                            $saved['invoice'], $saved['target'], $saved['date'], $saved['amount'],
                            $saved['reference'], $saved['description'], 0, $saved['project'], 2
                        );
                    }
                );
                $expectedReceipt = $payment['receipt'];
                $expectedReceipt['date'] = $plan['date'];
                $expectedReceipt['reference'] = $payment['refund_reference'];
                $this->checkInstallmentReceipt($api, 2, $receiptNumber, $returnNumber, 2, $expectedReceipt);

                if ($payment['reversal'] !== null) {
                    $reversalKey = $this->buildOperationKey('payment_fee_reversal', array($idOrder, $payment['source_fee']));
                    $documentNumber = $this->saveInstallmentReturnWrite(
                        $reversalKey, 'payment_fee_reversal', $idOrder, $payment['reversal'],
                        static function ($saved) use ($api) { return $api->documentSave($saved); }
                    );
                    $this->checkInstallmentReversal($api, $documentNumber, $payment['reversal']);
                }
            }

            $returned = $this->checkInstallmentReturnInvoice($api, $returnNumber, $plan);
            if (abs((float) $returned->Paid - $plan['amount']) > 0.01 || abs((float) $returned->Rest) > 0.01) {
                throw new RuntimeException('ماندهٔ فاکتور برگشت با پرداخت‌های ثبت‌شده برابر نیست.');
            }
            HesabfaIssueRepository::resolveByOperationKey($key);
            return true;
        } catch (Throwable $e) {
            $response = (object) array('Success' => false, 'ErrorCode' => 'INSTALLMENT_RETURN_FAILED', 'ErrorMessage' => $e->getMessage());
            $this->normalizeHesabfaResponse($response, 'installmentReturn', 'ReturnOrder', $idOrder);
            $this->addFollowUpIssue('installment_return_failed', $e->getMessage(), 'ReturnOrder', $idOrder, $key, 'ERROR');
            return false;
        }
    }

    private function hasUnregisteredInstallmentPayment($order, $saleNumber)
    {
        if (!Module::isInstalled('wavendorpay')) {
            return false;
        }
        foreach (OrderPayment::getByOrderReference($order->reference) as $payment) {
            if ($payment->amount <= 0 || empty($payment->transaction_id)) {
                continue;
            }
            $key = $this->buildOperationKey('invoice_payment', array($order->id, $saleNumber, $payment->id, $payment->transaction_id));
            if ($this->getQestyarReceivablePlan($this->getOperationByKey($key), $order, $payment, $saleNumber) !== null) {
                return true;
            }
        }
        return false;
    }

    private function buildInstallmentReturnPlan($order, $saleNumber, $date, array $operations)
    {
        $date = $date ?: $order->date_upd;
        if (!is_string($date) || !HesabfaDateHelper::toTimestamp($date)) {
            throw new RuntimeException('تاریخ برگشت سفارش معتبر نیست.');
        }
        $api = new HesabfaApi();
        $response = $api->invoiceGet($saleNumber, 0);
        if (empty($response->Success) || (int) ($response->Result->Number ?? 0) !== $saleNumber
            || (int) ($response->Result->InvoiceType ?? -1) !== 0) {
            throw new RuntimeException('فاکتور فروش اصلی برای ثبت برگشت در دسترس نیست.');
        }
        $sale = $response->Result;
        $amount = (float) $sale->Payable;
        $expectedAmount = $this->getOrderPriceInHesabfaDefaultCurrency($order->total_paid_tax_incl, (int) $order->id);
        if (!is_finite($amount) || $amount <= 0 || abs($amount - $expectedAmount) > 0.01) {
            throw new RuntimeException('مبلغ فاکتور فروش اصلی با مبلغ نهایی سفارش برابر نیست.');
        }
        if (!empty($sale->Returned) && !HesabfaMappingRepository::getHesabfaCode('returnOrder', (int) $order->id)) {
            throw new RuntimeException('فاکتور فروش قبلاً برگشت خورده است. شمارهٔ فاکتور برگشت باید تطبیق داده شود.');
        }
        $invoice = array(
            'Number' => 0, 'InvoiceType' => 2, 'ContactCode' => $sale->ContactCode ?? $sale->Contact->Code,
            'Date' => $date, 'DueDate' => $date, 'Reference' => (string) $saleNumber, 'Status' => 2,
            'Tag' => json_encode(array('id_order' => (int) $order->id, 'sale_invoice' => $saleNumber)),
            'Freight' => $sale->Freight, 'FreightPersonCode' => $sale->FreightPersonCode ?? '',
            'Project' => $sale->Project ?? '', 'Currency' => $sale->Currency, 'CurrencyRate' => $sale->CurrencyRate,
            'InvoiceItems' => $this->getInstallmentReturnItems($sale->InvoiceItems),
            'Note' => sprintf('برگشت کامل سفارش %s بابت فاکتور فروش %d', $order->reference, $saleNumber),
        );
        if (!empty($sale->Others)) {
            $invoice['Others'] = json_decode(json_encode($sale->Others), true);
        }

        $payments = array();
        $paid = 0;
        foreach ($operations as $operation) {
            $receipt = json_decode($operation['payment_plan'], true);
            if ($operation['status'] !== 'success' || !is_array($receipt)
                || !isset($receipt['receivable'], $receipt['amount'], $receipt['fee'], $receipt['date'], $receipt['reference'], $receipt['currency'])
                || !is_numeric($receipt['amount']) || !is_numeric($receipt['fee'])
                || !is_finite((float) $receipt['amount']) || (float) $receipt['amount'] <= 0
                || !is_finite((float) $receipt['fee']) || (float) $receipt['fee'] < 0
                || (float) $receipt['fee'] > (float) $receipt['amount'] || $receipt['currency'] !== $sale->Currency) {
                throw new RuntimeException('دریافت اصلی سفارش برای ثبت بازپرداخت کامل و معتبر نیست.');
            }
            $this->checkInstallmentReceipt($api, 1, (int) $operation['external_reference'], $saleNumber, 0, $receipt);
            $parts = explode(':', $operation['operation_key'], 5);
            if (count($parts) !== 5 || (int) $parts[1] !== (int) $order->id || (int) $parts[2] !== $saleNumber) {
                throw new RuntimeException('شناسهٔ دریافت اصلی با سفارش و فاکتور برابر نیست.');
            }
            $feeKey = $this->buildOperationKey('payment_fee_expense_document', array($order->id, $saleNumber, $parts[3], $receipt['reference']));
            $feeOperation = $this->getCompletedOperation($feeKey);
            $reversal = null;
            $sourceFee = 0;
            if ($receipt['fee'] > 0) {
                if (!$feeOperation || $this->verifyPaymentFeeExpenseDocument(
                    $api, $feeOperation, null,
                    array('expense_amount' => $receipt['fee'], 'expense_account_path' => $receipt['expense'],
                        'receivable_account_path' => $receipt['receivable'], 'receivable_contact_code' => $receipt['contact_code'] ?? null,
                        'currency' => $receipt['currency']),
                    $receipt['date'], $receipt['reference']
                ) !== 'exists') {
                    throw new RuntimeException('سند کارمزد اصلی برای ثبت سند معکوس تأیید نشد.');
                }
                $sourceFee = (int) $feeOperation['external_reference'];
                $document = $api->documentGet($sourceFee);
                if (empty($document->Success)) {
                    throw new RuntimeException('خواندن سند کارمزد اصلی کامل نشد.');
                }
                $description = sprintf('برگشت کارمزد درگاه پرداخت بابت سفارش %s، سند %d', $order->reference, $sourceFee);
                $reversal = array(
                    'number' => 0, 'reference' => $sourceFee, 'date' => $date, 'description' => $description,
                    'project' => $document->Result->Project ?? '', 'debit' => $receipt['fee'], 'credit' => $receipt['fee'], 'status' => 1,
                    'transactions' => array(),
                );
                foreach ($document->Result->Transactions as $line) {
                    $values = array();
                    foreach (array('AccountPath', 'Info', 'Amount', 'CurrencyAmount', 'Currency', 'ContactCode', 'ProductCode', 'BankCode', 'CashCode', 'PettyCashCode') as $field) {
                        $values[lcfirst($field)] = $line->{$field} ?? '';
                    }
                    $values['type'] = 1 - (int) $line->Type;
                    $values['description'] = $description;
                    $reversal['transactions'][] = $values;
                }
            }
            $payments[] = array(
                'source_key' => sha1($operation['operation_key']), 'source_fee' => $sourceFee,
                'receipt' => $receipt, 'reversal' => $reversal,
                'refund_reference' => 'return-' . $order->id . '-' . $parts[3],
            );
            $paid += (float) $receipt['amount'];
        }
        if (!$payments || abs($paid - $amount) > 0.01) {
            throw new RuntimeException('جمع دریافت‌های اقساطی با مبلغ برگشت کامل سفارش برابر نیست.');
        }
        return array('invoice' => $invoice, 'payments' => $payments, 'date' => $date, 'amount' => $amount, 'currency' => $sale->Currency, 'order_reference' => $order->reference);
    }

    private function getInstallmentReturnItems($items)
    {
        $result = array();
        foreach ($items as $item) {
            $item = (object) $item;
            $code = $item->ItemCode ?? $item->Item->Code ?? null;
            if ($code === null || $code === '' || (float) $item->Quantity <= 0) {
                throw new RuntimeException('اطلاعات ردیف فاکتور فروش برای برگشت کامل نیست.');
            }
            $row = array('ItemCode' => (string) $code, 'Description' => (string) $item->Description);
            foreach (array('RowNumber', 'Quantity', 'UnitPrice', 'Discount', 'Tax') as $field) {
                $row[$field] = (float) $item->{$field};
            }
            if (!empty($item->SerialNumbers)) {
                $row['SerialNumbers'] = (array) $item->SerialNumbers;
            }
            $result[] = $row;
        }
        return $result;
    }

    private function saveInstallmentReturnWrite($key, $type, $idOrder, array $plan, callable $write)
    {
        $completed = $this->getCompletedOperation($key);
        if ($completed) {
            $number = (int) $completed['external_reference'];
            if ($number <= 0) {
                throw new RuntimeException('شمارهٔ ثبت تکمیل‌شدهٔ برگشت در دسترس نیست.');
            }
            return $number;
        }
        if (!$this->startOperation($key, $type, 'ReturnOrder', $idOrder, $plan)) {
            throw new RuntimeException('عملیات برگشت در حال پردازش است یا به تطبیق نیاز دارد.');
        }
        $response = $this->normalizeHesabfaResponse($write($plan), $type, 'ReturnOrder', $idOrder);
        if (!$this->isHesabfaSuccess($response)) {
            $message = 'ثبت برگشت در حسابفا کامل نشد. ' . $this->getHesabfaErrorMessage($response);
            $this->finishOperation($key, 'failed', $message);
            throw new RuntimeException($message);
        }
        $number = (int) ($response->Result->Number ?? 0);
        if ($number <= 0) {
            $this->finishOperation($key, 'needs_attention', 'شمارهٔ ثبت برگشت در پاسخ حسابفا موجود نیست.');
            throw new RuntimeException('پاسخ ثبت برگشت به تطبیق با حسابفا نیاز دارد.');
        }
        if (!$this->finishOperation($key, 'success', 'ثبت برگشت با موفقیت انجام شد.', $number)) {
            throw new RuntimeException('ذخیرهٔ نتیجهٔ ثبت برگشت کامل نشد.');
        }
        return $number;
    }

    private function checkInstallmentReturnInvoice($api, $number, array $plan)
    {
        $response = $api->invoiceGet($number, 2);
        $invoice = $response->Result ?? null;
        if (empty($response->Success) || !$invoice || (int) $invoice->Number !== $number
            || (int) $invoice->InvoiceType !== 2 || $invoice->Currency !== $plan['currency']
            || (string) ($invoice->ContactCode ?? $invoice->Contact->Code ?? '') !== (string) $plan['invoice']['ContactCode']
            || (string) $invoice->Reference !== (string) $plan['invoice']['Reference']
            || substr($invoice->Date, 0, 10) !== substr($plan['date'], 0, 10)
            || abs((float) $invoice->Payable - $plan['amount']) > 0.01
            || $this->getInstallmentReturnItems($invoice->InvoiceItems) !== $this->getInstallmentReturnItems($plan['invoice']['InvoiceItems'])) {
            throw new RuntimeException('فاکتور برگشت ثبت‌شده با اطلاعات برگشت سفارش برابر نیست.');
        }
        return $invoice;
    }

    private function checkInstallmentReceipt($api, $receiptType, $number, $invoice, $invoiceType, array $plan)
    {
        $response = $api->receiptGet($receiptType, $number);
        $receipt = $response->Result ?? null;
        if (empty($response->Success) || !$receipt || (int) $receipt->Number !== $number
            || (int) ($receipt->Invoice->Number ?? 0) !== $invoice || (int) ($receipt->Invoice->InvoiceType ?? -1) !== $invoiceType
            || $receipt->Currency !== $plan['currency'] || abs((float) $receipt->Amount - $plan['amount']) > 0.01
            || substr($receipt->DateTime, 0, 10) !== substr($plan['date'], 0, 10) || count($receipt->Transactions) !== 1) {
            throw new RuntimeException('رسید ثبت‌شده با مبلغ و فاکتور برگشت برابر نیست.');
        }
        $line = $receipt->Transactions[0];
        $normalizePath = static function ($path) { return preg_replace('/\s*:\s*/u', ':', trim((string) $path)); };
        if ($normalizePath($line->Account ?? '') !== $normalizePath($plan['receivable'])
            || !$this->hasSameHesabfaContactCode($line->Contact->Code ?? '', $plan['contact_code'] ?? '')
            || (string) $line->Reference !== (string) $plan['reference']
            || abs((float) $line->Amount - $plan['amount']) > 0.01 || (float) $line->TransactionFee !== 0.0
            || !empty($line->Bank) || !empty($line->Cash) || !empty($line->PettyCash)) {
            throw new RuntimeException('حساب و کد شخص رسید برگشت با دریافت اصلی برابر نیست.');
        }
    }

    private function checkInstallmentReversal($api, $number, array $expected)
    {
        $response = $api->documentGet($number);
        $document = $response->Result ?? null;
        if (empty($response->Success) || !$document || (int) $document->Number !== $number
            || (int) $document->Reference !== (int) $expected['reference']
            || substr($document->Date, 0, 10) !== substr($expected['date'], 0, 10)
            || abs((float) $document->Debit - $expected['debit']) > 0.01
            || abs((float) $document->Credit - $expected['credit']) > 0.01
            || count($document->Transactions) !== count($expected['transactions'])) {
            throw new RuntimeException('سند معکوس کارمزد با سند اصلی برابر نیست.');
        }
        foreach ($document->Transactions as $i => $line) {
            foreach ($expected['transactions'][$i] as $field => $value) {
                $actual = $line->{ucfirst($field)} ?? '';
                if (in_array($field, array('amount', 'currencyAmount', 'type'), true)) {
                    if (abs((float) $actual - (float) $value) <= 0.01) {
                        continue;
                    }
                } elseif ((string) $actual === (string) $value) {
                    continue;
                }
                throw new RuntimeException('ردیف سند معکوس کارمزد با سند اصلی برابر نیست.');
            }
        }
    }
}
