<?php
require __DIR__.'/bootstrap.php';require __DIR__.'/bootstrap_auth.php';
require_login();
$tid=require_tour();

function gmjs_layout_seat_count(string $layout, int $rows, int $front, int $last): int {
    $per = $layout === '2+3' ? 5 : 4;
    $rows = max(1,$rows);
    $front = max(0,$front);
    $last = max(1,$last);
    return (($rows-1)*$per) + $last + $front;
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    check_csrf();
    $action=$_POST['action']??'';
    try{
        if($action==='add'){require_permission('bus.create');
            $name=trim($_POST['name']??'');
            $number=trim($_POST['bus_number']??'');
            $layout=($_POST['layout_type']??'2+2')==='2+3'?'2+3':'2+2';
            $rows=max(1,min(50,(int)($_POST['normal_rows']??1)));
            $front=max(0,min(2,(int)($_POST['front_single_count']??0)));
            $frontLabel=($_POST['front_single_label']??'A0')==='S1'?'S1':'A0';
            $last=max(1,min(6,(int)($_POST['last_row_seats']??4)));
            $total=max(1,min(100,(int)($_POST['seat_count']??0)));
            $calculated=gmjs_layout_seat_count($layout,$rows,$front,$last);
            if(!$name) throw new Exception('Bus name is required.');
            if($total!==$calculated) throw new Exception("Total seats mismatch. This layout creates $calculated seats; enter $calculated in Total Seats.");

            $pdo=db(); $pdo->beginTransaction();
            $q=$pdo->prepare("INSERT INTO buses(tour_id,name,bus_number,seat_count,rows_count,layout_type,normal_rows,front_single_count,front_single_label,last_row_seats) VALUES(?,?,?,?,?,?,?,?,?,?)");
            $q->execute([$tid,$name,$number?:null,$total,$rows,$layout,$rows,$front,$frontLabel,$last]);
            $bid=(int)$pdo->lastInsertId();
            $ins=$pdo->prepare("INSERT INTO seats(bus_id,seat_no,row_no,col_no) VALUES(?,?,?,?)");

            // Front single seats: row 0, so they render above the first normal row.
            // The first one is always the leftmost front seat.
            for($i=1;$i<=$front;$i++){
                $label=$front===1 ? $frontLabel : ($frontLabel==='S1'?'S'.$i:($i===1?'A0':'A'.(-$i+1)));
                $ins->execute([$bid,$label,0,$i]);
            }

            $per=$layout==='2+3'?5:4;
            // Normal rows: rows includes the last row; last row uses its own seat count.
            for($r=1;$r<=$rows;$r++){
                $count=($r===$rows)?$last:$per;
                for($c=1;$c<=$count;$c++){
                    // 2+2: left 2 seats = columns 1,2; right 2 = 3,4.
                    // 2+3: left 2 = 1,2; right 3 = 3,4,5.
                    $label=chr(64+$r).$c;
                    $ins->execute([$bid,$label,$r,$c]);
                }
            }
            $pdo->commit();
            audit('ADD_BUS','bus',$bid,$name);
            flash('success','New bus added with '.$total.' seats.');
        }
        elseif($action==='edit'){require_permission('bus.edit');
            $id=(int)$_POST['id'];
            $q=db()->prepare("UPDATE buses SET name=?,bus_number=?,active=? WHERE id=? AND tour_id=?");
            $q->execute([trim($_POST['name']??''),trim($_POST['bus_number']??'')?:null,isset($_POST['active'])?1:0,$id,$tid]);
            audit('UPDATE_BUS','bus',$id,$_POST['name']??'');
            flash('success','Bus updated.');
        }
        elseif($action==='delete'){require_permission('bus.delete');
            $id=(int)$_POST['id'];
            $c=db()->prepare("SELECT COUNT(*) FROM passengers WHERE bus_id=? AND status='ACTIVE'");$c->execute([$id]);
            if((int)$c->fetchColumn()>0) throw new Exception('Cannot delete a bus with active passengers. Deactivate it instead.');
            db()->prepare("DELETE FROM seats WHERE bus_id=?")->execute([$id]);
            db()->prepare("DELETE FROM buses WHERE id=? AND tour_id=?")->execute([$id,$tid]);
            audit('DELETE_BUS','bus',$id); flash('success','Bus deleted.');
        }
    }catch(Throwable $e){ if(db()->inTransaction())db()->rollBack(); flash('danger',$e->getMessage()); }
    redirect('bus_manage.php');
}

