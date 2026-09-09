<?php
/* GMJS Multi-Level Admin Access Control */
function current_admin(): array {
    static $admin = null;
    if ($admin !== null) return $admin;
    $id = (int)($_SESSION['admin_id'] ?? 0);
    if ($id <= 0) return $admin = [];
    $q = db()->prepare("SELECT * FROM admins WHERE id=? LIMIT 1");
    $q->execute([$id]);
    return $admin = ($q->fetch(PDO::FETCH_ASSOC) ?: []);
}
function admin_role(): string {
    return strtolower((string)(current_admin()['role'] ?? $_SESSION['admin_role'] ?? 'staff'));
}
function is_super_admin(): bool {
    return in_array(admin_role(), ['super_admin','main_admin','superadmin'], true);
}
function is_admin(): bool {
    return is_super_admin() || admin_role() === 'admin';
}
function is_manager(): bool {
    return is_admin() || admin_role() === 'manager';
}
function can(string $permission): bool {
    if (is_super_admin()) return true;
    $map = [
        'passenger.create' => ['staff','manager','admin'],
        'passenger.edit' => ['staff','manager','admin'],
        'passenger.delete' => [],

        'payment.create' => ['staff','manager','admin'],
        'payment.edit' => ['manager','admin'],
        'payment.delete' => [],

        'room.create' => ['staff','manager','admin'],
        'room.edit' => ['manager','admin'],
        'room.delete' => [],

        'bus.create' => ['manager','admin'],
        'bus.edit' => ['manager','admin'],
        'bus.delete' => [],

        'seat.move' => ['staff','manager','admin'],
        'seat.swap' => ['staff','manager','admin'],

        'expense.create' => ['staff','manager','admin'],
        'expense.edit' => ['manager','admin'],
        'expense.delete' => [],

        'checkin' => ['staff','manager','admin'],
        'backup' => ['admin'],
        'audit.view' => ['admin'],
        'user.manage' => [],
        'tour.create' => [],
        'tour.edit' => ['admin'],
        'tour.delete' => [],
        'tour.manage' => ['admin'],
        'settings.manage' => ['admin'],
    ];
    return in_array(admin_role(), $map[$permission] ?? [], true);
}
function require_permission(string $permission): void {
    if (!can($permission)) {
        http_response_code(403);
        exit('403 Forbidden — You do not have permission for this action.');
    }
}
function require_super_admin(): void { require_permission('user.manage'); }
