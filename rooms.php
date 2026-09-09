<?php require __DIR__.'/bootstrap.php';require __DIR__.'/room_inventory.php';require_login();$tid=require_tour();$roomInventory=gmjs_room_inventory($tid);
$editId=(int)($_GET['edit']??0);$editRoom=null;
if($editId){$q=db()->prepare("SELECT * FROM rooms WHERE id=? AND tour_id=?");$q->execute([$editId,$tid]);$editRoom=$q->fetch();if(!$editRoom)redirect('rooms.php');}
if($_SERVER['REQUEST_METHOD']==='POST'){check_csrf();try{$a=$_POST['action']??'';
 if($a==='add'){
  $roomNo=trim($_POST['room_no']);$cap=max(1,(int)$_POST['capacity']);
  if(!$roomNo)throw new Exception('Room number is required.');
  db()->prepare("INSERT INTO rooms(tour_id,room_no,room_type,capacity,notes) VALUES(?,?,?,?,?)")->execute([$tid,$roomNo,$_POST['room_type'],$cap,trim($_POST['notes'])]);audit('ADD_ROOM','room',(int)db()->lastInsertId(),$roomNo);flash('success','Room added.');
 }elseif($a==='update'){
  $rid=(int)$_POST['id'];$cap=max(1,(int)$_POST['capacity']);
  $q=db()->prepare("SELECT COUNT(*) FROM room_assignments ra JOIN passengers p ON p.id=ra.passenger_id WHERE ra.room_id=? AND p.status='ACTIVE'");$q->execute([$rid]);$used=(int)$q->fetchColumn();
  if($cap<$used)throw new Exception("Capacity cannot be less than current guests ($used).");
  db()->prepare("UPDATE rooms SET room_no=?,room_type=?,capacity=?,notes=? WHERE id=? AND tour_id=?")->execute([trim($_POST['room_no']),$_POST['room_type'],$cap,trim($_POST['notes']),$rid,$tid]);audit('UPDATE_ROOM','room',$rid);flash('success','Room updated.');
 }elseif($a==='delete'){
  $rid=(int)$_POST['id'];$q=db()->prepare("SELECT COUNT(*) FROM room_assignments WHERE room_id=?");$q->execute([$rid]);if((int)$q->fetchColumn()>0)throw new Exception('Cannot delete a room with guests. Remove guests first.');
  db()->prepare("DELETE FROM rooms WHERE id=? AND tour_id=?")->execute([$rid,$tid]);audit('DELETE_ROOM','room',$rid);flash('success','Room deleted.');
 }elseif($a==='assign'){
  $rid=(int)$_POST['room_id'];$pid=(int)$_POST['passenger_id'];
  $q=db()->prepare("SELECT capacity,(SELECT COUNT(*) FROM room_assignments WHERE room_id=rooms.id) used FROM rooms WHERE id=? AND tour_id=?");$q->execute([$rid,$tid]);$r=$q->fetch();if(!$r||$r['used']>=$r['capacity'])throw new Exception('Room is full.');
  $q=db()->prepare("SELECT p.id FROM passengers p WHERE p.id=? AND p.tour_id=? AND p.status='ACTIVE' AND NOT EXISTS(SELECT 1 FROM room_assignments ra WHERE ra.passenger_id=p.id)");$q->execute([$pid,$tid]);if(!$q->fetch())throw new Exception('Passenger is already assigned or invalid.');
  db()->prepare("INSERT INTO room_assignments(room_id,passenger_id) VALUES(?,?)")->execute([$rid,$pid]);audit('ASSIGN_ROOM','passenger',$pid,"room:$rid");flash('success','Passenger assigned.');
 }elseif($a==='move_room'){
  $assignmentId=(int)($_POST['assignment_id']??0);$targetRoomId=(int)($_POST['room_id']??0);
  $q=db()->prepare("SELECT ra.id,ra.room_id,ra.passenger_id FROM room_assignments ra JOIN rooms r ON r.id=ra.room_id JOIN passengers p ON p.id=ra.passenger_id WHERE ra.id=? AND r.tour_id=? AND p.tour_id=? AND p.status='ACTIVE'");
  $q->execute([$assignmentId,$tid,$tid]);$move=$q->fetch();
  if(!$move)throw new Exception('Guest assignment not found.');
  $q=db()->prepare("SELECT id,room_no,capacity,(SELECT COUNT(*) FROM room_assignments x JOIN passengers px ON px.id=x.passenger_id WHERE x.room_id=rooms.id AND px.status='ACTIVE') used FROM rooms WHERE id=? AND tour_id=?");
  $q->execute([$targetRoomId,$tid]);$target=$q->fetch();
  if(!$target)throw new Exception('Target room not found.');
  if((int)$target['id']!==(int)$move['room_id'] && (int)$target['used'] >= (int)$target['capacity'])throw new Exception('Target room is full.');
  if((int)$target['id']!==(int)$move['room_id']){
    db()->prepare("UPDATE room_assignments SET room_id=? WHERE id=?")->execute([$targetRoomId,$assignmentId]);
    audit('MOVE_ROOM_ASSIGNMENT','passenger',(int)$move['passenger_id'],"room:{$move['room_id']}->{$targetRoomId}");
  }
  if(isset($_SERVER['HTTP_X_REQUESTED_WITH'])){
    header('Content-Type: application/json');
    echo json_encode(['ok'=>true,'message'=>'Guest moved successfully.','assignment_id'=>$assignmentId,'target_room_id'=>$targetRoomId,'target_room_no'=>$target['room_no'],'source_room_id'=>(int)$move['room_id']]);
    exit;
  }
  flash('success','Guest moved successfully.');
 }elseif($a==='remove'){$id=(int)$_POST['id'];db()->prepare("DELETE ra FROM room_assignments ra JOIN rooms r ON r.id=ra.room_id WHERE ra.id=? AND r.tour_id=?")->execute([$id,$tid]);audit('REMOVE_ROOM_ASSIGNMENT','room',$id);flash('success','Guest removed from room.');}
}catch(Throwable $e){flash('danger',$e->getMessage());}redirect('rooms.php');}
$q=db()->prepare("SELECT r.*,(SELECT COUNT(*) FROM room_assignments ra JOIN passengers p ON p.id=ra.passenger_id WHERE ra.room_id=r.id AND p.status='ACTIVE') used FROM rooms r WHERE r.tour_id=? ORDER BY r.room_no");$q->execute([$tid]);$rooms=$q->fetchAll();
$q=db()->prepare("SELECT p.id,p.name,p.phone,p.room_type,b.name bus_name,s.seat_no,ra.id assignment_id,ra.room_id FROM passengers p JOIN seats s ON s.id=p.seat_id JOIN buses b ON b.id=p.bus_id LEFT JOIN room_assignments ra ON ra.passenger_id=p.id WHERE p.tour_id=? AND p.status='ACTIVE' ORDER BY p.name");$q->execute([$tid]);$allGuests=$q->fetchAll();$free=array_values(array_filter($allGuests,fn($p)=>empty($p['assignment_id'])));
$guestMap=[];foreach($allGuests as $g){if($g['room_id'])$guestMap[(int)$g['room_id']][]=$g;}
?><!doctype html><html><head><?php include __DIR__.'/partials/head.php';?></head><body><?php include __DIR__.'/partials/nav.php';?><main class="wrap"><?php flash_render();?>
<section class="card"><div class="head"><div><div class="eyebrow">ROOM INVENTORY</div><h2>Room Type Availability</h2></div></div><div class="grid2"><?php foreach($roomInventory as $ri):?><div class="stat"><span><?=h($ri['label'])?></span><b><?=$ri['available_rooms']?> / <?=$ri['rooms']?> rooms available</b><small class="muted"><?=$ri['available_beds']?> / <?=$ri['beds']?> booking slots available · <?=$ri['used_beds']?> booked</small><?php if($ri['full']):?><div class="badge due">FULL</div><?php endif;?></div><?php endforeach;?></div></section><div class="grid2"><section class="card"><h2><?=$editRoom?'Edit Room':'Add Room'?></h2><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="<?=$editRoom?'update':'add'?>"><?php if($editRoom):?><input type="hidden" name="id" value="<?=$editRoom['id']?>"><?php endif;?><div class="formgrid"><label>Room No<input name="room_no" required value="<?=h($editRoom['room_no']??'')?>"></label><label>Type<select name="room_type"><?php foreach(gmjs_room_types() as $k=>$v):?><option value="<?=$k?>" <?=($editRoom['room_type']??'AC_4_BED')===$k?'selected':''?>><?=$v?></option><?php endforeach;?></select></label><label>Capacity<input name="capacity" type="number" min="1" max="6" value="<?=h($editRoom['capacity']??4)?>"></label><label>Notes<input name="notes" value="<?=h($editRoom['notes']??'')?>"></label></div><div class="row-actions"><button class="btn primary"><?=$editRoom?'Update Room':'Add Room'?></button><?php if($editRoom):?><a class="btn secondary" href="rooms.php">Cancel</a><?php endif;?></div></form></section>
<section class="card room-assign-card" id="assign-box">
<h2>Assign Passenger</h2>
<form method="post" id="assignForm">
<input type="hidden" name="csrf" value="<?=h(csrf())?>">
<input type="hidden" name="action" value="assign">
<input type="hidden" name="passenger_id" id="selectedPassenger" value="">
<div class="assign-search-wrap">
<label>Passenger</label>
<div class="passenger-search">
<span class="search-icon">⌕</span>
<input type="text" id="passengerSearch" autocomplete="off" placeholder="Search name, phone or seat...">
<button type="button" class="clear-search" id="clearPassenger" aria-label="Clear">×</button>
</div>
<div class="search-hint" id="searchHint"><?=count($free)?> unassigned passenger<?=count($free)==1?'':'s'?> available</div>
<div class="passenger-results" id="passengerResults">
<?php foreach($free as $p):?>
<button type="button" class="passenger-result" data-id="<?=$p['id']?>" data-search="<?=h(strtolower($p['name'].' '.$p['phone'].' '.$p['seat_no'].' '.$p['bus_name']))?>">
<span class="result-seat"><?=h($p['seat_no'])?></span>
<span class="result-main"><b><?=h($p['name'])?></b><small><?=h($p['phone'])?> · <?=h($p['bus_name'])?></small></span>
</button>
<?php endforeach;?>
<div class="no-results" id="noPassengerResults">No matching unassigned passenger.</div>
</div>
<div class="selected-passenger" id="selectedPassengerCard" hidden></div>
</div>

