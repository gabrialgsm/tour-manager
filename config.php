<?php
declare(strict_types=1);

$dbName = getenv('DB_NAME') ?: 'tour_manager';
$dbUser = getenv('DB_USER') ?: 'tour_manager';
$dbHost = getenv('DB_HOST') ?: '127.0.0.1';
$dbPass = getenv('DB_PASS');
$appKey = getenv('APP_KEY');
$appEnv = strtolower(trim((string)(getenv('APP_ENV') ?: 'development')));
$baseUrl = trim((string)(getenv('APP_BASE_URL') ?: ''));

if ($dbPass === false || $dbPass === '') {
    $dbPass = '';
}

// Never allow the development placeholder secret in production.
if ($appKey === false || strlen($appKey) < 32) {
    if ($appEnv === 'production') {
        throw new RuntimeException('APP_KEY must be configured with a random secret of at least 32 characters in production.');
    }
    $appKey = 'CHANGE_THIS_APP_KEY_TO_A_RANDOM_64_CHAR_SECRET';
}

if ($appEnv === 'production') {
    if ($baseUrl === '' || !filter_var($baseUrl, FILTER_VALIDATE_URL) || !preg_match('/^https:\/\//i', $baseUrl)) {
        throw new RuntimeException('APP_BASE_URL must be a valid HTTPS URL in production.');
    }
}

return [
  'db'=>[
    'host'=>$dbHost,
    'name'=>$dbName,
    'user'=>$dbUser,
    'pass'=>$dbPass,
    'charset'=>'utf8mb4'
  ],
  'app'=>[
    'name'=>'Tour Manager',
    'timezone'=>'Asia/Dhaka',
    'base_url'=>$baseUrl,
    'key'=>$appKey,
    'environment'=>$appEnv
  ]
];
