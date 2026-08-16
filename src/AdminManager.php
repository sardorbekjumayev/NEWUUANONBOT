<?php

declare(strict_types=1);

namespace PUAnonymous;

final class AdminManager
{
    private string $filePath;
    private array $data = [];

    public function __construct(?string $filePath = null)
    {
        $this->filePath = $filePath ?? dirname(__DIR__) . '/data/settings.json';
        $this->load();
    }

    private function load(): void
    {
        if (is_file($this->filePath)) {
            $raw = @file_get_contents($this->filePath);
            if ($raw !== false && $raw !== '') {
                $data = json_decode($raw, true);
                if (is_array($data)) {
                    $this->data = $data;
                    return;
                }
            }
            Helpers::log('ERROR', 'AdminManager settings file read/parse issue, preserving file state', ['file' => $this->filePath]);
        }

        $this->data = [
            'admin_ids' => [],
            'gemini_enabled' => null,
            'gemini_api_key' => null,
            'gemini_model' => null,
            'groq_enabled' => null,
            'groq_api_key' => null,
            'groq_model' => null,
        ];
    }

    private function save(): void
    {
        $dir = dirname($this->filePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $json = json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $tmpFile = $this->filePath . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmpFile, $json, LOCK_EX) !== false) {
            @rename($tmpFile, $this->filePath);
        } else {
            @file_put_contents($this->filePath, $json, LOCK_EX);
        }
    }


    /**
     * @param array $baseConfig Base config loaded from config.php / .env
     */
    public function getMergedConfig(array $baseConfig): array
    {
        $merged = $baseConfig;

        // Admins: combine base config admin_ids with dynamic admin_ids
        $admins = array_values(array_unique(array_filter(array_map(
            'strval',
            array_merge($baseConfig['admin_ids'] ?? [], $this->data['admin_ids'] ?? [])
        ))));
        $merged['admin_ids'] = $admins;

        if (isset($this->data['gemini_enabled']) && $this->data['gemini_enabled'] !== null) {
            $merged['gemini_enabled'] = (bool) $this->data['gemini_enabled'];
        }

        if (isset($this->data['gemini_api_key']) && $this->data['gemini_api_key'] !== null) {
            $merged['gemini_api_key'] = (string) $this->data['gemini_api_key'];
        }

        if (isset($this->data['gemini_model']) && $this->data['gemini_model'] !== null) {
            $merged['gemini_model'] = (string) $this->data['gemini_model'];
        }

        if (isset($this->data['groq_enabled']) && $this->data['groq_enabled'] !== null) {
            $merged['groq_enabled'] = (bool) $this->data['groq_enabled'];
        }

        if (isset($this->data['groq_api_key']) && $this->data['groq_api_key'] !== null) {
            $merged['groq_api_key'] = (string) $this->data['groq_api_key'];
        }

        if (isset($this->data['groq_model']) && $this->data['groq_model'] !== null) {
            $merged['groq_model'] = (string) $this->data['groq_model'];
        }

        return $merged;
    }

    /**
     * @param array $baseAdmins Initial admins from env
     * @return array<string>
     */
    public function getAdmins(array $baseAdmins = []): array
    {
        $dynamic = $this->data['admin_ids'] ?? [];
        return array_values(array_unique(array_filter(array_map(
            'strval',
            array_merge($baseAdmins, $dynamic)
        ))));
    }

    public function addAdmin(string $telegramId, array $baseAdmins = []): bool
    {
        $cleanId = trim($telegramId);
        if ($cleanId === '' || !ctype_digit(ltrim($cleanId, '-'))) {
            return false;
        }

        $current = $this->getAdmins($baseAdmins);
        if (in_array($cleanId, $current, true)) {
            return true;
        }

        $dynamic = $this->data['admin_ids'] ?? [];
        $dynamic[] = $cleanId;
        $this->data['admin_ids'] = array_values(array_unique($dynamic));
        $this->save();
        return true;
    }

    public function removeAdmin(string $telegramId, array $baseAdmins = []): bool
    {
        $cleanId = trim($telegramId);
        if ($cleanId === '') {
            return false;
        }

        // Remove from dynamic storage
        $dynamic = $this->data['admin_ids'] ?? [];
        $key = array_search($cleanId, $dynamic, true);
        if ($key !== false) {
            unset($dynamic[$key]);
            $this->data['admin_ids'] = array_values($dynamic);
            $this->save();
        }

        return true;
    }

    public function isAdmin(string $telegramId, array $baseAdmins = []): bool
    {
        $cleanId = trim($telegramId);
        if ($cleanId === '') {
            return false;
        }

        return in_array($cleanId, $this->getAdmins($baseAdmins), true);
    }

    public function updateAiSettings(array $settings): void
    {
        if (array_key_exists('gemini_enabled', $settings)) {
            $this->data['gemini_enabled'] = (bool) $settings['gemini_enabled'];
        }
        if (array_key_exists('gemini_api_key', $settings)) {
            $this->data['gemini_api_key'] = (string) $settings['gemini_api_key'];
        }
        if (array_key_exists('gemini_model', $settings)) {
            $this->data['gemini_model'] = (string) $settings['gemini_model'];
        }
        if (array_key_exists('groq_enabled', $settings)) {
            $this->data['groq_enabled'] = (bool) $settings['groq_enabled'];
        }
        if (array_key_exists('groq_api_key', $settings)) {
            $this->data['groq_api_key'] = (string) $settings['groq_api_key'];
        }
        if (array_key_exists('groq_model', $settings)) {
            $this->data['groq_model'] = (string) $settings['groq_model'];
        }

        $this->save();

        // Synchronize with .env file as well if present
        $this->updateEnvFile([
            'GEMINI_ENABLED' => ($this->data['gemini_enabled'] ?? false) ? 'true' : 'false',
            'GEMINI_API_KEY' => (string) ($this->data['gemini_api_key'] ?? ''),
            'GEMINI_MODEL' => (string) ($this->data['gemini_model'] ?? 'gemini-2.5-flash-lite'),
            'GROQ_ENABLED' => ($this->data['groq_enabled'] ?? false) ? 'true' : 'false',
            'GROQ_API_KEY' => (string) ($this->data['groq_api_key'] ?? ''),
            'GROQ_MODEL' => (string) ($this->data['groq_model'] ?? 'llama-3.1-8b-instant'),
        ]);
    }

    private function updateEnvFile(array $values): void
    {
        $path = dirname(__DIR__) . '/.env';
        if (!is_file($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return;
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
}
