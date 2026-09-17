<?php
declare(strict_types=1);

/**
 * Tour Manager lightweight automated regression suite.
 *
 * This suite intentionally has no external dependency so it can run on a
 * fresh PHP installation and in CI. Database-backed integration tests can be
 * added later behind TEST_DB_* environment variables without blocking the
 * syntax/security regression gate.
 */

$root = dirname(__DIR__);
$failures = [];
$passed = 0;

function test_assert(bool $condition, string $message): void
{
    global $failures, $passed;
    if ($condition) {
        $passed++;
        echo "PASS  {$message}\n";
        return;
    }
    $failures[] = $message;
    echo "FAIL  {$message}\n";
}

function file_text(string $relative): string
{
    global $root;
    $path = $root . DIRECTORY_SEPARATOR . $relative;
    return is_file($path) ? (string) file_get_contents($path) : '';
}

function has_all(string $text, array $needles): bool
{
    foreach ($needles as $needle) {
        if (!str_contains($text, $needle)) {
            return false;
        }
    }
    return true;
}

echo "Tour Manager automated tests\n";
echo str_repeat('=', 32) . "\n";

// -------------------------------------------------------------------------
// PHP syntax gate: every application PHP file must parse successfully.
// -------------------------------------------------------------------------
$phpFiles = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $path = $file->getPathname();
    if (str_contains($path, DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR)) {
        continue;
    }
    $phpFiles[] = $path;
}
sort($phpFiles);

test_assert(count($phpFiles) > 20, 'PHP application files discovered');
foreach ($phpFiles as $path) {
    $output = [];
    $code = 0;
    exec('php -l ' . escapeshellarg($path) . ' 2>&1', $output, $code);
    test_assert($code === 0, 'PHP syntax: ' . ltrim(str_replace($root, '', $path), DIRECTORY_SEPARATOR));
}

// -------------------------------------------------------------------------
// Pure helper regression checks.
// -------------------------------------------------------------------------
$_SERVER['HTTPS'] = 'off';
require_once $root . '/bootstrap_saas.php';

test_assert(saas_slug('Sylhet Tour 2026!') === 'sylhet-tour-2026', 'slug helper normalizes public slugs');
test_assert(saas_slug('  A__B  ') === 'a-b', 'slug helper collapses separators');
test_assert(saas_slug('!!!') === 'tour', 'slug helper provides a safe fallback');
test_assert(saas_h('<script>alert(1)</script>') === '&lt;script&gt;alert(1)&lt;/script&gt;', 'HTML helper escapes markup');

// -------------------------------------------------------------------------
// Security contract tests. These catch accidental removal of critical
// controls during future refactors even without a live database.
// -------------------------------------------------------------------------
$csrfFiles = [
    'saas_booking_link.php',
    'saas_passengers.php',
    'saas_payments.php',
    'saas_payment_intents.php',
    'saas_registrations.php',
    'saas_feature_options.php',
    'saas_features.php',
    'saas_public.php',
    'saas_ticket_issue.php',
    'public_tour.php',
    'public_seat_select.php',
    'public_room_select.php',
    'public_features.php',
    'public_checkout.php',
];
foreach ($csrfFiles as $file) {
    $text = file_text($file);
    test_assert($text !== '' && str_contains($text, 'saas_check_csrf()'), "CSRF guard present: {$file}");
}

$permissionContracts = [
    'saas_passengers.php' => 'passenger.create',
    'saas_payments.php' => 'payment.create',
    'saas_payment_intents.php' => 'payment.create',
    'saas_registrations.php' => 'passenger.create',
    'saas_features.php' => 'settings.manage',
    'saas_feature_options.php' => 'settings.manage',
    'saas_public.php' => 'tour.edit',
    'saas_ticket_issue.php' => 'passenger.create',
];
foreach ($permissionContracts as $file => $permission) {
    $text = file_text($file);
    test_assert($text !== '' && str_contains($text, "saas_require_permission('{$permission}')"), "Permission guard {$permission}: {$file}");
}

