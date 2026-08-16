<?php

declare(strict_types=1);

namespace PUAnonymous;

class Telegram
{
    public function __construct(private readonly string $token)
    {
    }

    public function call(string $method, array $params = []): array
    {
        $url = 'https://api.telegram.org/bot' . $this->token . '/' . $method;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($params),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 18,
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            Helpers::log('ERROR', 'Telegram API failed', ['method' => $method, 'error' => $error]);
            return ['ok' => false, 'description' => 'Telegram API failed: ' . $error];
        }

        $data = json_decode((string) $response, true);
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
        $params = array_merge([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ], $extra);

        $res = $this->call('sendMessage', $params);

        if (!($res['ok'] ?? false)) {
            if (isset($params['reply_to_message_id'])) {
                unset($params['reply_to_message_id']);
                $res = $this->call('sendMessage', $params);
            }
            if (!($res['ok'] ?? false) && isset($params['parse_mode'])) {
                unset($params['parse_mode']);
                $res = $this->call('sendMessage', $params);
            }
        }

        return $res;
    }

    public function editMessageText(string|int $chatId, int $messageId, string $text, array $extra = []): array
    {
        $params = array_merge([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ], $extra);

        $res = $this->call('editMessageText', $params);

        if (!($res['ok'] ?? false) && isset($params['parse_mode'])) {
            unset($params['parse_mode']);
            $res = $this->call('editMessageText', $params);
        }

        return $res;
    }

    public function editMessageCaption(string|int $chatId, int $messageId, string $caption, array $extra = []): array
    {
        $params = array_merge([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'caption' => $caption,
            'parse_mode' => 'HTML',
        ], $extra);

        $res = $this->call('editMessageCaption', $params);

        if (!($res['ok'] ?? false) && isset($params['parse_mode'])) {
            unset($params['parse_mode']);
            $res = $this->call('editMessageCaption', $params);
        }

        return $res;
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
        $params = array_merge([
            'chat_id' => $to,
            'from_chat_id' => $from,
            'message_id' => $messageId,
        ], $extra);

        $res = $this->call('copyMessage', $params);

        if (!($res['ok'] ?? false) && isset($params['reply_to_message_id'])) {
            unset($params['reply_to_message_id']);
            $res = $this->call('copyMessage', $params);
        }
        if (!($res['ok'] ?? false) && isset($params['parse_mode'])) {
            unset($params['parse_mode']);
            $res = $this->call('copyMessage', $params);
        }

        return $res;
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

        $res = $this->call($method, $params);

        if (!($res['ok'] ?? false)) {
            if (isset($params['reply_to_message_id'])) {
                unset($params['reply_to_message_id']);
                $res = $this->call($method, $params);
            }
            if (!($res['ok'] ?? false) && isset($params['parse_mode'])) {
                unset($params['parse_mode']);
                $res = $this->call($method, $params);
            }
        }

        return $res;
    }

}
