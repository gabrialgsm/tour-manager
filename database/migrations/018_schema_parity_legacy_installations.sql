-- Production schema parity for legacy GoTM installations.
-- Safe for the current production database: buses/seats are empty, while
-- existing payment/room data is preserved and mapped into the current contract.

-- Rooms: some production installations carry organization_id as a required
-- tenant column. Keep it available in the canonical contract and backfill it
-- from the authoritative tour relationship.
ALTER TABLE rooms
  ADD COLUMN IF NOT EXISTS organization_id BIGINT UNSIGNED NULL AFTER id;

UPDATE rooms r
JOIN tours t ON t.id = r.tour_id
SET r.organization_id = t.organization_id
WHERE r.organization_id IS NULL;

CREATE INDEX IF NOT EXISTS idx_rooms_org_tour
  ON rooms(organization_id,tour_id);

-- Buses: older production schema used organization_id + registration_no and
-- did not have the current tour_id/bus_number fields.
ALTER TABLE buses
  ADD COLUMN IF NOT EXISTS tour_id BIGINT UNSIGNED NULL AFTER id,
  ADD COLUMN IF NOT EXISTS bus_number VARCHAR(80) NULL AFTER name,
  ADD COLUMN IF NOT EXISTS front_single_label VARCHAR(30) NULL AFTER front_single_count;

UPDATE buses b
SET b.bus_number = NULLIF(TRIM(b.registration_no),'')
WHERE b.bus_number IS NULL
  AND EXISTS (
    SELECT 1
    FROM information_schema.COLUMNS c
    WHERE c.TABLE_SCHEMA = DATABASE()
      AND c.TABLE_NAME = 'buses'
      AND c.COLUMN_NAME = 'registration_no'
  );

UPDATE buses b
JOIN (
  SELECT organization_id, MIN(id) AS tour_id
  FROM tours
  GROUP BY organization_id
  HAVING COUNT(*) = 1
) t ON t.organization_id = b.organization_id
SET b.tour_id = t.tour_id
WHERE b.tour_id IS NULL;

CREATE INDEX IF NOT EXISTS idx_bus_tour_compat
  ON buses(tour_id);

-- Seats: older production schema used seat_no and ACTIVE/INACTIVE status.
-- Convert those values into the current seat_code / AVAILABLE/BLOCKED contract.
ALTER TABLE seats
  ADD COLUMN IF NOT EXISTS seat_code VARCHAR(20) NULL AFTER seat_no;

UPDATE seats
SET seat_code = NULLIF(TRIM(CAST(seat_no AS CHAR(20))),'')
WHERE seat_code IS NULL;

UPDATE seats
SET status = CASE
  WHEN status = 'ACTIVE' THEN 'AVAILABLE'
  WHEN status = 'INACTIVE' THEN 'BLOCKED'
  ELSE status
END;

ALTER TABLE seats
  MODIFY COLUMN seat_code VARCHAR(20) NOT NULL,
  MODIFY COLUMN status ENUM('AVAILABLE','BLOCKED') NOT NULL DEFAULT 'AVAILABLE';

CREATE UNIQUE INDEX IF NOT EXISTS uq_bus_seat_code
  ON seats(bus_id,seat_code);

-- Payments: older production schema already has payment_reference,
-- transaction_reference and organization_id. Fresh/canonical installations
-- may still have the earlier reference-only shape, so add the new fields and
-- preserve the old reference value as transaction_reference.
ALTER TABLE payments
  ADD COLUMN IF NOT EXISTS organization_id BIGINT UNSIGNED NULL AFTER id,
  ADD COLUMN IF NOT EXISTS payment_reference VARCHAR(50) NULL AFTER tour_passenger_id,
  ADD COLUMN IF NOT EXISTS transaction_reference VARCHAR(150) NULL AFTER payment_reference;

UPDATE payments p
JOIN tours t ON t.id = p.tour_id
SET p.organization_id = t.organization_id
WHERE p.organization_id IS NULL;

UPDATE payments
SET payment_reference = CONCAT('LEGACY-',id)
WHERE payment_reference IS NULL OR TRIM(payment_reference) = '';

UPDATE payments
SET transaction_reference = NULLIF(TRIM(reference),'')
WHERE transaction_reference IS NULL
  AND EXISTS (
    SELECT 1
    FROM information_schema.COLUMNS c
    WHERE c.TABLE_SCHEMA = DATABASE()
      AND c.TABLE_NAME = 'payments'
      AND c.COLUMN_NAME = 'reference'
  );

ALTER TABLE payments
  MODIFY COLUMN payment_reference VARCHAR(50) NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uq_payments_payment_reference
  ON payments(payment_reference);

CREATE INDEX IF NOT EXISTS idx_payments_org_tour
  ON payments(organization_id,tour_id);
