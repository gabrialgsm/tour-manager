<?php
require __DIR__.'/bootstrap.php';
require_login();
$tid=require_tour();
$qTour=db()->prepare("SELECT name,start_date,end_date FROM tours WHERE id=? LIMIT 1");
$qTour->execute([$tid]);$tour=$qTour->fetch()?:['name'=>'GMJS Tour','start_date'=>'','end_date'=>''];
$leg=(($_GET['leg']??$_POST['leg']??'outbound')==='return')?'return':'outbound';
$checkAction=$leg==='return'?'CHECK_IN_RETURN':'CHECK_IN_OUTBOUND';
$undoAction=$leg==='return'?'CHECK_IN_RETURN_UNDO':'CHECK_IN_OUTBOUND_UNDO';

function gmjs_leg_checked(int $id,string $checkAction,string $undoAction):bool{
  $q=db()->prepare("SELECT action FROM audit_log WHERE entity_type='passenger' AND entity_id=? AND action IN (?,?) ORDER BY id DESC LIMIT 1");
  $q->execute([$id,$checkAction,$undoAction]);
  return $q->fetchColumn()===$checkAction;
}

$id=(int)($_GET['id']??$_GET['passenger_id']??$_POST['id']??$_POST['passenger_id']??0);
$seatId=(int)($_GET['seat_id']??$_POST['seat_id']??0);

if($id<=0 && $seatId>0){
  $q=db()->prepare("SELECT p.id FROM passengers p WHERE p.seat_id=? AND p.tour_id=? AND p.status='ACTIVE' ORDER BY p.id DESC LIMIT 1");
  $q->execute([$seatId,$tid]);$id=(int)($q->fetchColumn()?:0);
}

