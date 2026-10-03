<?php
if (!defined('_PS_VERSION_')) { exit; }

/** Shared fail-closed boundaries for the module's single-shop integration. */
class HesabfaSecurity
{
    const MAX_WEBHOOK_BYTES = 65536;

    public static function matchesSecret($expected, $provided)
    {
        return is_string($expected) && $expected !== '' && is_string($provided)
            && $provided !== '' && hash_equals($expected, $provided);
    }

    public static function isSingleShop()
    {
        if (!class_exists('Shop')) { return false; }
        try {
            $shops = Shop::getShops(false, null, true);
            return is_array($shops) && count($shops) === 1;
        } catch (Throwable $e) { return false; }
    }

    public static function isOperational()
    {
        return self::isSingleShop() && class_exists('Module') && Module::isEnabled('ssbhesabfa');
    }

    public static function employeeCan($context, $controller, $action = 'view')
    {
        if (!in_array($action, array('view', 'add', 'edit', 'delete'), true)
            || !isset($context->employee) || !Validate::isLoadedObject($context->employee)) {
            return false;
        }
        try {
            $idTab = (int) Tab::getIdFromClassName($controller);
            if (!$idTab) { return false; }
            $access = Profile::getProfileAccess((int) $context->employee->id_profile, $idTab);
            return is_array($access) && isset($access[$action]) && (string) $access[$action] === '1';
        } catch (Throwable $e) { return false; }
    }

    public static function validAdminPost($controller)
    {
        return isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST'
            && self::matchesSecret(Tools::getAdminTokenLite($controller), Tools::getValue('token'));
    }

    public static function cronToken(array $server)
    {
        // Deliberately never read query parameters: web/proxy logs retain URLs.
        return isset($server['HTTP_X_SSB_HESABFA_TOKEN']) ? $server['HTTP_X_SSB_HESABFA_TOKEN'] : null;
    }

    public static function readWebhookBody($stream, $contentLength = null)
    {
        if ($contentLength !== null && (!is_string($contentLength) || !ctype_digit($contentLength)
            || (float) $contentLength > self::MAX_WEBHOOK_BYTES)) {
            return false;
        }
        if (!is_resource($stream)) { return false; }
        $body = stream_get_contents($stream, self::MAX_WEBHOOK_BYTES + 1);
        return is_string($body) && strlen($body) <= self::MAX_WEBHOOK_BYTES ? $body : false;
    }

    public static function validWebhookBody($body, $password)
    {
        if (!is_string($body) || strlen($body) > self::MAX_WEBHOOK_BYTES) { return false; }
        $request = json_decode($body, false, 16);
        return is_object($request) && isset($request->Password)
            && self::matchesSecret($password, $request->Password);
    }
}
