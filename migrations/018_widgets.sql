CREATE TABLE IF NOT EXISTS family_widgets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    owner_id BIGINT UNSIGNED NOT NULL,
    kind VARCHAR(32) NOT NULL DEFAULT 'dinner-spinner',
    title VARCHAR(120) NOT NULL,
    visibility VARCHAR(16) NOT NULL DEFAULT 'private',
    version INT UNSIGNED NOT NULL DEFAULT 1,
    request_key CHAR(64) NOT NULL UNIQUE,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    deleted_at DATETIME(6) NULL,
    FOREIGN KEY (owner_id) REFERENCES users(id),
    INDEX widget_visibility (visibility, deleted_at, owner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dinner_meals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    widget_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(80) NOT NULL,
    position INT UNSIGNED NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    deleted_at DATETIME(6) NULL,
    FOREIGN KEY (widget_id) REFERENCES family_widgets(id),
    INDEX meal_widget (widget_id, deleted_at, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dinner_spins (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    widget_id BIGINT UNSIGNED NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    meal_id BIGINT UNSIGNED NOT NULL,
    meal_title VARCHAR(80) NOT NULL,
    base_version INT UNSIGNED NOT NULL,
    request_key CHAR(64) NOT NULL UNIQUE,
    occurred_at DATETIME(6) NOT NULL,
    FOREIGN KEY (widget_id) REFERENCES family_widgets(id),
    FOREIGN KEY (actor_id) REFERENCES users(id),
    FOREIGN KEY (meal_id) REFERENCES dinner_meals(id),
    INDEX spin_widget (widget_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hidden_family_widgets (
    widget_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (widget_id, user_id),
    FOREIGN KEY (widget_id) REFERENCES family_widgets(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
