<?php
require __DIR__.'/bootstrap.php'; require_login(); $tid=require_tour(); $t=tour_row($tid);
function leg_state(PDO $db,int $tid,string $leg):array{
 $check=$leg==='return'?'CHECK_IN_RETURN':'CHECK_IN_OUTBOUND'; $undo=$leg==='return'?'CHECK_IN_RETURN_UNDO':'CHECK_IN_OUTBOUND_UNDO';
 $q=$db->prepare("SELECT p.id,p.name,p.phone,p.departure,b.name bus_name,s.seat_no,
 (SELECT a.action FROM audit_log a WHERE a.entity_type='passenger' AND a.entity_id=p.id AND a.action IN (?,?) ORDER BY a.id DESC LIMIT 1) last_action
 FROM passengers p JOIN buses b ON b.id=p.bus_id JOIN seats s ON s.id=p.seat_id WHERE p.tour_id=? AND p.status='ACTIVE' ORDER BY b.id,s.row_no,s.col_no");
 $q->execute([$check,$undo,$tid]); return $q->fetchAll();
}
$out=leg_state(db(),$tid,'outbound'); $ret=leg_state(db(),$tid,'return');
$oc=count(array_filter($out,fn($p)=>$p['last_action']==='CHECK_IN_OUTBOUND')); $rc=count(array_filter($ret,fn($p)=>$p['last_action']==='CHECK_IN_RETURN'));
if($_SERVER['REQUEST_METHOD']==='POST'){check_csrf();try{
 $action=$_POST['action']??'';
 if($action==='reset_return'){
   $ids=array_column(array_filter($ret,fn($p)=>$p['last_action']==='CHECK_IN_RETURN'),'id');
   $q=db()->prepare("INSERT INTO audit_log(admin_id,action,entity_type,entity_id,details) VALUES(?,?,?,?,?)");
   foreach($ids as $pid){$q->execute([$_SESSION['admin_id'],'CHECK_IN_RETURN_UNDO','passenger',(int)$pid,'Bulk return-trip reset']);}
   audit('RESET_RETURN_CHECKIN', 'tour',$tid,count($ids).' passenger(s) reset');
   flash('success',count($ids).' return check-in(s) reset.');
 }
} catch(Throwable $e){flash('danger',$e->getMessage());} redirect('checkin_dashboard.php');}
?>
<!doctype html><html><head><?php include __DIR__.'/partials/head.php';?><style>
.checkgrid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.big{font-size:28px;font-weight:900}.ok{color:#168344}.pending{color:#b36a00}.dep{display:inline-block;padding:5px 9px;border-radius:999px;background:#eef8f1;font-weight:800;font-size:12px}@media(max-width:700px){.checkgrid{grid-template-columns:1fr}}
</style></head><body><?php include __DIR__.'/partials/nav.php';?><main class="wrap"><?php flash_render();?>
<section class="card"><div class="head"><div><div class="eyebrow">TOUR DAY</div><h1>Check-in Dashboard</h1><p class="muted"><?=h($t['name'])?></p></div><a class="btn secondary" href="dashboard.php">Dashboard</a></div>
<div class="checkgrid">
<div class="stat"><span>Outbound</span><b class="big"><?=$oc?> / <?=count($out)?></b><small class="muted"><?=count($out)-$oc?> remaining</small></div>
<div class="stat"><span>Return</span><b class="big"><?=$rc?> / <?=count($ret)?></b><small class="muted"><?=count($ret)-$rc?> remaining</small></div>
</div></section>
<section class="card"><div class="head"><h2>Return Trip Reset</h2><form method="post" onsubmit="return confirm('Reset all currently checked-in return passengers?')"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="reset_return"><button class="btn danger">↩ Reset Return Check-in</button></form></div><p class="muted">This does not delete passenger data. It records an undo action so you can check everyone in again for the return journey.</p></section>
<?php foreach([['Outbound',$out,'CHECK_IN_OUTBOUND'],['Return',$ret,'CHECK_IN_RETURN']] as [$label,$rows,$x]):?>
<section class="card"><div class="head"><h2><?=$label?> Passenger Status</h2></div><div class="table-wrap responsive-table"><table><thead><tr><th>Seat</th><th>Name</th><th>Phone</th><th>Departure</th><th>Bus</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach($rows as $p):$done=$p['last_action']===$x;?><tr><td><?=h($p['seat_no'])?></td><td><b><?=h($p['name'])?></b></td><td><?=h($p['phone'])?></td><td><span class="dep"><?=h($p['departure']?:'—')?></span></td><td><?=h($p['bus_name'])?></td><td class="<?=$done?'ok':'pending'?>"><?=$done?'✓ Checked In':'Not Checked In'?></td><td><a class="btn secondary" href="passenger_form.php?id=<?=$p['id']?>">Open</a></td></tr><?php endforeach;?>
</tbody></table></div></section><?php endforeach;?>
</main></body></html>