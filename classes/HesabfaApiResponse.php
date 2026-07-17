<?php
/**
 * Normalizes Hesabfa API responses while preserving the legacy object shape.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class HesabfaApiResponse
{
    private static $lastResponse = null;
    public static function normalize($response, $method = null, $httpCode = null, $rawResponse = null)
    {
        if (is_array($response)) {
            $response = (object) $response;
        }

        if (!is_object($response)) {
            $response = (object) array(
                'Success' => false,
                'ErrorCode' => 'INVALID_HESABFA_RESPONSE',
                'ErrorMessage' => 'Hesabfa API returned an invalid response.',
                'RawResponse' => $response,
            );
        }

        if (!isset($response->Success)) {
            $response->Success = false;
        }

        $response->Success = (bool) $response->Success;

        if (!isset($response->ErrorCode)) {
            $response->ErrorCode = $response->Success ? null : 'HESABFA_RESPONSE_ERROR';
        }

        if (!isset($response->ErrorMessage)) {
            $response->ErrorMessage = $response->Success ? '' : 'Hesabfa returned an incomplete response.';
        }

        if ($method !== null && !isset($response->ApiMethod)) {
            $response->ApiMethod = (string) $method;
        }

        if ($httpCode !== null && !isset($response->HttpCode)) {
            $response->HttpCode = (int) $httpCode;
        }

        if ($rawResponse !== null && !isset($response->RawResponse)) {
            $response->RawResponse = $rawResponse;
        }

        self::$lastResponse = $response;
        return $response;
    }

    public static function resetLastResponse()
    {
        self::$lastResponse = null;
    }

    public static function getLastResponse()
    {
        return self::$lastResponse;
    }

    public static function isSuccess($response)
    {
        $response = self::normalize($response);
        return isset($response->Success) && (bool) $response->Success;
    }

    public static function getErrorMessage($response)
    {
        $response = self::normalize($response);
        return isset($response->ErrorMessage) ? (string) $response->ErrorMessage : 'Unknown Hesabfa API error.';
    }

    public static function getErrorCode($response)
    {
        $response = self::normalize($response);
        return isset($response->ErrorCode) ? (string) $response->ErrorCode : 'UNKNOWN_HESABFA_API_ERROR';
    }
}
