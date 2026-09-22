<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

$db=saas_db();
$ticketNo=trim((string)($_GET['ticket']??''));
$qr=trim((string)($_GET['q']??''));
if($ticketNo===''){http_response_code(400);exit('Ticket number is required.');}

$q=$db->prepare("SELECT ti.id,ti.ticket_number,ti.status,ti.issued_at,ti.voided_at,ti.qr_token_hash,pp.full_name,pp.phone,t.name tour_name,t.start_date,t.end_date,o.name organization_name FROM ticket_instances ti JOIN tour_passengers tp ON tp.id=ti.tour_passenger_id JOIN passenger_profiles pp ON pp.id=tp.passenger_profile_id JOIN tours t ON t.id=ti.tour_id JOIN organizations o ON o.id=ti.organization_id WHERE ti.ticket_number=? LIMIT 1");
$q->execute([$ticketNo]);
$ticket=$q->fetch();

$expected=$ticket?hash_hmac('sha256',$ticketNo,saas_app_key()):'';
$hasQr=preg_match('/^[a-f0-9]{64}$/',$qr)===1;
$qrMatches=$ticket && $hasQr && hash_equals((string)$ticket['qr_token_hash'],hash('sha256',$qr)) && hash_equals($expected,$qr);
$valid=$qrMatches && $ticket['status']==='ISSUED' && $ticket['voided_at']===null;

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Ticket Verification</title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#eef4f8;font-family:Inter,Arial,sans-serif;color:#12263f}
.wrap{max-width:650px;margin:45px auto;padding:0 16px}
.card{background:#fff;border:1px solid #dce7ef;border-radius:20px;padding:28px;box-shadow:0 14px 40px rgba(8,43,75,.09)}
.brand{font-weight:900;color:#0b2a49;letter-spacing:.5px;margin-bottom:6px}
h1{margin:0 0 20px;font-size:28px}
.status{font-size:18px;font-weight:800;padding:14px;border-radius:12px;margin-bottom:20px}
.valid{background:#ecfdf3;color:#067647;border:1px solid #b7ebca}
.invalid{background:#fef3f2;color:#b42318;border:1px solid #f3c2c7}
.info{padding:11px 13px;border-radius:10px;background:#fff8e8;color:#8a5700;border:1px solid #f4dfad;margin:-8px 0 20px;font-size:13px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.item{padding:12px;background:#f8fafc;border:1px solid #e7edf2;border-radius:10px}
.label{font-size:12px;color:#667085}
.value{font-weight:700;margin-top:3px;word-break:break-word}
@media(max-width:500px){.grid{grid-template-columns:1fr}.wrap{margin:20px auto}.card{padding:20px}}
</style>
</head>
<body>
<main class="wrap">
<div class="card">
<div class="brand">GoTM · GoZyraa Tour Management</div>
<h1>Ticket Verification</h1>
<?php if(!$ticket): ?>
  <div class="status invalid">INVALID — Ticket not found.</div>
  <p>Please check the ticket number.</p>
<?php else: ?>
  <div class="status <?=($valid?'valid':'invalid')?>"><?=($valid?'VALID TICKET':'VOID / INVALID TICKET')?></div>
  <?php if(!$hasQr): ?>
    <div class="info">Ticket found, but this page was opened without the QR verification token. Please scan the QR code printed on the ticket for full verification.</div>
  <?php elseif(!$qrMatches): ?>
    <div class="info">The ticket number exists, but the QR verification token does not match this ticket.</div>
  <?php endif; ?>
  <div class="grid">
    <div class="item"><div class="label">Ticket</div><div class="value"><?=saas_h($ticket['ticket_number'])?></div></div>
    <div class="item"><div class="label">Passenger</div><div class="value"><?=saas_h($ticket['full_name'])?></div></div>
    <div class="item"><div class="label">Tour</div><div class="value"><?=saas_h($ticket['tour_name'])?></div></div>
    <div class="item"><div class="label">Organization</div><div class="value"><?=saas_h($ticket['organization_name'])?></div></div>
    <div class="item"><div class="label">Phone</div><div class="value"><?=saas_h($ticket['phone']??'')?></div></div>
    <div class="item"><div class="label">Issued</div><div class="value"><?=saas_h($ticket['issued_at'])?></div></div>
  </div>
<?php endif; ?>
</div>
</main>
</body>
</html>