-- Gateway-neutral payment intent records for customer checkout.
-- A gateway adapter can later update provider fields/status after verification.
CREATE TABLE IF NOT EXISTS payment_intents (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 tour_id BIGINT UNSIGNED NOT NULL,
 tour_passenger_id BIGINT UNSIGNED NOT NULL,
 amount DECIMAL(12,2) NOT NULL,
 currency CHAR(3) NOT NULL DEFAULT 'BDT',
 method VARCHAR(50) NOT NULL,
 status ENUM('PENDING','PROCESSING','PAID','FAILED','CANCELLED','EXPIRED') NOT NULL DEFAULT 'PENDING',
 provider VARCHAR(80) NULL,
 provider_reference VARCHAR(190) NULL,
 client_reference VARCHAR(120) NULL,
 metadata_json LONGTEXT NULL,
 expires_at DATETIME NULL,
 paid_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(id),
 UNIQUE KEY uq_payment_intent_client_ref(client_reference),
 KEY idx_payment_intent_tour(tour_id,status),
 KEY idx_payment_intent_passenger(tour_passenger_id,status),
 KEY idx_payment_intent_provider(provider,provider_reference),
 CONSTRAINT fk_payment_intent_tour FOREIGN KEY(tour_id) REFERENCES tours(id) ON DELETE CASCADE,
 CONSTRAINT fk_payment_intent_tp FOREIGN KEY(tour_passenger_id) REFERENCES tour_passengers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
