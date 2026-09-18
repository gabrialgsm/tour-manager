-- Central SaaS plan catalog, subscriptions, billing records and entitlements.
CREATE TABLE IF NOT EXISTS saas_plans (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 plan_code VARCHAR(40) NOT NULL,
 name VARCHAR(100) NOT NULL,
 description VARCHAR(500) NULL,
 monthly_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
 yearly_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
 currency CHAR(3) NOT NULL DEFAULT 'BDT',
 status ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
 sort_order INT UNSIGNED NOT NULL DEFAULT 0,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(id),
 UNIQUE KEY uq_saas_plan_code(plan_code),
 KEY idx_saas_plan_status_sort(status,sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS saas_plan_entitlements (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 plan_id BIGINT UNSIGNED NOT NULL,
 entitlement_key VARCHAR(100) NOT NULL,
 entitlement_value VARCHAR(255) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(id),
 UNIQUE KEY uq_saas_plan_entitlement(plan_id,entitlement_key),
 CONSTRAINT fk_saas_pe_plan FOREIGN KEY(plan_id) REFERENCES saas_plans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS organization_subscriptions (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 organization_id BIGINT UNSIGNED NOT NULL,
 plan_id BIGINT UNSIGNED NOT NULL,
 status ENUM('TRIALING','ACTIVE','PAST_DUE','CANCELLED') NOT NULL DEFAULT 'ACTIVE',
 billing_cycle ENUM('MONTHLY','YEARLY','MANUAL') NOT NULL DEFAULT 'MANUAL',
 current_period_start DATETIME NULL,
 current_period_end DATETIME NULL,
 trial_ends_at DATETIME NULL,
 cancelled_at DATETIME NULL,
 external_customer_ref VARCHAR(190) NULL,
 external_subscription_ref VARCHAR(190) NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(id),
 UNIQUE KEY uq_org_subscription(organization_id),
 UNIQUE KEY uq_external_subscription(external_subscription_ref),
 KEY idx_os_status_period(status,current_period_end),
 CONSTRAINT fk_os_org FOREIGN KEY(organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
 CONSTRAINT fk_os_plan FOREIGN KEY(plan_id) REFERENCES saas_plans(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_invoices (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 organization_id BIGINT UNSIGNED NOT NULL,
 subscription_id BIGINT UNSIGNED NULL,
 invoice_number VARCHAR(60) NOT NULL,
 status ENUM('DRAFT','OPEN','PAID','VOID','UNCOLLECTIBLE') NOT NULL DEFAULT 'DRAFT',
 currency CHAR(3) NOT NULL DEFAULT 'BDT',
 subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
 discount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
 tax DECIMAL(12,2) NOT NULL DEFAULT 0.00,
 total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
 amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0.00,
 due_at DATETIME NULL,
 paid_at DATETIME NULL,
 external_invoice_ref VARCHAR(190) NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(id),
 UNIQUE KEY uq_bi_number(invoice_number),
 UNIQUE KEY uq_bi_external(external_invoice_ref),
 KEY idx_bi_org_status(organization_id,status,created_at),
 CONSTRAINT fk_bi_org FOREIGN KEY(organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
 CONSTRAINT fk_bi_subscription FOREIGN KEY(subscription_id) REFERENCES organization_subscriptions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_invoice_items (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 invoice_id BIGINT UNSIGNED NOT NULL,
 description VARCHAR(255) NOT NULL,
 quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00,
 unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
 amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(id),
 KEY idx_bii_invoice(invoice_id),
 CONSTRAINT fk_bii_invoice FOREIGN KEY(invoice_id) REFERENCES billing_invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_events (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 organization_id BIGINT UNSIGNED NOT NULL,
 subscription_id BIGINT UNSIGNED NULL,
 event_key VARCHAR(100) NOT NULL,
 idempotency_key VARCHAR(190) NULL,
 payload_hash CHAR(64) NULL,
 payload_json LONGTEXT NULL,
 processed_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(id),
 UNIQUE KEY uq_be_idempotency(idempotency_key),
 KEY idx_be_org_created(organization_id,created_at),
 KEY idx_be_subscription_created(subscription_id,created_at),
 CONSTRAINT fk_be_org FOREIGN KEY(organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
 CONSTRAINT fk_be_subscription FOREIGN KEY(subscription_id) REFERENCES organization_subscriptions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO saas_plans (plan_code,name,description,monthly_price,yearly_price,currency,status,sort_order)
VALUES
('FREE','Free','Core tour management for getting started.',0,0,'BDT','ACTIVE',10),
('PRO','Pro','Advanced branding and higher operating limits.',999,9990,'BDT','ACTIVE',20),
('BUSINESS','Business','Higher limits for growing tour operators.',2499,24990,'BDT','ACTIVE',30),
('ENTERPRISE','Enterprise','Custom limits, support and commercial terms.',0,0,'BDT','ACTIVE',40)
ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),monthly_price=VALUES(monthly_price),yearly_price=VALUES(yearly_price),currency=VALUES(currency),status=VALUES(status),sort_order=VALUES(sort_order);

INSERT INTO saas_plan_entitlements (plan_id,entitlement_key,entitlement_value)
SELECT id,'max_tours',CASE plan_code WHEN 'FREE' THEN '3' WHEN 'PRO' THEN '50' WHEN 'BUSINESS' THEN '500' ELSE '-1' END FROM saas_plans
ON DUPLICATE KEY UPDATE entitlement_value=VALUES(entitlement_value);
INSERT INTO saas_plan_entitlements (plan_id,entitlement_key,entitlement_value)
SELECT id,'max_members',CASE plan_code WHEN 'FREE' THEN '5' WHEN 'PRO' THEN '25' WHEN 'BUSINESS' THEN '100' ELSE '-1' END FROM saas_plans
ON DUPLICATE KEY UPDATE entitlement_value=VALUES(entitlement_value);
INSERT INTO saas_plan_entitlements (plan_id,entitlement_key,entitlement_value)
SELECT id,'max_passengers_per_tour',CASE plan_code WHEN 'FREE' THEN '100' WHEN 'PRO' THEN '1000' WHEN 'BUSINESS' THEN '5000' ELSE '-1' END FROM saas_plans
ON DUPLICATE KEY UPDATE entitlement_value=VALUES(entitlement_value);
INSERT INTO saas_plan_entitlements (plan_id,entitlement_key,entitlement_value)
SELECT id,'custom_branding',CASE WHEN plan_code='FREE' THEN '0' ELSE '1' END FROM saas_plans
ON DUPLICATE KEY UPDATE entitlement_value=VALUES(entitlement_value);
INSERT INTO saas_plan_entitlements (plan_id,entitlement_key,entitlement_value)
SELECT id,'custom_features',CASE WHEN plan_code='FREE' THEN '0' ELSE '1' END FROM saas_plans
ON DUPLICATE KEY UPDATE entitlement_value=VALUES(entitlement_value);
INSERT INTO saas_plan_entitlements (plan_id,entitlement_key,entitlement_value)
SELECT id,'advanced_reports',CASE WHEN plan_code IN ('FREE','PRO') THEN '0' ELSE '1' END FROM saas_plans
ON DUPLICATE KEY UPDATE entitlement_value=VALUES(entitlement_value);
