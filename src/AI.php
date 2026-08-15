<?php

declare(strict_types=1);

namespace PUAnonymous;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

final class AI
{
    private Client $client;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
    ) {
        $this->client = new Client([
            'base_uri' => 'https://generativelanguage.googleapis.com/v1beta/',
            'timeout' => 15,
        ]);
    }

    public function classifyText(string $content): array
    {
        $local = $this->localDecision($content);
        if ($local !== null) {
            return $local;
        }

        if ($this->apiKey === '') {
            return ['decision' => 'review', 'category' => 'other', 'unavailable' => true];
        }

        $prompt = <<<'PROMPT'
You are a content moderation classifier for a university anonymous Telegram community.

Classify the submitted content.

ALLOW:
Normal student questions, discussions, opinions, criticism, jokes, memes and harmless community content.

REVIEW:
Content that may be inappropriate, ambiguous, targeted, contain personal information, questionable links, or require human judgment.

REJECT:
Spam, scams, advertisements, doxxing, threats, severe harassment, explicit sexual content, malicious content, or dangerous abuse.

Return ONLY JSON:
{"decision":"allow|review|reject","category":"safe|spam|advertisement|harassment|hate|threat|sexual|doxxing|personal_data|malicious_link|scam|other"}

Do not explain your answer.

Content:
PROMPT;

        try {
            $response = $this->client->post(
                'models/' . rawurlencode($this->model) . ':generateContent?key=' . rawurlencode($this->apiKey),
                [
                    'json' => [
                        'contents' => [[
                            'role' => 'user',
                            'parts' => [['text' => $prompt . "\n" . $content]],
                        ]],
                        'generationConfig' => [
                            'temperature' => 0,
                            'maxOutputTokens' => 40,
                            'responseMimeType' => 'application/json',
                        ],
                    ],
                ]
            );
        } catch (GuzzleException $e) {
            Helpers::log('ERROR', 'Gemini API failed', ['error' => $e->getMessage()]);
            return ['decision' => 'review', 'category' => 'other', 'unavailable' => true];
        }

        $data = json_decode((string) $response->getBody(), true);
        $text = (string) ($data['candidates'][0]['content']['parts'][0]['text'] ?? '');
        $json = json_decode(trim($text), true);

        if (!is_array($json)) {
            Helpers::log('ERROR', 'Gemini response invalid');
            return ['decision' => 'review', 'category' => 'other', 'unavailable' => true];
        }

        $decision = in_array($json['decision'] ?? '', ['allow', 'review', 'reject'], true) ? $json['decision'] : 'review';
        $category = in_array($json['category'] ?? '', [
            'safe',
            'spam',
            'advertisement',
            'harassment',
            'hate',
            'threat',
            'sexual',
            'doxxing',
            'personal_data',
            'malicious_link',
            'scam',
            'other',
        ], true) ? $json['category'] : 'other';

        Helpers::log('INFO', 'AI decision=' . $decision, ['category' => $category]);
        return ['decision' => $decision, 'category' => $category];
    }

    private function localDecision(string $content): ?array
    {
        $trimmed = trim($content);
        if ($trimmed === '') {
            return ['decision' => 'review', 'category' => 'other'];
        }

        preg_match_all('~https?://|t\.me/|www\.~i', $trimmed, $links);
        if (count($links[0]) >= 3) {
            return ['decision' => 'review', 'category' => 'malicious_link'];
        }

        if (preg_match('~(?:\+?\d[\s().-]?){9,}~', $trimmed) === 1) {
            return ['decision' => 'review', 'category' => 'personal_data'];
        }

        if (preg_match('~\b(?:crypto|forex|betting|casino|promo code|airdrop)\b~i', $trimmed) === 1) {
            return ['decision' => 'review', 'category' => 'advertisement'];
        }

        return null;
    }
}
