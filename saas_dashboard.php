<?php
require_once __DIR__ . '/bootstrap_saas.php';
saas_require_login();
$orgs = saas_organizations_for_user();
if (!$orgs) saas_redirect('organization_create.php');
$org = saas_current_organization();
if (!$org) { saas_set_current_organization((int)$orgs[0]['id']); $org = saas_current_organization(); }
$tours = saas_tours_for_user();
if (!$tours) saas_redirect('tour_create.php');
if (!saas_current_tour()) saas_set_current_tour((int)$tours[0]['id']);
$tour = saas_current_tour();
$db = saas_db();
$stats = ['passengers'=>0,'paid'=>0,'expenses'=>0];
$stmt=$db->prepare('SELECT COUNT(*) FROM tour_passengers WHERE tour_id=? AND status <> ?'); $stmt->execute([$tour['id'],'CANCELLED']); $stats['passengers']=(int)$stmt->fetchColumn();
$stmt=$db->prepare('SELECT COALESCE(SUM(amount),0) FROM payments WHERE tour_id=?'); $stmt->execute([$tour['id']]); $stats['paid']=(float)$stmt->fetchColumn();
$stmt=$db->prepare('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE tour_id=?'); $stmt->execute([$tour['id']]); $stats['expenses']=(float)$stmt->fetchColumn();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= saas_h($org['name']) ?> — Dashboard</title>
<style>body{font-family:Arial,sans-serif;background:#f5f7fb;margin:0;color:#172033}.top{background:#172033;color:#fff;padding:18px 28px;display:flex;justify-content:space-between;align-items:center}.wrap{max-width:1100px;margin:30px auto;padding:0 18px}.cards{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}.card,.panel{background:#fff;border-radius:14px;padding:20px;box-shadow:0 5px 20px #0000000d}.num{font-size:28px;font-weight:700;margin-top:8px}.nav{display:flex;gap:10px;flex-wrap:wrap;margin:20px 0}.nav a{background:#fff;padding:12px 15px;border-radius:10px;text-decoration:none;color:#172033}.muted{color:#667085}@media(max-width:700px){.cards{grid-template-columns:1fr}}</style></head>
<body><header class="top"><strong><?= saas_h($org['name']) ?></strong><span><?= saas_h($tour['name']) ?></span></header><main class="wrap"><h1>Tour dashboard</h1><p class="muted">Manage this tour from one place.</p><div class="cards"><div class="card">Passengers<div class="num"><?= $stats['passengers'] ?></div></div><div class="card">Payments received<div class="num"><?= number_format($stats['paid'],2) ?></div></div><div class="card">Expenses<div class="num"><?= number_format($stats['expenses'],2) ?></div></div></div><nav class="nav"><a href="bus_manage.php">Buses & seats</a><a href="passengers.php">Passengers</a><a href="rooms.php">Rooms</a><a href="payments.php">Payments</a><a href="expenses.php">Expenses</a><a href="tour_public.php">Public page</a></nav><section class="panel"><h2><?= saas_h($tour['name']) ?></h2><p><?= nl2br(saas_h($tour['description'] ?? '')) ?></p><p class="muted"><?= saas_h($tour['start_date'] ?? '') ?><?= !empty($tour['end_date']) ? ' — '.saas_h($tour['end_date']) : '' ?></p></section></main></body></html>
