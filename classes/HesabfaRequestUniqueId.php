<?php
/**
 * Unique requestUniqueId helper for Hesabfa write API calls.
 *
 * A fresh UUID v4 is created for a new logical API operation. When a queue
 * or internal API request is retried, the UUID assigned to each write-call
 * slot is persisted and reused for the lifetime of that operation.
 */
class HesabfaRequestUniqueId
{
    private static $contextId = null;
    private static $requestIds = array();
    private static $methodCounters = array();
    private static $persistCallback = null;

    public static function beginContext($contextId, array $requestIds = array(), $persistCallback = null)
    {
        self::$contextId = $contextId;
        self::$requestIds = $requestIds;
        self::$methodCounters = array();
        self::$persistCallback = is_callable($persistCallback) ? $persistCallback : null;
    }

    public static function endContext()
    {
        self::$contextId = null;
        self::$requestIds = array();
        self::$methodCounters = array();
        self::$persistCallback = null;
    }

    public static function generate($method, $payload = array())
    {
        if (self::$contextId === null) {
            return self::uuidV4();
        }

        $methodKey = strtolower(trim((string) $method));
        if ($methodKey === '') {
            $methodKey = 'unknown';
        }

        $normalizedPayload = self::sortRecursive(self::removeVolatileFields($payload));
        $payloadJson = json_encode($normalizedPayload);
        if ($payloadJson === false) {
            $payloadJson = serialize($normalizedPayload);
        }

        $slotBase = $methodKey . ':' . sha1((string) $payloadJson);
        if (!isset(self::$methodCounters[$slotBase])) {
            self::$methodCounters[$slotBase] = 0;
        }
        self::$methodCounters[$slotBase]++;

        $slot = $slotBase . '#' . (int) self::$methodCounters[$slotBase];
        if (isset(self::$requestIds[$slot]) && self::isValidGuid(self::$requestIds[$slot])) {
            return (string) self::$requestIds[$slot];
        }

        $requestUniqueId = self::uuidV4();
        self::$requestIds[$slot] = $requestUniqueId;

        if (self::$persistCallback !== null) {
            call_user_func(self::$persistCallback, self::$contextId, self::$requestIds);
        }

        return $requestUniqueId;
    }


    public static function payloadHash($payload)
    {
        $normalizedPayload = self::sortRecursive(self::removeVolatileFields($payload));
        $payloadJson = json_encode($normalizedPayload);
        if ($payloadJson === false) {
            $payloadJson = serialize($normalizedPayload);
        }
        return sha1((string) $payloadJson);
    }

    public static function isWriteMethod($method)
    {
        $method = strtolower((string) $method);

        if ($method === '') {
            return false;
        }

        $writeMarkers = array(
            '/save',
            '/batchsave',
            '/save2',
            '/savepayment',
            '/delete',
            '/set',
            '/update',
            '/change',
        );

        foreach ($writeMarkers as $marker) {
            if (strpos($method, $marker) !== false) {
                return true;
            }
        }

        return false;
    }

    public static function isValidGuid($value)
    {
        return is_string($value)
            && (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value);
    }

    private static function removeVolatileFields($value)
    {
        if (is_object($value)) {
            $value = (array) $value;
        }

        if (!is_array($value)) {
            return $value;
        }

        $volatile = array('apiKey', 'userId', 'password', 'loginToken', 'requestUniqueId');
        $result = array();
        foreach ($value as $key => $item) {
            if (in_array((string) $key, $volatile, true)) {
                continue;
            }
            $result[$key] = self::removeVolatileFields($item);
        }

        return $result;
    }

    private static function sortRecursive($value)
    {
        if (is_object($value)) {
            $value = (array) $value;
        }

        if (!is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = self::sortRecursive($item);
        }

        if (!self::isListArray($value)) {
            ksort($value);
        }

        return $value;
    }

    private static function isListArray(array $array)
    {
        $expected = 0;
        foreach ($array as $key => $value) {
            if ($key !== $expected++) {
                return false;
            }
        }
        return true;
    }

    private static function uuidV4()
    {
        $data = false;

        if (function_exists('random_bytes')) {
            try {
                $data = random_bytes(16);
            } catch (Exception $e) {
                $data = false;
            }
        }

        if (($data === false || strlen($data) !== 16) && function_exists('openssl_random_pseudo_bytes')) {
            $data = openssl_random_pseudo_bytes(16);
        }

        if ($data === false || strlen($data) !== 16) {
            $data = md5(uniqid((string) mt_rand(), true), true);
        }

        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
