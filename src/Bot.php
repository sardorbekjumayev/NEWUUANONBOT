<?php

declare(strict_types=1);

namespace PUAnonymous;

final class Bot
{
    /** @var array<string, array{window:int,count:int}> */
    private static array $rate = [];
    /** @var array<int, int> */
    private static array $seenUpdates = [];

    private readonly AdminManager $adminManager;
    private readonly Wordlist $wordlist;

    public function __construct(
        private readonly array $config,
        private readonly Telegram $telegram,
        private readonly AI $ai,
        ?AdminManager $adminManager = null,
        ?Wordlist $wordlist = null,
    ) {
        $this->adminManager = $adminManager ?? new AdminManager();
        $this->wordlist = $wordlist ?? new Wordlist();
    }

    public function handle(array $update): void
    {
        $updateId = (int) ($update['update_id'] ?? 0);
        if ($this->isDuplicateUpdate($updateId)) {
            return;
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

        $firstWord = strtolower(explode(' ', $text)[0] ?? '');
        $cmd = explode('@', $firstWord)[0];

        if ($cmd === '/start') {
            $arg = trim(substr($text, strlen($firstWord)));
            if (str_starts_with($arg, 'comment_')) {
                $this->startComment($chatId, substr($arg, 8));
                return;
            }

            if (str_starts_with($arg, 'mod_')) {
                $this->startAdminChannelModeration($chatId, (string) ($message['from']['id'] ?? ''), substr($arg, 4));
                return;
            }

            $this->telegram->sendMessage(
                $chatId,
                "👋 <b>PU Anonymous botiga xush kelibsiz!</b>\n\nXabaringizni yuboring. Matnli xabarlar avtomatik kanalga joylanadi. Media va havolali xabarlar esa admin moderatsiyasidan o'tadi.\n\n🔒 Shaxsingiz mutlaqo anonim saqlanadi.",
                [
                    'reply_markup' => [
                        'inline_keyboard' => [
                            [
                                ['text' => '📜 Qoidalar', 'callback_data' => 'rules'],
                                ['text' => '❓ Yordam', 'callback_data' => 'help'],
                            ],
                            [
                                ['text' => '🛑 To\'xtatish / Reset', 'callback_data' => 'stop'],
                            ]
                        ]
                    ],
                ]
            );
            return;
        }

        if ($cmd === '/admin') {
            if (!$this->isAdmin((string) ($message['from']['id'] ?? ''))) {
                $this->telegram->sendMessage($chatId, '⛔ Bu bo\'lim faqat adminlar uchun.');
                return;
            }

            $this->telegram->sendMessage($chatId, $this->adminMainText(), [
                'reply_markup' => $this->adminMainKeyboard(),
            ]);
            return;
        }

        if (str_starts_with($text, '/addadmin ')) {
            if (!$this->isAdmin((string) ($message['from']['id'] ?? ''))) {
                return;
            }
            $newId = trim(substr($text, 10));
            $safeId = htmlspecialchars($newId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            if ($this->adminManager->addAdmin($newId, $this->config['admin_ids'] ?? [])) {
                $this->telegram->sendMessage($chatId, "✅ Yangi admin qo'shildi: <code>{$safeId}</code>");
            } else {
                $this->telegram->sendMessage($chatId, "❌ Noto'g'ri Telegram ID.");
            }
            return;
        }

        if (str_starts_with($text, '/deladmin ')) {
            if (!$this->isAdmin((string) ($message['from']['id'] ?? ''))) {
                return;
            }
            $targetId = trim(substr($text, 10));
            $safeId = htmlspecialchars($targetId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $this->adminManager->removeAdmin($targetId, $this->config['admin_ids'] ?? []);
            $this->telegram->sendMessage($chatId, "🗑 Admin olib tashlandi: <code>{$safeId}</code>");
            return;
        }

        if (str_starts_with($text, '/addword ')) {
            if (!$this->isAdmin((string) ($message['from']['id'] ?? ''))) {
                return;
            }
            $word = trim(substr($text, 9));
            $safeWord = htmlspecialchars($word, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            if ($this->wordlist->add($word)) {
                $this->telegram->sendMessage($chatId, "✅ Wordlistga qo'shildi: <b>{$safeWord}</b>");
            } else {
                $this->telegram->sendMessage($chatId, "❌ So'z kiritilmadi.");
            }
            return;
        }

        if (str_starts_with($text, '/delword ')) {
            if (!$this->isAdmin((string) ($message['from']['id'] ?? ''))) {
                return;
            }
            $word = trim(substr($text, 9));
            $safeWord = htmlspecialchars($word, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            if ($this->wordlist->delete($word)) {
                $this->telegram->sendMessage($chatId, "🗑 Wordlistdan o'chirildi: <b>{$safeWord}</b>");
            } else {
                $this->telegram->sendMessage($chatId, "❌ So'z topilmadi.");
            }
            return;
        }

        if ($cmd === '/rules' || $text === '📜 Qoidalar') {
            $this->sendRules($chatId);
            return;
        }

        if ($cmd === '/stop' || $cmd === '/cancel' || $text === '🛑 To\'xtatish') {
            $this->stopAndReset($chatId, (string) ($message['from']['id'] ?? ''));
            return;
        }

        if ($cmd === '/help' || $text === '❓ Yordam') {
            $this->sendHelp($chatId);
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
        $publish = $this->publishDiscussionAnonymous($content, $replyToId, $threadId);

        if (!($publish['ok'] ?? false)) {
            Helpers::log('ERROR', 'anonymous discussion publish failed', ['description' => $publish['description'] ?? 'unknown']);
            return;
        }

        // Delete user's trigger message only after successful publication
        $this->telegram->deleteMessage($this->config['discussion_group_id'], (int) ($message['message_id'] ?? 0));

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

        $isMedia = $this->isMedia($content, $message);
        $hasLinks = $this->hasLinks($message, $textForAI);
        $matchedWords = $this->findBadWords($textForAI);
        $hasBadWords = !empty($matchedWords);

        // Pure text without media, without links, without banned words -> Auto-publish directly to Channel!
        if (!$isMedia && !$hasLinks && !$hasBadWords) {
            $publish = $this->publishTextDirect($textForAI, $target, $threadId ?? 0);
            if ($publish['ok']) {
                $publishedId = (int) ($publish['result']['message_id'] ?? 0);
                $this->savePublishedPost($publishedId, $textForAI);

                // Add delete button to channel post
                if ($publishedId > 0 && ($this->config['bot_username'] ?? '') !== '') {
                    $botUser = $this->config['bot_username'];
                    $this->telegram->editReplyMarkup(
                        $this->config['channel_id'],
                        $publishedId,
                        [
                            'inline_keyboard' => [[
                                ['text' => '🗑 O\'chirish / Taqiq', 'url' => "https://t.me/{$botUser}?start=mod_{$publishedId}"]
                            ]]
                        ]
                    );
                }

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
                Helpers::log('INFO', 'auto-published text submission');
                return;
            }

            // Direct channel publish failed
            $errDesc = (string) ($publish['description'] ?? 'Noma\'lum xatolik');
            Helpers::log('ERROR', 'Direct channel publish failed', ['description' => $errDesc, 'channel_id' => $this->config['channel_id'] ?? '']);

            $safeErr = htmlspecialchars($errDesc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $failText = "⚠️ Kanalga nashr etishda xatolik bo'ldi: <b>{$safeErr}</b>\n\nIltimos, bot kanalda admin ekanini va CHANNEL_ID to'g'ri ko'rsatilganini tekshiring.";
            if ($checkingId > 0) {
                $this->telegram->editMessageText($chatId, $checkingId, $failText);
            } else {
                $this->telegram->sendMessage($chatId, $failText, ['reply_to_message_id' => $userMsgId]);
            }
            return;
        }

        // Needs Admin Moderation Queue
        $category = $isMedia ? 'MEDIA' : ($hasLinks ? 'LINKS' : 'WORDLIST');

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
            'ai' => 'REVIEW',
            'category' => $category,
            'has_media' => $isMedia,
            'has_links' => $hasLinks,
            'local_wordlist' => $hasBadWords,
            'matched_words' => $matchedWords,
        ];

        // Send to Moderation Group for review
        $sent = $this->sendToModeration($content, $meta);
        $moderationMessageId = (int) ($sent['result']['message_id'] ?? 0);
        if ($moderationMessageId <= 0) {
            $errDesc = (string) ($sent['description'] ?? 'Noma\'lum xatolik');
            Helpers::log('ERROR', 'Moderation send failed', ['description' => $errDesc, 'moderation_group_id' => $this->config['moderation_group_id'] ?? '']);

            $safeErr = htmlspecialchars($errDesc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $failText = "⚠️ Moderatsiya guruhiga yuborishda xatolik bo'ldi: <b>{$safeErr}</b>\n\nIltimos, bot guruhda borligini va MODERATION_GROUP_ID to'g'ri o'rnatilganini tekshiring.";
            if ($checkingId > 0) {
                $this->telegram->editMessageText($chatId, $checkingId, $failText);
            } else {
                $this->telegram->sendMessage($chatId, $failText, ['reply_to_message_id' => $userMsgId]);
            }
            return;
        }

        $this->telegram->editReplyMarkup(
            $this->config['moderation_group_id'],
            $moderationMessageId,
            $this->adminKeyboard($moderationMessageId)
        );

        $queuedText = match ($category) {
            'MEDIA' => '⚠️ Media xabaringiz admin tekshiruviga yuborildi.',
            'LINKS' => '⚠️ Xabarda havola borligi sababli admin tekshiruviga yuborildi.',
            'WORDLIST' => '⚠️ Xabarda taqiqlangan so\'zlar borligi sababli admin tekshiruviga yuborildi.',
            default => '⚠️ Xabaringiz admin tekshiruviga yuborildi.',
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

        Helpers::log('INFO', 'submission queued for moderation', ['type' => $content['type'], 'category' => $category]);
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

        if ($data === 'rules') {
            $this->telegram->answerCallback($id);
            $this->sendRules((string) ($callback['message']['chat']['id'] ?? $fromId));
            return;
        }

        if ($data === 'stop') {
            $this->telegram->answerCallback($id, 'To\'xtatildi');
            $this->stopAndReset((string) ($callback['message']['chat']['id'] ?? $fromId), $fromId);
            return;
        }

        if (str_starts_with($data, 'delw:') || str_starts_with($data, 'delp:') || str_starts_with($data, 'del:')) {
            $this->deleteOwnPost($callback);
            return;
        }

        if (str_starts_with($data, 'modword:')) {
            $this->handleAdminWordToggleCallback($callback);
            return;
        }

        if (str_starts_with($data, 'modsave:')) {
            $this->handleAdminWordSaveCallback($callback);
            return;
        }

        if (str_starts_with($data, 'moddel:')) {
            $this->handleAdminWordDeleteOnlyCallback($callback);
            return;
        }

        if (str_starts_with($data, 'modcancel:')) {
            $this->handleAdminWordCancelCallback($callback);
            return;
        }

        if (str_starts_with($data, 'adminmenu:')) {
            $this->handleAdminMenuCallback($callback);
            return;
        }

        if (str_starts_with($data, 'adminai:')) {
            $this->handleAdminAiCallback($callback);
            return;
        }

        if (str_starts_with($data, 'adminadm:')) {
            $this->handleAdminAdminsCallback($callback);
            return;
        }

        if (str_starts_with($data, 'adminwl:')) {
            $this->handleAdminWordlistCallback($callback);
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
        if ($channelMessageId > 0 && ($meta['target'] ?? 'post') === 'post') {
            $postContent = (string) ($meta['content'] ?? '');
            $this->savePublishedPost($channelMessageId, $postContent);
            if (($this->config['bot_username'] ?? '') !== '') {
                $botUser = $this->config['bot_username'];
                $this->telegram->editReplyMarkup(
                    $this->config['channel_id'],
                    $channelMessageId,
                    [
                        'inline_keyboard' => [[
                            ['text' => '🗑 O\'chirish / Taqiq', 'url' => "https://t.me/{$botUser}?start=mod_{$channelMessageId}"]
                        ]]
                    ]
                );
            }
        }
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
        $this->telegram->sendMessage($chatId, "❓ <b>Qanday ishlaydi</b>\n\n1. Xabaringizni yuboring.\n2. Matnlar AI orqali avtomatik joylanadi.\n3. Medialar adminlar tomonidan tekshiriladi.\n4. Qabul qilinsa anonim nashr etiladi.\n\nSiz kanal postlariga ham anonim izoh qoldirishingiz mumkin.\n\n🔒 Shaxsingiz mutlaqo oshkor etilmaydi.", [
            'reply_markup' => [
                'inline_keyboard' => [
                    [
                        ['text' => '📜 Qoidalar', 'callback_data' => 'rules'],
                        ['text' => '🛑 To\'xtatish', 'callback_data' => 'stop'],
                    ]
                ]
            ]
        ]);
    }

    private function sendRules(string $chatId): void
    {
        $text = "📜 <b>PU Anonymous Bot Qoidalari</b>\n\n"
            . "✅ <b>Mumkin bo'lgan amallar:</b>\n"
            . "• Universitet va talabalar hayotiga oid savollar va muhokamalar\n"
            . "• Fikr-mulohazalar, takliflar va erkin fikr bildirish\n"
            . "• Kanal postlariga anonim izoh qoldirish\n"
            . "• Odob doirasidagi hazillar va memlar\n\n"
            . "🚫 <b>Taqiqlangan amallar:</b>\n"
            . "• Reklama, tijorat, promo-kod va sotuv e'lonlari\n"
            . "• Behabar/harom/so'kinish so'zlari, behayo kontent\n"
            . "• Shaxsiy ma'lumotlarni tarqatish (doxxing, telefon, pasport)\n"
            . "• Kimgadir nisbatan shaxsiy adovat, tuhmat va haqorat\n"
            . "• Spam, firibgarlik va shubhali havolalar\n\n"
            . "🔒 <i>Shaxsingiz mutlaqo anonim saqlanadi. Qoidalarga amal qilishingizni so'raymiz!</i>";

        $this->telegram->sendMessage($chatId, $text, [
            'reply_markup' => [
                'inline_keyboard' => [
                    [
                        ['text' => '❓ Yordam', 'callback_data' => 'help'],
                        ['text' => '🛑 To\'xtatish', 'callback_data' => 'stop'],
                    ]
                ]
            ]
        ]);
    }

    private function stopAndReset(string $chatId, string $fromId): void
    {
        if ($fromId !== '') {
            $key = hash_hmac('sha256', $fromId, $this->config['app_secret']);
            unset(self::$rate[$key]);
        }

        $text = "🛑 <b>Barcha amallar to'xtatildi.</b>\n\nBot holati nolga qaytarildi. Agar bot loopga tushib qolgan bo'lsa yoki yangidan boshlamoqchi bo'lsangiz, endi bemalol yangi xabar yuborishingiz mumkin.";

        $this->telegram->sendMessage($chatId, $text, [
            'reply_markup' => [
                'remove_keyboard' => true,
            ]
        ]);
    }

    private function findBadWords(string $text): array
    {
        return $this->wordlist->findMatches($text);
    }

    private function badWordCandidates(string $text): array
    {
        return $this->wordlist->candidates($text);
    }

    private function appendBadWord(string $word): void
    {
        $this->wordlist->add($word);
    }

    private function handleAdminMenuCallback(array $callback): void
    {
        $id = (string) ($callback['id'] ?? '');
        $fromId = (string) ($callback['from']['id'] ?? '');
        if (!$this->isAdmin($fromId)) {
            $this->telegram->answerCallback($id, '⛔ Siz admin emassiz.', true);
            return;
        }

        $this->telegram->answerCallback($id);
        $message = $callback['message'] ?? [];
        if (is_array($message) && isset($message['chat']['id'], $message['message_id'])) {
            $this->telegram->editMessageText(
                $message['chat']['id'],
                (int) $message['message_id'],
                $this->adminMainText(),
                ['reply_markup' => $this->adminMainKeyboard()]
            );
        }
    }

    private function handleAdminAdminsCallback(array $callback): void
    {
        $id = (string) ($callback['id'] ?? '');
        $fromId = (string) ($callback['from']['id'] ?? '');
        if (!$this->isAdmin($fromId)) {
            $this->telegram->answerCallback($id, '⛔ Siz admin emassiz.', true);
            return;
        }

        $this->telegram->answerCallback($id);
        $message = $callback['message'] ?? [];
        if (is_array($message) && isset($message['chat']['id'], $message['message_id'])) {
            $this->telegram->editMessageText(
                $message['chat']['id'],
                (int) $message['message_id'],
                $this->adminAdminsText(),
                ['reply_markup' => $this->adminAdminsKeyboard()]
            );
        }
    }

    private function handleAdminWordlistCallback(array $callback): void
    {
        $id = (string) ($callback['id'] ?? '');
        $fromId = (string) ($callback['from']['id'] ?? '');
        if (!$this->isAdmin($fromId)) {
            $this->telegram->answerCallback($id, '⛔ Siz admin emassiz.', true);
            return;
        }

        $this->telegram->answerCallback($id);
        $message = $callback['message'] ?? [];
        if (is_array($message) && isset($message['chat']['id'], $message['message_id'])) {
            $this->telegram->editMessageText(
                $message['chat']['id'],
                (int) $message['message_id'],
                $this->adminWordlistText(),
                ['reply_markup' => $this->adminWordlistKeyboard()]
            );
        }
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
            $this->adminManager->updateAiSettings(['gemini_enabled' => false, 'groq_enabled' => false]);
            $this->telegram->answerCallback($id, 'Ikkala AI ham o\'chirildi.');
        } elseif ($action === 'gemini_on') {
            $this->adminManager->updateAiSettings(['gemini_enabled' => true, 'groq_enabled' => false]);
            $this->telegram->answerCallback($id, 'Gemini yoqildi, Groq o\'chirildi.');
        } elseif ($action === 'gemini_off') {
            $this->adminManager->updateAiSettings(['gemini_enabled' => false]);
            $this->telegram->answerCallback($id, 'Gemini o\'chirildi.');
        } elseif ($action === 'groq_on') {
            $this->adminManager->updateAiSettings(['gemini_enabled' => false, 'groq_enabled' => true]);
            $this->telegram->answerCallback($id, 'Groq yoqildi, Gemini o\'chirildi.');
        } elseif ($action === 'groq_off') {
            $this->adminManager->updateAiSettings(['groq_enabled' => false]);
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

    private function adminMainText(): string
    {
        $adminsCount = count($this->adminManager->getAdmins($this->config['admin_ids'] ?? []));
        $wordsCount = count($this->wordlist->getAll());
        $geminiEnabled = (bool) ($this->config['gemini_enabled'] ?? false);
        $groqEnabled = (bool) ($this->config['groq_enabled'] ?? false);
        $activeAi = $geminiEnabled ? 'Gemini' : ($groqEnabled ? 'Groq' : 'O\'chirilgan');

        return "⚙️ <b>Admin Boshqaruvi Paneli</b>\n\n"
            . "🤖 Active AI: <b>{$activeAi}</b>\n"
            . "👥 Adminlar soni: <b>{$adminsCount}</b>\n"
            . "📝 Wordlist so'zlar soni: <b>{$wordsCount}</b>\n\n"
            . "Quyidagi tugmalar orqali sozlamalarni boshqaring yoki <b>Admin Web App</b>'ni oching:";
    }

    private function adminMainKeyboard(): array
    {
        $webhookUrl = (string) ($this->config['webhook_url'] ?? '');
        if (str_starts_with($webhookUrl, 'http://')) {
            $webhookUrl = 'https://' . substr($webhookUrl, 7);
        }
        $webAppUrl = str_contains($webhookUrl, 'index.php') 
            ? rtrim($webhookUrl, '/') . '?app=admin'
            : rtrim($webhookUrl, '/') . '/admin';

        return ['inline_keyboard' => [
            [
                ['text' => '🌐 Admin Web App\'ni ochish', 'web_app' => ['url' => $webAppUrl]],
            ],
            [
                ['text' => '🤖 AI Sozlamalari', 'callback_data' => 'adminai:menu'],
                ['text' => '👥 Adminlar', 'callback_data' => 'adminadm:menu'],
            ],
            [
                ['text' => '📝 Wordlist (Taqiq so\'zlar)', 'callback_data' => 'adminwl:menu'],
            ],
        ]];
    }

    private function adminAiText(): string
    {
        $geminiEnabled = (bool) ($this->config['gemini_enabled'] ?? false);
        $groqEnabled = (bool) ($this->config['groq_enabled'] ?? false);
        $geminiKey = (string) ($this->config['gemini_api_key'] ?? '');
        $groqKey = (string) ($this->config['groq_api_key'] ?? '');
        $active = $geminiEnabled ? 'Gemini' : ($groqEnabled ? 'Groq' : 'AI ishlamayapti');

        return "⚙️ <b>Admin AI sozlamalari</b>\n\n"
            . "Faol holat: <b>{$active}</b>\n\n"
            . "Gemini: " . ($geminiEnabled ? '✅ yoqilgan' : '❌ o\'chiq')
            . " | key: " . ($geminiKey === '' || str_contains($geminiKey, 'YOUR_') ? '❌ yo\'q' : '✅ bor') . "\n"
            . "Groq: " . ($groqEnabled ? '✅ yoqilgan' : '❌ o\'chiq')
            . " | key: " . ($groqKey === '' ? '❌ yo\'q' : '✅ bor') . "\n\n"
            . "API kalitlarni yangilash uchun Web App'dan foydalaning.";
    }

    private function adminAiKeyboard(): array
    {
        $geminiEnabled = (bool) ($this->config['gemini_enabled'] ?? false);
        $groqEnabled = (bool) ($this->config['groq_enabled'] ?? false);

        return ['inline_keyboard' => [
            [
                ['text' => $geminiEnabled ? '❌ Gemini off' : '✅ Gemini on', 'callback_data' => $geminiEnabled ? 'adminai:gemini_off' : 'adminai:gemini_on'],
            ],
            [
                ['text' => $groqEnabled ? '❌ Groq off' : '✅ Groq on', 'callback_data' => $groqEnabled ? 'adminai:groq_off' : 'adminai:groq_on'],
            ],
            [
                ['text' => '⛔ Hammasini o\'chirish', 'callback_data' => 'adminai:alloff'],
                ['text' => '🔙 Orqaga', 'callback_data' => 'adminmenu:main'],
            ],
        ]];
    }

    private function adminAdminsText(): string
    {
        $admins = $this->adminManager->getAdmins($this->config['admin_ids'] ?? []);
        $list = implode("\n", array_map(static fn (string $id): string => '• <code>' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '</code>', $admins));

        return "👥 <b>Adminlar Ro'yxati</b>\n\n"
            . ($list === '' ? 'Adminlar yo\'q' : $list) . "\n\n"
            . "➕ Admin qo'shish: <code>/addadmin TelegramID</code>\n"
            . "➖ Admin o'chirish: <code>/deladmin TelegramID</code>";
    }

    private function adminAdminsKeyboard(): array
    {
        return ['inline_keyboard' => [
            [
                ['text' => '🔙 Orqaga', 'callback_data' => 'adminmenu:main'],
            ],
        ]];
    }

    private function adminWordlistText(): string
    {
        $words = $this->wordlist->getAll();
        $sample = array_slice($words, 0, 20);
        $list = implode(", ", array_map(static fn (string $w): string => htmlspecialchars($w, ENT_QUOTES, 'UTF-8'), $sample));
        $count = count($words);

        return "📝 <b>Wordlist (Taqiqlangan so'zlar)</b>\n\n"
            . "Jami so'zlar soni: <b>{$count}</b>\n\n"
            . "Namuna: " . ($list === '' ? 'So\'zlar yo\'q' : $list) . ($count > 20 ? '...' : '') . "\n\n"
            . "➕ So'z qo'shish: <code>/addword soz</code>\n"
            . "➖ So'z o'chirish: <code>/delword soz</code>";
    }

    private function adminWordlistKeyboard(): array
    {
        return ['inline_keyboard' => [
            [
                ['text' => '🔙 Orqaga', 'callback_data' => 'adminmenu:main'],
            ],
        ]];
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
        return $this->adminManager->isAdmin($telegramId, $this->config['admin_ids'] ?? []);
    }

    private function isDuplicateUpdate(int $updateId): bool
    {
        if ($updateId <= 0) {
            return false;
        }

        $now = time();
        if (isset(self::$seenUpdates[$updateId])) {
            return true;
        }

        $file = dirname(__DIR__) . '/data/seen_updates.json';
        $seen = [];
        if (is_file($file)) {
            $raw = @file_get_contents($file);
            if ($raw !== false && $raw !== '') {
                $data = json_decode($raw, true);
                if (is_array($data)) {
                    $seen = $data;
                }
            }
        }

        if (isset($seen[(string) $updateId])) {
            self::$seenUpdates[$updateId] = $now;
            return true;
        }

        foreach ($seen as $id => $timestamp) {
            if (($now - (int) $timestamp) > 600) {
                unset($seen[$id]);
            }
        }

        $seen[(string) $updateId] = $now;
        self::$seenUpdates[$updateId] = $now;

        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $json = json_encode($seen);
        $tmpFile = $file . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmpFile, $json) !== false) {
            @rename($tmpFile, $file);
        }

        return false;
    }

    private function hasLinks(array $message, string $text): bool
    {
        $entities = array_merge($message['entities'] ?? [], $message['caption_entities'] ?? []);
        foreach ($entities as $entity) {
            $type = (string) ($entity['type'] ?? '');
            if (in_array($type, ['url', 'text_link', 'mention', 'email', 'phone_number'], true)) {
                return true;
            }
        }

        if ($text !== '') {
            if (preg_match('~(https?://|t\.me/|telegram\.me/|www\.|@[\w_]+)~iu', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    private function isMedia(array $content, array $message): bool
    {
        if (($content['type'] ?? 'text') !== 'text') {
            return true;
        }

        foreach (['photo', 'video', 'animation', 'document', 'sticker', 'voice', 'audio', 'video_note', 'contact', 'location', 'venue'] as $key) {
            if (isset($message[$key])) {
                return true;
            }
        }

        return false;
    }

    private function startAdminChannelModeration(string $chatId, string $fromId, string $arg): void
    {
        if (!$this->isAdmin($fromId)) {
            $this->telegram->sendMessage($chatId, '⛔ Ushbu bo\'lim faqat adminlar uchun.');
            return;
        }

        $channelMsgId = (int) $arg;
        if ($channelMsgId <= 0) {
            $this->telegram->sendMessage($chatId, '❌ Noto\'g\'ri post ID.');
            return;
        }

        $postText = $this->getPublishedPost($channelMsgId);
        if ($postText === null || trim($postText) === '') {
            $this->telegram->sendMessage($chatId, "⚠️ <b>Post #{$channelMsgId} topilmadi</b> yoki allaqachon o'chirilgan.");
            return;
        }

        $words = $this->wordlist->candidates($postText, 30);
        if (empty($words)) {
            $this->telegram->sendMessage($chatId, "📌 <b>Post matni:</b>\n<i>\"" . htmlspecialchars($postText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\"</i>\n\n⚠️ Matnda ajratib bo'ladigan so'zlar topilmadi.");
            return;
        }

        $session = [
            'msg_id' => $channelMsgId,
            'post_text' => $postText,
            'words' => $words,
            'selected' => [],
        ];
        $this->saveAdminSession($fromId, $session);

        $safeText = htmlspecialchars($postText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $msg = "📌 <b>Kanaldagi post:</b>\n<i>\"{$safeText}\"</i>\n\nQaysi so'zlarni xavfli/taqiqlangan deb belgilamoqchisiz? Kerakli so'zlarni tanlab, <b>Saqlash</b> tugmasini bosing:";

        $this->telegram->sendMessage($chatId, $msg, [
            'reply_markup' => $this->renderWordSelectorKeyboard($channelMsgId, $words, [])
        ]);
    }

    private function renderWordSelectorKeyboard(int $channelMsgId, array $words, array $selected): array
    {
        $rows = [];
        $row = [];

        foreach ($words as $idx => $word) {
            $isSelected = in_array($word, $selected, true);
            $icon = $isSelected ? '✅ ' : '◻️ ';
            $row[] = [
                'text' => $icon . $word,
                'callback_data' => 'modword:' . $idx,
            ];
            if (count($row) === 2) {
                $rows[] = $row;
                $row = [];
            }
        }
        if ($row !== []) {
            $rows[] = $row;
        }

        $cnt = count($selected);
        $saveBtnText = $cnt > 0 ? "💾 Saqlash ({$cnt} so'z) & O'chirish" : "💾 Saqlash & O'chirish";

        $rows[] = [[
            'text' => $saveBtnText,
            'callback_data' => 'modsave:' . $channelMsgId,
        ]];
        $rows[] = [
            ['text' => '🗑 Faqat O\'chirish', 'callback_data' => 'moddel:' . $channelMsgId],
            ['text' => '❌ Bekor qilish', 'callback_data' => 'modcancel:' . $channelMsgId],
        ];

        return ['inline_keyboard' => $rows];
    }

    private function handleAdminWordToggleCallback(array $callback): void
    {
        $id = (string) ($callback['id'] ?? '');
        $fromId = (string) ($callback['from']['id'] ?? '');
        $data = (string) ($callback['data'] ?? '');
        $messageId = (int) ($callback['message']['message_id'] ?? 0);
        $chatId = (string) ($callback['message']['chat']['id'] ?? $fromId);

        if (!$this->isAdmin($fromId)) {
            $this->telegram->answerCallback($id, '⛔ Siz admin emassiz.', true);
            return;
        }

        $idx = (int) substr($data, 8);
        $session = $this->getAdminSession($fromId);
        if (!is_array($session) || !isset($session['words'][$idx])) {
            $this->telegram->answerCallback($id, 'Sessiya topilmadi yoki muddati o\'tgan.', true);
            return;
        }

        $word = $session['words'][$idx];
        $selected = $session['selected'] ?? [];
        $pos = array_search($word, $selected, true);
        if ($pos !== false) {
            unset($selected[$pos]);
            $selected = array_values($selected);
            $alertText = "O'chirildi: {$word}";
        } else {
            $selected[] = $word;
            $alertText = "Tanlandi: {$word}";
        }

        $session['selected'] = $selected;
        $this->saveAdminSession($fromId, $session);

        $this->telegram->editReplyMarkup(
            $chatId,
            $messageId,
            $this->renderWordSelectorKeyboard($session['msg_id'], $session['words'], $session['selected'])
        );
        $this->telegram->answerCallback($id, $alertText);
    }

    private function handleAdminWordSaveCallback(array $callback): void
    {
        $id = (string) ($callback['id'] ?? '');
        $fromId = (string) ($callback['from']['id'] ?? '');
        $data = (string) ($callback['data'] ?? '');
        $messageId = (int) ($callback['message']['message_id'] ?? 0);
        $chatId = (string) ($callback['message']['chat']['id'] ?? $fromId);

        if (!$this->isAdmin($fromId)) {
            $this->telegram->answerCallback($id, '⛔ Siz admin emassiz.', true);
            return;
        }

        $channelMsgId = (int) substr($data, 8);
        $session = $this->getAdminSession($fromId);
        $selected = is_array($session) ? ($session['selected'] ?? []) : [];

        foreach ($selected as $w) {
            $this->wordlist->add($w);
        }

        if ($channelMsgId > 0) {
            $this->telegram->deleteMessage($this->config['channel_id'], $channelMsgId);
            $this->deletePublishedPost($channelMsgId);
        }
        $this->deleteAdminSession($fromId);

        $this->telegram->answerCallback($id, 'Bajarildi!');

        if (!empty($selected)) {
            $safeList = implode(', ', array_map(
                static fn (string $w): string => '<b>' . htmlspecialchars($w, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</b>',
                $selected
            ));
            $text = "✅ Tanlangan so'zlar ({$safeList}) taqiqlangan so'zlar ro'yxatiga qo'shildi va post kanaldan o'chirildi!";
        } else {
            $text = "🗑 Post kanaldan o'chirildi (hech qanday so'z taqiqlanmadi).";
        }

        $this->telegram->editMessageText($chatId, $messageId, $text);
    }

    private function handleAdminWordDeleteOnlyCallback(array $callback): void
    {
        $id = (string) ($callback['id'] ?? '');
        $fromId = (string) ($callback['from']['id'] ?? '');
        $data = (string) ($callback['data'] ?? '');
        $messageId = (int) ($callback['message']['message_id'] ?? 0);
        $chatId = (string) ($callback['message']['chat']['id'] ?? $fromId);

        if (!$this->isAdmin($fromId)) {
            $this->telegram->answerCallback($id, '⛔ Siz admin emassiz.', true);
            return;
        }

        $channelMsgId = (int) substr($data, 7);
        if ($channelMsgId > 0) {
            $this->telegram->deleteMessage($this->config['channel_id'], $channelMsgId);
            $this->deletePublishedPost($channelMsgId);
        }
        $this->deleteAdminSession($fromId);

        $this->telegram->answerCallback($id, 'Post o\'chirildi');
        $this->telegram->editMessageText($chatId, $messageId, "🗑 Post kanaldan o'chirildi.");
    }

    private function handleAdminWordCancelCallback(array $callback): void
    {
        $id = (string) ($callback['id'] ?? '');
        $fromId = (string) ($callback['from']['id'] ?? '');
        $messageId = (int) ($callback['message']['message_id'] ?? 0);
        $chatId = (string) ($callback['message']['chat']['id'] ?? $fromId);

        if (!$this->isAdmin($fromId)) {
            $this->telegram->answerCallback($id, '⛔ Siz admin emassiz.', true);
            return;
        }

        $this->deleteAdminSession($fromId);
        $this->telegram->answerCallback($id, 'Bekor qilindi');
        $this->telegram->editMessageText($chatId, $messageId, 'Amal bekor qilindi.');
    }

    private function savePublishedPost(int $messageId, string $text): void
    {
        if ($messageId <= 0 || trim($text) === '') {
            return;
        }
        $file = dirname(__DIR__) . '/data/published_posts.json';
        $posts = [];
        if (is_file($file)) {
            $raw = @file_get_contents($file);
            if ($raw !== false && $raw !== '') {
                $data = json_decode($raw, true);
                if (is_array($data)) {
                    $posts = $data;
                }
            }
        }
        $posts[(string) $messageId] = [
            'text' => $text,
            'time' => time(),
        ];
        if (count($posts) > 500) {
            asort($posts);
            $posts = array_slice($posts, -300, null, true);
        }
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $json = json_encode($posts, JSON_UNESCAPED_UNICODE);
        $tmpFile = $file . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmpFile, $json, LOCK_EX) !== false) {
            @rename($tmpFile, $file);
        }
    }

    private function getPublishedPost(int $messageId): ?string
    {
        if ($messageId <= 0) {
            return null;
        }
        $file = dirname(__DIR__) . '/data/published_posts.json';
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }
        return $data[(string) $messageId]['text'] ?? null;
    }

    private function deletePublishedPost(int $messageId): void
    {
        $file = dirname(__DIR__) . '/data/published_posts.json';
        if (!is_file($file)) {
            return;
        }
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data[(string) $messageId])) {
            return;
        }
        unset($data[(string) $messageId]);
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        $tmpFile = $file . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmpFile, $json, LOCK_EX) !== false) {
            @rename($tmpFile, $file);
        }
    }

    private function saveAdminSession(string $adminId, array $session): void
    {
        $file = dirname(__DIR__) . '/data/admin_sessions.json';
        $sessions = [];
        if (is_file($file)) {
            $raw = @file_get_contents($file);
            if ($raw !== false && $raw !== '') {
                $data = json_decode($raw, true);
                if (is_array($data)) {
                    $sessions = $data;
                }
            }
        }
        $sessions[$adminId] = $session;
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $json = json_encode($sessions, JSON_UNESCAPED_UNICODE);
        $tmpFile = $file . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmpFile, $json, LOCK_EX) !== false) {
            @rename($tmpFile, $file);
        }
    }

    private function getAdminSession(string $adminId): ?array
    {
        $file = dirname(__DIR__) . '/data/admin_sessions.json';
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }
        return $data[$adminId] ?? null;
    }

    private function deleteAdminSession(string $adminId): void
    {
        $file = dirname(__DIR__) . '/data/admin_sessions.json';
        if (!is_file($file)) {
            return;
        }
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data[$adminId])) {
            return;
        }
        unset($data[$adminId]);
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        $tmpFile = $file . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmpFile, $json, LOCK_EX) !== false) {
            @rename($tmpFile, $file);
        }
    }
}
