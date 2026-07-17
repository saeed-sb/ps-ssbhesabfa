<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class HesabfaDateHelper
{
    public static function formatAdminDate($date, $full = true)
    {
        $timestamp = self::toTimestamp($date);
        $date = trim((string) $date);
        if (!$timestamp) {
            return $date;
        }

        $normalized = date($full ? 'Y-m-d H:i:s' : 'Y-m-d', $timestamp);

        try {
            return Tools::displayDate($normalized, null, (bool) $full);
        } catch (Exception $e) {
            return $normalized;
        }
    }

    public static function formatAdminTime($date)
    {
        $timestamp = self::toTimestamp($date);
        if (!$timestamp) {
            return '';
        }

        return date('H:i:s', $timestamp);
    }

    public static function toTimestamp($date)
    {
        $date = trim((string) $date);
        if ($date === '' || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
            return 0;
        }

        if (ctype_digit($date)) {
            return (int) $date;
        }

        $normalized = str_replace('T', ' ', $date);
        $normalized = preg_replace('/\.[0-9]+Z?$/', '', $normalized);
        $normalized = preg_replace('/Z$/', '', $normalized);
        $timestamp = strtotime($normalized);

        return $timestamp ? (int) $timestamp : 0;
    }
}
