-- GMJS Tour Manager — SaaS Foundation Migration 001
-- MariaDB 10.6+/11.x
-- Additive/staged migration. Does NOT drop or rewrite existing business tables.
-- Run this after taking a database backup.
--
-- Phase 1 goals:
--   * introduce users + organizations + memberships
--   * give tours an organization boundary
--   * introduce tour-scoped memberships
--   * prepare reusable passenger profiles and public-tour configuration
--   * prepare feature flags, custom fields, ticket templates and subscriptions
--
-- IMPORTANT:
-- Existing PHP code continues to use admins and the existing tour_id/session model.
-- Do not remove admins or change foreign keys of existing business tables yet.

SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';
SET NAMES utf8mb4;
START TRANSACTION;

-- -------------------------------------------------------------------------
-- 1. Application users
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `username` varchar(100) DEFAULT NULL,
  `email` varchar(190) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `status` enum('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`),
  UNIQUE KEY `uq_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Copy existing admin identities without deleting/changing admins.
INSERT INTO `users` (`name`,`username`,`password_hash`,`status`,`created_at`)
SELECT a.`name`, a.`username`, a.`password_hash`,
       CASE WHEN COALESCE(a.`is_active`,1)=1 THEN 'ACTIVE' ELSE 'INACTIVE' END,
       COALESCE(a.`created_at`, CURRENT_TIMESTAMP)
FROM `admins` a
LEFT JOIN `users` u ON u.`username` = a.`username`
WHERE u.`id` IS NULL;

-- -------------------------------------------------------------------------
-- 2. Organizations / tenants
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `organizations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(180) NOT NULL,
  `slug` varchar(180) NOT NULL,
  `status` enum('ACTIVE','SUSPENDED') NOT NULL DEFAULT 'ACTIVE',
  `logo_path` varchar(500) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_organizations_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `organizations` (`name`,`slug`)
SELECT 'Golla Mission Jubo Sangha', 'gmjs'
WHERE NOT EXISTS (SELECT 1 FROM `organizations` WHERE `slug`='gmjs');

CREATE TABLE IF NOT EXISTS `organization_members` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `role` enum('OWNER','ADMIN','MANAGER','STAFF') NOT NULL DEFAULT 'STAFF',
  `status` enum('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_org_member` (`organization_id`,`user_id`),
  KEY `idx_org_members_user` (`user_id`),
  CONSTRAINT `fk_org_members_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_org_members_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- All currently existing admins are members of the migrated GMJS tenant.
INSERT INTO `organization_members` (`organization_id`,`user_id`,`role`)
SELECT o.`id`, u.`id`,
       CASE LOWER(COALESCE(a.`role`, 'staff'))
         WHEN 'super_admin' THEN 'OWNER'
         WHEN 'main_admin' THEN 'OWNER'
         WHEN 'superadmin' THEN 'OWNER'
         WHEN 'admin' THEN 'ADMIN'
         WHEN 'manager' THEN 'MANAGER'
         ELSE 'STAFF'
       END
FROM `admins` a
JOIN `users` u ON u.`username`=a.`username`
JOIN `organizations` o ON o.`slug`='gmjs'
LEFT JOIN `organization_members` om
  ON om.`organization_id`=o.`id` AND om.`user_id`=u.`id`
WHERE om.`id` IS NULL;

-- -------------------------------------------------------------------------
-- 3. Organization boundary on tours (staged: nullable for compatibility)
-- -------------------------------------------------------------------------
ALTER TABLE `tours`
  ADD COLUMN IF NOT EXISTS `organization_id` int(10) unsigned NULL AFTER `id`,
  ADD COLUMN IF NOT EXISTS `slug` varchar(180) NULL AFTER `name`,
  ADD COLUMN IF NOT EXISTS `created_by_user_id` int(10) unsigned NULL AFTER `slug`;

UPDATE `tours` t
JOIN `organizations` o ON o.`slug`='gmjs'
SET t.`organization_id` = o.`id`
WHERE t.`organization_id` IS NULL;

-- Generate deterministic, URL-friendly slugs for legacy tours.
-- Duplicate slugs are resolved with the tour id suffix.
UPDATE `tours`
SET `slug` = CONCAT(
  LEFT(
    TRIM(BOTH '-' FROM REGEXP_REPLACE(LOWER(COALESCE(`name`, CONCAT('tour-',`id`))), '[^a-z0-9]+', '-')),
    150
  ),
  '-', `id`
)
WHERE `slug` IS NULL OR `slug`='';

CREATE INDEX IF NOT EXISTS `idx_tours_organization` ON `tours` (`organization_id`);
CREATE INDEX IF NOT EXISTS `idx_tours_org_slug` ON `tours` (`organization_id`,`slug`);

-- -------------------------------------------------------------------------
-- 4. Tour-scoped memberships
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tour_members` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `tour_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `role` enum('OWNER','ADMIN','MANAGER','STAFF','VIEWER') NOT NULL DEFAULT 'STAFF',
  `status` enum('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tour_member` (`tour_id`,`user_id`),
  KEY `idx_tour_members_user` (`user_id`),
  CONSTRAINT `fk_tour_members_tour` FOREIGN KEY (`tour_id`) REFERENCES `tours` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tour_members_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Compatibility bootstrap: every current GMJS admin can access every current tour.
INSERT INTO `tour_members` (`tour_id`,`user_id`,`role`)
SELECT t.`id`, om.`user_id`,
       CASE om.`role`
         WHEN 'OWNER' THEN 'OWNER'
         WHEN 'ADMIN' THEN 'ADMIN'
         WHEN 'MANAGER' THEN 'MANAGER'
         ELSE 'STAFF'
       END
FROM `tours` t
JOIN `organization_members` om
  ON om.`organization_id`=t.`organization_id` AND om.`status`='ACTIVE'
LEFT JOIN `tour_members` tm
  ON tm.`tour_id`=t.`id` AND tm.`user_id`=om.`user_id`
WHERE tm.`id` IS NULL;

-- -------------------------------------------------------------------------
-- 5. Reusable passenger profiles + tour membership record
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `passenger_profiles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` int(10) unsigned NOT NULL,
  `full_name` varchar(180) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(190) DEFAULT NULL,
  `national_id` varchar(100) DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `gender` varchar(30) DEFAULT NULL,
  `address` varchar(500) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_passenger_profiles_org` (`organization_id`),
  KEY `idx_passenger_profiles_phone` (`organization_id`,`phone`),
  KEY `idx_passenger_profiles_name` (`organization_id`,`full_name`),
  CONSTRAINT `fk_passenger_profiles_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tour_passengers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tour_id` int(10) unsigned NOT NULL,
  `passenger_profile_id` bigint unsigned NOT NULL,
  `registration_source` enum('ADMIN','PUBLIC','IMPORT') NOT NULL DEFAULT 'ADMIN',
  `status` enum('PENDING','ACTIVE','CANCELLED','WAITLIST') NOT NULL DEFAULT 'ACTIVE',
  `registered_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tour_passenger_profile` (`tour_id`,`passenger_profile_id`),
  KEY `idx_tour_passengers_profile` (`passenger_profile_id`),
  CONSTRAINT `fk_tour_passengers_tour` FOREIGN KEY (`tour_id`) REFERENCES `tours` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tour_passengers_profile` FOREIGN KEY (`passenger_profile_id`) REFERENCES `passenger_profiles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -------------------------------------------------------------------------
-- 6. Tour configuration / feature flags
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tour_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tour_id` int(10) unsigned NOT NULL,
  `setting_key` varchar(120) NOT NULL,
  `setting_value` longtext DEFAULT NULL,
  `value_type` enum('STRING','NUMBER','BOOLEAN','JSON') NOT NULL DEFAULT 'STRING',
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tour_setting` (`tour_id`,`setting_key`),
  CONSTRAINT `fk_tour_settings_tour` FOREIGN KEY (`tour_id`) REFERENCES `tours` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tour_features` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tour_id` int(10) unsigned NOT NULL,
  `feature_key` varchar(120) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `config_json` longtext DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tour_feature` (`tour_id`,`feature_key`),
  CONSTRAINT `fk_tour_features_tour` FOREIGN KEY (`tour_id`) REFERENCES `tours` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -------------------------------------------------------------------------
-- 7. Public tour pages / sections
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tour_public_pages` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tour_id` int(10) unsigned NOT NULL,
  `status` enum('DRAFT','PUBLISHED','CLOSED') NOT NULL DEFAULT 'DRAFT',
  `theme_key` varchar(80) NOT NULL DEFAULT 'classic',
  `title` varchar(200) DEFAULT NULL,
  `subtitle` varchar(500) DEFAULT NULL,
  `cover_image` varchar(500) DEFAULT NULL,
  `logo_image` varchar(500) DEFAULT NULL,
  `primary_color` varchar(20) DEFAULT NULL,
  `secondary_color` varchar(20) DEFAULT NULL,
  `custom_css` longtext DEFAULT NULL,
  `seo_title` varchar(255) DEFAULT NULL,
  `seo_description` varchar(500) DEFAULT NULL,
  `published_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_public_page_tour` (`tour_id`),
  CONSTRAINT `fk_public_page_tour` FOREIGN KEY (`tour_id`) REFERENCES `tours` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tour_public_sections` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tour_public_page_id` bigint unsigned NOT NULL,
  `section_key` varchar(80) NOT NULL,
  `heading` varchar(200) DEFAULT NULL,
  `content_json` longtext DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT 0,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_public_sections_page_sort` (`tour_public_page_id`,`sort_order`),
  CONSTRAINT `fk_public_sections_page` FOREIGN KEY (`tour_public_page_id`) REFERENCES `tour_public_pages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -------------------------------------------------------------------------
-- 8. Custom registration fields
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `custom_fields` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tour_id` int(10) unsigned NOT NULL,
  `field_key` varchar(100) NOT NULL,
  `label` varchar(180) NOT NULL,
  `field_type` enum('TEXT','TEXTAREA','NUMBER','DATE','SELECT','CHECKBOX','RADIO','PHONE','EMAIL','FILE') NOT NULL DEFAULT 'TEXT',
  `options_json` longtext DEFAULT NULL,
  `is_required` tinyint(1) NOT NULL DEFAULT 0,
  `is_public` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_custom_field_key` (`tour_id`,`field_key`),
  CONSTRAINT `fk_custom_fields_tour` FOREIGN KEY (`tour_id`) REFERENCES `tours` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `custom_field_values` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `custom_field_id` bigint unsigned NOT NULL,
  `tour_passenger_id` bigint unsigned NOT NULL,
  `value_text` longtext DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_custom_field_value` (`custom_field_id`,`tour_passenger_id`),
  CONSTRAINT `fk_custom_values_field` FOREIGN KEY (`custom_field_id`) REFERENCES `custom_fields` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_custom_values_passenger` FOREIGN KEY (`tour_passenger_id`) REFERENCES `tour_passengers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -------------------------------------------------------------------------
-- 9. Ticket templates
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ticket_templates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` int(10) unsigned NOT NULL,
  `name` varchar(150) NOT NULL,
  `template_type` enum('SYSTEM','CUSTOM') NOT NULL DEFAULT 'SYSTEM',
  `config_json` longtext DEFAULT NULL,
  `preview_image` varchar(500) DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `created_by_user_id` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ticket_templates_org` (`organization_id`),
  CONSTRAINT `fk_ticket_templates_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ticket_templates_user` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -------------------------------------------------------------------------
-- 10. Plans / feature catalog / subscriptions
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `plans` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `plan_key` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `price_monthly` decimal(12,2) NOT NULL DEFAULT 0.00,
  `price_yearly` decimal(12,2) NOT NULL DEFAULT 0.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_plans_key` (`plan_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `plan_features` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `plan_id` int unsigned NOT NULL,
  `feature_key` varchar(120) NOT NULL,
  `limit_value` bigint DEFAULT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `config_json` longtext DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_plan_feature` (`plan_id`,`feature_key`),
  CONSTRAINT `fk_plan_features_plan` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `subscriptions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `organization_id` int(10) unsigned NOT NULL,
  `plan_id` int unsigned NOT NULL,
  `status` enum('TRIALING','ACTIVE','PAST_DUE','CANCELLED','EXPIRED') NOT NULL DEFAULT 'TRIALING',
  `started_at` datetime NOT NULL DEFAULT current_timestamp(),
  `ends_at` datetime DEFAULT NULL,
  `provider` varchar(50) DEFAULT NULL,
  `provider_subscription_id` varchar(190) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_subscriptions_org` (`organization_id`),
  CONSTRAINT `fk_subscriptions_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_subscriptions_plan` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `plans` (`plan_key`,`name`,`price_monthly`,`price_yearly`)
VALUES ('free','Free',0,0),('pro','Pro',0,0),('business','Business',0,0)
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

-- Give the migrated GMJS organization a Free subscription if it has none.
INSERT INTO `subscriptions` (`organization_id`,`plan_id`,`status`)
SELECT o.`id`, p.`id`, 'ACTIVE'
FROM `organizations` o
JOIN `plans` p ON p.`plan_key`='free'
LEFT JOIN `subscriptions` s ON s.`organization_id`=o.`id`
WHERE o.`slug`='gmjs' AND s.`id` IS NULL;

-- -------------------------------------------------------------------------
-- 11. Default public page records for existing tours
-- -------------------------------------------------------------------------
INSERT INTO `tour_public_pages` (`tour_id`,`status`,`theme_key`,`title`)
SELECT t.`id`, 'DRAFT', 'classic', t.`name`
FROM `tours` t
LEFT JOIN `tour_public_pages` p ON p.`tour_id`=t.`id`
WHERE p.`id` IS NULL;

-- -------------------------------------------------------------------------
-- 12. Migration marker
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `schema_migrations` (
  `version` varchar(100) NOT NULL,
  `applied_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `schema_migrations` (`version`)
VALUES ('001_saas_foundation')
ON DUPLICATE KEY UPDATE `version`=`version`;

COMMIT;

-- Next migration will:
--   002_tenant_data_keys.sql
--   Add organization_id to passengers/payments/buses/rooms/etc. as a
--   denormalized security key, then backfill and add appropriate indexes.
-- Existing PHP must be updated before those columns become mandatory.