<?php
require __DIR__.'/bootstrap.php';require __DIR__.'/bootstrap_auth.php';require_login();
header('Content-Type: application/json; charset=utf-8');
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(['ok'=>false,'message'=>'POST required.']);exit;}
try{
 check_csrf();require_permission($mode==='swap'?'seat.swap':'seat.move');
 $passengerId=(int)($_POST['passenger_id']??0);$targetSeatId=(int)($_POST['target_seat_id']??0);$mode=($_POST['mode']??'move')==='swap'?'swap':'move';
 if(!$passengerId||!$targetSeatId)throw new Exception('Passenger and target seat are required.');
 $pdo=db();$pdo->beginTransaction();
 $q=$pdo->prepare("SELECT p.id,p.tour_id,p.bus_id,p.seat_id,p.name,b.name bus_name,s.seat_no FROM passengers p JOIN buses b ON b.id=p.bus_id JOIN seats s ON s.id=p.seat_id WHERE p.id=? AND p.status='ACTIVE' FOR UPDATE");$q->execute([$passengerId]);$p=$q->fetch();if(!$p)throw new Exception('Passenger not found.');
 $tid=require_tour();if((int)$p['tour_id']!==$tid)throw new Exception('Passenger is outside the current tour.');
 $q=$pdo->prepare("SELECT s.id,s.bus_id,s.seat_no,b.name bus_name,b.tour_id FROM seats s JOIN buses b ON b.id=s.bus_id WHERE s.id=? FOR UPDATE");$q->execute([$targetSeatId]);$target=$q->fetch();if(!$target|| (int)$target['tour_id']!==$tid)throw new Exception('Target seat is invalid.');
 if((int)$target['id']===(int)$p['seat_id']){throw new Exception('Passenger is already on this seat.');}
 $q=$pdo->prepare("SELECT p.id,p.name,p.bus_id,p.seat_id,b.name bus_name,s.seat_no FROM passengers p JOIN buses b ON b.id=p.bus_id JOIN seats s ON s.id=p.seat_id WHERE p.seat_id=? AND p.status='ACTIVE' FOR UPDATE");$q->execute([$targetSeatId]);$occupant=$q->fetch();
 if($occupant && $mode!=='swap')throw new Exception('Target seat is occupied. Use Exchange to swap passengers.');
 if($occupant){
   $q=$pdo->prepare("UPDATE passengers SET bus_id=?,seat_id=? WHERE id=? AND tour_id=? AND status='ACTIVE'");
   $q->execute([(int)$p['bus_id'],(int)$p['seat_id'],(int)$occupant['id'],$tid]);
   $q->execute([(int)$target['bus_id'],(int)$target['id'],$passengerId,$tid]);
   audit('EXCHANGE_PASSENGER_SEATS','passenger',$passengerId,$p['name'].' · '.$p['bus_name'].'/'.$p['seat_no'].' ↔ '.$occupant['name'].' · '.$occupant['bus_name'].'/'.$occupant['seat_no']);
   audit('EXCHANGE_PASSENGER_SEATS','passenger',(int)$occupant['id'],$p['name'].' · '.$p['bus_name'].'/'.$p['seat_no'].' ↔ '.$occupant['name'].' · '.$occupant['bus_name'].'/'.$occupant['seat_no']);
   $message='Passenger seats exchanged successfully.';
 }else{
   $q=$pdo->prepare("UPDATE passengers SET bus_id=?,seat_id=? WHERE id=? AND tour_id=? AND status='ACTIVE'");$q->execute([(int)$target['bus_id'],(int)$target['id'],$passengerId,$tid]);
   audit('MOVE_PASSENGER_SEAT','passenger',$passengerId,$p['name'].' · '.$p['bus_name'].'/'.$p['seat_no'].' → '.$target['bus_name'].'/'.$target['seat_no']);
   $message='Passenger moved successfully.';
 }
 $pdo->commit();echo json_encode(['ok'=>true,'message'=>$message,'bus_id'=>(int)$target['bus_id'],'seat_id'=>(int)$target['id']]);
}catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();http_response_code(400);echo json_encode(['ok'=>false,'message'=>$e->getMessage()]);}
