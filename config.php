<?php
declare(strict_types=1);

$dbName = getenv('DB_NAME') ?: 'tour_manager';
$dbUser = getenv('DB_USER') ?: 'tour_manager';
$dbHost = getenv('DB_HOST') ?: '127.0.0.1';
$dbPass = getenv('DB_PASS');
$appKey = getenv('APP_KEY');

if ($dbPass === false || $dbPass === '') {
    $dbPass = '';
}
if ($appKey === false || strlen($appKey) < 32) {
    // Set APP_KEY in the server environment before production use.
    $appKey = 'CHANGE_THIS_APP_KEY_TO_A_RANDOM_64_CHAR_SECRET';
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
    'base_url'=>getenv('APP_BASE_URL') ?: '',
    'key'=>$appKey
  ]
];
