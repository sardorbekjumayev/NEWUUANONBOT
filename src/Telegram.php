<?php

declare(strict_types=1);

namespace PUAnonymous;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

final class Telegram
{
    private Client $client;

    public function __construct(private readonly string $token)
    {
        $this->client = new Client([
            'base_uri' => 'https://api.telegram.org/bot' . $this->token . '/',
            'timeout' => 18,
        ]);
    }

    public function call(string $method, array $params = []): array
    {
        try {
            $response = $this->client->post($method, ['json' => $params]);
            $data = json_decode((string) $response->getBody(), true);
        } catch (GuzzleException $e) {
            Helpers::log('ERROR', 'Telegram API failed', ['method' => $method, 'error' => $e->getMessage()]);
            return ['ok' => false, 'description' => 'Telegram API failed'];
        }

        if (!is_array($data) || ($data['ok'] ?? false) !== true) {
            Helpers::log('ERROR', 'Telegram API failed', [
                'method' => $method,
                'description' => is_array($data) ? ($data['description'] ?? 'unknown') : 'invalid json',
            ]);
            return is_array($data) ? $data : ['ok' => false];
        }

        return $data;
    }

    public function sendMessage(string|int $chatId, string $text, array $extra = []): array
    {
        return $this->call('sendMessage', array_merge([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ], $extra));
    }

    public function editMessageText(string|int $chatId, int $messageId, string $text, array $extra = []): array
    {
        return $this->call('editMessageText', array_merge([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ], $extra));
    }

    public function editMessageCaption(string|int $chatId, int $messageId, string $caption, array $extra = []): array
    {
        return $this->call('editMessageCaption', array_merge([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'caption' => $caption,
            'parse_mode' => 'HTML',
        ], $extra));
    }

    public function editReplyMarkup(string|int $chatId, int $messageId, array $markup = []): array
    {
        return $this->call('editMessageReplyMarkup', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'reply_markup' => $markup,
        ]);
    }

    public function answerCallback(string $id, string $text = '', bool $alert = false): array
    {
        return $this->call('answerCallbackQuery', [
            'callback_query_id' => $id,
            'text' => $text,
            'show_alert' => $alert,
        ]);
    }

    public function copyMessage(string|int $to, string|int $from, int $messageId, array $extra = []): array
    {
        return $this->call('copyMessage', array_merge([
            'chat_id' => $to,
            'from_chat_id' => $from,
            'message_id' => $messageId,
        ], $extra));
    }

    public function deleteMessage(string|int $chatId, int $messageId): array
    {
        return $this->call('deleteMessage', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
        ]);
    }

    public function sendMediaByType(string|int $chatId, string $type, string $fileId, string $caption, array $extra = []): array
    {
        $map = [
            'photo' => ['sendPhoto', 'photo'],
            'video' => ['sendVideo', 'video'],
            'animation' => ['sendAnimation', 'animation'],
            'document' => ['sendDocument', 'document'],
            'sticker' => ['sendSticker', 'sticker'],
        ];

        if (!isset($map[$type])) {
            return ['ok' => false, 'description' => 'Unsupported media'];
        }

        [$method, $field] = $map[$type];
        $params = array_merge([
            'chat_id' => $chatId,
            $field => $fileId,
        ], $extra);

        if ($type !== 'sticker' && $caption !== '') {
            $params['caption'] = $caption;
            $params['parse_mode'] = 'HTML';
        }

        return $this->call($method, $params);
    }
}
