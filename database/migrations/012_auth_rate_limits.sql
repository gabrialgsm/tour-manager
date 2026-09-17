-- Passenger authentication brute-force protection.
-- Tracks short-lived attempt counters by normalized email and source IP.
CREATE TABLE IF NOT EXISTS passenger_auth_rate_limits (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 rate_key CHAR(64) NOT NULL,
 attempts INT UNSIGNED NOT NULL DEFAULT 0,
 window_started_at DATETIME NOT NULL,
 blocked_until DATETIME NULL,
 last_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(id),
 UNIQUE KEY uq_parl_key(rate_key),
 KEY idx_parl_blocked(blocked_until),
 KEY idx_parl_window(window_started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
