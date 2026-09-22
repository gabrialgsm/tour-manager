<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
saas_require_login();
$orgId=saas_require_organization();
$tourId=saas_require_tour();
$db=saas_db();
$org=saas_current_organization();
$tour=saas_current_tour();
$type=(string)($_GET['type']??'passengers');
$format=(string)($_GET['format']??'pdf');
$map=[
 'passengers'=>['Passenger List',"SELECT pp.full_name,pp.phone,pp.email,tp.departure_from,tp.fee,tp.discount,COALESCE((SELECT SUM(amount) FROM payments p WHERE p.tour_passenger_id=tp.id AND p.tour_id=tp.tour_id),0) paid FROM tour_passengers tp JOIN passenger_profiles pp ON pp.id=tp.passenger_profile_id WHERE tp.tour_id=? AND tp.status<>'CANCELLED' ORDER BY pp.full_name",['full_name'=>'Passenger','phone'=>'Phone','email'=>'Email','departure_from'=>'Departure','fee'=>'Fee (BDT)','discount'=>'Discount (BDT)','paid'=>'Paid (BDT)']],
 'expenses'=>['Expense List',"SELECT expense_date,category,description,amount FROM expenses WHERE tour_id=? ORDER BY expense_date DESC,id DESC",['expense_date'=>'Date','category'=>'Category','description'=>'Description','amount'=>'Amount (BDT)']],
 'income'=>['Income List',"SELECT income_date,category,received_from,description,amount FROM incomes WHERE tour_id=? ORDER BY income_date DESC,id DESC",['income_date'=>'Date','category'=>'Category','received_from'=>'Received From','description'=>'Description','amount'=>'Amount (BDT)']],
 'rooms'=>['Room List',"SELECT r.room_no,r.room_type,r.capacity,r.status,COUNT(ra.tour_passenger_id) occupied FROM rooms r LEFT JOIN room_assignments ra ON ra.room_id=r.id WHERE r.tour_id=? GROUP BY r.id ORDER BY r.room_no",['room_no'=>'Room','room_type'=>'Type','capacity'=>'Capacity','status'=>'Status','occupied'=>'Occupied']],
 'features'=>['Tour Features',"SELECT feature_key,enabled,config_json FROM tour_features WHERE tour_id=? ORDER BY id",['feature_key'=>'Feature','enabled'=>'Status','config_json'=>'Description']],
 'payments'=>['Payment Report',"SELECT p.payment_date,pp.full_name,p.amount,p.payment_method,p.transaction_reference FROM payments p JOIN tour_passengers tp ON tp.id=p.tour_passenger_id JOIN passenger_profiles pp ON pp.id=tp.passenger_profile_id WHERE p.tour_id=? AND p.organization_id=? ORDER BY p.payment_date DESC,p.id DESC",['payment_date'=>'Date','full_name'=>'Passenger','amount'=>'Amount (BDT)','payment_method'=>'Method','transaction_reference'=>'Transaction Ref.']],
 'buses'=>['Bus & Seat Report',"SELECT name,bus_number,layout_type,total_seats,status FROM buses WHERE tour_id=? ORDER BY id",['name'=>'Bus Name','bus_number'=>'Bus Number','layout_type'=>'Layout','total_seats'=>'Total Seats','status'=>'Status']],
 'tickets'=>['Ticket Report',"SELECT pp.full_name,pp.phone,ti.ticket_number,ti.status,ti.issued_at FROM ticket_instances ti JOIN tour_passengers tp ON tp.id=ti.tour_passenger_id JOIN passenger_profiles pp ON pp.id=tp.passenger_profile_id WHERE ti.tour_id=? AND ti.organization_id=? ORDER BY ti.id DESC",['full_name'=>'Passenger','phone'=>'Phone','ticket_number'=>'Ticket Number','status'=>'Status','issued_at'=>'Issued At']]
];
if(!isset($map[$type]))$type='passengers';
[$title,$sql,$cols]=$map[$type];
$q=$db->prepare($sql);
$type==='payments'||$type==='tickets'?$q->execute([$tourId,$orgId]):$q->execute([$tourId]);
$rows=$q->fetchAll();
foreach($rows as &$row){
 if($type==='features'){
  $cfg=json_decode((string)$row['config_json'],true)?:[];
  $row['feature_key']=$cfg['label']??$row['feature_key'];
  $row['enabled']=!empty($row['enabled'])?'Enabled':'Disabled';
  $row['config_json']=$cfg['description']??'';
 }
 if($type==='rooms')$row['available']=max(0,(int)$row['capacity']-(int)$row['occupied']);
}
unset($row);
function xh(string $v):string{return htmlspecialchars($v,ENT_QUOTES,'UTF-8');}
if($format==='excel'){
 $fn=preg_replace('/[^A-Za-z0-9_-]+/','-',(string)$tour['name']).'-'.strtolower(str_replace(' ','-',(string)$title)).'.xls';
 header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
 header('Content-Disposition: attachment; filename="'.$fn.'"');
 echo "\xEF\xBB\xBF".'<html><head><meta charset="UTF-8"><style>body{font-family:Arial}h1{color:#0b2a49}th{background:#0b2a49;color:#fff}th,td{border:1px solid #d0d5dd;padding:8px}table{border-collapse:collapse;width:100%}</style></head><body>';
 echo '<h1>'.xh($title).' — '.xh((string)$tour['name']).'</h1><h3>'.xh((string)$org['name']).' · '.date('d M Y, h:i A').'</h3><table><tr>';
 foreach($cols as $key=>$label)echo '<th>'.xh($label).'</th>';
 echo '</tr>';
 foreach($rows as $row){echo '<tr>';foreach($cols as $key=>$label)echo '<td>'.xh((string)($row[$key]??'')).'</td>';echo '</tr>';}
 echo '</table></body></html>';exit;
}
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=xh($title)?> — <?=xh((string)$tour['name'])?></title><style>
@page{size:A4 landscape;margin:12mm}*{box-sizing:border-box}body{margin:0;background:#eef2f6;font-family:Arial,sans-serif;color:#172033}.sheet{max-width:1400px;margin:24px auto;padding:28px;background:#fff;box-shadow:0 8px 30px #0001}.toolbar{text-align:right;margin-bottom:15px}.btn{display:inline-block;padding:9px 14px;margin-left:7px;border:1px solid #d0d5dd;border-radius:8px;background:#fff;color:#172033;text-decoration:none;font-weight:bold}.primary{background:#0b2a49;color:#fff}.brand{display:flex;justify-content:space-between;border-bottom:3px solid #0b2a49;padding-bottom:14px;margin-bottom:16px}.brand h1{margin:0;color:#0b2a49}.muted{color:#667085;font-size:12px}table{width:100%;border-collapse:collapse;font-size:11px}th{background:#0b2a49;color:#fff;padding:8px;text-align:left}td{padding:7px;border:1px solid #e4e7ec}tbody tr:nth-child(even){background:#f8fafc}.footer{margin-top:15px;color:#667085;font-size:10px}@media print{body{background:#fff}.sheet{margin:0;padding:0;box-shadow:none}.toolbar{display:none}th{-webkit-print-color-adjust:exact;print-color-adjust:exact}}@media(max-width:900px){.sheet{margin:0;padding:15px;overflow:auto}}
</style></head><body><main class="sheet"><div class="toolbar"><button class="btn primary" onclick="window.print()">Print / Save as PDF</button><a class="btn" href="?type=<?=rawurlencode($type)?>&format=excel">Export Excel</a><a class="btn" href="javascript:history.back()">Back</a></div><div class="brand"><div><h1><?=xh($title)?></h1><div class="muted"><?=xh((string)$tour['name'])?></div></div><div class="muted"><?=xh((string)$org['name'])?><br>Generated <?=date('d M Y, h:i A')?><br><?=count($rows)?> records</div></div><table><thead><tr><?php foreach($cols as $label):?><th><?=xh($label)?></th><?php endforeach;?></tr></thead><tbody><?php foreach($rows as $row):?><tr><?php foreach($cols as $key=>$label):?><td><?=xh((string)($row[$key]??''))?></td><?php endforeach;?></tr><?php endforeach;?></tbody></table><div class="footer">GoTM — GoZyraa Tour Management</div></main></body></html>