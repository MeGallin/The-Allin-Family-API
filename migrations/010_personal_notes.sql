CREATE TABLE IF NOT EXISTS personal_notes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    owner_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(120) NOT NULL,
    header VARCHAR(500) NOT NULL DEFAULT '',
    body MEDIUMTEXT NOT NULL,
    footer VARCHAR(500) NOT NULL DEFAULT '',
    note_date DATE NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    deleted_at DATETIME(6) NULL,
    request_key CHAR(64) CHARACTER SET ascii NOT NULL UNIQUE,
    FOREIGN KEY (owner_id) REFERENCES users(id),
    INDEX notes_owner (owner_id, deleted_at, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS personal_note_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    note_id BIGINT UNSIGNED NOT NULL,
    owner_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(20) CHARACTER SET ascii NOT NULL,
    occurred_at DATETIME(6) NOT NULL,
    before_data JSON NULL,
    after_data JSON NULL,
    FOREIGN KEY (note_id) REFERENCES personal_notes(id),
    FOREIGN KEY (owner_id) REFERENCES users(id),
    INDEX notes_history_owner (owner_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
