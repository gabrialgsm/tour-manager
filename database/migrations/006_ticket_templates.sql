-- Customer-selectable ticket design. The organization still controls which templates are available.
ALTER TABLE ticket_instances
  ADD COLUMN template_key VARCHAR(80) NOT NULL DEFAULT 'classic' AFTER status,
  ADD KEY idx_ticket_template_key(template_key);
