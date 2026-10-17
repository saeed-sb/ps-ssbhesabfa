<?php
if (!defined('_PS_VERSION_')) { exit; }

/** Pure construction of a freight adjustment from verified invoice and saved cost. */
class HesabfaFreightMarginPlan
{
    const MAX_RIAL = 999999999999999;

    public static function build(array $order, array $source, array $invoice, array $settings, $documentDate, $number = 0)
    {
        $idOrder = self::positiveId(isset($order['id_order']) ? $order['id_order'] : null, 'order');
        $idShop = self::positiveId(isset($order['id_shop']) ? $order['id_shop'] : null, 'shop');
        $carrier = self::positiveId(isset($order['id_carrier']) ? $order['id_carrier'] : null, 'order carrier');
        if (self::positiveId(isset($source['id_carrier']) ? $source['id_carrier'] : null, 'source carrier') !== $carrier) {
            throw new InvalidArgumentException('The shipment carrier changed; reconciliation is required.');
        }
        if (!isset($source['accounting_blocked']) || !in_array($source['accounting_blocked'], array(false, 0, '0', ''), true)) {
            throw new InvalidArgumentException('The shipment cost is not verified for accounting.');
        }
        if (!isset($settings[HesabfaFreightMarginSettings::ENABLED])
            || HesabfaFreightMarginSettings::normalize(HesabfaFreightMarginSettings::ENABLED, $settings[HesabfaFreightMarginSettings::ENABLED]) !== '1') {
            throw new InvalidArgumentException('Automatic freight margin accounting is disabled.');
        }
        $income = HesabfaFreightMarginSettings::normalize(HesabfaFreightMarginSettings::INCOME_ACCOUNT,
            isset($settings[HesabfaFreightMarginSettings::INCOME_ACCOUNT]) ? $settings[HesabfaFreightMarginSettings::INCOME_ACCOUNT] : '');
        $expense = HesabfaFreightMarginSettings::normalize(HesabfaFreightMarginSettings::EXPENSE_ACCOUNT,
            isset($settings[HesabfaFreightMarginSettings::EXPENSE_ACCOUNT]) ? $settings[HesabfaFreightMarginSettings::EXPENSE_ACCOUNT] : '');
        if ($income === '' || $expense === '') { throw new InvalidArgumentException('Freight income and expense accounts are required.'); }
        $person = HesabfaFreightPerson::normalizeCode(isset($settings['person_code']) ? $settings['person_code'] : '');
        if ($person === '') { throw new InvalidArgumentException('The carrier person code is required.'); }
        $reference = self::text(isset($order['reference']) ? $order['reference'] : null, 128);
        if ($reference === '' || !isset($invoice['Reference']) || (string) $invoice['Reference'] !== $reference) {
            throw new InvalidArgumentException('Invoice reference does not match this order.');
        }
        $tag = isset($invoice['Tag']) ? $invoice['Tag'] : null;
        if (is_string($tag)) { $tag = json_decode($tag, true); }
        if (!is_array($tag) || !isset($tag['id_order']) || self::positiveId($tag['id_order'], 'invoice order') !== $idOrder) {
            throw new InvalidArgumentException('Invoice ownership tag does not match this order.');
        }
        if (!isset($invoice['InvoiceType']) || !in_array($invoice['InvoiceType'], array(0, '0'), true)
            || !isset($invoice['Status']) || !in_array($invoice['Status'], array(1, '1'), true)
            || !array_key_exists('Returned', $invoice) || !in_array($invoice['Returned'], array(false, 0, '0'), true)) {
            throw new InvalidArgumentException('Only a confirmed, non-returned sales invoice can be adjusted.');
        }
        if (!isset($invoice['Currency']) || $invoice['Currency'] !== 'IRR' || !isset($invoice['CurrencyRate'])
            || !preg_match('/^1(?:\.0+)?$/D', self::decimalString($invoice['CurrencyRate'], true))) {
            throw new InvalidArgumentException('The invoice must use IRR at currency rate one.');
        }
        $invoiceNumber = self::positiveId(isset($invoice['Number']) ? $invoice['Number'] : null, 'invoice number');
        if (!array_key_exists('Freight', $invoice)) { throw new InvalidArgumentException('The invoice freight amount is missing.'); }
        $freight = self::roundedRial($invoice['Freight'], 0, true);
        $invoicePerson = HesabfaFreightPerson::normalizeCode(isset($invoice['FreightPersonCode']) ? $invoice['FreightPersonCode'] : '');
        if ($freight > 0 && $invoicePerson === '') { throw new InvalidArgumentException('Invoice freight was not assigned to a carrier person.'); }
        $reclassify=$freight > 0 && ltrim($invoicePerson,'0')!==ltrim($person,'0');
        if (!array_key_exists('sender_cost_toman', $source) || !array_key_exists('cost_total_toman', $source)) {
            throw new InvalidArgumentException('The saved shipment cost is incomplete.');
        }
        $cost = self::tomanToRial($source['sender_cost_toman']);
        $total = self::tomanToRial($source['cost_total_toman']);
        if ($cost > $total) { throw new InvalidArgumentException('Sender cost exceeds the total shipment cost.'); }
        $margin = $freight - $cost;
        $result = array('document' => null, 'margin_rial' => $margin, 'customer_freight_rial' => $freight,
            'sender_cost_rial' => $cost, 'person_code' => $person);
        if (!is_string($documentDate) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:T00:00:00)?$/D', $documentDate, $date)
            || !checkdate((int) $date[2], (int) $date[3], (int) $date[1])) {
            throw new InvalidArgumentException('Invalid freight document date.');
        }
        $number = self::nonnegativeId($number, 'document number');
        if ($margin === 0 && !$reclassify) { return $result; }
        $tracking = self::tracking(isset($source['tracking']) ? $source['tracking'] : '');
        $description = '[SSB-FREIGHT:' . $idShop . ':' . $idOrder . '] سفارش' . $idOrder
            . '/' . self::shortText($reference, 24) . '؛ فاکتور' . $invoiceNumber
            . '؛ دریافت' . $freight . ' هزینه' . $cost . ' اختلاف' . $margin . ' ریال؛ رهگیری';
        $extra = count($tracking) > 1 ? ' (+' . (count($tracking) - 1) . ')' : '';
        // Hesabfa rejects descriptions of 200 characters or more. Keep the
        // financial identifiers intact; full tracking/source evidence is local.
        $room = 199 - self::apiTextLength($description . $extra);
        if ($room < 1) { throw new InvalidArgumentException('Freight description exceeds the API limit.'); }
        $description .= self::shortText($tracking ? $tracking[0] : 'ثبت نشده', $room) . $extra;
        if ($reclassify) {
            // The immutable invoice credited its original carrier. Clear that
            // balance and establish only the current carrier's actual payable.
            $transactions=array(self::transaction(HesabfaFreightMarginSettings::CARRIER_ACCOUNT,0,$freight,$description,$invoicePerson));
            if ($margin<0) { $transactions[]=self::transaction($expense,0,-$margin,$description,''); }
            if ($cost>0) { $transactions[]=self::transaction(HesabfaFreightMarginSettings::CARRIER_ACCOUNT,1,$cost,$description,$person); }
            if ($margin>0) { $transactions[]=self::transaction($income,1,$margin,$description,''); }
            $amount=max($freight,$cost);
        } else {
            $amount=abs($margin);
            $carrierRow=self::transaction(HesabfaFreightMarginSettings::CARRIER_ACCOUNT,$margin>0?0:1,$amount,$description,$person);
            $marginRow=self::transaction($margin>0?$income:$expense,$margin>0?1:0,$amount,$description,'');
            $transactions=$margin>0?array($carrierRow,$marginRow):array($marginRow,$carrierRow);
        }
        $document = array('reference' => $idOrder,
            'date' => $date[1] . '-' . $date[2] . '-' . $date[3] . 'T00:00:00', 'description' => $description,
            'project' => self::text(isset($invoice['Project']) ? $invoice['Project'] : '', 512),
            'debit' => $amount, 'credit' => $amount, 'status' => 1,
            'transactions' => $transactions);
        if ($number > 0) { $document['number'] = $number; }
        $result['document'] = $document;
        return $result;
    }

    public static function tomanToRial($value) { return self::roundedRial($value, 1, false); }

    private static function roundedRial($value, $shift, $allowFloat)
    {
        $raw = self::decimalString($value, $allowFloat);
        if (!preg_match('/^([0-9]{1,30})(?:\.([0-9]{1,18}))?$/D', $raw, $match)) {
            throw new InvalidArgumentException('Money must be a bounded nonnegative decimal.');
        }
        $fraction = isset($match[2]) ? $match[2] : '';
        $digits = ltrim($match[1] . substr(str_pad($fraction, $shift, '0'), 0, $shift), '0');
        if ($digits === '') { $digits = '0'; }
        $limit = (string) self::MAX_RIAL;
        if (strlen($digits) > strlen($limit) || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) {
            throw new InvalidArgumentException('Money exceeds the supported accounting range.');
        }
        $amount = (int) $digits;
        if (isset($fraction[$shift]) && $fraction[$shift] >= '5') {
            if ($amount === self::MAX_RIAL) { throw new InvalidArgumentException('Rounded money exceeds the supported range.'); }
            ++$amount;
        }
        return $amount;
    }

    private static function decimalString($value, $allowFloat)
    {
        if (is_int($value) || is_string($value)) { return (string) $value; }
        // API JSON may decode an integral decimal as float; never do monetary arithmetic with it.
        if ($allowFloat && is_float($value) && is_finite($value)) { return json_encode($value, JSON_PRESERVE_ZERO_FRACTION); }
        throw new InvalidArgumentException('A decimal money value is required.');
    }

    private static function positiveId($value, $label)
    {
        $id = self::nonnegativeId($value, $label);
        if ($id === 0) { throw new InvalidArgumentException('Invalid ' . $label . '.'); }
        return $id;
    }

    private static function nonnegativeId($value, $label)
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^[0-9]{1,10}$/D', (string) $value)
            || (int) $value > 2147483647) { throw new InvalidArgumentException('Invalid ' . $label . '.'); }
        return (int) $value;
    }

    private static function text($value, $limit)
    {
        if (!is_string($value) || strlen($value) > $limit || !preg_match('//u', $value)
            || preg_match('/[\x00-\x1F\x7F]/u', $value)) { throw new InvalidArgumentException('Invalid freight document text.'); }
        return $value;
    }

    private static function tracking($value)
    {
        if (!is_array($value)) {
            $value = self::text($value, 512);
            return $value === '' ? array() : array($value);
        }
        if (count($value) > 20 || ($value && array_keys($value) !== range(0, count($value) - 1))) {
            throw new InvalidArgumentException('Invalid shipment tracking list.');
        }
        $codes = array();
        foreach ($value as $code) {
            $code = trim(self::text($code, 128));
            if ($code === '') { throw new InvalidArgumentException('Empty shipment tracking code.'); }
            $codes[] = $code;
        }
        $codes = array_values(array_unique($codes));
        sort($codes, SORT_STRING);
        self::text(implode(', ', $codes), 512);
        return $codes;
    }

    private static function apiTextLength($value)
    {
        $length = 0;
        foreach (preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) as $char) {
            // Count surrogate pairs too, matching common .NET API validation.
            $length += strlen($char) === 4 ? 2 : 1;
        }
        return $length;
    }

    private static function shortText($value, $limit)
    {
        if (self::apiTextLength($value) <= $limit) { return $value; }
        $result = ''; $length = 0;
        foreach (preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) as $char) {
            $units = strlen($char) === 4 ? 2 : 1;
            if ($length + $units > $limit - 1) { break; }
            $result .= $char; $length += $units;
        }
        return $result . '…';
    }

    private static function transaction($path, $type, $amount, $description, $person)
    {
        return array('accountPath' => $path, 'description' => $description, 'info' => '',
            'amount' => $amount, 'currencyAmount' => $amount, 'currency' => 'IRR', 'type' => $type,
            'contactCode' => $person, 'productCode' => '', 'bankCode' => '', 'cashCode' => '', 'pettyCashCode' => '');
    }
}
