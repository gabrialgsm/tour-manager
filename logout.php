<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

if (saas_authenticated()) {
    saas_audit('auth.logout', 'user', saas_user_id());
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        (bool)$params['secure'],
        (bool)$params['httponly']
    );
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}

saas_redirect('login.php');
