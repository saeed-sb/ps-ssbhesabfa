<?php

trait HesabfaSyncTrait
{
    public function setChangeHook()
    {
        $store_url = $this->context->link->getBaseLink();
        $webhookToken = Configuration::get('SSBHESABFA_WEBHOOK_TOKEN');
        if (!$webhookToken) {
            $webhookToken = bin2hex(openssl_random_pseudo_bytes(32));
            Configuration::updateValue('SSBHESABFA_WEBHOOK_TOKEN', $webhookToken);
        }
        $url = $store_url . 'modules/ssbhesabfa/ssbhesabfa-webhook.php?token=' . urlencode($webhookToken);
        $hookPassword = Configuration::get('SSBHESABFA_WEBHOOK_PASSWORD');

        $hesabfa = new HesabfaApi();
        $response = $hesabfa->settingSetChangeHook($url, $hookPassword);

        //ToDo: implement with try and catch
        if (is_object($response)) {
            if ($response->Success) {
                Configuration::updateValue('SSBHESABFA_LIVE_MODE', 1);

                //set the last log ID
                $lastChange = Configuration::get('SSBHESABFA_LAST_LOG_CHECK_ID');
                $changes = $hesabfa->settingGetChanges($lastChange);
                if ($changes->Success) {
                    if (Configuration::get('SSBHESABFA_LAST_LOG_CHECK_ID') == 0) {
                        $lastChange = end($changes->Result);
                        Configuration::updateValue('SSBHESABFA_LAST_LOG_CHECK_ID', $lastChange->Id);
                    }
                } else {
                    $msg = 'Failed to check the latest Hesabfa change ID. Details: ' . $changes->ErrorMessage;
                    self::addLegacyLog($msg, 2, $changes->ErrorCode, 'Webhook', null, true);
                }

                //set the Hesabfa default currency
                $default_currency = $hesabfa->settingGetCurrency();
                if ($default_currency->Success) {
                    $id_currency = Currency::getIdByIsoCode($default_currency->Result->Currency);
                    if ($id_currency > 0) {
                        Configuration::updateValue('SSBHESABFA_HESABFA_DEFAULT_CURRENCY', $id_currency);
                    } elseif (_PS_VERSION_ > 1.7) {
                        $currency = new Currency();
                        $currency->iso_code = $default_currency->Result->Currency;

                        if ($currency->add()) {
                            Configuration::updateValue('SSBHESABFA_HESABFA_DEFAULT_CURRENCY', $currency->id);

                            $msg = 'Hesabfa default currency ('. $default_currency->Result->Currency .') was added to the online store';
                            self::addLegacyLog($msg, 1, null, 'Webhook', null, true);
                        }
                    }
                } else {
                    $msg = 'Failed to check the Hesabfa default currency. Details: ' . $default_currency->ErrorMessage;
                    self::addLegacyLog($msg, 2, $default_currency->ErrorCode, 'Webhook', null, true);
                }

                //set the Gift wrapping service id
                if (Configuration::get('SSBHESABFA_ITEM_GIFT_WRAPPING_ID') == 0) {
                    $hesabfa = new HesabfaApi();
                    $gift_wrapping = $hesabfa->itemSave(array(
                        'Name' => 'Gift wrapping service',
                        'ItemType' => 1,
                        'Tag' => json_encode(array('id_product' => 0, 'id_attribute' => 0)),
                    ));

                    if ($gift_wrapping->Success) {
                        Configuration::updateValue('SSBHESABFA_ITEM_GIFT_WRAPPING_ID', $gift_wrapping->Result->Code);

                        $msg = 'Hesabfa gift wrapping service was added successfully. Service code: ' . $gift_wrapping->Result->Code;
                        self::addLegacyLog($msg, 1, null, 'Webhook', null, true);
                    } else {
                        $msg = 'Failed to set the Hesabfa gift wrapping service code. Details: ' . $gift_wrapping->ErrorMessage;
                        self::addLegacyLog($msg, 2, $gift_wrapping->ErrorCode, 'Webhook', null, true);
                    }
                }

                $msg = 'Hesabfa webhook was configured successfully. URL: ' . (string)$response->Result->url;
                self::addLegacyLog($msg, 1, null, 'Webhook', null, true);
            } else {
                Configuration::updateValue('SSBHESABFA_LIVE_MODE', 0);

                $msg = 'Failed to configure the Hesabfa webhook. Details: ' . $response->ErrorMessage;
                self::addLegacyLog($msg, 2, $response->ErrorCode, 'Webhook', null, true);
            }
        } else {
            $msg = 'Failed to configure the Hesabfa webhook. Please check the internet connection.';
            self::addLegacyLog($msg, 2, null, 'Webhook', null, true);
        }

        return $response;
    }

