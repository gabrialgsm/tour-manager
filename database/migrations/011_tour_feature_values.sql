-- Extensible per-tour feature catalog and per-passenger selections.
-- Safe to apply after the existing tour_features foundation table.

CREATE TABLE IF NOT EXISTS tour_feature_options (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 tour_feature_id BIGINT UNSIGNED NOT NULL,
 option_key VARCHAR(120) NOT NULL,
 option_label VARCHAR(180) NOT NULL,
 option_value VARCHAR(255) DEFAULT NULL,
 price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
 sort_order INT UNSIGNED NOT NULL DEFAULT 0,
 status ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(id),
 UNIQUE KEY uq_tfo_key(tour_feature_id,option_key),
 KEY idx_tfo_feature_sort(tour_feature_id,sort_order),
 CONSTRAINT fk_tfo_feature FOREIGN KEY(tour_feature_id) REFERENCES tour_features(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tour_passenger_features (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 tour_id BIGINT UNSIGNED NOT NULL,
 tour_passenger_id BIGINT UNSIGNED NOT NULL,
 tour_feature_id BIGINT UNSIGNED NOT NULL,
 option_id BIGINT UNSIGNED DEFAULT NULL,
 value_text VARCHAR(500) DEFAULT NULL,
 quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00,
 unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
 total_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
 status ENUM('SELECTED','CANCELLED') NOT NULL DEFAULT 'SELECTED',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(id),
 UNIQUE KEY uq_tpf_passenger_feature(tour_passenger_id,tour_feature_id),
 KEY idx_tpf_tour_passenger(tour_id,tour_passenger_id),
 KEY idx_tpf_feature(tour_feature_id),
 CONSTRAINT fk_tpf_tour FOREIGN KEY(tour_id) REFERENCES tours(id) ON DELETE CASCADE,
 CONSTRAINT fk_tpf_passenger FOREIGN KEY(tour_passenger_id) REFERENCES tour_passengers(id) ON DELETE CASCADE,
 CONSTRAINT fk_tpf_feature FOREIGN KEY(tour_feature_id) REFERENCES tour_features(id) ON DELETE CASCADE,
 CONSTRAINT fk_tpf_option FOREIGN KEY(option_id) REFERENCES tour_feature_options(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
