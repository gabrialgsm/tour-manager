<?php
if (!function_exists('gmjs_room_types')) {
function gmjs_room_types():array{return [
'AC_COUPLE'=>'AC Couple','NON_AC_COUPLE'=>'Non-AC Couple',
'AC_4_BED'=>'AC 4 Bed','NON_AC_4_BED'=>'Non-AC 4 Bed','AC_TWIN_BED'=>'AC Twin Bed'];}
}
if (!function_exists('gmjs_room_inventory')) {
function gmjs_room_inventory(int $tourId):array{
$out=[]; foreach(gmjs_room_types() as $key=>$label){
$q=db()->prepare("SELECT COUNT(*) rooms,COALESCE(SUM(capacity),0) beds FROM rooms WHERE tour_id=? AND room_type=?");$q->execute([$tourId,$key]);$r=$q->fetch()?:['rooms'=>0,'beds'=>0];
$q=db()->prepare("SELECT COUNT(*) FROM passengers WHERE tour_id=? AND room_type=? AND status='ACTIVE'");$q->execute([$tourId,$key]);$used=(int)$q->fetchColumn();
$total=(int)$r['beds'];$avail=max(0,$total-$used);
$q=db()->prepare("SELECT COUNT(*) FROM rooms r WHERE r.tour_id=? AND r.room_type=? AND (SELECT COUNT(*) FROM room_assignments ra JOIN passengers p ON p.id=ra.passenger_id WHERE ra.room_id=r.id AND p.status='ACTIVE')<r.capacity");$q->execute([$tourId,$key]);$ar=(int)$q->fetchColumn();
$out[$key]=['label'=>$label,'rooms'=>(int)$r['rooms'],'available_rooms'=>$ar,'beds'=>$total,'used_beds'=>$used,'available_beds'=>$avail,'full'=>$avail<=0];
} return $out;}
}