$q=db()->prepare("SELECT b.*,(SELECT COUNT(*) FROM passengers p WHERE p.bus_id=b.id AND p.status='ACTIVE') booked FROM buses b WHERE b.tour_id=? ORDER BY b.id");
$q->execute([$tid]); $buses=$q->fetchAll();
?>
<!doctype html><html><head><?php include __DIR__.'/partials/head.php';?>
<style>
.bus-builder{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.bus-builder label{margin:0}.layout-help{background:#f4faf6;border:1px solid #dcefe2;border-radius:12px;padding:11px;font-size:12px;color:#466356}
.total-box{font-size:13px;font-weight:900;background:#f5f8fb;border-radius:10px;padding:11px}.total-box strong{color:#08783d}
.preview-wrap{margin-top:12px;border:1px solid #dcefe2;border-radius:16px;padding:16px;background:#fbfefc}.preview{max-width:620px;margin:auto}.preview .driver{width:100%;margin-bottom:10px}.preview-row{display:grid;gap:8px;margin:8px 0}.preview-row.two{grid-template-columns:1fr 1fr 18px 1fr 1fr}.preview-row.three{grid-template-columns:1fr 1fr 18px 1fr 1fr 1fr}.pv-seat{min-height:42px;border:1px solid #9be6ae;border-radius:10px;background:#effff3;display:grid;place-items:center;font-weight:900;color:#14823b}.pv-aisle{min-width:0}.front-row{display:grid;grid-template-columns:1fr 1fr 18px 1fr 1fr 1fr;gap:8px;margin-bottom:8px}.front-seat{grid-column:1;max-width:120px}.mismatch{color:#b42318;font-weight:800}.match{color:#08783d;font-weight:800}
@media(max-width:700px){.bus-builder{grid-template-columns:1fr}.preview-wrap{overflow:auto}.preview{min-width:500px}}
</style></head><body><?php include __DIR__.'/partials/nav.php';?><main class="wrap"><?php flash_render();
?>
<section class="card"><div class="head"><div><div class="eyebrow">BUS CONFIGURATION</div><h2>+ Add New Bus</h2><p class="muted">Build the real seat layout before creating the bus.</p></div></div>
<form method="post" id="busForm"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="add">
<div class="bus-builder">
<label>Bus Name<input name="name" placeholder="Green Line" value="New Bus <?=count($buses)+1?>" required></label>
<label>Bus Number<input name="bus_number" placeholder="DH-BA-11-0001"></label>
<label>Layout<select name="layout_type" id="layout"><option value="2+2">2 + 2 (4 seats / normal row)</option><option value="2+3">2 + 3 (5 seats / normal row)</option></select></label>
<label>Normal Rows<input name="normal_rows" id="rows" type="number" min="1" max="50" value="12"></label>
<label>Front Single Seats<input name="front_single_count" id="front" type="number" min="0" max="2" value="1"></label>
<label>Front Single Label<select name="front_single_label" id="frontLabel"><option value="A0">A0</option><option value="S1">S1</option></select></label>
<label>Last Row Seats<input name="last_row_seats" id="last" type="number" min="1" max="6" value="4"></label>
<label>Total Seats<input name="seat_count" id="total" type="number" min="1" max="100" value="49" required></label>
</div>
<div class="total-box" id="totalInfo"></div>
<div class="layout-help" style="margin-top:10px">Rows means the total number of rows including the last row. The last row replaces one normal row. Example: 2+2 + 12 rows + last row 4 + 1 front single = 49 seats.</div>
<div class="preview-wrap"><div class="eyebrow">LIVE PREVIEW</div><div class="preview" id="preview"></div></div>
<div class="row-actions" style="margin-top:14px"><button class="btn primary" id="createBtn">Create Bus</button></div>
</form></section>
<section class="card"><h2>Current Tour Buses</h2><div class="table-wrap"><table><thead><tr><th>Bus Name</th><th>Bus Number</th><th>Seats</th><th>Booked</th><th>Status</th><th>Action</th></tr></thead><tbody><?php foreach($buses as $b):?><tr><td><?=h($b['name'])?></td><td><?=h($b['bus_number']??'—')?></td><td><?=$b['seat_count']?></td><td><?=$b['booked']?></td><td><?=$b['active']?'<span class="badge">Active</span>':'<span class="badge due">Inactive</span>'?></td><td><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="edit"><input type="hidden" name="id" value="<?=$b['id']?>"><input type="hidden" name="name" value="<?=h($b['name'])?>"><input type="hidden" name="bus_number" value="<?=h($b['bus_number']??'')?>"><input type="hidden" name="active" value="<?=$b['active']?1:0?>"></form><a class="btn primary" href="bus_edit.php?id=<?=$b['id']?>">Edit</a> <?php if(!$b['booked']):?><form method="post" style="display:inline" onsubmit="return confirm('Delete this bus?')"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$b['id']?>"><?php if(can("bus.delete")):?><?php if(can("bus.delete")):?><button class="btn danger">Delete</button><?php endif;?><?php endif;?></form><?php endif;?></td></tr><?php endforeach;?></tbody></table></div></section>
</main>
<script>
(()=>{
 const layout=document.getElementById('layout'),rows=document.getElementById('rows'),front=document.getElementById('front'),last=document.getElementById('last'),total=document.getElementById('total'),fl=document.getElementById('frontLabel'),info=document.getElementById('totalInfo'),preview=document.getElementById('preview'),btn=document.getElementById('createBtn');
 const calc=()=>{const per=layout.value==='2+3'?5:4,r=Math.max(1,+rows.value||1),f=Math.max(0,+front.value||0),l=Math.max(1,+last.value||1);return (r-1)*per+l+f};
 const render=()=>{const per=layout.value==='2+3'?5:4,r=Math.max(1,+rows.value||1),f=Math.max(0,+front.value||0),l=Math.max(1,+last.value||1),c=calc();
   info.innerHTML=`Total seats: <strong>${c}</strong> · ${layout.value} · ${r} rows · ${f} front single · last row ${l} seats`;
   const entered=+total.value||0,ok=entered===c; info.innerHTML+=` <span class="${ok?'match':'mismatch'}">${ok?'✓ matches':'⚠ enter '+c}</span>`;btn.disabled=!ok;
   let h='<div class="driver">DRIVER</div>';
   if(f){h+='<div class="front-row">';for(let i=1;i<=f;i++){const lab=f===1?fl.value:(fl.value==='S1'?'S'+i:(i===1?'A0':'A'+(-i+1)));h+=`<div class="pv-seat front-seat">${lab}</div>`;if(i<f)h+='<div></div>';}h+='</div>';}
   for(let rr=1;rr<=r;rr++){const n=rr===r?l:per;h+=`<div class="preview-row ${per===5?'three':'two'}">`;for(let c0=1;c0<=n;c0++){if(c0===3)h+='<div class="pv-aisle"></div>';h+=`<div class="pv-seat">${String.fromCharCode(64+rr)}${c0}</div>`;}h+='</div>';}
   preview.innerHTML=h;
 };
 [layout,rows,front,last,total,fl].forEach(x=>x.addEventListener('input',render));
 [layout,rows,front,last,fl].forEach(x=>x.addEventListener('change',render));
 render();
})();
</script></body></html>
