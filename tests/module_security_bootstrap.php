<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

if (isset($argv[1]) && $argv[1] === '--child') {
    define('_PS_VERSION_', '8.1.7');
    define('_PS_MODULE_DIR_', $argv[2] . '/');
    class Module { public static function isEnabled($name) { return $name === 'ssbhesabfa'; } }
    class ObjectModel { const TYPE_INT = 1; const TYPE_STRING = 3; }
    class Shop { public static function getShops($active, $group, $ids) { return array(1); } }

    $module = _PS_MODULE_DIR_ . 'ssbhesabfa/ssbhesabfa.php';
    $security = _PS_MODULE_DIR_ . 'ssbhesabfa/classes/HesabfaSecurity.php';
    if ($argv[3] === 'endpoint-first') {
        // Both authenticated entrypoints preload this class before Module::getInstanceByName().
        require_once $security;
        require_once $module;
    } else {
        // The shop bootstrap may load the module before an entrypoint loads its boundary class.
        require_once $module;
        require_once $security;
    }
    if (!class_exists('Ssbhesabfa', false) || !HesabfaSecurity::isOperational()) {
        throw new RuntimeException('Module or operational security boundary did not load.');
    }
    if (HesabfaSecurity::cronToken(array('QUERY_STRING' => 'token=secret')) !== null
        || HesabfaSecurity::cronToken(array('HTTP_X_SSB_HESABFA_TOKEN' => 'secret')) !== 'secret'
        || !HesabfaSecurity::matchesSecret('secret', 'secret')
        || HesabfaSecurity::matchesSecret('secret', 'wrong')) {
        throw new RuntimeException('Bootstrap changed the authentication boundary.');
    }
    echo 'PASS: ' . $argv[3] . PHP_EOL;
    exit;
}

$fixture = sys_get_temp_dir() . '/ssbh-bootstrap-' . uniqid('', true);
if (!mkdir($fixture, 0700) || !symlink(dirname(__DIR__), $fixture . '/ssbhesabfa')) {
    throw new RuntimeException('Cannot create isolated module-path fixture.');
}
try {
    foreach (array('endpoint-first', 'module-first') as $order) {
        $output = array(); $status = 0;
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -d log_errors=0 ' . escapeshellarg(__FILE__)
            . ' --child ' . escapeshellarg($fixture) . ' ' . escapeshellarg($order)
            . ' 2>&1', $output, $status);
        if ($status !== 0) {
            throw new RuntimeException('Bootstrap failed: ' . $order . PHP_EOL . implode(PHP_EOL, $output));
        }
        echo implode(PHP_EOL, $output) . PHP_EOL;
    }
} finally {
    unlink($fixture . '/ssbhesabfa');
    rmdir($fixture);
}
