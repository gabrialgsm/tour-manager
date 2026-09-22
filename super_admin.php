<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
saas_require_login();
$db=saas_db();
$uid=saas_user_id();
function super_admin_allowed(PDO $db,int $uid):bool{
    $q=$db->prepare('SELECT 1 FROM super_admins WHERE user_id=? LIMIT 1');$q->execute([$uid]);return (bool)$q->fetchColumn();
}
if(!super_admin_allowed($db,$uid)){http_response_code(403);exit('403 Forbidden — Super Admin access required.');}
$csrf=saas_csrf();$error='';$ok='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        saas_check_csrf();
        $action=(string)($_POST['action']??'');
        if($action==='org_status'){
            $id=(int)$_POST['organization_id'];$status=strtoupper(trim((string)$_POST['status']));
            if(!in_array($status,['ACTIVE','SUSPENDED','ARCHIVED'],true))throw new RuntimeException('Invalid organization status.');
            $q=$db->prepare('UPDATE organizations SET status=? WHERE id=?');$q->execute([$status,$id]);
            saas_audit('super_admin.organization_status','organization',$id,json_encode(['status'=>$status]));
            $ok='Organization status updated.';
        }elseif($action==='subscription'){
            $orgId=(int)$_POST['organization_id'];$planCode=strtoupper(trim((string)$_POST['plan_code']));
            $cycle=strtoupper(trim((string)$_POST['billing_cycle']));$end=trim((string)($_POST['period_end']??''));
            if(!in_array($cycle,['MONTHLY','YEARLY','MANUAL'],true))throw new RuntimeException('Invalid billing cycle.');
            $q=$db->prepare("SELECT id,plan_code FROM saas_plans WHERE plan_code=? AND status='ACTIVE' LIMIT 1");$q->execute([$planCode]);$plan=$q->fetch();
            if(!$plan)throw new RuntimeException('Plan not found.');
            $q=$db->prepare('SELECT id FROM organization_subscriptions WHERE organization_id=? LIMIT 1');$q->execute([$orgId]);$subId=(int)($q->fetchColumn()?:0);
            $periodEnd=$end!==''?$end.' 23:59:59':null;
            if($subId){
                $q=$db->prepare('UPDATE organization_subscriptions SET plan_id=?,status="ACTIVE",billing_cycle=?,current_period_start=COALESCE(current_period_start,NOW()),current_period_end=?,cancelled_at=NULL WHERE id=?');
                $q->execute([(int)$plan['id'],$cycle,$periodEnd,$subId]);
            }else{
                $q=$db->prepare('INSERT INTO organization_subscriptions(organization_id,plan_id,status,billing_cycle,current_period_start,current_period_end) VALUES(?,?,"ACTIVE",?,NOW(),?)');
                $q->execute([$orgId,(int)$plan['id'],$cycle,$periodEnd]);$subId=(int)$db->lastInsertId();
            }
            $payload=json_encode(['plan_code'=>$planCode,'billing_cycle'=>$cycle,'period_end'=>$periodEnd],JSON_UNESCAPED_UNICODE);
            $idem='super-admin-'.$orgId.'-'.$subId.'-'.hash('sha256',$payload.'-'.microtime(true));
            $q=$db->prepare('INSERT INTO billing_events(organization_id,subscription_id,event_key,idempotency_key,payload_hash,payload_json,processed_at) VALUES(?,?,?,?,?,?,NOW())');
            $q->execute([$orgId,$subId,'super_admin.subscription_changed',$idem,hash('sha256',$payload),$payload]);
            saas_audit('super_admin.subscription_changed','organization_subscription',$subId,$payload);
            $ok='Subscription updated.';
        }elseif($action==='subscription_status'){
            $orgId=(int)$_POST['organization_id'];$status=strtoupper(trim((string)$_POST['subscription_status']));
            if(!in_array($status,['TRIALING','ACTIVE','PAST_DUE','CANCELLED'],true))throw new RuntimeException('Invalid subscription status.');
            $q=$db->prepare('SELECT id FROM organization_subscriptions WHERE organization_id=? LIMIT 1 FOR UPDATE');$q->execute([$orgId]);$subId=(int)($q->fetchColumn()?:0);
            if(!$subId)throw new RuntimeException('Organization has no subscription.');
            if($status==='CANCELLED'){$q=$db->prepare("UPDATE organization_subscriptions SET status='CANCELLED',cancelled_at=NOW() WHERE id=?");$q->execute([$subId]);}
            else{$q=$db->prepare('UPDATE organization_subscriptions SET status=?,cancelled_at=NULL WHERE id=?');$q->execute([$status,$subId]);}
            $payload=json_encode(['status'=>$status],JSON_UNESCAPED_UNICODE);saas_audit('super_admin.subscription_status','organization_subscription',$subId,$payload);$ok='Subscription status updated.';
        }elseif($action==='user_status'){
            $id=(int)$_POST['user_id'];$status=strtoupper(trim((string)$_POST['status']));
            if(!in_array($status,['ACTIVE','INACTIVE','SUSPENDED'],true))throw new RuntimeException('Invalid user status.');
            if($id===$uid && $status!=='ACTIVE')throw new RuntimeException('You cannot disable your own account.');
            $q=$db->prepare('UPDATE users SET status=? WHERE id=?');$q->execute([$status,$id]);
            saas_audit('super_admin.user_status','user',$id,json_encode(['status'=>$status]));
            $ok='User status updated.';
        }elseif($action==='plan'){
            $id=(int)$_POST['plan_id'];$monthly=(float)$_POST['monthly_price'];$yearly=(float)$_POST['yearly_price'];
            $status=strtoupper(trim((string)$_POST['status']));
            if($monthly<0||$yearly<0||!in_array($status,['ACTIVE','INACTIVE'],true))throw new RuntimeException('Invalid plan values.');
            $q=$db->prepare('UPDATE saas_plans SET monthly_price=?,yearly_price=?,status=? WHERE id=?');$q->execute([$monthly,$yearly,$status,$id]);
            saas_audit('super_admin.plan_updated','saas_plan',$id,json_encode(['monthly_price'=>$monthly,'yearly_price'=>$yearly,'status'=>$status]));
            $ok='Plan updated.';
        }else throw new RuntimeException('Invalid action.');
    }catch(Throwable $e){$error=$e->getMessage();}
}
$stats=[];
$stats['organizations']=(int)$db->query('SELECT COUNT(*) FROM organizations')->fetchColumn();
$stats['active_orgs']=(int)$db->query("SELECT COUNT(*) FROM organizations WHERE status='ACTIVE'")->fetchColumn();
$stats['users']=(int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();
$stats['tours']=(int)$db->query('SELECT COUNT(*) FROM tours')->fetchColumn();
$stats['subscriptions']=(int)$db->query("SELECT COUNT(*) FROM organization_subscriptions WHERE status IN ('ACTIVE','TRIALING')")->fetchColumn();
$stats['mrr']=(float)$db->query("SELECT COALESCE(SUM(CASE WHEN os.status IN ('ACTIVE','TRIALING') AND os.billing_cycle='MONTHLY' THEN sp.monthly_price WHEN os.status IN ('ACTIVE','TRIALING') AND os.billing_cycle='YEARLY' THEN sp.yearly_price/12 ELSE 0 END),0) FROM organization_subscriptions os JOIN saas_plans sp ON sp.id=os.plan_id WHERE sp.currency='BDT'")->fetchColumn();
$search=trim((string)($_GET['q']??''));
if($search!==''){
 $like='%'.$search.'%';$q=$db->prepare("SELECT o.id,o.name,o.slug,o.status,o.currency,o.created_at,u.name owner_name,u.email owner_email,sp.plan_code,sp.name plan_name,os.status sub_status,os.current_period_end FROM organizations o LEFT JOIN organization_members om ON om.organization_id=o.id AND om.role='OWNER' AND om.status='ACTIVE' LEFT JOIN users u ON u.id=om.user_id LEFT JOIN organization_subscriptions os ON os.organization_id=o.id LEFT JOIN saas_plans sp ON sp.id=os.plan_id WHERE o.name LIKE ? OR o.slug LIKE ? OR u.email LIKE ? ORDER BY o.id DESC LIMIT 100");$q->execute([$like,$like,$like]);
}else{$q=$db->query("SELECT o.id,o.name,o.slug,o.status,o.currency,o.created_at,u.name owner_name,u.email owner_email,sp.plan_code,sp.name plan_name,os.status sub_status,os.current_period_end FROM organizations o LEFT JOIN organization_members om ON om.organization_id=o.id AND om.role='OWNER' AND om.status='ACTIVE' LEFT JOIN users u ON u.id=om.user_id LEFT JOIN organization_subscriptions os ON os.organization_id=o.id LEFT JOIN saas_plans sp ON sp.id=os.plan_id ORDER BY o.id DESC LIMIT 100");}
$orgs=$q->fetchAll();
$users=$db->query('SELECT id,name,username,email,status,created_at FROM users ORDER BY id DESC LIMIT 100')->fetchAll();
$plans=$db->query('SELECT id,plan_code,name,monthly_price,yearly_price,currency,status,sort_order FROM saas_plans ORDER BY sort_order,id')->fetchAll();
$me=saas_current_user();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>GoTM — Super Admin</title><link rel="stylesheet" href="assets/app.css"><style>
body{background:#f4f7fb;color:#172033}.wrap{max-width:1280px;margin:auto;padding:24px 16px 70px}.top{display:flex;justify-content:space-between;align-items:center;gap:15px;flex-wrap:wrap;margin-bottom:18px}.cards{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:18px}.card,.panel{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:18px;box-shadow:0 6px 24px #00000008}.num{font-size:26px;font-weight:900;margin-top:5px}.muted{color:#667085;font-size:13px}.tabs{display:flex;gap:8px;flex-wrap:wrap;margin:18px 0}.tabs a{padding:9px 13px;background:#fff;border:1px solid #e5e7eb;border-radius:9px;text-decoration:none;color:#172033}.table-wrap{overflow:auto}.table{width:100%;border-collapse:collapse}.table th,.table td{padding:10px;border-bottom:1px solid #eef0f2;text-align:left;font-size:13px;vertical-align:top}.pill{display:inline-block;padding:4px 8px;border-radius:999px;background:#eef2ff;font-size:11px;font-weight:800}.pill.warn{background:#fff7df;color:#8a5a00}.pill.bad{background:#fef3f2;color:#b42318}.form-inline{display:flex;gap:6px;align-items:center;flex-wrap:wrap}.form-inline select,.form-inline input{padding:7px;border:1px solid #d0d5dd;border-radius:7px}.btnx{padding:7px 10px;border:1px solid #d0d5dd;border-radius:7px;background:#fff;cursor:pointer;font-weight:700}.btnx.primary{background:#155eef;color:#fff;border-color:#155eef}.btnx.danger{background:#b42318;color:#fff;border-color:#b42318}.alert{padding:11px;border-radius:10px;margin-bottom:15px}.ok{background:#ecfdf3;color:#067647}.err{background:#fef3f2;color:#b42318}.search{display:flex;gap:8px;margin-bottom:12px}.search input{flex:1;padding:10px;border:1px solid #d0d5dd;border-radius:9px}@media(max-width:1050px){.cards{grid-template-columns:repeat(3,1fr)}}@media(max-width:650px){.cards{grid-template-columns:1fr 1fr}.table{min-width:900px}}
</style></head><body><main class="wrap">
<header class="top"><div><div class="eyebrow">GOTM SYSTEM ADMINISTRATION</div><h1>Super Admin Console</h1><p class="muted">Signed in as <?=saas_h($me['name']??'')?> · platform-level controls</p></div><div class="form-inline"><a class="btnx" href="dashboard.php">App Dashboard</a><a class="btnx" href="logout.php">Logout</a></div></header>
<?php if($ok):?><div class="alert ok"><?=saas_h($ok)?></div><?php endif;?><?php if($error):?><div class="alert err"><?=saas_h($error)?></div><?php endif;?>
<section class="cards"><div class="card">MRR (BDT)<div class="num"><?=number_format($stats['mrr'],0)?></div></div><div class="card">Organizations<div class="num"><?=$stats['organizations']?></div></div><div class="card">Active Organizations<div class="num"><?=$stats['active_orgs']?></div></div><div class="card">Users<div class="num"><?=$stats['users']?></div></div><div class="card">Tours<div class="num"><?=$stats['tours']?></div></div><div class="card">Active Subscriptions<div class="num"><?=$stats['subscriptions']?></div></div></section>
<nav class="tabs"><a href="#organizations">Organizations</a><a href="#users">Users</a><a href="#subscriptions">Subscriptions</a><a href="#plans">Plans</a><a href="super_admin_plans.php">Entitlements</a><a href="audit_log.php">Audit Log</a></nav>
<section class="panel" id="organizations"><h2>Organizations</h2><form class="search" method="get"><input name="q" value="<?=saas_h($search)?>" placeholder="Search organization, slug or owner email"><button class="btnx primary">Search</button></form><div class="table-wrap"><table class="table"><thead><tr><th>Organization</th><th>Owner</th><th>Status</th><th>Plan</th><th>Period End</th><th>Actions</th></tr></thead><tbody>
<?php foreach($orgs as $o):?><tr><td><b><a href="super_admin_organization.php?id=<?=$o['id']?>"><?=saas_h($o['name'])?></a></b><br><span class="muted">#<?=$o['id']?> · <?=saas_h($o['slug'])?></span></td><td><?=saas_h($o['owner_name']??'—')?><br><span class="muted"><?=saas_h($o['owner_email']??'')?></span></td><td><span class="pill <?=$o['status']==='SUSPENDED'?'warn':($o['status']==='ARCHIVED'?'bad':'')?>"><?=saas_h($o['status'])?></span></td><td><?=saas_h($o['plan_name']??'No plan')?><br><span class="muted"><?=saas_h($o['sub_status']??'—')?></span></td><td><?=saas_h($o['current_period_end']??'—')?></td><td><form method="post" class="form-inline"><input type="hidden" name="csrf" value="<?=saas_h($csrf)?>"><input type="hidden" name="action" value="org_status"><input type="hidden" name="organization_id" value="<?=$o['id']?>"><select name="status"><option>ACTIVE</option><option>SUSPENDED</option><option>ARCHIVED</option></select><button class="btnx">Save</button></form><form method="post" class="form-inline" style="margin-top:6px"><input type="hidden" name="csrf" value="<?=saas_h($csrf)?>"><input type="hidden" name="action" value="subscription"><input type="hidden" name="organization_id" value="<?=$o['id']?>"><select name="plan_code"><?php foreach($plans as $p):?><option value="<?=saas_h($p['plan_code'])?>" <?=$p['plan_code']===$o['plan_code']?'selected':''?>><?=saas_h($p['name'])?></option><?php endforeach;?></select><select name="billing_cycle"><option>MANUAL</option><option>MONTHLY</option><option>YEARLY</option></select><input type="date" name="period_end" value="<?=!empty($o['current_period_end'])?saas_h(substr($o['current_period_end'],0,10)):''?>"><button class="btnx primary">Set Plan</button></form><form method="post" class="form-inline" style="margin-top:6px"><input type="hidden" name="csrf" value="<?=saas_h($csrf)?>"><input type="hidden" name="action" value="subscription_status"><input type="hidden" name="organization_id" value="<?=$o['id']?>"><select name="subscription_status"><option>ACTIVE</option><option>TRIALING</option><option>PAST_DUE</option><option>CANCELLED</option></select><button class="btnx">Status</button></form></td></tr><?php endforeach;?>
</tbody></table></div></section>
<section class="panel" id="users"><h2>Users</h2><div class="table-wrap"><table class="table"><thead><tr><th>ID</th><th>User</th><th>Email</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead><tbody><?php foreach($users as $u):?><tr><td>#<?=$u['id']?></td><td><b><?=saas_h($u['name'])?></b><br><span class="muted"><?=saas_h($u['username']??'')?></span></td><td><?=saas_h($u['email']??'')?></td><td><?=saas_h($u['status'])?></td><td><?=saas_h($u['created_at'])?></td><td><form method="post" class="form-inline"><input type="hidden" name="csrf" value="<?=saas_h($csrf)?>"><input type="hidden" name="action" value="user_status"><input type="hidden" name="user_id" value="<?=$u['id']?>"><select name="status"><option>ACTIVE</option><option>INACTIVE</option><option>SUSPENDED</option></select><button class="btnx">Save</button></form></td></tr><?php endforeach;?></tbody></table></div></section>
<section class="panel" id="plans"><h2>Plan Catalog</h2><div class="table-wrap"><table class="table"><thead><tr><th>Plan</th><th>Monthly</th><th>Yearly</th><th>Status</th><th>Save</th></tr></thead><tbody><?php foreach($plans as $p):?><tr><form method="post"><td><b><?=saas_h($p['name'])?></b><br><span class="muted"><?=saas_h($p['plan_code'])?></span><input type="hidden" name="csrf" value="<?=saas_h($csrf)?>"><input type="hidden" name="action" value="plan"><input type="hidden" name="plan_id" value="<?=$p['id']?>"></td><td><input type="number" step="0.01" min="0" name="monthly_price" value="<?=saas_h($p['monthly_price'])?>"></td><td><input type="number" step="0.01" min="0" name="yearly_price" value="<?=saas_h($p['yearly_price'])?>"></td><td><select name="status"><option <?=$p['status']==='ACTIVE'?'selected':''?>>ACTIVE</option><option <?=$p['status']==='INACTIVE'?'selected':''?>>INACTIVE</option></select></td><td><button class="btnx primary">Save</button></td></form></tr><?php endforeach;?></tbody></table></div><p class="muted">Entitlement limits remain in <code>saas_plan_entitlements</code>; this console currently changes plan availability and pricing plus organization assignments.</p></section>
</main></body></html>