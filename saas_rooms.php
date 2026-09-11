<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap_saas.php';
saas_require_login();
$tourId = saas_require_tour();
saas_require_permission('room.manage');
$db = saas_db();
$error = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    saas_check_csrf();
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'create_room') {
            $roomNo = trim((string)($_POST['room_no'] ?? ''));
            $type = trim((string)($_POST['room_type'] ?? ''));
            $capacity = max(1, min(20, (int)($_POST['capacity'] ?? 1)));
            $notes = trim((string)($_POST['notes'] ?? ''));
            if ($roomNo === '' || $type === '') throw new RuntimeException('Room number and room type are required.');
            $q = $db->prepare('INSERT INTO rooms(tour_id,room_no,room_type,capacity,notes,status) VALUES(?,?,?,?,?,\'AVAILABLE\')');
            $q->execute([$tourId,$roomNo,$type,$capacity,$notes ?: null]);
            saas_audit('room.created','room',(int)$db->lastInsertId(),json_encode(['room_no'=>$roomNo,'capacity'=>$capacity],JSON_UNESCAPED_UNICODE));
            $ok = 'Room created.';
        } elseif ($action === 'toggle_room') {
            $roomId = (int)($_POST['room_id'] ?? 0);
            $q = $db->prepare('SELECT id,status FROM rooms WHERE id=? AND tour_id=? FOR UPDATE');
            $db->beginTransaction(); $q->execute([$roomId,$tourId]); $room=$q->fetch();
            if (!$room) throw new RuntimeException('Room not found.');
            $new = $room['status'] === 'BLOCKED' ? 'AVAILABLE' : 'BLOCKED';
            if ($new === 'BLOCKED') {
                $q=$db->prepare('SELECT COUNT(*) FROM room_assignments ra JOIN tour_passengers tp ON tp.id=ra.tour_passenger_id WHERE ra.room_id=? AND tp.status<>\'CANCELLED\'');
                $q->execute([$roomId]); if ((int)$q->fetchColumn()>0) throw new RuntimeException('Unassign all passengers before blocking this room.');
            }
            $q=$db->prepare('UPDATE rooms SET status=? WHERE id=? AND tour_id=?'); $q->execute([$new,$roomId,$tourId]); $db->commit();
            saas_audit('room.status_changed','room',$roomId,json_encode(['status'=>$new])); $ok='Room status updated.';
        } elseif ($action === 'assign_room') {
            $roomId=(int)($_POST['room_id']??0); $passengerId=(int)($_POST['tour_passenger_id']??0);
            if(!$roomId||!$passengerId) throw new RuntimeException('Passenger and room are required.');
            $db->beginTransaction();
            $q=$db->prepare('SELECT id,capacity,status,room_no FROM rooms WHERE id=? AND tour_id=? FOR UPDATE'); $q->execute([$roomId,$tourId]); $room=$q->fetch();
            if(!$room) throw new RuntimeException('Room does not belong to this tour.');
            if($room['status']!=='AVAILABLE') throw new RuntimeException('This room is blocked.');
            $q=$db->prepare('SELECT tp.id,pp.full_name FROM tour_passengers tp JOIN passenger_profiles pp ON pp.id=tp.passenger_profile_id WHERE tp.id=? AND tp.tour_id=? AND tp.status<>\'CANCELLED\' FOR UPDATE'); $q->execute([$passengerId,$tourId]); $passenger=$q->fetch();
            if(!$passenger) throw new RuntimeException('Passenger does not belong to this tour.');
            $q=$db->prepare('SELECT COUNT(*) FROM room_assignments ra JOIN tour_passengers tp ON tp.id=ra.tour_passenger_id WHERE ra.room_id=? AND tp.status<>\'CANCELLED\' FOR UPDATE'); $q->execute([$roomId]);
            $count=(int)$q->fetchColumn();
            $q=$db->prepare('SELECT room_id FROM room_assignments WHERE tour_passenger_id=? FOR UPDATE'); $q->execute([$passengerId]); $current=$q->fetchColumn();
            if($current && (int)$current===$roomId){$db->commit();$ok='Passenger is already in this room.';}
            else {
                if($count >= (int)$room['capacity']) throw new RuntimeException('Room '.$room['room_no'].' is full.');
                $q=$db->prepare('DELETE FROM room_assignments WHERE tour_passenger_id=?');$q->execute([$passengerId]);
                $q=$db->prepare('INSERT INTO room_assignments(tour_passenger_id,room_id,assigned_by) VALUES(?,?,?)');$q->execute([$passengerId,$roomId,saas_user_id()]);
                $db->commit(); saas_audit('room.assigned','tour_passenger',$passengerId,json_encode(['room_id'=>$roomId,'room_no'=>$room['room_no']],JSON_UNESCAPED_UNICODE)); $ok=$passenger['full_name'].' assigned to room '.$room['room_no'].'.';
            }
        } elseif ($action === 'unassign_room') {
            $passengerId=(int)($_POST['tour_passenger_id']??0); $db->beginTransaction();
            $q=$db->prepare('SELECT ra.room_id,pp.full_name FROM room_assignments ra JOIN tour_passengers tp ON tp.id=ra.tour_passenger_id JOIN passenger_profiles pp ON pp.id=tp.passenger_profile_id WHERE ra.tour_passenger_id=? AND tp.tour_id=? FOR UPDATE');$q->execute([$passengerId,$tourId]);$row=$q->fetch();
            if(!$row) throw new RuntimeException('No room assignment found.');
            $q=$db->prepare('DELETE ra FROM room_assignments ra JOIN tour_passengers tp ON tp.id=ra.tour_passenger_id WHERE ra.tour_passenger_id=? AND tp.tour_id=?');$q->execute([$passengerId,$tourId]);$db->commit();saas_audit('room.unassigned','tour_passenger',$passengerId,json_encode(['room_id'=>(int)$row['room_id']]));$ok=$row['full_name'].' unassigned.';
        }
    } catch(Throwable $e) { if($db->inTransaction())$db->rollBack(); $error=$e->getMessage(); }
}

