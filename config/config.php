<?php

declare(strict_types=1);

use Dotenv\Dotenv;

$root = dirname(__DIR__);

if (is_file($root . '/vendor/autoload.php')) {
    require_once $root . '/vendor/autoload.php';
}

if (class_exists(Dotenv::class) && is_file($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

$env = static fn (string $key, ?string $default = null): ?string => $_ENV[$key] ?? getenv($key) ?: $default;

$csv = static function (?string $value): array {
    if ($value === null || trim($value) === '') {
        return [];
    }

    return array_values(array_filter(array_map(
        static fn (string $item): string => trim($item),
        explode(',', $value)
    )));
};

return [
    'bot_token' => (string) $env('BOT_TOKEN', ''),
    'bot_username' => ltrim((string) $env('BOT_USERNAME', ''), '@'),
    'channel_id' => (string) $env('CHANNEL_ID', ''),
    'moderation_group_id' => (string) $env('MODERATION_GROUP_ID', ''),
    'discussion_group_id' => (string) $env('DISCUSSION_GROUP_ID', ''),
    'gemini_api_key' => (string) $env('GEMINI_API_KEY', ''),
    'gemini_model' => (string) $env('GEMINI_MODEL', 'gemini-2.5-flash-lite'),
    'admin_ids' => $csv($env('ADMIN_IDS', '')),
    'webhook_url' => (string) $env('WEBHOOK_URL', ''),
    'webhook_secret' => (string) $env('WEBHOOK_SECRET', ''),
    'app_secret' => (string) $env('APP_SECRET', ''),
    'log_message_content' => filter_var($env('LOG_MESSAGE_CONTENT', 'false'), FILTER_VALIDATE_BOOLEAN),
    'rate_limit_per_minute' => max(1, (int) $env('RATE_LIMIT_PER_MINUTE', '6')),
    'max_text_length' => max(100, (int) $env('MAX_TEXT_LENGTH', '3500')),
    'max_caption_length' => max(100, (int) $env('MAX_CAPTION_LENGTH', '600')),
];
