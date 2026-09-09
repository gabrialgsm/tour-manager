<?php
require __DIR__.'/bootstrap.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect('passengers.php');

$q = db()->prepare("SELECT p.*, b.name bus_name, b.bus_number, s.seat_no, t.name tour_name, t.start_date, t.end_date,
(SELECT r.room_no FROM room_assignments ra JOIN rooms r ON r.id=ra.room_id WHERE ra.passenger_id=p.id LIMIT 1) room_no
FROM passengers p
JOIN buses b ON b.id=p.bus_id
JOIN seats s ON s.id=p.seat_id
JOIN tours t ON t.id=p.tour_id
WHERE p.id = ?");
$q->execute([$id]);
$p = $q->fetch();
if (!$p) redirect('passengers.php');

$q = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE passenger_id=?");
$q->execute([$id]);
$paid = (float)$q->fetchColumn();
$due = max(0, (float)$p['final_fee'] - $paid);

$ticketBg = 'assets/ticket-template1.jpg';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=h($p['tour_name'])?> — Guest Ticket</title>
<?php include __DIR__.'/partials/head.php'; ?>

<!-- The artwork already contains all fixed labels. Oswald is used only for dynamic values. -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
.gmjs-ticket-page{
  padding:18px 12px 40px;
  background:#eef4f0;
  min-height:100vh;
}
.gmjs-actions{
  width:min(1280px,100%);
  margin:0 auto 12px;
  display:flex;
  gap:8px;
}
.gmjs-ticket{
  position:relative;
  width:min(1280px,100%);
  aspect-ratio:1983/793;
  margin:0 auto;
  overflow:hidden;
  border-radius:24px;
  box-shadow:0 15px 40px rgba(0,80,40,.12);
  background:#fff;
}
.gmjs-ticket-bg{
  position:absolute;
  inset:0;
  width:100%;
  height:100%;
  display:block;
  object-fit:fill;
  z-index:1;
}
.gmjs-data{
  position:absolute;
  z-index:3;
  pointer-events:none;
  font-family:'Oswald','Arial Narrow','Roboto Condensed',Arial,sans-serif;
  color:#075c35;
  font-synthesis:none;
  text-rendering:geometricPrecision;
}

/* Positions intentionally match the currently tuned ticket.php. */
.gmjs-bus-seat{
  left: 26.8%;
  top: 78.2%;
  width: 13%;
  text-align: center;
  font-size: clamp(13px, 1.45vw, 21px);
  font-weight:700;
  line-height:1.05;
  white-space:nowrap;
  color:#17231d;
}
.gmjs-bus-number{
  left: 26.7%;
  top: 83.5%;
  width: 13%;
  text-align:center;
  font-size:clamp(10px,.95vw,14px);
  font-weight:600;
  line-height:1;
  color:#075c35;
  white-space:nowrap;
}
.gmjs-room{
  left: 39.7%;
  top: 78.2%;
  width: 9.5%;
  text-align:center;
  font-size:clamp(13px,1.45vw,21px);
  font-weight:700;
  line-height:1.05;
  white-space:nowrap;
  color:#17231d;
}
.gmjs-room-type{
  left: 39.65%;
  top: 83.2%;
  width: 9.6%;
  text-align: center;
  font-size: clamp(12px, 1.30vw, 16px);
  font-weight: 500;
  line-height: 1;
  white-space:nowrap;
  color:#075c35;
}
.gmjs-due{
  left: 49.5%;
  top: 78.2%;
  width: 10.5%;
  text-align: center;
  font-size: clamp(13px, 1.35vw, 19px);
  font-weight: 700;
  line-height:1.05;
  white-space:nowrap;
  color:#075c35;
}
.gmjs-guest-name{
  left: 60.8%;
  top: 72%;
  width: 19%;
  text-align: center;
  font-size: clamp(18px, 2.20vw, 20px);
  font-weight:700;
  line-height:1.5;
  white-space:nowrap;
  overflow:hidden;
  text-overflow:ellipsis;
  color:#075c35;
}
.gmjs-guest-phone{
  left: 61%;
  top: 79.2%;
  width: 18.8%;
  text-align: center;
  font-size: clamp(11px, 2.45vw, 18px);
  font-weight: 500;
  line-height:1.1;
  color:#17231d;
  white-space:nowrap;
}

/* Existing blank QR frame in the artwork. */
.gmjs-qr{
  position:absolute;
  z-index:4;
  left: 84.7%;
  top: 40.7%;
  width: 12.28%;
  aspect-ratio:1/1;
  object-fit:contain;
  display:block;
  padding:0;
  margin:0;
}

@media(max-width:680px){
  .gmjs-ticket-page{padding:10px 6px 20px}
  .gmjs-ticket{border-radius:12px}
}

@media print{
  @page{size:A4 landscape;margin:0}
  html,body{
    margin:0!important;
    padding:0!important;
    background:#fff!important;
  }
  .no-print,.gmjs-actions{display:none!important}
  .gmjs-ticket-page{
    padding:0!important;
    background:#fff!important;
    min-height:0!important;
  }
  .gmjs-ticket{
    width:297mm!important;
    height:118.8mm!important;
    aspect-ratio:auto!important;
    margin:0!important;
    border-radius:0!important;
    box-shadow:none!important;
    page-break-after:always;
    break-inside:avoid;
  }
  .gmjs-ticket:last-child{page-break-after:auto}
  .gmjs-data{
    print-color-adjust:exact;
    -webkit-print-color-adjust:exact;
  }
}
</style>
</head>

<body>
<main class="gmjs-ticket-page">
  <div class="no-print gmjs-actions">
    <a class="btn secondary" href="passengers.php">Back</a>
    <button class="btn primary" onclick="printTicket()">Print Ticket</button>
  </div>

  <section class="gmjs-ticket" aria-label="GMJS Guest Ticket">
    <img class="gmjs-ticket-bg" src="<?=h($ticketBg)?>" alt="GMJS Ticket Design">

    <!-- Only dynamic values are printed. All labels/titles are part of the JPG artwork. -->
    <div class="gmjs-data gmjs-bus-seat"><?=h($p['bus_name'])?> · <?=h($p['seat_no'])?></div>

    <?php if(!empty($p['bus_number'])): ?>
      <div class="gmjs-data gmjs-bus-number"><?=h($p['bus_number'])?></div>
    <?php endif; ?>

    <div class="gmjs-data gmjs-room"><?=h($p['room_no'] ?? '—')?></div>
    <div class="gmjs-data gmjs-room-type"><?=h(room_label($p['room_type']))?></div>
    <div class="gmjs-data gmjs-due"><?=money($due)?></div>

    <div class="gmjs-data gmjs-guest-name"><?=h($p['name'])?></div>
    <div class="gmjs-data gmjs-guest-phone"><?=h($p['phone'])?></div>

    <img class="gmjs-qr" src="<?=h(qr_image_url($id,300))?>" alt="Guest QR Code">
  </section>
</main>

<script>
function printTicket(){
  // Wait for Oswald to finish loading so the printed ticket does not fall back to Arial.
  if (document.fonts && document.fonts.ready) {
    document.fonts.ready.then(() => window.print());
  } else {
    window.print();
  }
}
</script>
</body>
</html>
