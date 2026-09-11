-- Extend bus layouts for premium 1+2 coaches.
-- Run after the SaaS foundation schema.
ALTER TABLE buses MODIFY layout_type ENUM('1+2','2+2','2+3','LEGACY5') NOT NULL DEFAULT '2+2';

-- Prevent two passengers from occupying the same physical seat.
ALTER TABLE passenger_seat_assignments ADD UNIQUE KEY uq_bus_seat(bus_id,seat_id);
