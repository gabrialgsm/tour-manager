<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new RuntimeException('Method not allowed.');
    saas_check_csrf();
    $field=(string)($_POST['field']??'');
    $value=trim((string)($_POST['value']??''));
    if ($field==='username') {
        $ok=(bool)preg_match('/^[A-Za-z0-9._-]{3,80}$/',$value);
        if (!$ok) { echo json_encode(['available'=>false,'message'=>'Use 3–80 letters, numbers, dots, underscores or hyphens.']); exit; }
        $q=saas_db()->prepare('SELECT id FROM users WHERE username=? LIMIT 1'); $q->execute([$value]);
    } elseif ($field==='email') {
        $value=strtolower($value);
        if (!filter_var($value,FILTER_VALIDATE_EMAIL)) { echo json_encode(['available'=>false,'message'=>'Enter a valid email address.']); exit; }
        $q=saas_db()->prepare('SELECT id FROM users WHERE email=? LIMIT 1'); $q->execute([$value]);
    } else throw new RuntimeException('Invalid field.');
    $exists=(bool)$q->fetchColumn();
    echo json_encode(['available'=>!$exists,'message'=>$exists?'Already in use.':'Available.']);
} catch(Throwable $e) {
    http_response_code(400); echo json_encode(['available'=>false,'message'=>$e->getMessage()]);
}
