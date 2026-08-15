<?php
// src/BotHandler.php

declare(strict_types=1);

namespace UAC;

use PDO;
use Exception;
use Throwable;

class BotHandler {
    private PDO $db;
    private TelegramBot $bot;
    private AnonymityService $anonymity;
    private RateLimiter $rateLimiter;
    private AiModerationService $aiService;
    private ModerationService $moderationService;
    private PublisherService $publisherService;
    private string $statesDir;

    public function __construct() {
        $this->db = Database::getConnection();
        $this->bot = new TelegramBot();
        $this->anonymity = new AnonymityService($this->db);
        $this->rateLimiter = new RateLimiter($this->db);
        $this->aiService = new AiModerationService($this->anonymity);
        $this->moderationService = new ModerationService($this->db, $this->bot);
        $this->publisherService = new PublisherService($this->db, $this->bot);
        $this->moderationService->setPublisher($this->publisherService);

        $this->statesDir = __DIR__ . '/../storage/states';
        if (!is_dir($this->statesDir)) {
            mkdir($this->statesDir, 0777, true);
        }
    }

    /**
     * Process Incoming Telegram Webhook JSON Update.
     */
    public function handleUpdate(array $update): void {
        if (isset($update['callback_query'])) {
            $this->handleCallbackQuery($update['callback_query']);
            return;
        }

        if (!isset($update['message'])) {
            return;
        }

        $msg = $update['message'];
        $chatId = (int)$msg['chat']['id'];
        $userId = (int)($msg['from']['id'] ?? $chatId);
        $text = trim($msg['text'] ?? $msg['caption'] ?? '');
        $chatType = $msg['chat']['type'] ?? 'private';

        // Extract photo or video media if attached
        $mediaType = null;
        $mediaFileId = null;

        if (isset($msg['photo']) && is_array($msg['photo'])) {
            $mediaType = 'photo';
            $photoArray = $msg['photo'];
            $largestPhoto = end($photoArray);
            $mediaFileId = $largestPhoto['file_id'] ?? null;
        } elseif (isset($msg['video']) && is_array($msg['video'])) {
            $mediaType = 'video';
            $mediaFileId = $msg['video']['file_id'] ?? null;
        }

        // Ignore non-private messages unless moderation group
        if ($chatType !== 'private') {
            return;
        }

        $anonId = $this->anonymity->getOrCreateSession($userId);

        // Check if user is in a state
        $stateData = $this->getUserState($userId);

        if ($text === '/cancel' || $text === '❌ Cancel' || $text === '❌ Bekor qilish') {
            $this->clearUserState($userId);
            $this->sendMainMenu($chatId, "❌ Action cancelled.");
            return;
        }

        if (str_starts_with($text, '/start')) {
            $this->handleStartCommand($chatId, $userId, $text, $anonId);
            return;
        }

        if ($text === '/help' || $text === '/rules') {
            $this->sendRules($chatId);
            return;
        }

        if ($text === '/about') {
            $this->sendAbout($chatId);
            return;
        }

        if ($text === '/my' || $text === '📋 My Submissions' || $text === '📋 Mening xabarlarim') {
            $this->sendMySubmissions($chatId, $anonId);
            return;
        }

        // Handle State Machines (Writing message or writing comment)
        if (!empty($stateData)) {
            if ($stateData['action'] === 'wait_message') {
                if (empty($text) && !$mediaFileId) {
                    $this->bot->sendMessage($chatId, "⚠️ Please send text, photo, or video for your anonymous submission.");
                    return;
                }
                $this->processUserSubmission($chatId, $anonId, $text, $stateData['category'] ?? '📌 Other', $mediaType, $mediaFileId);
                $this->clearUserState($userId);
                return;
            } elseif ($stateData['action'] === 'wait_comment') {
                if (empty($text)) {
                    $this->bot->sendMessage($chatId, "⚠️ Please write text for your comment.");
                    return;
                }
                $this->processUserComment($chatId, $anonId, $text, (int)$stateData['submission_id']);
                $this->clearUserState($userId);
                return;
            }
        }

        // Default Main Menu Response
        $this->sendMainMenu($chatId, "👋 Welcome! Please select an option from the menu below:");
    }

