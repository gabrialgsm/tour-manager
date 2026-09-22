-- Passenger information enhancements for tour operations.
-- Reusable profile fields plus tour-specific departure point.

SET @sql := IF(
  EXISTS(
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='passenger_profiles' AND COLUMN_NAME='blood_group'
  ),
  'SELECT 1',
  "ALTER TABLE passenger_profiles ADD COLUMN blood_group VARCHAR(10) NULL AFTER gender"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='passenger_profiles' AND COLUMN_NAME='emergency_contact_name'
  ),
  'SELECT 1',
  "ALTER TABLE passenger_profiles ADD COLUMN emergency_contact_name VARCHAR(180) NULL AFTER blood_group"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='passenger_profiles' AND COLUMN_NAME='emergency_contact_phone'
  ),
  'SELECT 1',
  "ALTER TABLE passenger_profiles ADD COLUMN emergency_contact_phone VARCHAR(50) NULL AFTER emergency_contact_name"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  EXISTS(
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tour_passengers' AND COLUMN_NAME='departure_from'
  ),
  'SELECT 1',
  "ALTER TABLE tour_passengers ADD COLUMN departure_from VARCHAR(180) NULL AFTER discount"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