    public function isDateInFiscalYear($date)
    {
        $fiscalYear = $this->getCachedFiscalYear();

        if (!is_object($fiscalYear) || !isset($fiscalYear->Success) || !$fiscalYear->Success || !isset($fiscalYear->Result)) {
            return true;
        }

        $startDate = isset($fiscalYear->Result->StartDate) ? $fiscalYear->Result->StartDate : null;
        $endDate = isset($fiscalYear->Result->EndDate) ? $fiscalYear->Result->EndDate : null;

        if (empty($startDate) || empty($endDate)) {
            return true;
        }

        $fiscalYearStartTimeStamp = strtotime($startDate);
        $fiscalYearEndTimeStamp = strtotime($endDate);
        $dateTimeStamp = strtotime($date);

        return ($dateTimeStamp >= $fiscalYearStartTimeStamp && $dateTimeStamp <= $fiscalYearEndTimeStamp);
    }

    public function setItems($id_product_array)
    {
        if (!is_array($id_product_array)) {
            return false;
        }

        $idProducts = array();
        foreach ($id_product_array as $idProduct) {
            $idProduct = (int) $idProduct;
            if ($idProduct > 0) {
                $idProducts[] = $idProduct;
            }
        }

        $idProducts = array_values(array_unique($idProducts));
        if (empty($idProducts)) {
            return true;
        }

        sort($idProducts, SORT_NUMERIC);
        $locks = $this->acquireProductSyncLocks($idProducts);
        if ($locks === false) {
            self::addLegacyLog(
                'Product sync was stopped because its cross-process lock could not be acquired.',
                3,
                'PRODUCT_SYNC_LOCK_FAILED',
                'Product',
                implode(',', $idProducts),
                true
            );
            return false;
        }

        try {
            $items = $this->buildHesabfaItems($idProducts);
            if ($items === false) {
                return false;
            }

            // A mapping may have been lost while the Hesabfa item still exists.
            // Recover it before sending Code=null, otherwise Hesabfa creates a new item.
            if (!$this->recoverMissingItemMappings($items)) {
                return false;
            }

            return $this->saveItems($items);
        } catch (Exception $e) {
            self::addLegacyLog(
                'Product sync failed inside the protected section. Details: ' . $e->getMessage(),
                3,
                'PRODUCT_SYNC_PROTECTED_SECTION_FAILED',
                'Product',
                implode(',', $idProducts),
                true
            );
            return false;
        } finally {
            $this->releaseProductSyncLocks($locks);
        }
    }

    private function buildHesabfaItems(array $idProducts)
    {
        $items = array();

        foreach ($idProducts as $idProduct) {
            $product = new Product((int) $idProduct);
            if (!Validate::isLoadedObject($product)) {
                self::addLegacyLog(
                    'Product sync was stopped because the PrestaShop product could not be loaded.',
                    3,
                    'PRODUCT_SYNC_PRODUCT_NOT_FOUND',
                    'Product',
                    (int) $idProduct,
                    true
                );
                return false;
            }

            $itemType = ((int) $product->is_virtual === 1 ? 1 : 0);
            $item = array(
                'Code' => $this->getItemCodeByProductId((int) $idProduct, 0),
                'Name' => mb_substr($product->name[$this->id_default_lang], 0, 99),
                'ItemType' => $itemType,
                'SellPrice' => $product->price * 10,
                'Tag' => json_encode(array('id_product' => (int) $idProduct, 'id_attribute' => 0)),
                'Active' => $product->active ? true : false,
                'NodeFamily' => $this->getCategoryPathForExport($product->id_category_default),
                'ProductCode' => (int) $idProduct,
            );

            if (!Configuration::get('SSBHESABFA_ITEM_UPDATE_PRICE')) {
                $item['SellPrice'] = $this->getPriceInHesabfaDefaultCurrency($product->price);
            }

            $items[] = $item;

            if ($product->hasAttributes() > 0) {
                $combinations = $product->getAttributesResume($this->id_default_lang);
                if (!is_array($combinations)) {
                    continue;
                }

                foreach ($combinations as $combination) {
                    $idAttribute = (int) $combination['id_product_attribute'];
                    $item = array(
                        'Code' => $this->getItemCodeByProductId((int) $idProduct, $idAttribute),
                        'Name' => mb_substr(
                            $product->name[$this->id_default_lang] . ' - ' . $combination['attribute_designation'],
                            0,
                            99
                        ),
                        'ItemType' => $itemType,
                        'Tag' => json_encode(array(
                            'id_product' => (int) $idProduct,
                            'id_attribute' => $idAttribute,
                        )),
                        'Active' => $product->active ? true : false,
                        'NodeFamily' => $this->getCategoryPathForExport($product->id_category_default),
                        'ProductCode' => (int) $idProduct,
                    );

                    if (!Configuration::get('SSBHESABFA_ITEM_UPDATE_PRICE')) {
                        $item['SellPrice'] = $this->getPriceInHesabfaDefaultCurrency(
                            $product->price + $combination['price']
                        );
                    }

                    $items[] = $item;
                }
            }
        }

        return $items;
    }

