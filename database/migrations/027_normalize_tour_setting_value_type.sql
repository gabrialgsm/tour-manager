-- Normalize legacy tour_settings.value_type values before the current application writes.
ALTER TABLE tour_settings
  MODIFY COLUMN value_type ENUM('STRING','NUMBER','BOOLEAN','JSON','TEXT') NOT NULL DEFAULT 'STRING';

UPDATE tour_settings
SET value_type='STRING'
WHERE value_type='TEXT';

ALTER TABLE tour_settings
  MODIFY COLUMN value_type ENUM('STRING','NUMBER','BOOLEAN','JSON') NOT NULL DEFAULT 'STRING';
