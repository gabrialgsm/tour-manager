CREATE TABLE IF NOT EXISTS tour_ticket_designs (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 tour_id BIGINT UNSIGNED NOT NULL,
 organization_id BIGINT UNSIGNED NOT NULL,
 design_name VARCHAR(120) NOT NULL DEFAULT 'Default',
 canvas_width INT UNSIGNED NOT NULL DEFAULT 1120,
 canvas_height INT UNSIGNED NOT NULL DEFAULT 448,
 background_image VARCHAR(500) NULL,
 elements_json LONGTEXT NOT NULL,
 created_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(id),
 UNIQUE KEY uq_ticket_design_tour(tour_id),
 KEY idx_ticket_design_org(organization_id),
 CONSTRAINT fk_ticket_design_tour FOREIGN KEY(tour_id) REFERENCES tours(id) ON DELETE CASCADE,
 CONSTRAINT fk_ticket_design_org FOREIGN KEY(organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
 CONSTRAINT fk_ticket_design_user FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
);