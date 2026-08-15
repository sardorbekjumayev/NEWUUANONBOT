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

        if ($text === '/admin') {
            if (!$this->isAdmin((string) ($message['from']['id'] ?? ''))) {
                $this->telegram->sendMessage($chatId, '⛔ Bu bo\'lim faqat adminlar uchun.');
                return;
            }

            $this->telegram->sendMessage($chatId, $this->adminAiText(), [
                'reply_markup' => $this->adminAiKeyboard(),
            ]);
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

        $this->submit($message, 'post', $this->privateChannelReplyTargetId($message));
    }

    private function handleDiscussionGroupMessage(array $message): void
    {
        if ($this->isChannelDiscussionPost($message)) {
            $this->sendAnonymousReplyButton($message);
            return;
        }

        $this->handleDiscussionAnonymousMessage($message);
    }

    private function sendAnonymousReplyButton(array $message): void
    {
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

    private function handleDiscussionAnonymousMessage(array $message): void
    {
        if (!empty($message['from']['is_bot'])) {
            return;
        }

        $text = trim((string) ($message['text'] ?? $message['caption'] ?? ''));
        $isAnonCommand = preg_match('~^/anon(?:@\w+)?(?:\s+(.+))?$~su', $text, $anonMatch) === 1;
        $isReply = isset($message['reply_to_message']);

        if (!$isAnonCommand && !$isReply) {
            return;
        }

        $content = $this->extractContent($message);
        if ($isAnonCommand && $content['type'] === 'text') {
            $content['text'] = trim((string) ($anonMatch[1] ?? ''));
            if ($content['text'] === '') {
                $this->telegram->deleteMessage($this->config['discussion_group_id'], (int) ($message['message_id'] ?? 0));
                return;
            }
        } elseif ($isAnonCommand && $content['type'] !== 'sticker') {
            $content['text'] = trim((string) preg_replace('~^/anon(?:@\w+)?\s*~u', '', $content['text']));
        }

        $replyToId = $isReply ? $this->resolveDiscussionReplyTargetId($message['reply_to_message']) : 0;
        $threadId = (int) ($message['message_thread_id'] ?? 0);
        $this->telegram->deleteMessage($this->config['discussion_group_id'], (int) ($message['message_id'] ?? 0));
        $publish = $this->publishDiscussionAnonymous($content, $replyToId, $threadId);

        if (!($publish['ok'] ?? false)) {
            Helpers::log('ERROR', 'anonymous discussion publish failed', ['description' => $publish['description'] ?? 'unknown']);
            return;
        }

        $publishedId = (int) ($publish['result']['message_id'] ?? 0);
        $fromId = (string) ($message['from']['id'] ?? '');
        if ($publishedId > 0 && $fromId !== '') {
            $this->telegram->editReplyMarkup($this->config['discussion_group_id'], $publishedId, [
                'inline_keyboard' => [[
                    ['text' => '🗑 O\'chirish', 'callback_data' => $this->deleteCallback('delp', 'comment', $publishedId, $fromId)]
                ]]
            ]);
        }
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
        $checking = $this->telegram->sendMessage($chatId, '⏳ Xabaringiz tekshirilmoqda...', [
            'reply_to_message_id' => $userMsgId,
        ]);
        $checkingId = (int) ($checking['result']['message_id'] ?? 0);
        $content = $this->extractContent($message);

        if ($content['type'] !== 'text' && mb_strlen($content['text']) > (int) $this->config['max_caption_length']) {
            $content['text'] = mb_substr($content['text'], 0, (int) $this->config['max_caption_length']);
        }

        $textForAI = trim($content['text']);

        if ($target === 'comment') {
            $publish = $this->publishContentDirect($content, 'comment', $threadId ?? 0);
            if ($publish['ok']) {
                $publishedId = (int) ($publish['result']['message_id'] ?? 0);
                $statusMsg = '✅ Anonim izohingiz yuborildi.';
                $statusExtra = [
                    'reply_markup' => [
                        'inline_keyboard' => [[
                            ['text' => '🗑 Izohni o\'chirish', 'callback_data' => $this->deleteCallback('delp', 'comment', $publishedId, $fromId)]
                        ]]
                    ]
                ];

                if ($checkingId > 0) {
                    $this->telegram->editMessageText($chatId, $checkingId, $statusMsg, $statusExtra);
                } else {
                    $statusExtra['reply_to_message_id'] = $userMsgId;
                    $this->telegram->sendMessage($chatId, $statusMsg, $statusExtra);
                }
                Helpers::log('INFO', 'anonymous comment published');
                return;
            }

            $failText = '⚠️ Izohni yuborishda xatolik bo\'ldi. Iltimos, keyinroq qayta urinib ko\'ring.';
            if ($checkingId > 0) {
                $this->telegram->editMessageText($chatId, $checkingId, $failText);
            } else {
                $this->telegram->sendMessage($chatId, $failText, ['reply_to_message_id' => $userMsgId]);
            }
            return;
        }

        $matchedWords = $content['type'] === 'text' ? $this->findBadWords($textForAI) : [];

        // Rule: local wordlist is checked before AI so known bad words are cheap and deterministic.
        if ($matchedWords !== []) {
            $ai = ['decision' => 'review', 'category' => 'wordlist', 'local_wordlist' => true];
        } elseif ($content['type'] !== 'text') {
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
            'local_wordlist' => !empty($ai['local_wordlist']),
            'matched_words' => $matchedWords,
        ];

        // Rule: If pure text and AI decision is ALLOW -> auto-publish directly without waiting for admin!
        if ($content['type'] === 'text' && ($ai['decision'] ?? '') === 'allow') {
            $publish = $this->publishTextDirect($textForAI, $target, $threadId ?? 0);
            if ($publish['ok']) {
                $publishedId = (int) ($publish['result']['message_id'] ?? 0);
                $statusMsg = $target === 'comment'
                    ? '✅ Anonim izohingiz yuborildi va post ostiga joylashtirildi.'
                    : '✅ Xabaringiz yuborildi va anonim ravishda kanalga nashr qilindi.';

                $statusExtra = [
                    'reply_markup' => [
                        'inline_keyboard' => [[
                            ['text' => '🗑 Postni o\'chirish', 'callback_data' => $this->deleteCallback('delp', $target, $publishedId, $fromId)]
                        ]]
                    ]
                ];
                if ($checkingId > 0) {
                    $this->telegram->editMessageText($chatId, $checkingId, $statusMsg, $statusExtra);
                } else {
                    $statusExtra['reply_to_message_id'] = $userMsgId;
                    $this->telegram->sendMessage($chatId, $statusMsg, $statusExtra);
                }
                Helpers::log('INFO', 'AI auto-published text submission');
                return;
            }
        }

        // Send to Moderation Group for review
        $sent = $this->sendToModeration($content, $meta);
        $moderationMessageId = (int) ($sent['result']['message_id'] ?? 0);
        if ($moderationMessageId <= 0) {
            $failText = '⚠️ Xabaringizni yuborishda xatolik bo\'ldi. Iltimos, keyinroq qayta urinib ko\'ring.';
            if ($checkingId > 0) {
                $this->telegram->editMessageText($chatId, $checkingId, $failText);
            } else {
                $this->telegram->sendMessage($chatId, $failText, ['reply_to_message_id' => $userMsgId]);
            }
            return;
        }

        if ($moderationMessageId > 0) {
            $this->telegram->editReplyMarkup(
                $this->config['moderation_group_id'],
                $moderationMessageId,
                $this->adminKeyboard($moderationMessageId)
            );
        }

        $queuedText = match (strtolower((string) ($ai['decision'] ?? 'review'))) {
            'reject' => '⚠️ Xabaringiz filterda ushlab qolindi va admin tekshiruviga yuborildi.',
            default => $content['type'] === 'text'
                ? '⚠️ Xabaringiz filter/admin tekshiruviga yuborildi.'
                : '⚠️ Media xabaringiz admin tekshiruviga yuborildi.',
        };

        $queuedExtra = [
            'reply_markup' => [
                'inline_keyboard' => [[
                    ['text' => '🗑 Yuborishni bekor qilish', 'callback_data' => $this->deleteCallback('delw', $target, $moderationMessageId, $fromId)]
                ]]
            ]
        ];
        if ($checkingId > 0) {
            $this->telegram->editMessageText($chatId, $checkingId, $queuedText, $queuedExtra);
        } else {
            $queuedExtra['reply_to_message_id'] = $userMsgId;
            $this->telegram->sendMessage($chatId, $queuedText, $queuedExtra);
        }

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

        if (str_starts_with($data, 'delw:') || str_starts_with($data, 'delp:') || str_starts_with($data, 'del:')) {
            $this->deleteOwnPost($callback);
            return;
        }

        if (str_starts_with($data, 'adminai:')) {
            $this->handleAdminAiCallback($callback);
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

        if (str_starts_with($data, 'learnbad:')) {
            $this->handleLearnBadWordCallback($callback, $message);
            return;
        }

        [$action, $sig] = array_pad(explode(':', $data, 2), 2, '');
        $messageId = (int) ($message['message_id'] ?? 0);
        if ($action === 'noop' && $this->verifyAction($action, $messageId, $sig)) {
            $this->telegram->answerCallback($id, 'Moderatsiya xabariga reply qilib /edit yangi matn ko\'rinishida yuboring.');
            return;
        }

        if (!in_array($action, ['approve', 'reject', 'rejectfinal'], true) || !$this->verifyAction($action, $messageId, $sig)) {
            $this->telegram->answerCallback($id, 'Amal yaroqsiz yoki muddati o\'tgan.', true);
            return;
        }

        $meta = $this->readMeta($message);
        if (($meta['status'] ?? '') !== 'WAITING') {
            $this->telegram->answerCallback($id, 'Ushbu xabar allaqachon ko\'rib chiqilgan.', true);
            return;
        }

        if ($action === 'reject') {
            $this->askRejectReason($callback, $message, $meta);
            return;
        }

        if ($action === 'rejectfinal') {
            $meta['status'] = 'REJECTED';
            $this->updateModerationMessage($message, $meta, false);
            $this->telegram->answerCallback($id, 'Rad etildi.');
            $this->notifyOwnerRejected($meta);
            Helpers::log('INFO', 'moderation rejected');
            return;
        }

        $publish = $this->publish($message, $meta);
        if (!$publish['ok']) {
            $this->telegram->answerCallback($id, 'Kanalga joylashda xatolik: ' . $this->shortError($publish), true);
            return;
        }

        $channelMessageId = (int) ($publish['result']['message_id'] ?? 0);
        $meta['status'] = 'PUBLISHED';
        $this->updateModerationMessage($message, $meta, false);
        $this->notifyOwnerPublished($meta, $channelMessageId);
        $this->telegram->answerCallback($id, 'Chop etildi.');
        Helpers::log('INFO', 'channel post published');
    }

    private function askRejectReason(array $callback, array $message, array $meta): void
    {
        $messageId = (int) ($message['message_id'] ?? 0);
        $this->telegram->editReplyMarkup(
            $this->config['moderation_group_id'],
            $messageId,
            $this->rejectReasonKeyboard($messageId, (string) ($meta['content'] ?? ''))
        );
        $this->telegram->answerCallback((string) ($callback['id'] ?? ''), 'Qaysi so\'z sabab rad qilindi?');
    }

    private function handleLearnBadWordCallback(array $callback, array $message): void
    {
        $id = (string) ($callback['id'] ?? '');
        $data = (string) ($callback['data'] ?? '');
        $messageId = (int) ($message['message_id'] ?? 0);
        [, $index, $sig] = array_pad(explode(':', $data, 3), 3, '');

        if (!ctype_digit($index) || !$this->verifyAction('learnbad:' . $index, $messageId, $sig)) {
            $this->telegram->answerCallback($id, 'Amal yaroqsiz yoki muddati o\'tgan.', true);
            return;
        }

        $meta = $this->readMeta($message);
        if (($meta['status'] ?? '') !== 'WAITING') {
            $this->telegram->answerCallback($id, 'Ushbu xabar allaqachon ko\'rib chiqilgan.', true);
            return;
        }

        $candidates = $this->badWordCandidates((string) ($meta['content'] ?? ''));
        $word = $candidates[(int) $index] ?? '';
        if ($word === '') {
            $this->telegram->answerCallback($id, 'So\'z topilmadi.', true);
            return;
        }

        $this->appendBadWord($word);
        $meta['status'] = 'REJECTED';
        $meta['matched_words'] = array_values(array_unique(array_merge((array) ($meta['matched_words'] ?? []), [$word])));
        $this->updateModerationMessage($message, $meta, false);
        $this->notifyOwnerRejected($meta);
        $this->telegram->answerCallback($id, 'Rad etildi va wordlistga qo\'shildi: ' . $word);
        Helpers::log('INFO', 'moderation rejected and word learned');
    }

    private function rejectReasonKeyboard(int $messageId, string $content): array
    {
        $rows = [];
        $row = [];
        foreach ($this->badWordCandidates($content) as $index => $word) {
            $row[] = [
                'text' => '🚫 ' . mb_substr($word, 0, 24),
                'callback_data' => 'learnbad:' . $index . ':' . $this->actionSig('learnbad:' . $index, $messageId),
            ];
            if (count($row) === 2) {
                $rows[] = $row;
                $row = [];
            }
        }
        if ($row !== []) {
            $rows[] = $row;
        }

        $rows[] = [[
            'text' => '🚫 Faqat rad etish',
            'callback_data' => 'rejectfinal:' . $this->actionSig('rejectfinal', $messageId),
        ]];

        return ['inline_keyboard' => $rows];
    }

    private function publishTextDirect(string $content, string $target, int $threadId): array
    {
        $caption = $this->publicText($content);
        $extra = [];

        if ($target === 'comment' && $threadId > 0) {
            $extra['reply_to_message_id'] = $threadId;
            $extra['message_thread_id'] = $threadId;
        } elseif ($target === 'post' && $threadId > 0) {
            $extra['reply_to_message_id'] = $threadId;
        }

        return $this->telegram->sendMessage(
            $target === 'comment' ? $this->config['discussion_group_id'] : $this->config['channel_id'],
            $caption,
            $extra
        );
    }

    private function publishContentDirect(array $content, string $target, int $threadId): array
    {
        $caption = $this->publicText((string) ($content['text'] ?? ''));
        $chatId = $target === 'comment' ? $this->config['discussion_group_id'] : $this->config['channel_id'];
        $extra = [];

        if ($target === 'comment' && $threadId > 0) {
            $extra['reply_to_message_id'] = $threadId;
            $extra['message_thread_id'] = $threadId;
        } elseif ($target === 'post' && $threadId > 0) {
            $extra['reply_to_message_id'] = $threadId;
        }

        if (($content['type'] ?? 'text') === 'text') {
            return $this->telegram->sendMessage($chatId, $caption, $extra);
        }

        if (($content['type'] ?? '') === 'unsupported') {
            return ['ok' => false, 'description' => 'Unsupported comment content'];
        }

        return $this->telegram->sendMediaByType(
            $chatId,
            (string) $content['type'],
            (string) $content['file_id'],
            ($content['type'] ?? '') === 'sticker' ? '' : $caption,
            $extra
        );
    }

    private function publishDiscussionAnonymous(array $content, int $replyToId, int $threadId): array
    {
        $caption = $this->publicText((string) ($content['text'] ?? ''));
        $extra = [];

        if ($threadId > 0) {
            $extra['message_thread_id'] = $threadId;
        }
        if ($replyToId > 0) {
            $extra['reply_to_message_id'] = $replyToId;
        }

        if (($content['type'] ?? 'text') === 'text') {
            if ($caption === '') {
                return ['ok' => false, 'description' => 'Empty anonymous text'];
            }

            return $this->telegram->sendMessage($this->config['discussion_group_id'], $caption, $extra);
        }

        if (($content['type'] ?? '') === 'unsupported') {
            return ['ok' => false, 'description' => 'Unsupported discussion content'];
        }

        return $this->telegram->sendMediaByType(
            $this->config['discussion_group_id'],
            (string) $content['type'],
            (string) $content['file_id'],
            ($content['type'] ?? '') === 'sticker' ? '' : $caption,
            $extra
        );
    }

    private function isChannelDiscussionPost(array $message): bool
    {
        if (!empty($message['is_automatic_forward'])) {
            return true;
        }

        if (($message['sender_chat']['type'] ?? '') === 'channel') {
            return true;
        }

        if (($message['forward_from_chat']['type'] ?? '') === 'channel') {
            return true;
        }

        return (string) ($message['sender_chat']['id'] ?? '') === (string) $this->config['channel_id']
            || (string) ($message['forward_from_chat']['id'] ?? '') === (string) $this->config['channel_id'];
    }

    private function privateChannelReplyTargetId(array $message): int
    {
        $directExternalReply = $this->channelOriginMessageId($message['external_reply'] ?? null);
        if ($directExternalReply > 0) {
            return $directExternalReply;
        }

        $reply = $message['reply_to_message'] ?? null;
        if (!is_array($reply)) {
            return 0;
        }

        $nestedExternalReply = $this->channelOriginMessageId($reply['external_reply'] ?? null);
        if ($nestedExternalReply > 0) {
            return $nestedExternalReply;
        }

        if ($this->isConfiguredChannel($reply['forward_from_chat'] ?? null)) {
            return (int) ($reply['forward_from_message_id'] ?? 0);
        }

        return $this->channelOriginMessageId($reply['forward_origin'] ?? null);
    }

    private function channelOriginMessageId(mixed $originContainer): int
    {
        if (!is_array($originContainer)) {
            return 0;
        }

        $origin = $originContainer['origin'] ?? $originContainer;
        if (!is_array($origin)
            || ($origin['type'] ?? '') !== 'channel'
            || !$this->isConfiguredChannel($origin['chat'] ?? null)
        ) {
            return 0;
        }

        return (int) ($origin['message_id'] ?? 0);
    }

    private function isConfiguredChannel(mixed $chat): bool
    {
        return is_array($chat)
            && (string) ($chat['id'] ?? '') === (string) ($this->config['channel_id'] ?? '');
    }

    private function resolveDiscussionReplyTargetId(array $reply): int
    {
        if ($this->isChannelDiscussionPost($reply)) {
            return (int) ($reply['message_id'] ?? 0);
        }

        $parent = $reply['reply_to_message'] ?? null;
        if (is_array($parent) && $this->isChannelDiscussionPost($parent)) {
            return (int) ($parent['message_id'] ?? 0);
        }

        return (int) ($reply['message_id'] ?? 0);
    }

    private function publish(array $message, array $meta): array
    {
        $content = trim((string) ($meta['content'] ?? ''));
        $target = (string) ($meta['target'] ?? 'post');
        $threadId = (int) ($meta['thread'] ?? 0);
        $caption = $this->publicText($content);
        $extra = [];

        if ($target === 'comment' && $threadId > 0) {
            $extra['reply_to_message_id'] = $threadId;
            $extra['message_thread_id'] = $threadId;
        } elseif ($target === 'post' && $threadId > 0) {
            $extra['reply_to_message_id'] = $threadId;
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

    private function publicText(string $content): string
    {
        $content = trim($content);
        if ($content === '') {
            return '';
        }

        return htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
            : (!empty($meta['local_wordlist'])
                ? '🚫 Wordlist: MATCH'
            : match ($meta['ai'] ?? 'REVIEW') {
                'ALLOW' => '🤖 AI: SAFE',
                'REJECT' => '🔴 AI suggested rejection',
                default => '⚠️ AI: REVIEW',
            });
        $content = trim((string) ($meta['content'] ?? ''));
        $matchedWords = implode(', ', array_map(
            static fn (string $word): string => htmlspecialchars($word, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            (array) ($meta['matched_words'] ?? [])
        ));

        return "🆕 Anonymous Submission\n\n"
            . ($content === '' ? '[media only]' : htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))
            . "\n\n{$aiLine}\nCategory: " . htmlspecialchars((string) ($meta['category'] ?? 'OTHER'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . "\nStatus: {$statusIcon}"
            . "\n\nRef: " . htmlspecialchars((string) ($meta['owner'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . "\nType: " . htmlspecialchars((string) ($meta['type'] ?? 'text'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . "\nTarget: " . htmlspecialchars((string) ($meta['target'] ?? 'post'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . "\nThread: " . (int) ($meta['thread'] ?? 0)
            . "\nMedia: " . (int) ($meta['media_message'] ?? 0)
            . "\nMatches: " . $matchedWords;
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
        preg_match('~Matches:\s*(.*)~u', $text, $matches);

        $contentBlock = trim((string) preg_replace('~^🆕 Anonymous Submission\s*|\n\n(?:🤖|⚠️|🔴).*~us', '', $text));
        $contentBlock = $contentBlock === '[media only]' ? '' : html_entity_decode($contentBlock, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $matchedWords = array_values(array_filter(array_map(
            static fn (string $word): string => trim(html_entity_decode($word, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')),
            explode(',', (string) ($matches[1] ?? ''))
        )));

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
            'local_wordlist' => str_contains($text, 'Wordlist: MATCH'),
            'matched_words' => $matchedWords,
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

        $replyMsgId = (int) ($owner['msg'] ?? 0);
        $extra = [
            'reply_markup' => [
                'inline_keyboard' => [[
                    ['text' => '🗑 Postni o\'chirish', 'callback_data' => $this->deleteCallback(
                        'delp',
                        (string) ($meta['target'] ?? 'post'),
                        $publishedMessageId,
                        (string) $owner['u']
                    )]
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

        $parsed = $this->parseDeleteCallback($data, $fromId);
        if ($parsed === null) {
            $this->telegram->answerCallback($id, '⛔ Ushbu tugma sizga tegishli emas.', true);
            return;
        }

        [$action, $target, $messageId] = $parsed;
        $chatId = (string) ($callback['message']['chat']['id'] ?? $fromId);
        $isPrivateCallback = (($callback['message']['chat']['type'] ?? '') === 'private');
        $buttonMessageId = (int) ($callback['message']['message_id'] ?? 0);

        if ($action === 'delw') {
            $delete = $this->telegram->deleteMessage($this->config['moderation_group_id'], $messageId);
            if (!($delete['ok'] ?? false)) {
                $this->telegram->answerCallback($id, 'O\'chirishda xatolik: ' . $this->shortError($delete), true);
                return;
            }
            $this->telegram->answerCallback($id, 'Xabar bekor qilindi.');
            $this->editOrSendPrivateStatus($chatId, $buttonMessageId, '🗑 Xabaringiz moderatsiyadan olindi va o\'chirildi.');
            return;
        }

        $targetChat = $target === 'comment' ? $this->config['discussion_group_id'] : $this->config['channel_id'];
        $delete = $this->telegram->deleteMessage($targetChat, $messageId);
        if (!($delete['ok'] ?? false)) {
            $this->telegram->answerCallback($id, 'O\'chirishda xatolik: ' . $this->shortError($delete), true);
            return;
        }
        $this->telegram->answerCallback($id, 'Post o\'chirildi.');
        if ($isPrivateCallback) {
            $this->editOrSendPrivateStatus($chatId, $buttonMessageId, '🗑 Nashr qilingan xabaringiz o\'chirildi.');
        }
    }

    private function deleteCallback(string $action, string $target, int $messageId, string $ownerId): string
    {
        $targetCode = $target === 'comment' ? 'c' : 'p';
        $sig = substr(Helpers::b64(hash_hmac(
            'sha256',
            $action . '|' . $targetCode . '|' . $messageId . '|' . $ownerId,
            $this->config['app_secret'],
            true
        )), 0, 16);

        return $action . ':' . $targetCode . ':' . $messageId . ':' . $sig;
    }

    private function parseDeleteCallback(string $data, string $fromId): ?array
    {
        [$action, $targetCode, $messageId, $sig] = array_pad(explode(':', $data, 4), 4, '');
        if (!in_array($action, ['delw', 'delp'], true) || !in_array($targetCode, ['p', 'c'], true) || !ctype_digit($messageId)) {
            return null;
        }

        $expected = substr(Helpers::b64(hash_hmac(
            'sha256',
            $action . '|' . $targetCode . '|' . $messageId . '|' . $fromId,
            $this->config['app_secret'],
            true
        )), 0, 16);

        if (!hash_equals($expected, $sig)) {
            return null;
        }

        return [$action, $targetCode === 'c' ? 'comment' : 'post', (int) $messageId];
    }

    private function editOrSendPrivateStatus(string $chatId, int $messageId, string $text): void
    {
        if ($messageId > 0) {
            $this->telegram->editMessageText($chatId, $messageId, $text);
            return;
        }

        $this->telegram->sendMessage($chatId, $text);
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
            "✍️ <b>Ushbu postga anonim izoh qoldiring.</b>\n\nIzohingizni shu xabarga reply qilib yuboring. Shaxsingiz ko'rsatilmaydi.\n\nCOMMENT_REF:" . htmlspecialchars($token, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
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

    private function handleAdminAiCallback(array $callback): void
    {
        $id = (string) ($callback['id'] ?? '');
        $fromId = (string) ($callback['from']['id'] ?? '');
        if (!$this->isAdmin($fromId)) {
            $this->telegram->answerCallback($id, '⛔ Siz admin emassiz.', true);
            return;
        }

        $action = substr((string) ($callback['data'] ?? ''), 8);
        if ($action === 'alloff') {
            $this->updateEnvValues(['GEMINI_ENABLED' => 'false', 'GROQ_ENABLED' => 'false']);
            $this->telegram->answerCallback($id, 'Ikkala AI ham o\'chirildi.');
        } elseif ($action === 'gemini_on') {
            $this->updateEnvValues(['GEMINI_ENABLED' => 'true', 'GROQ_ENABLED' => 'false']);
            $this->telegram->answerCallback($id, 'Gemini yoqildi, Groq o\'chirildi.');
        } elseif ($action === 'gemini_off') {
            $this->updateEnvValues(['GEMINI_ENABLED' => 'false']);
            $this->telegram->answerCallback($id, 'Gemini o\'chirildi.');
        } elseif ($action === 'groq_on') {
            $this->updateEnvValues(['GEMINI_ENABLED' => 'false', 'GROQ_ENABLED' => 'true']);
            $this->telegram->answerCallback($id, 'Groq yoqildi, Gemini o\'chirildi.');
        } elseif ($action === 'groq_off') {
            $this->updateEnvValues(['GROQ_ENABLED' => 'false']);
            $this->telegram->answerCallback($id, 'Groq o\'chirildi.');
        } else {
            $this->telegram->answerCallback($id);
        }

        $message = $callback['message'] ?? [];
        if (is_array($message) && isset($message['chat']['id'], $message['message_id'])) {
            $this->telegram->editMessageText(
                $message['chat']['id'],
                (int) $message['message_id'],
                $this->adminAiText(),
                ['reply_markup' => $this->adminAiKeyboard()]
            );
        }
    }

    private function adminAiText(): string
    {
        $geminiEnabled = $this->envBool('GEMINI_ENABLED', (bool) $this->config['gemini_enabled']);
        $groqEnabled = $this->envBool('GROQ_ENABLED', (bool) $this->config['groq_enabled']);
        $geminiKey = $this->envValue('GEMINI_API_KEY', (string) $this->config['gemini_api_key']);
        $groqKey = $this->envValue('GROQ_API_KEY', (string) $this->config['groq_api_key']);
        $active = $geminiEnabled ? 'Gemini' : ($groqEnabled ? 'Groq' : 'AI ishlamayapti');

        return "⚙️ <b>Admin AI sozlamalari</b>\n\n"
            . "Faol holat: <b>{$active}</b>\n\n"
            . "Gemini: " . ($geminiEnabled ? '✅ yoqilgan' : '❌ o\'chiq')
            . " | key: " . ($geminiKey === '' || str_contains($geminiKey, 'YOUR_') ? '❌ yo\'q' : '✅ bor') . "\n"
            . "Groq: " . ($groqEnabled ? '✅ yoqilgan' : '❌ o\'chiq')
            . " | key: " . ($groqKey === '' ? '❌ yo\'q' : '✅ bor') . "\n\n"
            . "Ikkalasi ham o\'chiq bo\'lsa AI umuman ishlamaydi. Bitta AI yoqilganda ikkinchisi avtomatik o\'chadi.";
    }

    private function adminAiKeyboard(): array
    {
        $geminiEnabled = $this->envBool('GEMINI_ENABLED', (bool) $this->config['gemini_enabled']);
        $groqEnabled = $this->envBool('GROQ_ENABLED', (bool) $this->config['groq_enabled']);

        return ['inline_keyboard' => [
            [
                ['text' => $geminiEnabled ? '❌ Gemini off' : '✅ Gemini on', 'callback_data' => $geminiEnabled ? 'adminai:gemini_off' : 'adminai:gemini_on'],
            ],
            [
                ['text' => $groqEnabled ? '❌ Groq off' : '✅ Groq on', 'callback_data' => $groqEnabled ? 'adminai:groq_off' : 'adminai:groq_on'],
            ],
            [
                ['text' => '⛔ Hammasini o\'chirish', 'callback_data' => 'adminai:alloff'],
                ['text' => '🔄 Refresh', 'callback_data' => 'adminai:refresh'],
            ],
        ]];
    }

    private function envValue(string $key, string $default = ''): string
    {
        $value = $_ENV[$key] ?? getenv($key);
        return $value === false || $value === null ? $default : (string) $value;
    }

    private function envBool(string $key, bool $default): bool
    {
        return filter_var($this->envValue($key, $default ? 'true' : 'false'), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Admin toggles are stored in .env; this is lightweight config, not user/message storage.
     *
     * @param array<string, string> $values
     */
    private function updateEnvValues(array $values): void
    {
        $path = dirname(__DIR__) . '/.env';
        $lines = is_file($path) ? file($path, FILE_IGNORE_NEW_LINES) : [];
        if ($lines === false) {
            $lines = [];
        }

        $seen = [];
        foreach ($lines as $index => $line) {
            if (!str_contains((string) $line, '=')) {
                continue;
            }
            [$key] = explode('=', (string) $line, 2);
            $key = trim($key);
            if (array_key_exists($key, $values)) {
                $lines[$index] = $key . '=' . $values[$key];
                $seen[$key] = true;
            }
        }

        foreach ($values as $key => $value) {
            if (!isset($seen[$key])) {
                $lines[] = $key . '=' . $value;
            }
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        file_put_contents($path, implode("\n", $lines) . "\n", LOCK_EX);
    }

    private function shortError(array $result): string
    {
        $description = (string) ($result['description'] ?? 'noma\'lum xato');
        return mb_substr($description, 0, 160);
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
