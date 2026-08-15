<?php
// index.php - Main Webhook Entrypoint & Health Check Router

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/AnonymityService.php';
require_once __DIR__ . '/src/RateLimiter.php';
require_once __DIR__ . '/src/AiModerationService.php';
require_once __DIR__ . '/src/TelegramBot.php';
require_once __DIR__ . '/src/PublisherService.php';
require_once __DIR__ . '/src/ModerationService.php';
require_once __DIR__ . '/src/BotHandler.php';

use UAC\Database;
use UAC\BotHandler;

// Handle Health Endpoint (GET /health)
$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($requestUri === '/health' || isset($_GET['health'])) {
    header('Content-Type: application/json');
    $dbOk = 'error';
    try {
        $db = Database::getConnection();
        $db->query("SELECT 1");
        $dbOk = 'ok';
    } catch (Exception $e) {
        $dbOk = $e->getMessage();
    }

    echo json_encode([
        'status' => ($dbOk === 'ok') ? 'ok' : 'error',
        'database' => $dbOk,
        'telegram' => 'ok',
        'ai' => 'ok',
        'timestamp' => date('c')
    ], JSON_PRETTY_PRINT);
    exit;
}

// Handle Webhook POST Request from Telegram
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    if (!empty($rawInput)) {
        $update = json_decode($rawInput, true);
        if (is_array($update)) {
            try {
                $handler = new BotHandler();
                $handler->handleUpdate($update);
            } catch (Throwable $e) {
                error_log("Webhook Error: " . $e->getMessage());
            }
        }
    }
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok']);
    exit;
}

// Web Browser Default Interface (Redirect to Landing Page)
if (file_exists(__DIR__ . '/public/landing.php')) {
    require __DIR__ . '/public/landing.php';
    exit;
}

echo "University Anonymous Community API Gateway";
