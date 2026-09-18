-- Performance indexes for high-frequency tour, booking, payment and allocation queries.
-- Also normalizes legacy column names found in earlier production schemas.
-- Safe to run once through bin/migrate.php.

-- Legacy compatibility:
-- Older installations used passenger_id in payments/room_assignments.
-- The canonical application contract uses tour_passenger_id.
SET @gotm_rename_payments = (
  SELECT CASE
    WHEN EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'payments'
        AND COLUMN_NAME = 'passenger_id'
    )
    AND NOT EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'payments'
        AND COLUMN_NAME = 'tour_passenger_id'
    )
    THEN 'ALTER TABLE payments CHANGE COLUMN passenger_id tour_passenger_id BIGINT UNSIGNED NOT NULL'
    ELSE 'SELECT 1'
  END
);
PREPARE gotm_stmt FROM @gotm_rename_payments;
EXECUTE gotm_stmt;
DEALLOCATE PREPARE gotm_stmt;

SET @gotm_rename_rooms = (
  SELECT CASE
    WHEN EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'room_assignments'
        AND COLUMN_NAME = 'passenger_id'
    )
    AND NOT EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'room_assignments'
        AND COLUMN_NAME = 'tour_passenger_id'
    )
    THEN 'ALTER TABLE room_assignments CHANGE COLUMN passenger_id tour_passenger_id BIGINT UNSIGNED NOT NULL'
    ELSE 'SELECT 1'
  END
);
PREPARE gotm_stmt FROM @gotm_rename_rooms;
EXECUTE gotm_stmt;
DEALLOCATE PREPARE gotm_stmt;

CREATE INDEX IF NOT EXISTS idx_tp_tour_status_profile
  ON tour_passengers(tour_id,status,passenger_profile_id);

CREATE INDEX IF NOT EXISTS idx_tp_profile_tour
  ON tour_passengers(passenger_profile_id,tour_id);

CREATE INDEX IF NOT EXISTS idx_payments_tour_passenger
  ON payments(tour_id,tour_passenger_id,id);

CREATE INDEX IF NOT EXISTS idx_tpf_tour_passenger_status
  ON tour_passenger_features(tour_id,tour_passenger_id,status);

CREATE INDEX IF NOT EXISTS idx_tpf_passenger_status
  ON tour_passenger_features(tour_passenger_id,status);

CREATE INDEX IF NOT EXISTS idx_ra_room_passenger
  ON room_assignments(room_id,tour_passenger_id);

CREATE INDEX IF NOT EXISTS idx_ra_passenger_room
  ON room_assignments(tour_passenger_id,room_id);

CREATE INDEX IF NOT EXISTS idx_psa_passenger_bus
  ON passenger_seat_assignments(tour_passenger_id,bus_id,seat_id);

CREATE INDEX IF NOT EXISTS idx_payment_intents_tour_status
  ON payment_intents(tour_id,status,id);

CREATE INDEX IF NOT EXISTS idx_ticket_tour_passenger_status
  ON ticket_instances(tour_id,tour_passenger_id,status);

CREATE INDEX IF NOT EXISTS idx_tfo_feature_status_sort
  ON tour_feature_options(tour_feature_id,status,sort_order,id);

CREATE INDEX IF NOT EXISTS idx_tp_profile_org
  ON passenger_profiles(organization_id,id);
