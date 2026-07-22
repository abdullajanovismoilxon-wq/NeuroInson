<?php
require_once __DIR__ . '/env.php';

function verifyTelegramInitData($initData) {
    $botToken = loadEnv('BOT_TOKEN');
    if (!$botToken) return false;

    $params = [];
    parse_str($initData, $params);
    if (!isset($params['hash'])) return false;

    $hash = $params['hash'];
    unset($params['hash']);

    ksort($params);
    $checkString = '';
    foreach ($params as $key => $value) {
        $checkString .= $key . '=' . $value . "\n";
    }
    $checkString = rtrim($checkString, "\n");

    $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
    $expectedHash = bin2hex(hash_hmac('sha256', $checkString, $secretKey, true));

    return hash_equals($expectedHash, $hash);
}
