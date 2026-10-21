CREATE TABLE IF NOT EXISTS calendar_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(80) NOT NULL,
    starts_at DATETIME(6) NOT NULL,
    time_zone VARCHAR(80) CHARACTER SET ascii NOT NULL,
    detail VARCHAR(300) NOT NULL DEFAULT '',
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    deleted_at DATETIME(6) NULL,
    request_key CHAR(64) CHARACTER SET ascii NOT NULL UNIQUE,
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (updated_by) REFERENCES users(id),
    INDEX calendar_active (deleted_at, starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
