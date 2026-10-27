<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
define('_PS_VERSION_', '8.1.7');
function check($ok, $message) { if (!$ok) { throw new Exception($message); } }
class HesabfaSecurity { public static function isOperational() { return true; } }
class Configuration {
    public static function get($key) { return in_array($key, array('SSBHESABFA_ITEM_UPDATE_PRICE', 'SSBHESABFA_ITEM_UPDATE_QUANTITY'), true); }
}
class Ssbhesabfa {
    public static function getObjectId(...$args) { return 1; }
    public static function getPriceInHesabfaDefaultCurrency($value) { return $value; }
    public static function getPriceInPrestashopDefaultCurrency($value) { return $value; }
    public static function addLegacyLog(...$args) {}
}
class HesabfaModel { public $id_hesabfa = 905104; public function __construct($id) {} }
class Product { public $price = 500; public function __construct($id) {} }
class Validate { public static function isLoadedObject($object) { return true; } }
class HesabfaProductService {
    public static $prices = array();
    public static function updateBaseProductPrice($id, $price) { self::$prices[$id] = $price; return true; }
}
class StockAvailable { public static function getQuantityAvailableByProduct(...$args) { return 4; } }
class HesabfaStockService {
    public static $stocks = array();
    public static function setQuantity($id, $attribute, $quantity) { self::$stocks[$id] = $quantity; return true; }
}
require dirname(__DIR__) . '/classes/HesabfaWebhook.php';
$item = (object) array('Code' => '905104', 'Tag' => '{"id_product":19764,"id_attribute":0}', 'Stock' => 0, 'SellPrice' => 100);
check(HesabfaWebhook::setItemChanges($item, false, true, false), 'Stock-only application failed');
check(HesabfaStockService::$stocks === array(19764 => 0), 'Authoritative zero stock was not applied');
check(HesabfaProductService::$prices === array(), 'API invoice stock sync changed the price');
check(HesabfaWebhook::setItemChanges($item, false, true), 'Existing full sync failed');
check(HesabfaProductService::$prices === array(19764 => 100), 'Existing manual/product price synchronization changed');
echo "PASS: actual item handler updates authoritative zero stock without price writes; existing full synchronization remains enabled\n";
