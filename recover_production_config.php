<?php
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

$token = (string) ($_GET['token'] ?? '');
if (!hash_equals('8a56f3b9231c42cc9fd82bd07709c99e', $token)) {
    http_response_code(404);
    exit('Not found');
}

$appRoot = __DIR__;
$recoveryRoot = $appRoot . '/sena_learning';
$source = $recoveryRoot . '/config/config.php';
$destination = $appRoot . '/config/private.php';

if (!is_file($destination)) {
    if (!is_file($source) || !copy($source, $destination)) {
        http_response_code(500);
        exit('Recovery failed');
    }
}

@chmod($destination, 0600);

$recoveryArchive = dirname($appRoot, 2) . '/tmp/sena_learning_recovery_20260817';
if (is_dir($recoveryRoot) && !file_exists($recoveryArchive)) {
    @rename($recoveryRoot, $recoveryArchive);
}

@unlink(__FILE__);
echo 'Recovery complete';
