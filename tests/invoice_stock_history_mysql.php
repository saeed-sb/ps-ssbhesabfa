<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

// Explicit shop bootstrap, used only to obtain the existing DB adapter.
// A connection-local TEMPORARY journal shadows the real table throughout.
$bootstrap = getenv('SSBHESABFA_TEST_BOOTSTRAP');
if (!$bootstrap || !is_file($bootstrap)) {
    fwrite(STDERR, "Set SSBHESABFA_TEST_BOOTSTRAP to a PrestaShop config.inc.php.\n");
    exit(2);
}
$host = getenv('SSBHESABFA_TEST_HOST');
if ($host) {
    $_SERVER['HTTP_HOST'] = $host;
    $_SERVER['SERVER_NAME'] = $host;
}
require $bootstrap;
require_once dirname(__DIR__) . '/classes/HesabfaWebhookChangeRepository.php';
require dirname(__DIR__) . '/upgrade/upgrade-2.3.44.php';
function stockHistoryCheck($ok, $message) { if (!$ok) { throw new Exception($message); } }
$db = Db::getInstance();
$table = _DB_PREFIX_ . 'ssb_hesabfa_webhook_change';
$schema = $db->executeS('SHOW CREATE TABLE `' . $table . '`', true, false);
stockHistoryCheck(is_array($schema) && isset($schema[0]['Create Table']), 'Cannot read journal structure');
$temporarySchema = preg_replace('/^CREATE TABLE/', 'CREATE TEMPORARY TABLE', $schema[0]['Create Table']);
stockHistoryCheck($db->execute($temporarySchema), 'Cannot create temporary journal');
try {
    stockHistoryCheck(upgrade_module_2_3_44((object) array('name' => 'ssbhesabfa')), 'Metadata migration failed');
    stockHistoryCheck(upgrade_module_2_3_44((object) array('name' => 'ssbhesabfa')), 'Repeated metadata migration failed');
    foreach (array(
        array(10, 482, '000001,000002'),
        array(20, 482, '000003'),
        array(21, 999, 'unrelated'),
        array(90, 482, 'future')
    ) as $row) {
        $change = (object) array('Id' => $row[0], 'ObjectType' => 'Invoice', 'ObjectId' => $row[1], 'Action' => 132, 'Extra' => $row[2]);
        stockHistoryCheck(HesabfaWebhookChangeRepository::save($change), 'Cannot seed temporary journal');
    }
    $codes = HesabfaWebhookChangeRepository::getInvoiceItemCodes(482, 20);
    stockHistoryCheck($codes === array('000003', '000001', '000002'), 'Historical union, scope or leading zeroes failed');
    stockHistoryCheck(HesabfaWebhookChangeRepository::saveInvoiceItemCodes(20, 482, $codes), 'Cannot persist real snapshot');
    // Refetching a change must preserve its locally observed item metadata.
    stockHistoryCheck(HesabfaWebhookChangeRepository::save((object) array(
        'Id' => 20, 'ObjectType' => 'Invoice', 'ObjectId' => 482, 'Action' => 132, 'Extra' => '000003'
    )), 'Cannot refetch change');
    stockHistoryCheck(HesabfaWebhookChangeRepository::getInvoiceItemCodes(482, 20) === $codes, 'Refetch discarded the durable snapshot');
    $new = (object) array('Id' => 30, 'ObjectType' => 'Invoice', 'ObjectId' => 482, 'Action' => 132, 'Extra' => '');
    stockHistoryCheck(HesabfaWebhookChangeRepository::save($new), 'Cannot seed empty edit');
    stockHistoryCheck(HesabfaWebhookChangeRepository::getInvoiceItemCodes(482, 30) === $codes, 'Empty edit lost historical items');
    stockHistoryCheck((int) $db->getValue('SELECT COUNT(*) FROM `' . $table . '`', false) === 5, 'Unexpected temporary journal size');
    echo "PASS: real MySQL upgrade twice, legacy history, invoice/event scope, durable snapshot, refetch preservation and empty edit\n";
} finally {
    $db->execute('DROP TEMPORARY TABLE IF EXISTS `' . $table . '`');
}
