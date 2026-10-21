CREATE TABLE IF NOT EXISTS shopping_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(100) NOT NULL,
    checked TINYINT(1) NOT NULL DEFAULT 0,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    deleted_at DATETIME(6) NULL,
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (updated_by) REFERENCES users(id),
    INDEX shopping_active (deleted_at, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS activity_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(40) CHARACTER SET ascii NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(40) CHARACTER SET ascii NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    actor_name VARCHAR(120) NOT NULL,
    occurred_at DATETIME(6) NOT NULL,
    before_data JSON NULL,
    after_data JSON NULL,
    FOREIGN KEY (actor_id) REFERENCES users(id),
    INDEX activity_entity (entity_type, entity_id, id),
    INDEX activity_actor_time (actor_id, occurred_at),
    INDEX activity_time (occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
