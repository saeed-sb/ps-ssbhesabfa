<?php
if (!defined('_PS_VERSION_')) { exit; }

class HesabfaLock
{
    private static $supported = null;
    private static $held = array();

    public static function supportsMultipleLocks()
    {
        if (self::$supported === null) {
            $version = (string) Db::getInstance()->getValue('SELECT VERSION()', false);
            if (stripos($version, 'MariaDB') !== false) {
                preg_match('/(?:5\.5\.5-)?(\d+\.\d+\.\d+)/', $version, $match);
                self::$supported = isset($match[1]) && version_compare($match[1], '10.0.2', '>=');
            } else {
                preg_match('/^(\d+\.\d+\.\d+)/', $version, $match);
                self::$supported = isset($match[1]) && version_compare($match[1], '5.7.5', '>=');
            }
        }
        return self::$supported;
    }

    public static function name($scope)
    {
        return 'ssbh:' . sha1(_DB_NAME_ . ':' . _DB_PREFIX_ . ':' . (string) $scope);
    }

    public static function acquire($scope)
    {
        if (isset(self::$held[$scope]) || !self::supportsMultipleLocks()) { return false; }
        if ((int) Db::getInstance()->getValue("SELECT GET_LOCK('" . self::name($scope) . "', 0)", false) !== 1) { return false; }
        self::$held[$scope] = true;
        return true;
    }

    public static function release($scope)
    {
        if (!isset(self::$held[$scope])) { return false; }
        unset(self::$held[$scope]);
        return Db::getInstance()->getValue("SELECT RELEASE_LOCK('" . self::name($scope) . "')", false);
    }
}
