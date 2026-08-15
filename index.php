<?php

declare(strict_types=1);

use PUAnonymous\AI;
use PUAnonymous\AdminManager;
use PUAnonymous\AdminView;
use PUAnonymous\AdminWebApp;
use PUAnonymous\Bot;
use PUAnonymous\Helpers;
use PUAnonymous\Telegram;
use PUAnonymous\Wordlist;

$config = require __DIR__ . '/config/config.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// 1. Health check endpoint
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

$adminManager = new AdminManager();
$wordlist = new Wordlist();
$adminWebApp = new AdminWebApp($config, $adminManager, $wordlist);

// 2. Admin API Endpoints (/api/admin/*)
if (str_starts_with($path, '/api/admin/')) {
    $adminWebApp->handleApiRequest($path);
    return;
}

// 3. Admin Web App Interface (/admin or ?app=admin)
if ($path === '/admin' || isset($_GET['app']) && $_GET['app'] === 'admin') {
    AdminView::render($config);
    return;
}

// 4. Fallback GET request handler
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "PU Anonymous webhook is ready.\n";
    return;
}

// 5. Telegram Webhook Verification & Handling
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

ob_start();
http_response_code(200);
header('Content-Type: application/json');
header('Connection: close');
header('Content-Length: 12');
echo '{"ok":true}';
ob_end_flush();

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    @ob_flush();
    @flush();
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
    new AI(
        (bool) ($config['gemini_enabled'] ?? false),
        (string) ($config['gemini_api_key'] ?? ''),
        (string) ($config['gemini_model'] ?? 'gemini-2.5-flash-lite'),
        (bool) ($config['groq_enabled'] ?? false),
        (string) ($config['groq_api_key'] ?? ''),
        (string) ($config['groq_model'] ?? 'llama-3.1-8b-instant')
    ),
    $adminManager,
    $wordlist
);

$bot->handle($update);
