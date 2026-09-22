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

ob_start();
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
require_once $root . '/bootstrap.php';

test_assert(saas_slug('Sylhet Tour 2026!') === 'sylhet-tour-2026', 'slug helper normalizes public slugs');
test_assert(saas_slug('  A__B  ') === 'a-b', 'slug helper collapses separators');
test_assert(saas_slug('!!!') === 'tour', 'slug helper provides a safe fallback');
test_assert(saas_h('<script>alert(1)</script>') === '&lt;script&gt;alert(1)&lt;/script&gt;', 'HTML helper escapes markup');

// -------------------------------------------------------------------------
// Security contract tests. These catch accidental removal of critical
// controls during future refactors even without a live database.
// -------------------------------------------------------------------------
$csrfFiles = [
    'booking_link.php',
    'passengers.php',
    'payments.php',
    'payment_intents.php',
    'registrations.php',
    'feature_options.php',
    'features.php',
    'tour_settings.php',
    'ticket_issue.php',
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
    'passengers.php' => 'passenger.create',
    'payments.php' => 'payment.create',
    'payment_intents.php' => 'payment.create',
    'registrations.php' => 'passenger.create',
    'features.php' => 'settings.manage',
    'feature_options.php' => 'settings.manage',
    'tour_settings.php' => 'tour.edit',
    'ticket_issue.php' => 'passenger.create',
];
foreach ($permissionContracts as $file => $permission) {
    $text = file_text($file);
    test_assert($text !== '' && str_contains($text, "saas_require_permission('{$permission}')"), "Permission guard {$permission}: {$file}");
}

