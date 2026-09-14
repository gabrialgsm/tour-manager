-- Bring the fresh-install schema and migration path to the same runtime contract.
-- Safe to run after the existing migrations or after schema_saas.sql.

ALTER TABLE tour_passengers
  ADD COLUMN IF NOT EXISTS booking_access_token_hash CHAR(64) NULL AFTER registration_source,
  ADD COLUMN IF NOT EXISTS booking_access_token_created_at DATETIME NULL AFTER booking_access_token_hash,
  ADD COLUMN IF NOT EXISTS booking_access_token_revoked_at DATETIME NULL AFTER booking_access_token_created_at;

CREATE INDEX IF NOT EXISTS idx_tp_booking_token_hash
  ON tour_passengers(booking_access_token_hash);

CREATE TABLE IF NOT EXISTS ticket_instances (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 tour_passenger_id BIGINT UNSIGNED NOT NULL,
 organization_id BIGINT UNSIGNED NOT NULL,
 tour_id BIGINT UNSIGNED NOT NULL,
 ticket_number VARCHAR(40) NOT NULL,
 qr_token_hash CHAR(64) NOT NULL,
 qr_token_created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 qr_token_revoked_at DATETIME NULL,
 status ENUM('ISSUED','VOID','USED') NOT NULL DEFAULT 'ISSUED',
 template_key VARCHAR(80) NOT NULL DEFAULT 'classic',
 issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 voided_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(id),
 UNIQUE KEY uq_ticket_number(ticket_number),
 UNIQUE KEY uq_ticket_passenger(tour_passenger_id),
 UNIQUE KEY uq_ticket_qr_hash(qr_token_hash),
 KEY idx_ticket_org_tour(organization_id,tour_id),
 KEY idx_ticket_qr(qr_token_hash),
 KEY idx_ticket_template_key(template_key),
 CONSTRAINT fk_ticket_tp FOREIGN KEY(tour_passenger_id) REFERENCES tour_passengers(id) ON DELETE CASCADE,
 CONSTRAINT fk_ticket_org FOREIGN KEY(organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
 CONSTRAINT fk_ticket_tour FOREIGN KEY(tour_id) REFERENCES tours(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_intents (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 organization_id BIGINT UNSIGNED NOT NULL,
 tour_id BIGINT UNSIGNED NOT NULL,
 tour_passenger_id BIGINT UNSIGNED NOT NULL,
 amount DECIMAL(12,2) NOT NULL,
 currency CHAR(3) NOT NULL DEFAULT 'BDT',
 provider VARCHAR(80) NOT NULL DEFAULT 'MANUAL',
 provider_intent_id VARCHAR(190) NULL,
 status ENUM('PENDING','REQUIRES_ACTION','SUCCEEDED','FAILED','CANCELLED','EXPIRED') NOT NULL DEFAULT 'PENDING',
 reference VARCHAR(190) NULL,
 metadata_json LONGTEXT NULL,
 expires_at DATETIME NULL,
 succeeded_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(id),
 UNIQUE KEY uq_payment_intent_provider(provider,provider_intent_id),
 KEY idx_payment_intent_org_tour(organization_id,tour_id),
 KEY idx_payment_intent_passenger(tour_passenger_id,status),
 CONSTRAINT fk_pi_org FOREIGN KEY(organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
 CONSTRAINT fk_pi_tour FOREIGN KEY(tour_id) REFERENCES tours(id) ON DELETE CASCADE,
 CONSTRAINT fk_pi_tp FOREIGN KEY(tour_passenger_id) REFERENCES tour_passengers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