$securityContracts = [
    'saas_booking_link.php' => ["hash('sha256'", 'random_bytes(32)', 'booking_access_token_hash'],
    'passenger_auth.php' => ['password_verify(', 'passenger_auth_rate_limited('],
    'passenger_google_auth.php' => ['random_bytes(32)', 'passenger_auth_rate_limited('],
    'passenger_facebook_auth.php' => ['random_bytes(32)', 'passenger_auth_rate_limited('],
    'passenger_password_reset.php' => ['random_bytes(32)', 'password_hash(', 'passenger_auth_rate_limited('],
    'public_seat_select.php' => ['FOR UPDATE', 'beginTransaction', 'commit'],
    'public_room_select.php' => ['FOR UPDATE', 'beginTransaction', 'commit'],
    'public_features.php' => ['FOR UPDATE', 'unit_price', 'total_price'],
    'public_checkout.php' => ['payment_intents', 'FOR UPDATE', 'feature_total'],
    'saas_payment_intents.php' => ['FOR UPDATE', 'SUCCEEDED', 'saas_issue_ticket('],
    'saas_ticket_service.php' => ['hash_hmac', 'qr_token_hash', 'forceReissue'],
    'ticket_verify.php' => ['hash_equals(', 'hash_hmac', "status='ISSUED'"],
];
foreach ($securityContracts as $file => $needles) {
    $text = file_text($file);
    test_assert($text !== '' && has_all($text, $needles), "Security contract: {$file}");
}

// Feature pricing must be server-derived; reject a frontend-supplied price
// field as the authoritative amount.
$featureText = file_text('public_features.php');
test_assert(
    $featureText !== '' &&
    !preg_match('/\$_POST\s*\[[\'\"](?:price|unit_price|total_price)[\'\"]\]/', $featureText),
    'Feature pricing is not trusted from POST input'
);

// Cancellation must revoke booking access and release inventory.
$registrationText = file_text('saas_registrations.php');
test_assert(
    $registrationText !== '' &&
    has_all($registrationText, [
        'booking_access_token_revoked_at=NOW()',
        'DELETE FROM passenger_seat_assignments',
        'DELETE FROM room_assignments',
    ]),
    'Registration cancellation revokes access and releases seat/room inventory'
);

// Payment records must not be created by a public checkout before confirmation.
$checkoutText = file_text('public_checkout.php');
test_assert(
    $checkoutText !== '' &&
    str_contains($checkoutText, 'INSERT INTO payment_intents') &&
    !str_contains($checkoutText, 'INSERT INTO payments'),
    'Public checkout creates payment intents, not received-payment records'
);

// Ticket verification must reject revoked/voided tickets.
$verifyText = file_text('ticket_verify.php');
test_assert(
    $verifyText !== '' && str_contains($verifyText, "status='ISSUED'") && str_contains($verifyText, 'voided_at'),
    'Ticket verification checks issued/non-voided status'
);

// Migration files should be uniquely versioned and non-empty.
$migrationDir = $root . '/database/migrations';
$migrations = glob($migrationDir . '/*.sql') ?: [];
$versions = [];
foreach ($migrations as $migration) {
    $name = basename($migration, '.sql');
    $versions[] = $name;
    test_assert((int) filesize($migration) > 20, "Migration is non-empty: {$name}");
}
test_assert(count($versions) === count(array_unique($versions)), 'Migration filenames are unique');
test_assert(in_array('014_performance_indexes', $versions, true), 'Performance migration is present');
test_assert(in_array('013_migration_tracking', $versions, true), 'Migration tracking is present');

// Accidental committed PHP error log must stay out of the repository.
test_assert(!is_file($root . '/storage/php-error.log'), 'Committed PHP error log is absent');

// -------------------------------------------------------------------------
// Result.
// -------------------------------------------------------------------------
echo "\n" . str_repeat('-', 32) . "\n";
echo "Passed: {$passed}\n";
echo "Failed: " . count($failures) . "\n";

if ($failures) {
    echo "\nFailures:\n";
    foreach ($failures as $failure) {
        echo "- {$failure}\n";
    }
    exit(1);
}

echo "All automated tests passed.\n";
