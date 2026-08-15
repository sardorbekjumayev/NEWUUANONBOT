<?php

declare(strict_types=1);

$root = dirname(__DIR__);

spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'PUAnonymous\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relativeClass = substr($class, strlen($prefix));
    $file = $root . '/src/' . str_replace('\\', '/', $relativeClass) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

$loadEnv = static function (string $path): void {
    if (!is_file($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $key = trim($parts[0]);
            $val = trim($parts[1]);
            if ((str_starts_with($val, '"') && str_ends_with($val, '"')) ||
                (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
                $val = substr($val, 1, -1);
            }
            if (!array_key_exists($key, $_SERVER) && !array_key_exists($key, $_ENV)) {
                putenv("{$key}={$val}");
                $_ENV[$key] = $val;
                $_SERVER[$key] = $val;
            }
        }
    }
};

$loadEnv($root . '/.env');

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
    'ai_enabled' => filter_var($env('AI_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN),
    'ai_provider' => strtolower((string) $env('AI_PROVIDER', 'gemini')),
    'gemini_api_key' => (string) $env('GEMINI_API_KEY', ''),
    'gemini_model' => (string) $env('GEMINI_MODEL', 'gemini-2.5-flash-lite'),
    'groq_api_key' => (string) $env('GROQ_API_KEY', ''),
    'groq_model' => (string) $env('GROQ_MODEL', 'llama-3.1-8b-instant'),
    'admin_ids' => $csv($env('ADMIN_IDS', '')),
    'webhook_url' => (string) $env('WEBHOOK_URL', ''),
    'webhook_secret' => (string) $env('WEBHOOK_SECRET', ''),
    'app_secret' => (string) $env('APP_SECRET', ''),
    'log_message_content' => filter_var($env('LOG_MESSAGE_CONTENT', 'false'), FILTER_VALIDATE_BOOLEAN),
    'rate_limit_per_minute' => max(1, (int) $env('RATE_LIMIT_PER_MINUTE', '6')),
    'max_text_length' => max(100, (int) $env('MAX_TEXT_LENGTH', '3500')),
    'max_caption_length' => max(100, (int) $env('MAX_CAPTION_LENGTH', '600')),
];
