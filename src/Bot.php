<?php

declare(strict_types=1);

namespace PUAnonymous;

final class Bot
{
    /** @var array<string, array{window:int,count:int}> */
    private static array $rate = [];
    /** @var array<int, int> */
    private static array $seenUpdates = [];

    public function __construct(
        private readonly array $config,
        private readonly Telegram $telegram,
        private readonly AI $ai,
    ) {
    }

    public function handle(array $update): void
    {
        $updateId = (int) ($update['update_id'] ?? 0);
        if ($updateId > 0) {
            if (isset(self::$seenUpdates[$updateId])) {
                return;
            }
            self::$seenUpdates[$updateId] = time();
            if (count(self::$seenUpdates) > 200) {
                asort(self::$seenUpdates);
                self::$seenUpdates = array_slice(self::$seenUpdates, -100, null, true);
            }
        }

        if (isset($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);
            return;
        }

        $message = $update['message'] ?? null;
        if (!is_array($message)) {
            return;
        }

        $chatId = (string) ($message['chat']['id'] ?? '');

        if (($message['chat']['type'] ?? '') === 'private') {
            $this->handlePrivateMessage($message);
            return;
        }

        if ($chatId === (string) $this->config['moderation_group_id']) {
            $this->handleModerationMessage($message);
            return;
        }

        if ($chatId === (string) $this->config['discussion_group_id']) {
            $this->handleDiscussionGroupMessage($message);
            return;
        }
    }

    private function handlePrivateMessage(array $message): void
    {
        $chatId = (string) ($message['chat']['id'] ?? '');
        $text = trim((string) ($message['text'] ?? ''));

        if (str_starts_with($text, '/start')) {
            $arg = trim(substr($text, 6));
            if (str_starts_with($arg, 'comment_')) {
                $this->startComment($chatId, substr($arg, 8));
                return;
            }

            $this->telegram->sendMessage(
                $chatId,
                "👋 <b>PU Anonymous botiga xush kelibsiz!</b>\n\nXabaringizni yuboring. Matnli xabarlar AI orqali tekshirilib, avtomatik kanalga joylanadi. Medialar esa moderatsiyadan o'tadi.\n\n🔒 Shaxsingiz mutlaqo anonim saqlanadi.",
                [
                    'reply_markup' => ['inline_keyboard' => [[['text' => '❓ Yordam', 'callback_data' => 'help']]]],
                ]
            );
            return;
        }

        if ($text === '/help' || $text === '❓ Yordam') {
            $this->sendHelp($chatId);
            return;
        }

        if ($text === '/cancel') {
            $this->telegram->sendMessage($chatId, 'Bekor qilindi.');
            return;
        }

        if (str_starts_with($text, '/')) {
            $this->telegram->sendMessage($chatId, 'Anonim joylash uchun oddiy xabar yuboring.');
            return;
        }

        $replyText = (string) ($message['reply_to_message']['text'] ?? '') ?: (string) ($message['reply_to_message']['caption'] ?? '');
        if (preg_match('~COMMENT_REF:([A-Za-z0-9_.-]+)~', $replyText, $match) === 1) {
            $payload = Helpers::verifyHmacToken($match[1], $this->config['app_secret']);
            if (!is_array($payload) || empty($payload['thread'])) {
                $this->telegram->sendMessage($chatId, 'Ushbu anonim izoh havolasi yaroqsiz yoki muddati o\'tgan.');
                return;
            }

            if (!$this->allowRate((string) ($message['from']['id'] ?? '0'))) {
                $this->telegram->sendMessage($chatId, 'Iltimos, izoh yuborishdan oldin biroz kuting.');
                return;
            }

            $this->submit($message, 'comment', (int) $payload['thread']);
            return;
        }

        if (!$this->allowRate((string) ($message['from']['id'] ?? '0'))) {
            $this->telegram->sendMessage($chatId, 'Iltimos, yangi xabar yuborishdan oldin biroz kuting.');
            return;
        }

        $this->submit($message, 'post');
    }

