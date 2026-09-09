<?php
require __DIR__.'/bootstrap.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

try {
    if($_SERVER['REQUEST_METHOD']!=='POST') throw new Exception('POST required.');
    check_csrf();

    $id=(int)($_POST['passenger_id']??0);
    $leg=(($_POST['leg']??'outbound')==='return')?'return':'outbound';
    $action=$_POST['action']??'checkin';

    if($id<=0) throw new Exception('Invalid passenger ID.');

    $tid=require_tour();
    $q=db()->prepare("SELECT p.id,p.name,p.tour_id,s.seat_no,b.name bus_name
                      FROM passengers p
                      LEFT JOIN seats s ON s.id=p.seat_id
                      LEFT JOIN buses b ON b.id=p.bus_id
                      WHERE p.id=? AND p.tour_id=? AND p.status='ACTIVE' LIMIT 1");
    $q->execute([$id,$tid]);
    $p=$q->fetch();
    if(!$p) throw new Exception('Passenger not found for this tour.');

    $checkAction=$leg==='return'?'CHECK_IN_RETURN':'CHECK_IN_OUTBOUND';
    $undoAction=$leg==='return'?'CHECK_IN_RETURN_UNDO':'CHECK_IN_OUTBOUND_UNDO';

    $q=db()->prepare("SELECT action FROM audit_log
                      WHERE entity_type='passenger' AND entity_id=?
                      AND action IN (?,?) ORDER BY id DESC LIMIT 1");
    $q->execute([$id,$checkAction,$undoAction]);
    $checked=$q->fetchColumn()===$checkAction;

    if($action==='checkin'){
        if(!$checked){
            audit($checkAction,'passenger',$id,ucfirst($leg).' journey check-in via AJAX');
            $checked=true;
        }
        $message=$p['name'].' checked in for the '.($leg==='return'?'return':'outbound').' journey.';
    } elseif($action==='undo'){
        if($checked){
            audit($undoAction,'passenger',$id,ucfirst($leg).' journey check-in undone via AJAX');
            $checked=false;
        }
        $message=$p['name'].' check-in undone for the '.($leg==='return'?'return':'outbound').' journey.';
    } else {
        throw new Exception('Invalid check-in action.');
    }

    echo json_encode([
        'ok'=>true,
        'checked_in'=>$checked,
        'message'=>$message,
        'name'=>$p['name'],
        'seat'=>$p['seat_no']??'',
        'leg'=>$leg
    ],JSON_UNESCAPED_UNICODE);
} catch(Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);
}
