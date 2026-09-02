<?php

define('_PS_VERSION_', '8.1.7');

require_once dirname(__DIR__) . '/classes/HesabfaLogService.php';

$cases = array(
    array('DEBUG', 'DEBUG'),
    array('INFO', 'INFO'),
    array('WARNING', 'WARNING'),
    array('WARN', 'WARNING'),
    array('ERROR', 'ERROR'),
    array('CRITICAL', 'CRITICAL'),
    array(0, 'DEBUG'),
    array(1, 'INFO'),
    array(2, 'WARNING'),
    array(3, 'ERROR'),
    array(4, 'CRITICAL'),
    array(5, 'CRITICAL'),
    array('0', 'DEBUG'),
    array('1', 'INFO'),
    array('2', 'WARNING'),
    array('3', 'ERROR'),
    array('4', 'CRITICAL'),
);

foreach ($cases as $case) {
    $actual = HesabfaLogService::getLogLevelFromSeverity($case[0]);
    if ($actual !== $case[1]) {
        fwrite(STDERR, 'Failed for ' . var_export($case[0], true) . ': expected ' . $case[1] . ', got ' . $actual . PHP_EOL);
        exit(1);
    }
}

echo 'Hesabfa log-level mapping tests passed.' . PHP_EOL;
