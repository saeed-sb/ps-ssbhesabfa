<?php
if (!defined('_PS_VERSION_')) { exit; }

/** Exact-shop settings. Global and shop-group values must never enable posting. */
class HesabfaFreightMarginSettings
{
    const ENABLED = 'SSBHESABFA_FREIGHT_MARGIN_ENABLED';
    const INCOME_ACCOUNT = 'SSBHESABFA_FREIGHT_MARGIN_INCOME_ACCOUNT';
    const EXPENSE_ACCOUNT = 'SSBHESABFA_FREIGHT_MARGIN_EXPENSE_ACCOUNT';
    const CARRIER_ACCOUNT = 'بدهی ها : بدهیهای جاری : حساب ها و اسناد پرداختنی : حساب های پرداختنی';

    public static function defaults()
    {
        return array(
            self::ENABLED => '0',
            self::INCOME_ACCOUNT => 'درآمد : درآمد های عملیاتی : درآمد حمل کالا',
            self::EXPENSE_ACCOUNT => 'هزینه ها : هزینه های عملیاتی : هزینه حمل کالا',
        );
    }

    public static function getFormValues($idShop)
    {
        $idShop = self::shopId($idShop);
        $values = self::defaults();
        foreach ($values as $key => $default) {
            $row = self::row($key, $idShop);
            if ($row !== false) { $values[$key] = self::normalize($key, $row['value']); }
        }
        return $values;
    }

    /** Normalize posted keys only, but validate their effective combination. */
    public static function validateSubmitted($idShop, array $input)
    {
        $effective = self::getFormValues($idShop);
        $posted = array();
        foreach (self::defaults() as $key => $default) {
            if (array_key_exists($key, $input)) {
                $posted[$key] = self::normalize($key, $input[$key]);
                $effective[$key] = $posted[$key];
            }
        }
        if ($effective[self::ENABLED] === '1'
            && ($effective[self::INCOME_ACCOUNT] === '' || $effective[self::EXPENSE_ACCOUNT] === '')) {
            throw new InvalidArgumentException('Both freight income and expense accounts are required when enabled.');
        }
        return $posted;
    }

    public static function saveSubmitted($idShop, array $input)
    {
        $idShop = self::shopId($idShop);
        $group = (int) Shop::getGroupFromShop($idShop);
        if ($group <= 0) { throw new InvalidArgumentException('Invalid shop for freight margin settings.'); }
        $values = self::validateSubmitted($idShop, $input);
        $changes = array();
        foreach ($values as $key => $value) {
            $row = self::row($key, $idShop);
            if ($row === false || (string) $row['value'] !== $value) {
                $changes[$key] = array('value' => $value, 'exists' => $row !== false);
            }
        }
        if (!$changes) { return true; }
        $db = Db::getInstance();
        if (!$db->execute('START TRANSACTION')) { throw new RuntimeException('Unable to begin freight margin settings update.'); }
        try {
            foreach ($changes as $key => $change) {
                $data = array('id_shop_group' => $group, 'id_shop' => $idShop,
                    'value' => pSQL($change['value']), 'date_upd' => date('Y-m-d H:i:s'));
                if ($change['exists']) {
                    $ok = $db->update('configuration', $data, self::where($key, $idShop));
                } else {
                    $data['name'] = pSQL($key);
                    $data['date_add'] = $data['date_upd'];
                    $ok = $db->insert('configuration', $data);
                }
                if (!$ok) { throw new RuntimeException('Unable to save freight margin settings.'); }
            }
            if (!$db->execute('COMMIT')) { throw new RuntimeException('Unable to commit freight margin settings.'); }
        } catch (Exception $e) {
            $db->execute('ROLLBACK');
            throw $e;
        }
        return true;
    }

    public static function normalize($key, $value)
    {
        if ($key === self::ENABLED) {
            if ($value !== 0 && $value !== 1 && $value !== '0' && $value !== '1') {
                throw new InvalidArgumentException('Invalid freight margin switch.');
            }
            return (string) $value;
        }
        if (!in_array($key, array(self::INCOME_ACCOUNT, self::EXPENSE_ACCOUNT), true)
            || !is_string($value) || strlen($value) > 1024 || !preg_match('//u', $value)
            || preg_match('/[\x00-\x1F\x7F<>]/u', $value)) {
            throw new InvalidArgumentException('Invalid freight margin account path.');
        }
        $value = preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $value);
        if ($value === '') { return ''; }
        $parts = explode(':', $value);
        if (count($parts) < 2) { throw new InvalidArgumentException('A full account path is required.'); }
        foreach ($parts as &$part) {
            $part = preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $part);
            if ($part === '') { throw new InvalidArgumentException('An account path segment is empty.'); }
        }
        unset($part);
        return implode(' : ', $parts);
    }

    private static function shopId($value)
    {
        if ((!is_int($value) && !is_string($value)) || !ctype_digit((string) $value) || (int) $value <= 0
            || strlen((string) $value) > 9) { throw new InvalidArgumentException('A specific shop is required.'); }
        return (int) $value;
    }

    private static function where($key, $idShop)
    {
        return "name = '" . pSQL($key) . "' AND id_shop = " . (int) $idShop;
    }

    private static function row($key, $idShop)
    {
        $rows = Db::getInstance()->executeS('SELECT id_configuration, value FROM `' . _DB_PREFIX_
            . 'configuration` WHERE ' . self::where($key, $idShop) . ' ORDER BY id_configuration DESC LIMIT 1', true, false);
        if (!is_array($rows)) { throw new RuntimeException('Unable to read freight margin settings.'); }
        return $rows ? $rows[0] : false;
    }
}
