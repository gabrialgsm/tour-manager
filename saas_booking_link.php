<?php
declare(strict_types=1);
require __DIR__.'/bootstrap_saas.php';
saas_require_login();
$tourId=saas_require_tour();
saas_require_permission('tour.update');
$db=saas_db();
$passengerId=(int)($_GET['passenger_id']??$_POST['passenger_id']??0);
if($passengerId<=0){http_response_code(400);exit('Passenger is required.');}
$q=$db->prepare("SELECT tp.id,tp.status,pp.full_name,pp.phone,t.name tour_name,t.slug FROM tour_passengers tp JOIN passenger_profiles pp ON pp.id=tp.passenger_profile_id JOIN tours t ON t.id=tp.tour_id WHERE tp.id=? AND tp.tour_id=? AND t.organization_id=? LIMIT 1");
$q->execute([$passengerId,$tourId,saas_current_organization_id()]);
$booking=$q->fetch();
if(!$booking){http_response_code(404);exit('Passenger not found.');}
$error='';$token='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  saas_check_csrf();
  $token=bin2hex(random_bytes(32));
  $hash=hash('sha256',$token);
  $q=$db->prepare('UPDATE tour_passengers SET booking_access_token_hash=?,booking_access_token_created_at=NOW(),booking_access_token_revoked_at=NULL WHERE id=? AND tour_id=?');
  $q->execute([$hash,$passengerId,$tourId]);
  saas_audit('booking.access_link_generated','tour_passenger',$passengerId,json_encode(['revoked_previous'=>true],JSON_UNESCAPED_UNICODE));
 }catch(Throwable $e){$error='Could not generate the booking access link.';}
}
if($token===''){
 $q=$db->prepare('SELECT booking_access_token_created_at FROM tour_passengers WHERE id=? AND tour_id=?');$q->execute([$passengerId,$tourId]);$created=$q->fetchColumn();
}
$base=rtrim(dirname((string)($_SERVER['SCRIPT_NAME']??'/')),'/');
$link=$base.'/public_booking.php?slug='.rawurlencode((string)$booking['slug']).'&token='.rawurlencode($token);
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Booking Access Link</title><style>*{box-sizing:border-box}body{margin:0;background:#f4f7fb;font-family:Arial,sans-serif;color:#172033}.wrap{max-width:720px;margin:35px auto;padding:0 16px}.card{background:#fff;border-radius:16px;padding:24px;box-shadow:0 8px 30px #0000000b}label{display:block;font-weight:700;margin:12px 0 6px}input,button{width:100%;padding:12px;border:1px solid #d0d5dd;border-radius:9px;font:inherit}button{background:#155eef;color:#fff;border-color:#155eef;font-weight:700;cursor:pointer;margin-top:12px}.ok{padding:12px;background:#ecfdf3;color:#067647;border-radius:9px}.err{padding:12px;background:#fef3f2;color:#b42318;border-radius:9px}.muted{color:#667085;font-size:13px}.link{word-break:break-all;background:#f8fafc;padding:12px;border-radius:9px;margin-top:8px}</style></head><body><main class="wrap"><div class="card"><p><a href="saas_registrations.php">← Registrations</a></p><h1>Booking Access Link</h1><p><strong><?=saas_h($booking['full_name'])?></strong> · <?=saas_h($booking['phone']??'')?> · <?=saas_h($booking['tour_name'])?></p><?php if($error):?><div class="err"><?=saas_h($error)?></div><?php endif;?><?php if($token):?><div class="ok"><strong>New secure link generated.</strong><br>For security, the token is stored only as a hash. Copy this link now and send it to the customer.</div><div class="link"><?=saas_h($link)?></div><?php else:?><p class="muted">Generate a new random access token. Generating another link later will revoke the previous token.</p><form method="post"><input type="hidden" name="csrf" value="<?=saas_h(saas_csrf())?>"><input type="hidden" name="passenger_id" value="<?=$passengerId?>"><button>Generate secure booking link</button></form><?php endif;?></div></main></body></html>