    private function recoverMissingItemMappings(array &$items)
    {
        $missingKeys = array();
        $productCodes = array();

        foreach ($items as $item) {
            if (!empty($item['Code'])) {
                continue;
            }

            $identity = $this->getProductIdentityFromTag(isset($item['Tag']) ? $item['Tag'] : null);
            if ($identity === false) {
                self::addLegacyLog(
                    'Product sync was stopped because an outbound item has an invalid Tag.',
                    3,
                    'PRODUCT_SYNC_INVALID_TAG',
                    'Product',
                    null,
                    true
                );
                return false;
            }

            $key = $identity['id_product'] . ':' . $identity['id_attribute'];
            $missingKeys[$key] = $identity;
            $productCodes[(string) $identity['id_product']] = (string) $identity['id_product'];
        }

        if (empty($missingKeys)) {
            return true;
        }

        $hesabfa = new HesabfaApi();
        $skip = 0;
        $take = 100;
        $candidates = array();
        $candidateCodes = array();

        do {
            $response = $hesabfa->itemGetItems(array(
                'take' => $take,
                'skip' => $skip,
                'sortBy' => 'Code',
                'sortDesc' => false,
                'filters' => array(
                    array(
                        'property' => 'ProductCode',
                        'operator' => 'in',
                        'value' => array_values($productCodes),
                    ),
                ),
            ));

            if (!is_object($response) || empty($response->Success) || !isset($response->Result)) {
                $details = is_object($response) && isset($response->ErrorMessage)
                    ? (string) $response->ErrorMessage
                    : 'No valid response was received.';
                $errorCode = is_object($response) && isset($response->ErrorCode)
                    ? (string) $response->ErrorCode
                    : 'PRODUCT_MAPPING_RECOVERY_FAILED';

                self::addLegacyLog(
                    'Product sync was stopped because existing Hesabfa items could not be checked. Details: ' . $details,
                    3,
                    $errorCode,
                    'Product',
                    implode(',', array_values($productCodes)),
                    true
                );
                return false;
            }

            $list = isset($response->Result->List) && is_array($response->Result->List)
                ? $response->Result->List
                : array();

            foreach ($list as $remoteItem) {
                if (!is_object($remoteItem) || empty($remoteItem->Code)) {
                    continue;
                }

                $identity = $this->getProductIdentityFromTag(
                    isset($remoteItem->Tag) ? $remoteItem->Tag : null
                );
                if ($identity === false) {
                    continue;
                }

                $key = $identity['id_product'] . ':' . $identity['id_attribute'];
                if (!isset($missingKeys[$key])) {
                    continue;
                }

                $code = (int) $remoteItem->Code;
                if ($code <= 0) {
                    continue;
                }

                if (!isset($candidateCodes[$key])) {
                    $candidateCodes[$key] = array();
                }
                $candidateCodes[$key][$code] = $code;

                if (!isset($candidates[$key]) || $code < $candidates[$key]) {
                    $candidates[$key] = $code;
                }
            }

            $count = count($list);
            $skip += $count;
            $filteredCount = isset($response->Result->FilteredCount)
                ? (int) $response->Result->FilteredCount
                : $skip;

            if ($skip >= 10000 && $skip < $filteredCount) {
                self::addLegacyLog(
                    'Product sync was stopped because the mapping recovery result exceeded its safety limit.',
                    3,
                    'PRODUCT_MAPPING_RECOVERY_LIMIT',
                    'Product',
                    implode(',', array_values($productCodes)),
                    true
                );
                return false;
            }
        } while ($count > 0 && $skip < $filteredCount);

        foreach ($items as $index => $item) {
            if (!empty($item['Code'])) {
                continue;
            }

            $identity = $this->getProductIdentityFromTag($item['Tag']);
            $key = $identity['id_product'] . ':' . $identity['id_attribute'];
            if (!isset($candidates[$key])) {
                continue;
            }

            $code = (int) $candidates[$key];
            if (!HesabfaMappingRepository::upsert(
                'product',
                $identity['id_product'],
                $code,
                $identity['id_attribute']
            )) {
                self::addLegacyLog(
                    'Product sync was stopped because a recovered Hesabfa mapping could not be saved. Item code: ' . $code,
                    3,
                    'PRODUCT_MAPPING_RECOVERY_SAVE_FAILED',
                    'Product',
                    $identity['id_product'],
                    true
                );
                return false;
            }

            $items[$index]['Code'] = $code;
            $codes = array_values($candidateCodes[$key]);
            sort($codes, SORT_NUMERIC);

            $message = 'Recovered missing Hesabfa product mapping. Item code: ' . $code;
            if (count($codes) > 1) {
                $message .= '. Duplicate candidates detected: ' . implode(',', $codes);
            }
            self::addLegacyLog(
                $message,
                count($codes) > 1 ? 2 : 1,
                count($codes) > 1 ? 'PRODUCT_DUPLICATE_CANDIDATES_FOUND' : null,
                'Product',
                $identity['id_product'],
                true
            );
        }

        return true;
    }

