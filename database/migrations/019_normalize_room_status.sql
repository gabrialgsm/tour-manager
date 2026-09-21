-- Normalize legacy room status values to the canonical GoTM contract.
-- Legacy installations may use ACTIVE/INACTIVE, while current code uses
-- AVAILABLE/BLOCKED. Preserve the existing value in a temporary text column
-- before changing the ENUM so MySQL never silently truncates it.

ALTER TABLE rooms
  ADD COLUMN IF NOT EXISTS gotm_status_legacy VARCHAR(30) NULL AFTER status;

UPDATE rooms
SET gotm_status_legacy = CAST(status AS CHAR(30));

ALTER TABLE rooms
  MODIFY COLUMN status ENUM('AVAILABLE','BLOCKED') NOT NULL DEFAULT 'AVAILABLE';

UPDATE rooms
SET status = CASE
  WHEN gotm_status_legacy IN ('ACTIVE','AVAILABLE') THEN 'AVAILABLE'
  WHEN gotm_status_legacy IN ('INACTIVE','BLOCKED') THEN 'BLOCKED'
  ELSE 'AVAILABLE'
END;

ALTER TABLE rooms
  DROP COLUMN gotm_status_legacy;
