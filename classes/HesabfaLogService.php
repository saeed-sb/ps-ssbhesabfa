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
        $sensitiveKeys = array('apiKey', 'password', 'loginToken', 'token', 'authorization', 'Authorization', 'webhook_token', 'mobile', 'email');
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (in_array((string) $key, $sensitiveKeys, true)) {
                    $value[$key] = '***';
                } else {
                    $value[$key] = self::maskSensitiveData($item);
                }
            }
            return $value;
        }
        if (is_object($value)) {
            foreach (get_object_vars($value) as $key => $item) {
                if (in_array((string) $key, $sensitiveKeys, true)) {
                    $value->{$key} = '***';
                } else {
                    $value->{$key} = self::maskSensitiveData($item);
                }
            }
            return $value;
        }
        if (is_string($value)) {
            $value = preg_replace('/("?(apiKey|password|loginToken|token|authorization|webhook_token)"?\s*[:=]\s*")([^"&\s]+)(")/i', '$1***$4', $value);
            $value = preg_replace('/((?:apiKey|password|loginToken|token|authorization|webhook_token)=)([^&\s]+)/i', '$1***', $value);
        }
        return $value;
    }

    public static function normalizeDebugValue($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = self::maskSensitiveData($value);
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            $value = (string) $value;
        }
        if (Tools::strlen($value) > self::DEBUG_TEXT_LIMIT) {
            $value = Tools::substr($value, 0, self::DEBUG_TEXT_LIMIT) . '... [truncated]';
        }
        return $value;
    }

    public static function addModuleLog($message, $severity = 1, $errorCode = null, $objectType = null, $objectId = null, array $options = array())
    {
        if (!class_exists('Db')) {
            return false;
        }

        $level = self::getLogLevelFromSeverity($severity);
        $severity = self::getSeverityFromLogLevel($level);
        $message = HesabfaTextHelper::normalizeLogMessage($message);

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
                    $data[$field] = pSQL((string) self::normalizeDebugValue($options[$field]), true);
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
