<?php
require __DIR__.'/bootstrap.php';require __DIR__.'/room_inventory.php';
$tour_id=(int)($_GET['tour_id']??0);
$q=db()->query("SELECT * FROM tours WHERE status='ACTIVE' ORDER BY start_date IS NULL, start_date, id DESC");
$tours=$q->fetchAll();
if(!$tour_id && $tours) $tour_id=(int)$tours[0]['id'];
$tour=null; foreach($tours as $x){if((int)$x['id']===$tour_id){$tour=$x;break;}}
if(!$tour && $tours){$tour=$tours[0];$tour_id=(int)$tour['id'];}
$buses=[];$selected_bus=0;$leg=(($_GET['leg']??'outbound')==='return')?'return':'outbound';
$checkAction=$leg==='return'?'CHECK_IN_RETURN':'CHECK_IN_OUTBOUND';
$undoAction=$leg==='return'?'CHECK_IN_RETURN_UNDO':'CHECK_IN_OUTBOUND_UNDO';
function gmjs_leg_checked(int $id,string $checkAction,string $undoAction):bool{$q=db()->prepare("SELECT action FROM audit_log WHERE entity_type='passenger' AND entity_id=? AND action IN (?,?) ORDER BY id DESC LIMIT 1");$q->execute([$id,$checkAction,$undoAction]);return $q->fetchColumn()===$checkAction;}