$q=$db->prepare('SELECT r.*, (SELECT COUNT(*) FROM room_assignments ra JOIN tour_passengers tp ON tp.id=ra.tour_passenger_id WHERE ra.room_id=r.id AND tp.status<>\'CANCELLED\') AS occupied FROM rooms r WHERE r.tour_id=? ORDER BY r.room_no');$q->execute([$tourId]);$rooms=$q->fetchAll();
$q=$db->prepare('SELECT tp.id,pp.full_name,pp.phone,ra.room_id,r.room_no FROM tour_passengers tp JOIN passenger_profiles pp ON pp.id=tp.passenger_profile_id LEFT JOIN room_assignments ra ON ra.tour_passenger_id=tp.id LEFT JOIN rooms r ON r.id=ra.room_id WHERE tp.tour_id=? AND tp.status<>\'CANCELLED\' ORDER BY pp.full_name');$q->execute([$tourId]);$passengers=$q->fetchAll();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Rooms — <?=saas_h(saas_current_tour()['name'])?></title><style>*{box-sizing:border-box}body{font-family:Inter,Arial,sans-serif;background:#f4f7fb;margin:0;color:#172033}.wrap{max-width:1150px;margin:25px auto;padding:0 16px}.box{background:#fff;border-radius:16px;padding:20px;margin-bottom:18px;box-shadow:0 6px 22px #0000000b}.grid{display:grid;grid-template-columns:320px 1fr;gap:18px}.fields{display:grid;grid-template-columns:1fr 1fr;gap:10px}label{display:block;font-size:13px;font-weight:700;margin:10px 0 5px}input,select,textarea,button{width:100%;padding:10px;border:1px solid #d0d5dd;border-radius:9px;font:inherit}textarea{min-height:70px;resize:vertical}button{background:#172033;color:#fff;border-color:#172033;cursor:pointer;font-weight:700}.msg{padding:11px;border-radius:9px;margin-bottom:15px}.ok{background:#ecfdf3;color:#067647}.err{background:#fef3f2;color:#b42318}.rooms{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px}.room{border:1px solid #d0d5dd;border-radius:14px;padding:15px;background:#fff}.room.full{border-color:#f59e0b}.room.blocked{opacity:.6;background:#f2f4f7}.room h3{margin:0 0 5px}.meta{color:#667085;font-size:13px}.bar{height:7px;background:#eaecf0;border-radius:99px;overflow:hidden;margin:12px 0}.bar i{display:block;height:100%;background:#12b76a}.bar.full i{background:#f79009}.passenger{display:grid;grid-template-columns:1fr 1fr;gap:10px;align-items:end}.mini{font-size:12px;color:#667085;margin:8px 0}.danger{background:#fff1f0;color:#b42318;border-color:#fecdca}.room form{margin-top:10px}.head{display:flex;justify-content:space-between;align-items:center;gap:10px}.status{font-size:12px;font-weight:700}@media(max-width:800px){.grid{grid-template-columns:1fr}.fields,.passenger{grid-template-columns:1fr}.rooms{grid-template-columns:1fr}}</style></head><body><main class="wrap"><p><a href="saas_dashboard.php">← Dashboard</a></p><div class="head"><div><h1>Rooms</h1><div class="meta">Manage room inventory and passenger allocation for this tour.</div></div></div><?php if($ok):?><div class="msg ok"><?=saas_h($ok)?></div><?php endif;?><?php if($error):?><div class="msg err"><?=saas_h($error)?></div><?php endif;?><div class="grid"><section class="box"><h2>Add room</h2><form method="post"><input type="hidden" name="csrf" value="<?=saas_h(saas_csrf())?>"><input type="hidden" name="action" value="create_room"><label>Room number</label><input name="room_no" placeholder="101" required><label>Room type</label><input name="room_type" placeholder="Deluxe / Twin / Family" required><div class="fields"><div><label>Capacity</label><input type="number" name="capacity" min="1" max="20" value="2" required></div><div><label>&nbsp;</label><button>Create room</button></div></div><label>Notes</label><textarea name="notes" placeholder="Floor, view, special notes..."></textarea></form></section><section class="box"><h2>Assign passenger</h2><form method="post"><input type="hidden" name="csrf" value="<?=saas_h(saas_csrf())?>"><input type="hidden" name="action" value="assign_room"><div class="passenger"><div><label>Passenger</label><select name="tour_passenger_id" required><option value="">Select passenger</option><?php foreach($passengers as $p):?><option value="<?=$p['id']?>"><?=saas_h($p['full_name'])?><?=!empty($p['phone'])?' — '.saas_h($p['phone']):''?></option><?php endforeach;?></select></div><div><label>Room</label><select name="room_id" required><option value="">Select available room</option><?php foreach($rooms as $r):$occ=(int)$r['occupied'];$cap=(int)$r['capacity'];if($r['status']==='AVAILABLE'&&$occ<$cap):?><option value="<?=$r['id']?>"><?=saas_h($r['room_no'])?> — <?=saas_h($r['room_type'])?> (<?=$occ?>/<?=$cap?>)</option><?php endif;endforeach;?></select></div></div><button style="margin-top:12px">Assign / Change room</button></form></section></div><section class="box"><h2>Room inventory</h2><?php if(!$rooms):?><p>No rooms configured yet.</p><?php else:?><div class="rooms"><?php foreach($rooms as $r):$occ=(int)$r['occupied'];$cap=(int)$r['capacity'];$pct=$cap?min(100,round($occ*100/$cap)):0;$cls=$r['status']==='BLOCKED'?'blocked':($occ>=$cap?'full':'');?><article class="room <?=$cls?>"><div class="head"><h3><?=saas_h($r['room_no'])?></h3><span class="status"><?=saas_h($r['status'])?></span></div><div class="meta"><?=saas_h($r['room_type'])?> · <?=$occ?> / <?=$cap?></div><div class="bar <?=($occ>=$cap?'full':'')?>"><i style="width:<?=$pct?>%"></i></div><?php if($r['notes']):?><div class="mini"><?=saas_h($r['notes'])?></div><?php endif;?><form method="post"><input type="hidden" name="csrf" value="<?=saas_h(saas_csrf())?>"><input type="hidden" name="action" value="toggle_room"><input type="hidden" name="room_id" value="<?=$r['id']?>"><button class="<?=$r['status']==='AVAILABLE'?'danger':''?>"><?=$r['status']==='BLOCKED'?'Unblock room':'Block room'?></button></form><?php if($occ):?><div class="mini"><strong>Guests</strong></div><?php foreach($passengers as $p):if((int)($p['room_id']??0)===(int)$r['id']):?><div class="head mini"><span><?=saas_h($p['full_name'])?></span><form method="post" style="margin:0"><input type="hidden" name="csrf" value="<?=saas_h(saas_csrf())?>"><input type="hidden" name="action" value="unassign_room"><input type="hidden" name="tour_passenger_id" value="<?=$p['id']?>"><button class="danger" style="width:auto;padding:5px 8px">Remove</button></form></div><?php endif;endforeach;endif;?></article><?php endforeach;?></div><?php endif;?></section></main></body></html>