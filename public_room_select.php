<?php
declare(strict_types=1);
require __DIR__.'/bootstrap_saas.php';
$db=saas_db();
$slug=trim((string)($_GET['slug']??''));
$token=trim((string)($_GET['token']??''));
if($slug===''||$token===''){http_response_code(404);exit('Booking link is incomplete.');}
$q=$db->prepare("SELECT tp.id tp_id,tp.status,pp.full_name,pp.phone,t.id tour_id,t.name tour_name,t.slug,t.organization_id FROM tour_passengers tp JOIN passenger_profiles pp ON pp.id=tp.passenger_profile_id JOIN tours t ON t.id=tp.tour_id JOIN organizations o ON o.id=t.organization_id WHERE t.slug=? AND SHA2(CONCAT('seat:',tp.id,':',pp.phone,':',t.id),256)=? AND o.status='ACTIVE' LIMIT 1");
$q->execute([$slug,$token]);
$booking=$q->fetch();
if(!$booking||$booking['status']!=='ACTIVE'){http_response_code(404);exit('Active booking not found.');}
$roomMsg='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        saas_check_csrf();
        $roomId=(int)($_POST['room_id']??0);
        if(!$roomId) throw new RuntimeException('Please select a room.');
        $db->beginTransaction();
        $q=$db->prepare("SELECT r.id,r.room_no,r.room_type,r.capacity,r.status,COUNT(ra.id) occupied FROM rooms r LEFT JOIN room_assignments ra ON ra.room_id=r.id JOIN tours t ON t.id=r.tour_id WHERE r.id=? AND r.tour_id=? GROUP BY r.id FOR UPDATE");
        $q->execute([$roomId,(int)$booking['tour_id']]);
        $room=$q->fetch();
        if(!$room) throw new RuntimeException('Invalid room.');
        if($room['status']!=='AVAILABLE') throw new RuntimeException('This room is not available.');
        $q=$db->prepare('SELECT room_id FROM room_assignments WHERE tour_passenger_id=? FOR UPDATE');
        $q->execute([(int)$booking['tp_id']]);
        $oldRoomId=(int)($q->fetchColumn()?:0);
        if($oldRoomId===$roomId){$db->commit();$roomMsg='This room is already selected.';}
        else{
            $occupied=(int)$room['occupied'];
            if($occupied >= (int)$room['capacity']) throw new RuntimeException('This room is already full. Please choose another room.');
            $q=$db->prepare('DELETE FROM room_assignments WHERE tour_passenger_id=?');
            $q->execute([(int)$booking['tp_id']]);
            $q=$db->prepare('INSERT INTO room_assignments(tour_passenger_id,room_id,assigned_by) VALUES(?,?,NULL)');
            $q->execute([(int)$booking['tp_id'],$roomId]);
            $db->commit();
            $roomMsg='Room '.$room['room_no'].' has been selected successfully.';
            saas_audit('public.room_selected','tour_passenger',(int)$booking['tp_id'],json_encode(['room_id'=>$roomId,'room_no'=>$room['room_no']],JSON_UNESCAPED_UNICODE));
        }
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();$error=$e->getMessage();}
}
$q=$db->prepare("SELECT r.id,r.room_no,r.room_type,r.capacity,r.status,COUNT(ra.id) occupied,MAX(CASE WHEN ra.tour_passenger_id=? THEN 1 ELSE 0 END) mine FROM rooms r LEFT JOIN room_assignments ra ON ra.room_id=r.id WHERE r.tour_id=? GROUP BY r.id ORDER BY r.room_no");
$q->execute([(int)$booking['tp_id'],(int)$booking['tour_id']]);
$rooms=$q->fetchAll();
$myRoom=null;foreach($rooms as $r){if((int)$r['mine']===1){$myRoom=$r;break;}}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Select Room — <?=saas_h($booking['tour_name'])?></title><style>*{box-sizing:border-box}body{margin:0;background:#f4f7fb;font-family:Inter,Arial,sans-serif;color:#172033}.wrap{max-width:1000px;margin:25px auto;padding:0 16px}.card{background:#fff;border-radius:18px;padding:22px;margin-bottom:18px;box-shadow:0 8px 30px #0000000b}.notice{padding:12px;border-radius:10px;background:#ecfdf3;color:#067647}.err{padding:12px;border-radius:10px;background:#fef3f2;color:#b42318}.rooms{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px}.room{border:1px solid #d0d5dd;border-radius:14px;padding:16px;background:#fff}.room.mine{border:2px solid #155eef}.room.full{opacity:.6;background:#f2f4f7}.badge{display:inline-block;padding:4px 8px;border-radius:999px;font-size:12px;background:#eef4ff;color:#175cd3}.badge.full{background:#f2f4f7;color:#667085}.badge.mine{background:#155eef;color:#fff}.room h3{margin:0 0 7px}.muted{color:#667085;font-size:13px}.btn{margin-top:12px;width:100%;padding:10px 12px;border:0;border-radius:9px;background:#155eef;color:#fff;font-weight:600;cursor:pointer}.btn:disabled{background:#98a2b3;cursor:not-allowed}.current{font-size:16px}.privacy{font-size:12px;color:#667085;margin-top:18px}@media(max-width:500px){.wrap{margin:10px auto}.card{padding:16px}}
</style></head><body><main class="wrap"><section class="card"><h1><?=saas_h($booking['tour_name'])?></h1><p>Welcome, <strong><?=saas_h($booking['full_name'])?></strong> (<?=saas_h($booking['phone'])?>)</p><?php if($roomMsg):?><div class="notice"><?=saas_h($roomMsg)?></div><?php endif;?><?php if($error):?><div class="err"><?=saas_h($error)?></div><?php endif;?><p class="current"><strong>Current room:</strong> <?=saas_h($myRoom['room_no']??'Not selected')?></p></section><section class="card"><h2>Choose your room</h2><p class="muted">Select any available room with space remaining. Your previous room will be replaced when you choose a different one.</p><div class="rooms"><?php foreach($rooms as $r):$occupied=(int)$r['occupied'];$capacity=(int)$r['capacity'];$mine=(int)$r['mine']===1;$full=$occupied>=$capacity&&!$mine;$blocked=$r['status']!=='AVAILABLE';?><article class="room <?=($mine?'mine ':'').(($full||$blocked)?'full':'')?>"><h3>Room <?=saas_h($r['room_no'])?></h3><div><span class="badge"><?=saas_h($r['room_type'])?></span> <?php if($mine):?><span class="badge mine">Yours</span><?php elseif($full):?><span class="badge full">Full</span><?php elseif($blocked):?><span class="badge full">Blocked</span><?php else:?><span class="badge"><?=($capacity-$occupied)?> space<?=($capacity-$occupied)!==1?'s':''?> left</span><?php endif;?></div><p class="muted">Capacity: <?=$capacity?> · Occupied: <?=$occupied?></p><?php if(!$mine):?><form method="post"><input type="hidden" name="csrf_token" value="<?=saas_h(saas_csrf())?>"><input type="hidden" name="room_id" value="<?=$r['id']?>"><button class="btn" type="submit" <?=($full||$blocked?'disabled':'')?>><?=($blocked?'Unavailable':($full?'Full':'Select this room'))?></button></form><?php else:?><p><strong>You are assigned to this room.</strong></p><?php endif;?></article><?php endforeach;?></div><p class="privacy">For privacy, other occupants are not displayed on this public page.</p></section></main></body></html>
