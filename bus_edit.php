<?php
require __DIR__.'/bootstrap.php';require __DIR__.'/bootstrap_auth.php';
require_login();
$tid=require_tour();
$id=(int)($_GET['id']??0);

$q=db()->prepare("SELECT b.*,(SELECT COUNT(*) FROM passengers p WHERE p.bus_id=b.id) passenger_count FROM buses b WHERE b.id=? AND b.tour_id=?");
$q->execute([$id,$tid]);
$b=$q->fetch();
if(!$b) redirect('bus_manage.php');

$layoutType=$b['layout_type']??'legacy5';
$normalRows=(int)($b['normal_rows']??$b['rows_count']??1);
$frontCount=(int)($b['front_single_count']??0);
$frontLabel=(($b['front_single_label']??'A0')==='S1')?'S1':'A0';
$lastSeats=(int)($b['last_row_seats']??0);
if($layoutType==='legacy5' && $lastSeats<1) $lastSeats=5;
if($lastSeats<1) $lastSeats=($layoutType==='2+3'?5:4);

function gmjs_edit_calc(string $layout,int $rows,int $front,int $last):int{
    if($layout==='legacy5') return max(1,$rows)*5;
    $per=$layout==='2+3'?5:4;
    return max(1,($rows-1))*$per + max(1,$last) + max(0,$front);
}