$securityContracts = [
    'booking_link.php' => ["hash('sha256'", 'random_bytes(32)', 'booking_access_token_hash'],
    'passenger_auth.php' => ['password_verify(', 'passenger_auth_rate_limited('],
    'passenger_google_auth.php' => ['random_bytes(32)', 'passenger_oauth_rate_limited(', 'hash_equals('],
    'passenger_facebook_auth.php' => ['random_bytes(32)', 'passenger_oauth_rate_limited(', 'hash_equals('],
    'passenger_password_reset.php' => ['random_bytes(32)', 'password_hash(', 'passenger_reset_rate_limited('],
    'public_seat_select.php' => ['FOR UPDATE', 'beginTransaction', 'commit'],
    'public_room_select.php' => ['FOR UPDATE', 'beginTransaction', 'commit'],
    'public_features.php' => ['FOR UPDATE', 'unit_price', 'total_price'],
    'public_checkout.php' => ['payment_intents', 'FOR UPDATE', 'feature_total'],
    'payment_intents.php' => ['FOR UPDATE', 'SUCCEEDED', 'saas_issue_ticket('],
    'ticket_service.php' => ['hash_hmac', 'qr_token_hash', 'forceReissue'],
    'ticket_verify.php' => ['hash_equals(', 'hash_hmac', "['status']==='ISSUED'", 'voided_at'],
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
$registrationText = file_text('registrations.php');
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
// Passenger/Auth + booking UX contracts.
foreach (['passenger_auth.php','passenger_dashboard.php','public_booking.php','public_seat_select.php','public_room_select.php','public_features.php','public_checkout.php','passenger_password_reset.php'] as $uxFile) {
    $ux = file_text($uxFile);
    test_assert($ux !== '' && str_contains($ux, '<meta name="viewport"'), "Passenger/booking UX is responsive: {$uxFile}");
    test_assert($ux !== '' && str_contains($ux, 'font-family:'), "Passenger/booking UX has dedicated visual styling: {$uxFile}");
}
$bookingUx = file_text('public_booking.php');
test_assert(
    $bookingUx !== '' &&
    has_all($bookingUx, ['My Booking','Select seat','Select room','Payment','Your ticket']),
    'Booking portal exposes the complete passenger action flow'
);

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
    $verifyText !== '' &&
    str_contains($verifyText, "['status']==='ISSUED'") &&
    str_contains($verifyText, 'voided_at'),
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

// Production schema-parity contracts. These mirror the migration/code fields that
// previously caused live 500 errors on legacy installations.
$parityMigration = file_text('database/migrations/018_schema_parity_legacy_installations.sql');
test_assert(
    $parityMigration !== '' &&
    has_all($parityMigration, [
        'organization_id',
        'tour_id',
        'bus_number',
        'seat_code',
        'payment_reference',
        'transaction_reference',
        'AVAILABLE',
        'BLOCKED',
    ]),
    'Production schema parity migration covers rooms, buses, seats and payments'
);

$busText = file_text('buses.php');
test_assert(
    $busText !== '' &&
    has_all($busText, [
        'INSERT INTO buses(tour_id,name,bus_number',
        '$seatOrgColumn',
        '$seatTourColumn',
        '$seatCodeColumn',
        '$seatNoColumn',
        'FRONT_SINGLE',
        "'LEFT'",
        "'RIGHT'",
        "'LAST'",
    ]) &&
    (
        str_contains($busText, "if (\$seatCodeColumn) {") ||
        str_contains($busText, "if(\$seatCodeColumn){")
    ) &&
    (
        str_contains($busText, "if (\$seatNoColumn) {") ||
        str_contains($busText, "if(\$seatNoColumn){")
    ),
    'Bus/seat management writes canonical and legacy-compatible columns and positions'
);

$roomText = file_text('rooms.php');
test_assert(
    $roomText !== '' &&
    str_contains($roomText, 'INSERT INTO rooms(organization_id,tour_id,room_no,room_type,capacity,notes,status)'),
    'Room creation writes the organization_id required by legacy production schema'
);

$paymentText = file_text('payments.php');
test_assert(
    $paymentText !== '' &&
    has_all($paymentText, [
        'INSERT INTO payments(organization_id,tour_id,tour_passenger_id,payment_reference',
        'transaction_reference',
        'random_bytes(4)',
    ]) &&
    !str_contains($paymentText, 'INSERT INTO payments(tour_id,tour_passenger_id,amount,payment_method,reference'),
    'Admin payment creation uses payment_reference/transaction_reference instead of the obsolete reference column'
);

$paymentIntentText = file_text('payment_intents.php');
test_assert(
    $paymentIntentText !== '' &&
    str_contains($paymentIntentText, 'INSERT INTO payments(organization_id,tour_id,tour_passenger_id,payment_reference'),
    'Confirmed payment requests use the production payment columns'
);

test_assert(
    file_text('expenses.php') !== '',
    'Dashboard Expenses link has a deployed target page'
);

$roomStatusMigration = file_text('database/migrations/019_normalize_room_status.sql');
test_assert(
    $roomStatusMigration !== '' &&
    has_all($roomStatusMigration, [
        'gotm_status_legacy',
        "ENUM('AVAILABLE','BLOCKED')",
        "'ACTIVE','AVAILABLE'",
        "'INACTIVE','BLOCKED'",
    ]),
    'Legacy room status values are normalized safely before application writes'
);

$roomContextText = file_text('rooms.php');
test_assert(
    $roomContextText !== '' &&
    has_all($roomContextText, [
        "isset(\$_POST['tour_id'])",
        'saas_set_context($orgId, $postedTourId)',
        'name="tour_id"',
    ]),
    'Room forms preserve and validate the active tour context across POST requests'
);
test_assert(
    $roomContextText !== '' &&
    has_all($roomContextText, [
        "move_room_guest",
        "data-room-drop",
        'draggable="true"',
        "data-passenger-id",
        "is full.",
        "room.moved",
    ]),
    'Room guests support drag-and-drop moves with capacity checks and audit logging'
);

// Accidental committed PHP error log must stay out of the repository.
test_assert(!is_file($root . '/storage/php-error.log'), 'Committed PHP error log is absent');

$mailText = file_text('mail.php');
test_assert(
    $mailText !== '' &&
    has_all($mailText, ['stream_socket_client', 'stream_socket_enable_crypto', 'AUTH PLAIN', 'AUTH LOGIN']),
    'Production SMTP mailer supports authenticated TLS delivery'
);
$resetText = file_text('passenger_password_reset.php');
test_assert(
    $resetText !== '' &&
    str_contains($resetText, "require __DIR__.'/mail.php';") &&
    str_contains($resetText, 'saas_send_email(') &&
    !str_contains($resetText, '$resetUrl</div>') &&
    !str_contains($resetText, 'Email delivery is not configured yet'),
    'Password reset uses email delivery without exposing reset URLs in the web response'
);



// -------------------------------------------------------------------------
 // SaaS billing / entitlement contracts.
 // -------------------------------------------------------------------------
 $billingSchema=file_text('database/migrations/015_saas_billing_entitlements.sql');
 test_assert($billingSchema!=='' && has_all($billingSchema,['saas_plans','saas_plan_entitlements','organization_subscriptions','billing_invoices','billing_events','uq_org_subscription']), 'SaaS billing schema has plans, entitlements, subscriptions, invoices and idempotent events');
 $entText=file_text('entitlements.php');
 test_assert($entText!=='' && has_all($entText,['saas_entitlement(','saas_has_entitlement(','saas_require_entitlement(','saas_require_limit(','saas_billing_usage']), 'Central entitlement service exposes plan checks and usage limits');
 $billingText=file_text('billing.php');
 test_assert($billingText!=='' && has_all($billingText,['organization.manage','saas_plan(','saas_entitlement(','billing_events','saas_check_csrf()']), 'Billing UI is organization-admin protected, CSRF guarded and audit/event ready');
 $tourCreateText=file_text('tour_create.php');
test_assert(str_contains($tourCreateText,'$slugInput=trim') && str_contains($tourCreateText,'id="slug"') && str_contains($tourCreateText,'makeSlug'),'Tour create auto-generates an editable public slug');

 test_assert($tourCreateText!=='' && str_contains($tourCreateText, 'saas_require_limit($orgId,\'max_tours\''), 'Tour creation enforces centralized max_tours entitlement');
 $featureSettingsText=file_text('features.php');
 test_assert($featureSettingsText!=='' && str_contains($featureSettingsText, 'saas_require_entitlement($orgId,\'custom_features\''), 'Custom tour features enforce centralized entitlement');
 $publicText=file_text('tour_settings.php');
 test_assert($publicText!=='' && str_contains($publicText, 'saas_has_entitlement($orgId,\'custom_branding\')'), 'Custom public-page branding enforces centralized entitlement');
 
// -------------------------------------------------------------------------
// Super Admin console contracts.
// -------------------------------------------------------------------------
$superAdminText = file_text('super_admin.php');
test_assert(
    $superAdminText !== '' &&
    has_all($superAdminText, ['super_admins', 'saas_check_csrf()', 'super_admin.subscription_status', 'current_period_end', "status IN ('ACTIVE','TRIALING')", '$stats[\'mrr\']']),
    'Super Admin console has access control, CSRF, subscription status controls and MRR calculation'
);
$superOrgText = file_text('super_admin_organization.php');
test_assert(
    $superOrgText !== '' &&
    has_all($superOrgText, ['super_admins', 'organization_members', 'organization_subscriptions', 'billing_invoices', 'saas_plan_entitlements', 'audit_log']),
    'Super Admin organization detail view exposes team, subscription, entitlements, invoices and audit history'
);

// -------------------------------------------------------------------------
// Extended Super Admin SaaS controls.
// -------------------------------------------------------------------------
$superPlansText = file_text('super_admin_plans.php');
test_assert(
    $superPlansText !== '' &&
    has_all($superPlansText, ['super_admins', 'saas_check_csrf()', 'saas_plan_entitlements', 'ON DUPLICATE KEY UPDATE', 'super_admin.entitlement_updated']),
    'Super Admin can securely manage plan entitlements'
);
$expiryText = file_text('bin/subscription_expiry.php');
test_assert(
    $expiryText !== '' &&
    has_all($expiryText, ['PHP_SAPI', 'PAST_DUE', 'current_period_end', 'NOW()']),
    'Subscription expiry maintenance job marks overdue subscriptions PAST_DUE'
);

// -------------------------------------------------------------------------
// Billing operations contracts.
// -------------------------------------------------------------------------
$superBillingText = file_text('super_admin_billing.php');
test_assert(
    $superBillingText !== '' &&
    has_all($superBillingText, ['super_admins', 'saas_check_csrf()', 'billing_invoices', 'invoice_create', 'invoice_status', 'super_admin.invoice_created']),
    'Super Admin invoice management is protected and auditable'
);

// -------------------------------------------------------------------------
// Organization / team management contracts.
// -------------------------------------------------------------------------

$teamText = file_text('team.php');
test_assert(
    $teamText !== '' &&
    has_all($teamText, ['saas_require_permission(\'member.manage\')', 'saas_check_csrf()', 'organization_members', 'FOR UPDATE', 'organization.owner_transferred']),
    'Organization team management has permission, CSRF, scoped locking and ownership-transfer controls'
);
$tourTeamText = file_text('tour_team.php');
test_assert(
    $tourTeamText !== '' &&
    has_all($tourTeamText, ['saas_require_permission(\'member.manage\')', 'saas_check_csrf()', 'tour_members', 'organization_id', 'FOR UPDATE']),
    'Tour team management is organization-scoped with permission, CSRF and row locking'
);
test_assert(
    $tourTeamText !== '' &&
    str_contains($tourTeamText, "['role']==='OWNER'") &&
    str_contains($tourTeamText, 'cannot be removed'),
    'Tour owner cannot be removed through team management'
);

// -------------------------------------------------------------------------
// Tour hub and dashboard UX contracts.
// -------------------------------------------------------------------------
$hubText=file_text('tours.php');
test_assert($hubText!=='' && has_all($hubText,['Current plan','Registered tours','Create tour','Open dashboard','billing.php','team.php','account_settings.php','logout.php']),'Tour hub exposes account summary, tour actions and settings navigation');
$createText=file_text('tour_create.php');
test_assert($createText!=='' && has_all($createText,['default_transport','banner_image','tour_settings','tour.created']),'Tour creation stores default transport and dashboard banner settings');
$featuresText=file_text('features.php');
test_assert(
    $featuresText!=='' &&
    has_all($featuresText,['add_custom','update_custom','delete_custom','custom_label','custom_description','name="enabled"','Save','My custom features']),
    'Tour features supports unlimited custom features with independent enable and disable controls'
);
$featureHelperText=file_text('feature_helpers.php');
test_assert(
    $featureHelperText!=='' &&
    str_contains($featureHelperText,"\$r['config']['label']??\$c['label']"),
    'Enabled custom features use their saved display label and description'
);
$ticketDesignText=file_text('ticket_design.php');
$ticketPrintText=file_text('tickets_print_all.php');
$ticketViewText=file_text('ticket.php');
test_assert(
    $ticketDesignText!=='' && has_all($ticketDesignText,['720 × 350 px','A4 portrait','3 tickets per sheet','canvas_width','canvas_height','Available fields','On canvas','demo','resize-handle','removeField']),
    'Ticket visual designer supports removable/re-addable fields, demo data and resizing'
);
test_assert(
    $ticketPrintText!=='' && has_all($ticketPrintText,['array_chunk($rows,3)','A4 portrait','grid-template-rows:repeat(3,92mm)','row-gap:5mm']),
    'All tickets print three per A4 portrait sheet with cutting gaps'
);
test_assert(
    $ticketViewText!=='' && has_all($ticketViewText,['3up','three-up-sheet','3 tickets / A4 portrait']),
    'Single ticket view supports three-up A4 portrait printing'
);
$expenseText=file_text('expenses.php');
test_assert(
    $expenseText!=='' &&
    has_all($expenseText,['update_expense','expense.edit','data-edit-expense','Edit expense','Save changes']),
    'Expense history supports permission-protected editing'
);
$dashText=file_text('dashboard.php');
test_assert(
    $dashText!=='' &&
    has_all($dashText,['Tour note','Schedule','Bus seat plan','Accommodation','Recent passengers','rooms.php','seat_plan.php','buses.php','data-bus-tab','seatModal','dragstart']),
    'Tour dashboard exposes shared-shell operations, default bus seat plan and passenger seat assignment'
);
$shellText=file_text('assets/app-shell.js');
test_assert($shellText!=='' && has_all($shellText,['gm-sidebar','payments.php','expenses.php','tour_team.php','tickets.php','income.php','features.php','Settings','Logout']),'Shared workspace shell provides the dashboard sidebar navigation');
$incomeText=file_text('income.php');
test_assert($incomeText!=='' && has_all($incomeText,['information_schema.COLUMNS','organization_id','INSERT INTO incomes']),'Income insert supports legacy organization_id schema');
$seatAssignText=file_text('seat_assign.php');
test_assert(
    $seatAssignText!=='' &&
    has_all($seatAssignText,['source_seat_id','target_seat_id','seat.changed','Swapped']),
    'Seat assignment endpoint supports drag-and-drop seat changes and swaps'
);
$openText=file_text('tour_open.php');
test_assert($openText!=='' && has_all($openText,['tour_members','saas_set_context','dashboard.php']),'Tour opening is scoped to an organization member before changing context');
$accountText=file_text('account_settings.php');
test_assert($accountText!=='' && has_all($accountText,['saas_check_csrf()','UPDATE users SET name=?']),'Account settings are protected by CSRF and update the signed-in user only');
$publicUrlsText=file_text('public_urls.php');
test_assert($publicUrlsText!=='' && has_all($publicUrlsText,['tour_open.php','tour_settings.php','Public URLs']),'Public URL hub lists only accessible organization tours');

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
$tourCreateText=file_text('tour_create.php');$tourEditText=file_text('tour_edit.php');
test_assert($tourCreateText!==''&&has_all($tourCreateText,['multipart/form-data','banner_upload','logo_upload','departure_options','1600 × 600','500 × 150','upload_tour_image']),'Tour create supports image uploads and departure options');
test_assert($tourEditText!==''&&has_all($tourEditText,['multipart/form-data','banner_upload','logo_upload','departure_options','1600 × 600','500 × 150','upload_tour_image']),'Tour edit supports image uploads and departure options');
test_assert(has_all($tourEditText,['slugInput','Public URL / slug','UPDATE tours SET name=?,slug=?']),'Tour edit exposes and saves the public slug');
$passengerText=file_text('passengers.php');
test_assert($passengerText!=='' && has_all($passengerText,['gotm-logo.png','features.php','expenses.php','income.php','tickets.php','tour_team.php','recentProfiles','reusable-list','departureOptions','Select departure point']),'Passenger page uses the shared-style sidebar and shows reusable passenger profiles');
$toursText=file_text('tours.php');
test_assert($toursText!=='' && has_all($toursText,['gotm-logo.png','settingsBtn','settingsMenu','tour_edit.php','public_urls.php','billing.php','team.php','account_settings.php','logout.php']),'Tours page uses the GoTM sidebar and settings menu');
test_assert(substr($toursText,strpos($toursText,'<div class="brand">'),250)===false,'Tours sidebar does not duplicate the GoTM logo markup');
$settingsText=file_text('settings.php');
test_assert($settingsText!=='' && has_all($settingsText,['saas_require_login','saas_require_permission','tour_edit.php']),'Settings entry uses the current SaaS auth and tour settings flow');
$shellText=file_text('assets/app-shell.js');
test_assert($shellText!=='' && has_all($shellText,['gm-settings-wrap','Public URLs','Billing','Organization Team','Account Settings']),'Shared sidebar settings dropdown exposes workspace settings links');
