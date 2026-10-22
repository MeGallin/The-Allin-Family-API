CREATE TABLE IF NOT EXISTS user_week_preferences (
    user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    town_json TEXT NULL,
    holiday_region VARCHAR(24) NOT NULL DEFAULT '',
    version INT UNSIGNED NOT NULL DEFAULT 1,
    updated_at DATETIME(6) NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
