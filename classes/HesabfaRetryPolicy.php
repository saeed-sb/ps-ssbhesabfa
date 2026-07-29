<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class HesabfaRetryPolicy
{
    const STATUS_RETRY_WAIT = 'retry_wait';
    const STATUS_NEEDS_ATTENTION = 'needs_attention';
    const STATUS_DUPLICATE_CHECK = 'duplicate_check';
    const STATUS_DEAD = 'dead';
    const REQUEST_ID_TTL_SECONDS = 86400;

    public static function classifyResponse($response)
    {
        $response = HesabfaApiResponse::normalize($response);
        return self::classify(
            HesabfaApiResponse::getErrorCode($response),
            HesabfaApiResponse::getErrorMessage($response),
            isset($response->HttpCode) ? (int) $response->HttpCode : null
        );
    }

    public static function classify($errorCode, $message = '', $httpCode = null)
    {
        $code = strtoupper(trim((string) $errorCode));
        $message = strtolower((string) $message);

        if ($code === '120' || $code === 'DUPLICATE_REQUEST_ID') {
            return self::STATUS_DUPLICATE_CHECK;
        }

        if ($code === '100' || $code === '101' || $code === 'RATE_LIMIT' || $code === 'INVOICE_MAPPING_NOT_FOUND' || $code === 'CUSTOMER_SYNC_PENDING') {
            return self::STATUS_RETRY_WAIT;
        }

        if (strpos($code, 'CURL_') === 0
            || in_array($code, array('NO_RESPONSE', 'INVALID_JSON', 'INVALID_HESABFA_RESPONSE', 'HESABFA_API_EXCEPTION', 'QUEUE_EXCEPTION', 'LOCAL_POST_PROCESSING_FAILED', 'LOCAL_POST_PROCESSING_EXCEPTION', 'UNKNOWN_HESABFA_API_ERROR', 'HESABFA_RESPONSE_ERROR'), true)) {
            return self::STATUS_RETRY_WAIT;
        }

        if (strpos($code, 'HTTP_') === 0) {
            $statusCode = (int) substr($code, 5);
            if ($statusCode === 408 || $statusCode === 425 || $statusCode === 429 || $statusCode >= 500) {
                return self::STATUS_RETRY_WAIT;
            }
            if ($statusCode >= 400 && $statusCode < 500) {
                return self::STATUS_NEEDS_ATTENTION;
            }
        }

        if (is_numeric($code) && $code !== '') {
            return self::STATUS_NEEDS_ATTENTION;
        }

        $nonRetryableCodes = array(
            'INVALID_METHOD',
            'INVALID_API_METHOD',
            'INVALID_INTERNAL_API_METHOD',
            'HESABFA_NOT_CONNECTED',
            'NOT_CONNECTED',
            'INVALID_PAYMENT_TARGET',
            'INVALID_FEE_INCOME_DOCUMENT',
        );
        if (in_array($code, $nonRetryableCodes, true)) {
            return self::STATUS_NEEDS_ATTENTION;
        }

        if (strpos($message, 'timeout') !== false
            || strpos($message, 'timed out') !== false
            || strpos($message, 'connection') !== false
            || strpos($message, 'network') !== false
            || strpos($message, 'temporar') !== false
            || strpos($message, 'rate limit') !== false) {
            return self::STATUS_RETRY_WAIT;
        }

        if ($code === '') {
            return self::STATUS_RETRY_WAIT;
        }

        return self::STATUS_NEEDS_ATTENTION;
    }

    public static function shouldRetryUntilSuccess($errorCode, $message = '', $httpCode = null)
    {
        $code = strtoupper(trim((string) $errorCode));
        $message = strtolower((string) $message);

        if (in_array($code, array('100', '101', 'RATE_LIMIT', 'CUSTOMER_SYNC_PENDING'), true)) {
            return true;
        }

        if (strpos($code, 'CURL_') === 0) {
            $curlCode = (int) substr($code, 5);
            return in_array($curlCode, array(5, 6, 7, 18, 28, 35, 47, 52, 55, 56, 92), true);
        }

        if (strpos($code, 'HTTP_') === 0) {
            $statusCode = (int) substr($code, 5);
            return $statusCode === 408 || $statusCode === 425 || $statusCode === 429 || $statusCode >= 500;
        }

        if ($httpCode !== null) {
            $httpCode = (int) $httpCode;
            if ($httpCode === 408 || $httpCode === 425 || $httpCode === 429 || $httpCode >= 500) {
                return true;
            }
        }

        if (in_array($code, array('NO_RESPONSE', 'INVALID_JSON', 'INVALID_HESABFA_RESPONSE'), true)) {
            return true;
        }

        return strpos($message, 'timeout') !== false
            || strpos($message, 'timed out') !== false
            || strpos($message, 'could not resolve') !== false
            || strpos($message, 'resolving') !== false
            || strpos($message, 'connection') !== false
            || strpos($message, 'network') !== false
            || strpos($message, 'temporar') !== false
            || strpos($message, 'rate limit') !== false;
    }

    public static function isRequestIdExpired($createdAt)
    {
        if (!$createdAt) {
            return false;
        }
        $timestamp = strtotime((string) $createdAt);
        return $timestamp !== false && (time() - $timestamp) >= self::REQUEST_ID_TTL_SECONDS;
    }

    public static function getRetryAfter($response, $defaultDelay)
    {
        if (is_object($response) && isset($response->RetryAfter) && (int) $response->RetryAfter > 0) {
            return max(1, (int) $response->RetryAfter);
        }
        if (is_array($response) && isset($response['retry_after']) && (int) $response['retry_after'] > 0) {
            return max(1, (int) $response['retry_after']);
        }
        return max(1, (int) $defaultDelay);
    }
}