    private function handleDiscussionGroupMessage(array $message): void
    {
        // Check if message is a new post or automatic forward from channel
        $isChannelPost = !empty($message['is_automatic_forward'])
            || (string) ($message['sender_chat']['id'] ?? '') === (string) $this->config['channel_id']
            || (string) ($message['forward_from_chat']['id'] ?? '') === (string) $this->config['channel_id'];

        if (!$isChannelPost) {
            return;
        }

        $discMessageId = (int) ($message['message_id'] ?? 0);
        if ($discMessageId <= 0 || $this->config['bot_username'] === '') {
            return;
        }

        $token = Helpers::hmacToken(['thread' => $discMessageId], $this->config['app_secret'], 2592000);
        $url = 'https://t.me/' . $this->config['bot_username'] . '?start=comment_' . $token;

        $this->telegram->sendMessage(
            $this->config['discussion_group_id'],
            '💬 <b>Ushbu postga anonim izoh qoldirish uchun quyidagi tugmani bosing:</b>',
            [
                'reply_to_message_id' => $discMessageId,
                'reply_markup' => [
                    'inline_keyboard' => [[
                        ['text' => '✍️ Anonim izoh yozish', 'url' => $url]
                    ]]
                ]
            ]
        );
    }

    private function handleModerationMessage(array $message): void
    {
        $text = trim((string) ($message['text'] ?? ''));
        if (!str_starts_with($text, '/edit ')) {
            return;
        }

        if (!$this->isAdmin((string) ($message['from']['id'] ?? ''))) {
            return;
        }

        $reply = $message['reply_to_message'] ?? null;
        if (!is_array($reply)) {
            $this->telegram->sendMessage($message['chat']['id'], 'Moderatsiya xabariga reply qilib /edit yangi matn ko\'rinishida yuboring.');
            return;
        }

        $newText = trim(substr($text, 6));
        if ($newText === '') {
            return;
        }

        $meta = $this->readMeta($reply);
        if (($meta['status'] ?? '') !== 'WAITING') {
            return;
        }

        $meta['content'] = $newText;
        $this->updateModerationMessage($reply, $meta);
    }

    private function submit(array $message, string $target = 'post', ?int $threadId = null): void
    {
        $chatId = (string) ($message['chat']['id'] ?? '');
        $fromId = (string) ($message['from']['id'] ?? '');
        $userMsgId = (int) ($message['message_id'] ?? 0);
        $content = $this->extractContent($message);

        if ($content['type'] !== 'text' && mb_strlen($content['text']) > (int) $this->config['max_caption_length']) {
            $content['text'] = mb_substr($content['text'], 0, (int) $this->config['max_caption_length']);
        }

        $textForAI = trim($content['text']);

        // Rule: Medias ALWAYS go to manual review queue without AI auto-publish
        if ($content['type'] !== 'text') {
            $ai = ['decision' => 'review', 'category' => 'other', 'media' => true];
        } elseif (mb_strlen($textForAI) > (int) $this->config['max_text_length']) {
            $ai = ['decision' => 'review', 'category' => 'other'];
        } else {
            $ai = $this->ai->classifyText($textForAI);
        }

        $owner = Helpers::seal([
            'u' => $fromId,
            'c' => $chatId,
            'msg' => $userMsgId,
        ], $this->config['app_secret']);

        $meta = [
            'status' => 'WAITING',
            'type' => $content['type'],
            'target' => $target,
            'thread' => $threadId ?? 0,
            'owner' => $owner,
            'content' => $textForAI,
            'ai' => strtoupper((string) ($ai['decision'] ?? 'REVIEW')),
            'category' => strtoupper((string) ($ai['category'] ?? 'OTHER')),
            'unavailable' => !empty($ai['unavailable']),
        ];

        // Rule: If pure text and AI decision is ALLOW -> auto-publish directly without waiting for admin!
        if ($content['type'] === 'text' && ($ai['decision'] ?? '') === 'allow') {
            $publish = $this->publishTextDirect($textForAI, $target, $threadId ?? 0);
            if ($publish['ok']) {
                $publishedId = (int) ($publish['result']['message_id'] ?? 0);
                $delSeal = Helpers::seal([
                    'u' => $fromId,
                    'c' => $chatId,
                    'status' => 'PUBLISHED',
                    'pub_id' => $publishedId,
                    'target' => $target,
                ], $this->config['app_secret']);

                $statusMsg = $target === 'comment'
                    ? '✅ Anonim izohingiz post ostiga joylashtirildi.'
                    : '✅ Xabaringiz anonim ravishda kanalga nashr qilindi.';

                $this->telegram->sendMessage($chatId, $statusMsg, [
                    'reply_to_message_id' => $userMsgId,
                    'reply_markup' => [
                        'inline_keyboard' => [[
                            ['text' => '🗑 Postni o\'chirish', 'callback_data' => 'del:' . $delSeal]
                        ]]
                    ]
                ]);
                Helpers::log('INFO', 'AI auto-published text submission');
                return;
            }
        }

        // Send to Moderation Group for review
        $sent = $this->sendToModeration($content, $meta);
        $moderationMessageId = (int) ($sent['result']['message_id'] ?? 0);
        if ($moderationMessageId > 0) {
            $this->telegram->editReplyMarkup(
                $this->config['moderation_group_id'],
                $moderationMessageId,
                $this->adminKeyboard($moderationMessageId)
            );
        }

        $delSeal = Helpers::seal([
            'u' => $fromId,
            'c' => $chatId,
            'status' => 'WAITING',
            'mod_id' => $moderationMessageId,
            'target' => $target,
        ], $this->config['app_secret']);

        $this->telegram->sendMessage($chatId, '⏳ Xabaringiz moderatsiya tekshiruviga olindi.', [
            'reply_to_message_id' => $userMsgId,
            'reply_markup' => [
                'inline_keyboard' => [[
                    ['text' => '🗑 Yuborishni bekor qilish', 'callback_data' => 'del:' . $delSeal]
                ]]
            ]
        ]);

        Helpers::log('INFO', 'submission queued for moderation', ['type' => $content['type']]);
    }