    private function saveItems($items)
    {
        if (empty($items)) {
            return true;
        }

        $hesabfa = new HesabfaApi();
        $response = $hesabfa->itemBatchSave($items);

        if (!is_object($response) || empty($response->Success) || !isset($response->Result) || !is_array($response->Result)) {
            $details = is_object($response) && isset($response->ErrorMessage)
                ? (string) $response->ErrorMessage
                : 'No valid response was received.';
            $errorCode = is_object($response) && isset($response->ErrorCode)
                ? $response->ErrorCode
                : 'PRODUCT_BATCH_SAVE_FAILED';

            self::addLegacyLog(
                'Failed to add or update Hesabfa items. Details: ' . $details,
                3,
                $errorCode,
                'Products',
                null,
                true
            );
            return false;
        }

        $success = true;
        foreach ($response->Result as $item) {
            if (!is_object($item) || empty($item->Code)) {
                $success = false;
                self::addLegacyLog(
                    'Hesabfa returned an invalid item while saving products.',
                    3,
                    'PRODUCT_BATCH_SAVE_INVALID_ITEM',
                    'Products',
                    null,
                    true
                );
                continue;
            }

            $identity = $this->getProductIdentityFromTag(isset($item->Tag) ? $item->Tag : null);
            if ($identity === false) {
                $success = false;
                self::addLegacyLog(
                    'Hesabfa returned an item with an invalid Tag while saving products. Item code: ' . (int) $item->Code,
                    3,
                    'PRODUCT_BATCH_SAVE_INVALID_TAG',
                    'Products',
                    null,
                    true
                );
                continue;
            }

            $previousCode = HesabfaMappingRepository::getHesabfaCode(
                'product',
                $identity['id_product'],
                $identity['id_attribute']
            );
            $code = (int) $item->Code;

            if (!HesabfaMappingRepository::upsert(
                'product',
                $identity['id_product'],
                $code,
                $identity['id_attribute']
            )) {
                $success = false;
                self::addLegacyLog(
                    'Hesabfa item was saved remotely, but its local mapping could not be persisted. Item code: ' . $code,
                    3,
                    'PRODUCT_MAPPING_PERSIST_FAILED',
                    'Product',
                    $identity['id_product'],
                    true
                );
                continue;
            }

            $msg = $previousCode === null
                ? 'Hesabfa item was added successfully. Item code: ' . $code
                : 'Hesabfa item was updated successfully. Item code: ' . $code;
            self::addLegacyLog($msg, 1, null, 'Product', $identity['id_product'], true);
        }

        return $success;
    }

