<?php
// src/RateLimiter.php

declare(strict_types=1);

namespace UAC;

use PDO;

class RateLimiter {
    private PDO $db;
    private array $limits;

    public function __construct(?PDO $db = null) {
        $this->db = $db ?? Database::getConnection();
        $config = require __DIR__ . '/../config.php';
        $this->limits = $config['rate_limits'];
    }

    /**
     * Check if user exceeded submission or comment rate limit.
     */
    public function checkRateLimit(string $anonId, string $actionType = 'submission'): bool {
        $windowSeconds = ($actionType === 'submission') ? 600 : 1800; // 10m or 30m
        $maxAllowed = ($actionType === 'submission') ? $this->limits['submissions'] : $this->limits['comments'];
        $cutoff = time() - $windowSeconds;

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM rate_limits WHERE anon_id = ? AND action_type = ? AND timestamp >= ?");
        $stmt->execute([$anonId, $actionType, $cutoff]);
        $count = (int)$stmt->fetchColumn();

        return $count < $maxAllowed;
    }

    /**
     * Log user activity timestamp.
     */
    public function recordAction(string $anonId, string $actionType = 'submission'): void {
        $now = time();
        $stmt = $this->db->prepare("INSERT INTO rate_limits (anon_id, action_type, timestamp) VALUES (?, ?, ?)");
        $stmt->execute([$anonId, $actionType, $now]);

        // Cleanup old logs older than 2 hours
        $oldCutoff = $now - 7200;
        $cleanup = $this->db->prepare("DELETE FROM rate_limits WHERE timestamp < ?");
        $cleanup->execute([$oldCutoff]);
    }

    /**
     * Normalize text for duplicate comparison.
     */
    public function normalizeText(string $text): string {
        $text = mb_strtolower($text, 'UTF-8');
        // Replace non-alphanumeric characters with space
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', '', $text);
        return trim(preg_replace('/\s+/', ' ', $text));
    }

    /**
     * Check if message content is a duplicate of recent submissions (last 24 hours).
     */
    public function isDuplicate(string $content): bool {
        $normalized = $this->normalizeText($content);
        if (mb_strlen($normalized, 'UTF-8') < 5) {
            return false;
        }

        $cutoff = date('Y-m-d H:i:s', strtotime('-24 hours'));
        $stmt = $this->db->prepare("SELECT content FROM submissions WHERE created_at >= ? ORDER BY id DESC LIMIT 100");
        $stmt->execute([$cutoff]);
        $recentPosts = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($recentPosts as $pastContent) {
            $pastNormalized = $this->normalizeText($pastContent);
            
            // Exact match on normalized text
            if ($normalized === $pastNormalized) {
                return true;
            }

            // High similarity check using levenshtein for short texts or similar percentage for long
            if (mb_strlen($normalized, 'UTF-8') < 255 && mb_strlen($pastNormalized, 'UTF-8') < 255) {
                $lev = levenshtein($normalized, $pastNormalized);
                $maxLen = max(mb_strlen($normalized, 'UTF-8'), mb_strlen($pastNormalized, 'UTF-8'));
                if ($maxLen > 0 && (1 - ($lev / $maxLen)) > 0.85) {
                    return true;
                }
            }
        }

        return false;
    }
}
