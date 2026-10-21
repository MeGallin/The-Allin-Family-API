CREATE TABLE IF NOT EXISTS platform_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tracking_key CHAR(64) CHARACTER SET ascii NOT NULL UNIQUE,
    user_id BIGINT UNSIGNED NOT NULL,
    started_at DATETIME NOT NULL,
    login_at DATETIME NULL,
    last_seen_at DATETIME NOT NULL,
    last_page VARCHAR(32) NOT NULL DEFAULT '/',
    active_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    was_active TINYINT(1) NOT NULL DEFAULT 0,
    ended_at DATETIME NULL,
    end_reason VARCHAR(16) NULL,
    FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX usage_user_started (user_id, started_at),
    INDEX usage_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