<label>Room
<select name="room_id" id="assignRoom" required>
<option value="">Select room</option>
<?php foreach($rooms as $r):?>
<option value="<?=$r['id']?>" <?=$r['used']>=$r['capacity']?'disabled':''?>>
<?=h($r['room_no'])?> · <?=h(room_label($r['room_type']))?> · <?=$r['used']?>/<?=$r['capacity']?><?=$r['used']>=$r['capacity']?' · FULL':''?>
</option>
<?php endforeach;?>
</select>
</label>
<button class="btn primary assign-submit" id="assignSubmit" disabled>Assign Passenger</button>
</form>
</section></div>
<section class="card">
<div class="head">
  <div><div class="eyebrow">ROOM MANAGEMENT</div><h2>Rooms & Guest Lists</h2></div>
  <div class="row-actions"><span class="muted"><?=count($rooms)?> rooms</span><a class="btn primary" href="room_print.php">Print All Rooms</a></div>
</div>

<?php
$roomsByType=[];
foreach($rooms as $rr){ $roomsByType[$rr['room_type']][]=$rr; }
$typeOrder=gmjs_room_types();
foreach($typeOrder as $typeKey=>$typeLabel):
  if(empty($roomsByType[$typeKey])) continue;
  $typeRooms=$roomsByType[$typeKey];
  $typeUsed=0; $typeCapacity=0;
  foreach($typeRooms as $tr){ $typeUsed+=(int)$tr['used']; $typeCapacity+=(int)$tr['capacity']; }
