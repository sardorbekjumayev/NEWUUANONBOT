<?php
// src/PublisherService.php

declare(strict_types=1);

namespace UAC;

use PDO;
use Exception;

class PublisherService {
    private PDO $db;
    private TelegramBot $bot;
    private string $channelId;
    private string $botUsername;

    public function __construct(?PDO $db = null, ?TelegramBot $bot = null) {
        $this->db = $db ?? Database::getConnection();
        $this->bot = $bot ?? new TelegramBot();
        $config = require __DIR__ . '/../config.php';
        $this->channelId = (string)$config['telegram']['channel_id'];
        $this->botUsername = (string)$config['telegram']['bot_username'];
    }

    /**
     * Create secure deep-link token for commenting / replying to a post.
     */
    public function createDeepLinkToken(int $submissionId, string $action = 'comment'): string {
        $token = bin2hex(random_bytes(16));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+90 days'));

        $stmt = $this->db->prepare("INSERT INTO deep_links (token, submission_id, action_type, expires_at) VALUES (?, ?, ?, ?)");
        $stmt->execute([$token, $submissionId, $action, $expiresAt]);

        return $token;
    }

    /**
     * Publish approved submission to Telegram Main Channel.
     */
    public function publishToChannel(int $submissionId): bool {
        if (empty($this->channelId)) {
            return false;
        }

        $stmt = $this->db->prepare("SELECT * FROM submissions WHERE id = ?");
        $stmt->execute([$submissionId]);
        $sub = $stmt->fetch();

        if (!$sub || $sub['status'] !== 'approved') {
            return false;
        }

        // Create secure comment token
        $commentToken = $this->createDeepLinkToken($submissionId, 'comment');
        $deepLinkUrl = "https://t.me/" . $this->botUsername . "?start=comment_" . $commentToken;

        $postText = !empty($sub['sanitized_content']) ? $sub['sanitized_content'] : "";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => "✍️ Reply anonymously ↗", 'url' => $deepLinkUrl]
                ]
            ]
        ];

        $mediaType = $sub['media_type'] ?? null;
        $mediaFileId = $sub['media_file_id'] ?? null;

        if ($mediaType === 'photo' && $mediaFileId) {
            $res = $this->bot->sendPhoto($this->channelId, $mediaFileId, $postText, $keyboard);
        } elseif ($mediaType === 'video' && $mediaFileId) {
            $res = $this->bot->sendVideo($this->channelId, $mediaFileId, $postText, $keyboard);
        } else {
            $res = $this->bot->sendMessage($this->channelId, $postText, $keyboard);
        }

        if (isset($res->result->message_id)) {
            $msgId = (int)$res->result->message_id;
            $upd = $this->db->prepare("UPDATE submissions SET channel_message_id = ? WHERE id = ?");
            $upd->execute([$msgId, $submissionId]);
            return true;
        }

        return false;
    }

    /**
     * Delete post from Telegram channel when submission is deleted by user.
     */
    public function deleteFromChannel(int $submissionId): bool {
        $stmt = $this->db->prepare("SELECT channel_message_id FROM submissions WHERE id = ?");
        $stmt->execute([$submissionId]);
        $msgId = $stmt->fetchColumn();

        if ($msgId && !empty($this->channelId)) {
            $this->bot->deleteMessage($this->channelId, (int)$msgId);
            return true;
        }
        return false;
    }
}