    private function handleStartCommand(int $chatId, int $userId, string $text, string $anonId): void {
        $parts = explode(' ', $text);
        if (count($parts) > 1 && str_starts_with($parts[1], 'comment_')) {
            $token = str_replace('comment_', '', $parts[1]);
            $stmt = $this->db->prepare("SELECT submission_id FROM deep_links WHERE token = ? AND action_type = 'comment' AND expires_at > ?");
            $stmt->execute([$token, date('Y-m-d H:i:s')]);
            $subId = $stmt->fetchColumn();

            if ($subId) {
                $this->setUserState($userId, ['action' => 'wait_comment', 'submission_id' => $subId]);
                $this->bot->sendMessage(
                    $chatId,
                    "💬 <b>Write your anonymous comment:</b>\n\nYour identity remains 100% hidden.",
                    $this->getCancelKeyboard()
                );
                return;
            }
        }

        $this->clearUserState($userId);
        $this->sendMainMenu(
            $chatId,
            "👋 <b>Welcome to University Anonymous Community!</b>\n\n" .
            "Here students can anonymously submit thoughts, questions, suggestions, photos, or videos.\n\n" .
            "🔒 Your identity is completely hidden from moderators and other users."
        );
    }

    private function handleCallbackQuery(array $cb): void {
        $cbId = $cb['id'];
        $chatId = (int)$cb['message']['chat']['id'];
        $messageId = (int)$cb['message']['message_id'];
        $userId = (int)$cb['from']['id'];
        $data = $cb['data'] ?? '';

        // Check if moderation callback from private admin group
        if (str_starts_with($data, 'mod_')) {
            $this->moderationService->handleModeratorCallback($cbId, $chatId, $messageId, $data);
            return;
        }

        $anonId = $this->anonymity->getOrCreateSession($userId);

        if ($data === 'btn_send') {
            $this->bot->answerCallbackQuery($cbId);
            $this->bot->sendMessage(
                $chatId,
                "📚 <b>Select a category for your anonymous submission:</b>",
                $this->getCategoryKeyboard()
            );
            return;
        }

        if (str_starts_with($data, 'cat_')) {
            $categoryKey = str_replace('cat_', '', $data);
            $categories = [
                'education' => '📚 Education',
                'university' => '🏫 University',
                'teachers' => '👨‍🏫 Teachers',
                'exams' => '📝 Exams',
                'payments' => '💰 Tuition & Fees',
                'campus' => '🍽 Campus Life',
                'suggestions' => '💡 Suggestions',
                'questions' => '❓ Questions',
                'humor' => '😂 Humor',
                'confession' => '❤️ Confession & Romance',
                'announcement' => '📢 Announcement',
                'other' => '📌 Other'
            ];
            $categoryName = $categories[$categoryKey] ?? '📌 Other';

            $this->setUserState($userId, ['action' => 'wait_message', 'category' => $categoryName]);
            $this->bot->answerCallbackQuery($cbId);
            $this->bot->sendMessage(
                $chatId,
                "✍️ Send your anonymous submission (text, photo, or video) for <b>" . htmlspecialchars($categoryName) . "</b>:\n\n<i>Note: Do not include personal phone numbers or identity details.</i>",
                $this->getCancelKeyboard()
            );
            return;
        }

        if ($data === 'btn_my') {
            $this->bot->answerCallbackQuery($cbId);
            $this->sendMySubmissions($chatId, $anonId);
            return;
        }

        if (str_starts_with($data, 'del_')) {
            $pubId = '#' . str_replace('del_', '', $data);
            $this->deleteSubmissionByOwner($chatId, $anonId, $pubId);
            $this->bot->answerCallbackQuery($cbId, "✅ Your submission was deleted!");
            return;
        }

        if ($data === 'btn_rules') {
            $this->bot->answerCallbackQuery($cbId);
            $this->sendRules($chatId);
            return;
        }

        if ($data === 'btn_about') {
            $this->bot->answerCallbackQuery($cbId);
            $this->sendAbout($chatId);
            return;
        }

        if ($data === 'btn_main' || $data === 'btn_cancel') {
            $this->clearUserState($userId);
            $this->bot->answerCallbackQuery($cbId);
            $this->sendMainMenu($chatId, "🏠 Main Menu:");
            return;
        }

        $this->bot->answerCallbackQuery($cbId);
    }

