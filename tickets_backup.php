<?php
require __DIR__.'/bootstrap.php';
require_login();

$ids = array_values(array_filter(array_map('intval', explode(',', $_GET['ids'] ?? ''))));
if (!$ids) redirect('passengers.php');

$ph = implode(',', array_fill(0, count($ids), '?'));
$q = db()->prepare("SELECT p.*, b.name bus_name, b.bus_number, s.seat_no, t.name tour_name, t.start_date, t.end_date,
(SELECT r.room_no FROM room_assignments ra JOIN rooms r ON r.id=ra.room_id WHERE ra.passenger_id=p.id LIMIT 1) room_no
FROM passengers p
JOIN buses b ON b.id=p.bus_id
JOIN seats s ON s.id=p.seat_id
JOIN tours t ON t.id=p.tour_id
WHERE p.id IN($ph)
ORDER BY b.id, s.row_no, s.col_no");
$q->execute($ids);
$rows = $q->fetchAll();

$logoFile = __DIR__.'/assets/GMJS_LOGO.png';
$logoUrl  = 'assets/GMJS_LOGO.png';

function ticket_date($date): string {
    if (!$date) return '';
    $ts = strtotime($date);
    return $ts ? date('d M Y', $ts) : $date;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>GMJS Tour — Guest Tickets</title>
<?php include __DIR__.'/partials/head.php'; ?>
<style>
/* Same visual system as ticket.php; each ticket is sized for 3-up A4 portrait printing. */
:root{--green:#006b38;--green2:#087a43;--gold:#b47b08;--ink:#10231b;--muted:#60756b;--line:#c9ddd0}
*{box-sizing:border-box}body{margin:0;background:#eef4f0;color:var(--ink);font-family:Inter,Arial,sans-serif}
.ticket-page{padding:18px 12px 40px}.ticket-actions{width:min(1200px,100%);margin:0 auto 12px;display:flex;gap:8px}.ticket-shell{position:relative;width:min(1200px,100%);margin:0 auto 14px;overflow:hidden;background:#fff;border:2px solid var(--green);border-radius:24px;box-shadow:0 15px 40px rgba(0,80,40,.12);height:480px}.ticket-shell:after{content:"";position:absolute;inset:0;pointer-events:none;background:radial-gradient(circle at 91% 10%,rgba(0,107,56,.06),transparent 17%),radial-gradient(circle at 95% 96%,rgba(0,107,56,.08),transparent 15%),linear-gradient(90deg,rgba(0,107,56,.035),transparent 18%)}.ticket-top{height:8px;background:linear-gradient(90deg,#00582f,#0b8145,#65c36d)}.ticket-inner{position:relative;z-index:2;display:grid;grid-template-columns:minmax(0,1fr) 270px;height:472px}.ticket-main{padding:30px 34px 22px 38px;display:flex;flex-direction:column}.brand-row{display:flex;align-items:center;gap:18px}.brand-logo{width:88px;height:88px;object-fit:contain;flex:0 0 88px}.brand-title{margin:0;color:var(--green);font-size:31px;line-height:1;font-weight:950;letter-spacing:.4px}.brand-sub{margin:7px 0 0;color:#111;font-size:17px;line-height:1;font-weight:850;letter-spacing:2px;text-transform:uppercase}.brand-tag{margin:11px 0 0;padding-top:9px;border-top:2px solid #285f43;color:var(--green);font-size:10px;font-weight:900;letter-spacing:2.3px}.tour-row{display:flex;align-items:flex-end;justify-content:space-between;gap:25px;margin-top:22px}.tour-kicker{color:var(--gold);font-size:12px;font-weight:900;letter-spacing:2px;text-transform:uppercase}.tour-name{margin:3px 0 0;color:var(--green);font-size:39px;line-height:.98;font-weight:1000;letter-spacing:1px;text-transform:uppercase}.tour-dest{margin:3px 0 0;font-size:16px;font-weight:850;color:#18221e}.ticket-label{text-align:right;white-space:nowrap}.ticket-label .pill{display:inline-block;background:var(--green);color:#fff;padding:10px 18px;border-radius:11px;font-size:16px;font-weight:950;letter-spacing:1.2px}.ticket-label strong{display:block;margin-top:10px;color:var(--green);font-size:17px;letter-spacing:2px}.gold-rule{height:3px;width:150px;margin-top:5px;background:var(--gold);margin-left:auto}.details{margin-top:auto;display:grid;grid-template-columns:1.05fr 1.25fr 1fr 1fr;border-top:1px solid var(--line);border-bottom:1px solid var(--line);padding:16px 0 12px}.detail{min-width:0;padding:0 16px;border-left:1px solid #cfded5;text-align:center}.detail:first-child{padding-left:0;border-left:0}.detail:last-child{padding-right:0}.detail-label{font-size:9px;font-weight:950;color:var(--green);letter-spacing:1.2px;text-transform:uppercase}.detail-value{margin-top:5px;font-size:15px;font-weight:950;line-height:1.2;color:#111;overflow-wrap:anywhere}.detail-small{margin-top:3px;font-size:10px;font-weight:700;color:#55675f}.guest-box{margin-top:16px;border:1px solid #a9c7b3;border-radius:13px;background:linear-gradient(135deg,#f4f8f4,#e9f2eb);padding:11px 18px;display:flex;align-items:center;justify-content:space-between;gap:20px}.guest-kicker{font-size:9px;font-weight:950;color:var(--green);letter-spacing:1.4px;text-transform:uppercase}.guest-name{margin:2px 0 0;font-size:25px;line-height:1.1;font-weight:1000;color:#0c5632}.guest-phone{margin:4px 0 0;font-size:12px;font-weight:750;color:#20332b}.guest-status{font-size:9px;font-weight:950;color:var(--gold);letter-spacing:1px;text-transform:uppercase;text-align:right}.guest-status strong{display:block;margin-top:3px;font-size:15px;color:#9c6200}.footer-line{margin-top:12px;text-align:center;color:#fff;background:var(--green);border-radius:10px;padding:8px 12px;font-family:"Brush Script MT","Segoe Script",cursive;font-size:18px;letter-spacing:.4px}.qr-panel{position:relative;border-left:2px dashed #9dbbaa;background:linear-gradient(145deg,#fbfdfb,#eef6f0);display:flex;flex-direction:column;align-items:center;justify-content:center;padding:28px 20px}.qr-heading{position:relative;z-index:2;color:var(--green);font-size:11px;font-weight:950;letter-spacing:2px;text-transform:uppercase;margin-bottom:12px}.qr-box{position:relative;z-index:2;background:#fff;border:4px solid var(--green);border-radius:14px;padding:9px;width:205px;height:205px;display:flex;align-items:center;justify-content:center;box-shadow:0 7px 20px rgba(0,70,35,.08)}.qr-ticket{width:181px;height:181px;object-fit:contain;display:block}.qr-note{position:relative;z-index:2;margin-top:12px;text-align:center;color:#52665c;font-size:9px;font-weight:800;line-height:1.35;letter-spacing:.7px;text-transform:uppercase}.qr-note strong{display:block;color:var(--green);font-size:11px;margin-top:4px}
@media(max-width:900px){.ticket-inner{grid-template-columns:minmax(0,1fr) 230px}.tour-name{font-size:32px}.brand-title{font-size:26px}.brand-sub{font-size:14px}.qr-box{width:175px;height:175px}.qr-ticket{width:151px;height:151px}}
@media(max-width:680px){.ticket-inner{grid-template-columns:1fr;height:auto}.ticket-shell{height:auto}.qr-panel{border-left:0;border-top:2px dashed #9dbbaa;min-height:260px}.ticket-main{padding:24px}.tour-row{align-items:flex-start;flex-direction:column}.ticket-label{text-align:left}.gold-rule{margin-left:0}.details{grid-template-columns:1fr 1fr}.detail{margin:7px 0}.detail:nth-child(3){border-left:0}.guest-box{align-items:flex-start;flex-direction:column}.guest-status{text-align:left}}
@media print{
 @page{size:A4 portrait;margin:0}html,body{background:#fff!important}body{margin:0!important}.no-print{display:none!important}.ticket-page{padding:0!important}.ticket-shell{width:210mm;height:82mm;max-width:none;margin:0;border:1.2mm solid var(--green);border-radius:4mm;box-shadow:none!important;break-inside:avoid;page-break-after:auto;overflow:hidden}.ticket-shell:nth-of-type(3n){page-break-after:always}.ticket-top{height:1.5mm}.ticket-inner{height:80.5mm;grid-template-columns:minmax(0,1fr) 46mm}.ticket-main{padding:4.8mm 5.5mm 3.3mm 6.5mm}.brand-row{gap:3.2mm}.brand-logo{width:18mm;height:18mm;flex-basis:18mm}.brand-title{font-size:6.7mm}.brand-sub{font-size:3.6mm;letter-spacing:.6mm}.brand-tag{margin-top:2mm;padding-top:1.8mm;font-size:2.1mm;letter-spacing:.65mm}.tour-row{margin-top:3.2mm;gap:4mm}.tour-kicker{font-size:2.2mm}.tour-name{font-size:8.3mm}.tour-dest{font-size:3.3mm}.ticket-label .pill{padding:2mm 3.3mm;border-radius:2mm;font-size:3.5mm}.ticket-label strong{margin-top:1.8mm;font-size:3.7mm;letter-spacing:.45mm}.gold-rule{width:28mm;height:.6mm;margin-top:1mm}.details{margin-top:3.5mm;padding:2.8mm 0 2mm}.detail{padding:0 3mm}.detail-label{font-size:2mm;letter-spacing:.25mm}.detail-value{font-size:3.5mm;margin-top:1mm}.detail-small{font-size:2.2mm;margin-top:.7mm}.guest-box{margin-top:2.7mm;border-radius:2.5mm;padding:2.1mm 3.2mm}.guest-kicker{font-size:1.9mm}.guest-name{font-size:5.3mm}.guest-phone{font-size:2.5mm;margin-top:.7mm}.guest-status{font-size:1.9mm}.guest-status strong{font-size:3.2mm}.footer-line{margin-top:2mm;padding:1.4mm 2mm;border-radius:2mm;font-size:3.8mm}.qr-panel{border-left:.5mm dashed #9dbbaa;padding:4mm 3mm}.qr-heading{font-size:2.3mm;margin-bottom:2mm}.qr-box{width:31mm;height:31mm;border-width:.7mm;border-radius:2.3mm;padding:1.4mm}.qr-ticket{width:27.5mm;height:27.5mm}.qr-note{margin-top:2mm;font-size:1.7mm}.qr-note strong{font-size:2.3mm;margin-top:.7mm}.ticket-top,.ticket-label .pill,.details,.guest-box,.footer-line,.qr-panel,.qr-box{print-color-adjust:exact;-webkit-print-color-adjust:exact}
}
</style>
</head>
<body>
<main class="ticket-page">
  <div class="no-print ticket-actions"><a class="btn secondary" href="passengers.php">Back</a><button class="btn primary" onclick="window.print()">Print All Tickets</button></div>
<?php foreach($rows as $p):
  $q2=db()->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE passenger_id=?");
  $q2->execute([$p['id']]);
  $paid=(float)$q2->fetchColumn();
  $due=max(0,(float)$p['final_fee']-$paid);
  $statusLabel=$due<=0.009?'PAID IN FULL':'PAYMENT DUE';
?>
  <section class="ticket-shell">
    <div class="ticket-top"></div>
    <div class="ticket-inner">
      <section class="ticket-main">
        <div class="brand-row">
          <?php if(is_file($logoFile)): ?><img class="brand-logo" src="<?=h($logoUrl)?>" alt="GMJS Logo"><?php endif; ?>
          <div><h1 class="brand-title">GOLLA MISSION</h1><div class="brand-sub">JUBO SANGHA</div><div class="brand-tag">FAITH &nbsp;•&nbsp; UNITY &nbsp;•&nbsp; SERVICE</div></div>
        </div>
        <div class="tour-row">
          <div><div class="tour-kicker">Tour 2026</div><div class="tour-name">KUAKATA</div><div class="tour-dest">&amp; SUNDARBAN</div></div>
          <div class="ticket-label"><span class="pill">GMJS TOUR 2026</span><strong>GUEST TICKET</strong><div class="gold-rule"></div></div>
        </div>
        <div class="details">
          <div class="detail"><div class="detail-label">Date</div><div class="detail-value"><?=h(ticket_date($p['start_date']))?><?php if(!empty($p['end_date'])):?> → <?=h(ticket_date($p['end_date']))?><?php endif;?></div></div>
          <div class="detail"><div class="detail-label">Bus / Seat</div><div class="detail-value"><?=h($p['bus_name'])?> · <?=h($p['seat_no'])?></div><?php if(!empty($p['bus_number'])):?><div class="detail-small"><?=h($p['bus_number'])?></div><?php endif;?></div>
          <div class="detail"><div class="detail-label">Room</div><div class="detail-value"><?=h($p['room_no']??'Not Assigned')?></div><div class="detail-small"><?=h(room_label($p['room_type']))?></div></div>
          <div class="detail"><div class="detail-label">Payment Due</div><div class="detail-value"><?=money($due)?></div><div class="detail-small"><?=h($statusLabel)?></div></div>
        </div>
        <div class="guest-box"><div><div class="guest-kicker">Guest</div><div class="guest-name"><?=h($p['name'])?></div><div class="guest-phone"><?=h($p['phone'])?></div></div><div class="guest-status">Official Tour Ticket<strong>GMJS TOUR 2026</strong></div></div>
        <div class="footer-line">Let’s Travel, Let’s Make Memories!</div>
      </section>
      <aside class="qr-panel"><div class="qr-heading">Scan to Verify</div><div class="qr-box"><img class="qr-ticket" src="<?=h(qr_image_url((int)$p['id'],260))?>" alt="Guest QR"></div><div class="qr-note">Keep this ticket with you during the tour<strong>Guest Verification</strong></div></aside>
    </div>
  </section>
<?php endforeach; ?>
</main>
</body>
</html>
