<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

saas_require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$raw = trim((string)($_GET['slug'] ?? ''));
$slug = saas_slug($raw);

if ($raw === '' || $slug === '' || !preg_match('/^[a-z0-9][a-z0-9-]{1,178}$/', $slug)) {
    echo json_encode([
        'ok' => true,
        'slug' => $slug,
        'available' => false,
        'valid' => false,
        'message' => 'Use 2–180 characters: lowercase letters, numbers and hyphens.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (in_array($slug, ['saas-dashboard', 'saas-public', 'public-tour', 'index'], true)) {
    echo json_encode([
        'ok' => true,
        'slug' => $slug,
        'available' => false,
        'valid' => true,
        'message' => 'This slug is reserved. Please choose another one.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = saas_db();
$q = $db->prepare('SELECT id FROM organizations WHERE slug=? LIMIT 1');
$q->execute([$slug]);
$exists = (bool)$q->fetchColumn();

echo json_encode([
    'ok' => true,
    'slug' => $slug,
    'available' => !$exists,
    'valid' => true,
    'message' => $exists ? 'This public slug is already in use.' : 'Slug is available.',
], JSON_UNESCAPED_UNICODE);
