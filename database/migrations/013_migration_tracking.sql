-- Migration runner metadata.
-- This migration is intentionally idempotent and only creates the tracking table.
CREATE TABLE IF NOT EXISTS schema_migrations (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 version VARCHAR(120) NOT NULL,
 checksum CHAR(64) NOT NULL,
 applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 execution_ms INT UNSIGNED NULL,
 PRIMARY KEY(id),
 UNIQUE KEY uq_schema_migrations_version(version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
