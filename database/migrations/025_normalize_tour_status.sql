-- Normalize legacy tour status enums to the current SaaS contract.
-- Some production installations used ACTIVE/INACTIVE while the SaaS UI uses
-- DRAFT/ACTIVE/CLOSED/ARCHIVED. Add the legacy value temporarily, map it,
-- then enforce the canonical enum.

ALTER TABLE tours
  MODIFY COLUMN status ENUM('DRAFT','ACTIVE','CLOSED','ARCHIVED','INACTIVE')
  NOT NULL DEFAULT 'DRAFT';

UPDATE tours
SET status = 'CLOSED'
WHERE status = 'INACTIVE';

ALTER TABLE tours
  MODIFY COLUMN status ENUM('DRAFT','ACTIVE','CLOSED','ARCHIVED')
  NOT NULL DEFAULT 'DRAFT';
