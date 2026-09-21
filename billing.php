<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
saas_require_login();
$orgId=saas_require_organization();
saas_require_permission('organization.manage');
$db=saas_db();$error='';$ok='';

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        saas_check_csrf();
        if((string)($_POST['action']??'')!=='change_plan') throw new RuntimeException('Invalid billing action.');
        $planCode=strtoupper(trim((string)($_POST['plan_code']??'FREE')));
        $cycle=strtoupper(trim((string)($_POST['billing_cycle']??'MANUAL')));
        if(!in_array($cycle,['MONTHLY','YEARLY','MANUAL'],true)) throw new RuntimeException('Invalid billing cycle.');
        $db->beginTransaction();
        $q=$db->prepare("SELECT id,plan_code,name FROM saas_plans WHERE plan_code=? AND status='ACTIVE' LIMIT 1 FOR UPDATE");
        $q->execute([$planCode]);$plan=$q->fetch();
        if(!$plan) throw new RuntimeException('Plan not found.');
        $q=$db->prepare("SELECT id,plan_id,status FROM organization_subscriptions WHERE organization_id=? LIMIT 1 FOR UPDATE");
        $q->execute([$orgId]);$sub=$q->fetch();
        if($sub){
            $q=$db->prepare("UPDATE organization_subscriptions SET plan_id=?,status='ACTIVE',billing_cycle=?,cancelled_at=NULL WHERE id=?");
            $q->execute([(int)$plan['id'],$cycle,(int)$sub['id']]);$subId=(int)$sub['id'];
        }else{
            $q=$db->prepare("INSERT INTO organization_subscriptions(organization_id,plan_id,status,billing_cycle,current_period_start) VALUES(?,?, 'ACTIVE',?,NOW())");
            $q->execute([$orgId,(int)$plan['id'],$cycle]);$subId=(int)$db->lastInsertId();
        }
        $eventKey='subscription.plan_changed';
        $payload=json_encode(['plan_code'=>$planCode,'billing_cycle'=>$cycle],JSON_UNESCAPED_UNICODE);
        $hash=hash('sha256',$payload);
        $idem='manual-'.$orgId.'-'.$subId.'-'.$hash;
        $q=$db->prepare("INSERT INTO billing_events(organization_id,subscription_id,event_key,idempotency_key,payload_hash,payload_json,processed_at) VALUES(?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE id=id");
        $q->execute([$orgId,$subId,$eventKey,$idem,$hash,$payload]);
        $db->commit();
        saas_audit('billing.plan_changed','organization_subscription',$subId,$payload);
        $ok='Plan updated successfully.';
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();$error=$e->getMessage();}
}

