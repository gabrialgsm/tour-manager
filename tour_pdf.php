<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/feature_helpers.php';

saas_require_login();
$tourId=saas_require_tour();
$db=saas_db();
$org=saas_current_organization();
$tour=saas_current_tour();

function tour_pdf_setting(PDO $db,int $tourId,string $key,string $default=''):string{
    $q=$db->prepare('SELECT setting_value FROM tour_settings WHERE tour_id=? AND setting_key=? LIMIT 1');
    $q->execute([$tourId,$key]);
    return (string)($q->fetchColumn()??$default);
}
function px(string $v):string{return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function pdf_lines(string $text):array{
    $lines=preg_split('/\\R/u',trim($text));
    return array_values(array_filter(array_map('trim',$lines),static fn($v)=>$v!==''));
}

$notes=tour_pdf_setting($db,$tourId,'notes');
$schedule=tour_pdf_setting($db,$tourId,'schedule');
$banner=tour_pdf_setting($db,$tourId,'banner_image');
$transport=tour_pdf_setting($db,$tourId,'default_transport','NONE');

$features=saas_enabled_features($tourId);
$q=$db->prepare("SELECT name,bus_number,layout_type,total_seats,status FROM buses WHERE tour_id=? ORDER BY id");
$q->execute([$tourId]);$buses=$q->fetchAll();

$q=$db->prepare("SELECT room_no,room_type,capacity,status FROM rooms WHERE tour_id=? ORDER BY room_no");
$q->execute([$tourId]);$rooms=$q->fetchAll();

$q=$db->prepare("SELECT COUNT(*) FROM tour_passengers WHERE tour_id=? AND status<>'CANCELLED'");
$q->execute([$tourId]);$passengerCount=(int)$q->fetchColumn();

$baseUrl=rtrim((string)($config['app']['base_url']??''),'/');
$logoUrl=$baseUrl.'/assets/gotm-logo.png';
$coverUrl=$banner!=='' ? ($baseUrl!=='' && str_starts_with($banner,'/') ? $baseUrl.$banner : $banner) : '';

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=px((string)$tour['name'])?> — Tour PDF</title>
<style>
@page{size:A4;margin:14mm 13mm 16mm}
*{box-sizing:border-box}
body{margin:0;background:#eef2f6;color:#172033;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.5}
.toolbar{position:sticky;top:0;z-index:20;background:#172033;padding:10px 14px;text-align:right}
.toolbar button,.toolbar a{display:inline-block;border:0;border-radius:8px;padding:9px 14px;margin-left:7px;text-decoration:none;font-weight:800;font-size:12px;cursor:pointer}
.toolbar .primary{background:#f5b51b;color:#102a43}.toolbar .light{background:#fff;color:#172033}
.sheet{max-width:900px;margin:20px auto;background:#fff;padding:0;box-shadow:0 10px 35px #0002}
.header{padding:22px 25px 15px;display:flex;align-items:flex-start;justify-content:space-between;gap:20px;border-bottom:3px solid #155eef}
.org{font-size:11px;text-transform:uppercase;letter-spacing:.12em;color:#667085;font-weight:800}
.title{font-size:28px;line-height:1.15;margin:5px 0;color:#0b2a49}
.dates{font-size:13px;color:#475467}
.gotm{width:78px;height:50px;object-fit:contain;object-position:right top}
.cover{width:100%;height:220px;object-fit:cover;display:block}
.cover-placeholder{height:120px;background:linear-gradient(135deg,#0b2a49,#155eef);display:flex;align-items:center;justify-content:center;color:#fff;font-size:24px;font-weight:900}
.body{padding:22px 25px}
.intro{display:grid;grid-template-columns:1.35fr .65fr;gap:16px;margin-bottom:20px}
.card{border:1px solid #e4e7ec;border-radius:12px;padding:14px;background:#fff}
.card h3{margin:0 0 9px;color:#0b2a49;font-size:14px}
.meta{display:grid;grid-template-columns:1fr 1fr;gap:9px}
.meta div{background:#f8fafc;border-radius:9px;padding:9px}.meta small{display:block;color:#667085;font-size:10px}.meta b{display:block;margin-top:2px}
.section{margin:18px 0;page-break-inside:avoid}
.section-title{display:flex;align-items:center;gap:9px;margin:0 0 9px;color:#0b2a49;font-size:17px;border-bottom:1px solid #e4e7ec;padding-bottom:7px}
.section-title:before{content:"";width:4px;height:19px;background:#155eef;border-radius:5px}
.schedule{display:grid;gap:7px}.schedule-item{display:grid;grid-template-columns:105px 1fr;gap:12px;padding:8px 10px;background:#f8fafc;border-radius:8px;border-left:3px solid #155eef}
.time{font-weight:800;color:#155eef}.note{background:#fff8e6;border:1px solid #f5d98b;border-radius:10px;padding:12px;white-space:pre-wrap}
.features{display:grid;grid-template-columns:1fr 1fr;gap:9px}.feature{border:1px solid #e4e7ec;border-radius:10px;padding:10px}.feature b{display:block;color:#0b2a49}.feature span{color:#667085;font-size:11px}
table{width:100%;border-collapse:collapse;font-size:11px}th{background:#0b2a49;color:#fff;text-align:left;padding:8px}td{border:1px solid #e4e7ec;padding:7px}tbody tr:nth-child(even){background:#f8fafc}
.footer{margin-top:25px;padding-top:12px;border-top:1px solid #e4e7ec;display:flex;justify-content:space-between;color:#667085;font-size:9px}
@media print{
 body{background:#fff}.toolbar{display:none}.sheet{margin:0;max-width:none;box-shadow:none}.cover{break-inside:avoid}
 a{color:inherit;text-decoration:none}.section{break-inside:avoid}
}
@media(max-width:650px){
 .sheet{margin:0}.header,.body{padding:17px}.intro,.features,.meta{grid-template-columns:1fr}.cover{height:180px}
}
</style>
</head>
<body>
<div class="toolbar">
<button class="primary" onclick="window.print()">Export / Save as PDF</button>
<a class="light" href="dashboard.php">Back to dashboard</a>
</div>
<main class="sheet">
<header class="header">
<div>
<div class="org"><?=px((string)$org['name'])?></div>
<h1 class="title"><?=px((string)$tour['name'])?></h1>
<div class="dates">
<?=px((string)($tour['start_date']??'Date not set'))?>
<?=!empty($tour['end_date'])?' — '.px((string)$tour['end_date']):''?>
</div>
</div>
<img class="gotm" src="<?=px($logoUrl)?>" alt="GoTM">
</header>

<?php if($coverUrl!==''):?><img class="cover" src="<?=px($coverUrl)?>" alt="<?=px((string)$tour['name'])?>"><?php else:?><div class="cover-placeholder"><?=px((string)$tour['name'])?></div><?php endif;?>

<div class="body">
<div class="intro">
<div class="card">
<h3>Tour Overview</h3>
<p><?=nl2br(px((string)($tour['description']??'Tour information and itinerary.')))?></p>
</div>
<div class="card">
<h3>Quick Details</h3>
<div class="meta">
<div><small>Status</small><b><?=px((string)$tour['status'])?></b></div>
<div><small>Transport</small><b><?=px($transport==='NONE'?'Not selected':$transport)?></b></div>
<div><small>Passengers</small><b><?=$passengerCount?></b></div>
<div><small>Buses</small><b><?=count($buses)?></b></div>
<div><small>Rooms</small><b><?=count($rooms)?></b></div>
<div><small>Features</small><b><?=count($features)?></b></div>
</div>
</div>
</div>

<?php if($schedule!==''):?>
<section class="section">
<h2 class="section-title">Schedule</h2>
<div class="schedule">
<?php foreach(pdf_lines($schedule) as $line):
$parts=preg_split('/\\s*[—–-]\\s*/u',$line,2);
$time=$parts[0]??'';$desc=$parts[1]??$line;
?>
<div class="schedule-item"><div class="time"><?=px($time)?></div><div><?=px($desc)?></div></div>
<?php endforeach;?>
</div>
</section>
<?php endif;?>

<?php if($features):?>
<section class="section">
<h2 class="section-title">Included Features</h2>
<div class="features">
<?php foreach($features as $f):?>
<div class="feature"><b><?=px((string)$f['label'])?></b><span><?=px((string)$f['description'])?></span></div>
<?php endforeach;?>
</div>
</section>
<?php endif;?>

<?php if($notes!==''):?>
<section class="section">
<h2 class="section-title">Important Notes</h2>
<div class="note"><?=px($notes)?></div>
</section>
<?php endif;?>

<?php if($buses):?>
<section class="section">
<h2 class="section-title">Transport</h2>
<table><thead><tr><th>Bus</th><th>Bus Number</th><th>Layout</th><th>Seats</th><th>Status</th></tr></thead><tbody>
<?php foreach($buses as $b):?><tr><td><?=px((string)$b['name'])?></td><td><?=px((string)$b['bus_number'])?></td><td><?=px(strtoupper((string)$b['layout_type']))?></td><td><?=px((string)$b['total_seats'])?></td><td><?=px((string)$b['status'])?></td></tr><?php endforeach;?>
</tbody></table>
</section>
<?php endif;?>

<?php if($rooms):?>
<section class="section">
<h2 class="section-title">Accommodation</h2>
<table><thead><tr><th>Room</th><th>Type</th><th>Capacity</th><th>Status</th></tr></thead><tbody>
<?php foreach($rooms as $r):?><tr><td><?=px((string)$r['room_no'])?></td><td><?=px((string)$r['room_type'])?></td><td><?=px((string)$r['capacity'])?></td><td><?=px((string)$r['status'])?></td></tr><?php endforeach;?>
</tbody></table>
</section>
<?php endif;?>

<footer class="footer"><span>GoTM — GoZyraa Tour Management</span><span>Generated <?=date('d M Y, h:i A')?></span></footer>
</div>
</main>
<script>
window.addEventListener('load',()=>{if(new URLSearchParams(location.search).get('autoprint')==='1')setTimeout(()=>window.print(),500);});
</script>
</body>
</html>
