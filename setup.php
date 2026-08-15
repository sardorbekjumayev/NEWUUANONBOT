<?php
// setup.php - Initialize Database and Seed Default Data

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/Database.php';

use UAC\Database;

echo "=== University Anonymous Community Platform Setup ===\n";

try {
    $db = Database::getConnection();
    Database::initSchema();
    echo "✓ Database schema initialized successfully.\n";

    $config = require __DIR__ . '/config.php';
    $defaultUser = $config['admin']['default_user'];
    $defaultPass = $config['admin']['default_pass'];

    // Check if admin user exists
    $stmt = $db->prepare("SELECT COUNT(*) FROM admin_users WHERE username = ?");
    $stmt->execute([$defaultUser]);
    if ($stmt->fetchColumn() == 0) {
        $hash = password_hash($defaultPass, PASSWORD_DEFAULT);
        $insert = $db->prepare("INSERT INTO admin_users (username, password_hash, role, created_at) VALUES (?, ?, 'SUPER_ADMIN', ?)");
        $insert->execute([$defaultUser, $hash, date('Y-m-d H:i:s')]);
        echo "✓ Super Admin user created: '{$defaultUser}' / '{$defaultPass}'\n";
    } else {
        echo "✓ Super Admin user already exists.\n";
    }

    echo "=== Setup Completed Successfully! ===\n";
} catch (Exception $e) {
    echo "❌ Error during setup: " . $e->getMessage() . "\n";
    exit(1);
}
