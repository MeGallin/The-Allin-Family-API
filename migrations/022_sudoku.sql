CREATE TABLE IF NOT EXISTS user_sudoku (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    owner_id BIGINT UNSIGNED NOT NULL UNIQUE,
    schema_version TINYINT UNSIGNED NOT NULL DEFAULT 1,
    difficulty VARCHAR(8) NOT NULL,
    givens CHAR(81) NOT NULL,
    entries CHAR(81) NOT NULL,
    pencil_marks JSON NOT NULL,
    paused TINYINT UNSIGNED NOT NULL DEFAULT 0,
    completed_at DATETIME(6) NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    last_request_key CHAR(64) NOT NULL,
    last_request_hash CHAR(64) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    deleted_at DATETIME(6) NULL,
    FOREIGN KEY (owner_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