    private function getProductIdentityFromTag($tag)
    {
        if (!is_string($tag) || trim($tag) === '') {
            return false;
        }

        $decoded = json_decode($tag, true);
        if (!is_array($decoded) || empty($decoded['id_product'])) {
            return false;
        }

        $idProduct = (int) $decoded['id_product'];
        $idAttribute = isset($decoded['id_attribute']) ? (int) $decoded['id_attribute'] : 0;
        if ($idProduct <= 0 || $idAttribute < 0) {
            return false;
        }

        return array(
            'id_product' => $idProduct,
            'id_attribute' => $idAttribute,
        );
    }

    private function acquireProductSyncLocks(array $idProducts)
    {
        $handles = array();
        $databaseName = defined('_DB_NAME_') ? (string) _DB_NAME_ : 'prestashop';
        $databasePrefix = defined('_DB_PREFIX_') ? (string) _DB_PREFIX_ : '';
        $namespace = sha1($databaseName . '|' . $databasePrefix);
        $directory = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR;

        foreach ($idProducts as $idProduct) {
            $path = $directory . 'ssbhesabfa-' . $namespace . '-product-' . (int) $idProduct . '.lock';
            $handle = @fopen($path, 'c');
            if ($handle === false || !@flock($handle, LOCK_EX)) {
                if (is_resource($handle)) {
                    @fclose($handle);
                }
                $this->releaseProductSyncLocks($handles);
                return false;
            }
            $handles[] = $handle;
        }

        return $handles;
    }

    private function releaseProductSyncLocks(array $handles)
    {
        for ($index = count($handles) - 1; $index >= 0; $index--) {
            if (!is_resource($handles[$index])) {
                continue;
            }
            @flock($handles[$index], LOCK_UN);
            @fclose($handles[$index]);
        }
    }

    public function getCategoryPathForExport($id_category)
    {
        $context = Context::getContext();
        $id_lang = (int) $context->language->id;
    
        $category = new Category((int) $id_category, $id_lang);
        $parents = $category->getParentsCategories($id_lang);
    
        $names = array();
    
        $id_category_home = (int) Configuration::get('PS_HOME_CATEGORY');
    
        foreach ($parents as $parent) {
            $parent_id = (int) $parent['id_category'];
            $parent_name = trim($parent['name']);
    
            // Remove Root and Home in all supported versions.
            if (
                $parent_id <= 1 ||
                $parent_id === $id_category_home ||
                Tools::strtolower($parent_name) === 'home'
            ) {
                continue;
            }
    
            $names[] = $parent_name;
        }
    
        $path = implode(' : ', array_reverse($names));
    
        return Configuration::get('SSBHESABFA_ITEM_ROOT_NODE') . ' ' . $path;
    }

    private function getBarcode($id_product, $id_attribute = 0)
    {
        if (!isset($id_product)) {
            return false;
        }

        $product = new Product($id_product);

        if ((int) Configuration::get('SSBHESABFA_ITEM_BARCODE') === 0) {
            return false;
        }

        if ($id_attribute == 0) {
            switch (Configuration::get('SSBHESABFA_ITEM_BARCODE')) {
                case 1:
                    return $product->reference;
                case 2:
                    return $product->upc;
                case 3:
                    return $product->ean13;
                case 4:
                    return $product->isbn;
            }
        } else {
            $product_attribute = $product->getAttributeCombinationsById($id_attribute, $this->id_default_lang);
            switch (Configuration::get('SSBHESABFA_ITEM_BARCODE')) {
                case 1:
                    return $product_attribute[0]['reference'];
                case 2:
                    return $product_attribute[0]['upc'];
                case 3:
                    return $product_attribute[0]['ean13'];
                case 4:
                    return $product_attribute[0]['isbn'];
            }
        }

        return false;
    }

    public function getItemCodeByProductId($id_product, $id_attribute = 0)
    {
        return HesabfaMappingRepository::getHesabfaCode('product', $id_product, $id_attribute);
    }

