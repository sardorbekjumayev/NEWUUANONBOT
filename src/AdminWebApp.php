<?php

declare(strict_types=1);

namespace PUAnonymous;

final class AdminWebApp
{
    public function __construct(
        private readonly array $config,
        private readonly AdminManager $adminManager,
        private readonly Wordlist $wordlist,
    ) {
    }

    /**
     * Validates Telegram WebApp initData string or secret token
     */
    public function authenticateRequest(): ?array
    {
        $initData = $_SERVER['HTTP_X_TELEGRAM_INIT_DATA'] ?? $_GET['initData'] ?? $_POST['initData'] ?? '';
        $secretToken = $_SERVER['HTTP_X_ADMIN_SECRET'] ?? $_GET['secret_token'] ?? '';

        // 1. Secret token auth (direct access fallback with app_secret)
        if ($secretToken !== '' && ($this->config['app_secret'] ?? '') !== '') {
            if (hash_equals((string) $this->config['app_secret'], (string) $secretToken)) {
                return ['user_id' => 'admin_secret', 'username' => 'SuperAdmin'];
            }
        }

        // 2. Telegram WebApp initData authentication
        if ($initData === '') {
            return null;
        }

        parse_str($initData, $params);
        if (!is_array($params) || empty($params['hash'])) {
            return null;
        }

        $hash = (string) $params['hash'];
        unset($params['hash']);

        ksort($params);
        $dataCheckArr = [];
        foreach ($params as $key => $val) {
            $dataCheckArr[] = $key . '=' . $val;
        }
        $dataCheckString = implode("\n", $dataCheckArr);

        $botToken = (string) ($this->config['bot_token'] ?? '');
        $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $calculatedHash = hash_hmac('sha256', $dataCheckString, $secretKey);

        if (!hash_equals($calculatedHash, $hash)) {
            return null;
        }

        $userJson = $params['user'] ?? '';
        $user = json_decode((string) $userJson, true);
        if (!is_array($user) || empty($user['id'])) {
            return null;
        }

        $telegramId = (string) $user['id'];
        if (!$this->adminManager->isAdmin($telegramId, $this->config['admin_ids'])) {
            return null;
        }

        return [
            'user_id' => $telegramId,
            'first_name' => $user['first_name'] ?? '',
            'username' => $user['username'] ?? '',
        ];
    }

