-- Passenger self-service authentication foundation.
-- Accounts are organization-scoped and linked to reusable passenger profiles.
-- This is the single canonical migration for passenger authentication.
CREATE TABLE IF NOT EXISTS passenger_accounts (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 organization_id BIGINT UNSIGNED NOT NULL,
 passenger_profile_id BIGINT UNSIGNED NOT NULL,
 email VARCHAR(190) NOT NULL,
 password_hash VARCHAR(255) NULL,
 status ENUM('ACTIVE','PENDING','SUSPENDED') NOT NULL DEFAULT 'ACTIVE',
 email_verified_at DATETIME NULL,
 last_login_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(id),
 UNIQUE KEY uq_pa_org_profile(organization_id,passenger_profile_id),
 UNIQUE KEY uq_pa_org_email(organization_id,email),
 KEY idx_pa_profile(passenger_profile_id),
 CONSTRAINT fk_pa_org FOREIGN KEY(organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
 CONSTRAINT fk_pa_profile FOREIGN KEY(passenger_profile_id) REFERENCES passenger_profiles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS passenger_auth_identities (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 passenger_account_id BIGINT UNSIGNED NOT NULL,
 provider VARCHAR(40) NOT NULL,
 provider_subject VARCHAR(190) NOT NULL,
 provider_email VARCHAR(190) NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 last_login_at DATETIME NULL,
 PRIMARY KEY(id),
 UNIQUE KEY uq_pai_provider_subject(provider,provider_subject),
 UNIQUE KEY uq_pai_account_provider(passenger_account_id,provider),
 KEY idx_pai_account(passenger_account_id),
 CONSTRAINT fk_pai_account FOREIGN KEY(passenger_account_id) REFERENCES passenger_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS passenger_sessions (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 passenger_account_id BIGINT UNSIGNED NOT NULL,
 session_hash CHAR(64) NOT NULL,
 expires_at DATETIME NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 revoked_at DATETIME NULL,
 ip_address VARCHAR(45) NULL,
 user_agent VARCHAR(500) NULL,
 PRIMARY KEY(id),
 UNIQUE KEY uq_ps_session_hash(session_hash),
 KEY idx_ps_account(passenger_account_id,expires_at),
 CONSTRAINT fk_ps_account FOREIGN KEY(passenger_account_id) REFERENCES passenger_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS passenger_password_resets (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 passenger_account_id BIGINT UNSIGNED NOT NULL,
 token_hash CHAR(64) NOT NULL,
 expires_at DATETIME NOT NULL,
 used_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(id),
 UNIQUE KEY uq_ppr_token_hash(token_hash),
 KEY idx_ppr_account(passenger_account_id,expires_at),
 CONSTRAINT fk_ppr_account FOREIGN KEY(passenger_account_id) REFERENCES passenger_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
