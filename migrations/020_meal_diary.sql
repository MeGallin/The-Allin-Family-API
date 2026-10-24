ALTER TABLE dinner_publications
    ADD COLUMN meal_type VARCHAR(16) NOT NULL DEFAULT 'dinner' AFTER for_date,
    ADD COLUMN eaten_at DATETIME(6) NULL,
    ADD COLUMN eaten_by BIGINT UNSIGNED NULL,
    ADD CONSTRAINT publication_eaten_user FOREIGN KEY (eaten_by) REFERENCES users(id),
    ADD INDEX publication_slot (widget_id, for_date, meal_type, id);
