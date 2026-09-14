<?php
declare(strict_types=1);
require __DIR__.'/bootstrap_saas.php';
saas_require_login();
$tourId=saas_require_tour();
saas_require_permission('passenger.create');
$db=saas_db();
$passengerId=(int)($_GET['passenger_id']??$_POST['passenger_id']??0);
if($passengerId<=0){http_response_code(400);exit('Passenger is required.');}
$q=$db->prepare("SELECT tp.id,tp.status,pp.full_name,pp.phone,t.name tour_name,t.organization_id FROM tour_passengers tp JOIN passenger_profiles pp ON pp.id=tp.passenger_profile_id JOIN tours t ON t.id=tp.tour_id WHERE tp.id=? AND tp.tour_id=? AND t.organization_id=? LIMIT 1");$q->execute([$passengerId,$tourId,saas_current_organization_id()]);$booking=$q->fetch();
if(!$booking||$booking['status']!=='ACTIVE'){http_response_code(404);exit('Active passenger not found.');}
$error='';$ticket=null;$rawToken='';
$q=$db->prepare('SELECT * FROM ticket_instances WHERE tour_passenger_id=? LIMIT 1');$q->execute([$passengerId]);$existing=$q->fetch();
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{saas_check_csrf();$db->beginTransaction();
  $q=$db->prepare('SELECT id FROM ticket_instances WHERE tour_passenger_id=? FOR UPDATE');$q->execute([$passengerId]);$old=$q->fetchColumn();
  if($old){$q=$db->prepare("UPDATE ticket_instances SET qr_token_revoked_at=NOW(),status='VOID',voided_at=NOW(),updated_at=NOW() WHERE id=?");$q->execute([(int)$old]);}
  $rawToken=bin2hex(random_bytes(32));$hash=hash('sha256',$rawToken);$ticketNumber='TM-'.date('Ym').'-'.strtoupper(bin2hex(random_bytes(4)));
  $q=$db->prepare('INSERT INTO ticket_instances(tour_passenger_id,organization_id,tour_id,ticket_number,qr_token_hash,status) VALUES(?,?,?,?,?,\'ISSUED\')');$q->execute([$passengerId,(int)$booking['organization_id'],$tourId,$ticketNumber,$hash]);$ticketId=(int)$db->lastInsertId();$db->commit();
  saas_audit('ticket.issued','ticket_instance',$ticketId,json_encode(['tour_passenger_id'=>$passengerId,'ticket_number'=>$ticketNumber],JSON_UNESCAPED_UNICODE));
  $ticket=['id'=>$ticketId,'ticket_number'=>$ticketNumber];
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();$error='Could not issue the ticket.';}
}
if(!$ticket&&$existing)$ticket=$existing;
$verifyUrl='ticket_verify.php?ticket='.rawurlencode((string)($ticket['ticket_number']??''));
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Issue Ticket</title><style>body{margin:0;background:#f4f7fb;font-family:Arial,sans-serif;color:#172033}.wrap{max-width:720px;margin:35px auto;padding:0 16px}.card{background:#fff;border-radius:16px;padding:24px;box-shadow:0 8px 30px #0000000b}.btn{width:100%;padding:12px;border:0;border-radius:9px;background:#155eef;color:#fff;font-weight:700;cursor:pointer}.ok{padding:12px;background:#ecfdf3;color:#067647;border-radius:9px;margin:15px 0}.err{padding:12px;background:#fef3f2;color:#b42318;border-radius:9px;margin:15px 0}.muted{color:#667085;font-size:13px}.link{word-break:break-all;background:#f8fafc;padding:12px;border-radius:9px}</style></head><body><main class="wrap"><div class="card"><p><a href="saas_registrations.php">← Registrations</a></p><h1>QR Ticket</h1><p><strong><?=saas_h($booking['full_name'])?></strong> · <?=saas_h($booking['phone']??'')?></p><?php if($error):?><div class="err"><?=saas_h($error)?></div><?php endif;?><?php if($ticket):?><div class="ok"><strong>Ticket <?=saas_h($ticket['ticket_number'])?> is issued.</strong><br>Issuing a new ticket revokes the previous ticket.</div><p class="muted">Verification endpoint:</p><div class="link"><?=saas_h($verifyUrl)?></div><?php endif;?><form method="post"><input type="hidden" name="csrf" value="<?=saas_h(saas_csrf())?>"><input type="hidden" name="passenger_id" value="<?=$passengerId?>"><button class="btn" type="submit"><?=($ticket?'Reissue QR ticket':'Issue QR ticket')?></button></form></div></main></body></html>