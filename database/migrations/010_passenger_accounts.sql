-- Passenger self-service authentication foundation
-- MariaDB 10.6+ / 11.x
CREATE TABLE IF NOT EXISTS passenger_accounts (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 passenger_profile_id BIGINT UNSIGNED NOT NULL,
 organization_id BIGINT UNSIGNED NOT NULL,
 email VARCHAR(190) NULL,
 phone VARCHAR(50) NULL,
 password_hash VARCHAR(255) NULL,
 auth_provider ENUM('PASSWORD','GOOGLE','FACEBOOK') NOT NULL DEFAULT 'PASSWORD',
 provider_subject VARCHAR(255) NULL,
 status ENUM('ACTIVE','INACTIVE','SUSPENDED') NOT NULL DEFAULT 'ACTIVE',
 email_verified_at DATETIME NULL,
 last_login_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(id),
 UNIQUE KEY uq_pa_profile(passenger_profile_id),
 UNIQUE KEY uq_pa_provider(auth_provider,provider_subject),
 KEY idx_pa_org_email(organization_id,email),
 KEY idx_pa_org_phone(organization_id,phone),
 CONSTRAINT fk_pa_profile FOREIGN KEY(passenger_profile_id) REFERENCES passenger_profiles(id) ON DELETE CASCADE,
 CONSTRAINT fk_pa_org FOREIGN KEY(organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
