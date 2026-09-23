<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

saas_require_login();
$tourId = saas_require_tour();
$db = saas_db();
$org = saas_current_organization();
$tour = saas_current_tour();

$busId = (int)($_GET['bus_id'] ?? 0);
if ($busId <= 0) {
    http_response_code(400);
    exit('Invalid bus.');
}

$q = $db->prepare("SELECT * FROM buses WHERE id=? AND tour_id=? AND status='ACTIVE' LIMIT 1");
$q->execute([$busId, $tourId]);
$bus = $q->fetch();
if (!$bus) {
    http_response_code(404);
    exit('Bus not found.');
}

$sq = $db->prepare("SELECT s.id,s.seat_code,s.row_no,s.position,s.status,
                           pa.tour_passenger_id,pp.full_name
                    FROM seats s
                    LEFT JOIN passenger_seat_assignments pa
                      ON pa.seat_id=s.id AND pa.bus_id=s.bus_id
                    LEFT JOIN tour_passengers tp
                      ON tp.id=pa.tour_passenger_id AND tp.tour_id=?
                    LEFT JOIN passenger_profiles pp
                      ON pp.id=tp.passenger_profile_id
                    WHERE s.bus_id=?
                    ORDER BY s.row_no,s.id");
$sq->execute([$tourId, $busId]);
$seatRows = [];
foreach ($sq->fetchAll() as $seat) {
    $seatRows[(int)$seat['row_no']][] = $seat;
}

$layout = strtoupper(str_replace('X', '+', trim((string)($bus['layout_type'] ?? '2+2'))));
$parts = preg_split('/\s*\+\s*/', $layout);
$leftLayout = isset($parts[0]) && is_numeric($parts[0]) ? max(1, (int)$parts[0]) : 2;
$rightLayout = isset($parts[1]) && is_numeric($parts[1]) ? max(1, (int)$parts[1]) : 2;

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function split_seats(array $items, int $leftCount): array {
    if (count($items) <= 1) return [$items, []];
    $leftCount = min(max(1, $leftCount), count($items) - 1);
    return [array_slice($items, 0, $leftCount), array_slice($items, $leftCount)];
}

$baseUrl = rtrim((string)($config['app']['base_url'] ?? ''), '/');
$logoUrl = $baseUrl . '/assets/gotm-logo.png';
$autoprint = (string)($_GET['autoprint'] ?? '1') === '1';
$totalSeats = count(array_merge(...array_values($seatRows ?: [[]])));
$occupiedSeats = 0;
foreach ($seatRows as $items) {
    foreach ($items as $s) if (!empty($s['full_name'])) $occupiedSeats++;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=h((string)$bus['name'])?> — Seat Plan</title>
<style>
*{box-sizing:border-box}
html,body{margin:0;padding:0;background:#eef2f6;color:#10243a;font-family:Inter,"Segoe UI",Arial,sans-serif}
.toolbar{display:flex;justify-content:center;gap:8px;padding:12px;background:#fff;border-bottom:1px solid #dbe3ea;position:sticky;top:0;z-index:10}
.btn{display:inline-flex;align-items:center;justify-content:center;padding:9px 14px;border:1px solid #cfd8e3;border-radius:9px;background:#fff;color:#10243a;text-decoration:none;font-weight:800;font-size:12px}
.btn.primary{background:#f7ab17;border-color:#f7ab17}
.sheet{width:210mm;min-height:297mm;margin:10px auto;padding:5mm 8mm 4mm;background:#fff;box-shadow:0 10px 35px rgba(8,43,75,.14);position:relative}
.header{display:flex;align-items:center;justify-content:space-between;gap:16px;border-bottom:2px solid #0b2a49;padding-bottom:2.5mm}
.brand{display:flex;align-items:center;gap:9px;min-width:0}
.brand img{width:34px;height:34px;object-fit:contain}
.brand-name{font-size:14px;font-weight:900;letter-spacing:.2px;color:#0b2a49}
.brand-org{font-size:10px;color:#667085;margin-top:2px}
.header-right{text-align:right}
.kicker{font-size:9px;letter-spacing:1.5px;text-transform:uppercase;color:#7a8795;font-weight:900}
.header-right strong{display:block;font-size:15px;margin-top:2px;color:#0b2a49}
.header-right span{display:block;font-size:10px;color:#667085;margin-top:3px}
.title{padding:2.5mm 0 1.5mm;text-align:center}
.title h1{margin:0;font-size:21px;letter-spacing:-.5px;color:#0b2a49}
.title .bus-number{font-size:12px;color:#526273;margin-top:4px;font-weight:700}
.meta{display:flex;justify-content:center;gap:5px;flex-wrap:wrap;margin-top:7px}
.pill{padding:3px 7px;border-radius:999px;background:#f3f6f9;border:1px solid #dce4eb;font-size:9px;font-weight:800;color:#344054}
.pill.gold{background:#fff4d6;border-color:#f7ab17;color:#805600}
.stats{display:grid;grid-template-columns:repeat(3,1fr);gap:5px;margin-bottom:2mm}
.stat{border:1px solid #dfe7ee;border-radius:8px;padding:5px 8px;text-align:center;background:#fbfcfd}
.stat b{display:block;font-size:13px;color:#0b2a49}
.stat span{display:block;font-size:8px;color:#667085;margin-top:2px;text-transform:uppercase;letter-spacing:.5px}
.bus-frame{border:1.5px solid #0b2a49;border-radius:11px;padding:2.5mm 4mm 2mm;background:linear-gradient(180deg,#fbfdff,#f7fafc)}
.driver{width:82px;margin:0 auto 1.8mm;padding:4px 7px;border-radius:8px;background:#0b2a49;color:#fff;text-align:center;font-size:9px;font-weight:900;letter-spacing:.5px}
.seat-row{display:grid;grid-template-columns:minmax(0,1fr) 9mm minmax(0,1fr);gap:2mm;margin:1mm 0;align-items:stretch}
.seat-side{display:flex;gap:1.2mm;min-width:0}
.aisle{border-left:1px dashed #b9c4cf;border-right:1px dashed #b9c4cf;border-radius:7px}
.seat{min-width:0;flex:1;min-height:9.2mm;border:1.4px solid #9ac8aa;border-radius:9px;background:#effaf2;padding:1mm .8mm .7mm;text-align:center;display:flex;flex-direction:column;justify-content:center;align-items:center}
.seat.occupied{background:#e8f2ff;border-color:#8fb4dc}
.seat-code{font-size:9px;line-height:1;font-weight:950;color:#0b2a49}
.seat-name{width:100%;margin-top:.7mm;font-size:7px;line-height:1.05;font-weight:800;color:#344054;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.front-single{margin-bottom:1.5mm}
.front-single .seat{max-width:22mm;flex:none}
.last-row{margin-top:1mm;padding-top:.8mm;border-top:1px solid #dce4eb}
.section-label{text-align:center;font-size:8px;letter-spacing:1px;text-transform:uppercase;color:#7a8795;font-weight:900;margin-bottom:.6mm}
.legend{display:flex;justify-content:center;gap:15px;flex-wrap:wrap;margin-top:1.5mm;font-size:6px;color:#667085}
.legend-item{display:flex;align-items:center;gap:5px}
.dot{width:7px;height:7px;border-radius:3px;border:1px solid #9ac8aa;background:#effaf2}
.dot.occ{border-color:#8fb4dc;background:#e8f2ff}
.footer{border-top:1px solid #dfe7ee;margin-top:3mm;padding-top:2mm;display:flex;justify-content:space-between;gap:10px;font-size:8px;color:#7a8795}
.empty-note{padding:18mm;text-align:center;color:#667085;border:1px dashed #cfd8e3;border-radius:12px}
@page{size:A4 portrait;margin:0}
@media print{
 @page{size:A4 portrait;margin:0}

 html,body{
  width:210mm!important;
  height:297mm!important;
  margin:0!important;
  padding:0!important;
  background:#fff!important;
  overflow:hidden!important;
 }

 /* The application shell may wrap this page. Hide the shell visually,
    then explicitly re-enable the printable sheet and everything inside it. */
 body *{
  visibility:hidden!important;
 }

 .sheet,
 .sheet *{
  visibility:visible!important;
 }

 .sheet{
  display:block!important;
  position:absolute!important;
  left:0!important;
  top:0!important;
  z-index:999999!important;
  width:210mm!important;
  height:297mm!important;
  min-height:297mm!important;
  max-height:297mm!important;
  margin:0!important;
  padding:5mm 8mm 4mm!important;
  background:#fff!important;
  box-shadow:none!important;
  overflow:hidden!important;
  break-before:avoid!important;
  break-after:avoid!important;
  break-inside:avoid!important;
  page-break-before:avoid!important;
  page-break-after:avoid!important;
  page-break-inside:avoid!important;
 }

 .toolbar,
 .footer{
  display:none!important;
 }

 a{color:inherit;text-decoration:none}
}
@media(max-width:850px){
 .sheet{width:100%;min-height:auto;margin:0;padding:24px}
 .seat-row{grid-template-columns:minmax(0,1fr) 36px minmax(0,1fr);gap:10px}
 .seat{min-height:76px}
}
</style>
</head>
<body>
<div class="toolbar">
<a class="btn" href="dashboard.php">← Back to dashboard</a>
<button class="btn primary" type="button" onclick="window.print()">🖨 Print / Save PDF</button>
</div>

<main class="sheet">
<header class="header">
  <div class="brand">
    <img src="<?=h($logoUrl)?>" alt="GoTM">
    <div>
      <div class="brand-name">GoTM</div>
      <div class="brand-org"><?=h((string)($org['name'] ?? ''))?></div>
    </div>
  </div>
  <div class="header-right">
    <div class="kicker">Tour Seat Plan</div>
    <strong><?=h((string)($tour['name'] ?? 'Tour'))?></strong>
    <span><?=h((string)($tour['start_date'] ?? ''))?><?=!empty($tour['end_date'])?' — '.h((string)$tour['end_date']):''?></span>
  </div>
</header>

<section class="title">
  <h1><?=h((string)$bus['name'])?></h1>
  <?php if(!empty($bus['bus_number'])):?><div class="bus-number">Bus No. <?=h((string)$bus['bus_number'])?></div><?php endif;?>
  <div class="meta">
    <span class="pill gold">Layout <?=h($layout)?></span>
    <span class="pill"><?=h((string)$bus['total_seats'])?> seats</span>
    <span class="pill"><?=h((string)$bus['status'])?></span>
  </div>
</section>

<section class="stats">
  <div class="stat"><b><?=h((string)$totalSeats)?></b><span>Total seats</span></div>
  <div class="stat"><b><?=h((string)$occupiedSeats)?></b><span>Assigned</span></div>
  <div class="stat"><b><?=h((string)max(0,$totalSeats-$occupiedSeats))?></b><span>Available</span></div>
</section>

<section class="bus-frame">
  <div class="driver">FRONT / DRIVER</div>

  <?php if(isset($seatRows[0])): ?>
    <div class="section-label">Front single seats</div>
    <div class="seat-row front-single">
      <div class="seat-side">
        <?php foreach($seatRows[0] as $s): $occupied=!empty($s['full_name']); ?>
          <div class="seat <?=$occupied?'occupied':''?>">
            <div class="seat-code"><?=h((string)$s['seat_code'])?></div>
            <div class="seat-name"><?=h($occupied?(string)$s['full_name']:'Available')?></div>
            
          </div>
        <?php endforeach; ?>
      </div><div class="aisle"></div><div class="seat-side"></div>
    </div>
  <?php endif; ?>

  <?php
  $normalRows = $seatRows;
  unset($normalRows[0]);
  $maxRow = $normalRows ? max(array_keys($normalRows)) : 0;
  foreach($normalRows as $rn=>$items):
      [$left,$right]=split_seats($items,$leftLayout);
      $isLast = ($rn === $maxRow);
  ?>
    <?php if($isLast): ?><div class="section-label last-row">Last row</div><?php endif; ?>
    <div class="seat-row">
      <div class="seat-side">
        <?php foreach($left as $s): $occupied=!empty($s['full_name']); ?>
          <div class="seat <?=$occupied?'occupied':''?>">
            <div class="seat-code"><?=h((string)$s['seat_code'])?></div>
            <div class="seat-name"><?=h($occupied?(string)$s['full_name']:'Available')?></div>
            
          </div>
        <?php endforeach; ?>
      </div>
      <div class="aisle"></div>
      <div class="seat-side">
        <?php foreach($right as $s): $occupied=!empty($s['full_name']); ?>
          <div class="seat <?=$occupied?'occupied':''?>">
            <div class="seat-code"><?=h((string)$s['seat_code'])?></div>
            <div class="seat-name"><?=h($occupied?(string)$s['full_name']:'Available')?></div>
            
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>

  <?php if(!$seatRows): ?><div class="empty-note">No seats have been configured for this bus.</div><?php endif; ?>

  <div class="legend">
    <span class="legend-item"><i class="dot"></i> Available</span>
    <span class="legend-item"><i class="dot occ"></i> Assigned</span>
  </div>
</section>

<footer class="footer">
  <span>GoTM — GoZyraa Tour Management</span>
  <span>Generated <?=h(date('d M Y, h:i A'))?></span>
</footer>
</main>

<script>
window.addEventListener('load',function(){
  <?php if($autoprint): ?>setTimeout(function(){window.print()},900);<?php endif; ?>
});
</script>
</body>
</html>