    private function processUserSubmission(int $chatId, string $anonId, string $text, string $category, ?string $mediaType = null, ?string $mediaFileId = null): void {
        // Rate limiting check
        if (!$this->rateLimiter->checkRateLimit($anonId, 'submission')) {
            $this->bot->sendMessage($chatId, "⚠️ <b>Limit reached!</b> You have sent too many submissions in 10 minutes. Please try again later.");
            return;
        }

        // Duplicate check for text content
        if (!empty($text) && $this->rateLimiter->isDuplicate($text)) {
            $this->bot->sendMessage($chatId, "⚠️ <b>Duplicate message!</b> This content was submitted recently. Please submit new content.");
            return;
        }

        $this->rateLimiter->recordAction($anonId, 'submission');

        // AI Moderation & PII Sanitization (supports text and photo/video)
        $aiEval = $this->aiService->analyzeContent($text, $mediaType, $mediaFileId, $this->bot);

        $pubId = $this->anonymity->generateUniqueId('UAC_');
        $modId = $this->anonymity->generateUniqueId('M');
        $ownerToken = $this->anonymity->generateOwnerToken();
        $now = date('Y-m-d H:i:s');

        $stmt = $this->db->prepare("INSERT INTO submissions (public_id, mod_id, anon_id, owner_token, category, content, sanitized_content, media_type, media_file_id, ai_status, ai_score, ai_reason, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)");
        $stmt->execute([
            $pubId,
            $modId,
            $anonId,
            $ownerToken,
            $category,
            $text,
            $aiEval['sanitized_text'],
            $mediaType,
            $mediaFileId,
            $aiEval['decision'],
            $aiEval['score'],
            $aiEval['reason'],
            $now,
            $now
        ]);

        $subId = (int)$this->db->lastInsertId();

        // Send card to moderation group
        $submission = [
            'id' => $subId,
            'public_id' => $pubId,
            'mod_id' => $modId,
            'category' => $category,
            'sanitized_content' => $aiEval['sanitized_text'],
            'media_type' => $mediaType,
            'media_file_id' => $mediaFileId,
            'ai_status' => $aiEval['decision'],
            'ai_score' => $aiEval['score'],
            'ai_reason' => $aiEval['reason'],
            'flagged_category' => $aiEval['flagged_category'] ?? null
        ];
        $this->moderationService->sendToModerationGroup($submission);

        // Check if AI flagged a sensitive category or flagged for review
        if (!empty($aiEval['flagged_category']) || $aiEval['decision'] !== 'APPROVED_FOR_MODERATION') {
            $flaggedCat = $aiEval['flagged_category'] ?? ($aiEval['categories'][0] ?? 'Sensitive Content');
            $responseText = "⚠️ We detected a message under category <b>" . htmlspecialchars($flaggedCat) . "</b> by AI filter. Please wait for admin approval.\n\nSubmission Code: <code>{$pubId}</code>";
        } else {
            $responseText = "✅ <b>Your message has been received!</b>\n\nIt is currently being reviewed by moderators.\nSubmission Code: <code>{$pubId}</code>\nYour identity is completely hidden.";
        }

        $this->sendMainMenu($chatId, $responseText);
    }

    private function processUserComment(int $chatId, string $anonId, string $text, int $submissionId): void {
        if (!$this->rateLimiter->checkRateLimit($anonId, 'comment')) {
            $this->bot->sendMessage($chatId, "⚠️ <b>Limit reached!</b> You have reached the comment limit for now.");
            return;
        }

        $this->rateLimiter->recordAction($anonId, 'comment');
        $aiEval = $this->aiService->analyzeContent($text, null, null, $this->bot);

        $pubId = $this->anonymity->generateUniqueId('CMT_');
        $ownerToken = $this->anonymity->generateOwnerToken();

        $stmt = $this->db->prepare("INSERT INTO comments (public_id, submission_id, anon_id, owner_token, content, sanitized_content, ai_status, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'approved', ?)");
        $stmt->execute([
            $pubId,
            $submissionId,
            $anonId,
            $ownerToken,
            $text,
            $aiEval['sanitized_text'],
            $aiEval['decision'],
            date('Y-m-d H:i:s')
        ]);

        $this->sendMainMenu($chatId, "✅ <b>Your comment was successfully sent!</b>");
    }

    private function sendMySubmissions(int $chatId, string $anonId): void {
        $stmt = $this->db->prepare("SELECT * FROM submissions WHERE anon_id = ? AND status != 'deleted' ORDER BY id DESC LIMIT 10");
        $stmt->execute([$anonId]);
        $submissions = $stmt->fetchAll();

        if (empty($submissions)) {
            $this->bot->sendMessage($chatId, "📋 You have no anonymous submissions yet.");
            return;
        }

        $text = "📋 <b>Your Anonymous Submissions:</b>\n\n";
        $keyboard = ['inline_keyboard' => []];

        foreach ($submissions as $sub) {
            $statusLabel = match ($sub['status']) {
                'approved' => '✅ Published',
                'pending' => '⏳ Pending Review',
                'rejected' => '❌ Rejected',
                'spam' => '🚫 Blocked',
                default => $sub['status']
            };

            $contentSnippet = !empty($sub['sanitized_content']) ? $sub['sanitized_content'] : ('[' . strtoupper($sub['media_type'] ?? 'Media') . ']');
            $snippet = mb_substr($contentSnippet, 0, 35) . '...';
            $text .= "• <b>{$sub['public_id']}</b> [{$statusLabel}]\n  <i>\"{$snippet}\"</i>\n\n";

            $pubCode = str_replace('#', '', $sub['public_id']);
            $keyboard['inline_keyboard'][] = [
                ['text' => "🗑 Delete {$sub['public_id']}", 'callback_data' => "del_" . $pubCode]
            ];
        }

        $keyboard['inline_keyboard'][] = [['text' => "🏠 Main Menu", 'callback_data' => 'btn_main']];
        $this->bot->sendMessage($chatId, $text, $keyboard);
    }

