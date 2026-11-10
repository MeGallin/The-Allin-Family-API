CREATE TABLE IF NOT EXISTS where_is_it_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    widget_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    location VARCHAR(160) NOT NULL,
    note TEXT NOT NULL,
    photo_key CHAR(64) NULL,
    photo_mime VARCHAR(32) NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    updated_at DATETIME(6) NOT NULL,
    deleted_at DATETIME(6) NULL,
    FOREIGN KEY (widget_id) REFERENCES family_widgets(id),
    INDEX item_collection (widget_id, deleted_at, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS where_is_it_receipts (
    actor_id BIGINT UNSIGNED NOT NULL,
    request_key CHAR(36) NOT NULL,
    signature CHAR(64) NOT NULL,
    item_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (actor_id, request_key),
    FOREIGN KEY (actor_id) REFERENCES users(id),
    FOREIGN KEY (item_id) REFERENCES where_is_it_items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS where_is_it_installation_receipts (
    actor_id BIGINT UNSIGNED NOT NULL,
    request_key CHAR(36) NOT NULL,
    signature CHAR(64) NOT NULL,
    widget_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (actor_id, request_key),
    FOREIGN KEY (actor_id) REFERENCES users(id),
    FOREIGN KEY (widget_id) REFERENCES family_widgets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
