<?php
// src/ModerationService.php

declare(strict_types=1);

namespace UAC;

use PDO;
use Exception;

class ModerationService {
    private PDO $db;
    private TelegramBot $bot;
    private string $moderationGroupId;
    private PublisherService $publisher;

    public function __construct(?PDO $db = null, ?TelegramBot $bot = null) {
        $this->db = $db ?? Database::getConnection();
        $this->bot = $bot ?? new TelegramBot();
        $config = require __DIR__ . '/../config.php';
        $this->moderationGroupId = (string)$config['telegram']['moderation_group_id'];
    }

    public function setPublisher(PublisherService $publisher): void {
        $this->publisher = $publisher;
    }

    /**
     * Send moderation card to private Telegram moderation group.
     */
    public function sendToModerationGroup(array $submission): bool {
        if (empty($this->moderationGroupId)) {
            return false;
        }

        $cardText = sprintf(
            "━━━━━━━━━━━━━━━━━━\n" .
            "🛡 <b>ANONIM MODERATSIYA</b>\n" .
            "━━━━━━━━━━━━━━━━━━\n\n" .
            "🆔 <b>Xabar ID:</b> %s\n" .
            "📚 <b>Kategoriya:</b> %s\n" .
            "🤖 <b>AI Holati:</b> %s\n" .
            "📊 <b>AI Ishonch:</b> %d%%\n\n" .
            "📝 <b>Matn:</b>\n" .
            "<i>%s</i>\n" .
            "━━━━━━━━━━━━━━━━━━",
            htmlspecialchars($submission['mod_id']),
            htmlspecialchars($submission['category']),
            htmlspecialchars($submission['ai_status']),
            (int)($submission['ai_score'] * 100),
            htmlspecialchars($submission['sanitized_content'])
        );

        $modId = str_replace('#', '', $submission['mod_id']);

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => "✅ Tasdiqlash", 'callback_data' => "mod_app_" . $modId],
                    ['text' => "❌ Rad etish", 'callback_data' => "mod_rej_" . $modId]
                ],
                [
                    ['text' => "✏️ Tahrirlash", 'callback_data' => "mod_edt_" . $modId],
                    ['text' => "🚫 Bloklash", 'callback_data' => "mod_ban_" . $modId]
                ],
                [
                    ['text' => "🔍 AI Tahlil", 'callback_data' => "mod_ai_" . $modId]
                ]
            ]
        ];

        $res = $this->bot->sendMessage($this->moderationGroupId, $cardText, $keyboard);
        return isset($res->result->message_id);
    }

    /**
     * Handle Inline Callback Query from Moderator in Private Group.
     */
    public function handleModeratorCallback(string $callbackId, int|string $chatId, int $messageId, string $data): bool {
        if (!preg_match('/^mod_(app|rej|edt|ban|ai)_(.+)$/', $data, $matches)) {
            return false;
        }

        $action = $matches[1];
        $modId = '#' . $matches[2];

        $stmt = $this->db->prepare("SELECT * FROM submissions WHERE mod_id = ?");
        $stmt->execute([$modId]);
        $submission = $stmt->fetch();

        if (!$submission) {
            $this->bot->answerCallbackQuery($callbackId, "❌ Xabar topilmadi!", true);
            return false;
        }

        switch ($action) {
            case 'app':
                // Approve & Publish
                $upd = $this->db->prepare("UPDATE submissions SET status = 'approved', updated_at = ? WHERE id = ?");
                $upd->execute([date('Y-m-d H:i:s'), $submission['id']]);

                if (!isset($this->publisher)) {
                    $this->publisher = new PublisherService($this->db, $this->bot);
                }
                $pubResult = $this->publisher->publishToChannel($submission['id']);

                $this->bot->answerCallbackQuery($callbackId, "✅ Xabar kanalga chop etildi!");
                $this->bot->editMessageText(
                    $chatId,
                    $messageId,
                    sprintf(
                        "━━━━━━━━━━━━━━━━━━\n" .
                        "✅ <b>TASDIQLANDI VA CHOP ETILDI</b>\n" .
                        "━━━━━━━━━━━━━━━━━━\n\n" .
                        "🆔 <b>Xabar ID:</b> %s\n" .
                        "📝 <b>Matn:</b> %s",
                        htmlspecialchars($submission['mod_id']),
                        htmlspecialchars($submission['sanitized_content'])
                    )
                );
                $this->logAudit('Moderator', 'APPROVE_SUBMISSION', $submission['public_id']);
                return true;

            case 'rej':
                // Reject
                $upd = $this->db->prepare("UPDATE submissions SET status = 'rejected', updated_at = ? WHERE id = ?");
                $upd->execute([date('Y-m-d H:i:s'), $submission['id']]);

                $this->bot->answerCallbackQuery($callbackId, "❌ Xabar rad etildi!");
                $this->bot->editMessageText(
                    $chatId,
                    $messageId,
                    sprintf(
                        "━━━━━━━━━━━━━━━━━━\n" .
                        "❌ <b>RAD ETILDI</b>\n" .
                        "━━━━━━━━━━━━━━━━━━\n\n" .
                        "🆔 <b>Xabar ID:</b> %s",
                        htmlspecialchars($submission['mod_id'])
                    )
                );
                $this->logAudit('Moderator', 'REJECT_SUBMISSION', $submission['public_id']);
                return true;

            case 'ban':
                // Ban Content / Spam
                $upd = $this->db->prepare("UPDATE submissions SET status = 'spam', updated_at = ? WHERE id = ?");
                $upd->execute([date('Y-m-d H:i:s'), $submission['id']]);

                $this->bot->answerCallbackQuery($callbackId, "🚫 Xabar spamlarga qo'shildi!", true);
                $this->bot->editMessageText(
                    $chatId,
                    $messageId,
                    "🚫 <b>SPAM SIFATIDA BLOKLANDI:</b> " . htmlspecialchars($submission['mod_id'])
                );
                $this->logAudit('Moderator', 'BAN_SUBMISSION', $submission['public_id']);
                return true;

            case 'ai':
                // AI Detailed Info
                $aiInfo = sprintf(
                    "🤖 <b>AI Tahlili:</b>\n\n" .
                    "• Holat: %s\n" .
                    "• Score: %d%%\n" .
                    "• Sabab: %s",
                    htmlspecialchars($submission['ai_status']),
                    (int)($submission['ai_score'] * 100),
                    htmlspecialchars($submission['ai_reason'] ?? 'Sabab ko\'rsatilmadi')
                );
                $this->bot->answerCallbackQuery($callbackId, $aiInfo, true);
                return true;
        }

        return false;
    }

    private function logAudit(string $adminId, string $action, string $targetId): void {
        $stmt = $this->db->prepare("INSERT INTO audit_logs (admin_identifier, action, target_id, details, created_at) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$adminId, $action, $targetId, 'Moderator group action', date('Y-m-d H:i:s')]);
    }
}
