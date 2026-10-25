CREATE TABLE focus_timer_installations (
 owner_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 version INT UNSIGNED NOT NULL DEFAULT 1,
 generation INT UNSIGNED NOT NULL DEFAULT 1,
 focus_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 25,
 short_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 5,
 long_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 15,
 current_session_id BIGINT UNSIGNED NULL,
 deleted_at DATETIME(3) NULL,
 FOREIGN KEY(owner_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE focus_timer_sessions (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 owner_id BIGINT UNSIGNED NOT NULL,
 phase VARCHAR(8) NOT NULL,
 label VARCHAR(80) NOT NULL DEFAULT '',
 duration_ms INT UNSIGNED NOT NULL,
 elapsed_ms INT UNSIGNED NOT NULL DEFAULT 0,
 state VARCHAR(20) NOT NULL,
 started_at DATETIME(3) NOT NULL,
 interval_started_at DATETIME(3) NULL,
 deadline_at DATETIME(3) NULL,
 ended_at DATETIME(3) NULL,
 INDEX owner_history(owner_id,ended_at),
 FOREIGN KEY(owner_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE focus_timer_receipts (
 owner_id BIGINT UNSIGNED NOT NULL,
 request_key CHAR(64) NOT NULL,
 request_hash CHAR(64) NOT NULL,
 response JSON NOT NULL,
 created_at DATETIME(3) NOT NULL,
 PRIMARY KEY(owner_id,request_key),
 FOREIGN KEY(owner_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
