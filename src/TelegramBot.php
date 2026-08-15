<?php
// src/TelegramBot.php

declare(strict_types=1);

namespace UAC;

use Exception;

class TelegramBot {
    private string $botToken;

    public function __construct(?string $token = null) {
        if ($token) {
            $this->botToken = $token;
        } else {
            $config = require __DIR__ . '/../config.php';
            $this->botToken = $config['telegram']['bot_token'];
        }
    }

    /**
     * Core API requester to Telegram Bot API.
     */
    public function call(string $method, array $params = []): mixed {
        $url = "https://api.telegram.org/bot" . $this->botToken . "/" . $method;
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $params,
            CURLOPT_TIMEOUT => 10
        ]);

        $result = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log("Telegram API Error ({$method}): " . $error);
            return null;
        }

        return json_decode($result);
    }

    public function sendMessage(int|string $chatId, string $text, mixed $replyMarkup = null, string $parseMode = 'HTML', bool $disableWebPagePreview = true, ?int $replyToMessageId = null): mixed {
        $data = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => $parseMode,
            'disable_web_page_preview' => $disableWebPagePreview ? 'true' : 'false'
        ];

        if ($replyToMessageId !== null) {
            $data['reply_to_message_id'] = $replyToMessageId;
        }

        if ($replyMarkup !== null) {
            $data['reply_markup'] = is_string($replyMarkup) ? $replyMarkup : json_encode($replyMarkup);
        }

        return $this->call('sendMessage', $data);
    }

    public function editMessageText(int|string $chatId, int $messageId, string $text, mixed $replyMarkup = null, string $parseMode = 'HTML'): mixed {
        $data = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => $parseMode
        ];

        if ($replyMarkup !== null) {
            $data['reply_markup'] = is_string($replyMarkup) ? $replyMarkup : json_encode($replyMarkup);
        }

        return $this->call('editMessageText', $data);
    }

    public function deleteMessage(int|string $chatId, int $messageId): mixed {
        return $this->call('deleteMessage', [
            'chat_id' => $chatId,
            'message_id' => $messageId
        ]);
    }

    public function answerCallbackQuery(string $callbackQueryId, string $text = '', bool $showAlert = false): mixed {
        return $this->call('answerCallbackQuery', [
            'callback_query_id' => $callbackQueryId,
            'text' => $text,
            'show_alert' => $showAlert ? 'true' : 'false'
        ]);
    }

    public function sendPhoto(int|string $chatId, string $photoFileId, string $caption = '', mixed $replyMarkup = null, string $parseMode = 'HTML', ?int $replyToMessageId = null): mixed {
        $data = [
            'chat_id' => $chatId,
            'photo' => $photoFileId,
            'caption' => $caption,
            'parse_mode' => $parseMode
        ];

        if ($replyToMessageId !== null) {
            $data['reply_to_message_id'] = $replyToMessageId;
        }

        if ($replyMarkup !== null) {
            $data['reply_markup'] = is_string($replyMarkup) ? $replyMarkup : json_encode($replyMarkup);
        }

        return $this->call('sendPhoto', $data);
    }

    public function sendVideo(int|string $chatId, string $videoFileId, string $caption = '', mixed $replyMarkup = null, string $parseMode = 'HTML', ?int $replyToMessageId = null): mixed {
        $data = [
            'chat_id' => $chatId,
            'video' => $videoFileId,
            'caption' => $caption,
            'parse_mode' => $parseMode
        ];

        if ($replyToMessageId !== null) {
            $data['reply_to_message_id'] = $replyToMessageId;
        }

        if ($replyMarkup !== null) {
            $data['reply_markup'] = is_string($replyMarkup) ? $replyMarkup : json_encode($replyMarkup);
        }

        return $this->call('sendVideo', $data);
    }

    public function getFile(string $fileId): ?string {
        $res = $this->call('getFile', ['file_id' => $fileId]);
        if ($res && isset($res->ok) && $res->ok && isset($res->result->file_path)) {
            return $res->result->file_path;
        }
        return null;
    }

    public function downloadFileBytes(string $filePath): ?string {
        $url = "https://api.telegram.org/file/bot" . $this->botToken . "/" . $filePath;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        $data = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || !$data) {
            return null;
        }
        return $data;
    }
}
