<?php
require_once __DIR__.'/bootstrap_saas.php';
header('Content-Type: application/json; charset=utf-8');
try{
  $tourId=saas_require_tour();
  saas_require_permission('bus.manage');
  if($_SERVER['REQUEST_METHOD']!=='POST')throw new RuntimeException('POST required.');
  saas_check_csrf();
  $passengerId=(int)($_POST['tour_passenger_id']??0);$busId=(int)($_POST['bus_id']??0);$seatId=(int)($_POST['seat_id']??0);
  if(!$passengerId||!$busId||!$seatId)throw new RuntimeException('Passenger, bus and seat are required.');
  $db=saas_db();$db->beginTransaction();
  $q=$db->prepare('SELECT tp.id,pp.full_name FROM tour_passengers tp JOIN passenger_profiles pp ON pp.id=tp.passenger_profile_id WHERE tp.id=? AND tp.tour_id=? FOR UPDATE');$q->execute([$passengerId,$tourId]);$p=$q->fetch();if(!$p)throw new RuntimeException('Passenger does not belong to this tour.');
  $q=$db->prepare('SELECT s.id,s.seat_code,s.bus_id FROM seats s JOIN buses b ON b.id=s.bus_id WHERE s.id=? AND s.bus_id=? AND b.tour_id=? FOR UPDATE');$q->execute([$seatId,$busId,$tourId]);$seat=$q->fetch();if(!$seat)throw new RuntimeException('Seat does not belong to this tour bus.');
  $q=$db->prepare('SELECT pa.tour_passenger_id,pp.full_name FROM passenger_seat_assignments pa JOIN tour_passengers tp ON tp.id=pa.tour_passenger_id JOIN passenger_profiles pp ON pp.id=tp.passenger_profile_id WHERE pa.bus_id=? AND pa.seat_id=? FOR UPDATE');$q->execute([$busId,$seatId]);$occupied=$q->fetch();
  if($occupied && (int)$occupied['tour_passenger_id']!==$passengerId)throw new RuntimeException('Seat '.$seat['seat_code'].' is already occupied by '.$occupied['full_name'].'.');
  $q=$db->prepare('DELETE FROM passenger_seat_assignments WHERE tour_passenger_id=?');$q->execute([$passengerId]);
  $q=$db->prepare('INSERT INTO passenger_seat_assignments(tour_passenger_id,bus_id,seat_id,assigned_by) VALUES(?,?,?,?)');$q->execute([$passengerId,$busId,$seatId,saas_user_id()]);
  $db->commit();saas_audit('seat.assigned','tour_passenger',$passengerId,json_encode(['bus_id'=>$busId,'seat_id'=>$seatId,'seat_code'=>$seat['seat_code']],JSON_UNESCAPED_UNICODE));
  echo json_encode(['ok'=>true,'message'=>$p['full_name'].' assigned to '.$seat['seat_code'].'.']);
}catch(Throwable $e){if(isset($db)&&$db->inTransaction())$db->rollBack();http_response_code(400);echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);}
