<?php
require __DIR__.'/bootstrap.php';
require_login();

$ids = array_values(array_filter(array_map('intval', explode(',', $_GET['ids'] ?? ''))));
$tid = require_tour();

// Print mode: selected passengers only.  Otherwise show a simple selection screen.
if ($ids) {
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge([$tid], $ids);
    $q = db()->prepare("SELECT p.*, b.name AS bus_name, b.bus_number, s.seat_no,
        t.name AS tour_name, t.start_date, t.end_date,
        (SELECT r.room_no FROM room_assignments ra JOIN rooms r ON r.id=ra.room_id
         WHERE ra.passenger_id=p.id LIMIT 1) AS room_no
        FROM passengers p
        JOIN buses b ON b.id=p.bus_id
        JOIN seats s ON s.id=p.seat_id
        JOIN tours t ON t.id=p.tour_id
        WHERE p.tour_id=? AND p.status='ACTIVE' AND p.id IN($ph)
        ORDER BY FIELD(p.id," . implode(',', $ids) . ")");
    $q->execute($params);
    $rows = $q->fetchAll();

    // Safety: never print a voucher for an ID outside the current tour.
    if (!$rows) redirect('passengers.php');

    foreach ($rows as &$p) {
        $pq = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE passenger_id=?");
        $pq->execute([(int)$p['id']]);
        $p['paid'] = (float)$pq->fetchColumn();
        $p['due'] = max(0, (float)$p['final_fee'] - $p['paid']);
    }
    unset($p);
}

if (!$ids) {
    $q = db()->prepare("SELECT p.id,p.name,p.phone,p.final_fee,p.room_type,
        b.name AS bus_name,b.bus_number,s.seat_no,
        (SELECT r.room_no FROM room_assignments ra JOIN rooms r ON r.id=ra.room_id
         WHERE ra.passenger_id=p.id LIMIT 1) AS room_no,
        COALESCE((SELECT SUM(pay.amount) FROM payments pay WHERE pay.passenger_id=p.id),0) AS paid
        FROM passengers p
        JOIN buses b ON b.id=p.bus_id
        JOIN seats s ON s.id=p.seat_id
        WHERE p.tour_id=? AND p.status='ACTIVE'
        ORDER BY b.id,s.row_no,s.col_no");
    $q->execute([$tid]);
    $passengers = $q->fetchAll();
}
?><!doctype html>
<html lang="en">
<head>
<?php include __DIR__.'/partials/head.php'; ?>
<style>
/* ---------- selection screen ---------- */
.voucher-tools{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:16px}
.voucher-tools .btn{cursor:pointer}
.selection-table{width:100%;border-collapse:collapse}
.selection-table th,.selection-table td{padding:10px 8px;border-bottom:1px solid #e6eee9;text-align:left}
.selection-table th{font-size:11px;text-transform:uppercase;letter-spacing:.7px;color:#5f756b}
.selection-table .money{font-weight:800}
.check-cell{width:35px}
.voucher-note{font-size:12px;color:#60766b;margin-top:8px}

/* ---------- A4 / 4-up payment vouchers ---------- */
@page{size:A4 portrait;margin:0}
.print-page{width:210mm;margin:0;padding:0}
.voucher{
  width:210mm;
  height:74.25mm;
  box-sizing:border-box;
  border:0;
  border-bottom:1px dashed #8d9d96;
  border-radius:0;
  margin:0;
  padding:0;
  overflow:hidden;
  background:#fff;
  display:flex;
  font-family:"Aptos","Segoe UI","Trebuchet MS",Arial,sans-serif;
  position:relative;
  page-break-inside:avoid;
}
.voucher:last-child{border-bottom:0}
.voucher::before{
  content:"";position:absolute;left:0;right:0;top:0;height:1.3mm;
  background:linear-gradient(90deg,#087a3c,#42c95d);
}
.copy{
  width:50%;box-sizing:border-box;padding:4.1mm 6mm 2.2mm;position:relative;
}
.copy + .copy{border-left:1px dashed #c3d0ca}
.copy::after{
  content:"COPY";position:absolute;top:4.5mm;right:5mm;
  font-size:6.2px;font-weight:900;letter-spacing:1px;color:#81938b;
}
.copy.customer::after{content:"CUSTOMER COPY"}
.copy.office::after{content:"OFFICE COPY"}
.brand-line{display:flex;align-items:center;gap:4px;margin-bottom:1.15mm}
.voucher-logo{width:20px;height:20px;object-fit:contain}
.brand-name{font-size:14px;font-weight:900;color:#087a3c;letter-spacing:.35px}
.brand-sub{font-size:6.7px;color:#71847c;letter-spacing:.9px;text-transform:uppercase;margin-top:.5px}
.receipt-title{font-size:10.2px;font-weight:900;letter-spacing:1.05px;text-transform:uppercase;color:#173a2c;margin-bottom:1.05mm}
.receipt-meta{display:flex;justify-content:space-between;gap:5px;border-bottom:1px solid #e2ebe6;padding-bottom:1.05mm;margin-bottom:1.05mm}
.tour-name{font-size:9.2px;font-weight:850;color:#17382a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:68%}
.receipt-no{font-size:7.2px;font-weight:800;color:#687d74;white-space:nowrap}
.info{display:grid;grid-template-columns:1.45fr 1fr;gap:.8mm 3mm;margin-bottom:1.0mm}
.info-block{min-width:0}
.label{display:block;font-size:6.2px;font-weight:800;color:#71857c;letter-spacing:.6px;text-transform:uppercase;margin-bottom:.35mm}
.value{display:block;font-size:8.8px;font-weight:800;color:#17382a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.value.small{font-size:7.7px;font-weight:700}
.info-block:first-child .value{font-size:9.6px;font-weight:850}
.info-block:nth-child(3) .value{font-size:8.5px;font-weight:750}
.payment-box{border:1px solid #d6e4dc;border-radius:3px;padding:1.15mm 2.2mm;display:grid;grid-template-columns:repeat(3,1fr);gap:1.6mm;background:#f8fcfa;margin-bottom:.8mm}
.pay-item{min-width:0}
.pay-item .value{font-size:8.7px;color:#a65e00}
.pay-item:first-child .value{color:#17382a}
.pay-item:last-child .value{color:#a51e17}
.allocation-note{font-size:5.1px;line-height:1.15;color:#7a8d85;text-align:center;margin:0.55mm 0 0.7mm;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-style:italic}
.sign-row{display:grid;grid-template-columns:1fr 1fr;gap:3mm;margin-top:0}
.line{border-bottom:1px solid #8fa39a;height:2.7mm;position:relative}
.line span{position:absolute;left:0;bottom:-2.25mm;font-size:7.6px;color:#73877e;background:#fff;padding-right:1mm}
.footer-note{position:relative;left:auto;right:auto;bottom:auto;margin-top:9mm;font-size:9.3px;color:#71857c;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cut-label{position:absolute;left:50%;bottom:-1.8mm;transform:translateX(-50%);font-size:4.5px;letter-spacing:1px;font-weight:900;color:#91a39b;background:#fff;padding:0 1.5mm;z-index:2}

@media print{
  html,body{margin:0!important;padding:0!important;background:#fff!important}
  body{font-family:Arial,sans-serif!important}
  .no-print{display:none!important}
  .print-page{display:block!important;width:210mm!important;margin:0!important;padding:0!important}
  .voucher{box-shadow:none!important}
}
@media screen{
  body{background:#f4f8f5}
  .print-page{width:210mm;margin:0 auto}
  .voucher{box-shadow:0 8px 22px rgba(20,70,45,.08)}
}
</style>
</head>
<body>
<?php if (!$ids): ?>
<main class="wrap">
  <div class="head no-print">
    <div>
      <div class="eyebrow">PAYMENT COLLECTION</div>
      <h2>Generate Payment Vouchers</h2>
      <p class="muted">Select passengers and print up to 4 vouchers per A4 page.</p>
    </div>
    <a class="btn secondary" href="passengers.php">Back</a>
  </div>

  <section class="card no-print">
    <form method="get" id="voucherForm">
      <div class="voucher-tools">
        <button type="button" class="btn secondary" onclick="selectAll(true)">Select All</button>
        <button type="button" class="btn secondary" onclick="selectAll(false)">Clear</button>
        <button type="submit" class="btn primary">Generate Vouchers</button>
      </div>
      <table class="selection-table">
        <thead><tr>
          <th class="check-cell"></th><th>Passenger</th><th>Bus / Seat</th><th>Room</th><th>Total</th><th>Paid</th><th>Due</th>
        </tr></thead>
        <tbody>
        <?php foreach($passengers as $p): $due=max(0,(float)$p['final_fee']-(float)$p['paid']); ?>
          <tr>
            <td class="check-cell"><input type="checkbox" name="ids[]" value="<?=h($p['id'])?>"></td>
            <td><strong><?=h($p['name'])?></strong><br><span class="muted"><?=h($p['phone'])?> · #<?=h($p['id'])?></span></td>
            <td><?=h($p['bus_name'])?> · <strong><?=h($p['seat_no'])?></strong></td>
            <td><?=h($p['room_no']??'Not Assigned')?></td>
            <td class="money"><?=money($p['final_fee'])?></td>
            <td class="money"><?=money($p['paid'])?></td>
            <td class="money"><?=money($due)?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p class="voucher-note">Tip: 4 selected passengers are designed to fit on one A4 page. If more are selected, printing will continue on the next page.</p>
    </form>
  </section>
</main>
<script>
function selectAll(state){document.querySelectorAll('input[name="ids[]"]').forEach(x=>x.checked=state)}
document.getElementById('voucherForm').addEventListener('submit',function(e){
  const ids=[...document.querySelectorAll('input[name="ids[]"]:checked')].map(x=>x.value);
  if(!ids.length){e.preventDefault();alert('Please select at least one passenger.');return;}
  e.preventDefault(); location.href='payment_vouchers.php?ids='+encodeURIComponent(ids.join(','));
});
</script>
<?php else: ?>
<main class="wrap">
  <div class="head no-print">
    <a class="btn secondary" href="payment_vouchers.php">Back</a>
    <button class="btn primary" onclick="window.print()">Print Vouchers</button>
  </div>

  <div class="print-page">
  <?php foreach($rows as $index=>$p):
      $receiptNo = 'GMJS-' . date('Y') . '-' . str_pad((string)$p['id'],5,'0',STR_PAD_LEFT);
      $tourDate = !empty($p['start_date']) ? date('d M Y',strtotime($p['start_date'])) : '';
      $currentDue = $p['due'];
  ?>
    <section class="voucher">
      <div class="copy office">
        <div class="brand-line">
          <img class="voucher-logo" src="assets/GMJS_LOGO.png" alt="GMJS">
          <div><div class="brand-name">GMJS TOUR</div><div class="brand-sub">Group Tour &amp; Travel</div></div>
        </div>
        <div class="receipt-title">Management Receipt</div>
        <div class="receipt-meta"><span class="tour-name"><?=h($p['tour_name'])?></span><span class="receipt-no"><?=h($receiptNo)?></span></div>
        <div class="info">
          <div class="info-block"><span class="label">Passenger</span><span class="value"><?=h($p['name'])?></span></div>
          <div class="info-block"><span class="label">Passenger ID</span><span class="value">#<?=h($p['id'])?></span></div>
          <div class="info-block"><span class="label">Phone</span><span class="value small"><?=h($p['phone'])?></span></div>
          <div class="info-block"><span class="label">Current Booking</span><span class="value small"><?=h($p['bus_name'])?> · <?=h($p['seat_no'])?> · <?=h($p['room_no']??'N/A')?></span></div>
        </div>
        <div class="payment-box">
          <div class="pay-item"><span class="label">Total Fee</span><span class="value"><?=money($p['final_fee'])?></span></div>
          <div class="pay-item"><span class="label">Already Paid</span><span class="value"><?=money($p['paid'])?></span></div>
          <div class="pay-item"><span class="label">Current Due</span><span class="value"><?=money($currentDue)?></span></div>
        </div>
        <div class="allocation-note">Seat and room allocation shown above is subject to change before final ticket issuance.</div>
        <div class="sign-row"><div class="line"><span>Customer Signature</span></div><div class="line"><span>Collected By</span></div></div>
        <div class="footer-note">Amount Received: ৳ __________________ &nbsp; Date: __________ &nbsp; Method: __________ &nbsp; Entry: __________</div>
      </div>
      <div class="cut-label">CUT / TEAR</div>
      <div class="copy customer">
        <div class="brand-line">
          <img class="voucher-logo" src="assets/GMJS_LOGO.png" alt="GMJS">
          <div><div class="brand-name">GMJS TOUR</div><div class="brand-sub">Group Tour &amp; Travel</div></div>
        </div>
        <div class="receipt-title">Payment Collection Receipt</div>
        <div class="receipt-meta"><span class="tour-name"><?=h($p['tour_name'])?></span><span class="receipt-no"><?=h($receiptNo)?></span></div>
        <div class="info">
          <div class="info-block"><span class="label">Passenger</span><span class="value"><?=h($p['name'])?></span></div>
          <div class="info-block"><span class="label">Passenger ID</span><span class="value">#<?=h($p['id'])?></span></div>
          <div class="info-block"><span class="label">Phone</span><span class="value small"><?=h($p['phone'])?></span></div>
          <div class="info-block"><span class="label">Current Booking</span><span class="value small"><?=h($p['bus_name'])?> · <?=h($p['seat_no'])?> · <?=h($p['room_no']??'N/A')?></span></div>
        </div>
        <div class="payment-box">
          <div class="pay-item"><span class="label">Total Fee</span><span class="value"><?=money($p['final_fee'])?></span></div>
          <div class="pay-item"><span class="label">Already Paid</span><span class="value"><?=money($p['paid'])?></span></div>
          <div class="pay-item"><span class="label">Current Due</span><span class="value"><?=money($currentDue)?></span></div>
        </div>
        <div class="allocation-note">Seat and room allocation shown above is subject to change before final ticket issuance.</div>
        <div class="sign-row"><div class="line"><span>Customer Signature</span></div><div class="line"><span>Received By</span></div></div>
        <div class="footer-note">Amount Received: ৳ __________________ &nbsp; Date: __________ &nbsp; Method: __________</div>
      </div>

    </section>
  <?php endforeach; ?>
  </div>
</main>
<?php endif; ?>
</body>
</html>
