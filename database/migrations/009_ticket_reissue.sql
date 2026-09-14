-- Allow ticket history so a passenger can have multiple ticket instances over time.
-- The application keeps only one ISSUED ticket active; older tickets become VOID.
ALTER TABLE ticket_instances
  DROP INDEX uq_ticket_passenger,
  ADD KEY idx_ticket_passenger(tour_passenger_id,status);
