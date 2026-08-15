<?php
// tests/test_direct_submission.php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/AnonymityService.php';
require_once __DIR__ . '/../src/RateLimiter.php';
require_once __DIR__ . '/../src/AiModerationService.php';
require_once __DIR__ . '/../src/TelegramBot.php';
require_once __DIR__ . '/../src/ModerationService.php';
require_once __DIR__ . '/../src/PublisherService.php';
require_once __DIR__ . '/../src/BotHandler.php';

use UAC\Database;
use UAC\BotHandler;

echo "=== Testing Direct Message Submission (No Category Required) ===\n\n";

$db = Database::getConnection();
$handler = new BotHandler();

$testUserId = 998877661;
$testMsgText = "Yangi unikal test xabari " . bin2hex(random_bytes(6));

// Simulate direct message webhook update from Telegram user
$update = [
    'update_id' => rand(100000, 999999),
    'message' => [
        'message_id' => rand(1000, 9999),
        'from' => [
            'id' => $testUserId,
            'is_bot' => false,
            'first_name' => 'Sardorbek',
            'username' => 'sardorbek'
        ],
        'chat' => [
            'id' => $testUserId,
            'type' => 'private'
        ],
        'date' => time(),
        'text' => $testMsgText
    ]
];

// Handle update
$handler->handleUpdate($update);

// Check if submission was inserted in database
$stmt = $db->prepare("SELECT * FROM submissions WHERE content = ? ORDER BY id DESC LIMIT 1");
$stmt->execute([$testMsgText]);
$submission = $stmt->fetch();

if ($submission) {
    echo "✅ Direct submission successfully saved in database!\n";
    echo "   ID: " . $submission['id'] . "\n";
    echo "   Public ID: " . $submission['public_id'] . "\n";
    echo "   Mod ID: " . $submission['mod_id'] . "\n";
    echo "   Category: " . $submission['category'] . "\n";
    echo "   Status: " . $submission['status'] . "\n";
    echo "   Content: " . $submission['content'] . "\n";
} else {
    echo "❌ Direct submission FAILED to save in database!\n";
    exit(1);
}

echo "\n=== ALL DIRECT SUBMISSION TESTS PASSED PERFECTLY ===\n";