    public function getContactCodeByCustomerId($id_customer)
    {
        $code = HesabfaMappingRepository::getHesabfaCode('customer', $id_customer);
        return $code === null ? false : $code;
    }

    public function setContact($id_customer)
    {
        if (!isset($id_customer)) {
            return false;
        }

        $code = null;
        $tmp = $this->getContactCodeByCustomerId($id_customer);
        if ($tmp != false) {
            $code = $tmp;
        }

        $customer = new Customer($id_customer);

        //check if customer name is null
        $name = $customer->firstname . ' ' . $customer->lastname;
        if (empty($customer->firstname) && empty($customer->lastname)) {
            $name = 'Guest Customer';
        }

        $data = array (
            array(
                'Code' => $code,
                'Name' => $name,
                'FirstName' => $customer->firstname,
                'LastName' => $customer->lastname,
                'ContactType' => 1,
                'NodeFamily' => Configuration::get('SSBHESABFA_CONTACT_ROOT_NODE') . ' ' . Configuration::get('SSBHESABFA_CONTACT_NODE_FAMILY'),
                'Email' => $this->validEmail($customer->email) ? $customer->email : null,
                'Tag' => json_encode(array('id_customer' => $id_customer)),
                'Active' => $customer->active ? true : false,
                'Note' => 'Customer ID in OnlineStore: ' . $id_customer,
            )
        );

        $hesabfa = new HesabfaApi();
        $response = $hesabfa->contactBatchSave($data);

        if ($response->Success) {
            $obj = new HesabfaModel();
            $obj->id_hesabfa = (int)$response->Result[0]->Code;
            $obj->obj_type = 'customer';
            $obj->id_ps = $id_customer;
            if ($code == null) {
                $obj->add();
                $msg = 'Hesabfa contact was added successfully. Contact code: ' . $response->Result[0]->Code;
                self::addLegacyLog($msg, 1, null, 'Customer', $id_customer, true);
            } else {
                $obj->id_ssb_hesabfa = $this->getObjectId('customer', $id_customer);
                $obj->update();
                $msg = 'Hesabfa contact was updated successfully. Contact code: ' . $response->Result[0]->Code;
                self::addLegacyLog($msg, 1, null, 'Customer', $id_customer, true);
            }
            return $response->Result[0]->Code;
        } else {
            $msg = 'Failed to add or update Hesabfa contact. Details: ' . $response->ErrorMessage;
            self::addLegacyLog($msg, 2, $response->ErrorCode, 'Customer', $id_customer, true);
            return false;
        }
    }

    public function setContactAddress($id_customer, $id_address)
    {
        if (!isset($id_customer) || !isset($id_address)) {
            return false;
        }

        $code = $this->getContactCodeByCustomerId($id_customer);
        if ($code === false || (int) $code <= 0) {
            HesabfaApiResponse::normalize((object) array(
                'Success' => false,
                'ErrorCode' => 'CUSTOMER_SYNC_PENDING',
                'ErrorMessage' => 'Customer mapping is not available yet. Address synchronization must wait for customer synchronization.',
            ));
            self::addLegacyLog(
                'Hesabfa contact address synchronization was deferred until the customer mapping is available.',
                1,
                'CUSTOMER_SYNC_PENDING',
                'Customer',
                $id_customer
            );
            return false;
        }

        $customer = new Customer($id_customer);
        $address = new Address($id_address);

        $PostalCode = mb_substr(preg_replace("/[^0-9]/", '', $address->postcode), 0, 10);
        $data = array (
            array(
                'Code' => (int)$code,
                'Name' => $customer->firstname . ' ' . $customer->lastname,
                'FirstName' => $customer->firstname,
                'LastName' => $customer->lastname,
                'ContactType' => 1,
                'NationalCode' => $address->dni,
                'EconomicCode' => $address->vat_number,
                'Address' => $address->address1 . ' ' . $address->address2,
                'City' => $address->city,
                'State' => State::getNameById($address->id_state)  == false ? null : State::getNameById($address->id_state),
                'Country' => Country::getNameById($this->context->language->id, $address->id_country) == false ? null : Country::getNameById($this->context->language->id, $address->id_country),
                'PostalCode' => $PostalCode,
                'Phone' => preg_replace("/[^0-9]/", "", $address->phone),
                'Mobile' => preg_replace("/[^0-9]/", "", $address->phone_mobile),
                'Email' => $this->validEmail($customer->email) ? $customer->email : null,
                'Tag' => json_encode(array('id_customer' => $id_customer)),
            )
        );

        $hesabfa = new HesabfaApi();
        $response = $hesabfa->contactBatchSave($data);

        if ($response->Success) {
            $msg = 'Hesabfa contact address was updated successfully. Contact code: ' . $response->Result[0]->Code;
            self::addLegacyLog($msg, 1, null, 'Customer', $id_customer);
            return true;
        } else {
            $msg = 'Failed to add or update Hesabfa contact address. Details: ' . $response->ErrorMessage;
            self::addLegacyLog($msg, 2, $response->ErrorCode, 'Customer', $id_customer);
            return false;
        }
    }

