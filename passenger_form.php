<?php require __DIR__.'/bootstrap.php';require __DIR__.'/room_inventory.php';require_login();$tid=require_tour();$t=tour_row($tid);$roomInventory=gmjs_room_inventory($tid);$id=(int)($_GET['id']??0);$p=null;$bus_id=(int)($_GET['bus_id']??0);$seat_id=(int)($_GET['seat_id']??0);if($id){$q=db()->prepare("SELECT * FROM passengers WHERE id=? AND tour_id=?");$q->execute([$id,$tid]);$p=$q->fetch();if(!$p)redirect('passengers.php');$bus_id=(int)$p['bus_id'];$seat_id=(int)$p['seat_id'];}
$initialPayment=null;if($id){$q=db()->prepare("SELECT * FROM payments WHERE passenger_id=? ORDER BY paid_at ASC,id ASC LIMIT 1");$q->execute([$id]);$initialPayment=$q->fetch();}
$checkinState=['outbound'=>false,'return'=>false];
if($id){
  $cq=db()->prepare("SELECT action FROM audit_log WHERE entity_type='passenger' AND entity_id=? AND action IN ('CHECK_IN_OUTBOUND','CHECK_IN_OUTBOUND_UNDO','CHECK_IN_RETURN','CHECK_IN_RETURN_UNDO') ORDER BY id DESC");
  $cq->execute([$id]);
  foreach($cq->fetchAll(PDO::FETCH_COLUMN) as $ca){
    if($ca==='CHECK_IN_OUTBOUND' && !$checkinState['outbound'])$checkinState['outbound']=true;
    if($ca==='CHECK_IN_OUTBOUND_UNDO' && $checkinState['outbound'])$checkinState['outbound']=false;
    if($ca==='CHECK_IN_RETURN' && !$checkinState['return'])$checkinState['return']=true;
    if($ca==='CHECK_IN_RETURN_UNDO' && $checkinState['return'])$checkinState['return']=false;
  }
}