if($id<=0){
  $search=trim((string)($_GET['q']??''));
  $sql="SELECT p.id,p.name,p.phone,b.name bus_name,s.seat_no
    FROM passengers p
    LEFT JOIN buses b ON b.id=p.bus_id
    LEFT JOIN seats s ON s.id=p.seat_id
    WHERE p.tour_id=? AND p.status='ACTIVE'";
  $args=[$tid];
  if($search!==''){ $sql.=" AND (p.name LIKE ? OR p.phone LIKE ? OR s.seat_no LIKE ?)";$like='%'.$search.'%';$args[]=$like;$args[]=$like;$args[]=$like; }
  $sql.=" ORDER BY s.row_no,s.col_no,p.name";
  $q=db()->prepare($sql);$q->execute($args);$passengers=$q->fetchAll();
  foreach($passengers as &$pp){$pp['checked_in']=gmjs_leg_checked((int)$pp['id'],$checkAction,$undoAction)?1:0;}unset($pp);
  ?>
  <!doctype html><html><head><?php include __DIR__.'/partials/head.php';?><style>
.gmjs-top{background:linear-gradient(110deg,#087f3f,#35c957);color:#fff;padding:18px 0;box-shadow:0 5px 18px #0a6b3822}
.gmjs-top-inner{max-width:1180px;margin:auto;padding:0 18px;display:flex;align-items:center;justify-content:space-between;gap:18px}
.gmjs-brand small{display:block;font-weight:800;letter-spacing:1.4px;font-size:11px;opacity:.9}
.gmjs-brand strong{display:block;font-size:23px;line-height:1.1;margin-top:3px}
.gmjs-brand span{display:block;font-size:14px;font-weight:700;margin-top:4px}
.gmjs-nav{background:#fff;max-width:1180px;margin:0 auto;display:flex;align-items:center;gap:4px;padding:0 12px;box-shadow:0 3px 12px #0000000b;overflow-x:auto;white-space:nowrap}
.gmjs-nav a{display:block;padding:13px 12px;color:#173b2a;text-decoration:none;font-weight:800;font-size:13px;border-bottom:3px solid transparent}
.gmjs-nav a:hover,.gmjs-nav a.active{color:#07853f;border-bottom-color:#18b94b}
.gmjs-top-actions{display:flex;gap:8px;align-items:center}
.gmjs-top-actions a{color:#fff;text-decoration:none;border:1px solid #ffffff55;border-radius:10px;padding:9px 13px;font-weight:800}
@media(max-width:760px){.gmjs-top-inner{align-items:flex-start;flex-direction:column}.gmjs-top-actions{display:none}.gmjs-nav a{font-size:12px;padding:11px 9px}}
</style><style>
  .checkin-wrap{max-width:1050px;margin:28px auto}.leg-tabs{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:16px 0}.leg-tabs a{padding:13px;border-radius:12px;text-align:center;font-weight:900;text-decoration:none;background:#f1f5f9;color:#334155}.leg-tabs a.active{background:#16a34a;color:#fff}.check-search{display:flex;gap:8px;margin:14px 0}.check-search input{flex:1}.ci-ok{color:#1d4ed8;font-weight:900}.ci-no{color:#92400e;font-weight:900}@media(max-width:700px){.check-search{flex-direction:column}}
  </style></head><body><header class="gmjs-top">
<div class="gmjs-top-inner">
<div class="gmjs-brand"><small>GMJS TOUR MANAGER</small><strong><?=h($tour['name'])?></strong><span><?=h($tour['start_date'])?> → <?=h($tour['end_date'])?></span></div>
<div class="gmjs-top-actions"><a href="dashboard.php">GMJS</a><a href="tours.php">Tours</a><a href="logout.php">Logout</a></div>
</div>
</header>
<nav class="gmjs-nav">
<a href="dashboard.php">Dashboard</a>
<a href="passengers.php">Passengers</a>
<a href="payments.php">Payments</a>
<a href="rooms.php">Rooms</a>
<a href="expenses.php">Expenses</a>
<a href="cost_calculator.php">Cost Calculator</a>
<a class="active" href="checkin.php?leg=<?=h($leg)?>">Check-in</a>
<a href="seat_plan.php">Public Seat Plan</a>
<a href="bus_manage.php">Buses</a>
<a href="reports.php">Reports</a>
<a href="settings.php">Settings</a>
</nav><main class="wrap checkin-wrap">
  <section class="card"><div class="eyebrow">TOUR DAY CHECK-IN</div><h1>Passenger Check-in</h1><p class="muted">Select a passenger below, or use QR scan / Seat Plan for faster check-in.</p>
  <div class="leg-tabs"><a class="<?=$leg==='outbound'?'active':''?>" href="checkin.php?leg=outbound">🚌 Going / Outbound</a><a class="<?=$leg==='return'?'active':''?>" href="checkin.php?leg=return">🔄 Return Journey</a></div>
  <form class="check-search" method="get"><input type="hidden" name="leg" value="<?=h($leg)?>"><input name="q" value="<?=h($search)?>" placeholder="Search passenger name, phone or seat"><button class="btn primary" type="submit">Search</button><?php if($search!==''):?><a class="btn secondary" href="checkin.php?leg=<?=h($leg)?>">Clear</a><?php endif;?></form>
  <div class="table-wrap"><table><thead><tr><th>Passenger</th><th>Phone</th><th>Bus</th><th>Seat</th><th>Status</th><th>Action</th></tr></thead><tbody>
  <?php foreach($passengers as $pp):?><tr><td><b><?=h($pp['name'])?></b></td><td><?=h($pp['phone']??'')?></td><td><?=h($pp['bus_name']??'')?></td><td><?=h($pp['seat_no']??'')?></td><td class="ci-status-cell <?=$pp['checked_in']?'ci-ok':'ci-no'?>"><?=$pp['checked_in']?'✓ CHECKED IN':'Not checked in'?></td><td><button type="button" class="btn <?=$pp['checked_in']?'secondary':'primary'?> checkin-open" data-pid="<?=$pp['id']?>" data-name="<?=h($pp['name'])?>" data-seat="<?=h($pp['seat_no']??'')?>" data-checked="<?=$pp['checked_in']?'1':'0'?>"><?=$pp['checked_in']?'Open / Undo':'Check In'?></button></td></tr><?php endforeach;?>
  <?php if(!$passengers):?><tr><td colspan="6">No active passengers found.</td></tr><?php endif;?></tbody></table></div>
  </section>
</main>
<div id="ciModal" class="ci-modal" aria-hidden="true">
 <div class="ci-dialog">
  <div class="eyebrow">ADMIN CHECK-IN</div>
  <h2 id="ciTitle">Passenger</h2>
  <p id="ciMeta" class="muted"></p>
  <div id="ciStatus" class="ci-status"></div>
  <div id="ciBusy" class="muted" style="display:none;text-align:center;padding:10px">Processing…</div>
  <div class="ci-actions"><button id="ciDo" type="button" class="btn primary">✓ Check In</button><button type="button" class="btn secondary" onclick="closeCiModal()">Close</button></div>
 </div>
</div>
<script>
let ciPid=0,ciChecked=false,ciBtn=null;
const CI_LEG=<?=json_encode($leg)?>;
function openCiModal(btn){
 ciBtn=btn;ciPid=Number(btn.dataset.pid||0);ciChecked=btn.dataset.checked==='1';
 document.getElementById('ciTitle').textContent=btn.dataset.name||'Passenger';
 document.getElementById('ciMeta').textContent='Seat '+(btn.dataset.seat||'')+' · '+(CI_LEG==='return'?'Return Journey':'Outbound Journey');
 renderCi();
 document.getElementById('ciModal').style.display='flex';
 document.getElementById('ciModal').setAttribute('aria-hidden','false');
}
function renderCi(){
 document.getElementById('ciStatus').innerHTML=ciChecked?'<div class="alert success">✓ Already checked in</div>':'<div class="alert">Not checked in</div>';
 const b=document.getElementById('ciDo');b.textContent=ciChecked?'↩ Undo Check-In':'✓ Check In';b.className='btn '+(ciChecked?'danger':'primary');b.disabled=false;
}
function closeCiModal(){document.getElementById('ciModal').style.display='none';document.getElementById('ciModal').setAttribute('aria-hidden','true');}
document.querySelectorAll('.checkin-open').forEach(b=>b.addEventListener('click',()=>openCiModal(b)));
document.getElementById('ciModal')?.addEventListener('click',e=>{if(e.target.id==='ciModal')closeCiModal();});
document.getElementById('ciDo')?.addEventListener('click',async()=>{
 if(!ciPid)return;
 const b=document.getElementById('ciDo');b.disabled=true;document.getElementById('ciBusy').style.display='block';
 const fd=new FormData();fd.append('csrf',<?=json_encode(csrf())?>);fd.append('passenger_id',String(ciPid));fd.append('leg',CI_LEG);fd.append('action',ciChecked?'undo':'checkin');
 try{
  const r=await fetch('checkin_ajax.php',{method:'POST',body:fd,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}});
  const d=await r.json();if(!r.ok||!d.ok)throw new Error(d.message||'Check-in failed.');
  ciChecked=!!d.checked_in;ciBtn.dataset.checked=ciChecked?'1':'0';ciBtn.textContent=ciChecked?'Open / Undo':'Check In';renderCi();
  const st=ciBtn.closest('tr')?.querySelector('.ci-status-cell');if(st){st.textContent=ciChecked?'✓ CHECKED IN':'Not checked in';st.className='ci-status-cell '+(ciChecked?'ci-ok':'ci-no');}
  const toast=document.getElementById('toast');if(toast){toast.textContent=d.message;toast.classList.add('show');setTimeout(()=>toast.classList.remove('show'),2200);}
 }catch(e){document.getElementById('ciStatus').innerHTML='<div class="alert danger">'+String(e.message).replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]))+'</div>';b.disabled=false;}
 finally{document.getElementById('ciBusy').style.display='none';}
});
</script>
<style>
.ci-modal{display:none;position:fixed;inset:0;background:#0008;z-index:99999;align-items:center;justify-content:center;padding:18px}
.ci-dialog{background:#fff;border-radius:18px;width:min(420px,100%);padding:22px;box-shadow:0 25px 80px #0004}
.ci-actions{display:flex;gap:8px;margin-top:16px}.ci-actions .btn{flex:1}
</style>
</body></html>
  <?php exit;
}

$q=db()->prepare("SELECT p.id,p.name,p.tour_id,b.name bus_name,s.seat_no FROM passengers p LEFT JOIN buses b ON b.id=p.bus_id LEFT JOIN seats s ON s.id=p.seat_id WHERE p.id=? AND p.tour_id=? LIMIT 1");
$q->execute([$id,$tid]);$p=$q->fetch();
if(!$p){flash('danger','Passenger not found for this tour.');redirect('checkin.php?leg='.$leg);}

$checked=gmjs_leg_checked($id,$checkAction,$undoAction);
if($_SERVER['REQUEST_METHOD']==='POST'){
  check_csrf();
  $action=$_POST['action']??'';
  if($action==='checkin' && !$checked){audit($checkAction,'passenger',$id,ucfirst($leg).' journey check-in');flash('success',$p['name'].' checked in for the '.($leg==='return'?'return':'outbound').' journey.');}
  elseif($action==='undo' && $checked){audit($undoAction,'passenger',$id,ucfirst($leg).' journey check-in undone');flash('success',$p['name'].' check-in undone for the '.($leg==='return'?'return':'outbound').' journey.');}
  redirect('checkin.php?id='.$id.'&leg='.$leg);
}
$checked=gmjs_leg_checked($id,$checkAction,$undoAction);
?>
<!doctype html><html><head><?php include __DIR__.'/partials/head.php';?><style>
.gmjs-top{background:linear-gradient(110deg,#087f3f,#35c957);color:#fff;padding:18px 0;box-shadow:0 5px 18px #0a6b3822}
.gmjs-top-inner{max-width:1180px;margin:auto;padding:0 18px;display:flex;align-items:center;justify-content:space-between;gap:18px}
.gmjs-brand small{display:block;font-weight:800;letter-spacing:1.4px;font-size:11px;opacity:.9}
.gmjs-brand strong{display:block;font-size:23px;line-height:1.1;margin-top:3px}
.gmjs-brand span{display:block;font-size:14px;font-weight:700;margin-top:4px}
.gmjs-nav{background:#fff;max-width:1180px;margin:0 auto;display:flex;align-items:center;gap:4px;padding:0 12px;box-shadow:0 3px 12px #0000000b;overflow-x:auto;white-space:nowrap}
.gmjs-nav a{display:block;padding:13px 12px;color:#173b2a;text-decoration:none;font-weight:800;font-size:13px;border-bottom:3px solid transparent}
.gmjs-nav a:hover,.gmjs-nav a.active{color:#07853f;border-bottom-color:#18b94b}
.gmjs-top-actions{display:flex;gap:8px;align-items:center}
.gmjs-top-actions a{color:#fff;text-decoration:none;border:1px solid #ffffff55;border-radius:10px;padding:9px 13px;font-weight:800}
@media(max-width:760px){.gmjs-top-inner{align-items:flex-start;flex-direction:column}.gmjs-top-actions{display:none}.gmjs-nav a{font-size:12px;padding:11px 9px}}
</style><style>.leg-tabs{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:16px 0}.leg-tabs a{padding:11px;border-radius:12px;text-align:center;font-weight:900;text-decoration:none;background:#f1f5f9;color:#334155}.leg-tabs a.active{background:#16a34a;color:#fff}.status{padding:14px;border-radius:14px;font-weight:900}.status.ok{background:#dbeafe;color:#1d4ed8}.status.no{background:#fef3c7;color:#92400e}</style></head><body><header class="gmjs-top">
<div class="gmjs-top-inner">
<div class="gmjs-brand"><small>GMJS TOUR MANAGER</small><strong><?=h($tour['name'])?></strong><span><?=h($tour['start_date'])?> → <?=h($tour['end_date'])?></span></div>
<div class="gmjs-top-actions"><a href="dashboard.php">GMJS</a><a href="tours.php">Tours</a><a href="logout.php">Logout</a></div>
</div>
</header>
<nav class="gmjs-nav">
<a href="dashboard.php">Dashboard</a>
<a href="passengers.php">Passengers</a>
<a href="payments.php">Payments</a>
<a href="rooms.php">Rooms</a>
<a href="expenses.php">Expenses</a>
<a href="cost_calculator.php">Cost Calculator</a>
<a class="active" href="checkin.php?leg=<?=h($leg)?>">Check-in</a>
<a href="seat_plan.php">Public Seat Plan</a>
<a href="bus_manage.php">Buses</a>
<a href="reports.php">Reports</a>
<a href="settings.php">Settings</a>
</nav><main class="wrap"><section class="card" style="max-width:580px;margin:30px auto">
<div class="eyebrow">PASSENGER CHECK-IN</div><h1><?=h($p['name'])?></h1><p class="muted"><?=h($p['bus_name']??'')?> · Seat <?=h($p['seat_no']??'')?></p>
<div class="leg-tabs"><a class="<?=$leg==='outbound'?'active':''?>" href="checkin.php?id=<?=$id?>&leg=outbound">🚌 Going / Outbound</a><a class="<?=$leg==='return'?'active':''?>" href="checkin.php?id=<?=$id?>&leg=return">🔄 Return Journey</a></div>
<?php flash_render();?><div class="status <?=$checked?'ok':'no'?>"><?=$checked?'✓ CHECKED IN':'NOT CHECKED IN'?> — <?=h($leg==='return'?'Return Journey':'Outbound Journey')?></div>
<form method="post" style="margin-top:16px"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="leg" value="<?=h($leg)?>">
<?php if($checked):?><input type="hidden" name="action" value="undo"><button class="btn danger wide" type="submit" onclick="return confirm('Undo this check-in?')">↩ Undo Check-In</button>
<?php else:?><input type="hidden" name="action" value="checkin"><button class="btn primary wide" type="submit">✓ Check In for <?=$leg==='return'?'Return':'Outbound'?></button><?php endif;?></form>
<a class="btn secondary wide" href="checkin.php?leg=<?=$leg?>" style="margin-top:10px">← Back to Check-in List</a>
</section></main></body></html>
