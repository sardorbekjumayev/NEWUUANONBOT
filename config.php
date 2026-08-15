<?php
// config.php - System Configuration Loader

declare(strict_types=1);

date_default_timezone_set('Asia/Tashkent');

// Load environment variables from .env if present
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (str_contains($line, '=')) {
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value, " \"'");
            if (!array_key_exists($key, $_ENV) && !array_key_exists($key, $_SERVER)) {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
    }
}

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed {
        $value = getenv($key);
        if ($value === false) {
            return $_ENV[$key] ?? $_SERVER[$key] ?? $default;
        }
        return match (strtolower((string)$value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            default => $value,
        };
    }
}

return [
    'app_name' => 'University Anonymous Community',
    'app_env' => env('APP_ENV', 'production'),
    'app_debug' => env('APP_DEBUG', false),
    'app_url' => env('APP_URL', 'http://localhost:8000'),
    'app_secret' => env('APP_SECRET', 'c7349182f91a90c1284918f0a23bca41'),
    
    'db' => [
        'driver' => env('DB_DRIVER', 'mysql'),
        'path' => __DIR__ . '/' . env('DB_PATH', 'database/database.sqlite'),
        'host' => env('DB_HOST', '127.0.0.1'),
        'port' => (int)env('DB_PORT', 3306),
        'database' => env('DB_DATABASE', 'uac_db'),
        'username' => env('DB_USERNAME', 'root'),
        'password' => env('DB_PASSWORD', 'root'),
    ],
    
    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN', '7793113949:AAE1qD5OxEtYSsAxB00McQzO5N2RsgfaX5U'),
        'bot_username' => env('TELEGRAM_BOT_USERNAME', 'UUAnonBot'),
        'channel_id' => env('TELEGRAM_CHANNEL_ID', ''),
        'moderation_group_id' => env('TELEGRAM_MODERATION_GROUP_ID', ''),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET', 'webhook_secret_key'),
    ],
    
    'ai' => [
        'enabled' => env('AI_MODERATION_ENABLED', true),
        'provider' => env('AI_PROVIDER', 'mock'),
        'api_key' => env('AI_API_KEY', ''),
    ],
    
    'rate_limits' => [
        'submissions' => (int)env('RATE_LIMIT_SUBMISSIONS', 5),
        'comments' => (int)env('RATE_LIMIT_COMMENTS', 10),
    ],
    
    'admin' => [
        'default_user' => env('ADMIN_DEFAULT_USER', 'admin'),
        'default_pass' => env('ADMIN_DEFAULT_PASS', 'admin123456'),
    ]
];
