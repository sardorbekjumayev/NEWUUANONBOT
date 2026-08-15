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

        $flaggedCategory = $submission['flagged_category'] ?? null;
        $flaggedStr = $flaggedCategory ? "\n⚠️ <b>AI Category:</b> " . htmlspecialchars($flaggedCategory) : "";

        $cardText = sprintf(
            "━━━━━━━━━━━━━━━━━━\n" .
            "🛡 <b>ANONYMOUS MODERATION</b>\n" .
            "━━━━━━━━━━━━━━━━━━\n\n" .
            "🆔 <b>Message ID:</b> %s\n" .
            "📚 <b>Category:</b> %s\n" .
            "🤖 <b>AI Status:</b> %s\n" .
            "📊 <b>AI Confidence:</b> %d%%%s\n\n" .
            "📝 <b>Content:</b>\n" .
            "<i>%s</i>\n" .
            "━━━━━━━━━━━━━━━━━━",
            htmlspecialchars($submission['mod_id']),
            htmlspecialchars($submission['category']),
            htmlspecialchars($submission['ai_status']),
            (int)($submission['ai_score'] * 100),
            $flaggedStr,
            htmlspecialchars(!empty($submission['sanitized_content']) ? $submission['sanitized_content'] : '[' . strtoupper($submission['media_type'] ?? 'MEDIA') . ']')
        );

        $modId = str_replace('#', '', $submission['mod_id']);

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => "✅ Approve", 'callback_data' => "mod_app_" . $modId],
                    ['text' => "❌ Reject", 'callback_data' => "mod_rej_" . $modId]
                ],
                [
                    ['text' => "🚫 Ban Spam", 'callback_data' => "mod_ban_" . $modId],
                    ['text' => "🔍 AI Details", 'callback_data' => "mod_ai_" . $modId]
                ]
            ]
        ];

        $mediaType = $submission['media_type'] ?? null;
        $mediaFileId = $submission['media_file_id'] ?? null;

        if ($mediaType === 'photo' && $mediaFileId) {
            $res = $this->bot->sendPhoto($this->moderationGroupId, $mediaFileId, $cardText, $keyboard);
        } elseif ($mediaType === 'video' && $mediaFileId) {
            $res = $this->bot->sendVideo($this->moderationGroupId, $mediaFileId, $cardText, $keyboard);
        } else {
            $res = $this->bot->sendMessage($this->moderationGroupId, $cardText, $keyboard);
        }

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
            $this->bot->answerCallbackQuery($callbackId, "❌ Submission not found!", true);
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
                $pubResult = $this->publisher->publishToChannel((int)$submission['id']);

                // Notify user in DM (edit quoted reply message)
                if (!empty($submission['user_dm_chat_id']) && !empty($submission['user_dm_message_id'])) {
                    $pubCode = str_replace('#', '', $submission['public_id']);
                    $this->bot->editMessageText(
                        $submission['user_dm_chat_id'],
                        (int)$submission['user_dm_message_id'],
                        "Your message was sent to the channel!",
                        ['inline_keyboard' => [[['text' => '🗑 Delete', 'callback_data' => 'del_' . $pubCode]]]]
                    );
                }

                $this->bot->answerCallbackQuery($callbackId, "✅ Published to channel!");
                $this->bot->editMessageText(
                    $chatId,
                    $messageId,
                    sprintf(
                        "━━━━━━━━━━━━━━━━━━\n" .
                        "✅ <b>APPROVED & PUBLISHED</b>\n" .
                        "━━━━━━━━━━━━━━━━━━\n\n" .
                        "🆔 <b>Message ID:</b> %s\n" .
                        "📝 <b>Content:</b> %s",
                        htmlspecialchars($submission['mod_id']),
                        htmlspecialchars(!empty($submission['sanitized_content']) ? $submission['sanitized_content'] : '[' . strtoupper($submission['media_type'] ?? 'MEDIA') . ']')
                    )
                );
                $this->logAudit('Moderator', 'APPROVE_SUBMISSION', $submission['public_id']);
                return true;

            case 'rej':
                // Reject
                $upd = $this->db->prepare("UPDATE submissions SET status = 'rejected', updated_at = ? WHERE id = ?");
                $upd->execute([date('Y-m-d H:i:s'), $submission['id']]);

                // Notify user in DM
                if (!empty($submission['user_dm_chat_id']) && !empty($submission['user_dm_message_id'])) {
                    $this->bot->editMessageText(
                        $submission['user_dm_chat_id'],
                        (int)$submission['user_dm_message_id'],
                        "❌ Your message was rejected by moderators."
                    );
                }

                $this->bot->answerCallbackQuery($callbackId, "❌ Submission rejected!");
                $this->bot->editMessageText(
                    $chatId,
                    $messageId,
                    sprintf(
                        "━━━━━━━━━━━━━━━━━━\n" .
                        "❌ <b>REJECTED</b>\n" .
                        "━━━━━━━━━━━━━━━━━━\n\n" .
                        "🆔 <b>Message ID:</b> %s",
                        htmlspecialchars($submission['mod_id'])
                    )
                );
                $this->logAudit('Moderator', 'REJECT_SUBMISSION', $submission['public_id']);
                return true;

            case 'ban':
                // Ban Content / Spam
                $upd = $this->db->prepare("UPDATE submissions SET status = 'spam', updated_at = ? WHERE id = ?");
                $upd->execute([date('Y-m-d H:i:s'), $submission['id']]);

                $this->bot->answerCallbackQuery($callbackId, "🚫 Marked as spam!", true);
                $this->bot->editMessageText(
                    $chatId,
                    $messageId,
                    "🚫 <b>BLOCKED AS SPAM:</b> " . htmlspecialchars($submission['mod_id'])
                );
                $this->logAudit('Moderator', 'BAN_SUBMISSION', $submission['public_id']);
                return true;

            case 'ai':
                // AI Detailed Info
                $aiInfo = sprintf(
                    "🤖 <b>AI Evaluation:</b>\n\n" .
                    "• Status: %s\n" .
                    "• Confidence: %d%%\n" .
                    "• Reason: %s",
                    htmlspecialchars($submission['ai_status']),
                    (int)($submission['ai_score'] * 100),
                    htmlspecialchars($submission['ai_reason'] ?? 'No explanation provided')
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
