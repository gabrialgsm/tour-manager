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

$ticketBg = 'assets/ticket-template1.jpg';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>GMJS Tour — Guest Tickets</title>
<?php include __DIR__.'/partials/head.php'; ?>

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
  margin:0 auto 14px;
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

.gmjs-qr{
  position:absolute;
  z-index: 4;
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
  /*
   * IMPORTANT:
   * The ticket itself is NOT redesigned for print.
   * It keeps the exact 1983:793 artwork ratio and all
   * percentage-based positions from the normal screen CSS.
   * Only the outer print sheet is changed so 2 tickets fit
   * on one A4 page.
   */
  @page{
    size:A4 portrait;
    margin:0;
  }

  html,body{
    margin:0!important;
    padding:0!important;
    background:#fff!important;
  }

  .no-print,
  .gmjs-actions{
    display:none!important;
  }

  .gmjs-ticket-page{
    width:210mm!important;
    margin:0!important;
    padding:0!important;
    background:#fff!important;
    min-height:0!important;
  }

  .gmjs-ticket{
    /*
     * 210mm × 84mm = exact 1983:793 proportion.
     * Do NOT change the internal ticket coordinates.
     */
    width:210mm!important;
    height:84mm!important;
    aspect-ratio:1983/793!important;
    margin:0 0 5mm!important;
    border-radius:0!important;
    box-shadow:none!important;
    overflow:hidden!important;

    /*
     * Each ticket occupies exactly one 84mm print slot.
     * Two slots = 168mm, leaving 42mm of unused A4 height.
     */
    page-break-after:auto!important;
    break-after:auto!important;
    break-inside:avoid!important;
  }

  /*
   * Keep the ticket's internal percentage positioning intact.
   * Scale the existing screen typography proportionally for
   * the smaller physical ticket; this prevents overlap while
   * preserving the same visual hierarchy.
   */
  .gmjs-bus-seat{
    font-size:13px!important;
  }
  .gmjs-bus-number{
    font-size:9px!important;
  }
  .gmjs-room{
    font-size:13px!important;
  }
  .gmjs-room-type{
    font-size:9px!important;
  }
  .gmjs-due{
    font-size:12px!important;
  }
  .gmjs-guest-name{
    font-size:14px!important;
  }
  .gmjs-guest-phone{
    font-size:9px!important;
  }

  .gmjs-data{
    print-color-adjust:exact!important;
    -webkit-print-color-adjust:exact!important;
  }

  .gmjs-ticket-bg,
  .gmjs-qr{
    print-color-adjust:exact!important;
    -webkit-print-color-adjust:exact!important;
  }

  /* Put every pair on the same A4 page. */
  .gmjs-ticket:nth-of-type(3n) {
    page-break-after: always !important;
    break-after: page !important;
  }

  .gmjs-ticket:last-child{
    page-break-after:auto!important;
    break-after:auto!important;
  }
}
</style>
</head>

<body>
<main class="gmjs-ticket-page">

  <div class="no-print gmjs-actions">
    <a class="btn secondary" href="passengers.php">Back</a>
    <button class="btn primary" onclick="printTickets()">Print All Tickets</button>
  </div>

<?php foreach($rows as $p):
  $q2=db()->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE passenger_id=?");
  $q2->execute([$p['id']]);
  $paid=(float)$q2->fetchColumn();
  $due=max(0,(float)$p['final_fee']-$paid);
?>
  <section class="gmjs-ticket" aria-label="GMJS Guest Ticket">
    <img class="gmjs-ticket-bg" src="<?=h($ticketBg)?>" alt="GMJS Ticket Design">

    <div class="gmjs-data gmjs-bus-seat"><?=h($p['bus_name'])?> · <?=h($p['seat_no'])?></div>

    <?php if(!empty($p['bus_number'])): ?>
      <div class="gmjs-data gmjs-bus-number"><?=h($p['bus_number'])?></div>
    <?php endif; ?>

    <div class="gmjs-data gmjs-room"><?=h($p['room_no'] ?? '—')?></div>
    <div class="gmjs-data gmjs-room-type"><?=h(room_label($p['room_type']))?></div>
    <div class="gmjs-data gmjs-due"><?=money($due)?></div>

    <div class="gmjs-data gmjs-guest-name"><?=h($p['name'])?></div>
    <div class="gmjs-data gmjs-guest-phone"><?=h($p['phone'])?></div>

    <img class="gmjs-qr" src="<?=h(qr_image_url((int)$p['id'],300))?>" alt="Guest QR Code">
  </section>
<?php endforeach; ?>

</main>

<script>
function printTickets(){
  if (document.fonts && document.fonts.ready) {
    document.fonts.ready.then(() => window.print());
  } else {
    window.print();
  }
}
</script>
</body>
</html>
