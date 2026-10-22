ALTER TABLE polls ADD COLUMN closes_at DATETIME(6) NULL AFTER closed;
-- Give existing open polls a four-hour transition window; retain closed results.
UPDATE polls SET closes_at = CASE WHEN closed = 0 THEN DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 4 HOUR)
 ELSE DATE_ADD(created_at, INTERVAL 4 HOUR) END;
ALTER TABLE polls MODIFY closes_at DATETIME(6) NOT NULL;
