<?php
declare(strict_types=1);

/* Tour Manager — SaaS Core Bootstrap
 * This file is the foundation for the new multi-tenant application.
 * Legacy GMJS-specific bootstrap remains untouched until module migration is complete.
 */

$config = require __DIR__ . '/config.php';
date_default_timezone_set($config['app']['timezone'] ?? 'Asia/Dhaka');

session_name('TOURMANAGERSESSID');
session_set_cookie_params([
    'httponly' => true,
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'samesite' => 'Lax',
]);
session_start();

function saas_db(): PDO
{
    static $pdo;
    global $config;
    if (!$pdo) {
        $d = $config['db'];
        $pdo = new PDO(
            "mysql:host={$d['host']};dbname={$d['name']};charset=utf8mb4",
            $d['user'],
            $d['pass'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }
    return $pdo;
}

function saas_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function saas_redirect(string $url): never
{
    header('Location: ' . $url, true, 302);
    exit;
}

function saas_csrf(): string
{
    if (empty($_SESSION['saas_csrf'])) {
        $_SESSION['saas_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['saas_csrf'];
}

function saas_check_csrf(): void
{
    $token = (string)($_POST['csrf'] ?? '');
    if (!hash_equals((string)($_SESSION['saas_csrf'] ?? ''), $token)) {
        http_response_code(419);
        exit('Invalid CSRF token');
    }
}

function saas_user_id(): int
{
    return (int)($_SESSION['user_id'] ?? 0);
}

function saas_authenticated(): bool
{
    return saas_user_id() > 0;
}

function saas_require_login(): void
{
    if (!saas_authenticated()) {
        saas_redirect('login.php');
    }
}

function saas_current_user(): array
{
    static $user = null;
    if ($user !== null) return $user;
    $id = saas_user_id();
    if ($id <= 0) return $user = [];
    $q = saas_db()->prepare("SELECT id,name,username,email,status,created_at FROM users WHERE id=? LIMIT 1");
    $q->execute([$id]);
    $user = $q->fetch() ?: [];
    if (($user['status'] ?? '') !== 'ACTIVE') {
        saas_logout();
        return [];
    }
    return $user;
}

function saas_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', (bool)$p['secure'], (bool)$p['httponly']);
    }
    session_destroy();
}

function saas_current_organization_id(): int
{
    return (int)($_SESSION['organization_id'] ?? 0);
}

function saas_current_tour_id(): int
{
    return (int)($_SESSION['tour_id'] ?? 0);
}

function saas_set_context(int $organizationId, int $tourId = 0): void
{
    $_SESSION['organization_id'] = $organizationId;
    $_SESSION['tour_id'] = $tourId;
}

function saas_organizations_for_user(int $userId): array
{
    $q = saas_db()->prepare(
        "SELECT o.*, om.role AS member_role
         FROM organizations o
         INNER JOIN organization_members om ON om.organization_id=o.id
         WHERE om.user_id=? AND om.status='ACTIVE' AND o.status='ACTIVE'
         ORDER BY o.name"
    );
    $q->execute([$userId]);
    return $q->fetchAll();
}

function saas_current_organization(): array
{
    static $org = null;
    if ($org !== null) return $org;
    $userId = saas_user_id();
    $orgId = saas_current_organization_id();
    if ($userId <= 0) return $org = [];

    if ($orgId > 0) {
        $q = saas_db()->prepare(
            "SELECT o.*, om.role AS member_role
             FROM organizations o
             INNER JOIN organization_members om ON om.organization_id=o.id
             WHERE o.id=? AND om.user_id=? AND om.status='ACTIVE' AND o.status='ACTIVE' LIMIT 1"
        );
        $q->execute([$orgId, $userId]);
        if ($row = $q->fetch()) return $org = $row;
    }

    $orgs = saas_organizations_for_user($userId);
    if (!$orgs) return $org = [];
    saas_set_context((int)$orgs[0]['id'], 0);
    return $org = $orgs[0];
}

function saas_require_organization(): int
{
    $org = saas_current_organization();
    if (!$org) {
        saas_redirect('organization_create.php');
    }
    return (int)$org['id'];
}

function saas_tours_for_user(int $userId, int $organizationId): array
{
    $q = saas_db()->prepare(
        "SELECT t.*, tm.role AS member_role
         FROM tours t
         INNER JOIN tour_members tm ON tm.tour_id=t.id
         WHERE t.organization_id=? AND tm.user_id=? AND tm.status='ACTIVE'
         ORDER BY t.start_date DESC, t.id DESC"
    );
    $q->execute([$organizationId, $userId]);
    return $q->fetchAll();
}

function saas_current_tour(): array
{
    static $tour = null;
    if ($tour !== null) return $tour;
    $orgId = saas_require_organization();
    $userId = saas_user_id();
    $tourId = saas_current_tour_id();

    if ($tourId > 0) {
        $q = saas_db()->prepare(
            "SELECT t.*, tm.role AS member_role
             FROM tours t INNER JOIN tour_members tm ON tm.tour_id=t.id
             WHERE t.id=? AND t.organization_id=? AND tm.user_id=? AND tm.status='ACTIVE' LIMIT 1"
        );
        $q->execute([$tourId, $orgId, $userId]);
        if ($row = $q->fetch()) return $tour = $row;
    }

    $tours = saas_tours_for_user($userId, $orgId);
    if (!$tours) return $tour = [];
    $_SESSION['tour_id'] = (int)$tours[0]['id'];
    return $tour = $tours[0];
}

function saas_require_tour(): int
{
    $tour = saas_current_tour();
    if (!$tour) saas_redirect('tour_create.php');
    return (int)$tour['id'];
}

function saas_role(): string
{
    return strtoupper((string)(saas_current_organization()['member_role'] ?? ''));
}

function saas_tour_role(): string
{
    return strtoupper((string)(saas_current_tour()['member_role'] ?? ''));
}

function saas_can(string $permission): bool
{
    $role = saas_tour_role() ?: saas_role();
    if ($role === 'OWNER') return true;

    $map = [
        'organization.manage' => ['OWNER','ADMIN'],
        'tour.create' => ['OWNER','ADMIN'],
        'tour.edit' => ['OWNER','ADMIN','MANAGER'],
        'tour.delete' => ['OWNER'],
        'member.manage' => ['OWNER','ADMIN'],
        'passenger.create' => ['OWNER','ADMIN','MANAGER','STAFF'],
        'passenger.edit' => ['OWNER','ADMIN','MANAGER','STAFF'],
        'passenger.delete' => ['OWNER','ADMIN'],
        'payment.create' => ['OWNER','ADMIN','MANAGER','STAFF'],
        'payment.edit' => ['OWNER','ADMIN','MANAGER'],
        'payment.delete' => ['OWNER','ADMIN'],
        'room.manage' => ['OWNER','ADMIN','MANAGER','STAFF'],
        'bus.manage' => ['OWNER','ADMIN','MANAGER'],
        'expense.create' => ['OWNER','ADMIN','MANAGER','STAFF'],
        'expense.edit' => ['OWNER','ADMIN','MANAGER'],
        'expense.delete' => ['OWNER','ADMIN'],
        'settings.manage' => ['OWNER','ADMIN'],
        'audit.view' => ['OWNER','ADMIN'],
    ];
    return in_array($role, $map[$permission] ?? [], true);
}

function saas_require_permission(string $permission): void
{
    if (!saas_can($permission)) {
        http_response_code(403);
        exit('403 Forbidden');
    }
}

function saas_audit(string $action, ?string $entityType = null, ?int $entityId = null, ?string $details = null): void
{
    try {
        $q = saas_db()->prepare(
            "INSERT INTO audit_log(user_id,organization_id,tour_id,action,entity_type,entity_id,details)
             VALUES(?,?,?,?,?,?,?)"
        );
        $q->execute([
            saas_user_id() ?: null,
            saas_current_organization_id() ?: null,
            saas_current_tour_id() ?: null,
            $action,
            $entityType,
            $entityId,
            $details,
        ]);
    } catch (Throwable $e) {
        error_log((string)$e);
    }
}

function saas_slug(string $value): string
{
    $value = trim(strtolower($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    return trim($value, '-') ?: 'tour';
}
