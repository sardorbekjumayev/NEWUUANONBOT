<?php

declare(strict_types=1);

namespace PUAnonymous;

final class AI
{
    public function __construct(
        private readonly bool $geminiEnabled,
        private readonly string $geminiApiKey,
        private readonly string $geminiModel,
        private readonly bool $groqEnabled,
        private readonly string $groqApiKey,
        private readonly string $groqModel,
    ) {
    }

    public function classifyText(string $content): array
    {
        if (!$this->geminiEnabled && !$this->groqEnabled) {
            return ['decision' => 'allow', 'category' => 'safe', 'disabled' => true];
        }

        $local = $this->localDecision($content);
        if ($local !== null) {
            return $local;
        }

        if ($this->geminiEnabled) {
            return $this->classifyWithGemini($content);
        }

        return $this->classifyWithGroq($content);
    }

    private function classifyWithGemini(string $content): array
    {
        if ($this->geminiApiKey === '') {
            return ['decision' => 'review', 'category' => 'other', 'unavailable' => true];
        }

        $prompt = $this->prompt();

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($this->geminiModel) . ':generateContent?key=' . rawurlencode($this->geminiApiKey);
        $payload = json_encode([
            'contents' => [[
                'role' => 'user',
                'parts' => [['text' => $prompt . "\n" . $content]],
            ]],
            'generationConfig' => [
                'temperature' => 0,
                'maxOutputTokens' => 256,
                'responseMimeType' => 'application/json',
            ],
        ]);

        $response = $this->postJson($url, $payload, ['Content-Type: application/json'], 'Gemini API failed');
        if ($response === null) {
            return ['decision' => 'review', 'category' => 'other', 'unavailable' => true];
        }

        $data = json_decode($response, true);
        $text = (string) ($data['candidates'][0]['content']['parts'][0]['text'] ?? '');

        return $this->normalizeAiJson($text, 'Gemini response invalid');
    }

    private function classifyWithGroq(string $content): array
    {
        if ($this->groqApiKey === '') {
            return ['decision' => 'review', 'category' => 'other', 'unavailable' => true];
        }

        $payload = json_encode([
            'model' => $this->groqModel,
            'temperature' => 0,
            'max_tokens' => 80,
            'response_format' => ['type' => 'json_object'],
            'messages' => [[
                'role' => 'user',
                'content' => $this->prompt() . "\n" . $content,
            ]],
        ]);

        $response = $this->postJson(
            'https://api.groq.com/openai/v1/chat/completions',
            $payload,
            [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->groqApiKey,
            ],
            'Groq API failed'
        );

        if ($response === null) {
            return ['decision' => 'review', 'category' => 'other', 'unavailable' => true];
        }

        $data = json_decode($response, true);
        $text = (string) ($data['choices'][0]['message']['content'] ?? '');

        return $this->normalizeAiJson($text, 'Groq response invalid');
    }

    private function prompt(): string
    {
        return <<<'PROMPT'
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
    }

    private function postJson(string $url, string|false $payload, array $headers, string $errorMessage): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 15,
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            Helpers::log('ERROR', $errorMessage, ['error' => $error]);
            return null;
        }

        return (string) $response;
    }

    private function normalizeAiJson(string $text, string $errorMessage): array
    {
        $json = json_decode(trim($text), true);

        if (!is_array($json)) {
            Helpers::log('ERROR', $errorMessage);
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
