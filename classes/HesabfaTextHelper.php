<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class HesabfaTextHelper
{
    public static function renderTemplate($template, array $vars)
    {
        $text = (string) $template;
        foreach ($vars as $key => $value) {
            $text = str_replace('{' . $key . '}', (string) $value, $text);
        }
        return $text;
    }

    public static function normalizeLogMessage($message)
    {
        $message = trim((string) $message);
        $message = preg_replace('/^ssbhesabfa\s*-\s*/i', '', $message);
        $message = str_replace(array('webHook', 'WebHook'), 'webhook', $message);
        $message = str_replace('Error Message:', 'Details:', $message);
        $message = str_replace('Bank Code not define', 'Bank code is not defined', $message);
        $message = str_replace('No product available for set Opening quantity', 'No product is available for opening quantity sync', $message);
        $message = str_replace('No product available for export', 'No product is available for export', $message);
        $message = str_replace('No customer exists for export', 'No customer is available for export', $message);
        $message = str_replace('Online Store', 'the online store', $message);
        $message = str_replace('Giftwrapping', 'gift wrapping', $message);
        $message = str_replace('Opening quantity', 'opening quantity', $message);
        $message = str_replace('Return sale Invoice', 'return sales invoice', $message);
        $message = str_replace('Invoice number', 'invoice number', $message);
        $message = str_replace('Item Code', 'item code', $message);
        $message = str_replace('Contact Code', 'contact code', $message);
        $message = str_replace('Service Code', 'service code', $message);
        $message = preg_replace('/^Cannot\s+/', 'Failed to ', $message);
        $message = str_replace('Cannot ', 'Failed to ', $message);
        $message = str_replace('successfully Set', 'was configured successfully', $message);
        $message = preg_replace('/successfully added/i', 'was added successfully', $message);
        $message = preg_replace('/successfully updated/i', 'was updated successfully', $message);
        $message = preg_replace('/successfully deleted/i', 'was deleted successfully', $message);
        $message = preg_replace('/(?<!was )added successfully/i', 'was added successfully', $message);
        $message = preg_replace('/(?<!was )updated successfully/i', 'was updated successfully', $message);
        $message = preg_replace('/(?<!was )deleted successfully/i', 'was deleted successfully', $message);
        $message = preg_replace('/\s+/', ' ', $message);
        $message = trim($message);
        if (Tools::strlen($message) > 4000) {
            $message = Tools::substr($message, 0, 4000) . '... [truncated]';
        }
        return $message;
    }
}