?>
<div class="room-category">
  <div class="room-category-head">
    <div>
      <div class="room-category-title"><?=h($typeLabel)?></div>
      <div class="room-category-meta"><?=count($typeRooms)?> room<?=count($typeRooms)==1?'':'s'?> · <?=$typeUsed?>/<?=$typeCapacity?> guests</div>
    </div>
    <span class="category-badge"><?=$typeUsed?> / <?=$typeCapacity?></span>
  </div>

  <div class="grid2 rooms-grid">
  <?php foreach($typeRooms as $r):?>
    <div class="room-card room-drop-zone" data-room-id="<?=$r['id']?>" data-room-no="<?=h($r['room_no'])?>">
      <div class="head">
        <div><b><?=h($r['room_no'])?></b><div class="muted"><?=h($r['notes']??'')?></div></div>
        <span class="badge <?=$r['used']>=$r['capacity']?'due':''?>"><?=$r['used']?>/<?=$r['capacity']?></span>
      </div>
      <div class="guest-list">
      <?php if(empty($guestMap[$r['id']])):?>
        <div class="muted">No guests assigned.</div>
      <?php else: foreach($guestMap[$r['id']] as $g):?>
        <div class="guest-row draggable-guest" draggable="true" data-assignment-id="<?=$g['assignment_id']?>" data-source-room="<?=$r['id']?>">
          <div><b><?=h($g['name'])?></b><div class="muted"><?=h($g['seat_no'])?> · <?=h($g['phone'])?></div></div>
          <form method="post">
            <input type="hidden" name="csrf" value="<?=h(csrf())?>">
            <input type="hidden" name="action" value="remove">
            <input type="hidden" name="id" value="<?=$g['assignment_id']?>">
            <button class="btn secondary" title="Remove guest">Remove</button>
          </form>
        </div>
      <?php endforeach; endif;?>
      </div>
      <div class="row-actions room-actions">
        <?php if((int)$r['used'] < (int)$r['capacity']):?>
        <a class="btn primary add-guest-btn" href="#assign-box" data-room-id="<?=$r['id']?>" data-room-label="<?=h($r['room_no'].' · '.room_label($r['room_type']))?>">+ Add Guest</a>
        <?php endif;?>
        <a class="btn secondary" href="rooms.php?edit=<?=$r['id']?>">Edit</a>
        <a class="btn secondary" href="room_print.php?room_id=<?=$r['id']?>">Print Room</a>
        <?php if((int)$r['used']===0):?>
        <form method="post" onsubmit="return confirm('Delete this empty room?')">
          <input type="hidden" name="csrf" value="<?=h(csrf())?>">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?=$r['id']?>">
          <button class="btn danger">Delete</button>
        </form>
        <?php else:?>
        <span class="muted">Remove guests before delete</span>
        <?php endif;?>
      </div>
    </div>
  <?php endforeach;?>
  </div>
