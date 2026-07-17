<?php
if (!defined('_PS_VERSION_')) { exit; }
class HesabfaRateLimiter
{
    const PROVIDER_LIMIT_PER_MINUTE = 240;
    const DEFAULT_SAFE_LIMIT = 200;

    public static function acquire($cost = 1)
    {
        $cost = max(1, (int) $cost);
        $configured = (int) Configuration::get('SSBHESABFA_RATE_LIMIT_PER_MINUTE');
        $limit = $configured > 0 ? min(self::PROVIDER_LIMIT_PER_MINUTE, $configured) : self::DEFAULT_SAFE_LIMIT;
        $window = date('Y-m-d H:i:00');
        $table = _DB_PREFIX_ . 'ssb_hesabfa_rate_limit';
        $sql = 'INSERT INTO `' . bqSQL($table) . '` (`window_start`,`request_count`,`date_upd`) VALUES ("' . pSQL($window) . '",' . $cost . ',NOW()) '
            . 'ON DUPLICATE KEY UPDATE `request_count`=`request_count`+' . $cost . ', `date_upd`=NOW()';
        if (!Db::getInstance()->execute($sql)) {
            return true;
        }
        $count = (int) Db::getInstance()->getValue('SELECT `request_count` FROM `' . bqSQL($table) . '` WHERE `window_start`="' . pSQL($window) . '"');
        if (mt_rand(1, 100) === 1) {
            Db::getInstance()->execute('DELETE FROM `' . bqSQL($table) . '` WHERE `window_start` < DATE_SUB(NOW(), INTERVAL 2 DAY)');
        }
        if ($count > $limit) {
            $retryAfter = max(1, 60 - (int) date('s'));
            throw new HesabfaRateLimitException('Hesabfa API safe rate limit reached. Retry after ' . $retryAfter . ' seconds.', $retryAfter);
        }
        return true;
    }
}
