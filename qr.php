<?php
require __DIR__.'/bootstrap.php';
$token=(string)($_GET['t']??'');
$id=qr_verify($token);
if(!$id){http_response_code(404);exit('Invalid or expired QR code.');}

$q=db()->prepare("SELECT p.*,t.name tour_name,t.start_date,t.end_date,b.name bus_name,b.bus_number,s.seat_no,
(SELECT r.room_no FROM room_assignments ra JOIN rooms r ON r.id=ra.room_id WHERE ra.passenger_id=p.id LIMIT 1) room_no,
(SELECT ra.room_id FROM room_assignments ra WHERE ra.passenger_id=p.id LIMIT 1) room_id
FROM passengers p JOIN tours t ON t.id=p.tour_id JOIN buses b ON b.id=p.bus_id JOIN seats s ON s.id=p.seat_id
WHERE p.id=? LIMIT 1");
$q->execute([$id]);$p=$q->fetch();
if(!$p){http_response_code(404);exit('Passenger not found.');}

/* Room mates: all active passengers assigned to the same room. */
$roomMates=[];
if(!empty($p['room_id'])){
    $qRoom=db()->prepare("SELECT p2.id,p2.name,s2.seat_no
        FROM room_assignments ra
        JOIN passengers p2 ON p2.id=ra.passenger_id
        LEFT JOIN seats s2 ON s2.id=p2.seat_id
        WHERE ra.room_id=? AND p2.status='ACTIVE'
        ORDER BY CASE WHEN p2.id=? THEN 0 ELSE 1 END, p2.name");
    $qRoom->execute([(int)$p['room_id'],$id]);
    $roomMates=$qRoom->fetchAll();
}

[$paid,$dummy]=passenger_payment_totals($id);
$due=max(0,(float)$p['final_fee']-$paid);
$isAdmin=!empty($_SESSION['admin_id']);
$leg=(($_GET['leg']??$_POST['leg']??'outbound')==='return')?'return':'outbound';
$checkAction=$leg==='return'?'CHECK_IN_RETURN':'CHECK_IN_OUTBOUND';$undoAction=$leg==='return'?'CHECK_IN_RETURN_UNDO':'CHECK_IN_OUTBOUND_UNDO';
$qState=db()->prepare("SELECT action FROM audit_log WHERE entity_type='passenger' AND entity_id=? AND action IN (?,?) ORDER BY id DESC LIMIT 1");$qState->execute([$id,$checkAction,$undoAction]);$checked=$qState->fetchColumn()===$checkAction;
$qState=db()->prepare("SELECT action FROM audit_log WHERE entity_type='passenger' AND entity_id=? AND action IN (?,?) ORDER BY id DESC LIMIT 1");$qState->execute([$id,$checkAction,$undoAction]);$checked=$qState->fetchColumn()===$checkAction;


if($isAdmin && $_SERVER['REQUEST_METHOD']==='POST'){
    check_csrf();
    $action=$_POST['action']??'';
    if($action==='checkin' && !$checked){audit($checkAction,'passenger',$id,ucfirst($leg).' journey check-in from QR');flash('success','Passenger checked in successfully.');}
    elseif($action==='undo' && $checked){audit($undoAction,'passenger',$id,ucfirst($leg).' journey check-in undone from QR');flash('success','Passenger check-in undone.');}
    redirect('qr.php?t='.rawurlencode($token).'&leg='.$leg);
}
?>
<!doctype html><html><head><?php include __DIR__.'/partials/head.php';?>
<style>
.qr-view{max-width:720px;margin:30px auto}
.qr-status{padding:10px 14px;border-radius:12px;font-weight:800;background:#ecfdf5;color:#166534}.qr-status.done{background:#eff6ff;color:#1d4ed8}

.roommates-card{margin-top:18px;padding:18px;border:1px solid #dbe7df;border-radius:18px;background:linear-gradient(180deg,#f7fbf8 0%,#fff 100%);box-shadow:0 8px 24px rgba(15,72,42,.06)}
.roommates-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px}
.roommates-title{color:#075c32;font-size:13px;font-weight:900;letter-spacing:.12em}
.roommates-sub{margin-top:3px;color:#64748b;font-size:13px;font-weight:600}
.roommates-icon{width:40px;height:40px;display:grid;place-items:center;border-radius:12px;background:#e8f5ed;font-size:20px}
.roommates-list{display:grid;gap:8px}
.roommate-row{display:flex;align-items:center;gap:11px;min-height:54px;padding:9px 11px;border:1px solid #e5eee8;border-radius:13px;background:#fff}
.roommate-row.current{border-color:#a8d4b8;background:#f0faf4}
.roommate-avatar{width:36px;height:36px;flex:0 0 36px;display:grid;place-items:center;border-radius:50%;background:#08783f;color:#fff;font-size:14px;font-weight:900}
.roommate-info{min-width:0;flex:1}
.roommate-name{color:#064e2d;font-size:15px;font-weight:800;line-height:1.25}
.roommate-you,.roommate-seat{margin-top:2px;color:#718096;font-size:12px;font-weight:600}
.roommate-badge{padding:4px 8px;border-radius:999px;background:#dff3e6;color:#08783f;font-size:10px;font-weight:900;letter-spacing:.08em}
</style></head><body><main class="wrap"><section class="card qr-view">
<?php flash_render();?>
<div class="eyebrow">GMJS TOUR • PASSENGER QR</div>
<h1><?=h($p['name'])?></h1>
<p class="muted"><?=h($p['tour_name'])?> · <?=h($p['start_date'])?> → <?=h($p['end_date'])?></p>
<div class="grid2">
<div><b>Phone</b><p><?=h($p['phone'])?></p></div>
<div><b>Blood Group</b><p><?=h($p['blood_group']??'—')?></p></div>
<div><b>Departure</b><p><?=h($p['departure']??'—')?></p></div>
<div><b>Bus / Seat</b><p><?=h($p['bus_name'])?><?php if(!empty($p['bus_number'])):?> · <?=h($p['bus_number'])?><?php endif;?> · <b><?=h($p['seat_no'])?></b></p></div>
<div><b>Room</b><p><?=h($p['room_no']??'Not Assigned')?> · <?=h(room_label($p['room_type']))?></p></div>
<div><b>Payment Due</b><p><?=money($due)?></p></div>
<?php if($isAdmin):?><div><b>Original Package Price</b><p><?=money($p['package_price'])?></p></div><div><b>Final Price</b><p><?=money($p['final_fee'])?></p></div><div><b>Total Paid</b><p><?=money($paid)?></p></div><?php endif;?>
</div>

<?php if(!empty($roomMates)): ?>
<div class="roommates-card">
    <div class="roommates-head">
        <div>
            <div class="roommates-title">ROOM MATES</div>
            <div class="roommates-sub"><?=count($roomMates)?> guest<?=count($roomMates)===1?'':'s'?> in Room <?=h($p['room_no']??'—')?></div>
        </div>
        <div class="roommates-icon">🛏</div>
    </div>
    <div class="roommates-list">
        <?php foreach($roomMates as $mate): ?>
            <div class="roommate-row <?=$mate['id']===$id?'current':''?>">
                <div class="roommate-avatar"><?=h(mb_strtoupper(mb_substr(trim($mate['name']),0,1,'UTF-8'),'UTF-8'))?></div>
                <div class="roommate-info">
                    <div class="roommate-name"><?=h($mate['name'])?></div>
                    <?php if($mate['id']===$id): ?>
                        <div class="roommate-you">You</div>
                    <?php elseif(!empty($mate['seat_no'])): ?>
                        <div class="roommate-seat">Seat <?=h($mate['seat_no'])?></div>
                    <?php endif; ?>
                </div>
                <?php if($mate['id']===$id): ?><span class="roommate-badge">YOU</span><?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="leg-tabs" style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:16px 0"><a href="qr.php?t=<?=rawurlencode($token)?>&leg=outbound" style="padding:10px;border-radius:10px;text-align:center;font-weight:800;text-decoration:none;background:<?=$leg==='outbound'?'#16a34a':'#f1f5f9'?>;color:<?=$leg==='outbound'?'#fff':'#334155'?>">🚌 Outbound</a><a href="qr.php?t=<?=rawurlencode($token)?>&leg=return" style="padding:10px;border-radius:10px;text-align:center;font-weight:800;text-decoration:none;background:<?=$leg==='return'?'#16a34a':'#f1f5f9'?>;color:<?=$leg==='return'?'#fff':'#334155'?>">🔄 Return</a></div>
<div class="qr-status <?=$checked?'done':''?>"><?= $checked?'✓ CHECKED IN':'NOT CHECKED IN' ?> — <?=h($leg==='return'?'Return Journey':'Outbound Journey')?></div>
<?php if($isAdmin):?><form method="post" style="margin-top:16px"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="leg" value="<?=h($leg)?>"><input type="hidden" name="action" value="<?=$checked?'undo':'checkin'?>"><button class="btn <?=$checked?'danger':'primary'?> wide" type="submit" onclick="return <?=$checked?"confirm('Undo this check-in?')":'true'?>"><?=$checked?'↩ Undo Check-In':'✓ Check In Passenger'?></button></form><?php endif;?>
<?php if($isAdmin):?><p class="muted" style="margin-top:12px">Admin view: package/final price and check-in control are visible.</p><?php else:?><p class="muted">Public view: financial package price is hidden. Only current due is shown.</p><?php endif;?>
</section></main></body></html>