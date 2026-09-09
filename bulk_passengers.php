<?php
require __DIR__.'/bootstrap.php'; require_login(); $tid=require_tour();
$q=db()->prepare("SELECT p.id,p.name,p.phone,p.departure,b.name bus_name,s.seat_no,COALESCE((SELECT SUM(amount) FROM payments WHERE passenger_id=p.id),0) paid FROM passengers p JOIN buses b ON b.id=p.bus_id JOIN seats s ON s.id=p.seat_id WHERE p.tour_id=? AND p.status='ACTIVE' ORDER BY b.id,s.row_no,s.col_no");$q->execute([$tid]);$rows=$q->fetchAll();
?>
<!doctype html><html><head><?php include __DIR__.'/partials/head.php';?><style>.bulk-actions{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0}.selected-count{font-weight:800;color:#168344}</style></head><body><?php include __DIR__.'/partials/nav.php';?><main class="wrap">
<section class="card"><div class="head"><div><div class="eyebrow">BULK TOOLS</div><h2>Passenger Bulk Actions</h2></div><a class="btn secondary" href="admin_tools.php">Back</a></div>
<form id="bulkForm" method="get">
<div class="bulk-actions"><button type="button" class="btn secondary" id="selectAll">Select All</button><button type="button" class="btn secondary" id="clearAll">Clear</button><button type="button" class="btn primary" id="printBtn">Print Selected Tickets</button><button type="button" class="btn primary" id="exportBtn">Export Selected CSV</button><span class="selected-count" id="count">0 selected</span></div>
<div class="table-wrap responsive-table"><table><thead><tr><th></th><th>Seat</th><th>Name</th><th>Phone</th><th>Departure</th><th>Bus</th><th>Paid</th></tr></thead><tbody>
<?php foreach($rows as $p):?><tr><td><input class="pick" type="checkbox" value="<?=$p['id']?>"></td><td><?=h($p['seat_no'])?></td><td><b><?=h($p['name'])?></b></td><td><?=h($p['phone'])?></td><td><?=h($p['departure'])?></td><td><?=h($p['bus_name'])?></td><td><?=money($p['paid'])?></td></tr><?php endforeach;?>
</tbody></table></div></form></section></main>
<script>
const picks=()=>[...document.querySelectorAll('.pick:checked')].map(x=>x.value);
const update=()=>document.getElementById('count').textContent=picks().length+' selected';
document.querySelectorAll('.pick').forEach(x=>x.onchange=update);
selectAll.onclick=()=>{document.querySelectorAll('.pick').forEach(x=>x.checked=true);update()};
clearAll.onclick=()=>{document.querySelectorAll('.pick').forEach(x=>x.checked=false);update()};
printBtn.onclick=()=>{let a=picks();if(!a.length)return alert('Select at least one passenger.');location.href='tickets.php?ids='+a.join(',')};
exportBtn.onclick=()=>{let a=picks();if(!a.length)return alert('Select at least one passenger.');location.href='passenger_export.php?ids='+a.join(',')};
</script></body></html>