$errors=[];
if($_SERVER['REQUEST_METHOD']==='POST'){check_csrf();$bus_id=(int)$_POST['bus_id'];$seat_id=(int)$_POST['seat_id'];$name=trim($_POST['name']);$phone=trim($_POST['phone']);$tour=(float)$_POST['tour_fee'];$discount=max(0,(float)$_POST['discount']);$final=max(0,$tour-$discount);$room=$_POST['room_type'];
if(!isset($roomInventory[$room]))$errors[]='Invalid room category.';
else{
  $extraCurrent=($id && (($p['room_type']??'')===$room))?1:0;
  if($roomInventory[$room]['available_beds']+$extraCurrent<=0)$errors[]='This room type is full and unavailable.';
}
if(!$name||!$phone)$errors[]='Name and phone are required.';if($discount>$tour)$errors[]='Discount cannot exceed tour fee.';
if(!$errors){$pdo=db();$pdo->beginTransaction();try{
$q=$pdo->prepare("SELECT id FROM seats WHERE id=? AND bus_id=?");$q->execute([$seat_id,$bus_id]);if(!$q->fetch())throw new Exception('Invalid seat.');
$q=$pdo->prepare("SELECT id FROM passengers WHERE seat_id=? AND status='ACTIVE' AND id<>? FOR UPDATE");$q->execute([$seat_id,$id]);if($q->fetch())throw new Exception('This seat is already booked.');
if($id){$q=$pdo->prepare("UPDATE passengers SET bus_id=?,seat_id=?,name=?,phone=?,address=?,blood_group=?,emergency_contact=?,departure=?,room_type=?,tour_fee=?,discount=?,final_fee=?,notes=? WHERE id=? AND tour_id=?");$q->execute([$bus_id,$seat_id,$name,$phone,$_POST['address'],$_POST['blood_group'],$_POST['emergency_contact'],$_POST['departure'],$room,$tour,$discount,$final,$_POST['notes'],$id,$tid]);audit('UPDATE_PASSENGER','passenger',$id,$name);}
else{$q=$pdo->prepare("INSERT INTO passengers(tour_id,bus_id,seat_id,name,phone,address,blood_group,emergency_contact,departure,room_type,tour_fee,discount,final_fee,notes) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)");$q->execute([$tid,$bus_id,$seat_id,$name,$phone,$_POST['address'],$_POST['blood_group'],$_POST['emergency_contact'],$_POST['departure'],$room,$tour,$discount,$final,$_POST['notes']]);$id=(int)$pdo->lastInsertId();audit('BOOK_SEAT','passenger',$id,$name);}
$payRaw=trim((string)($_POST['payment']??''));
if($p){
  if($payRaw!==''){
    $newPay=(float)$payRaw;
    $q=$pdo->prepare("SELECT id,COALESCE((SELECT SUM(amount) FROM payments px WHERE px.passenger_id=payments.passenger_id AND px.id<>payments.id),0) other_paid FROM payments WHERE passenger_id=? ORDER BY paid_at ASC,id ASC LIMIT 1");$q->execute([$id]);$first=$q->fetch();
    if($newPay<0)throw new Exception('Initial payment cannot be negative.');
    if($newPay>0 && $first){if((float)$first['other_paid']+$newPay>$final)throw new Exception('Initial payment plus other payments cannot exceed final fee.');$q=$pdo->prepare("UPDATE payments SET amount=?,method=?,reference=? WHERE id=?");$q->execute([$newPay,$_POST['payment_method']??($initialPayment['method']??'Cash'),trim($_POST['payment_reference']??($initialPayment['reference']??'')),$first['id']]);audit('UPDATE_INITIAL_PAYMENT','payment',(int)$first['id'],(string)$newPay);}
    elseif($newPay<=0 && $first){$pdo->prepare("DELETE FROM payments WHERE id=?")->execute([$first['id']]);audit('DELETE_INITIAL_PAYMENT','payment',(int)$first['id']);}
    elseif($newPay>0){$q=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE passenger_id=?");$q->execute([$id]);$others=(float)$q->fetchColumn();if($others+$newPay>$final)throw new Exception('Payment cannot exceed final fee.');$q=$pdo->prepare("INSERT INTO payments(passenger_id,amount,method,reference,note,created_by) VALUES(?,?,?,?,?,?)");$q->execute([$id,$newPay,$_POST['payment_method']??'Cash',trim($_POST['payment_reference']??''),'Booking advance',$_SESSION['admin_id']]);}
  }
}else{$pay=(float)($_POST['payment']??0);if($pay<0||$pay>$final)throw new Exception('Invalid initial payment amount.');if($pay>0){$q=$pdo->prepare("INSERT INTO payments(passenger_id,amount,method,reference,note,created_by) VALUES(?,?,?,?,?,?)");$q->execute([$id,$pay,$_POST['payment_method']??'Cash',trim($_POST['payment_reference']??''),'Initial payment',$_SESSION['admin_id']]);}}
$q=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE passenger_id=?");$q->execute([$id]);$projectedPaid=(float)$q->fetchColumn();if($projectedPaid>$final)throw new Exception('Final fee cannot be lower than the total payments received.');
$pdo->commit();flash('success',($p?'Passenger updated: ':'Booked: ').$name);redirect('dashboard.php');
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$errors[]=$e->getMessage();}}}
$q=db()->prepare("SELECT * FROM buses WHERE tour_id=? AND active=1 ORDER BY id");$q->execute([$tid]);$buses=$q->fetchAll();if(!$bus_id&&$buses)$bus_id=(int)$buses[0]['id'];$q=db()->prepare("SELECT * FROM seats WHERE bus_id=? ORDER BY row_no,col_no");$q->execute([$bus_id]);$seats=$q->fetchAll();
?><!doctype html><html><head><?php include __DIR__.'/partials/head.php';?></head><body><?php include __DIR__.'/partials/nav.php';?><main class="wrap"><section class="card"><div class="head"><div><div class="eyebrow">PASSENGER INFORMATION</div><h2><?=$p?'Update Passenger':'Book Seat'?></h2></div><div><a class="btn secondary" href="dashboard.php">Back</a><?php if($p):?><a class="btn primary" href="ticket.php?id=<?=$p['id']?>">Ticket</a><?php endif;?></div></div><?php foreach($errors as $e):?><div class="alert danger"><?=h($e)?></div><?php endforeach;?>
<?php if($p):?>
<section class="card" style="margin:0 0 16px;border:1px solid #dcefe3;background:#fbfffc">
  <div class="head"><div><div class="eyebrow">TOUR DAY CHECK-IN</div><h3 style="margin:3px 0">Passenger Check-in</h3><p class="muted" style="margin:0">Check in from this passenger form without leaving the page.</p></div></div>
  <div class="formgrid" style="grid-template-columns:1fr 1fr">
    <div style="padding:12px;border:1px solid #e6ece8;border-radius:12px">
      <b>🚌 Outbound / Going</b><div id="ciOutStatus" class="ci-status <?=!empty($checkinState['outbound'])?'done':''?>"><?=!empty($checkinState['outbound'])?'✓ Checked In':'Not Checked In'?></div>
      <button type="button" id="ciOutBtn" class="btn <?=!empty($checkinState['outbound'])?'danger':'primary'?>" style="margin-top:8px" data-leg="outbound"><?=!empty($checkinState['outbound'])?'↩ Undo Check-In':'✓ Check In'?></button>
    </div>
    <div style="padding:12px;border:1px solid #e6ece8;border-radius:12px">
      <b>🔄 Return Journey</b><div id="ciReturnStatus" class="ci-status <?=!empty($checkinState['return'])?'done':''?>"><?=!empty($checkinState['return'])?'✓ Checked In':'Not Checked In'?></div>
      <button type="button" id="ciReturnBtn" class="btn <?=!empty($checkinState['return'])?'danger':'primary'?>" style="margin-top:8px" data-leg="return"><?=!empty($checkinState['return'])?'↩ Undo Check-In':'✓ Check In'?></button>
    </div>
  </div>
  <div id="ciMsg" style="display:none;margin-top:10px"></div>
</section>
<?php endif;?>
<form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><div class="formgrid">
<label>Bus<select id="passengerBus" name="bus_id"><?php foreach($buses as $b):?><option value="<?=$b['id']?>" <?=$bus_id==$b['id']?'selected':''?>><?=h($b['name'])?> · <?=$b['seat_count']?> seats</option><?php endforeach;?></select><small id="busSeatHint" class="muted">Select a bus to load its available seats.</small></label>
<label>Seat<select id="passengerSeat" name="seat_id"><?php foreach($seats as $s):$x=db()->prepare("SELECT COUNT(*) FROM passengers WHERE seat_id=? AND status='ACTIVE' AND id<>?");$x->execute([$s['id'],$id]);$taken=(int)$x->fetchColumn()>0;?><option value="<?=$s['id']?>" <?=$seat_id==$s['id']?'selected':''?> <?=$taken?'disabled':''?>><?=h($s['seat_no'])?> <?=$taken?'— BOOKED':''?></option><?php endforeach;?></select><small id="seatLoadStatus" class="muted"></small></label>
<label>Name<input name="name" value="<?=h($p['name']??'')?>" required></label><label>Phone<input name="phone" value="<?=h($p['phone']??'')?>" required></label>
<label>Address<textarea name="address"><?=h($p['address']??'')?></textarea></label><label>Emergency Contact<input name="emergency_contact" value="<?=h($p['emergency_contact']??'')?>"></label>
<label>Blood Group<select name="blood_group">
<option value="">Select Blood Group</option>
<?php foreach(['A+','A-','B+','B-','AB+','AB-','O+','O-'] as $bg): ?>
<option value="<?=$bg?>" <?=($p['blood_group']??'')===$bg?'selected':''?>><?=$bg?></option>
<?php endforeach; ?>
</select></label>
<label>Departure Place<select name="departure">
<option value="">Select Departure Place</option>
<?php foreach(['Dhapari','TNT Math','Baksanagar','Other'] as $dp): ?>
<option value="<?=$dp?>" <?=($p['departure']??'Dhapari')===$dp?'selected':''?>><?=$dp?></option>
<?php endforeach; ?>
</select></label>
<label>Room Category<select name="room_type" required><?php foreach($roomInventory as $k=>$ri):$selected=(($p['room_type']??'AC_4_BED')===$k);$disabled=(!$selected && $ri['available_beds']<=0);?><option value="<?=h($k)?>" <?=$selected?'selected':''?> <?=$disabled?'disabled':''?>><?=h($ri['label'])?> — <?=$ri['available_beds']?> available<?=$disabled?' (FULL)':''?></option><?php endforeach;?></select><small class="muted">Full room types cannot be selected for a new booking.</small></label>
<label>Tour Fee<input type="number" name="tour_fee" min="0" value="<?=h($p['tour_fee']??$t['default_fee'])?>"></label><label>Discount / Assistance<input type="number" name="discount" min="0" value="<?=h($p['discount']??0)?>"></label>
<?php if(!$p):?><label>Payment Now<input type="number" name="payment" min="0" step="0.01" value="<?=h($t['booking_fee'])?>"></label><label>Payment Method<select name="payment_method"><option>Cash</option><option>bKash</option><option>Nagad</option><option>Bank</option><option>Rocket</option></select></label><label>Payment Reference<input name="payment_reference"></label><?php else: ?><label>Booking Advance (first payment)<input type="number" name="payment" min="0" step="0.01" value="<?=h($initialPayment['amount']??'')?>"><small class="muted">Enter 0 to remove the first payment, or leave unchanged if blank.</small></label><label>Advance Method<select name="payment_method"><?php foreach(['Cash','bKash','Nagad','Bank','Rocket'] as $m):?><option <?=($initialPayment['method']??'Cash')===$m?'selected':''?>><?=$m?></option><?php endforeach;?></select></label><label>Advance Reference<input name="payment_reference" value="<?=h($initialPayment['reference']??'')?>"></label><?php endif;?>
<label class="full">Notes<textarea name="notes"><?=h($p['notes']??'')?></textarea></label></div><button class="btn primary"><?=$p?'Update Passenger':'Book Seat'?></button></form></section></main>
<?php if($p):?>
<style>
.ci-status{display:inline-block;margin:9px 0 0 8px;padding:6px 9px;border-radius:8px;font-size:12px;font-weight:800;background:#fef3c7;color:#92400e}
.ci-status.done{background:#dbeafe;color:#1d4ed8}
#ciMsg{padding:9px 11px;border-radius:9px;background:#ecfdf5;color:#166534;font-weight:700}
#ciMsg.error{background:#fef2f2;color:#991b1b}
</style>
<script>
(function(){
const CI_ID=<?=json_encode((int)$p['id'])?>, CI_CSRF=<?=json_encode(csrf())?>;
async function doCheckin(leg,btn){
 const status=document.getElementById(leg==='return'?'ciReturnStatus':'ciOutStatus');
 const msg=document.getElementById('ciMsg');
 const checked=status.classList.contains('done');
 if(!confirm((checked?'Undo':'Check in')+' this passenger for '+(leg==='return'?'Return':'Outbound')+' journey?'))return;
 btn.disabled=true;
 const fd=new FormData();fd.append('csrf',CI_CSRF);fd.append('passenger_id',CI_ID);fd.append('leg',leg);fd.append('action',checked?'undo':'checkin');
 try{
   const r=await fetch('checkin_ajax.php',{method:'POST',body:fd,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}});
   const d=await r.json();
   if(!r.ok||!d.ok)throw new Error(d.message||'Check-in failed.');
   status.classList.toggle('done',!!d.checked_in);
   status.textContent=d.checked_in?'✓ Checked In':'Not Checked In';
   btn.textContent=d.checked_in?'↩ Undo Check-In':'✓ Check In';
   btn.className='btn '+(d.checked_in?'danger':'primary');
   btn.disabled=false;
   msg.className='';msg.textContent=d.message;msg.style.display='block';
   setTimeout(()=>msg.style.display='none',3000);
 }catch(e){
   btn.disabled=false;msg.className='error';msg.textContent=e.message;msg.style.display='block';
 }
}
document.getElementById('ciOutBtn')?.addEventListener('click',function(){doCheckin('outbound',this)});
document.getElementById('ciReturnBtn')?.addEventListener('click',function(){doCheckin('return',this)});
})();
</script>
<?php endif;?>

<style>
#busSeatHint,#seatLoadStatus{display:block;margin-top:5px;font-size:11px}
#seatLoadStatus.loading{color:#92400e}
#seatLoadStatus.success{color:#166534}
#seatLoadStatus.error{color:#991b1b}
</style>
<script>
(function(){
  const busSelect = document.getElementById('passengerBus');
  const seatSelect = document.getElementById('passengerSeat');
  const status = document.getElementById('seatLoadStatus');
  const hint = document.getElementById('busSeatHint');
  if(!busSelect || !seatSelect) return;

  const currentPassengerId = <?=json_encode((int)$id)?>;
  const initialSeatId = <?=json_encode((int)$seat_id)?>;
  let requestNo = 0;

  function setStatus(message, type=''){
    if(!status) return;
    status.textContent = message;
    status.className = type;
  }

  function escapeHtml(value){
    return String(value ?? '').replace(/[&<>"']/g, function(m){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m];
    });
  }

  async function loadAvailableSeats(busId, preferredSeatId=null){
    const myRequest = ++requestNo;
    seatSelect.disabled = true;
    setStatus('Loading available seats…','loading');

    try{
      const url = 'api/seats.php?bus_id=' + encodeURIComponent(busId) +
        '&exclude_passenger_id=' + encodeURIComponent(currentPassengerId);

      const response = await fetch(url, {
        credentials:'same-origin',
        headers:{
          'Accept':'application/json',
          'X-Requested-With':'XMLHttpRequest'
        }
      });

      const data = await response.json();
      if(myRequest !== requestNo) return;

      if(!response.ok || !Array.isArray(data)){
        throw new Error(data?.message || 'Could not load seats.');
      }

      const selectedBefore = preferredSeatId !== null
        ? String(preferredSeatId)
        : String(seatSelect.value || '');

      const available = data.filter(function(s){
        return !s.passenger_id || Number(s.passenger_id) === currentPassengerId;
      });

      const allSeats = data.length;
      seatSelect.innerHTML = '';

      available.forEach(function(s){
        const option = document.createElement('option');
        option.value = String(s.id);
        option.textContent = s.seat_no + (s.passenger_id && Number(s.passenger_id) !== currentPassengerId ? ' — BOOKED' : '');
        if(s.passenger_id && Number(s.passenger_id) !== currentPassengerId) option.disabled = true;
        seatSelect.appendChild(option);
      });

      // Normally only available seats are shown. Keep the current passenger's
      // existing seat selected when editing, even if it is technically occupied by them.
      const wanted = available.find(s => String(s.id) === selectedBefore);
      if(wanted){
        seatSelect.value = selectedBefore;
      }else if(seatSelect.options.length){
        seatSelect.selectedIndex = 0;
      }

      seatSelect.disabled = false;
      const availableCount = available.length;
      setStatus(
        availableCount + ' available seat' + (availableCount === 1 ? '' : 's') +
        ' · ' + allSeats + ' total',
        'success'
      );
      if(hint) hint.textContent = 'Changing the bus updates only the seat list — passenger information stays unchanged.';
    }catch(error){
      if(myRequest !== requestNo) return;
      console.error('Passenger seat load error:', error);
      seatSelect.innerHTML = '<option value="">Unable to load seats</option>';
      seatSelect.disabled = true;
      setStatus(error.message || 'Unable to load seats.','error');
    }
  }

  busSelect.addEventListener('change', function(){
    // IMPORTANT: do not navigate/reload. Only refresh the seat dropdown.
    loadAvailableSeats(this.value, null);
  });

  // On edit, keep the current passenger's seat. On new booking, load the
  // selected bus dynamically so the list is always current.
  loadAvailableSeats(busSelect.value, initialSeatId || null);
})();
</script>
</section></body></html>