    public function validEmail($email)
    {
        $isValid = true;
        $atIndex = strrpos($email, "@");
        if (is_bool($atIndex) && !$atIndex) {
            $isValid = false;
        } else {
            $domain = Tools::substr($email, $atIndex+1);
            $local = Tools::substr($email, 0, $atIndex);
            $localLen = Tools::strlen($local);
            $domainLen = Tools::strlen($domain);
            if ($localLen < 1 || $localLen > 64) {
                // local part length exceeded
                $isValid = false;
            } else if ($domainLen < 1 || $domainLen > 255) {
                // domain part length exceeded
                $isValid = false;
            } else if ($local[0] == '.' || $local[$localLen-1] == '.') {
                // local part starts or ends with '.'
                $isValid = false;
            } else if (preg_match('/\\.\\./', $local)) {
                // local part has two consecutive dots
                $isValid = false;
            } else if (!preg_match('/^[A-Za-z0-9\\-\\.]+$/', $domain)) {
                // character not valid in domain part
                $isValid = false;
            } else if (preg_match('/\\.\\./', $domain)) {
                // domain part has two consecutive dots
                $isValid = false;
            } else if (!preg_match('/^(\\\\.|[A-Za-z0-9!#%&`_=\\/$\'*+?^{}|~.-])+$/', str_replace("\\\\", "", $local))) {
                // character not valid in local part unless
                // local part is quoted
                if (!preg_match('/^"(\\\\"|[^"])+"$/', str_replace("\\\\", "", $local))) {
                    $isValid = false;
                }
            }
//            if ($isValid && !(checkdnsrr($domain,"MX") || checkdnsrr($domain,"A")))
//            {
//                // domain not found in DNS
//                $isValid = false;
//            }
        }
        return $isValid;
    }
    public function exportProducts()
    {
        $result=(new HesabfaExportBatchService($this))->runAjax('products',false);
        return !empty($result['success']) ? true : (isset($result['message'])?$result['message']:false);
    }


