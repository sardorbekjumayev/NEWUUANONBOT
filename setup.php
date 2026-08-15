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

if (($config['webhook_url'] ?? '') === '') {
    echo "WEBHOOK_URL is empty. Example: https://example.com/index.php\n";
    exit(1);
}

$telegram = new Telegram($config['bot_token']);
$result = $telegram->call('setWebhook', array_filter([
    'url' => $config['webhook_url'],
    'secret_token' => $config['webhook_secret'] ?: null,
    'allowed_updates' => ['message', 'callback_query'],
]));

if (($result['ok'] ?? false) !== true) {
    echo "Webhook setup failed: " . ($result['description'] ?? 'unknown error') . "\n";
    exit(1);
}

echo "Webhook configured successfully.\n";
echo "Health check: " . rtrim((string) $config['webhook_url'], '/') . "?health=1\n";
