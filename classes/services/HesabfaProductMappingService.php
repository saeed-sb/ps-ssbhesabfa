<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class HesabfaProductMappingService
{
    protected $module;

    public function __construct($module)
    {
        $this->module = $module;
    }

    public function syncFromAdminRequest($idProduct, Product $product)
    {
        $result = array(
            'has_input' => false,
            'success' => true,
            'messages' => array(),
            'errors' => array(),
            'removed' => false,
        );

        $this->processField($result, (int) $idProduct, 0, 'ssbhesabfa_hesabfa_item_code_0', $this->module->l('Base product', 'ssbhesabfa'));

        if ($product->hasAttributes() > 0) {
            $combinations = $product->getAttributesResume((int) $this->module->id_default_lang);
            if (is_array($combinations)) {
                foreach ($combinations as $combination) {
                    $idAttribute = (int) $combination['id_product_attribute'];
                    $label = isset($combination['attribute_designation']) && $combination['attribute_designation'] !== ''
                        ? (string) $combination['attribute_designation']
                        : $this->module->l('Combination', 'ssbhesabfa') . ' #' . $idAttribute;
                    $this->processField(
                        $result,
                        (int) $idProduct,
                        $idAttribute,
                        'ssbhesabfa_hesabfa_item_code_' . $idAttribute,
                        $label
                    );
                }
            }
        }

        return $result;
    }

    protected function processField(array &$result, $idProduct, $idAttribute, $fieldName, $label)
    {
        if (!Tools::getIsset($fieldName)) {
            return;
        }

        $result['has_input'] = true;
        $raw = trim($this->normalizeDigits((string) Tools::getValue($fieldName, '')));
        $current = HesabfaMappingRepository::getProductMappingRow((int) $idProduct, (int) $idAttribute);

        if ($raw === '') {
            // Product forms can submit this field empty even when the operator did
            // not explicitly edit the mapping. Empty therefore means "unchanged".
            // Removing a mapping implicitly is unsafe because the next outbound
            // sync would create a second Hesabfa item.
            return;
        }

        if (!ctype_digit($raw) || (int) $raw <= 0) {
            $result['success'] = false;
            $result['errors'][] = sprintf($this->module->l('The Hesabfa item code for %s must be a positive integer.', 'ssbhesabfa'), $label);
            return;
        }

        $code = (int) $raw;
        $conflict = HesabfaMappingRepository::getProductMappingByHesabfaCode($code);
        if ($conflict && ((int) $conflict['id_ps'] !== (int) $idProduct || (int) $conflict['id_ps_attribute'] !== (int) $idAttribute)) {
            $result['success'] = false;
            $result['errors'][] = sprintf(
                $this->module->l('Hesabfa item code %1$s is already assigned to product %2$s, combination %3$s.', 'ssbhesabfa'),
                $code,
                (int) $conflict['id_ps'],
                (int) $conflict['id_ps_attribute']
            );
            return;
        }

        if ($current && (int) $current['id_hesabfa'] === $code) {
            return;
        }

        if (!HesabfaMappingRepository::upsert('product', (int) $idProduct, $code, (int) $idAttribute)) {
            $result['success'] = false;
            $result['errors'][] = sprintf($this->module->l('Could not save the Hesabfa mapping for %s.', 'ssbhesabfa'), $label);
            return;
        }

        $result['messages'][] = sprintf($this->module->l('The Hesabfa mapping for %s was saved.', 'ssbhesabfa'), $label);
    }

    protected function normalizeDigits($value)
    {
        $persianDigits = json_decode('["\\u06f0","\\u06f1","\\u06f2","\\u06f3","\\u06f4","\\u06f5","\\u06f6","\\u06f7","\\u06f8","\\u06f9"]', true);
        $arabicDigits = json_decode('["\\u0660","\\u0661","\\u0662","\\u0663","\\u0664","\\u0665","\\u0666","\\u0667","\\u0668","\\u0669"]', true);
        $latinDigits = array('0', '1', '2', '3', '4', '5', '6', '7', '8', '9');

        if (!is_array($persianDigits) || !is_array($arabicDigits)) {
            return (string) $value;
        }

        return str_replace(array_merge($persianDigits, $arabicDigits), array_merge($latinDigits, $latinDigits), (string) $value);
    }
}
