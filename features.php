<?php
declare(strict_types=1);require __DIR__.'/bootstrap.php';require __DIR__.'/feature_helpers.php';
saas_require_login();$tourId=saas_require_tour();saas_require_permission('settings.manage');$orgId=saas_current_organization_id();$db=saas_db();$error='';$ok='';$catalog=saas_feature_catalog();$q=$db->prepare('SELECT * FROM tour_features WHERE tour_id=? ORDER BY id');$q->execute([$tourId]);$existing=[];foreach($q->fetchAll() as $r)$existing[$r['feature_key']]=$r;
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  saas_check_csrf();
  $action=(string)($_POST['action']??'');
  if($action==='add_custom'){
   saas_require_entitlement($orgId,'custom_features','Custom features require a paid plan.');
   $label=trim((string)($_POST['custom_label']??''));
   $description=trim((string)($_POST['custom_description']??''));
   if($label===''||strlen($label)>180)throw new RuntimeException('Feature name is required and must be 180 characters or less.');
   if(strlen($description)>1000)throw new RuntimeException('Description is too long.');
   $base=saas_slug($label);
   if($base==='')$base='custom-feature';
   $key=$base.'-'.bin2hex(random_bytes(4));
   $config=['label'=>$label,'description'=>$description,'custom'=>true];
   $q=$db->prepare('INSERT INTO tour_features(tour_id,feature_key,enabled,config_json) VALUES(?,?,1,?)');
   $q->execute([$tourId,$key,json_encode($config,JSON_UNESCAPED_UNICODE)]);
   saas_audit('tour_feature.created','tour_feature',null,json_encode(['feature'=>$key,'label'=>$label],JSON_UNESCAPED_UNICODE));
   $ok='Custom feature added.';
  }elseif($action==='update_custom'){
   $key=(string)($_POST['feature_key']??'');
   $q=$db->prepare('SELECT id,enabled,config_json FROM tour_features WHERE tour_id=? AND feature_key=? LIMIT 1');
   $q->execute([$tourId,$key]);$r=$q->fetch();
   $cfg=json_decode((string)($r['config_json']??''),true)?:[];
   if(!$r||empty($cfg['custom']))throw new RuntimeException('Custom feature not found.');
   $label=trim((string)($_POST['label']??''));
   $description=trim((string)($_POST['description']??''));
   if($label===''||strlen($label)>180)throw new RuntimeException('Display label is required and must be 180 characters or less.');
   if(strlen($description)>1000)throw new RuntimeException('Description is too long.');
   $enabled=isset($_POST['enabled'])?1:0;
   $config=['label'=>$label,'description'=>$description,'custom'=>true];
   $q=$db->prepare('UPDATE tour_features SET enabled=?,config_json=? WHERE id=? AND tour_id=?');
   $q->execute([$enabled,json_encode($config,JSON_UNESCAPED_UNICODE),(int)$r['id'],$tourId]);
   saas_audit('tour_feature.updated','tour_feature',(int)$r['id'],json_encode(['feature'=>$key,'enabled'=>$enabled,'label'=>$label],JSON_UNESCAPED_UNICODE));
   $ok='Feature settings saved.';
  }elseif($action==='toggle_custom'){
   $key=(string)($_POST['feature_key']??'');
   $q=$db->prepare('SELECT id,enabled,config_json FROM tour_features WHERE tour_id=? AND feature_key=? LIMIT 1');
   $q->execute([$tourId,$key]);$r=$q->fetch();
   $cfg=json_decode((string)($r['config_json']??''),true)?:[];
   if(!$r||empty($cfg['custom']))throw new RuntimeException('Custom feature not found.');
   $enabled=(int)!((int)$r['enabled']);
   $q=$db->prepare('UPDATE tour_features SET enabled=? WHERE id=? AND tour_id=?');
   $q->execute([$enabled,(int)$r['id'],$tourId]);
   saas_audit('tour_feature.updated','tour_feature',(int)$r['id'],json_encode(['feature'=>$key,'enabled'=>$enabled],JSON_UNESCAPED_UNICODE));
   $ok=$enabled?'Custom feature enabled.':'Custom feature disabled.';
  }elseif($action==='delete_custom'){
   $key=(string)($_POST['feature_key']??'');
   $q=$db->prepare('SELECT id,config_json FROM tour_features WHERE tour_id=? AND feature_key=? LIMIT 1');
   $q->execute([$tourId,$key]);$r=$q->fetch();
   $cfg=json_decode((string)($r['config_json']??''),true)?:[];
   if(!$r||empty($cfg['custom']))throw new RuntimeException('Custom feature not found.');
   $q=$db->prepare('DELETE FROM tour_features WHERE id=? AND tour_id=?');$q->execute([(int)$r['id'],$tourId]);
   saas_audit('tour_feature.deleted','tour_feature',(int)$r['id'],json_encode(['feature'=>$key],JSON_UNESCAPED_UNICODE));
   $ok='Custom feature removed.';
  }else{
   $key=(string)($_POST['feature_key']??'');
   if(!isset($catalog[$key]))throw new RuntimeException('Invalid feature.');
   $enabled=isset($_POST['enabled'])?1:0;
   $label=trim((string)($_POST['label']??$catalog[$key]['label']));
   $description=trim((string)($_POST['description']??$catalog[$key]['description']));
   if($label===''||strlen($label)>180)throw new RuntimeException('Display label is required and must be 180 characters or less.');
   if(strlen($description)>1000)throw new RuntimeException('Description is too long.');
   $config=['label'=>$label,'description'=>$description];
   $db->beginTransaction();
   $q=$db->prepare('INSERT INTO tour_features(tour_id,feature_key,enabled,config_json) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),config_json=VALUES(config_json)');
   $q->execute([$tourId,$key,$enabled,json_encode($config,JSON_UNESCAPED_UNICODE)]);
   $db->commit();
   saas_audit('tour_feature.updated','tour_feature',null,json_encode(['feature'=>$key,'enabled'=>$enabled],JSON_UNESCAPED_UNICODE));
   $ok='Feature settings saved.';
  }
  $q=$db->prepare('SELECT * FROM tour_features WHERE tour_id=? ORDER BY id');$q->execute([$tourId]);$existing=[];foreach($q->fetchAll() as $r)$existing[$r['feature_key']]=$r;
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();$error=$e->getMessage();}
}
$customFeatures=[];
foreach($existing as $key=>$r){
 $cfg=json_decode((string)($r['config_json']??''),true)?:[];
 if(!empty($cfg['custom']))$customFeatures[]=['key'=>$key,'id'=>(int)$r['id'],'enabled'=>(int)$r['enabled'],'label'=>(string)($cfg['label']??$key),'description'=>(string)($cfg['description']??'')];
}
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Tour Features</title><style>body{font-family:system-ui;background:#f5f7fb;margin:0;color:#172033}.wrap{max-width:1050px;margin:30px auto;padding:0 18px}.box{background:#fff;border-radius:15px;padding:20px;margin:15px 0;box-shadow:0 5px 20px #0001}.feature{border:1px solid #e4e7ec;border-radius:12px;padding:16px;margin:12px 0}.row{display:flex;align-items:center;justify-content:space-between;gap:15px}.switch{display:flex;gap:8px;align-items:center;font-weight:700}.switch input{width:auto}.muted{color:#667085;font-size:13px}input,textarea,button{width:100%;box-sizing:border-box;padding:10px;border:1px solid #d0d5dd;border-radius:8px;font:inherit}textarea{min-height:70px}button{background:#155eef;color:#fff;border:0;font-weight:700;margin-top:12px}.ok{background:#ecfdf3;color:#067647;padding:10px;border-radius:8px}.err{background:#fef3f2;color:#b42318;padding:10px;border-radius:8px}.options{display:inline-block;margin-top:10px;color:#155eef;font-weight:700;text-decoration:none}.custom-add{display:grid;grid-template-columns:1fr 1.4fr auto;gap:10px;align-items:end}.custom-add label{font-size:12px;font-weight:800}.custom-add button{width:auto;margin:0;white-space:nowrap}.custom-item{border:1px solid #e4e7ec;border-radius:12px;padding:16px;margin:12px 0;background:#fff}.custom-head{display:flex;align-items:flex-start;justify-content:space-between;gap:15px}.custom-head h2{margin:0 0 4px;font-size:20px}.custom-form-actions{display:flex;gap:10px;align-items:stretch}.custom-form-actions button{flex:1}.custom-form-actions .remove{background:#fff1f0;color:#b42318;border:1px solid #fecdca}.custom-form-actions form{margin:0;display:flex;flex:0 0 120px}.custom-form-actions form button{width:100%}@media(max-width:700px){.custom-add{grid-template-columns:1fr}.custom-add button{width:100%}.custom-head{display:block}.custom-head .switch{margin-top:10px}.custom-form-actions{display:grid;grid-template-columns:1fr}.custom-form-actions form{display:flex}}</style></head><body><main class="wrap"><p><a href="dashboard.php">← Dashboard</a></p><h1>Tour Features</h1><p class="muted">Enable only the options this tour needs. Passenger selections remain isolated to this tour.</p><?php if($ok):?><div class="ok"><?=saas_h($ok)?></div><?php endif;?><?php if($error):?><div class="err"><?=saas_h($error)?></div><?php endif;?><section class="box"><h2>My custom features</h2><p class="muted">Add as many tour-specific features as you need. Each one can be enabled or disabled independently.</p><form method="post" class="custom-add"><input type="hidden" name="csrf" value="<?=saas_h(saas_csrf())?>"><input type="hidden" name="action" value="add_custom"><div><label>Feature name</label><input name="custom_label" maxlength="180" placeholder="e.g. Airport Pickup" required></div><div><label>Description</label><input name="custom_description" maxlength="1000" placeholder="Optional description"></div><button type="submit">+ Add feature</button></form><?php if(!$customFeatures):?><p class="muted" style="margin-top:14px">No custom features added yet.</p><?php else:?><?php foreach($customFeatures as $cf):?><form method="post" class="feature custom-item"><input type="hidden" name="csrf" value="<?=saas_h(saas_csrf())?>"><input type="hidden" name="action" value="update_custom"><input type="hidden" name="feature_key" value="<?=saas_h($cf['key'])?>"><div class="custom-head"><div><h2><?=saas_h($cf['label'])?></h2><p class="muted"><?=saas_h($cf['description'])?></p></div><label class="switch"><input type="checkbox" name="enabled" <?=$cf['enabled']?'checked':''?>> Enabled</label></div><label>Display label</label><input name="label" maxlength="180" value="<?=saas_h($cf['label'])?>" required><label>Description</label><textarea name="description" maxlength="1000"><?=saas_h($cf['description'])?></textarea><div class="custom-form-actions"><button type="submit">Save <?=saas_h($cf['label'])?></button><button type="button" class="remove" onclick="if(confirm('Remove this custom feature?')){this.form.action.value='delete_custom';this.form.submit();}">Remove</button></div></form><?php endforeach;?><?php endif;?></section><div class="box"><?php foreach($catalog as $key=>$c):$r=$existing[$key]??[];$cfg=json_decode((string)($r['config_json']??''),true)?:[];?><form method="post" class="feature"><input type="hidden" name="csrf" value="<?=saas_h(saas_csrf())?>"><input type="hidden" name="feature_key" value="<?=saas_h($key)?>"><div class="row"><div><h2><?=saas_h($c['label'])?></h2><p class="muted"><?=saas_h($c['description'])?></p></div><label class="switch"><input type="checkbox" name="enabled" <?=!empty($r['enabled'])?'checked':''?>> Enabled</label></div><label>Display label</label><input name="label" maxlength="180" value="<?=saas_h($cfg['label']??$c['label'])?>"><label>Description</label><textarea name="description" maxlength="1000"><?=saas_h($cfg['description']??$c['description'])?></textarea><button>Save <?=saas_h($c['label'])?></button><?php if($c['kind']==='select'):?><a class="options" href="feature_options.php?feature=<?=rawurlencode($key)?>">Manage options & prices →</a><?php endif;?></form><?php endforeach;?></div></main></body></html>