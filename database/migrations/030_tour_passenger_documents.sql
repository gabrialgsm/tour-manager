ALTER TABLE tour_passengers
  ADD COLUMN passport_number VARCHAR(80) NULL AFTER departure_from,
  ADD COLUMN visa_number VARCHAR(80) NULL AFTER passport_number,
  ADD COLUMN passport_copy VARCHAR(500) NULL AFTER visa_number,
  ADD COLUMN visa_copy VARCHAR(500) NULL AFTER passport_copy;
