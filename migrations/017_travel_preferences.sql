CREATE TABLE IF NOT EXISTS user_travel_preferences (
    user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    lines_json TEXT NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    updated_at DATETIME(6) NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
