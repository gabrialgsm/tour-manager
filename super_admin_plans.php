<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
saas_require_login();$db=saas_db();$uid=saas_user_id();
$q=$db->prepare('SELECT 1 FROM super_admins WHERE user_id=? LIMIT 1');$q->execute([$uid]);
if(!$q->fetchColumn()){http_response_code(403);exit('403 Forbidden — Super Admin access required.');}
$csrf=saas_csrf();$error='';$ok='';
if($_SERVER['REQUEST_METHOD']==='POST'){try{
 saas_check_csrf();$action=(string)($_POST['action']??'');
 if($action!=='entitlement')throw new RuntimeException('Invalid action.');
 $planId=(int)$_POST['plan_id'];$key=trim((string)$_POST['entitlement_key']);$value=trim((string)$_POST['entitlement_value']);
 if($planId<1||$key===''||!preg_match('/^[a-z0-9_]{2,100}$/',$key)||strlen($value)>255)throw new RuntimeException('Invalid entitlement.');
 $q=$db->prepare('SELECT name FROM saas_plans WHERE id=? LIMIT 1');$q->execute([$planId]);$plan=$q->fetch();if(!$plan)throw new RuntimeException('Plan not found.');
 $q=$db->prepare('INSERT INTO saas_plan_entitlements(plan_id,entitlement_key,entitlement_value) VALUES(?,?,?) ON DUPLICATE KEY UPDATE entitlement_value=VALUES(entitlement_value)');
 $q->execute([$planId,$key,$value]);saas_audit('super_admin.entitlement_updated','saas_plan',$planId,json_encode(['key'=>$key,'value'=>$value],JSON_UNESCAPED_UNICODE));$ok='Entitlement saved.';
}catch(Throwable $e){$error=$e->getMessage();}}
$plans=$db->query('SELECT id,plan_code,name,status FROM saas_plans ORDER BY sort_order,id')->fetchAll();
$selected=(int)($_GET['plan_id']??($plans[0]['id']??0));
$q=$db->prepare('SELECT entitlement_key,entitlement_value FROM saas_plan_entitlements WHERE plan_id=? ORDER BY entitlement_key');$q->execute([$selected]);$ents=$q->fetchAll();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>GoTM — Plan Entitlements</title><link rel="stylesheet" href="assets/app.css"><style>
body{background:#f5f7fb}.wrap{max-width:1050px;margin:auto;padding:26px 16px 70px}.panel{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:20px;margin-bottom:16px}.nav{display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap}.nav a{padding:8px 11px;border:1px solid #d0d5dd;border-radius:8px;background:#fff;text-decoration:none}.tabs{display:flex;gap:8px;flex-wrap:wrap}.tabs a{padding:8px 12px;border-radius:999px;border:1px solid #d0d5dd;text-decoration:none}.tabs a.active{background:#155eef;color:#fff}.table{width:100%;border-collapse:collapse}.table th,.table td{padding:10px;border-bottom:1px solid #eef0f2;text-align:left}.form{display:flex;gap:8px;flex-wrap:wrap}.form input{padding:9px;border:1px solid #d0d5dd;border-radius:8px}.btn{padding:9px 12px;border:1px solid #155eef;border-radius:8px;background:#155eef;color:#fff;font-weight:700}.muted{color:#667085;font-size:13px}.alert{padding:10px;border-radius:9px;margin-bottom:14px}.ok{background:#ecfdf3;color:#067647}.err{background:#fef3f2;color:#b42318}</style></head><body><main class="wrap">
<div class="nav"><a href="super_admin.php">← Super Admin</a><a href="super_admin_organization.php">Organization</a></div>
<section class="panel"><div class="eyebrow">SAAS CONFIGURATION</div><h1>Plan Entitlements</h1><p class="muted">Control limits and feature flags used by the centralized entitlement service.</p>
<?php if($ok):?><div class="alert ok"><?=saas_h($ok)?></div><?php endif;?><?php if($error):?><div class="alert err"><?=saas_h($error)?></div><?php endif;?>
<div class="tabs"><?php foreach($plans as $p):?><a class="<?=$p['id']===$selected?'active':''?>" href="?plan_id=<?=$p['id']?>"><?=saas_h($p['name'])?></a><?php endforeach;?></div></section>
<section class="panel"><h2><?=saas_h($plans[array_search($selected,array_column($plans,'id'))]['name']??'Plan')?> entitlements</h2><table class="table"><thead><tr><th>Key</th><th>Value</th><th>Save</th></tr></thead><tbody><?php foreach($ents as $e):?><tr><form method="post"><td><code><?=saas_h($e['entitlement_key'])?></code><input type="hidden" name="csrf" value="<?=saas_h($csrf)?>"><input type="hidden" name="action" value="entitlement"><input type="hidden" name="plan_id" value="<?=$selected?>"><input type="hidden" name="entitlement_key" value="<?=saas_h($e['entitlement_key'])?>"></td><td><input name="entitlement_value" value="<?=saas_h($e['entitlement_value'])?>" maxlength="255"></td><td><button class="btn">Save</button></td></form></tr><?php endforeach;?></tbody></table></section>
<section class="panel"><h2>Add / override entitlement</h2><form method="post" class="form"><input type="hidden" name="csrf" value="<?=saas_h($csrf)?>"><input type="hidden" name="action" value="entitlement"><input type="hidden" name="plan_id" value="<?=$selected?>"><input name="entitlement_key" placeholder="max_tours" pattern="[a-z0-9_]{2,100}" required><input name="entitlement_value" placeholder="50" maxlength="255" required><button class="btn">Save entitlement</button></form></section>
</main></body></html>