-- University Anonymous Community Platform Database Schema
-- MySQL 8.0 / MariaDB Compatible

CREATE TABLE IF NOT EXISTS anonymous_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    anon_id VARCHAR(64) UNIQUE NOT NULL,
    tg_hash VARCHAR(64) UNIQUE NOT NULL,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    status VARCHAR(20) DEFAULT 'active',
    INDEX idx_tg_hash (tg_hash),
    INDEX idx_anon_id (anon_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    public_id VARCHAR(20) UNIQUE NOT NULL,
    mod_id VARCHAR(20) UNIQUE NOT NULL,
    anon_id VARCHAR(64) NOT NULL,
    owner_token VARCHAR(64) NOT NULL,
    category VARCHAR(50) DEFAULT 'General',
    content TEXT NOT NULL,
    sanitized_content TEXT NOT NULL,
    media_type VARCHAR(20) DEFAULT NULL,
    media_file_id VARCHAR(255) DEFAULT NULL,
    ai_status VARCHAR(50) DEFAULT 'NEEDS_REVIEW',
    ai_score FLOAT DEFAULT 0.0,
    ai_reason TEXT DEFAULT NULL,
    status VARCHAR(30) DEFAULT 'pending',
    rejection_reason TEXT DEFAULT NULL,
    channel_message_id BIGINT DEFAULT NULL,
    user_dm_chat_id BIGINT DEFAULT NULL,
    user_dm_message_id BIGINT DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_public_id (public_id),
    INDEX idx_mod_id (mod_id),
    INDEX idx_status (status),
    INDEX idx_anon_id (anon_id),
    INDEX idx_owner_token (owner_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    public_id VARCHAR(20) UNIQUE NOT NULL,
    submission_id INT NOT NULL,
    anon_id VARCHAR(64) NOT NULL,
    owner_token VARCHAR(64) NOT NULL,
    content TEXT NOT NULL,
    sanitized_content TEXT NOT NULL,
    ai_status VARCHAR(50) DEFAULT 'APPROVED',
    status VARCHAR(30) DEFAULT 'pending',
    channel_message_id BIGINT DEFAULT NULL,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE,
    INDEX idx_submission_id (submission_id),
    INDEX idx_public_id (public_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_id VARCHAR(20) UNIQUE NOT NULL,
    target_type VARCHAR(20) NOT NULL,
    target_id INT NOT NULL,
    reason TEXT NOT NULL,
    status VARCHAR(20) DEFAULT 'pending',
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS banned_words (
    id INT AUTO_INCREMENT PRIMARY KEY,
    word VARCHAR(100) UNIQUE NOT NULL,
    severity VARCHAR(20) DEFAULT 'medium',
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    key_name VARCHAR(50) UNIQUE NOT NULL,
    name_uz VARCHAR(100) NOT NULL,
    icon VARCHAR(10) NOT NULL,
    is_active INT DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(30) DEFAULT 'MODERATOR',
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_identifier VARCHAR(50) NOT NULL,
    action VARCHAR(100) NOT NULL,
    target_id VARCHAR(50) DEFAULT NULL,
    details TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    anon_id VARCHAR(64) NOT NULL,
    action_type VARCHAR(30) NOT NULL,
    timestamp INT NOT NULL,
    INDEX idx_anon_action (anon_id, action_type, timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deep_links (
    token VARCHAR(64) PRIMARY KEY NOT NULL,
    submission_id INT NOT NULL,
    action_type VARCHAR(30) NOT NULL,
    expires_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed Default Categories if empty
INSERT IGNORE INTO categories (key_name, name_uz, icon, is_active) VALUES
('education', 'Ta''lim', '📚', 1),
('university', 'Universitet', '🏫', 1),
('teachers', 'O''qituvchilar', '👨‍🏫', 1),
('exams', 'Imtihonlar', '📝', 1),
('payments', 'To''lovlar', '💰', 1),
('campus', 'Kampus', '🍽', 1),
('suggestions', 'Takliflar', '💡', 1),
('questions', 'Savollar', '❓', 1),
('humor', 'Yumor', '😂', 1),
('confession', 'Iqror', '❤️', 1),
('announcement', 'E''lon', '📢', 1),
('other', 'Boshqa', '📌', 1);

-- Seed Default Banned Words
INSERT IGNORE INTO banned_words (word, severity, created_at) VALUES
('spam', 'high', NOW()),
('casino', 'high', NOW()),
('crypto_scam', 'high', NOW());
