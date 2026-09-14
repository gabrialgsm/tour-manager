-- Gateway-ready customer payment intent ledger.
-- Canonical schema used by public_checkout.php and future gateway adapters.
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