</div>
<?php endforeach; ?>
</section>
</main>

<style>
.room-category{margin-top:24px;border:1px solid #dcebe4;border-radius:16px;background:#fbfefc;padding:14px}
.room-category:first-of-type{margin-top:18px}
.room-category-head{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:4px 4px 13px;border-bottom:1px solid #e1eee8}
.room-category-title{font-size:18px;font-weight:800;color:#12382b}
.room-category-meta{font-size:12px;color:#789087;margin-top:3px}
.category-badge{font-size:12px;font-weight:800;color:#087d40;background:#e9f8ef;border:1px solid #c9ecd6;border-radius:999px;padding:7px 11px;white-space:nowrap}
.room-category .rooms-grid{margin-top:14px}
@media(max-width:700px){
 .room-category{padding:11px;border-radius:13px}
 .room-category-head{align-items:flex-start}
 .room-category-title{font-size:16px}
 .category-badge{font-size:11px}
}
</style>
<style>
.room-assign-card .assign-search-wrap{position:relative}
.passenger-search{position:relative;display:flex;align-items:center}
.passenger-search input{width:100%;padding:12px 42px 12px 38px;border:1px solid #d9e5df;border-radius:12px;font-size:15px;background:#fff;box-sizing:border-box}
.passenger-search input:focus{outline:0;border-color:#20b85a;box-shadow:0 0 0 3px rgba(32,184,90,.12)}
.search-icon{position:absolute;left:13px;z-index:2;font-size:20px;color:#71857d;line-height:1}
.clear-search{position:absolute;right:7px;border:0;background:transparent;color:#71857d;font-size:22px;cursor:pointer;display:none}
.search-hint{font-size:12px;color:#789087;margin:6px 2px 8px}
.passenger-results{position:relative;max-height:245px;overflow:auto;border:1px solid #dfeae5;border-radius:12px;background:#fff;box-shadow:0 8px 25px rgba(18,59,45,.08);margin-bottom:10px}
.passenger-result{display:flex;width:100%;gap:12px;align-items:center;text-align:left;border:0;border-bottom:1px solid #edf3f0;background:#fff;padding:11px 13px;cursor:pointer}
.passenger-result:last-of-type{border-bottom:0}
.passenger-result:hover,.passenger-result:focus{background:#effbf3}
.result-seat{flex:0 0 42px;font-weight:800;color:#0c8d48;background:#e8f8ee;border-radius:8px;padding:6px 4px;text-align:center}
.result-main{min-width:0;display:flex;flex-direction:column}
.result-main b{font-size:14px;color:#12382b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.result-main small{font-size:11px;color:#758b82;margin-top:2px}
.no-results{display:none;padding:14px;color:#7a8d85;text-align:center;font-size:13px}
.selected-passenger{border:1px solid #bce7cb;background:#f2fbf5;border-radius:12px;padding:10px 12px;margin:8px 0 12px}
.selected-passenger .selected-label{font-size:10px;text-transform:uppercase;letter-spacing:.12em;color:#668076;font-weight:800}
.selected-passenger .selected-name{font-weight:800;color:#12382b;margin-top:2px}
.selected-passenger .selected-meta{font-size:12px;color:#71877e;margin-top:2px}
.assign-submit{margin-top:4px}
.room-actions{display:flex;flex-wrap:wrap;align-items:center;gap:7px}
.room-actions form{display:inline-flex}
@media(max-width:700px){
 .room-assign-card .formgrid{grid-template-columns:1fr!important}
 .passenger-results{max-height:220px}
 .room-actions .btn{flex:1 1 auto;text-align:center}
 .room-actions .muted{width:100%}
}
</style>
<script>
(function(){
 const search=document.getElementById('passengerSearch');
 const results=document.getElementById('passengerResults');
 const selected=document.getElementById('selectedPassenger');
 const card=document.getElementById('selectedPassengerCard');
 const submit=document.getElementById('assignSubmit');
 const clear=document.getElementById('clearPassenger');
 const hint=document.getElementById('searchHint');
 const noResults=document.getElementById('noPassengerResults');
 const room=document.getElementById('assignRoom');
 if(!search || !results) return;

 const items=[...results.querySelectorAll('.passenger-result')];

 function filter(){
   const q=search.value.trim().toLowerCase();
   let shown=0;
   items.forEach(el=>{
     const ok=!q || el.dataset.search.includes(q);
     el.style.display=ok?'flex':'none';
     if(ok) shown++;
   });
   noResults.style.display=shown?'none':'block';
   clear.style.display=q?'block':'none';
   hint.textContent=q ? shown+' matching unassigned passenger'+(shown===1?'':'s') : <?=count($free)?>+' unassigned passenger<?=count($free)==1?'':'s'?> available';
 }

 items.forEach(el=>{
   el.addEventListener('click',function(){
     selected.value=this.dataset.id;
     const seat=this.querySelector('.result-seat')?.textContent.trim() || '';
     const main=this.querySelector('.result-main');
     const name=main?.querySelector('b')?.textContent.trim() || '';
     const meta=main?.querySelector('small')?.textContent.trim() || '';
     search.value=name;
     card.hidden=false;
     card.innerHTML='<div class="selected-label">Selected passenger</div><div class="selected-name">'+escapeHtml(name)+'</div><div class="selected-meta">'+escapeHtml(seat+' · '+meta)+'</div>';
     results.style.display='none';
     clear.style.display='block';
     submit.disabled=!selected.value || !room.value;
   });
 });

 search.addEventListener('input',function(){
   selected.value='';
   card.hidden=true;
   submit.disabled=true;
   results.style.display='block';
   filter();
 });
 search.addEventListener('focus',function(){
   results.style.display='block';
   filter();
 });
 clear.addEventListener('click',function(){
   search.value=''; selected.value=''; card.hidden=true; submit.disabled=true;
   results.style.display='block'; filter(); search.focus();
 });
 room.addEventListener('change',function(){submit.disabled=!selected.value || !room.value;});

 document.querySelectorAll('.add-guest-btn').forEach(btn=>{
   btn.addEventListener('click',function(){
     room.value=this.dataset.roomId;
     search.value=''; selected.value=''; card.hidden=true; submit.disabled=true;
     results.style.display='block'; filter();
     setTimeout(()=>search.focus(),50);
   });
 });

 document.addEventListener('click',function(e){
   if(!e.target.closest('.passenger-search') && !e.target.closest('.passenger-results') && !e.target.closest('.selected-passenger')){
     if(selected.value) results.style.display='none';
   }
 });

 function escapeHtml(s){return String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));}
 filter();
})();
</script>

<style>
.draggable-guest{cursor:grab;transition:opacity .15s,transform .15s,background .15s}
.draggable-guest.dragging{opacity:.45;transform:scale(.98)}
.room-drop-zone{transition:box-shadow .15s,border-color .15s,background .15s}
.room-drop-zone.room-drag-over{border-color:#20b85a!important;box-shadow:0 0 0 3px rgba(32,184,90,.12);background:#f3fcf6}
.room-drop-zone.room-saving{opacity:.72;pointer-events:none}
.room-move-toast{position:fixed;right:20px;bottom:20px;z-index:9999;background:#12382b;color:#fff;padding:10px 14px;border-radius:10px;box-shadow:0 8px 25px rgba(0,0,0,.18);font-size:13px}
.room-move-toast.error{background:#991b1b}
</style>
<script>
(function(){
 const csrf='<?=h(csrf())?>';
 const zones=[...document.querySelectorAll('.room-drop-zone')];
 let dragged=null;
 function toast(msg,error){const el=document.createElement('div');el.className='room-move-toast'+(error?' error':'');el.textContent=msg;document.body.appendChild(el);setTimeout(()=>el.remove(),2200)}
 function updateBadge(zone,delta){
   if(!zone)return; const b=zone.querySelector('.badge'); if(!b)return;
   const m=b.textContent.match(/(\d+)\s*\/\s*(\d+)/); if(!m)return;
   const used=Math.max(0,parseInt(m[1],10)+delta),cap=parseInt(m[2],10);
   b.textContent=used+'/'+cap;b.classList.toggle('due',used>=cap);
 }
 function emptyMessage(zone){
   if(!zone)return; const list=zone.querySelector('.guest-list');
   if(list && !list.querySelector('.draggable-guest')){const d=document.createElement('div');d.className='muted room-empty-message';d.textContent='No guests assigned.';list.appendChild(d);}
 }
 function removeEmptyMessage(zone){zone?.querySelector('.room-empty-message')?.remove()}
 document.querySelectorAll('.draggable-guest').forEach(el=>{
   el.addEventListener('dragstart',e=>{dragged=el;el.classList.add('dragging');e.dataTransfer.effectAllowed='move';e.dataTransfer.setData('text/plain',el.dataset.assignmentId)});
   el.addEventListener('dragend',()=>{el.classList.remove('dragging');zones.forEach(z=>z.classList.remove('room-drag-over'));dragged=null});
 });
 zones.forEach(zone=>{
   zone.addEventListener('dragover',e=>{if(!dragged)return;e.preventDefault();e.dataTransfer.dropEffect='move';zone.classList.add('room-drag-over')});
   zone.addEventListener('dragleave',e=>{if(!zone.contains(e.relatedTarget))zone.classList.remove('room-drag-over')});
   zone.addEventListener('drop',async e=>{
     e.preventDefault();zone.classList.remove('room-drag-over');if(!dragged)return;

     // Keep a stable reference before the async AJAX request.
     // dragend fires while fetch() is waiting and clears `dragged`.
     const guestEl=dragged;
     const assignmentId=guestEl.dataset.assignmentId;
     const source=guestEl.dataset.sourceRoom;
     const target=zone.dataset.roomId;

     if(String(target)===String(source))return;

     const sourceZone=document.querySelector('.room-drop-zone[data-room-id="'+CSS.escape(String(source))+'"]');
     zone.classList.add('room-saving');

     const fd=new FormData();
     fd.append('csrf',csrf);
     fd.append('action','move_room');
     fd.append('assignment_id',assignmentId);
     fd.append('room_id',target);

     try{
       const res=await fetch('rooms.php',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}});
       const data=await res.json();
       if(!data.ok)throw new Error(data.message||'Could not move guest.');

       const list=zone.querySelector('.guest-list');
       if(!list)throw new Error('Target room guest list not found.');

       removeEmptyMessage(zone);
       list.appendChild(guestEl);
       guestEl.dataset.sourceRoom=target;

       updateBadge(sourceZone,-1);
       updateBadge(zone,1);
       emptyMessage(sourceZone);

       toast('Guest moved to Room '+data.target_room_no);
     }catch(err){
       toast(err.message||'Could not move guest.',true);
     }finally{
       zone.classList.remove('room-saving');
     }
   });
 });
})();
</script>
</body></html>
