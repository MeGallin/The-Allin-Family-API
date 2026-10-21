ALTER TABLE platform_sessions ADD COLUMN last_signal_at DATETIME NULL AFTER last_seen_at;
UPDATE platform_sessions SET last_signal_at = last_seen_at;
ALTER TABLE platform_sessions MODIFY last_signal_at DATETIME NOT NULL;