$plan=saas_plan($db,$orgId);$usage=saas_billing_usage($orgId);
$limits=[
 'tours'=>saas_entitlement($orgId,'max_tours',-1),
 'members'=>saas_entitlement($orgId,'max_members',-1),
 'passengers'=>saas_entitlement($orgId,'max_passengers_per_tour',-1)
];
$q=$db->prepare("SELECT id,invoice_number,status,currency,total,amount_paid,due_at,paid_at,created_at FROM billing_invoices WHERE organization_id=? ORDER BY id DESC LIMIT 20");
$q->execute([$orgId]);$invoices=$q->fetchAll();
$q=$db->query("SELECT plan_code,name,description,monthly_price,yearly_price,currency FROM saas_plans WHERE status='ACTIVE' ORDER BY sort_order,id");
$plans=$q->fetchAll();
function billing_usage_text(int $value,int $limit):string{return $limit<0?$value.' / Unlimited':$value.' / '.$limit;}
function billing_bar(int $value,int $limit):string{if($limit<0)return 'unlimited';return $limit<=0?'full':min(100,(int)round(($value/$limit)*100)).'%';}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Billing — <?=saas_h($plan['name'])?></title><link rel="stylesheet" href="assets/app.css"><style>
body{background:#f5f7fb}.billing-wrap{max-width:1120px;margin:auto;padding:28px 16px 70px}.hero,.panel{background:#fff;border:1px solid #e5e7eb;border-radius:18px;padding:22px;box-shadow:0 8px 28px #0000000a;margin-bottom:18px}.hero{display:flex;justify-content:space-between;gap:20px;align-items:center;flex-wrap:wrap}.plan-badge{display:inline-flex;padding:7px 12px;border-radius:999px;background:#fff7df;color:#8a5a00;font-weight:800}.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}.usage{border:1px solid #e5e7eb;border-radius:14px;padding:16px}.usage h3{margin:0 0 7px}.bar{height:8px;background:#edf0f2;border-radius:20px;overflow:hidden}.bar i{display:block;height:100%;background:#155eef;border-radius:20px}.plans{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}.plan{border:1px solid #e5e7eb;border-radius:14px;padding:16px}.plan.current{border:2px solid #155eef}.price{font-size:22px;font-weight:900;margin:10px 0}.muted{color:#667085;font-size:13px}.table{width:100%;border-collapse:collapse}.table th,.table td{padding:11px;border-bottom:1px solid #eef0f2;text-align:left;font-size:13px}select,button{padding:10px;border:1px solid #d0d5dd;border-radius:9px;font:inherit}button{background:#155eef;color:#fff;border-color:#155eef;font-weight:800;cursor:pointer}.alert{padding:11px;border-radius:10px;margin-bottom:16px;background:#ecfdf3;color:#067647}.danger{background:#fef3f2;color:#b42318}.formrow{display:flex;gap:10px;align-items:end;flex-wrap:wrap}.formrow label{display:grid;gap:5px;font-weight:700;font-size:13px}@media(max-width:900px){.plans{grid-template-columns:1fr 1fr}.grid{grid-template-columns:1fr 1fr}}@media(max-width:600px){.plans,.grid{grid-template-columns:1fr}.table{display:block;overflow:auto;white-space:nowrap}}</style></head><body><header class="topbar"><div><div class="eyebrow">GoTM — Organization</div><h1>Billing & Entitlements</h1><div class="sub">Central plan, subscription and usage controls</div></div><div class="nav-actions"><a class="btn ghost" href="dashboard.php">Dashboard</a></div></header>
<main class="billing-wrap"><?php if($ok):?><div class="alert"><?=saas_h($ok)?></div><?php endif;?><?php if($error):?><div class="alert danger"><?=saas_h($error)?></div><?php endif;?>
<section class="hero"><div><span class="plan-badge"><?=saas_h($plan['name'])?> plan</span><h2><?=saas_h($plan['description'])?></h2><p class="muted">Subscription status: <?=saas_h($plan['subscription_status'])?> · <?=saas_h($plan['currency'])?> <?=number_format((float)$plan['monthly_price'],2)?>/month</p></div><div><a class="btn ghost" href="team.php">Team</a></div></section>
<section class="panel"><h2>Current usage</h2><div class="grid"><?php foreach([['Tours',$usage['tours'],$limits['tours']],['Active members',$usage['members'],$limits['members']],['Passengers',$usage['passengers'],$limits['passengers']]] as $u):?><div class="usage"><h3><?=saas_h($u[0])?></h3><div class="muted"><?=saas_h(billing_usage_text((int)$u[1],(int)$u[2]))?></div><div class="bar"><i style="width:<?=saas_h(billing_bar((int)$u[1],(int)$u[2]))?>"></i></div></div><?php endforeach;?></div></section>
<section class="panel"><h2>Available plans</h2><div class="plans"><?php foreach($plans as $p):?><div class="plan <?=($p['plan_code']===$plan['plan_code']?'current':'')?>"><strong><?=saas_h($p['name'])?></strong><div class="price"><?=saas_h($p['currency'])?> <?=number_format((float)$p['monthly_price'],0)?> <small>/ month</small></div><p class="muted"><?=saas_h($p['description'])?></p></div><?php endforeach;?></div></section>
<section class="panel"><h2>Subscription administration</h2><p class="muted">This control provisions a plan for the organization. Payment-provider checkout/webhooks can later update the same subscription record without changing entitlement checks.</p><form method="post" class="formrow"><input type="hidden" name="csrf" value="<?=saas_h(saas_csrf())?>"><input type="hidden" name="action" value="change_plan"><label>Plan<select name="plan_code"><?php foreach($plans as $p):?><option value="<?=saas_h($p['plan_code'])?>" <?=($p['plan_code']===$plan['plan_code']?'selected':'')?>><?=saas_h($p['name'])?></option><?php endforeach;?></select></label><label>Billing cycle<select name="billing_cycle"><option>MONTHLY</option><option>YEARLY</option><option>MANUAL</option></select></label><button type="submit">Save subscription</button></form></section>
<section class="panel"><h2>Invoices</h2><?php if(!$invoices):?><p class="muted">No invoices yet.</p><?php else:?><table class="table"><thead><tr><th>Invoice</th><th>Status</th><th>Total</th><th>Paid</th><th>Due</th><th>Created</th></tr></thead><tbody><?php foreach($invoices as $i):?><tr><td><?=saas_h($i['invoice_number'])?></td><td><?=saas_h($i['status'])?></td><td><?=saas_h($i['currency'])?> <?=number_format((float)$i['total'],2)?></td><td><?=number_format((float)$i['amount_paid'],2)?></td><td><?=saas_h($i['due_at']?:'—')?></td><td><?=saas_h($i['created_at'])?></td></tr><?php endforeach;?></tbody></table><?php endif;?></section>
</main></body></html>