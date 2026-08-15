<?php

declare(strict_types=1);

use PUAnonymous\AI;
use PUAnonymous\Bot;
use PUAnonymous\Helpers;
use PUAnonymous\Telegram;

$config = require __DIR__ . '/config/config.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($path === '/health' || isset($_GET['health']))) {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'ok',
        'storage' => 'telegram',
        'database' => 'disabled',
        'timestamp' => date('c'),
    ]);
    return;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "PU Anonymous webhook is ready.\n";
    return;
}

if (($config['webhook_secret'] ?? '') !== '') {
    $header = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
    if (!hash_equals((string) $config['webhook_secret'], (string) $header)) {
        http_response_code(403);
        echo 'forbidden';
        return;
    }
}

$raw = file_get_contents('php://input') ?: '';
$update = json_decode($raw, true);

http_response_code(200);
header('Content-Type: application/json');
echo '{"ok":true}';

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

if (!is_array($update)) {
    return;
}

if (($config['bot_token'] ?? '') === '' || ($config['app_secret'] ?? '') === '') {
    Helpers::log('ERROR', 'Missing BOT_TOKEN or APP_SECRET');
    return;
}

$bot = new Bot(
    $config,
    new Telegram($config['bot_token']),
    new AI($config['gemini_api_key'], $config['gemini_model'])
);

$bot->handle($update);
