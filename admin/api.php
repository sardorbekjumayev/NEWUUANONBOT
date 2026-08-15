<?php
// admin/api.php - REST API for Admin Dashboard

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/AnonymityService.php';
require_once __DIR__ . '/../src/TelegramBot.php';
require_once __DIR__ . '/../src/PublisherService.php';

use UAC\Database;
use UAC\TelegramBot;
use UAC\PublisherService;

header('Content-Type: application/json');

$db = Database::getConnection();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Handle Login
if ($action === 'login') {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true) ?? $_POST;
    $username = trim($data['username'] ?? '');
    $password = trim($data['password'] ?? '');

    $stmt = $db->prepare("SELECT * FROM admin_users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['admin_logged'] = true;
        $_SESSION['admin_user'] = $user['username'];
        $_SESSION['admin_role'] = $user['role'];

        echo json_encode([
            'success' => true,
            'user' => [
                'username' => $user['username'],
                'role' => $user['role']
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Login yoki parol xato!']);
    }
    exit;
}

// Require Auth for all other API endpoints
if (empty($_SESSION['admin_logged'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Avtorizatsiyadan o\'tilmagan!']);
    exit;
}

// Handle Admin Actions
switch ($action) {
    case 'stats':
        $total = $db->query("SELECT COUNT(*) FROM submissions")->fetchColumn();
        $pending = $db->query("SELECT COUNT(*) FROM submissions WHERE status = 'pending'")->fetchColumn();
        $approved = $db->query("SELECT COUNT(*) FROM submissions WHERE status = 'approved'")->fetchColumn();
        $rejected = $db->query("SELECT COUNT(*) FROM submissions WHERE status = 'rejected'")->fetchColumn();
        $spam = $db->query("SELECT COUNT(*) FROM submissions WHERE status = 'spam'")->fetchColumn();
        $today = $db->query("SELECT COUNT(*) FROM submissions WHERE DATE(created_at) = CURRENT_DATE()")->fetchColumn();
        $comments = $db->query("SELECT COUNT(*) FROM comments")->fetchColumn();

        echo json_encode([
            'success' => true,
            'stats' => [
                'total' => (int)$total,
                'pending' => (int)$pending,
                'approved' => (int)$approved,
                'rejected' => (int)$rejected,
                'spam' => (int)$spam,
                'today' => (int)$today,
                'comments' => (int)$comments
            ]
        ]);
        break;

    case 'queue':
        $stmt = $db->prepare("SELECT id, public_id, mod_id, category, sanitized_content, ai_status, ai_score, ai_reason, created_at FROM submissions WHERE status = 'pending' ORDER BY id DESC LIMIT 50");
        $stmt->execute();
        $queue = $stmt->fetchAll();

        echo json_encode(['success' => true, 'queue' => $queue]);
        break;

    case 'published':
        $stmt = $db->prepare("SELECT id, public_id, category, sanitized_content, created_at FROM submissions WHERE status = 'approved' ORDER BY id DESC LIMIT 50");
        $stmt->execute();
        $posts = $stmt->fetchAll();

        echo json_encode(['success' => true, 'posts' => $posts]);
        break;

    case 'rejected':
        $stmt = $db->prepare("SELECT id, public_id, category, sanitized_content, rejection_reason, created_at FROM submissions WHERE status = 'rejected' ORDER BY id DESC LIMIT 50");
        $stmt->execute();
        $posts = $stmt->fetchAll();

        echo json_encode(['success' => true, 'posts' => $posts]);
        break;

    case 'approve':
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true) ?? $_POST;
        $id = (int)($data['id'] ?? 0);

        if ($id > 0) {
            $upd = $db->prepare("UPDATE submissions SET status = 'approved', updated_at = ? WHERE id = ?");
            $upd->execute([date('Y-m-d H:i:s'), $id]);

            $publisher = new PublisherService($db);
            $publisher->publishToChannel($id);

            logAudit($db, $_SESSION['admin_user'], 'APPROVE_SUBMISSION', (string)$id);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'ID xato']);
        }
        break;

    case 'reject':
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true) ?? $_POST;
        $id = (int)($data['id'] ?? 0);
        $reason = trim($data['reason'] ?? 'Qoidabuzarlik');

        if ($id > 0) {
            $upd = $db->prepare("UPDATE submissions SET status = 'rejected', rejection_reason = ?, updated_at = ? WHERE id = ?");
            $upd->execute([$reason, date('Y-m-d H:i:s'), $id]);

            logAudit($db, $_SESSION['admin_user'], 'REJECT_SUBMISSION', (string)$id);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'ID xato']);
        }
        break;

    case 'edit_approve':
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true) ?? $_POST;
        $id = (int)($data['id'] ?? 0);
        $content = trim($data['content'] ?? '');

        if ($id > 0 && !empty($content)) {
            $upd = $db->prepare("UPDATE submissions SET sanitized_content = ?, status = 'approved', updated_at = ? WHERE id = ?");
            $upd->execute([$content, date('Y-m-d H:i:s'), $id]);

            $publisher = new PublisherService($db);
            $publisher->publishToChannel($id);

            logAudit($db, $_SESSION['admin_user'], 'EDIT_APPROVE_SUBMISSION', (string)$id);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Ma\'lumot yetarsiz']);
        }
        break;

    case 'audit_logs':
        $stmt = $db->query("SELECT * FROM audit_logs ORDER BY id DESC LIMIT 100");
        echo json_encode(['success' => true, 'logs' => $stmt->fetchAll()]);
        break;

    case 'logout':
        session_destroy();
        echo json_encode(['success' => true]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Noma\'lum amal']);
        break;
}

function logAudit(PDO $db, string $adminUser, string $action, string $targetId): void {
    $stmt = $db->prepare("INSERT INTO audit_logs (admin_identifier, action, target_id, details, created_at) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$adminUser, $action, $targetId, 'Dashboard action', date('Y-m-d H:i:s')]);
}
