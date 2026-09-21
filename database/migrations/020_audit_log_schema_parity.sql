-- Normalize legacy audit metadata column to the canonical details column.
SET @audit_details_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'audit_log'
      AND COLUMN_NAME = 'details'
);
SET @audit_metadata_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'audit_log'
      AND COLUMN_NAME = 'metadata_json'
);

SET @sql := CASE
    WHEN @audit_details_exists = 0 AND @audit_metadata_exists > 0
    THEN 'ALTER TABLE audit_log CHANGE COLUMN metadata_json details LONGTEXT NULL'
    ELSE 'SELECT 1'
END;
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := CASE
    WHEN @audit_details_exists = 0 AND @audit_metadata_exists = 0
    THEN 'ALTER TABLE audit_log ADD COLUMN details LONGTEXT NULL AFTER entity_id'
    ELSE 'SELECT 1'
END;
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
