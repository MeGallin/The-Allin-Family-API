CREATE TABLE household_job_order (
 scope_key VARCHAR(40) NOT NULL PRIMARY KEY,
 revision INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO household_job_order(scope_key) VALUES ('family');
CREATE TABLE household_jobs (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 owner_id BIGINT UNSIGNED NOT NULL,
 scope VARCHAR(8) NOT NULL,
 title VARCHAR(120) NOT NULL,
 note VARCHAR(2000) NOT NULL DEFAULT '',
 area VARCHAR(80) NOT NULL DEFAULT '',
 target_date DATE NULL,
 repeat_kind VARCHAR(8) NOT NULL DEFAULT 'none',
 assignee_id BIGINT UNSIGNED NULL,
 done TINYINT(1) NOT NULL DEFAULT 0,
 position INT UNSIGNED NOT NULL DEFAULT 0,
 version INT UNSIGNED NOT NULL DEFAULT 1,
 created_at DATETIME(6) NOT NULL,
 updated_at DATETIME(6) NOT NULL,
 deleted_at DATETIME(6) NULL,
 INDEX scope_order(scope,owner_id,deleted_at,position),
 FOREIGN KEY(owner_id) REFERENCES users(id),
 FOREIGN KEY(assignee_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE household_job_history (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 job_id BIGINT UNSIGNED NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 action VARCHAR(16) NOT NULL,
 target_date DATE NULL,
 occurred_at DATETIME(6) NOT NULL,
 INDEX job_history(job_id,id),
 FOREIGN KEY(job_id) REFERENCES household_jobs(id),
 FOREIGN KEY(actor_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE household_job_receipts (
 actor_id BIGINT UNSIGNED NOT NULL,
 request_key CHAR(64) NOT NULL,
 request_hash CHAR(64) NOT NULL,
 response JSON NOT NULL,
 PRIMARY KEY(actor_id,request_key),
 FOREIGN KEY(actor_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
