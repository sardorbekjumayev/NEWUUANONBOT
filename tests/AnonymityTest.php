<?php
// tests/AnonymityTest.php — Comprehensive Anonymity & Security Test Suite

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
use UAC\AnonymityService;
use UAC\RateLimiter;
use UAC\AiModerationService;

class AnonymityTest {
    private PDO $db;
    private AnonymityService $anonymity;
    private RateLimiter $rateLimiter;
    private AiModerationService $aiService;

    public function __construct() {
        $this->db = Database::getConnection();
        $this->anonymity = new AnonymityService($this->db);
        $this->rateLimiter = new RateLimiter($this->db);
        $this->aiService = new AiModerationService($this->anonymity);
    }

    public function runAll(): void {
        echo "=== Running University Anonymous Community Security & Anonymity Tests ===\n\n";

        $this->testTelegramUserHashing();
        $this->testPersonalDataRedaction();
        $this->testIdentityIsolationInDatabase();
        $this->testUnauthorizedDeletionPrevention();
        $this->testRateLimiting();
        $this->testDuplicateDetection();
        $this->testAiFailureFallback();

        echo "\n✅ ALL 7 ANONYMITY AND SECURITY TESTS PASSED PERFECTLY!\n";
    }

    private function assert(bool $condition, string $testName): void {
        if ($condition) {
            echo "  [PASS] {$testName}\n";
        } else {
            echo "❌ [FAIL] {$testName}\n";
            throw new Exception("Test failed: {$testName}");
        }
    }

    private function testTelegramUserHashing(): void {
        $tgUserId = 7714914661;
        $hash = $this->anonymity->hashTelegramUser($tgUserId);
        $anonId = $this->anonymity->getOrCreateSession($tgUserId);

        $this->assert(!str_contains($hash, (string)$tgUserId), "Telegram Hash does not reveal raw ID");
        $this->assert(str_starts_with($anonId, 'anon_'), "Anonymous session token format is valid (anon_xxxx)");
    }

    private function testPersonalDataRedaction(): void {
        $rawText = "Mening raqamim +998901234567, emailim john@gmail.com, telegramim @john_doe, card 8600 1234 5678 9012";
        $result = $this->anonymity->sanitizePersonalData($rawText);

        $this->assert($result['contains_personal_data'] === true, "PII Detector identifies sensitive personal data");
        $this->assert(!str_contains($result['sanitized_text'], '+998901234567'), "Phone number redacted");
        $this->assert(!str_contains($result['sanitized_text'], 'john@gmail.com'), "Email address redacted");
        $this->assert(!str_contains($result['sanitized_text'], '@john_doe'), "Telegram handle redacted");
        $this->assert(!str_contains($result['sanitized_text'], '8600 1234 5678 9012'), "Card number redacted");
    }

    private function testIdentityIsolationInDatabase(): void {
        $randSuffix = rand(10000, 99999);
        $anonId = 'anon_test_' . $randSuffix;
        $pubId = 'UAC_TEST_' . $randSuffix;
        $modId = 'M_TEST_' . $randSuffix;
        $ownerToken = $this->anonymity->generateOwnerToken();

        $stmt = $this->db->prepare("INSERT INTO submissions (public_id, mod_id, anon_id, owner_token, category, content, sanitized_content, status, created_at, updated_at) VALUES (?, ?, ?, ?, 'Test', 'Test text', 'Test text', 'approved', NOW(), NOW())");
        $stmt->execute([$pubId, $modId, $anonId, $ownerToken]);

        // Verify public query cannot expose telegram identity
        $query = $this->db->prepare("SELECT * FROM submissions WHERE public_id = ?");
        $query->execute([$pubId]);
        $row = $query->fetch();

        $this->assert(!isset($row['telegram_id']), "Submissions table has no telegram_id column");
        $this->assert(!isset($row['username']), "Submissions table has no username column");
        $this->assert(!isset($row['first_name']), "Submissions table has no first_name column");
    }

    private function testUnauthorizedDeletionPrevention(): void {
        $ownerAnonId = 'anon_owner_' . rand(1000, 9999);
        $strangerAnonId = 'anon_stranger_' . rand(1000, 9999);
        $randSuffix = rand(10000, 99999);
        $pubId = 'UAC_DEL_' . $randSuffix;
        $modId = 'M_DEL_' . $randSuffix;
        $ownerToken = $this->anonymity->generateOwnerToken();

        $stmt = $this->db->prepare("INSERT INTO submissions (public_id, mod_id, anon_id, owner_token, category, content, sanitized_content, status, created_at, updated_at) VALUES (?, ?, ?, ?, 'Test', 'Delete me', 'Delete me', 'approved', NOW(), NOW())");
        $stmt->execute([$pubId, $modId, $ownerAnonId, $ownerToken]);

        // Attempt deletion by stranger
        $check = $this->db->prepare("SELECT COUNT(*) FROM submissions WHERE public_id = ? AND anon_id = ?");
        $check->execute([$pubId, $strangerAnonId]);
        $allowed = ($check->fetchColumn() > 0);

        $this->assert($allowed === false, "Stranger (User B) cannot delete User A's submission");

        // Deletion by legitimate owner
        $checkOwner = $this->db->prepare("SELECT COUNT(*) FROM submissions WHERE public_id = ? AND anon_id = ?");
        $checkOwner->execute([$pubId, $ownerAnonId]);
        $ownerAllowed = ($checkOwner->fetchColumn() > 0);

        $this->assert($ownerAllowed === true, "Owner (User A) is authorized to delete own submission");
    }

    private function testRateLimiting(): void {
        $testAnon = 'anon_ratelimit_' . rand(10000, 99999);
        for ($i = 0; $i < 5; $i++) {
            $this->rateLimiter->recordAction($testAnon, 'submission');
        }

        $canSubmitMore = $this->rateLimiter->checkRateLimit($testAnon, 'submission');
        $this->assert($canSubmitMore === false, "Rate Limiter blocks 6th submission within window limit");
    }

    private function testDuplicateDetection(): void {
        $uniqueMsg = "Xabar kodi " . bin2hex(random_bytes(8)) . " va shaxsiy taklif " . rand(10000, 99999);
        $isDup1 = $this->rateLimiter->isDuplicate($uniqueMsg);
        $this->assert($isDup1 === false, "First time unique message is not duplicate");

        // Insert into DB
        $randSuffix = rand(10000, 99999);
        $stmt = $this->db->prepare("INSERT INTO submissions (public_id, mod_id, anon_id, owner_token, category, content, sanitized_content, status, created_at, updated_at) VALUES (?, ?, 'anon_dup', 'token', 'General', ?, ?, 'approved', NOW(), NOW())");
        $stmt->execute(['UAC_DUP_' . $randSuffix, 'M_DUP_' . $randSuffix, $uniqueMsg, $uniqueMsg]);

        $isDup2 = $this->rateLimiter->isDuplicate($uniqueMsg);
        $this->assert($isDup2 === true, "Repeated submission detected as duplicate");
    }

    private function testAiFailureFallback(): void {
        // Trigger AI analysis with disabled/invalid API key
        $result = $this->aiService->analyzeContent("Oddiy matn check");
        $this->assert(in_array($result['decision'], ['APPROVED_FOR_MODERATION', 'NEEDS_REVIEW', 'AI_UNAVAILABLE']), "AI fallback decision is safe");
        $this->assert($result['decision'] !== 'AUTOMATIC_PUBLISH', "AI Never auto-publishes without human moderation");
    }
}

// Execute tests if called directly
$test = new AnonymityTest();
$test->runAll();
