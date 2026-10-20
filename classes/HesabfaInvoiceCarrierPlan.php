<?php
if (!defined('_PS_VERSION_')) { exit; }

/** A carrier-only edit of the fetched invoice, never a reconstruction of its sale. */
class HesabfaInvoiceCarrierPlan
{
    private const FIELDS = array('Number','Reference','Date','DueDate','ContactCode','ContactTitle','Note','Sent','InvoiceType','Status','Tag','Freight','FreightPersonCode','WarehouseReceiptStatus','Project','SalesmanCode','SalesmanPercent','Currency','CurrencyRate','TaxId','Others');
    private const ITEM_FIELDS = array('RowNumber','Description','ItemCode','Unit','Quantity','UnitPrice','Discount','Tax','SerialNumbers');

    public static function build(array $order, array $invoice, $person)
    {
        $tag = $invoice['Tag'] ?? null;
        if (is_string($tag)) { $tag = json_decode($tag,true); }
        if ((int)($invoice['Id'] ?? 0)<1 || (int)($invoice['Number'] ?? 0)<1
            || (string)($invoice['Reference'] ?? '')!==(string)$order['reference']
            || !is_array($tag) || (int)($tag['id_order'] ?? 0)!==(int)$order['id_order']
            || (int)($invoice['InvoiceType'] ?? -1)!==0 || (int)($invoice['Status'] ?? 0)!==1
            || !array_key_exists('Returned',$invoice) || $invoice['Returned']
            || !is_array($invoice['InvoiceItems'] ?? null) || !$invoice['InvoiceItems']) {
            throw new InvalidArgumentException('Only the confirmed, non-returned sales invoice owned by this order can be reassigned.');
        }
        $person = HesabfaFreightPerson::normalizeCode($person);
        if ($person==='') { throw new InvalidArgumentException('The replacement carrier has no accounting contact mapping.'); }
        $payload = array_intersect_key($invoice,array_flip(self::FIELDS));
        $payload['FreightPersonCode'] = $person;
        $payload['InvoiceItems'] = array();
        foreach ($invoice['InvoiceItems'] as $item) {
            $row = array_intersect_key($item,array_flip(self::ITEM_FIELDS));
            // invoice/get may return ItemCode=null while Item.Code owns the actual item.
            $row['ItemCode'] = self::itemCode($item);
            if ($row['ItemCode']==='') { throw new InvalidArgumentException('The invoice item identity could not be resolved.'); }
            $payload['InvoiceItems'][] = $row;
        }
        $expected = $invoice;
        $expected['FreightPersonCode'] = $person;
        return array('payload'=>$payload,'before'=>$invoice,'after'=>$expected);
    }

    public static function same(array $expected,array $actual)
    {
        return self::canonical($expected)===self::canonical($actual);
    }

    private static function canonical(array $invoice)
    {
        $out = array();
        foreach (array_merge(self::FIELDS,array('Id','Sum','Payable','Paid','Rest','Returned')) as $key) {
            $value = $invoice[$key] ?? '';
            if (in_array($key,array('Freight','SalesmanPercent','CurrencyRate','Sum','Payable','Paid','Rest'),true)) {
                $value = self::number($value);
            } elseif (in_array($key,array('Number','Id','Status','InvoiceType','WarehouseReceiptStatus'),true)) {
                $value = (string)(int)$value;
            } elseif (in_array($key,array('ContactCode','FreightPersonCode','SalesmanCode'),true)) {
                $value = ltrim((string)$value,'0');
            } elseif (in_array($key,array('Sent','Returned'),true)) {
                $value = (bool)$value;
            } elseif ($key==='Others') {
                $value = array_map(static function($row) {
                    return array((string)($row['Title'] ?? ''),self::number($row['Amount'] ?? 0),(bool)($row['Add'] ?? false));
                },is_array($value)?$value:array());
            } elseif ($key==='Tag') {
                $parsed = is_string($value)?json_decode($value,true):$value;
                $value = is_array($parsed)?$parsed:(string)$value;
                if (is_array($value)) { ksort($value); }
            } else { $value = (string)$value; }
            $out[$key] = $value;
        }
        $out['InvoiceItems'] = array();
        foreach ($invoice['InvoiceItems'] ?? array() as $item) {
            $row = array();
            foreach (self::ITEM_FIELDS as $key) {
                $value = $item[$key] ?? '';
                if (in_array($key,array('Quantity','UnitPrice','Discount','Tax'),true)) { $value = self::number($value); }
                elseif ($key==='SerialNumbers') { $value = is_array($value)?array_values($value):array(); }
                elseif ($key==='ItemCode') { $value = ltrim(self::itemCode($item),'0'); }
                else { $value = (string)$value; }
                $row[$key] = $value;
            }
            $out['InvoiceItems'][] = $row;
        }
        return $out;
    }

    private static function number($value)
    {
        if (!is_numeric($value) || !is_finite((float)$value)) { throw new InvalidArgumentException('Invalid invoice amount.'); }
        return number_format((float)$value,6,'.','');
    }

    private static function itemCode(array $item)
    {
        $code=$item['ItemCode'] ?? '';
        if ((string)$code==='') { $code=$item['Item']['Code'] ?? ''; }
        return (string)$code;
    }
}