    private function deleteSubmissionByOwner(int $chatId, string $anonId, string $publicId): void {
        $stmt = $this->db->prepare("SELECT * FROM submissions WHERE public_id = ? AND anon_id = ?");
        $stmt->execute([$publicId, $anonId]);
        $sub = $stmt->fetch();

        if (!$sub) {
            $this->bot->sendMessage($chatId, "❌ Submission not found or you don't have permission to delete it.");
            return;
        }

        // Remove from channel if published
        if ($sub['status'] === 'approved') {
            $this->publisherService->deleteFromChannel((int)$sub['id']);
        }

        // Mark deleted
        $upd = $this->db->prepare("UPDATE submissions SET status = 'deleted', updated_at = ? WHERE id = ?");
        $upd->execute([date('Y-m-d H:i:s'), $sub['id']]);

        $this->bot->sendMessage($chatId, "✅ Submission <b>{$publicId}</b> has been successfully deleted.");
    }

    private function sendMainMenu(int $chatId, string $text): void {
        $keyboard = [
            'inline_keyboard' => [
                [['text' => "📝 Send Anonymous Message", 'callback_data' => "btn_send"]],
                [['text' => "📋 My Submissions", 'callback_data' => "btn_my"]],
                [['text' => "ℹ️ About", 'callback_data' => "btn_about"], ['text' => "⚠️ Rules", 'callback_data' => "btn_rules"]]
            ]
        ];
        $this->bot->sendMessage($chatId, $text, $keyboard);
    }

    private function sendRules(int $chatId): void {
        $rules = "⚠️ <b>COMMUNITY RULES:</b>\n\n" .
                 "1. Insults, hate speech, and profanity are strictly prohibited.\n" .
                 "2. Sharing personal phone numbers or private data (doxxing) is prohibited.\n" .
                 "3. Commercial advertising, gambling, and spam are prohibited.\n" .
                 "4. All submissions are moderated before publishing to the main channel.";
        $this->bot->sendMessage($chatId, $rules);
    }

    private function sendAbout(int $chatId): void {
        $about = "ℹ️ <b>ABOUT THE PLATFORM:</b>\n\n" .
                 "University Anonymous Community is an open, anonymous expression platform for students.\n\n" .
                 "🔒 <b>Privacy Guarantee:</b> Your Telegram ID, name, and profile details are NEVER stored or displayed.";
        $this->bot->sendMessage($chatId, $about);
    }

    private function getCategoryKeyboard(): array {
        return [
            'inline_keyboard' => [
                [['text' => "📚 Education", 'callback_data' => "cat_education"], ['text' => "🏫 University", 'callback_data' => "cat_university"]],
                [['text' => "👨‍🏫 Teachers", 'callback_data' => "cat_teachers"], ['text' => "📝 Exams", 'callback_data' => "cat_exams"]],
                [['text' => "💰 Tuition & Fees", 'callback_data' => "cat_payments"], ['text' => "🍽 Campus Life", 'callback_data' => "cat_campus"]],
                [['text' => "💡 Suggestions", 'callback_data' => "cat_suggestions"], ['text' => "❓ Questions", 'callback_data' => "cat_questions"]],
                [['text' => "😂 Humor", 'callback_data' => "cat_humor"], ['text' => "❤️ Confession & Romance", 'callback_data' => "cat_confession"]],
                [['text' => "📢 Announcement", 'callback_data' => "cat_announcement"], ['text' => "📌 Other", 'callback_data' => "cat_other"]],
                [['text' => "❌ Cancel", 'callback_data' => "btn_cancel"]]
            ]
        ];
    }

    private function getCancelKeyboard(): array {
        return [
            'keyboard' => [
                [['text' => "❌ Cancel"]]
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => true
        ];
    }

    private function setUserState(int $userId, array $data): void {
        file_put_contents($this->statesDir . '/' . $userId . '.json', json_encode($data));
    }

    private function getUserState(int $userId): ?array {
        $file = $this->statesDir . '/' . $userId . '.json';
        if (file_exists($file)) {
            return json_decode(file_get_contents($file), true);
        }
        return null;
    }

    private function clearUserState(int $userId): void {
        $file = $this->statesDir . '/' . $userId . '.json';
        if (file_exists($file)) {
            @unlink($file);
        }
    }
}
