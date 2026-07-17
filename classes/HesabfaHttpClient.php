<?php
if (!defined('_PS_VERSION_')) { exit; }
class HesabfaHttpClient
{
    public function post($method, array $data)
    {
        try {
            HesabfaRateLimiter::acquire(1);
        } catch (HesabfaRateLimitException $e) {
            return HesabfaApiResponse::normalize((object) array(
                'Success' => false,
                'ErrorCode' => 'RATE_LIMIT',
                'ErrorMessage' => $e->getMessage(),
                'RetryAfter' => $e->getRetryAfter(),
            ), $method, 429, null);
        }

        $url = 'https://api.hesabfa.com/v1/' . ltrim((string) $method, '/');
        $payload = json_encode($data);
        $debug = (bool) Configuration::get('SSBHESABFA_DEBUG_MODE');
        $debugData = $this->maskSensitiveData($data);
        if ($debug && class_exists('Ssbhesabfa')) {
            Ssbhesabfa::addLegacyLog('Hesabfa API request. Method: ' . $method, 'DEBUG', null, 'System', $method, true, array(
                'area' => 'API', 'prestashop_code' => $method, 'debug_endpoint' => $url,
                'debug_request' => $debugData, 'debug_payload' => $debugData,
            ));
        }

        $start = microtime(true);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        $result = curl_exec($ch);
        $curlErrorNo = curl_errno($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $durationMs = (int) round((microtime(true) - $start) * 1000);

        if ($curlErrorNo) {
            return HesabfaApiResponse::normalize((object) array('Success'=>false,'ErrorCode'=>'CURL_'.$curlErrorNo,'ErrorMessage'=>$curlError), $method, $httpCode, $result);
        }
        if ($debug && class_exists('Ssbhesabfa')) {
            $responseLogOptions = array(
                'area' => 'API',
                'prestashop_code' => $method,
                'debug_endpoint' => $url,
                'debug_http_code' => $httpCode,
                'debug_duration_ms' => $durationMs,
                'debug_response' => $result,
            );
            $responseHesabfaCode = $this->extractHesabfaCodeFromResponse($result);
            if ($responseHesabfaCode !== null) {
                $responseLogOptions['hesabfa_code'] = (string) $responseHesabfaCode;
            }
            Ssbhesabfa::addLegacyLog(
                'Hesabfa API response. Method: ' . $method . ' - HTTP ' . $httpCode,
                'DEBUG',
                null,
                'System',
                $method,
                true,
                $responseLogOptions
            );
        }
        if ($result === false || $result === null || $result === '') {
            return HesabfaApiResponse::normalize((object) array('Success'=>false,'ErrorCode'=>'NO_RESPONSE','ErrorMessage'=>'No response from Hesabfa. HTTP code: '.$httpCode), $method, $httpCode, $result);
        }
        $decoded = json_decode($result);
        if (!is_object($decoded)) {
            return HesabfaApiResponse::normalize((object) array('Success'=>false,'ErrorCode'=>'INVALID_JSON','ErrorMessage'=>'Invalid JSON response from Hesabfa. HTTP code: '.$httpCode), $method, $httpCode, $result);
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            if (!isset($decoded->Success)) $decoded->Success = false;
            if (!isset($decoded->ErrorCode)) $decoded->ErrorCode = 'HTTP_' . $httpCode;
            if (!isset($decoded->ErrorMessage)) $decoded->ErrorMessage = 'HTTP error from Hesabfa.';
            if ($httpCode === 429 && !isset($decoded->RetryAfter)) $decoded->RetryAfter = 60;
        }
        return HesabfaApiResponse::normalize($decoded, $method, $httpCode, $result);
    }

    private function extractHesabfaCodeFromResponse($rawResponse)
    {
        if (!is_string($rawResponse) || $rawResponse === '') {
            return null;
        }

        $decoded = json_decode($rawResponse);
        if (!is_object($decoded) || !isset($decoded->Result)) {
            return null;
        }

        $result = $decoded->Result;
        if (is_object($result)) {
            if (isset($result->Code) && is_numeric($result->Code)) {
                return (string) $result->Code;
            }
            if (isset($result->Number) && is_numeric($result->Number)) {
                return (string) $result->Number;
            }
        }

        if (is_array($result) && isset($result[0]) && is_object($result[0])) {
            if (isset($result[0]->Code) && is_numeric($result[0]->Code)) {
                return (string) $result[0]->Code;
            }
            if (isset($result[0]->Number) && is_numeric($result[0]->Number)) {
                return (string) $result[0]->Number;
            }
        }

        return null;
    }

    private function maskSensitiveData($data)
    {
        $keys = array('apiKey','password','loginToken','token','passwordHash');
        if (is_object($data)) $data = (array) $data;
        if (!is_array($data)) return $data;
        foreach ($data as $key=>$value) {
            if (in_array($key, $keys, true)) $data[$key] = '***';
            elseif (is_array($value) || is_object($value)) $data[$key] = $this->maskSensitiveData($value);
        }
        return $data;
    }
}
