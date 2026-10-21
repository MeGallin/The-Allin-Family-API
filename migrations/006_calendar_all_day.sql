ALTER TABLE calendar_events ADD COLUMN all_day TINYINT(1) NOT NULL DEFAULT 0 AFTER starts_at;
