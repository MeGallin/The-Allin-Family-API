CREATE TABLE daily_word_installations (
 owner_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 version INT UNSIGNED NOT NULL DEFAULT 1,
 deleted_at DATETIME(6) NULL,
 FOREIGN KEY(owner_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE daily_word_challenges (
 challenge_date DATE NOT NULL PRIMARY KEY,
 answer CHAR(5) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 list_version VARCHAR(64) NOT NULL,
 expires_at DATETIME(6) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE daily_word_progress (
 owner_id BIGINT UNSIGNED NOT NULL,
 challenge_date DATE NOT NULL,
 guesses JSON NOT NULL,
 revision INT UNSIGNED NOT NULL DEFAULT 0,
 finished_at DATETIME(6) NULL,
 solved TINYINT UNSIGNED NOT NULL DEFAULT 0,
 shared_at DATETIME(6) NULL,
 PRIMARY KEY(owner_id,challenge_date),
 FOREIGN KEY(owner_id) REFERENCES users(id),
 FOREIGN KEY(challenge_date) REFERENCES daily_word_challenges(challenge_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE daily_word_receipts (
 owner_id BIGINT UNSIGNED NOT NULL,
 request_key CHAR(64) NOT NULL,
 request_hash CHAR(64) NOT NULL,
 response JSON NOT NULL,
 created_at DATETIME(6) NOT NULL,
 PRIMARY KEY(owner_id,request_key),
 FOREIGN KEY(owner_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE daily_word_history (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 owner_id BIGINT UNSIGNED NOT NULL,
 challenge_date DATE NULL,
 action VARCHAR(16) NOT NULL,
 occurred_at DATETIME(6) NOT NULL,
 FOREIGN KEY(owner_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