if($_SERVER['REQUEST_METHOD']==='POST'){ require_permission('bus.edit');
    check_csrf();
    try{
        $name=trim($_POST['name']??'');
        $number=trim($_POST['bus_number']??'');
        if(!$name) throw new Exception('Bus name is required.');

        $layout=($_POST['layout_type']??'2+2');
        if(!in_array($layout,['legacy5','2+2','2+3'],true)) $layout='2+2';
        $rows=max(1,min(50,(int)($_POST['normal_rows']??1)));
        $front=max(0,min(2,(int)($_POST['front_single_count']??0)));
        $frontLabel=(($_POST['front_single_label']??'A0')==='S1')?'S1':'A0';
        $last=max(1,min(6,(int)($_POST['last_row_seats']??4)));
        $total=max(1,min(100,(int)($_POST['seat_count']??0)));
        $active=isset($_POST['active'])?1:0;

        if($layout==='legacy5'){
            $front=0;
            $frontLabel='A0';
            $last=5;
        }
        $calculated=gmjs_edit_calc($layout,$rows,$front,$last);
        if($total!==$calculated){
            throw new Exception("Total seats mismatch. This layout creates $calculated seats; enter $calculated in Total Seats.");
        }

        $passengerCount=(int)$b['passenger_count'];
        $layoutChanged=(($b['layout_type']??'legacy5')!==$layout
            || (int)($b['normal_rows']??$b['rows_count']??0)!==$rows
            || (int)($b['front_single_count']??0)!==$front
            || (($b['front_single_label']??'A0')!==$frontLabel)
            || (int)($b['last_row_seats']??0)!==$last
            || (int)$b['seat_count']!==$total);

        $pdo=db();
        $pdo->beginTransaction();

        if($layoutChanged && $passengerCount>0){
            throw new Exception('Seat layout cannot be changed because this bus already has passenger assignments. Create a new bus with the new layout instead.');
        }

        $q=$pdo->prepare("UPDATE buses SET name=?,bus_number=?,seat_count=?,rows_count=?,layout_type=?,normal_rows=?,front_single_count=?,front_single_label=?,last_row_seats=?,active=? WHERE id=? AND tour_id=?");
        $q->execute([$name,$number?:null,$total,$rows,$layout,$rows,$front,$frontLabel,$last,$active,$id,$tid]);

        if($layoutChanged){
            $pdo->prepare("DELETE FROM seats WHERE bus_id=?")->execute([$id]);
            $ins=$pdo->prepare("INSERT INTO seats(bus_id,seat_no,row_no,col_no) VALUES(?,?,?,?)");

            if($layout==='legacy5'){
                for($r=1;$r<=$rows;$r++){
                    for($c=1;$c<=5;$c++){
                        $ins->execute([$id,chr(64+$r).$c,$r,$c]);
                    }
                }
            }else{
                if($front>0){
                    for($i=1;$i<=$front;$i++){
                        $label=$front===1?$frontLabel:($frontLabel==='S1'?'S'.$i:($i===1?'A0':'A'.(-$i+1)));
                        $ins->execute([$id,$label,0,$i]);
                    }
                }
                $per=$layout==='2+3'?5:4;
                for($r=1;$r<=$rows;$r++){
                    $count=($r===$rows)?$last:$per;
                    for($c=1;$c<=$count;$c++){
                        $ins->execute([$id,chr(64+$r).$c,$r,$c]);
                    }
                }
            }
        }

        $pdo->commit();
        audit('UPDATE_BUS','bus',$id,$name.' · '.$layout.' · '.$total.' seats');
        flash('success','Bus updated successfully.');
        redirect('bus_manage.php');
    }catch(Throwable $e){
        if(isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        flash('danger',$e->getMessage());
        redirect('bus_edit.php?id='.$id);
    }
}

$locked=(int)$b['passenger_count']>0;
?>
<!doctype html>
<html><head><?php include __DIR__.'/partials/head.php';?>
<style>
.bus-builder{display:grid;grid-template-columns:1fr 1fr;gap:12px}.bus-builder label{margin:0}
.layout-help{background:#f4faf6;border:1px solid #dcefe2;border-radius:12px;padding:11px;font-size:12px;color:#466356;margin-top:10px}
.total-box{font-size:13px;font-weight:900;background:#f5f8fb;border-radius:10px;padding:11px;margin-top:10px}.total-box strong{color:#08783d}.mismatch{color:#b42318}.match{color:#08783d}
.lock-note{background:#fff8e8;border:1px solid #f4d58a;color:#8a5a00;border-radius:12px;padding:11px;font-size:12px;margin:12px 0}
.preview-wrap{margin-top:14px;border:1px solid #dcefe2;border-radius:16px;padding:16px;background:#fbfefc;overflow:auto}.preview{max-width:680px;min-width:520px;margin:auto}.preview .driver{width:100%;margin-bottom:10px}.preview-row{display:grid;gap:8px;margin:8px 0}.preview-row.two{grid-template-columns:1fr 1fr 18px 1fr 1fr}.preview-row.three{grid-template-columns:1fr 1fr 18px 1fr 1fr 1fr}.pv-seat{min-height:42px;border:1px solid #9be6ae;border-radius:10px;background:#effff3;display:grid;place-items:center;font-weight:900;color:#14823b}.front-row{display:grid;grid-template-columns:1fr 1fr 18px 1fr 1fr 1fr;gap:8px;margin-bottom:8px}.front-seat{grid-column:1;max-width:120px}
@media(max-width:700px){.bus-builder{grid-template-columns:1fr}.preview{min-width:500px}}
</style></head><body><?php include __DIR__.'/partials/nav.php';?>
<main class="wrap"><?php flash_render();?>
<section class="card">
<div class="head"><div><div class="eyebrow">BUS CONFIGURATION</div><h2>Edit Bus</h2><p class="muted">Update the bus information and, when safe, its complete seat layout.</p></div><a class="btn secondary" href="bus_manage.php">← Back to Buses</a></div>
<?php if($locked): ?><div class="lock-note"><b>Seat layout locked:</b> this bus already has <?=h($b['passenger_count'])?> passenger assignment(s). You can view the layout here, but changing the seat structure would invalidate existing assignments. Create a new bus for a different layout.</div><?php endif; ?>
<form method="post" id="busEditForm"><input type="hidden" name="csrf" value="<?=h(csrf())?>">
<div class="bus-builder">
<label>Bus Name<input name="name" value="<?=h($b['name'])?>" required></label>
<label>Bus Number<input name="bus_number" value="<?=h($b['bus_number']??'')?>" placeholder="DH-BA-11-0001"></label>
<label>Layout<select name="layout_type" id="layout" <?=$locked?'disabled':''?>><option value="legacy5" <?=$layoutType==='legacy5'?'selected':''?>>Legacy 5 (5 seats / row)</option><option value="2+2" <?=$layoutType==='2+2'?'selected':''?>>2 + 2 (4 seats / normal row)</option><option value="2+3" <?=$layoutType==='2+3'?'selected':''?>>2 + 3 (5 seats / normal row)</option></select></label>
<label>Normal Rows<input name="normal_rows" id="rows" type="number" min="1" max="50" value="<?=h($normalRows)?>" <?=$locked?'disabled':''?>></label>
<label>Front Single Seats<input name="front_single_count" id="front" type="number" min="0" max="2" value="<?=h($frontCount)?>" <?=$locked?'disabled':''?>></label>
<label>Front Single Label<select name="front_single_label" id="frontLabel" <?=$locked?'disabled':''?>><option value="A0" <?=$frontLabel==='A0'?'selected':''?>>A0</option><option value="S1" <?=$frontLabel==='S1'?'selected':''?>>S1</option></select></label>
<label>Last Row Seats<input name="last_row_seats" id="last" type="number" min="1" max="6" value="<?=h($lastSeats)?>" <?=$locked?'disabled':''?>></label>
<label>Total Seats<input name="seat_count" id="total" type="number" min="1" max="100" value="<?=h($b['seat_count'])?>" required <?=$locked?'disabled':''?>></label>
</div>
<?php if($locked): ?>
<input type="hidden" name="layout_type" value="<?=h($layoutType)?>"><input type="hidden" name="normal_rows" value="<?=h($normalRows)?>"><input type="hidden" name="front_single_count" value="<?=h($frontCount)?>"><input type="hidden" name="front_single_label" value="<?=h($frontLabel)?>"><input type="hidden" name="last_row_seats" value="<?=h($lastSeats)?>"><input type="hidden" name="seat_count" value="<?=h($b['seat_count'])?>">
<?php endif; ?>
<div class="total-box" id="totalInfo"></div>
<div class="layout-help">For 2+2, normal rows have 4 seats. For 2+3, normal rows have 5 seats. The last row can have up to 6 seats. A front single seat is always placed on the <b>left above A1</b> and can be named <b>A0</b> or <b>S1</b>.</div>
<div class="preview-wrap"><div class="eyebrow">LIVE PREVIEW</div><div class="preview" id="preview"></div></div>
<div class="row-actions" style="margin-top:14px"><label class="check" style="margin:0"><input type="checkbox" name="active" <?=$b['active']?'checked':''?>> Active</label><button class="btn primary">Save Changes</button><a class="btn secondary" href="bus_manage.php">Cancel</a></div>
</form></section></main>
<script>
(()=>{
 const layout=document.getElementById('layout'),rows=document.getElementById('rows'),front=document.getElementById('front'),last=document.getElementById('last'),total=document.getElementById('total'),fl=document.getElementById('frontLabel'),info=document.getElementById('totalInfo'),preview=document.getElementById('preview');
 const calc=()=>{const type=layout.value,r=Math.max(1,+rows.value||1),f=Math.max(0,+front.value||0),l=Math.max(1,+last.value||1);if(type==='legacy5')return r*5;const per=type==='2+3'?5:4;return (r-1)*per+l+f;};
 const render=()=>{const type=layout.value,r=Math.max(1,+rows.value||1),f=type==='legacy5'?0:Math.max(0,+front.value||0),l=type==='legacy5'?5:Math.max(1,+last.value||1),c=calc(),entered=+total.value||0,ok=entered===c;info.innerHTML=`Total seats: <strong>${c}</strong> · ${type} · ${r} rows · ${f} front single · last row ${l} seats <span class="${ok?'match':'mismatch'}">${ok?'✓ matches':'⚠ enter '+c}</span>`;
   let h='<div class="driver">DRIVER</div>';
   if(f){h+='<div class="front-row"><div class="pv-seat front-seat">'+fl.value+'</div></div>';}
   if(type==='legacy5'){for(let rr=1;rr<=r;rr++){h+=`<div class="preview-row three">`;for(let c=1;c<=5;c++)h+=`<div class="pv-seat">${String.fromCharCode(64+rr)}${c}</div>`;h+='</div>';}}
   else {const per=type==='2+3'?5:4;for(let rr=1;rr<=r;rr++){const n=rr===r?l:per;h+=`<div class="preview-row ${per===5?'three':'two'}">`;for(let c=1;c<=n;c++){if(c===3)h+='<div></div>';h+=`<div class="pv-seat">${String.fromCharCode(64+rr)}${c}</div>`;}h+='</div>';}}
   preview.innerHTML=h;
 };
 [layout,rows,front,last,total,fl].forEach(x=>x&&x.addEventListener('input',render));[layout,rows,front,last,fl].forEach(x=>x&&x.addEventListener('change',render));render();
})();
</script></body></html>
