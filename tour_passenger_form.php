<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
saas_require_login();
$orgId=saas_require_organization(); $tourId=saas_require_tour(); saas_require_permission('settings.manage');
$db=saas_db(); $tour=saas_current_tour(); $error=''; $ok='';
$fields=[
 'phone'=>['Phone','Contact number'],
 'email'=>['Email','Email address'],
 'national_id'=>['NID / National ID','National identity number'],
 'date_of_birth'=>['Date of birth','Birth date'],
 'gender'=>['Gender','Gender selection'],
 'blood_group'=>['Blood group','Blood group'],
 'address'=>['Address','Passenger address'],
 'emergency_contact'=>['Emergency contact','Emergency contact name and phone'],
 'passport_number'=>['Passport number','Passport number for international tours'],
 'passport_copy'=>['Passport copy','Upload passport copy'],
 'visa_number'=>['Visa number','Visa number'],
 'visa_copy'=>['Visa copy','Upload visa copy'],
];
$defaults=array_fill_keys(array_keys($fields),true);
$q=$db->prepare("SELECT setting_value FROM tour_settings WHERE tour_id=? AND setting_key='passenger_form_fields' LIMIT 1");
$q->execute([$tourId]);
$saved=json_decode((string)$q->fetchColumn(),true);
$enabled=is_array($saved)?array_merge($defaults,array_map('boolval',$saved)):$defaults;

if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  saas_check_csrf();
  foreach($fields as $key=>$label) $enabled[$key]=isset($_POST['fields'][$key]);
  $json=json_encode($enabled,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
  $q=$db->prepare("INSERT INTO tour_settings(tour_id,setting_key,setting_value,value_type) VALUES(?,?,?,'JSON') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),value_type='JSON'");
  $q->execute([$tourId,'passenger_form_fields',$json]);
  saas_audit('tour.passenger_form_settings.updated','tour',$tourId,$json);
  $ok='Passenger form settings saved.';
 }catch(Throwable $e){$error=$e->getMessage();}
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Passenger form settings — <?=saas_h($tour['name'])?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f5f7fb;color:#172033;font-family:Inter,Arial,sans-serif}.wrap{max-width:850px;margin:28px auto;padding:0 16px}.box{background:#fff;border:1px solid #e5e7eb;border-radius:18px;padding:24px;box-shadow:0 8px 30px #1018280a}.top{display:flex;justify-content:space-between;gap:15px;align-items:center;flex-wrap:wrap}.muted{color:#667085}.row{display:flex;align-items:center;justify-content:space-between;gap:15px;padding:16px 0;border-bottom:1px solid #edf0f2}.row:last-child{border-bottom:0}.row strong{display:block}.row small{color:#667085}.switch{position:relative;width:48px;height:28px;flex:0 0 auto}.switch input{opacity:0;width:0;height:0}.slider{position:absolute;inset:0;background:#d0d5dd;border-radius:999px;cursor:pointer;transition:.2s}.slider:before{content:"";position:absolute;width:22px;height:22px;left:3px;top:3px;background:#fff;border-radius:50%;transition:.2s;box-shadow:0 1px 3px #0002}.switch input:checked+.slider{background:#155eef}.switch input:checked+.slider:before{transform:translateX(20px)}button,a{font:inherit}.btn{display:inline-flex;padding:10px 14px;border:1px solid #d0d5dd;border-radius:10px;background:#fff;color:#172033;font-weight:800;text-decoration:none}.btn.primary{background:#155eef;color:#fff;border-color:#155eef}.msg{padding:11px;border-radius:10px;margin:14px 0}.ok{background:#ecfdf3;color:#067647}.err{background:#fef3f2;color:#b42318}.group{margin-top:20px}.group h3{margin-bottom:4px}@media(max-width:600px){.row{align-items:flex-start}}
</style></head><body><main class="wrap">
<div class="top"><div><a href="tour_edit.php?id=<?=$tourId?>">← Tour settings</a><h1>Passenger form settings</h1><p class="muted">Choose which fields this tour needs. Name, departure, fee and payment remain part of the registration flow.</p></div><a class="btn" href="passengers.php">Passenger page</a></div>
<?php if($ok):?><div class="msg ok"><?=saas_h($ok)?></div><?php endif;?><?php if($error):?><div class="msg err"><?=saas_h($error)?></div><?php endif;?>
<form method="post" class="box"><input type="hidden" name="csrf" value="<?=saas_h(saas_csrf())?>">
<div class="group"><h3>Passenger information</h3><?php foreach($fields as $key=>$meta):?><label class="row"><span><strong><?=saas_h($meta[0])?></strong><small><?=saas_h($meta[1])?></small></span><span class="switch"><input type="checkbox" name="fields[<?=saas_h($key)?>]" <?=$enabled[$key]?'checked':''?>><span class="slider"></span></span></label><?php endforeach;?></div>
<div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px"><a class="btn" href="dashboard.php">Cancel</a><button class="btn primary" type="submit">Save settings</button></div>
</form></main></body></html>