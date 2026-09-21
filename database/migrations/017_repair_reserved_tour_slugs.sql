-- Repair a legacy public-page slug that can collide with an internal PHP endpoint.
-- The current public URL is /tour/{slug}; internal .php filenames must never be used as tour slugs.
UPDATE tours
SET slug = CONCAT('tour-', id)
WHERE slug IN ('saas_dashboard.php','saas_public.php','public_tour.php','index.php');