    public function handleApiRequest(string $endpoint): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $user = $this->authenticateRequest();
        if ($user === null) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Unauthorized or non-admin access']);
            return;
        }

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $input = json_decode((string) file_get_contents('php://input'), true) ?? $_POST;

        switch ($endpoint) {
            case '/api/admin/status':
                $this->getStatus();
                break;

            case '/api/admin/ai':
                if ($method === 'POST') {
                    $this->updateAiSettings($input);
                } else {
                    $this->getAiSettings();
                }
                break;

            case '/api/admin/admins':
                if ($method === 'POST') {
                    $this->manageAdmins($input);
                } else {
                    $this->getAdmins();
                }
                break;

            case '/api/admin/wordlist':
                if ($method === 'POST') {
                    $this->manageWordlist($input);
                } else {
                    $this->getWordlist();
                }
                break;

            default:
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Endpoint not found']);
                break;
        }
    }

    private function getStatus(): void
    {
        $admins = $this->adminManager->getAdmins($this->config['admin_ids']);
        $words = $this->wordlist->getAll();

        $geminiKey = (string) ($this->config['gemini_api_key'] ?? '');
        $groqKey = (string) ($this->config['groq_api_key'] ?? '');

        echo json_encode([
            'ok' => true,
            'status' => 'online',
            'bot_username' => $this->config['bot_username'] ?? '',
            'admins_count' => count($admins),
            'wordlist_count' => count($words),
            'ai' => [
                'gemini_enabled' => (bool) ($this->config['gemini_enabled'] ?? false),
                'gemini_model' => $this->config['gemini_model'] ?? 'gemini-2.5-flash-lite',
                'gemini_key_set' => $geminiKey !== '' && !str_contains($geminiKey, 'YOUR_'),
                'gemini_key_masked' => $this->maskKey($geminiKey),
                'groq_enabled' => (bool) ($this->config['groq_enabled'] ?? false),
                'groq_model' => $this->config['groq_model'] ?? 'llama-3.1-8b-instant',
                'groq_key_set' => $groqKey !== '',
                'groq_key_masked' => $this->maskKey($groqKey),
            ],
        ]);
    }

    private function getAiSettings(): void
    {
        $geminiKey = (string) ($this->config['gemini_api_key'] ?? '');
        $groqKey = (string) ($this->config['groq_api_key'] ?? '');

        echo json_encode([
            'ok' => true,
            'gemini_enabled' => (bool) ($this->config['gemini_enabled'] ?? false),
            'gemini_api_key' => $geminiKey,
            'gemini_model' => $this->config['gemini_model'] ?? 'gemini-2.5-flash-lite',
            'groq_enabled' => (bool) ($this->config['groq_enabled'] ?? false),
            'groq_api_key' => $groqKey,
            'groq_model' => $this->config['groq_model'] ?? 'llama-3.1-8b-instant',
        ]);
    }

    private function updateAiSettings(array $input): void
    {
        $settings = [];

        if (array_key_exists('gemini_enabled', $input)) {
            $settings['gemini_enabled'] = filter_var($input['gemini_enabled'], FILTER_VALIDATE_BOOLEAN);
        }
        if (array_key_exists('gemini_api_key', $input)) {
            $key = trim((string) $input['gemini_api_key']);
            if ($key !== '') {
                $settings['gemini_api_key'] = $key;
            }
        }
        if (array_key_exists('gemini_model', $input)) {
            $settings['gemini_model'] = trim((string) $input['gemini_model']) ?: 'gemini-2.5-flash-lite';
        }
        if (array_key_exists('groq_enabled', $input)) {
            $settings['groq_enabled'] = filter_var($input['groq_enabled'], FILTER_VALIDATE_BOOLEAN);
        }
        if (array_key_exists('groq_api_key', $input)) {
            $key = trim((string) $input['groq_api_key']);
            if ($key !== '') {
                $settings['groq_api_key'] = $key;
            }
        }
        if (array_key_exists('groq_model', $input)) {
            $settings['groq_model'] = trim((string) $input['groq_model']) ?: 'llama-3.1-8b-instant';
        }

        $this->adminManager->updateAiSettings($settings);

        echo json_encode([
            'ok' => true,
            'message' => 'AI sozlamalari yangilandi',
        ]);
    }

    private function getAdmins(): void
    {
        $admins = $this->adminManager->getAdmins($this->config['admin_ids']);
        echo json_encode([
            'ok' => true,
            'admins' => $admins,
        ]);
    }

    private function manageAdmins(array $input): void
    {
        $action = (string) ($input['action'] ?? '');
        $telegramId = trim((string) ($input['telegram_id'] ?? ''));

        if ($telegramId === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Telegram ID kiritilmadi']);
            return;
        }

        if ($action === 'add') {
            $ok = $this->adminManager->addAdmin($telegramId, $this->config['admin_ids']);
            echo json_encode([
                'ok' => $ok,
                'message' => $ok ? 'Yangi admin qo\'shildi' : 'Admin qo\'shishda xatolik',
                'admins' => $this->adminManager->getAdmins($this->config['admin_ids']),
            ]);
            return;
        }

        if ($action === 'remove') {
            $ok = $this->adminManager->removeAdmin($telegramId, $this->config['admin_ids']);
            echo json_encode([
                'ok' => $ok,
                'message' => $ok ? 'Admin olib tashlandi' : 'Adminni o\'chirishda xatolik',
                'admins' => $this->adminManager->getAdmins($this->config['admin_ids']),
            ]);
            return;
        }

        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Noma\'lum harakat']);
    }

    private function getWordlist(): void
    {
        echo json_encode([
            'ok' => true,
            'words' => $this->wordlist->getAll(),
        ]);
    }

    private function manageWordlist(array $input): void
    {
        $action = (string) ($input['action'] ?? '');

        if ($action === 'add') {
            $word = trim((string) ($input['word'] ?? ''));
            if ($word === '') {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'So\'z kiritilmadi']);
                return;
            }

            $ok = $this->wordlist->add($word);
            echo json_encode([
                'ok' => $ok,
                'message' => $ok ? 'So\'z wordlistga qo\'shildi' : 'Xatolik',
                'words' => $this->wordlist->getAll(),
            ]);
            return;
        }

        if ($action === 'update') {
            $oldWord = trim((string) ($input['old_word'] ?? ''));
            $newWord = trim((string) ($input['new_word'] ?? ''));

            $ok = $this->wordlist->update($oldWord, $newWord);
            echo json_encode([
                'ok' => $ok,
                'message' => $ok ? 'So\'z tahrirlandi' : 'So\'z topilmadi yoki xatolik',
                'words' => $this->wordlist->getAll(),
            ]);
            return;
        }

        if ($action === 'delete') {
            $word = trim((string) ($input['word'] ?? ''));
            $ok = $this->wordlist->delete($word);
            echo json_encode([
                'ok' => $ok,
                'message' => $ok ? 'So\'z o\'chirildi' : 'So\'z topilmadi',
                'words' => $this->wordlist->getAll(),
            ]);
            return;
        }

        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Noma\'lum harakat']);
    }

    private function maskKey(string $key): string
    {
        if ($key === '') {
            return 'O\'rnatilmagan';
        }
        $len = strlen($key);
        if ($len <= 8) {
            return '••••••••';
        }
        return substr($key, 0, 4) . '••••' . substr($key, -4);
    }
}
