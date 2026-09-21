-- Normalize the legacy payments foreign key.
-- payments.tour_passenger_id stores a tour_passengers ID.
-- Some production databases retained a legacy FK pointing at passengers(id).

SET @fk_exists := (
    SELECT COUNT(*)
    FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND CONSTRAINT_NAME = 'fk_payments_passenger'
      AND TABLE_NAME = 'payments'
);

SET @sql := CASE
    WHEN @fk_exists > 0
    THEN 'ALTER TABLE payments DROP FOREIGN KEY fk_payments_passenger'
    ELSE 'SELECT 1'
END;
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @target_fk_exists := (
    SELECT COUNT(*)
    FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND CONSTRAINT_NAME = 'fk_payments_tour_passenger'
      AND TABLE_NAME = 'payments'
);

SET @sql := CASE
    WHEN @target_fk_exists = 0
    THEN 'ALTER TABLE payments ADD CONSTRAINT fk_payments_tour_passenger FOREIGN KEY (tour_passenger_id) REFERENCES tour_passengers(id) ON DELETE CASCADE'
    ELSE 'SELECT 1'
END;
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
