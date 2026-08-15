<?php

declare(strict_types=1);

namespace PUAnonymous;

final class Helpers
{
    public static function log(string $level, string $message, array $context = []): void
    {
        $safe = [];
        foreach ($context as $key => $value) {
            if (in_array($key, ['user_id', 'username', 'text', 'caption', 'content'], true)) {
                continue;
            }
            $safe[$key] = is_scalar($value) ? $value : gettype($value);
        }

        error_log(trim($level . ' ' . $message . ($safe === [] ? '' : ' ' . json_encode($safe))));
    }

    public static function hmacToken(array $payload, string $secret, int $ttl = 604800): string
    {
        $payload['exp'] = time() + $ttl;
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $body = self::b64($json ?: '{}');
        $sig = self::shortSig($body, $secret);

        return $body . $sig;
    }

    public static function verifyHmacToken(string $token, string $secret): ?array
    {
        if (str_contains($token, '.')) {
            [$body, $sig] = array_pad(explode('.', $token, 2), 2, '');
        } else {
            $body = substr($token, 0, -16);
            $sig = substr($token, -16);
        }

        if ($body === '' || $sig === '' || !hash_equals(self::shortSig($body, $secret), $sig)) {
            return null;
        }

        $payload = json_decode(self::b64Decode($body), true);
        if (!is_array($payload) || (int) ($payload['exp'] ?? 0) < time()) {
            return null;
        }

        return $payload;
    }

    public static function seal(array $payload, string $secret): string
    {
        $key = hash('sha256', $secret, true);
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt(
            json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}',
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        return self::b64($iv . $tag . ($cipher ?: ''));
    }

    public static function openSeal(string $token, string $secret): ?array
    {
        $raw = self::b64Decode($token);
        if (strlen($raw) < 29) {
            return null;
        }

        $key = hash('sha256', $secret, true);
        $json = openssl_decrypt(
            substr($raw, 28),
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            substr($raw, 0, 12),
            substr($raw, 12, 16)
        );

        $payload = json_decode((string) $json, true);
        return is_array($payload) ? $payload : null;
    }

    public static function b64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public static function b64Decode(string $value): string
    {
        return base64_decode(strtr($value . str_repeat('=', (4 - strlen($value) % 4) % 4), '-_', '+/')) ?: '';
    }

    private static function shortSig(string $body, string $secret): string
    {
        return substr(self::b64(hash_hmac('sha256', $body, $secret, true)), 0, 16);
    }
}
