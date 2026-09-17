-- Performance indexes for high-frequency tour, booking, payment and allocation queries.
-- Safe to run once through bin/migrate.php.

CREATE INDEX idx_tp_tour_status_profile
  ON tour_passengers(tour_id,status,passenger_profile_id);

CREATE INDEX idx_tp_profile_tour
  ON tour_passengers(passenger_profile_id,tour_id);

CREATE INDEX idx_payments_tour_passenger
  ON payments(tour_id,tour_passenger_id,id);

CREATE INDEX idx_tpf_tour_passenger_status
  ON tour_passenger_features(tour_id,tour_passenger_id,status);

CREATE INDEX idx_tpf_passenger_status
  ON tour_passenger_features(tour_passenger_id,status);

CREATE INDEX idx_ra_room_passenger
  ON room_assignments(room_id,tour_passenger_id);

CREATE INDEX idx_ra_passenger_room
  ON room_assignments(tour_passenger_id,room_id);

CREATE INDEX idx_psa_passenger_bus
  ON passenger_seat_assignments(tour_passenger_id,bus_id,seat_id);

CREATE INDEX idx_payment_intents_tour_status
  ON payment_intents(tour_id,status,id);

CREATE INDEX idx_ticket_tour_passenger_status
  ON ticket_instances(tour_id,tour_passenger_id,status);

CREATE INDEX idx_tfo_feature_status_sort
  ON tour_feature_options(tour_feature_id,status,sort_order,id);

CREATE INDEX idx_tp_profile_org
  ON passenger_profiles(organization_id,id);
