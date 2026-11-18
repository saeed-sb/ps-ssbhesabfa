<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('_PS_VERSION_', '8.2.9');

class HesabfaRequestUniqueId {}
require dirname(__DIR__) . '/classes/HesabfaAPI.php';

class InvoicePaymentProbe extends HesabfaApi
{
    public $calls = 0;
    public function __construct() {}
    public function apiRequest($method, $data = array())
    {
        ++$this->calls;
        return [$method, $data];
    }
}

$api = new InvoicePaymentProbe();
$path = 'دارایی ها : دارایی های جاری : حساب های دریافتنی : حساب های دریافتنی';
[$method, $data] = $api->invoiceSavePayment(5, ['contactCode' => '000421', 'accountPath' => $path], '2026-11-17', 1100, 'fixture');
if ($method !== 'invoice/savepayment' || ($data['contactCode'] ?? null) !== '000421' || ($data['accountPath'] ?? null) !== $path) {
    throw new RuntimeException('An account with a contact detail must send both targets as strings.');
}
[, $data] = $api->invoiceSavePayment(5, '0019', '2026-11-17', 1100, 'fixture');
if (($data['bankCode'] ?? null) !== 19 || isset($data['contactCode']) || isset($data['accountPath'])) {
    throw new RuntimeException('Number and string bank targets must preserve legacy behavior.');
}
[, $data] = $api->invoiceSavePayment(7, ['accountPath' => $path, 'contactCode' => '000421'], '2026-11-18', 1100, 'refund', 'بازپرداخت', 0, null, 2);
if (($data['type'] ?? null) !== 2 || ($data['contactCode'] ?? null) !== '000421') {
    throw new RuntimeException('A sales return payment must send its invoice type and original gateway contact.');
}
$result = $api->invoiceSavePayment(5, ['bankCode' => 19, 'contactCode' => '000421', 'accountPath' => $path], '2026-11-17', 1100, 'fixture');
if ($api->calls !== 3 || $result->Success !== false || $result->ErrorCode !== 'INVALID_PAYMENT_TARGET') {
    throw new RuntimeException('Conflicting payment targets must be rejected before transport.');
}

echo "Invoice payment target checks passed.\n";