    private function handleCallback(array $callback): void
    {
        $id = (string) ($callback['id'] ?? '');
        $data = (string) ($callback['data'] ?? '');
        $fromId = (string) ($callback['from']['id'] ?? '');

        if ($data === 'help') {
            $this->telegram->answerCallback($id);
            $this->sendHelp((string) ($callback['message']['chat']['id'] ?? $fromId));
            return;
        }

        if (str_starts_with($data, 'del:')) {
            $this->deleteOwnPost($callback);
            return;
        }

        if (!$this->isAdmin($fromId)) {
            $this->telegram->answerCallback($id, '⛔ Sizda ma\'muriy huquq yo\'q.', true);
            return;
        }

        $message = $callback['message'] ?? null;
        if (!is_array($message)) {
            return;
        }

        [$action, $sig] = array_pad(explode(':', $data, 2), 2, '');
        $messageId = (int) ($message['message_id'] ?? 0);
        if ($action === 'noop' && $this->verifyAction($action, $messageId, $sig)) {
            $this->telegram->answerCallback($id, 'Moderatsiya xabariga reply qilib /edit yangi matn ko\'rinishida yuboring.');
            return;
        }

        if (!in_array($action, ['approve', 'reject'], true) || !$this->verifyAction($action, $messageId, $sig)) {
            $this->telegram->answerCallback($id, 'Amal yaroqsiz yoki muddati o\'tgan.', true);
            return;
        }

        $meta = $this->readMeta($message);
        if (($meta['status'] ?? '') !== 'WAITING') {
            $this->telegram->answerCallback($id, 'Ushbu xabar allaqachon ko\'rib chiqilgan.', true);
            return;
        }

        if ($action === 'reject') {
            $meta['status'] = 'REJECTED';
            $this->updateModerationMessage($message, $meta, false);
            $this->telegram->answerCallback($id, 'Rad etildi.');
            $this->notifyOwnerRejected($meta);
            Helpers::log('INFO', 'moderation rejected');
            return;
        }

        $publish = $this->publish($message, $meta);
        if (!$publish['ok']) {
            $this->telegram->answerCallback($id, 'Kanalga joylashda xatolik.', true);
            return;
        }

        $channelMessageId = (int) ($publish['result']['message_id'] ?? 0);
        $meta['status'] = 'PUBLISHED';
        $this->updateModerationMessage($message, $meta, false);
        $this->notifyOwnerPublished($meta, $channelMessageId);
        $this->telegram->answerCallback($id, 'Chop etildi.');
        Helpers::log('INFO', 'channel post published');
    }

    private function publishTextDirect(string $content, string $target, int $threadId): array
    {
        $caption = $content === '' ? 'PU Anonymous' : "PU Anonymous\n\n" . $content;
        $extra = [];

        if ($target === 'comment' && $threadId > 0) {
            $extra['reply_to_message_id'] = $threadId;
            $extra['message_thread_id'] = $threadId;
        }

        return $this->telegram->sendMessage(
            $target === 'comment' ? $this->config['discussion_group_id'] : $this->config['channel_id'],
            $caption,
            $extra
        );
    }

