<?php
require_once __DIR__.'/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
try{
  $tourId=saas_require_tour();
  saas_require_permission('bus.manage');
  if($_SERVER['REQUEST_METHOD']!=='POST')throw new RuntimeException('POST required.');
  saas_check_csrf();
  $passengerId=(int)($_POST['tour_passenger_id']??0);$busId=(int)($_POST['bus_id']??0);$seatId=(int)($_POST['seat_id']??0);$swap=((string)($_POST['swap']??'')==='1');$sourceSeatId=(int)($_POST['source_seat_id']??0);$targetSeatId=(int)($_POST['target_seat_id']??0);
  if($swap){
    if(!$sourceSeatId||!$targetSeatId||$sourceSeatId===$targetSeatId)throw new RuntimeException('Two different seats are required for a seat change.');
  }elseif(!$passengerId||!$busId||!$seatId){
    throw new RuntimeException('Passenger, bus and seat are required.');
  }
  $db=saas_db();$db->beginTransaction();
  if($swap){
    $q=$db->prepare('SELECT s.id,s.seat_code,s.bus_id,b.name bus_name FROM seats s JOIN buses b ON b.id=s.bus_id WHERE s.id IN (?,?) AND b.tour_id=? FOR UPDATE');$q->execute([$sourceSeatId,$targetSeatId,$tourId]);$swapSeats=$q->fetchAll();
    if(count($swapSeats)!==2)throw new RuntimeException('One or both seats are outside this tour.');
    $seatById=[];foreach($swapSeats as $ss)$seatById[(int)$ss['id']]=$ss;
    $q=$db->prepare('SELECT pa.tour_passenger_id,pa.seat_id,pp.full_name FROM passenger_seat_assignments pa JOIN tour_passengers tp ON tp.id=pa.tour_passenger_id JOIN passenger_profiles pp ON pp.id=tp.passenger_profile_id WHERE pa.seat_id IN (?,?) FOR UPDATE');$q->execute([$sourceSeatId,$targetSeatId]);$assignments=$q->fetchAll();
    $bySeat=[];foreach($assignments as $a)$bySeat[(int)$a['seat_id']]=$a;
    $source=$bySeat[$sourceSeatId]??null;$target=$bySeat[$targetSeatId]??null;
    if(!$source)throw new RuntimeException('Source seat is not occupied.');
    $db->prepare('DELETE FROM passenger_seat_assignments WHERE seat_id IN (?,?)')->execute([$sourceSeatId,$targetSeatId]);
    if($target){
      $ins=$db->prepare('INSERT INTO passenger_seat_assignments(tour_passenger_id,bus_id,seat_id,assigned_by) VALUES(?,?,?,?)');
      $ins->execute([(int)$source['tour_passenger_id'],(int)$seatById[$targetSeatId]['bus_id'],$targetSeatId,saas_user_id()]);
      $ins->execute([(int)$target['tour_passenger_id'],(int)$seatById[$sourceSeatId]['bus_id'],$sourceSeatId,saas_user_id()]);
      $message='Swapped '.$source['full_name'].' and '.$target['full_name'].'.';
    }else{
      $ins=$db->prepare('INSERT INTO passenger_seat_assignments(tour_passenger_id,bus_id,seat_id,assigned_by) VALUES(?,?,?,?)');
      $ins->execute([(int)$source['tour_passenger_id'],(int)$seatById[$targetSeatId]['bus_id'],$targetSeatId,saas_user_id()]);
      $message=$source['full_name'].' moved to '.$seatById[$targetSeatId]['seat_code'].'.';
    }
    $db->commit();saas_audit('seat.changed','tour_passenger',(int)$source['tour_passenger_id'],json_encode(['source_seat_id'=>$sourceSeatId,'target_seat_id'=>$targetSeatId,'swapped'=>(bool)$target],JSON_UNESCAPED_UNICODE));echo json_encode(['ok'=>true,'message'=>$message]);exit;
  }
  $q=$db->prepare('SELECT tp.id,pp.full_name FROM tour_passengers tp JOIN passenger_profiles pp ON pp.id=tp.passenger_profile_id WHERE tp.id=? AND tp.tour_id=? FOR UPDATE');$q->execute([$passengerId,$tourId]);$p=$q->fetch();if(!$p)throw new RuntimeException('Passenger does not belong to this tour.');
  $q=$db->prepare('SELECT s.id,s.seat_code,s.bus_id FROM seats s JOIN buses b ON b.id=s.bus_id WHERE s.id=? AND s.bus_id=? AND b.tour_id=? FOR UPDATE');$q->execute([$seatId,$busId,$tourId]);$seat=$q->fetch();if(!$seat)throw new RuntimeException('Seat does not belong to this tour bus.');
  $q=$db->prepare('SELECT pa.tour_passenger_id,pp.full_name FROM passenger_seat_assignments pa JOIN tour_passengers tp ON tp.id=pa.tour_passenger_id JOIN passenger_profiles pp ON pp.id=tp.passenger_profile_id WHERE pa.bus_id=? AND pa.seat_id=? FOR UPDATE');$q->execute([$busId,$seatId]);$occupied=$q->fetch();
  if($occupied && (int)$occupied['tour_passenger_id']!==$passengerId)throw new RuntimeException('Seat '.$seat['seat_code'].' is already occupied by '.$occupied['full_name'].'.');
  $q=$db->prepare('DELETE FROM passenger_seat_assignments WHERE tour_passenger_id=?');$q->execute([$passengerId]);
  $q=$db->prepare('INSERT INTO passenger_seat_assignments(tour_passenger_id,bus_id,seat_id,assigned_by) VALUES(?,?,?,?)');$q->execute([$passengerId,$busId,$seatId,saas_user_id()]);
  $db->commit();saas_audit('seat.assigned','tour_passenger',$passengerId,json_encode(['bus_id'=>$busId,'seat_id'=>$seatId,'seat_code'=>$seat['seat_code']],JSON_UNESCAPED_UNICODE));
  echo json_encode(['ok'=>true,'message'=>$p['full_name'].' assigned to '.$seat['seat_code'].'.']);
}catch(Throwable $e){if(isset($db)&&$db->inTransaction())$db->rollBack();http_response_code(400);echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);}
