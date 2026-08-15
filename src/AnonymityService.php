<?php
// src/AnonymityService.php

declare(strict_types=1);

namespace UAC;

use PDO;
use Exception;

class AnonymityService {
    private PDO $db;
    private string $appSecret;

    public function __construct(?PDO $db = null) {
        $this->db = $db ?? Database::getConnection();
        $config = require __DIR__ . '/../config.php';
        $this->appSecret = $config['app_secret'];
    }

    /**
     * Hash Telegram User ID with salt to preserve anonymity.
     */
    public function hashTelegramUser(int $tgUserId): string {
        return hash_hmac('sha256', (string)$tgUserId, $this->appSecret);
    }

    /**
     * Retrieve existing active anonymous session or create a new one.
     */
    public function getOrCreateSession(int $tgUserId): string {
        $tgHash = $this->hashTelegramUser($tgUserId);
        $now = date('Y-m-d H:i:s');

        $stmt = $this->db->prepare("SELECT anon_id, expires_at FROM anonymous_sessions WHERE tg_hash = ? AND status = 'active'");
        $stmt->execute([$tgHash]);
        $session = $stmt->fetch();

        if ($session && $session['expires_at'] > $now) {
            return $session['anon_id'];
        }

        // Generate brand new anonymous ID: anon_ + 6 random hex chars
        $anonId = 'anon_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));

        if ($session) {
            // Refresh existing
            $update = $this->db->prepare("UPDATE anonymous_sessions SET anon_id = ?, created_at = ?, expires_at = ? WHERE tg_hash = ?");
            $update->execute([$anonId, $now, $expiresAt, $tgHash]);
        } else {
            $insert = $this->db->prepare("INSERT INTO anonymous_sessions (anon_id, tg_hash, created_at, expires_at, status) VALUES (?, ?, ?, ?, 'active')");
            $insert->execute([$anonId, $tgHash, $now, $expiresAt]);
        }

        return $anonId;
    }

    /**
     * Generate cryptographically secure ownership token for message management.
     */
    public function generateOwnerToken(): string {
        return bin2hex(random_bytes(32));
    }

    /**
     * Generate unique random public/moderation IDs.
     */
    public function generateUniqueId(string $prefix = 'UAC_'): string {
        $characters = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $random = '';
        for ($i = 0; $i < 5; $i++) {
            $random .= $characters[random_int(0, strlen($characters) - 1)];
        }
        return '#' . $prefix . $random;
    }

    /**
     * Redact personal identifying information (PII) from user text.
     */
    public function sanitizePersonalData(string $text): array {
        $detectedTypes = [];
        $sanitized = $text;

        // 1. Phone numbers (e.g. +998901234567, 901234567, 90-123-45-67)
        $phoneRegex = '/(\+?998[-\s]?\d{2}[-\s]?\d{3}[-\s]?\d{2}[-\s]?\d{2}|\b9[01345789]\d[-\s]?\d{3}[-\s]?\d{2}[-\s]?\d{2}\b)/';
        if (preg_match($phoneRegex, $sanitized)) {
            $detectedTypes[] = 'phone';
            $sanitized = preg_replace($phoneRegex, '[SHAXSIY MA\'LUMOT O\'CHIRILDI - TELEFON]', $sanitized);
        }

        // 2. Email addresses
        $emailRegex = '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/';
        if (preg_match($emailRegex, $sanitized)) {
            $detectedTypes[] = 'email';
            $sanitized = preg_replace($emailRegex, '[SHAXSIY MA\'LUMOT O\'CHIRILDI - EMAIL]', $sanitized);
        }

        // 3. Telegram & Social Handles (@username)
        $handleRegex = '/(?<!\w)@[a-zA-Z0-9_]{4,32}\b/';
        if (preg_match($handleRegex, $sanitized)) {
            $detectedTypes[] = 'username';
            $sanitized = preg_replace($handleRegex, '[SHAXSIY MA\'LUMOT O\'CHIRILDI - USERNAME]', $sanitized);
        }

        // 4. URLs (http/https and t.me links)
        $urlRegex = '/(https?:\/\/[^\s]+|t\.me\/[^\s]+)/i';
        if (preg_match($urlRegex, $sanitized)) {
            $detectedTypes[] = 'url';
            $sanitized = preg_replace($urlRegex, '[LINK O\'CHIRILDI]', $sanitized);
        }

        // 5. Card / Passport / Student ID Numbers
        $cardRegex = '/\b\d{4}[-\s]?\d{4}[-\s]?\d{4}[-\s]?\d{4}\b/';
        if (preg_match($cardRegex, $sanitized)) {
            $detectedTypes[] = 'card_number';
            $sanitized = preg_replace($cardRegex, '[KARTA NOMI/NUMERI O\'CHIRILDI]', $sanitized);
        }

        return [
            'sanitized_text' => $sanitized,
            'contains_personal_data' => !empty($detectedTypes),
            'detected_types' => $detectedTypes
        ];
    }
}