    private function publish(array $message, array $meta): array
    {
        $content = trim((string) ($meta['content'] ?? ''));
        $target = (string) ($meta['target'] ?? 'post');
        $threadId = (int) ($meta['thread'] ?? 0);
        $caption = $content === '' ? 'PU Anonymous' : "PU Anonymous\n\n" . $content;
        $extra = [];

        if ($target === 'comment' && $threadId > 0) {
            $extra['reply_to_message_id'] = $threadId;
            $extra['message_thread_id'] = $threadId;
        }

        if (($meta['type'] ?? 'text') === 'text') {
            return $this->telegram->sendMessage(
                $target === 'comment' ? $this->config['discussion_group_id'] : $this->config['channel_id'],
                $caption,
                $extra
            );
        }

        $sourceMessageId = (int) ($meta['media_message'] ?? $message['message_id'] ?? 0);

        return $this->telegram->copyMessage(
            $target === 'comment' ? $this->config['discussion_group_id'] : $this->config['channel_id'],
            $this->config['moderation_group_id'],
            $sourceMessageId,
            array_merge($extra, ($meta['type'] ?? '') === 'sticker' ? [] : ['caption' => $caption, 'parse_mode' => 'HTML'])
        );
    }

    private function sendToModeration(array $content, array $meta): array
    {
        $text = $this->formatModeration($meta);
        if ($content['type'] === 'text' || $content['type'] === 'unsupported') {
            return $this->telegram->sendMessage($this->config['moderation_group_id'], $text);
        }

        if ($content['type'] === 'sticker') {
            $sticker = $this->telegram->sendMediaByType(
                $this->config['moderation_group_id'],
                'sticker',
                $content['file_id'],
                ''
            );
            $meta['media_message'] = (int) ($sticker['result']['message_id'] ?? 0);
            return $this->telegram->sendMessage(
                $this->config['moderation_group_id'],
                $this->formatModeration($meta),
                $meta['media_message'] > 0 ? ['reply_to_message_id' => $meta['media_message']] : []
            );
        }

        return $this->telegram->sendMediaByType(
            $this->config['moderation_group_id'],
            $content['type'],
            $content['file_id'],
            $text
        );
    }

    private function extractContent(array $message): array
    {
        if (isset($message['text'])) {
            return ['type' => 'text', 'text' => trim((string) $message['text']), 'file_id' => ''];
        }

        $caption = trim((string) ($message['caption'] ?? ''));
        if (isset($message['photo'])) {
            $photos = $message['photo'];
            $best = end($photos);
            return ['type' => 'photo', 'text' => $caption, 'file_id' => (string) ($best['file_id'] ?? '')];
        }

        foreach (['video', 'animation', 'document', 'sticker'] as $type) {
            if (isset($message[$type]['file_id'])) {
                return ['type' => $type, 'text' => $caption, 'file_id' => (string) $message[$type]['file_id']];
            }
        }

        return ['type' => 'unsupported', 'text' => '[Unsupported Telegram content]', 'file_id' => ''];
    }