$isAdmin=!empty($_SESSION['admin_id']);
$seats=[];$booked=[];
if($tour){
  $q=db()->prepare("SELECT * FROM buses WHERE tour_id=? AND active=1 ORDER BY id");$q->execute([$tour_id]);$buses=$q->fetchAll();
  $selected_bus=(int)($_GET['bus_id']??($buses[0]['id']??0));
  foreach($buses as $b){if((int)$b['id']===$selected_bus){$bus=$b;break;}}
  if(empty($bus)&&$buses){$bus=$buses[0];$selected_bus=(int)$bus['id'];}
  if($selected_bus){$q=db()->prepare("SELECT s.id,s.seat_no,s.row_no,s.col_no,p.id passenger_id,p.name passenger_name,
CASE WHEN p.id IS NULL THEN 0 ELSE 1 END booked
FROM seats s LEFT JOIN passengers p ON p.seat_id=s.id AND p.status='ACTIVE'
WHERE s.bus_id=? ORDER BY s.row_no,s.col_no");
$q->execute([$selected_bus]);$seats=$q->fetchAll();
foreach($seats as &$seat){$seat['checked_in']=!empty($seat['passenger_id']) && gmjs_leg_checked((int)$seat['passenger_id'],$checkAction,$undoAction)?1:0;}unset($seat);}
  $contact=public_contact($tour);
  $roomInventory=gmjs_room_inventory($tour_id);
  $layout=json_decode((string)($bus['layout_json']??''),true)?:['type'=>'legacy5'];
  $gridCols=($layout['type']??'legacy5')==='2+3'?'1fr 1fr 30px 1fr 1fr 1fr':(($layout['type']??'legacy5')==='2+2'?'1fr 1fr 30px 1fr 1fr':'repeat(5,minmax(48px,1fr))');
}
function public_phone_href(string $phone):string{return preg_replace('/[^0-9+]/','',$phone);}
function public_wa_href(string $wa):string{ $n=preg_replace('/\D+/','',$wa); return $n?'https://wa.me/'.$n:''; }
?><!doctype html><html><head><?php include __DIR__.'/partials/head.php';?><style>
.public-wrap{max-width:1100px;margin:auto;padding:20px 16px 60px}.public-hero{background:linear-gradient(135deg,#064e49,#0f766e);color:#fff;border-radius:22px;padding:24px;margin-bottom:16px}.public-hero h1{font-size:30px;margin:4px 0 6px}.public-hero p{margin:4px 0;opacity:.9}.public-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}.public-actions a{background:#fff;color:#075e54;border-radius:10px;padding:8px 10px;font-weight:900;font-size:12px}.public-contact{background:#ffffff18;border:1px solid #ffffff35;border-radius:12px;padding:8px 10px;display:flex;gap:7px;align-items:center;flex-wrap:wrap}.public-contact strong{font-size:12px;color:#fff}.public-contact a{background:#fff;color:#075e54}.public-card{background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:16px;margin-bottom:16px}.public-controls{display:grid;grid-template-columns:1fr 1fr;gap:10px}.public-seat-map{max-width:720px;margin:18px auto;display:flex;flex-direction:column;gap:8px;overflow:hidden}.public-seat-row{display:grid;align-items:stretch;gap:8px;width:100%}.public-seat-row.layout-2-2{grid-template-columns:minmax(0,1fr) minmax(0,1fr) 30px minmax(0,1fr) minmax(0,1fr)}.public-seat-row.layout-2-3{grid-template-columns:minmax(0,1fr) minmax(0,1fr) 30px minmax(0,1fr) minmax(0,1fr) minmax(0,1fr)}.public-seat-row.layout-legacy5{grid-template-columns:repeat(5,minmax(0,1fr))}.public-seat-row.front-single{grid-template-columns:minmax(0,1fr) 1fr 1fr 1fr 1fr}.public-seat-row.front-single .public-seat{grid-column:1}.public-seat-row .public-seat{min-width:0}.public-seat-row .public-aisle{min-height:1px}.public-seat{min-height:56px;box-sizing:border-box;border-radius:12px;border:1px solid #bbf7d0;background:#dcfce7;color:#166534;font-weight:900;cursor:pointer}.public-seat.checked-in{background:#dbeafe!important;border-color:#93c5fd!important;color:#1d4ed8!important}.public-seat.booked{background:#fee2e2;border-color:#fecaca;color:#991b1b;cursor:default}.public-seat small{display:block;font-size:9px;margin-top:4px}.public-driver{width:110px;margin:auto;background:#172033;color:#fff;border-radius:8px;padding:7px;text-align:center;font-size:10px;font-weight:900}.public-aisle{min-height:1px}.public-summary{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.public-stat{background:#f8fafc;border-radius:12px;padding:12px}.public-stat span{font-size:11px;color:#718096}.public-stat b{display:block;font-size:20px;margin-top:2px}.public-note{background:#fffbeb;border:1px solid #fde68a;color:#92400e;border-radius:12px;padding:12px;font-size:13px}.public-legend{display:flex;gap:16px;justify-content:center;font-size:12px;color:#64748b;margin:10px 0}.public-dot{display:inline-block;width:12px;height:12px;border-radius:50%;vertical-align:-2px;margin-right:4px}.available-dot{background:#22c55e}.booked-dot{background:#ef4444}@media(max-width:650px){.public-controls,.public-summary{grid-template-columns:1fr}.public-seat-map{gap:5px}.public-seat-row{gap:5px}.public-seat-row.layout-2-2{grid-template-columns:minmax(0,1fr) minmax(0,1fr) 18px minmax(0,1fr) minmax(0,1fr)}.public-seat-row.layout-2-3{grid-template-columns:minmax(0,1fr) minmax(0,1fr) 18px minmax(0,1fr) minmax(0,1fr) minmax(0,1fr)}.public-seat-row.layout-legacy5,.public-seat-row.front-single{grid-template-columns:repeat(5,minmax(0,1fr))}.public-seat{min-height:48px;font-size:11px}.public-hero h1{font-size:23px}}
</style></head><body><main class="public-wrap">
<?php if(!$tour):?><section class="public-card"><h2>No active tour available</h2><p class="muted">Please contact us for upcoming tours.</p></section><?php else:?>
<section class="public-hero"><div class="eyebrow">GMJS TOUR</div><h1><?=h($tour['name'])?></h1><p><?=h($tour['start_date'])?> → <?=h($tour['end_date'])?></p><p><?=h($contact['text'])?></p><div class="public-actions"><?php foreach($contact['contacts'] as $c):?><div class="public-contact"><strong><?=h($c['label']?:'Contact')?></strong><?php if($c['phone']):?><a href="tel:<?=h(public_phone_href($c['phone']))?>">📞 Call</a><?php endif;?><?php if($c['whatsapp']):?><a target="_blank" rel="noopener" href="<?=h(public_wa_href($c['whatsapp']))?>">💬 WhatsApp</a><?php endif;?></div><?php endforeach;?></div></section>
<section class="public-card"><div class="public-controls"><label>Select Tour<select onchange="location.href='seat_plan.php?tour_id='+this.value"><?php foreach($tours as $tt):?><option value="<?=$tt['id']?>" <?=$tt['id']==$tour_id?'selected':''?>><?=h($tt['name'])?> — <?=h($tt['start_date'])?></option><?php endforeach;?></select></label><label>Select Bus<select onchange="location.href='seat_plan.php?tour_id=<?=$tour_id?>&bus_id='+this.value"><?php foreach($buses as $b):?><option value="<?=$b['id']?>" <?=$b['id']==$selected_bus?'selected':''?>><?=h($b['name'])?><?php if(!empty($b['bus_number'])):?> · <?=h($b['bus_number'])?><?php endif;?> · <?=$b['seat_count']?> seats</option><?php endforeach;?></select></label><?php if($isAdmin):?><label>Check-in Journey<select onchange="location.href='seat_plan.php?tour_id=<?=$tour_id?>&bus_id=<?=$selected_bus?>&leg='+this.value"><option value="outbound" <?=$leg==='outbound'?'selected':''?>>🚌 Going / Outbound</option><option value="return" <?=$leg==='return'?'selected':''?>>🔄 Return Journey</option></select></label><?php endif;?></div></section>
<section class="public-card"><div class="head"><div><div class="eyebrow">ROOM AVAILABILITY</div><h2>Room Availability</h2><p class="muted">Current availability for this tour.</p></div></div><div class="public-summary"><?php foreach($roomInventory as $ri):?><div class="public-stat"><span><?=h($ri['label'])?></span><b><?=$ri['available_rooms']?> / <?=$ri['rooms']?></b><small><?=$ri['available_beds']?> slots available<?=$ri['full']?' · FULL':''?></small></div><?php endforeach;?></div></section>
<section class="public-card"><div class="head"><div><div class="eyebrow">PASSENGER VIEW</div><h2><?=h($bus['name']??'Bus Seat Plan')?></h2><?php if(!empty($bus['bus_number'])):?><p class="muted"><b>Bus No:</b> <?=h($bus['bus_number'])?></p><?php endif;?></div></div><div class="public-legend"><span><i class="public-dot available-dot"></i> Available</span><span><i class="public-dot booked-dot"></i> Booked</span></div><div class="public-summary"><div class="public-stat"><span>Total Seats</span><b><?=count($seats)?></b></div><div class="public-stat"><span>Available</span><b><?=count(array_filter($seats,fn($s)=>(int)$s['booked']===0))?></b></div><div class="public-stat"><span>Booked</span><b><?=count(array_filter($seats,fn($s)=>(int)$s['booked']===1))?></b></div></div><div class="public-seat-map">
<div class="public-driver">DRIVER</div>
<?php
$rows=[];
foreach($seats as $ss)$rows[(int)$ss['row_no']][]=$ss;
ksort($rows);
$layoutType=(string)($layout['type']??'legacy5');

foreach($rows as $r=>$rowSeats):
    usort($rowSeats,function($a,$b){return (int)$a['col_no']<=>(int)$b['col_no'];});
    $rowCount=count($rowSeats);
?>
<?php
    $frontSingle = $rowCount === 1 && in_array(strtoupper((string)$rowSeats[0]['seat_no']), ['A0','S1'], true);
    $rowClass = $frontSingle ? 'front-single' : ($layoutType === '2+3' ? 'layout-2-3' : ($layoutType === '2+2' ? 'layout-2-2' : 'layout-legacy5'));
?>
<div class="public-seat-row <?=$rowClass?>">
<?php
    foreach($rowSeats as $i=>$seat):
        /* The aisle is a real grid column between the left and right seat blocks. */
        if(!$frontSingle && $layoutType!=='legacy5' && $i===2): ?>
            <div class="public-aisle" aria-hidden="true"></div>
        <?php endif; ?>
        <button type="button" class="public-seat <?=$seat['booked']?'booked':''?> <?=$seat['checked_in']?'checked-in':''?>" data-seat="<?=h($seat['seat_no'])?>" data-booked="<?=((int)$seat['booked'])?>" data-pid="<?=((int)($seat['passenger_id']??0))?>" data-name="<?=h($seat['passenger_name']??'')?>" data-checked="<?=((int)($seat['checked_in']??0))?>" title="<?=is_logged_in() && $seat['booked']?'Admin: click for Check-in / Undo':'Seat status'?>"><?=h($seat['seat_no'])?><small><?=$seat['booked']?($seat['checked_in']?'✓ CHECKED IN':'BOOKED'):'AVAILABLE'?></small></button>
<?php endforeach; ?>
</div>
<?php endforeach; ?>
</div>
<div class="public-note"><b>Important:</b> Seat এখান থেকে online book করা যাবে না। Available seat পছন্দ হলে উপরের <b>Call Us</b> / <b>WhatsApp</b> দিয়ে আমাদের সাথে যোগাযোগ করুন।</div></section>
<?php endif;?></main><div id="toast" class="toast"></div><div id="checkModal" style="display:none;position:fixed;inset:0;background:#0008;z-index:9999;align-items:center;justify-content:center;padding:18px">
<div style="background:#fff;border-radius:18px;max-width:420px;width:100%;padding:20px;box-shadow:0 20px 60px #0003">
<div class="eyebrow">ADMIN CHECK-IN</div>
<h2 id="cmTitle">Seat</h2>
<p id="cmPassenger" class="muted"></p>
<div id="cmBody"></div>
<div id="cmBusy" style="display:none;text-align:center;padding:12px">Processing…</div>
<div style="display:flex;gap:8px;margin-top:14px">
<button id="cmAction" class="btn primary" type="button" onclick="ajaxCheckin()"></button>
<button class="btn secondary" type="button" onclick="closeCheckModal()">Close</button>
</div>
</div></div>
<script>
const IS_ADMIN=<?=json_encode(is_logged_in())?>;
const CHECKIN_LEG=<?=json_encode($leg)?>;
let activeSeatEl=null, activePid=0;

function seatInfo(el){
 const seat=el.dataset.seat||'', booked=Number(el.dataset.booked||0), pid=Number(el.dataset.pid||0);
 const name=el.dataset.name||'', checked=Number(el.dataset.checked||0);
 const toast=document.getElementById('toast');

 if(IS_ADMIN && booked && pid){
   activeSeatEl=el; activePid=pid;
   document.getElementById('cmTitle').textContent='Seat '+seat+' · '+(CHECKIN_LEG==='return'?'Return':'Outbound');
   document.getElementById('cmPassenger').textContent=name;
   document.getElementById('cmBody').innerHTML=checked
      ? '<div class="alert success">✓ Already checked in</div>'
      : '<div class="alert">Not checked in</div>';
   const btn=document.getElementById('cmAction');
   btn.textContent=checked?'↩ Undo Check-In':'✓ Check In Passenger';
   btn.className='btn '+(checked?'danger':'primary');
   btn.disabled=false;
   document.getElementById('checkModal').style.display='flex';
   return;
 }
 toast.textContent=booked?'Seat '+seat+' is already booked.':'Seat '+seat+' is available. Please contact us to book it.';
 toast.classList.add('show'); setTimeout(()=>toast.classList.remove('show'),2500);
}

async function ajaxCheckin(){
 if(!activePid || !activeSeatEl) return;
 const btn=document.getElementById('cmAction');
 const checked=Number(activeSeatEl.dataset.checked||0)===1;
 const action=checked?'undo':'checkin';
 btn.disabled=true;
 document.getElementById('cmBusy').style.display='block';

 const fd=new FormData();
 fd.append('csrf',<?=json_encode(csrf())?>);
 fd.append('passenger_id',String(activePid));
 fd.append('leg',CHECKIN_LEG);
 fd.append('action',action);

 try{
   const res=await fetch('checkin_ajax.php',{
     method:'POST',
     body:fd,
     credentials:'same-origin',
     headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}
   });
   const data=await res.json();
   if(!res.ok || !data.ok) throw new Error(data.message||'Check-in failed.');

   activeSeatEl.dataset.checked=data.checked_in?'1':'0';
   const small=activeSeatEl.querySelector('small');
   if(small) small.textContent=data.checked_in?'✓ CHECKED IN':'BOOKED';
   activeSeatEl.classList.toggle('checked-in',!!data.checked_in);

   document.getElementById('cmBody').innerHTML=data.checked_in
      ? '<div class="alert success">✓ Checked in successfully</div>'
      : '<div class="alert">↩ Check-in undone successfully</div>';
   btn.textContent=data.checked_in?'↩ Undo Check-In':'✓ Check In Passenger';
   btn.className='btn '+(data.checked_in?'danger':'primary');
   btn.disabled=false;

   const toast=document.getElementById('toast');
   toast.textContent=data.message;
   toast.classList.add('show'); setTimeout(()=>toast.classList.remove('show'),2500);
 }catch(err){
   document.getElementById('cmBody').innerHTML='<div class="alert danger">'+escapeHtml(err.message)+'</div>';
   btn.disabled=false;
 }finally{
   document.getElementById('cmBusy').style.display='none';
 }
}
function escapeHtml(v){return String(v).replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));}
document.querySelectorAll('.public-seat').forEach(el=>el.addEventListener('click',()=>seatInfo(el)));
function closeCheckModal(){document.getElementById('checkModal').style.display='none';activeSeatEl=null;activePid=0;}
document.getElementById('checkModal')?.addEventListener('click',e=>{if(e.target.id==='checkModal')closeCheckModal();});
</script></body></html>
