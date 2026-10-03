<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class HesabfaLogService
{
    const DEBUG_TEXT_LIMIT = 50000;

    public static function getLogLevelFromSeverity($severity)
    {
        if (is_numeric($severity)) {
            $severity = (int) $severity;
            if ($severity >= 4) {
                return 'CRITICAL';
            }
            if ($severity === 3) {
                return 'ERROR';
            }
            if ($severity === 2) {
                return 'WARNING';
            }
            if ($severity === 0) {
                return 'DEBUG';
            }
            return 'INFO';
        }

        $severity = strtoupper(trim((string) $severity));
        if ($severity === 'CRITICAL') {
            return 'CRITICAL';
        }
        if ($severity === 'ERROR') {
            return 'ERROR';
        }
        if ($severity === 'WARNING' || $severity === 'WARN') {
            return 'WARNING';
        }
        if ($severity === 'DEBUG') {
            return 'DEBUG';
        }
        return 'INFO';
    }

    public static function getSeverityFromLogLevel($level)
    {
        $level = strtoupper((string) $level);
        if ($level === 'CRITICAL') {
            return 4;
        }
        if ($level === 'ERROR') {
            return 3;
        }
        if ($level === 'WARNING' || $level === 'WARN') {
            return 2;
        }
        if ($level === 'DEBUG') {
            return 0;
        }
        return 1;
    }

    public static function isDebugModeEnabled()
    {
        return (bool) Configuration::get('SSBHESABFA_DEBUG_MODE');
    }

    public static function maskSensitiveData($value)
    {
        if (is_object($value)) { $value = get_object_vars($value); }
        if (is_array($value)) {
            $out = array();
            foreach ($value as $key => $item) {
                $normalized = strtolower(preg_replace('/[^a-z0-9]/i', '', (string)$key));
                $sensitive = preg_match('/password|token|apikey|authorization|secret|email|mobile|phone|address|firstname|lastname|nationalcode|economiccode/', $normalized);
                $out[$key] = $sensitive ? '***' : self::maskSensitiveData($item);
            }
            return $out;
        }
        if (!is_string($value)) { return $value; }
        // Remove known secrets even from ordinary exception/log messages.
        foreach (array('SSBHESABFA_ACCOUNT_API','SSBHESABFA_ACCOUNT_TOKEN','SSBHESABFA_ACCOUNT_PASSWORD','SSBHESABFA_WEBHOOK_PASSWORD','SSBHESABFA_WEBHOOK_TOKEN','SSBHESABFA_QUEUE_CRON_TOKEN') as $key) {
            $secret = Configuration::get($key);
            if (is_string($secret) && $secret !== '') { $value = str_replace($secret, '***', $value); }
        }
        $value = preg_replace('/Bearer\s+[^\s,;]+/i', 'Bearer ***', $value);
        $value = preg_replace('/((?:api[_-]?key|(?:hook)?password(?:hash)?|login[_-]?token|webhook[_-]?token|token|authorization|secret)[\"\']?\s*[:=]\s*)(?:\"[^\"]*\"|\'[^\']*\'|[^\s&,;]+)/i', '$1***', $value);
        $value = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[email]', $value);
        return $value;
    }

    public static function debugMetadata($value, $depth = 0)
    {
        $budget = 100;
        return self::collectDebugMetadata($value, $depth, $budget);
    }

    private static function collectDebugMetadata($value, $depth, &$budget)
    {
        if ($depth > 6) { return array(); }
        if (is_string($value)) { $value = json_decode($value, true); }
        if (is_object($value)) { $value = get_object_vars($value); }
        if (!is_array($value)) { return array('content' => '[omitted]'); }
        $out = array();
        foreach ($value as $key => $item) {
            if (--$budget < 0) { break; }
            $normalized = strtolower((string)$key);
            if (in_array($normalized, array('success','httpcode','http_code','retryafter','count','filteredcount','number','code','errorcode'), true)
                && (is_bool($item) || is_numeric($item))) { $out[$key] = $item; }
            elseif ($normalized === 'errorcode' && is_string($item) && preg_match('/^[A-Za-z0-9_:-]{1,64}$/', $item)) { $out[$key] = $item; }
            elseif ((is_int($key) || in_array($normalized, array('result','list','response','raw','metadata'), true)) && (is_array($item) || is_object($item))) {
                $metadata = self::collectDebugMetadata($item, $depth + 1, $budget);
                if ($metadata) { $out[$key] = $metadata; }
            }
        }
        return $out;
    }

    public static function safeEndpoint($value)
    {
        $parts = parse_url((string)$value);
        return is_array($parts) && isset($parts['scheme'], $parts['host'])
            ? $parts['scheme'] . '://' . $parts['host'] . (isset($parts['path']) ? self::maskSensitiveData($parts['path']) : '') : '[endpoint omitted]';
    }

    public static function normalizeDebugValue($value)
    {
        if ($value === null || $value === '') { return null; }
        $json = json_encode(self::maskSensitiveData(self::debugMetadata($value)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return strlen((string)$json) > self::DEBUG_TEXT_LIMIT ? '{"content":"[metadata limit]"}' : $json;
    }

    public static function addModuleLog($message, $severity = 1, $errorCode = null, $objectType = null, $objectId = null, array $options = array())
    {
        if (!class_exists('Db')) {
            return false;
        }

        $level = self::getLogLevelFromSeverity($severity);
        $severity = self::getSeverityFromLogLevel($level);
        $message = self::maskSensitiveData(HesabfaTextHelper::normalizeLogMessage($message));

        $area = isset($options['area']) ? (string) $options['area'] : null;
        if ($area === null || $area === '') {
            $area = self::guessAreaFromContext($objectType, $message);
        }

        $prestashopCode = isset($options['prestashop_code']) ? (string) $options['prestashop_code'] : (string) $objectId;
        $hesabfaCode = isset($options['hesabfa_code']) ? (string) $options['hesabfa_code'] : self::resolveHesabfaCode($objectType, $objectId, $message);

        $data = array(
            'severity' => (int) $severity,
            'level' => pSQL($level),
            'area' => pSQL((string) $area),
            'error_code' => pSQL((string) $errorCode),
            'object_type' => pSQL((string) $objectType),
            'object_id' => pSQL((string) $objectId),
            'prestashop_code' => pSQL((string) $prestashopCode),
            'hesabfa_code' => pSQL((string) $hesabfaCode),
            'message' => pSQL((string) $message),
            'date_add' => date('Y-m-d H:i:s'),
        );

        if (self::isDebugModeEnabled()) {
            foreach (array('debug_endpoint', 'debug_payload', 'debug_request', 'debug_response') as $field) {
                if (array_key_exists($field, $options)) {
                    $data[$field] = pSQL((string) ($field === 'debug_endpoint' ? self::safeEndpoint($options[$field]) : self::normalizeDebugValue($options[$field])), true);
                }
            }
            if (isset($options['debug_http_code']) && $options['debug_http_code'] !== '') {
                $data['debug_http_code'] = (int) $options['debug_http_code'];
            }
            if (isset($options['debug_duration_ms']) && $options['debug_duration_ms'] !== '') {
                $data['debug_duration_ms'] = (int) $options['debug_duration_ms'];
            }
        }

        return Db::getInstance()->insert('ssb_hesabfa_log', $data, false, true, Db::INSERT_IGNORE);
    }

    protected static function guessAreaFromContext($objectType, $message)
    {
        $message = strtolower((string) $message);
        $objectType = (string) $objectType;
        if (stripos($objectType, 'API') !== false || strpos($message, 'api') !== false) {
            return 'API';
        }
        if (stripos($objectType, 'Webhook') !== false || strpos($message, 'webhook') !== false) {
            return 'Webhook';
        }
        if (strpos($message, 'queue') !== false || strpos($message, 'job') !== false) {
            return 'Queue';
        }
        if (strpos($message, 'payment') !== false || strpos($message, 'fee') !== false) {
            return 'Payment';
        }
        if (strpos($message, 'sync') !== false) {
            return 'Sync';
        }
        if (strpos($message, 'repair') !== false || strpos($message, 'mismatch') !== false) {
            return 'Repair';
        }
        return 'System';
    }

    protected static function resolveHesabfaCode($objectType, $objectId, $message)
    {
        $code = self::extractHesabfaCode($message);
        if ($code !== '') {
            return $code;
        }

        $normalizedType = strtolower(trim((string) $objectType));
        $objectId = trim((string) $objectId);

        if ($normalizedType === 'invoice' && preg_match('/^[0-9]+$/', $objectId)) {
            return $objectId;
        }

        $mappingType = null;
        $idPs = 0;
        $idPsAttribute = 0;

        if (in_array($normalizedType, array('product', 'products', 'item'), true)) {
            $mappingType = 'product';
            if (preg_match('/^([0-9]+)(?:-([0-9]+))?$/', $objectId, $matches)) {
                $idPs = (int) $matches[1];
                $idPsAttribute = isset($matches[2]) ? (int) $matches[2] : 0;
            }
        } elseif (in_array($normalizedType, array('customer', 'contact', 'address'), true)) {
            $mappingType = 'customer';
            $idPs = (int) $objectId;
        } elseif (in_array($normalizedType, array('order'), true)) {
            $mappingType = 'order';
            $idPs = (int) $objectId;
        } elseif (in_array($normalizedType, array('returnorder', 'return_order'), true)) {
            $mappingType = 'returnOrder';
            $idPs = (int) $objectId;
        }

        if ($mappingType === null || $idPs <= 0 || !class_exists('HesabfaMappingRepository')) {
            return '';
        }

        try {
            $mappedCode = HesabfaMappingRepository::getHesabfaCode($mappingType, $idPs, $idPsAttribute);
            return $mappedCode === null ? '' : (string) $mappedCode;
        } catch (Exception $e) {
            return '';
        }
    }

    protected static function extractHesabfaCode($message)
    {
        $patterns = array(
            '/New Hesabfa code:\s*([0-9]+)/i',
            '/Old Hesabfa code:\s*([0-9]+)/i',
            '/Hesabfa code:\s*([0-9]+)/i',
            '/Item code:\s*([0-9]+)/i',
            '/Contact code:\s*([0-9]+)/i',
            '/Service code:\s*([0-9]+)/i',
            '/Invoice number:\s*([0-9]+)/i',
            '/Payment number:\s*([0-9]+)/i',
            '/Receipt number:\s*([0-9]+)/i',
            '/Current mapped code:\s*([0-9]+)/i',
            '/New code from Hesabfa Tag:\s*([0-9]+)/i'
        );
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, (string) $message, $matches)) {
                return (string) $matches[1];
            }
        }
        return '';
    }
}
