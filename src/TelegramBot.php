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

    public function sendMessage(int|string $chatId, string $text, mixed $replyMarkup = null, string $parseMode = 'HTML', bool $disableWebPagePreview = true): mixed {
        $data = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => $parseMode,
            'disable_web_page_preview' => $disableWebPagePreview ? 'true' : 'false'
        ];

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
}
