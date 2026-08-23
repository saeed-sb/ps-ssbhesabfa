<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('_PS_VERSION_', '8.1.7');

class Configuration
{
    public static $values = array();

    public static function get($key)
    {
        return array_key_exists($key, self::$values) ? self::$values[$key] : false;
    }
}

class Validate
{
    public static function isLoadedObject($object)
    {
        return is_object($object);
    }
}

class Module
{
    public static $paymentHub;

    public static function getPaymentModules()
    {
        return array(array('id_module' => 1));
    }

    public static function getInstanceById($idModule)
    {
        return (int) $idModule === 1 ? self::$paymentHub : false;
    }

    public static function getInstanceByName($moduleName)
    {
        return (string) $moduleName === 'ssbpaymenthub' ? self::$paymentHub : false;
    }
}

class HesabfaPrestashopRepository
{
    public static function getOrderModuleName($idOrder)
    {
        return (int) $idOrder > 0 ? 'ssbpaymenthub' : '';
    }
}

final class PaymentHubCompatibilityFixture
{
    public $name = 'ssbpaymenthub';
    public $displayName = 'SSB Payment Hub';
    public $resolvedProvider = 'saman';

    public function getProviderPaymentMethods($activeOnly = false)
    {
        $methods = array(
            array(
                'id' => 'ssbpaymenthub:saman',
                'code' => 'saman',
                'name' => 'Saman configured title',
                'module' => 'ssbpaymenthub',
                'active' => true,
            ),
            array(
                'id' => 'ssbpaymenthub:digipay',
                'code' => 'digipay',
                'name' => 'DigiPay configured title',
                'module' => 'ssbpaymenthub',
                'active' => false,
            ),
        );

        if (!$activeOnly) {
            return $methods;
        }

        return array($methods[0]);
    }

    public function getProviderPaymentMethodForOrder($idOrder)
    {
        if ((int) $idOrder !== 42 || $this->resolvedProvider === null) {
            return null;
        }

        return array('code' => $this->resolvedProvider);
    }
}

require dirname(__DIR__) . '/classes/traits/HesabfaPaymentTrait.php';

final class HesabfaPaymentHubCompatibilitySubject
{
    use HesabfaPaymentTrait;

    public function l($text)
    {
        return $text;
    }
}

$failures = array();
$assert = static function ($condition, $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};

$paymentHub = new PaymentHubCompatibilityFixture();
Module::$paymentHub = $paymentHub;
$subject = new HesabfaPaymentHubCompatibilitySubject();

$samanConfig = 'SSBHESABFA_PAYMENT_METHOD_' . md5('ssbpaymenthub|@provider:saman');
$digipayConfig = 'SSBHESABFA_PAYMENT_METHOD_' . md5('ssbpaymenthub|@provider:digipay');
$genericConfig = 'SSBHESABFA_PAYMENT_METHOD_' . md5('ssbpaymenthub|SSB Payment Hub');

$methods = $subject->getPaymentMethodsName();
$methodIds = array_column($methods, 'id');
$gateways = array_column($methods, 'gateway');
$assert(count($methods) === 2, 'Payment Hub generic row is replaced by its two provider rows');
$assert(!in_array($genericConfig, $methodIds, true), 'Generic Payment Hub configuration is not displayed');
$assert(in_array($samanConfig, $methodIds, true), 'Saman uses a stable provider-code configuration key');
$assert(in_array($digipayConfig, $methodIds, true), 'DigiPay uses a stable provider-code configuration key');
$assert($gateways === array('saman', 'digipay'), 'Provider codes are exposed to the Hesabfa mapping UI');
$assert($methods[1]['active'] === false, 'Inactive providers remain available for advance mapping');
$assert($methods[0]['name'] === 'Saman configured title (SSB Payment Hub)', 'Saman mapping label identifies its parent module');
$assert($methods[1]['name'] === 'DigiPay configured title (SSB Payment Hub)', 'DigiPay mapping label identifies its parent module');

Configuration::$values[$samanConfig] = '0018';
$samanPayment = $subject->getPaymentConfigByOrderPayment(42, 'An old title that no longer matches');
$assert($samanPayment['configuration_name'] === $samanConfig, 'Order resolution uses the persisted Saman provider code');
$assert($samanPayment['bank_code'] === '0018', 'Order resolution reads the Saman bank mapping');
$assert($samanPayment['gateway'] === 'saman', 'Order resolution returns the Saman gateway code');
$assert($samanPayment['payment'] === 'Saman configured title', 'Order resolution returns the provider title without the mapping label');

$paymentHub->resolvedProvider = null;
Configuration::$values[$digipayConfig] = '0029';
$digipayPayment = $subject->getPaymentConfigByOrderPayment(43, 'DigiPay configured title');
$assert($digipayPayment['configuration_name'] === $digipayConfig, 'Older orders fall back to the matching DigiPay title');
$assert($digipayPayment['bank_code'] === '0029', 'Title fallback reads the DigiPay bank mapping');
$assert($digipayPayment['gateway'] === 'digipay', 'Title fallback returns the DigiPay gateway code');

if ($failures) {
    fwrite(STDERR, "FAIL\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK: ssbhesabfa Payment Hub compatibility tests\n";
