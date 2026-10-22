ALTER TABLE memories
    ADD COLUMN photo_width SMALLINT UNSIGNED NULL,
    ADD COLUMN photo_height SMALLINT UNSIGNED NULL;

CREATE INDEX activity_feature_recent ON activity_events (entity_type, id);
