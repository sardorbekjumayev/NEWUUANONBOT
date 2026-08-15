<?php
// src/BotHandler.php

declare(strict_types=1);

namespace UAC;

use PDO;
use Exception;

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
        $text = trim($msg['text'] ?? '');
        $chatType = $msg['chat']['type'] ?? 'private';

        // Ignore non-private messages unless moderation group
        if ($chatType !== 'private') {
            return;
        }

        $anonId = $this->anonymity->getOrCreateSession($userId);

        // Check if user is in a state
        $stateData = $this->getUserState($userId);

        if ($text === '/cancel' || $text === '❌ Bekor qilish') {
            $this->clearUserState($userId);
            $this->sendMainMenu($chatId, "❌ Amal bekor qilindi.");
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

        if ($text === '/my' || $text === '📋 Mening xabarlarim') {
            $this->sendMySubmissions($chatId, $anonId);
            return;
        }

        // Handle State Machines (Writing message or writing comment)
        if (!empty($stateData)) {
            if ($stateData['action'] === 'wait_message') {
                $this->processUserSubmission($chatId, $anonId, $text, $stateData['category'] ?? 'Boshqa');
                $this->clearUserState($userId);
                return;
            } elseif ($stateData['action'] === 'wait_comment') {
                $this->processUserComment($chatId, $anonId, $text, (int)$stateData['submission_id']);
                $this->clearUserState($userId);
                return;
            }
        }

        // Default Main Menu Response
        $this->sendMainMenu($chatId, "👋 Assalomu alaykum! Kerakli bo'limni tanlang:");
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
                    "💬 <b>Ushbu anonim xabarga izohingizni yozing:</b>\n\nShaxsingiz va ismingiz mutlaqo oshkor etilmaydi.",
                    $this->getCancelKeyboard()
                );
                return;
            }
        }

        $this->clearUserState($userId);
        $this->sendMainMenu($chatId, "👋 <b>University Anonymous Community</b> botiga xush kelibsiz!\n\nBu yerda talabalar anonim tarzda fikr, savol, taklif yoki murojaat yuborishlari mumkin.\n\nShaxsingiz moderatorlar va foydalanuvchilardan to'liq yashirilgan.");
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
                "📚 <b>Anonim xabaringiz uchun kategoriyani tanlang:</b>",
                $this->getCategoryKeyboard()
            );
            return;
        }

        if (str_starts_with($data, 'cat_')) {
            $categoryKey = str_replace('cat_', '', $data);
            $categories = [
                'education' => '📚 Ta\'lim',
                'university' => '🏫 Universitet',
                'teachers' => '👨‍🏫 O\'qituvchilar',
                'exams' => '📝 Imtihonlar',
                'payments' => '💰 To\'lovlar',
                'campus' => '🍽 Kampus',
                'suggestions' => '💡 Takliflar',
                'questions' => '❓ Savollar',
                'humor' => '😂 Yumor',
                'confession' => '❤️ Iqror',
                'announcement' => '📢 E\'lon',
                'other' => '📌 Boshqa'
            ];
            $categoryName = $categories[$categoryKey] ?? '📌 Boshqa';

            $this->setUserState($userId, ['action' => 'wait_message', 'category' => $categoryName]);
            $this->bot->answerCallbackQuery($cbId);
            $this->bot->sendMessage(
                $chatId,
                "✍️ <b>" . htmlspecialchars($categoryName) . "</b> bo'limi uchun anonim xabaringizni yozib yuboring:\n\n<i>Diqqat: Shaxsingizni oshkor qiluvchi telefon yoki ma'lumotlarni yozmang.</i>",
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
            $this->bot->answerCallbackQuery($cbId, "✅ Xabaringiz o'chirildi!");
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
            $this->sendMainMenu($chatId, "🏠 Bosh menyu:");
            return;
        }

        $this->bot->answerCallbackQuery($cbId);
    }

    private function processUserSubmission(int $chatId, string $anonId, string $text, string $category): void {
        // Rate limiting check
        if (!$this->rateLimiter->checkRateLimit($anonId, 'submission')) {
            $this->bot->sendMessage($chatId, "⚠️ <b>Cheklov!</b> Siz 10 daqiqa ichida juda ko'p xabar yubordingiz. Birozdan so'ng qayta urinib ko'ring.");
            return;
        }

        // Duplicate check
        if ($this->rateLimiter->isDuplicate($text)) {
            $this->bot->sendMessage($chatId, "⚠️ <b>Nusxa xabar!</b> Ushbu xabar avvalroq yuborilgan. Iltimos, yangi mazmundagi xabar yozing.");
            return;
        }

        $this->rateLimiter->recordAction($anonId, 'submission');

        // AI Moderation & PII Sanitization
        $aiEval = $this->aiService->analyzeContent($text);

        $pubId = $this->anonymity->generateUniqueId('UAC_');
        $modId = $this->anonymity->generateUniqueId('M');
        $ownerToken = $this->anonymity->generateOwnerToken();
        $now = date('Y-m-d H:i:s');

        $stmt = $this->db->prepare("INSERT INTO submissions (public_id, mod_id, anon_id, owner_token, category, content, sanitized_content, ai_status, ai_score, ai_reason, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)");
        $stmt->execute([
            $pubId,
            $modId,
            $anonId,
            $ownerToken,
            $category,
            $text,
            $aiEval['sanitized_text'],
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
            'ai_status' => $aiEval['decision'],
            'ai_score' => $aiEval['score']
        ];
        $this->moderationService->sendToModerationGroup($submission);

        $this->sendMainMenu(
            $chatId,
            "✅ <b>Xabaringiz qabul qilindi!</b>\n\n" .
            "Hozirda moderatorlar ko'rib chiqishmoqda.\n" .
            "Xabar kodi: <code>{$pubId}</code>\n" .
            "Shaxsingiz to'liq yashirilgan."
        );
    }

    private function processUserComment(int $chatId, string $anonId, string $text, int $submissionId): void {
        if (!$this->rateLimiter->checkRateLimit($anonId, 'comment')) {
            $this->bot->sendMessage($chatId, "⚠️ <b>Cheklov!</b> Izohlar uchun belgilangan limitga etdingiz.");
            return;
        }

        $this->rateLimiter->recordAction($anonId, 'comment');
        $aiEval = $this->aiService->analyzeContent($text);

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

        $this->sendMainMenu($chatId, "✅ <b>Izohingiz muvaffaqiyatli jo'natildi!</b>");
    }

    private function sendMySubmissions(int $chatId, string $anonId): void {
        $stmt = $this->db->prepare("SELECT * FROM submissions WHERE anon_id = ? AND status != 'deleted' ORDER BY id DESC LIMIT 10");
        $stmt->execute([$anonId]);
        $submissions = $stmt->fetchAll();

        if (empty($submissions)) {
            $this->bot->sendMessage($chatId, "📋 Sizda hali anonim xabarlar mavjud emas.");
            return;
        }

        $text = "📋 <b>Sizning anonim xabarlaringiz:</b>\n\n";
        $keyboard = ['inline_keyboard' => []];

        foreach ($submissions as $sub) {
            $statusLabel = match ($sub['status']) {
                'approved' => '✅ Chop etilgan',
                'pending' => '⏳ Kutilmoqda',
                'rejected' => '❌ Rad etilgan',
                'spam' => '🚫 Bloklangan',
                default => $sub['status']
            };

            $snippet = mb_substr($sub['sanitized_content'], 0, 35) . '...';
            $text .= "• <b>{$sub['public_id']}</b> [{$statusLabel}]\n  <i>\"{$snippet}\"</i>\n\n";

            $pubCode = str_replace('#', '', $sub['public_id']);
            $keyboard['inline_keyboard'][] = [
                ['text' => "🗑 O'chirish {$sub['public_id']}", 'callback_data' => "del_" . $pubCode]
            ];
        }

        $keyboard['inline_keyboard'][] = [['text' => "🏠 Bosh menyu", 'callback_data' => 'btn_main']];
        $this->bot->sendMessage($chatId, $text, $keyboard);
    }

    private function deleteSubmissionByOwner(int $chatId, string $anonId, string $publicId): void {
        $stmt = $this->db->prepare("SELECT * FROM submissions WHERE public_id = ? AND anon_id = ?");
        $stmt->execute([$publicId, $anonId]);
        $sub = $stmt->fetch();

        if (!$sub) {
            $this->bot->sendMessage($chatId, "❌ Xabar topilmadi yoki o'chirish huquqingiz yo'q.");
            return;
        }

        // Remove from channel if published
        if ($sub['status'] === 'approved') {
            $this->publisherService->deleteFromChannel((int)$sub['id']);
        }

        // Mark deleted
        $upd = $this->db->prepare("UPDATE submissions SET status = 'deleted', updated_at = ? WHERE id = ?");
        $upd->execute([date('Y-m-d H:i:s'), $sub['id']]);

        $this->bot->sendMessage($chatId, "✅ <b>{$publicId}</b> anonim xabaringiz muvaffaqiyatli o'chirildi.");
    }

    private function sendMainMenu(int $chatId, string $text): void {
        $keyboard = [
            'inline_keyboard' => [
                [['text' => "📝 Anonim Xabar Yuborish", 'callback_data' => "btn_send"]],
                [['text' => "📋 Mening Xabarlarim", 'callback_data' => "btn_my"]],
                [['text' => "ℹ️ Haqida", 'callback_data' => "btn_about"], ['text' => "⚠️ Qoidalar", 'callback_data' => "btn_rules"]]
            ]
        ];
        $this->bot->sendMessage($chatId, $text, $keyboard);
    }

    private function sendRules(int $chatId): void {
        $rules = "⚠️ <b>JAMOYAT QOIDALARI:</b>\n\n" .
                 "1. Haqoratli va nafratli kontent taqiqlanadi.\n" .
                 "2. Boshqa shaxslarning telefon nomeri va shaxsiy ma'lumotlarini tarqatish (doxxing) taqiqlanadi.\n" .
                 "3. Spam va reklama xabarlari taqiqlanadi.\n" .
                 "4. barcha xabarlar moderatorlar tomonidan tekshiriladi.";
        $this->bot->sendMessage($chatId, $rules);
    }

    private function sendAbout(int $chatId): void {
        $about = "ℹ️ <b>PLATFORMA HAQIDA:</b>\n\n" .
                 "University Anonymous Community — talabalarning erkin va anonim muloqot platformasi.\n\n" .
                 "🔒 <b>Maxfiylik kafolati:</b> Sizning Telegram ID, ismingiz va profilingiz platformada mutlaqo saqlanmaydi va ko'rsatilmaydi.";
        $this->bot->sendMessage($chatId, $about);
    }

    private function getCategoryKeyboard(): array {
        return [
            'inline_keyboard' => [
                [['text' => "📚 Ta'lim", 'callback_data' => "cat_education"], ['text' => "🏫 Universitet", 'callback_data' => "cat_university"]],
                [['text' => "👨‍🏫 O'qituvchilar", 'callback_data' => "cat_teachers"], ['text' => "📝 Imtihonlar", 'callback_data' => "cat_exams"]],
                [['text' => "💰 To'lovlar", 'callback_data' => "cat_payments"], ['text' => "🍽 Kampus", 'callback_data' => "cat_campus"]],
                [['text' => "💡 Takliflar", 'callback_data' => "cat_suggestions"], ['text' => "❓ Savollar", 'callback_data' => "cat_questions"]],
                [['text' => "😂 Yumor", 'callback_data' => "cat_humor"], ['text' => "❤️ Iqror", 'callback_data' => "cat_confession"]],
                [['text' => "📢 E'lon", 'callback_data' => "cat_announcement"], ['text' => "📌 Boshqa", 'callback_data' => "cat_other"]],
                [['text' => "❌ Bekor qilish", 'callback_data' => "btn_cancel"]]
            ]
        ];
    }

    private function getCancelKeyboard(): array {
        return [
            'keyboard' => [
                [['text' => "❌ Bekor qilish"]]
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
