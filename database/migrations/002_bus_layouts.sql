-- Extend coach layouts used by the SaaS bus designer.
ALTER TABLE buses MODIFY layout_type ENUM('1+2','2+1','2+2','2+3','LEGACY5') NOT NULL DEFAULT '2+2';

-- A physical seat can belong to only one passenger at a time.
ALTER TABLE passenger_seat_assignments ADD UNIQUE KEY uq_bus_seat(bus_id,seat_id);
