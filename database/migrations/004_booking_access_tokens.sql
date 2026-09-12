-- Secure customer self-service access tokens.
-- Store only the SHA-256 hash; the raw token is shown once to the customer.
ALTER TABLE tour_passengers
  ADD COLUMN booking_access_token_hash CHAR(64) NULL AFTER registration_source,
  ADD COLUMN booking_access_token_created_at DATETIME NULL AFTER booking_access_token_hash,
  ADD COLUMN booking_access_token_revoked_at DATETIME NULL AFTER booking_access_token_created_at,
  ADD KEY idx_tp_booking_token_hash (booking_access_token_hash);
