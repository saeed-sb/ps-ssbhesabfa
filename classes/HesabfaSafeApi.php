<?php
/**
 * Central safe API caller for new/refactored call sites.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class HesabfaSafeApi
{
    public static function call($api, $method, array $arguments = array(), $context = null, $objectType = null, $objectId = null)
    {
        try {
            if (!is_object($api) || !method_exists($api, $method)) {
                $response = HesabfaApiResponse::normalize((object) array(
                    'Success' => false,
                    'ErrorCode' => 'INVALID_API_METHOD',
                    'ErrorMessage' => 'Requested Hesabfa API method is not available.',
                ), $method);
            } else {
                $response = call_user_func_array(array($api, $method), $arguments);
                $response = HesabfaApiResponse::normalize($response, $method);
            }
        } catch (Exception $e) {
            $response = HesabfaApiResponse::normalize((object) array(
                'Success' => false,
                'ErrorCode' => 'HESABFA_API_EXCEPTION',
                'ErrorMessage' => $e->getMessage(),
            ), $method);
        }

        if (!HesabfaApiResponse::isSuccess($response) && class_exists('Ssbhesabfa')) {
            $message = trim((string) $context);
            if ($message === '') {
                $message = 'Hesabfa API call failed.';
            }
            $message .= ' Method: ' . (string) $method . '. Details: ' . HesabfaApiResponse::getErrorMessage($response);
            Ssbhesabfa::addModuleLog($message, 'ERROR', HesabfaApiResponse::getErrorCode($response), $objectType, $objectId);
        }

        return $response;
    }
}
