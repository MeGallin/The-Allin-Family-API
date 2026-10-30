-- Serialises recap-producing audit inserts with snapshot boundaries. No second event log.
CREATE TABLE home_recap_clock (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY
) ENGINE=InnoDB;
INSERT INTO home_recap_clock (id) VALUES (1);

CREATE TABLE home_recap_reviews (
    user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    reviewed_id BIGINT UNSIGNED NOT NULL,
    offered_id BIGINT UNSIGNED NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;
