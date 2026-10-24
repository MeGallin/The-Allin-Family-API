ALTER TABLE dinner_spins ADD COLUMN meal_version INT UNSIGNED NOT NULL DEFAULT 0 AFTER meal_title;
UPDATE dinner_spins s JOIN dinner_meals m ON m.id=s.meal_id AND m.widget_id=s.widget_id
SET s.meal_version=m.version
WHERE m.deleted_at IS NULL AND BINARY m.title=BINARY s.meal_title;
