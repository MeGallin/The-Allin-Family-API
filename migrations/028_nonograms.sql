-- Additive owner-private state; existing tables and rows are unchanged.
CREATE TABLE user_nonograms (
    owner_id BIGINT UNSIGNED PRIMARY KEY,
    puzzle_id VARCHAR(40) NOT NULL,
    cells VARCHAR(100) NOT NULL,
    assisted TINYINT UNSIGNED NOT NULL DEFAULT 0,
    completed_at DATETIME(6) NULL,
    played JSON NOT NULL,
    replay TINYINT UNSIGNED NOT NULL DEFAULT 0,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    deleted_at DATETIME(6) NULL,
    updated_at DATETIME(6) NOT NULL,
    FOREIGN KEY (owner_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE nonogram_receipts (
    owner_id BIGINT UNSIGNED NOT NULL,
    request_key CHAR(64) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    response JSON NOT NULL,
    PRIMARY KEY (owner_id, request_key),
    FOREIGN KEY (owner_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

