<?php
require_once __DIR__.'/bootstrap.php';
if (!function_exists('setting')) {
    function setting(string $key, $default='') {
        try {
            $q = db()->prepare("SELECT setting_value FROM settings WHERE setting_key=? LIMIT 1");
            $q->execute([$key]);
            $v = $q->fetchColumn();
            return $v === false ? $default : $v;
        } catch (Throwable $e) {
            return $default;
        }
    }
}
require_login();
$busCount=(int)db()->query("SELECT COUNT(*) FROM buses WHERE active=1")->fetchColumn();
$seats=(int)db()->query("SELECT COALESCE(SUM(seat_count),0) FROM buses WHERE active=1")->fetchColumn();
$booked=(int)db()->query("SELECT COUNT(*) FROM passengers WHERE status='ACTIVE'")->fetchColumn();
$fee=(float)db()->query("SELECT COALESCE(SUM(final_fee),0) FROM passengers WHERE status='ACTIVE'")->fetchColumn();
$paid=(float)db()->query("SELECT COALESCE(SUM(amount),0) FROM payments")->fetchColumn();
$expense=(float)db()->query("SELECT COALESCE(SUM(amount),0) FROM expenses")->fetchColumn();
$due=max(0,$fee-$paid);
?><!doctype html><html><head><?php include __DIR__.'/partials/head.php';?><style>@media print{.no-print{display:none!important}}</style></head><body><main class="wrap"><div class="head no-print"><a class="btn secondary" href="dashboard.php">Back</a><button class="btn primary" onclick="window.print()">Print Summary</button></div><section class="card"><div class="eyebrow">GMJS TOUR SUMMARY</div><h1><?=h(setting('tour_name'))?></h1><p><?=h(setting('start_date'))?> → <?=h(setting('end_date'))?></p><div class="stats"><div class="stat"><span>Buses</span><b><?=$busCount?></b></div><div class="stat"><span>Total Seats</span><b><?=$seats?></b></div><div class="stat"><span>Booked</span><b><?=$booked?></b></div><div class="stat"><span>Available</span><b><?=$seats-$booked?></b></div><div class="stat"><span>Total Fee</span><b><?=money($fee)?></b></div><div class="stat"><span>Collected</span><b><?=money($paid)?></b></div><div class="stat"><span>Due</span><b><?=money($due)?></b></div><div class="stat"><span>Expense</span><b><?=money($expense)?></b></div></div><h2>Net Balance: <?=money($paid-$expense)?></h2></section></main></body></html>