    private function formatModeration(array $meta): string
    {
        $statusIcon = match ($meta['status'] ?? 'WAITING') {
            'PUBLISHED' => '🟢 Published',
            'REJECTED' => '🔴 Rejected',
            default => '🟡 Waiting',
        };
        $aiLine = !empty($meta['unavailable'])
            ? "⚠️ AI unavailable\nManual review required"
            : match ($meta['ai'] ?? 'REVIEW') {
                'ALLOW' => '🤖 AI: SAFE',
                'REJECT' => '🔴 AI suggested rejection',
                default => '⚠️ AI: REVIEW',
            };
        $content = trim((string) ($meta['content'] ?? ''));

        return "🆕 Anonymous Submission\n\n"
            . ($content === '' ? '[media only]' : htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))
            . "\n\n{$aiLine}\nCategory: " . htmlspecialchars((string) ($meta['category'] ?? 'OTHER'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . "\nStatus: {$statusIcon}"
            . "\n\nRef: " . htmlspecialchars((string) ($meta['owner'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . "\nType: " . htmlspecialchars((string) ($meta['type'] ?? 'text'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . "\nTarget: " . htmlspecialchars((string) ($meta['target'] ?? 'post'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . "\nThread: " . (int) ($meta['thread'] ?? 0)
            . "\nMedia: " . (int) ($meta['media_message'] ?? 0);
    }

    private function readMeta(array $message): array
    {
        $text = (string) ($message['text'] ?? $message['caption'] ?? '');
        preg_match('~Status:\s*(.+)~u', $text, $status);
        preg_match('~Ref:\s*([A-Za-z0-9_-]+)~', $text, $owner);
        preg_match('~Type:\s*(\w+)~', $text, $type);
        preg_match('~Target:\s*(\w+)~', $text, $target);
        preg_match('~Thread:\s*(\d+)~', $text, $thread);
        preg_match('~Media:\s*(\d+)~', $text, $media);

        $contentBlock = trim((string) preg_replace('~^🆕 Anonymous Submission\s*|\n\n(?:🤖|⚠️|🔴).*~us', '', $text));
        $contentBlock = $contentBlock === '[media only]' ? '' : html_entity_decode($contentBlock, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return [
            'status' => str_contains($status[1] ?? '', 'Published') ? 'PUBLISHED' : (str_contains($status[1] ?? '', 'Rejected') ? 'REJECTED' : 'WAITING'),
            'owner' => $owner[1] ?? '',
            'type' => $type[1] ?? 'text',
            'target' => $target[1] ?? 'post',
            'thread' => (int) ($thread[1] ?? 0),
            'media_message' => (int) ($media[1] ?? 0),
            'content' => $contentBlock,
            'ai' => 'REVIEW',
            'category' => 'OTHER',
        ];
    }

    private function updateModerationMessage(array $message, array $meta, bool $withKeyboard = true): void
    {
        $messageId = (int) ($message['message_id'] ?? 0);
        $markup = $withKeyboard ? $this->adminKeyboard($messageId) : ['inline_keyboard' => []];
        $text = $this->formatModeration($meta);

        if (isset($message['text'])) {
            $this->telegram->editMessageText($this->config['moderation_group_id'], $messageId, $text, ['reply_markup' => $markup]);
        } else {
            $this->telegram->editMessageCaption($this->config['moderation_group_id'], $messageId, $text, ['reply_markup' => $markup]);
        }
    }

    private function adminKeyboard(int $messageId): array
    {
        return ['inline_keyboard' => [[
            ['text' => '✅ Approve', 'callback_data' => 'approve:' . $this->actionSig('approve', $messageId)],
            ['text' => '❌ Reject', 'callback_data' => 'reject:' . $this->actionSig('reject', $messageId)],
        ], [
            ['text' => '✏️ Edit by reply', 'callback_data' => 'noop:' . $this->actionSig('noop', $messageId)],
        ]]];
    }

    private function actionSig(string $action, int $messageId): string
    {
        return substr(Helpers::b64(hash_hmac('sha256', $action . '|' . $messageId, $this->config['app_secret'], true)), 0, 16);
    }

    private function verifyAction(string $action, int $messageId, string $sig): bool
    {
        return hash_equals($this->actionSig($action, $messageId), $sig);
    }

    private function notifyOwnerPublished(array $meta, int $publishedMessageId): void
    {
        $owner = Helpers::openSeal((string) ($meta['owner'] ?? ''), $this->config['app_secret']);
        if (!is_array($owner) || empty($owner['c']) || empty($owner['u']) || $publishedMessageId <= 0) {
            return;
        }

        $delSeal = Helpers::seal([
            'u' => (string) $owner['u'],
            'c' => (string) $owner['c'],
            'status' => 'PUBLISHED',
            'pub_id' => $publishedMessageId,
            'target' => (string) ($meta['target'] ?? 'post'),
        ], $this->config['app_secret']);

        $replyMsgId = (int) ($owner['msg'] ?? 0);
        $extra = [
            'reply_markup' => [
                'inline_keyboard' => [[
                    ['text' => '🗑 Postni o\'chirish', 'callback_data' => 'del:' . $delSeal]
                ]]
            ]
        ];
        if ($replyMsgId > 0) {
            $extra['reply_to_message_id'] = $replyMsgId;
        }

        $this->telegram->sendMessage((string) $owner['c'], '✅ Xabaringiz moderatsiyadan o\'tdi va anonim ravishda nashr qilindi.', $extra);
    }

    private function notifyOwnerRejected(array $meta): void
    {
        $owner = Helpers::openSeal((string) ($meta['owner'] ?? ''), $this->config['app_secret']);
        if (!is_array($owner) || empty($owner['c'])) {
            return;
        }

        $replyMsgId = (int) ($owner['msg'] ?? 0);
        $extra = [];
        if ($replyMsgId > 0) {
            $extra['reply_to_message_id'] = $replyMsgId;
        }

        $this->telegram->sendMessage((string) $owner['c'], '🔴 Xabaringiz moderatsiyadan o\'tmadi va rad etildi.', $extra);
    }

    private function deleteOwnPost(array $callback): void
    {
        $id = (string) ($callback['id'] ?? '');
        $fromId = (string) ($callback['from']['id'] ?? '');
        $data = (string) ($callback['data'] ?? '');
        $seal = substr($data, 4);

        $payload = Helpers::openSeal($seal, $this->config['app_secret']);
        if (!is_array($payload) || (string) ($payload['u'] ?? '') !== $fromId) {
            $this->telegram->answerCallback($id, '⛔ Ushbu tugma sizga tegishli emas.', true);
            return;
        }

        $status = (string) ($payload['status'] ?? '');
        $target = (string) ($payload['target'] ?? 'post');
        $chatId = (string) ($payload['c'] ?? $fromId);

        if ($status === 'WAITING') {
            $modId = (int) ($payload['mod_id'] ?? 0);
            if ($modId > 0) {
                $this->telegram->deleteMessage($this->config['moderation_group_id'], $modId);
            }
            $this->telegram->answerCallback($id, 'Xabar bekor qilindi.');
            if (isset($callback['message']['message_id'])) {
                $this->telegram->editMessageText($chatId, (int) $callback['message']['message_id'], '🗑 Xabaringiz moderatsiyadan olindi va o\'chirildi.');
            } else {
                $this->telegram->sendMessage($chatId, '🗑 Xabaringiz moderatsiyadan olindi va o\'chirildi.');
            }
            return;
        }

        if ($status === 'PUBLISHED') {
            $pubId = (int) ($payload['pub_id'] ?? 0);
            if ($pubId > 0) {
                $targetChat = $target === 'comment' ? $this->config['discussion_group_id'] : $this->config['channel_id'];
                $this->telegram->deleteMessage($targetChat, $pubId);
            }
            $this->telegram->answerCallback($id, 'Post o\'chirildi.');
            if (isset($callback['message']['message_id'])) {
                $this->telegram->editMessageText($chatId, (int) $callback['message']['message_id'], '🗑 Nashr qilingan xabaringiz o\'chirildi.');
            } else {
                $this->telegram->sendMessage($chatId, '🗑 Nashr qilingan xabaringiz o\'chirildi.');
            }
        }
    }

    private function startComment(string $chatId, string $token): void
    {
        $payload = Helpers::verifyHmacToken($token, $this->config['app_secret']);
        if (!is_array($payload) || empty($payload['thread'])) {
            $this->telegram->sendMessage($chatId, 'Ushbu anonim izoh havolasi yaroqsiz yoki muddati o\'tgan.');
            return;
        }

        $this->telegram->sendMessage(
            $chatId,
            "✍️ <b>Ushbu postga anonim izoh qoldirish:</b>\n\nQuyidagi kiritish maydoniga izohingizni yozib yuboring.\n\nCOMMENT_REF:" . htmlspecialchars($token, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            [
                'reply_markup' => [
                    'force_reply' => true,
                    'selective' => true,
                ]
            ]
        );
    }

    private function sendHelp(string $chatId): void
    {
        $this->telegram->sendMessage($chatId, "❓ Qanday ishlaydi\n\n1. Xabaringizni yuboring.\n2. Matnlar AI orqali avtomatik joylanadi.\n3. Medialar adminlar tomonidan tekshiriladi.\n4. Qabul qilinsa anonim nashr etiladi.\n\nSiz kanal postlariga ham anonim izoh qoldirishingiz mumkin.\n\n🔒 Shaxsingiz mutlaqo oshkor etilmaydi.");
    }

    private function allowRate(string $sender): bool
    {
        $now = time();
        $key = hash_hmac('sha256', $sender, $this->config['app_secret']);
        $window = intdiv($now, 60);
        $entry = self::$rate[$key] ?? ['window' => $window, 'count' => 0];
        if ($entry['window'] !== $window) {
            $entry = ['window' => $window, 'count' => 0];
        }

        $entry['count']++;
        self::$rate[$key] = $entry;

        return $entry['count'] <= (int) $this->config['rate_limit_per_minute'];
    }

    private function isAdmin(string $telegramId): bool
    {
        return in_array($telegramId, array_map('strval', $this->config['admin_ids']), true);
    }
}
