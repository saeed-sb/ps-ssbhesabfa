<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/** Shop-scoped Hesabfa contact codes, keyed by stable carrier references. */
class HesabfaFreightPerson
{
    public static function configKey($reference)
    {
        $reference = (int) $reference;
        if ($reference <= 0) {
            throw new InvalidArgumentException('Invalid carrier reference.');
        }

        return 'SSBHESABFA_FREIGHT_PERSON_' . $reference;
    }

    public static function normalizeCode($raw)
    {
        if ($raw === null) {
            return '';
        }
        if (!is_string($raw) && !is_int($raw)) {
            throw new InvalidArgumentException('The freight person code must contain digits only.');
        }

        $code = strtr((string) $raw, array(
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ));
        $code = preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $code);
        if ($code === '') {
            return '';
        }
        if (!is_string($code) || !preg_match('/^[0-9]+$/D', $code) || ltrim($code, '0') === '') {
            throw new InvalidArgumentException('The freight person code must be a positive digit string.');
        }

        return $code;
    }

    /** Include inactive carriers too; historical carrier revisions share one setting. */
    public static function getCarriers($idShop)
    {
        $idShop = self::shopId($idShop);
        $rows = Db::getInstance()->executeS(
            'SELECT c.id_carrier, c.id_reference, c.name, c.active'
            . ' FROM `' . _DB_PREFIX_ . 'carrier` c'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'carrier_shop` cs ON cs.id_carrier = c.id_carrier'
            . ' WHERE cs.id_shop = ' . $idShop . ' AND c.deleted = 0'
            . ' ORDER BY c.id_reference ASC, c.id_carrier DESC'
        );
        if (!is_array($rows)) {
            throw new RuntimeException('Unable to read shop carriers.');
        }

        $carriers = array();
        foreach ($rows as $row) {
            $reference = (int) $row['id_reference'];
            if ($reference <= 0 || isset($carriers[$reference])) {
                continue;
            }
            $carriers[$reference] = array(
                'reference' => $reference,
                'name' => (string) $row['name'],
                'active' => (bool) $row['active'],
            );
        }

        $carriers = array_values($carriers);
        usort($carriers, function ($first, $second) {
            if ($first['active'] !== $second['active']) {
                return $first['active'] ? -1 : 1;
            }

            return $first['reference'] <=> $second['reference'];
        });

        return $carriers;
    }

    public static function getCode($reference, $idShop)
    {
        $row = self::configurationRow(self::configKey($reference), self::shopId($idShop));

        return is_array($row) ? self::normalizeCode($row['value']) : '';
    }

    public static function getFormValues($idShop)
    {
        $values = array();
        foreach (self::getCarriers($idShop) as $carrier) {
            $values[self::configKey($carrier['reference'])] = self::getCode($carrier['reference'], $idShop);
        }

        return $values;
    }

    /** Validate every submitted carrier before writing any of its settings. */
    public static function saveSubmitted($idShop, array $input)
    {
        $idShop = self::shopId($idShop);
        $idShopGroup = (int) Shop::getGroupFromShop($idShop);
        if ($idShopGroup <= 0) {
            throw new InvalidArgumentException('Invalid shop for freight person settings.');
        }

        $values = array();
        foreach (self::getCarriers($idShop) as $carrier) {
            $key = self::configKey($carrier['reference']);
            if (array_key_exists($key, $input)) {
                $values[$key] = self::normalizeCode($input[$key]);
            }
        }

        // Configuration::get falls back across scopes; updateValue also compares
        // numeric strings loosely. Read/write these codes exactly to preserve 0012.
        $db = Db::getInstance();
        foreach ($values as $key => $code) {
            $row = self::configurationRow($key, $idShop);
            if (is_array($row) && (string) $row['value'] === $code) {
                continue;
            }
            $data = array(
                'id_shop_group' => $idShopGroup,
                'id_shop' => $idShop,
                'value' => pSQL($code),
                'date_upd' => date('Y-m-d H:i:s'),
            );
            if (is_array($row)) {
                $saved = $db->update('configuration', $data, self::configurationWhere($key, $idShop));
            } else {
                $data['name'] = pSQL($key);
                $data['date_add'] = $data['date_upd'];
                $saved = $db->insert('configuration', $data);
            }
            if (!$saved) {
                throw new RuntimeException('Unable to save freight person settings.');
            }
        }

        return true;
    }

    private static function configurationRow($key, $idShop)
    {
        $rows = Db::getInstance()->executeS(
            'SELECT id_configuration, value FROM `' . _DB_PREFIX_ . 'configuration`'
            . ' WHERE ' . self::configurationWhere($key, $idShop)
            . ' ORDER BY id_configuration DESC LIMIT 1',
            true,
            false
        );
        if (!is_array($rows)) {
            throw new RuntimeException('Unable to read freight person settings.');
        }

        return $rows ? $rows[0] : false;
    }

    private static function configurationWhere($key, $idShop)
    {
        return "name = '" . pSQL($key) . "' AND id_shop = " . (int) $idShop;
    }

    private static function shopId($idShop)
    {
        $idShop = (int) $idShop;
        if ($idShop <= 0) {
            throw new InvalidArgumentException('A specific shop is required for freight person settings.');
        }

        return $idShop;
    }
}
