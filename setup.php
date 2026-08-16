<?php

declare(strict_types=1);

use PUAnonymous\Telegram;

$config = require __DIR__ . '/config/config.php';

echo "=== PU Anonymous Stateless Bot Setup ===\n";
echo "Database: disabled\n";
echo "Webhook file: index.php\n";

if (($config['bot_token'] ?? '') === '') {
    echo "BOT_TOKEN is empty. Fill .env first.\n";
    exit(1);
}

$webhookUrl = trim((string) ($argv[1] ?? $config['webhook_url'] ?? ''));
if ($webhookUrl === '') {
    $webhookUrl = 'https://c869.coresuz.ru/NEWUUANONBOT/index.php';
}
if (str_starts_with($webhookUrl, 'http://')) {
    $webhookUrl = 'https://' . substr($webhookUrl, 7);
}
echo "🔗 Webhook URL: {$webhookUrl}\n";

// 1. Clear local cache and deduplication state
$dataDir = __DIR__ . '/data';
if (is_dir($dataDir)) {
    $tempFiles = glob($dataDir . '/*.tmp.*') ?: [];
    foreach ($tempFiles as $tmp) {
        if (is_file($tmp)) {
            @unlink($tmp);
        }
    }
    $seenFile = $dataDir . '/seen_updates.json';
    if (is_file($seenFile)) {
        @unlink($seenFile);
        echo "✅ Local seen_updates.json cache cleared.\n";
    }
}

// 2. Configure Telegram Webhook and drop stuck pending updates
$telegram = new Telegram($config['bot_token']);
$result = $telegram->call('setWebhook', array_filter([
    'url' => $webhookUrl,
    'secret_token' => $config['webhook_secret'] ?: null,
    'allowed_updates' => ['message', 'callback_query'],
    'drop_pending_updates' => true,
]));

if (($result['ok'] ?? false) !== true) {
    echo "❌ Webhook setup failed: " . ($result['description'] ?? 'unknown error') . "\n";
    exit(1);
}

echo "✅ Webhook configured successfully (pending updates cleared).\n";
echo "🔗 Health check: " . rtrim($webhookUrl, '/') . "?health=1\n";

