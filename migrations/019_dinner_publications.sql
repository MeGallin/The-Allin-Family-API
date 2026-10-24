CREATE TABLE IF NOT EXISTS dinner_publications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    widget_id BIGINT UNSIGNED NOT NULL,
    spin_id BIGINT UNSIGNED NOT NULL UNIQUE,
    publisher_id BIGINT UNSIGNED NOT NULL,
    meal_title VARCHAR(80) NOT NULL,
    for_date DATE NOT NULL,
    request_key CHAR(64) NOT NULL UNIQUE,
    published_at DATETIME(6) NOT NULL,
    FOREIGN KEY (widget_id) REFERENCES family_widgets(id),
    FOREIGN KEY (spin_id) REFERENCES dinner_spins(id),
    FOREIGN KEY (publisher_id) REFERENCES users(id),
    INDEX publication_widget (widget_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dinner_publication_members (
    publication_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (publication_id, user_id),
    FOREIGN KEY (publication_id) REFERENCES dinner_publications(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dinner_responses (
    publication_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    choice VARCHAR(16) NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (publication_id, user_id),
    FOREIGN KEY (publication_id) REFERENCES dinner_publications(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
