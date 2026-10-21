CREATE TABLE IF NOT EXISTS polls (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 title VARCHAR(160) NOT NULL,
 detail VARCHAR(1000) NOT NULL DEFAULT '',
 closed TINYINT(1) NOT NULL DEFAULT 0,
 version INT UNSIGNED NOT NULL DEFAULT 1,
 created_by BIGINT UNSIGNED NOT NULL,
 updated_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME(6) NOT NULL,
 updated_at DATETIME(6) NOT NULL,
 deleted_at DATETIME(6) NULL,
 request_key CHAR(64) CHARACTER SET ascii NOT NULL UNIQUE,
 FOREIGN KEY (created_by) REFERENCES users(id),
 FOREIGN KEY (updated_by) REFERENCES users(id),
 INDEX polls_active (deleted_at,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS poll_options (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 poll_id BIGINT UNSIGNED NOT NULL,
 label VARCHAR(80) NOT NULL,
 position TINYINT UNSIGNED NOT NULL,
 FOREIGN KEY (poll_id) REFERENCES polls(id),
 UNIQUE KEY option_poll (id,poll_id),
 UNIQUE KEY option_position (poll_id,position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS poll_votes (
 poll_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 option_id BIGINT UNSIGNED NULL,
 version INT UNSIGNED NOT NULL DEFAULT 1,
 updated_at DATETIME(6) NOT NULL,
 PRIMARY KEY (poll_id,user_id),
 FOREIGN KEY (poll_id) REFERENCES polls(id),
 FOREIGN KEY (user_id) REFERENCES users(id),
 FOREIGN KEY (option_id,poll_id) REFERENCES poll_options(id,poll_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
