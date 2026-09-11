-- Tour Manager SaaS — clean production foundation
-- MariaDB 10.6+/11.x
-- Fresh-install schema. Existing/legacy data should remain in backup.
-- No organization-specific seed data is included.

SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';
SET NAMES utf8mb4;
SET time_zone = '+00:00';
START TRANSACTION;

CREATE TABLE `schema_migrations` (`version` varchar(100) NOT NULL, `applied_at` timestamp NOT NULL DEFAULT current_timestamp(), PRIMARY KEY (`version`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `users` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `name` varchar(150) NOT NULL, `email` varchar(190) NOT NULL, `phone` varchar(40) DEFAULT NULL, `password_hash` varchar(255) NOT NULL,
 `status` enum('ACTIVE','INVITED','SUSPENDED','DELETED') NOT NULL DEFAULT 'ACTIVE', `email_verified_at` timestamp NULL DEFAULT NULL, `last_login_at` timestamp NULL DEFAULT NULL,
 `created_at` timestamp NOT NULL DEFAULT current_timestamp(), `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(), PRIMARY KEY (`id`),
 UNIQUE KEY `uq_users_email` (`email`), KEY `idx_users_phone` (`phone`), KEY `idx_users_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `organizations` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `name` varchar(180) NOT NULL, `slug` varchar(180) NOT NULL,
 `status` enum('ACTIVE','SUSPENDED','ARCHIVED') NOT NULL DEFAULT 'ACTIVE', `timezone` varchar(64) NOT NULL DEFAULT 'Asia/Dhaka', `currency` char(3) NOT NULL DEFAULT 'BDT', `locale` varchar(10) NOT NULL DEFAULT 'en-BD',
 `logo_path` varchar(500) DEFAULT NULL, `created_at` timestamp NOT NULL DEFAULT current_timestamp(), `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
 PRIMARY KEY (`id`), UNIQUE KEY `uq_org_slug` (`slug`), KEY `idx_org_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `organization_members` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `organization_id` bigint unsigned NOT NULL, `user_id` bigint unsigned NOT NULL,
 `role` enum('OWNER','ADMIN','MANAGER','STAFF','VIEWER') NOT NULL DEFAULT 'STAFF', `status` enum('ACTIVE','INVITED','SUSPENDED','REMOVED') NOT NULL DEFAULT 'ACTIVE',
 `created_at` timestamp NOT NULL DEFAULT current_timestamp(), `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(), PRIMARY KEY (`id`),
 UNIQUE KEY `uq_org_member` (`organization_id`,`user_id`), KEY `idx_org_members_user` (`user_id`),
 CONSTRAINT `fk_org_members_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE,
 CONSTRAINT `fk_org_members_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `tours` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `organization_id` bigint unsigned NOT NULL, `name` varchar(200) NOT NULL, `slug` varchar(180) NOT NULL, `description` text DEFAULT NULL,
 `start_date` date NOT NULL, `end_date` date NOT NULL, `registration_open_at` datetime NULL DEFAULT NULL, `registration_close_at` datetime NULL DEFAULT NULL,
 `default_fee` decimal(12,2) NOT NULL DEFAULT 0.00, `booking_fee` decimal(12,2) NOT NULL DEFAULT 0.00,
 `status` enum('DRAFT','OPEN','CLOSED','COMPLETED','ARCHIVED') NOT NULL DEFAULT 'DRAFT', `created_by` bigint unsigned DEFAULT NULL,
 `created_at` timestamp NOT NULL DEFAULT current_timestamp(), `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(), PRIMARY KEY (`id`),
 UNIQUE KEY `uq_tour_org_slug` (`organization_id`,`slug`), KEY `idx_tours_org_status` (`organization_id`,`status`), KEY `idx_tours_dates` (`start_date`,`end_date`),
 CONSTRAINT `fk_tours_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE RESTRICT,
 CONSTRAINT `fk_tours_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `tour_members` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `tour_id` bigint unsigned NOT NULL, `user_id` bigint unsigned NOT NULL,
 `role` enum('OWNER','ADMIN','MANAGER','STAFF','VIEWER') NOT NULL DEFAULT 'STAFF', `status` enum('ACTIVE','INVITED','REMOVED') NOT NULL DEFAULT 'ACTIVE',
 `created_at` timestamp NOT NULL DEFAULT current_timestamp(), PRIMARY KEY (`id`), UNIQUE KEY `uq_tour_member` (`tour_id`,`user_id`), KEY `idx_tour_members_user` (`user_id`),
 CONSTRAINT `fk_tour_members_tour` FOREIGN KEY (`tour_id`) REFERENCES `tours` (`id`) ON DELETE CASCADE,
 CONSTRAINT `fk_tour_members_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `tour_settings` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `tour_id` bigint unsigned NOT NULL, `setting_key` varchar(120) NOT NULL, `setting_value` longtext DEFAULT NULL,
 `value_type` enum('STRING','NUMBER','BOOLEAN','JSON') NOT NULL DEFAULT 'STRING', `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
 PRIMARY KEY (`id`), UNIQUE KEY `uq_tour_setting` (`tour_id`,`setting_key`), CONSTRAINT `fk_tour_settings_tour` FOREIGN KEY (`tour_id`) REFERENCES `tours` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `tour_features` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `tour_id` bigint unsigned NOT NULL, `feature_key` varchar(120) NOT NULL, `enabled` tinyint(1) NOT NULL DEFAULT 1, `config_json` longtext DEFAULT NULL,
 `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(), PRIMARY KEY (`id`), UNIQUE KEY `uq_tour_feature` (`tour_id`,`feature_key`),
 CONSTRAINT `fk_tour_features_tour` FOREIGN KEY (`tour_id`) REFERENCES `tours` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `passenger_profiles` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `organization_id` bigint unsigned NOT NULL, `full_name` varchar(180) NOT NULL, `phone` varchar(50) DEFAULT NULL, `email` varchar(190) DEFAULT NULL,
 `national_id` varchar(100) DEFAULT NULL, `date_of_birth` date DEFAULT NULL, `gender` varchar(30) DEFAULT NULL, `address` varchar(500) DEFAULT NULL,
 `emergency_contact_name` varchar(180) DEFAULT NULL, `emergency_contact_phone` varchar(50) DEFAULT NULL, `notes` text DEFAULT NULL,
 `created_at` timestamp NOT NULL DEFAULT current_timestamp(), `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(), PRIMARY KEY (`id`),
 KEY `idx_profiles_org_name` (`organization_id`,`full_name`), KEY `idx_profiles_org_phone` (`organization_id`,`phone`), KEY `idx_profiles_org_email` (`organization_id`,`email`),
 CONSTRAINT `fk_profiles_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `buses` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `organization_id` bigint unsigned NOT NULL, `name` varchar(150) NOT NULL, `registration_no` varchar(80) DEFAULT NULL,
 `operator_name` varchar(150) DEFAULT NULL, `layout_type` enum('2+2','2+3','CUSTOM') NOT NULL DEFAULT '2+2', `normal_rows` smallint unsigned NOT NULL DEFAULT 10,
 `front_single_count` tinyint unsigned NOT NULL DEFAULT 0, `front_single_label` varchar(30) NOT NULL DEFAULT 'S', `last_row_seats` tinyint unsigned NOT NULL DEFAULT 4,
 `total_seats` smallint unsigned NOT NULL DEFAULT 0, `status` enum('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
 `created_at` timestamp NOT NULL DEFAULT current_timestamp(), `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(), PRIMARY KEY (`id`),
 KEY `idx_buses_org_status` (`organization_id`,`status`), CONSTRAINT `fk_buses_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `seats` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `bus_id` bigint unsigned NOT NULL, `seat_no` varchar(30) NOT NULL, `row_no` smallint unsigned NOT NULL DEFAULT 0,
 `position` enum('FRONT_SINGLE','LEFT','RIGHT','LAST') NOT NULL DEFAULT 'LEFT', `sort_order` int unsigned NOT NULL DEFAULT 0, `status` enum('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
 PRIMARY KEY (`id`), UNIQUE KEY `uq_bus_seat` (`bus_id`,`seat_no`), KEY `idx_seats_bus_sort` (`bus_id`,`sort_order`),
 CONSTRAINT `fk_seats_bus` FOREIGN KEY (`bus_id`) REFERENCES `buses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `passengers` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `organization_id` bigint unsigned NOT NULL, `tour_id` bigint unsigned NOT NULL, `profile_id` bigint unsigned DEFAULT NULL,
 `booking_reference` varchar(40) NOT NULL, `full_name` varchar(180) NOT NULL, `phone` varchar(50) DEFAULT NULL, `email` varchar(190) DEFAULT NULL, `address` varchar(500) DEFAULT NULL, `gender` varchar(30) DEFAULT NULL,
 `room_type` varchar(50) DEFAULT NULL, `fee` decimal(12,2) NOT NULL DEFAULT 0.00, `discount` decimal(12,2) NOT NULL DEFAULT 0.00,
 `status` enum('PENDING','ACTIVE','CANCELLED','WAITLIST') NOT NULL DEFAULT 'ACTIVE', `registration_source` enum('ADMIN','PUBLIC','IMPORT') NOT NULL DEFAULT 'ADMIN',
 `registered_at` timestamp NOT NULL DEFAULT current_timestamp(), `cancelled_at` timestamp NULL DEFAULT NULL, `created_by` bigint unsigned DEFAULT NULL,
 `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(), PRIMARY KEY (`id`), UNIQUE KEY `uq_passenger_booking_ref` (`booking_reference`),
 KEY `idx_passengers_tour_status` (`tour_id`,`status`), KEY `idx_passengers_org_tour` (`organization_id`,`tour_id`), KEY `idx_passengers_profile` (`profile_id`), KEY `idx_passengers_phone` (`organization_id`,`phone`),
 CONSTRAINT `fk_passengers_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE RESTRICT,
 CONSTRAINT `fk_passengers_tour` FOREIGN KEY (`tour_id`) REFERENCES `tours` (`id`) ON DELETE CASCADE,
 CONSTRAINT `fk_passengers_profile` FOREIGN KEY (`profile_id`) REFERENCES `passenger_profiles` (`id`) ON DELETE SET NULL,
 CONSTRAINT `fk_passengers_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `passenger_seats` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `tour_id` bigint unsigned NOT NULL, `passenger_id` bigint unsigned NOT NULL, `seat_id` bigint unsigned NOT NULL,
 `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(), `assigned_by` bigint unsigned DEFAULT NULL, PRIMARY KEY (`id`), UNIQUE KEY `uq_tour_seat` (`tour_id`,`seat_id`), UNIQUE KEY `uq_passenger_seat` (`passenger_id`),
 KEY `idx_passenger_seats_passenger` (`passenger_id`), CONSTRAINT `fk_passenger_seats_tour` FOREIGN KEY (`tour_id`) REFERENCES `tours` (`id`) ON DELETE CASCADE,
 CONSTRAINT `fk_passenger_seats_passenger` FOREIGN KEY (`passenger_id`) REFERENCES `passengers` (`id`) ON DELETE CASCADE,
 CONSTRAINT `fk_passenger_seats_seat` FOREIGN KEY (`seat_id`) REFERENCES `seats` (`id`) ON DELETE CASCADE,
 CONSTRAINT `fk_passenger_seats_user` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `rooms` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `organization_id` bigint unsigned NOT NULL, `tour_id` bigint unsigned NOT NULL, `room_no` varchar(50) NOT NULL, `room_type` varchar(60) NOT NULL,
 `capacity` tinyint unsigned NOT NULL DEFAULT 1, `notes` varchar(500) DEFAULT NULL, `status` enum('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE', PRIMARY KEY (`id`),
 UNIQUE KEY `uq_tour_room_no` (`tour_id`,`room_no`), KEY `idx_rooms_org_tour` (`organization_id`,`tour_id`),
 CONSTRAINT `fk_rooms_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE RESTRICT, CONSTRAINT `fk_rooms_tour` FOREIGN KEY (`tour_id`) REFERENCES `tours` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `room_assignments` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `tour_id` bigint unsigned NOT NULL, `room_id` bigint unsigned NOT NULL, `passenger_id` bigint unsigned NOT NULL,
 `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(), `assigned_by` bigint unsigned DEFAULT NULL, PRIMARY KEY (`id`), UNIQUE KEY `uq_room_passenger` (`passenger_id`),
 KEY `idx_room_assignments_room` (`room_id`), KEY `idx_room_assignments_tour` (`tour_id`), CONSTRAINT `fk_room_assignments_tour` FOREIGN KEY (`tour_id`) REFERENCES `tours` (`id`) ON DELETE CASCADE,
 CONSTRAINT `fk_room_assignments_room` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE CASCADE, CONSTRAINT `fk_room_assignments_passenger` FOREIGN KEY (`passenger_id`) REFERENCES `passengers` (`id`) ON DELETE CASCADE,
 CONSTRAINT `fk_room_assignments_user` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `payments` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `organization_id` bigint unsigned NOT NULL, `tour_id` bigint unsigned NOT NULL, `passenger_id` bigint unsigned NOT NULL,
 `payment_reference` varchar(50) NOT NULL, `amount` decimal(12,2) NOT NULL, `payment_method` enum('CASH','BKASH','NAGAD','ROCKET','BANK','CARD','ONLINE','OTHER') NOT NULL DEFAULT 'CASH',
 `transaction_reference` varchar(150) DEFAULT NULL, `payment_date` date NOT NULL, `status` enum('PENDING','PAID','VOID','REFUNDED') NOT NULL DEFAULT 'PAID', `notes` varchar(500) DEFAULT NULL,
 `created_by` bigint unsigned DEFAULT NULL, `created_at` timestamp NOT NULL DEFAULT current_timestamp(), `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(), PRIMARY KEY (`id`),
 UNIQUE KEY `uq_payment_reference` (`payment_reference`), KEY `idx_payments_org_tour` (`organization_id`,`tour_id`), KEY `idx_payments_passenger` (`passenger_id`,`payment_date`), KEY `idx_payments_status` (`status`),
 CONSTRAINT `fk_payments_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE RESTRICT, CONSTRAINT `fk_payments_tour` FOREIGN KEY (`tour_id`) REFERENCES `tours` (`id`) ON DELETE CASCADE,
 CONSTRAINT `fk_payments_passenger` FOREIGN KEY (`passenger_id`) REFERENCES `passengers` (`id`) ON DELETE CASCADE, CONSTRAINT `fk_payments_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `expenses` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `organization_id` bigint unsigned NOT NULL, `tour_id` bigint unsigned NOT NULL, `category` varchar(100) NOT NULL, `description` varchar(500) DEFAULT NULL,
 `amount` decimal(12,2) NOT NULL, `expense_date` date NOT NULL, `payment_method` varchar(30) DEFAULT NULL, `created_by` bigint unsigned DEFAULT NULL,
 `created_at` timestamp NOT NULL DEFAULT current_timestamp(), `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(), PRIMARY KEY (`id`), KEY `idx_expenses_org_tour_date` (`organization_id`,`tour_id`,`expense_date`),
 CONSTRAINT `fk_expenses_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE RESTRICT, CONSTRAINT `fk_expenses_tour` FOREIGN KEY (`tour_id`) REFERENCES `tours` (`id`) ON DELETE CASCADE,
 CONSTRAINT `fk_expenses_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `incomes` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `organization_id` bigint unsigned NOT NULL, `tour_id` bigint unsigned NOT NULL, `category` varchar(100) NOT NULL, `description` varchar(500) DEFAULT NULL,
 `amount` decimal(12,2) NOT NULL, `received_from` varchar(150) DEFAULT NULL, `income_date` date NOT NULL, `created_by` bigint unsigned DEFAULT NULL,
 `created_at` timestamp NOT NULL DEFAULT current_timestamp(), `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(), PRIMARY KEY (`id`), KEY `idx_incomes_org_tour_date` (`organization_id`,`tour_id`,`income_date`),
 CONSTRAINT `fk_incomes_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE RESTRICT, CONSTRAINT `fk_incomes_tour` FOREIGN KEY (`tour_id`) REFERENCES `tours` (`id`) ON DELETE CASCADE,
 CONSTRAINT `fk_incomes_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `tour_contacts` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `tour_id` bigint unsigned NOT NULL, `label` varchar(80) DEFAULT NULL, `name` varchar(150) DEFAULT NULL, `phone` varchar(50) DEFAULT NULL,
 `email` varchar(190) DEFAULT NULL, `messenger_url` varchar(500) DEFAULT NULL, `sort_order` int NOT NULL DEFAULT 0, `is_active` tinyint(1) NOT NULL DEFAULT 1, PRIMARY KEY (`id`),
 KEY `idx_tour_contacts` (`tour_id`,`is_active`,`sort_order`), CONSTRAINT `fk_tour_contacts_tour` FOREIGN KEY (`tour_id`) REFERENCES `tours` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `tour_public_pages` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `tour_id` bigint unsigned NOT NULL, `status` enum('DRAFT','PUBLISHED','CLOSED') NOT NULL DEFAULT 'DRAFT', `theme_key` varchar(80) NOT NULL DEFAULT 'classic',
 `title` varchar(200) DEFAULT NULL, `subtitle` varchar(500) DEFAULT NULL, `cover_image` varchar(500) DEFAULT NULL, `logo_image` varchar(500) DEFAULT NULL,
 `primary_color` varchar(20) DEFAULT NULL, `secondary_color` varchar(20) DEFAULT NULL, `seo_title` varchar(255) DEFAULT NULL, `seo_description` varchar(500) DEFAULT NULL,
 `published_at` timestamp NULL DEFAULT NULL, `created_at` timestamp NOT NULL DEFAULT current_timestamp(), `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(), PRIMARY KEY (`id`),
 UNIQUE KEY `uq_public_page_tour` (`tour_id`), CONSTRAINT `fk_public_page_tour` FOREIGN KEY (`tour_id`) REFERENCES `tours` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `tour_public_sections` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `tour_public_page_id` bigint unsigned NOT NULL, `section_key` varchar(80) NOT NULL, `heading` varchar(200) DEFAULT NULL,
 `content_json` longtext DEFAULT NULL, `sort_order` int NOT NULL DEFAULT 0, `is_enabled` tinyint(1) NOT NULL DEFAULT 1, PRIMARY KEY (`id`), KEY `idx_public_sections_page_sort` (`tour_public_page_id`,`sort_order`),
 CONSTRAINT `fk_public_sections_page` FOREIGN KEY (`tour_public_page_id`) REFERENCES `tour_public_pages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `custom_fields` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `tour_id` bigint unsigned NOT NULL, `field_key` varchar(100) NOT NULL, `label` varchar(180) NOT NULL,
 `field_type` enum('TEXT','TEXTAREA','NUMBER','DATE','SELECT','CHECKBOX','RADIO','PHONE','EMAIL','FILE') NOT NULL DEFAULT 'TEXT', `options_json` longtext DEFAULT NULL,
 `is_required` tinyint(1) NOT NULL DEFAULT 0, `is_public` tinyint(1) NOT NULL DEFAULT 1, `sort_order` int NOT NULL DEFAULT 0, `created_at` timestamp NOT NULL DEFAULT current_timestamp(), PRIMARY KEY (`id`),
 UNIQUE KEY `uq_custom_field` (`tour_id`,`field_key`), CONSTRAINT `fk_custom_fields_tour` FOREIGN KEY (`tour_id`) REFERENCES `tours` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `custom_field_values` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `custom_field_id` bigint unsigned NOT NULL, `passenger_id` bigint unsigned NOT NULL, `value_text` longtext DEFAULT NULL,
 `created_at` timestamp NOT NULL DEFAULT current_timestamp(), `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(), PRIMARY KEY (`id`),
 UNIQUE KEY `uq_custom_field_value` (`custom_field_id`,`passenger_id`), CONSTRAINT `fk_custom_values_field` FOREIGN KEY (`custom_field_id`) REFERENCES `custom_fields` (`id`) ON DELETE CASCADE,
 CONSTRAINT `fk_custom_values_passenger` FOREIGN KEY (`passenger_id`) REFERENCES `passengers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ticket_templates` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `organization_id` bigint unsigned NOT NULL, `name` varchar(150) NOT NULL, `template_type` enum('SYSTEM','CUSTOM') NOT NULL DEFAULT 'SYSTEM',
 `config_json` longtext DEFAULT NULL, `preview_image` varchar(500) DEFAULT NULL, `is_default` tinyint(1) NOT NULL DEFAULT 0, `created_by` bigint unsigned DEFAULT NULL,
 `created_at` timestamp NOT NULL DEFAULT current_timestamp(), `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(), PRIMARY KEY (`id`), KEY `idx_ticket_templates_org` (`organization_id`),
 CONSTRAINT `fk_ticket_templates_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE, CONSTRAINT `fk_ticket_templates_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `tickets` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `organization_id` bigint unsigned NOT NULL, `tour_id` bigint unsigned NOT NULL, `passenger_id` bigint unsigned NOT NULL,
 `ticket_no` varchar(60) NOT NULL, `template_id` bigint unsigned DEFAULT NULL, `qr_token` char(64) NOT NULL, `issued_at` timestamp NULL DEFAULT NULL, `status` enum('ISSUED','VOID') NOT NULL DEFAULT 'ISSUED',
 `created_by` bigint unsigned DEFAULT NULL, `created_at` timestamp NOT NULL DEFAULT current_timestamp(), PRIMARY KEY (`id`), UNIQUE KEY `uq_ticket_no` (`ticket_no`), UNIQUE KEY `uq_ticket_qr` (`qr_token`), UNIQUE KEY `uq_passenger_ticket` (`passenger_id`),
 KEY `idx_tickets_org_tour` (`organization_id`,`tour_id`), CONSTRAINT `fk_tickets_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE RESTRICT,
 CONSTRAINT `fk_tickets_tour` FOREIGN KEY (`tour_id`) REFERENCES `tours` (`id`) ON DELETE CASCADE, CONSTRAINT `fk_tickets_passenger` FOREIGN KEY (`passenger_id`) REFERENCES `passengers` (`id`) ON DELETE CASCADE,
 CONSTRAINT `fk_tickets_template` FOREIGN KEY (`template_id`) REFERENCES `ticket_templates` (`id`) ON DELETE SET NULL, CONSTRAINT `fk_tickets_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `checkins` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `tour_id` bigint unsigned NOT NULL, `passenger_id` bigint unsigned NOT NULL, `checked_in_at` timestamp NOT NULL DEFAULT current_timestamp(), `checked_in_by` bigint unsigned DEFAULT NULL,
 `method` enum('MANUAL','QR') NOT NULL DEFAULT 'MANUAL', `notes` varchar(500) DEFAULT NULL, PRIMARY KEY (`id`), UNIQUE KEY `uq_checkin_passenger` (`passenger_id`), KEY `idx_checkins_tour` (`tour_id`,`checked_in_at`),
 CONSTRAINT `fk_checkins_tour` FOREIGN KEY (`tour_id`) REFERENCES `tours` (`id`) ON DELETE CASCADE, CONSTRAINT `fk_checkins_passenger` FOREIGN KEY (`passenger_id`) REFERENCES `passengers` (`id`) ON DELETE CASCADE,
 CONSTRAINT `fk_checkins_user` FOREIGN KEY (`checked_in_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `audit_log` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `organization_id` bigint unsigned DEFAULT NULL, `tour_id` bigint unsigned DEFAULT NULL, `user_id` bigint unsigned DEFAULT NULL,
 `action` varchar(100) NOT NULL, `entity_type` varchar(80) DEFAULT NULL, `entity_id` bigint unsigned DEFAULT NULL, `ip_address` varchar(45) DEFAULT NULL, `user_agent` varchar(500) DEFAULT NULL,
 `metadata_json` longtext DEFAULT NULL, `created_at` timestamp NOT NULL DEFAULT current_timestamp(), PRIMARY KEY (`id`), KEY `idx_audit_org_time` (`organization_id`,`created_at`), KEY `idx_audit_tour_time` (`tour_id`,`created_at`), KEY `idx_audit_user_time` (`user_id`,`created_at`),
 CONSTRAINT `fk_audit_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE SET NULL, CONSTRAINT `fk_audit_tour` FOREIGN KEY (`tour_id`) REFERENCES `tours` (`id`) ON DELETE SET NULL, CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `plans` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `plan_key` varchar(50) NOT NULL, `name` varchar(100) NOT NULL, `monthly_price` decimal(12,2) NOT NULL DEFAULT 0.00, `annual_price` decimal(12,2) NOT NULL DEFAULT 0.00, `is_active` tinyint(1) NOT NULL DEFAULT 1,
 PRIMARY KEY (`id`), UNIQUE KEY `uq_plan_key` (`plan_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `plan_features` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `plan_id` bigint unsigned NOT NULL, `feature_key` varchar(100) NOT NULL, `enabled` tinyint(1) NOT NULL DEFAULT 1, `limit_value` bigint DEFAULT NULL,
 PRIMARY KEY (`id`), UNIQUE KEY `uq_plan_feature` (`plan_id`,`feature_key`), CONSTRAINT `fk_plan_features_plan` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `subscriptions` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `organization_id` bigint unsigned NOT NULL, `plan_id` bigint unsigned NOT NULL, `status` enum('TRIALING','ACTIVE','PAST_DUE','CANCELLED','EXPIRED') NOT NULL DEFAULT 'TRIALING',
 `billing_cycle` enum('MONTHLY','ANNUAL','LIFETIME') NOT NULL DEFAULT 'MONTHLY', `started_at` datetime NOT NULL, `current_period_start` datetime NOT NULL, `current_period_end` datetime NOT NULL,
 `cancelled_at` datetime DEFAULT NULL, `external_customer_id` varchar(190) DEFAULT NULL, `external_subscription_id` varchar(190) DEFAULT NULL,
 `created_at` timestamp NOT NULL DEFAULT current_timestamp(), `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(), PRIMARY KEY (`id`), KEY `idx_subscriptions_org_status` (`organization_id`,`status`),
 CONSTRAINT `fk_subscriptions_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE, CONSTRAINT `fk_subscriptions_plan` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `usage_records` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `organization_id` bigint unsigned NOT NULL, `metric_key` varchar(100) NOT NULL, `period_start` date NOT NULL, `period_end` date NOT NULL, `usage_value` bigint unsigned NOT NULL DEFAULT 0,
 `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(), PRIMARY KEY (`id`), UNIQUE KEY `uq_usage_period` (`organization_id`,`metric_key`,`period_start`,`period_end`),
 CONSTRAINT `fk_usage_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `plans` (`plan_key`,`name`) VALUES ('free','Free'),('pro','Pro'),('business','Business'),('enterprise','Enterprise');
INSERT INTO `schema_migrations` (`version`) VALUES ('001_saas_foundation');
COMMIT;