    public function setOpeningQuantity()
    {
        $lastProductId = (int) Configuration::get('SSBHESABFA_OPENING_PRODUCTS_LAST_ID');
        $products = HesabfaPrestashopRepository::getProductIdsAfter($lastProductId, self::HESABFA_BATCH_SIZE);
        if (!is_array($products) || empty($products)) {
            Configuration::updateValue('SSBHESABFA_OPENING_PRODUCTS_LAST_ID', 0);
            self::addLegacyLog('Opening quantity batch completed. No more products to process.', 1, null, 'Product', null, true);
            return true;
        }

        $items = array();
        $lastProcessedProductId = 0;

        foreach ($products as $item) {
            $lastProcessedProductId = max($lastProcessedProductId, (int) $item['id_product']);
            $product = new Product($item['id_product']);

            if ($product->hasAttributes() == 0) {
                //do if product exists in hesabfa
                $id_obj = $this->getObjectId('product', $item['id_product'], 0);
                if ($id_obj > 0) {
                    $obj = new HesabfaModel($id_obj);
                    $quantity = StockAvailable::getQuantityAvailableByProduct($item['id_product']);

                    if (is_object($product) && is_object($obj) && $quantity > 0 && $product->price > 0) {
                        array_push($items, array(
                            'Code' => $obj->id_hesabfa,
                            'Quantity' => $quantity,
                            'UnitPrice' => $this->getPriceInHesabfaDefaultCurrency($product->price),
                        ));
                    }
                }
            } else {
                //Combinations
                $combinations = $product->getAttributesResume($this->id_default_lang);

                foreach ($combinations as $combination) {
                    $id_obj = $this->getObjectId('product', $item['id_product'], $combination['id_product_attribute']);
                    if ($id_obj > 0) {
                        $obj = new HesabfaModel($id_obj);
                        $quantity = StockAvailable::getQuantityAvailableByProduct($item['id_product'], $combination['id_product_attribute']);

                        if (is_object($obj) && $quantity > 0 && $product->price + $combination['price'] > 0) {
                            array_push($items, array(
                                'Code' => $obj->id_hesabfa,
                                'Quantity' => $quantity,
                                'UnitPrice' => $this->getPriceInHesabfaDefaultCurrency($product->price + $combination['price']),
                            ));
                        }
                    }
                }
            }
        }

        if ($lastProcessedProductId > 0) {
            Configuration::updateValue('SSBHESABFA_OPENING_PRODUCTS_LAST_ID', $lastProcessedProductId);
        }

        //call API when at least one product exists
        if (!empty($items)) {
            $hesabfa = new HesabfaApi();
            $response = $hesabfa->itemUpdateOpeningQuantity($items);
            if ($response->Success) {
                $msg = 'Opening quantity was added successfully.';
                self::addLegacyLog($msg, 1, null, 'Product', null, true);

                return true;
            } else {
                $msg = 'Failed to set opening quantity. Details: ' . $response->ErrorMessage;
                self::addLegacyLog($msg, 2, $response->ErrorCode, 'Product', null, true);

                return $msg . ' Error Code: ' . $response->ErrorCode;
            }
        } else {
            $msg = 'No product is available for opening quantity sync.';
            self::addLegacyLog($msg, 2, null, 'Product', null, true);

            return $msg;
        }
    }
    public function exportCustomers()
    {
        $result=(new HesabfaExportBatchService($this))->runAjax('customers',false);
        return !empty($result['success']) ? true : (isset($result['message'])?$result['message']:false);
    }
    public function ajaxExportBatch($type, $reset = false)
    {
        return (new HesabfaExportBatchService($this))->runAjax((string)$type,(bool)$reset);
    }


    public function syncOrders($from_date)
    {
        if (!isset($from_date)) {
            return false;
        }

        if (!$this->isDateInFiscalYear($from_date)) {
            return $this->l('The date entered is not within the fiscal year.');
        }

        $orders = Order::getOrdersIdByDate($from_date, date('Y-m-d h:i:s'));
        if (is_array($orders)) {
            $orders = array_slice($orders, 0, self::HESABFA_BATCH_SIZE);
        }
        $id_orders = array();
        foreach ($orders as $id_order) {
            $id_obj = $this->getObjectId('order', $id_order);
            if (!$id_obj) {
                if ($this->setOrder($id_order)) {
                    $this->setOrderPayment($id_order);
                    array_push($id_orders, $id_order);
                }

                $order = new Order($id_order);
                if ($order->current_state == Configuration::get('SSBHESABFA_INVOICE_RETURN_STATUS')) {
                    $obj = new HesabfaModel($id_obj);
                    $this->setOrder($id_order, 2, $obj->id_hesabfa);
                }
            }
        }

        return $id_orders;
    }

    public function syncProducts()
    {
        $skip = (int) Configuration::get('SSBHESABFA_SYNC_ITEMS_SKIP');
        $hesabfa = new HesabfaApi();
        $response = $hesabfa->itemGetItems(array('Take' => self::HESABFA_BATCH_SIZE, 'Skip' => $skip));
        if ($response->Success) {
            $products = $response->Result->List;
            require_once(_PS_MODULE_DIR_ . 'ssbhesabfa/classes/HesabfaWebhook.php');
            foreach ($products as $item) {
                HesabfaWebhook::setItemChanges($item, false, true);
            }

            if (count($products) >= self::HESABFA_BATCH_SIZE) {
                Configuration::updateValue('SSBHESABFA_SYNC_ITEMS_SKIP', $skip + self::HESABFA_BATCH_SIZE);
            } else {
                Configuration::updateValue('SSBHESABFA_SYNC_ITEMS_SKIP', 0);
            }
        } else {
            $msg = 'Failed to get Hesabfa items. Details: ' . $response->ErrorMessage;
            self::addLegacyLog($msg, 2, $response->ErrorCode, 'Product', null, true);
            return false;
        }
    }
}
