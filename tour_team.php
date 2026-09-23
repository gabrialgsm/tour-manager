<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
saas_require_login();
$tourId = saas_require_tour();
saas_require_permission('member.manage');
$orgId = saas_current_organization_id();
$db = saas_db();
$error=''; $ok='';

if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        saas_check_csrf();
        $action=(string)($_POST['action']??'save');
        $memberId=(int)($_POST['member_id']??0);
        if($memberId<=0) throw new RuntimeException('Invalid team member.');

        $db->beginTransaction();
        $q=$db->prepare("SELECT om.id,om.user_id,u.name FROM organization_members om JOIN users u ON u.id=om.user_id WHERE om.id=? AND om.organization_id=? AND om.status='ACTIVE' LIMIT 1 FOR UPDATE");
        $q->execute([$memberId,$orgId]); $member=$q->fetch();
        if(!$member) throw new RuntimeException('Select an active organization member.');

        if($action==='remove') {
            $q=$db->prepare("SELECT id,user_id,role FROM tour_members WHERE tour_id=? AND user_id=? LIMIT 1 FOR UPDATE");
            $q->execute([$tourId,(int)$member['user_id']]);
            $tm=$q->fetch();
            if(!$tm) throw new RuntimeException('Tour assignment not found.');
            if($tm['role']==='OWNER') throw new RuntimeException('The tour owner cannot be removed.');
            $q=$db->prepare("DELETE FROM tour_members WHERE id=? AND tour_id=?");
            $q->execute([(int)$tm['id'],$tourId]);
            $db->commit();
            saas_audit('tour.member_removed','tour_member',(int)$tm['id'],json_encode(['tour_id'=>$tourId,'user_id'=>(int)$member['user_id']],JSON_UNESCAPED_UNICODE));
            $ok='Tour team assignment removed.';
        } else {
            $role=strtoupper(trim((string)($_POST['role']??'STAFF')));
            $status=strtoupper(trim((string)($_POST['status']??'ACTIVE')));
            if(!in_array($role,['OWNER','ADMIN','MANAGER','STAFF','VIEWER'],true) || !in_array($status,['ACTIVE','INACTIVE'],true)) throw new RuntimeException('Invalid tour team update.');
            $q=$db->prepare("SELECT * FROM tour_members WHERE tour_id=? AND user_id=? LIMIT 1 FOR UPDATE");
            $q->execute([$tourId,(int)$member['user_id']]); $existing=$q->fetch();
            if($role==='OWNER' && (!$existing || $existing['role']!=='OWNER')){
                $ownerQ=$db->prepare("SELECT id FROM tour_members WHERE tour_id=? AND role='OWNER' LIMIT 1 FOR UPDATE");
                $ownerQ->execute([$tourId]);
                if($ownerQ->fetch()) throw new RuntimeException('This tour already has an owner. Change the existing owner before assigning OWNER.');
            }
            if($existing && $existing['role']==='OWNER' && $role!=='OWNER') throw new RuntimeException('Transfer the tour owner role before changing it.');
            if($existing && $existing['role']==='OWNER' && $status!=='ACTIVE') throw new RuntimeException('The tour owner must remain active.');
            if(!$existing) {
                $q=$db->prepare("INSERT INTO tour_members(tour_id,user_id,role,status) VALUES(?,?,?,?)");
                $q->execute([$tourId,(int)$member['user_id'],$role,$status]);
                $assignmentId=(int)$db->lastInsertId();
            } else {
                $assignmentId=(int)$existing['id'];
                $q=$db->prepare("UPDATE tour_members SET role=?,status=? WHERE id=? AND tour_id=?");
                $q->execute([$role,$status,$assignmentId,$tourId]);
            }
            $db->commit();
            saas_audit('tour.member_updated','tour_member',$assignmentId,json_encode(['tour_id'=>$tourId,'user_id'=>(int)$member['user_id'],'role'=>$role,'status'=>$status],JSON_UNESCAPED_UNICODE));
            $ok='Tour team assignment saved.';
        }
    } catch(Throwable $e){if($db->inTransaction())$db->rollBack();$error=$e->getMessage();}
}

$q=$db->prepare("SELECT om.id member_id,om.user_id,u.name,u.username,u.email,tm.id assignment_id,tm.role tour_role,tm.status tour_status FROM organization_members om JOIN users u ON u.id=om.user_id LEFT JOIN tour_members tm ON tm.user_id=om.user_id AND tm.tour_id=? WHERE om.organization_id=? AND om.status='ACTIVE' ORDER BY CASE WHEN tm.id IS NULL THEN 2 ELSE 1 END,u.name");
$q->execute([$tourId,$orgId]);$members=$q->fetchAll();$tour=saas_current_tour();$org=saas_current_organization();
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Tour Team — <?=saas_h($tour['name']??'Tour')?></title>
<link rel="stylesheet" href="assets/app.css">
<style>
.team-wrap{max-width:1180px;margin:auto;padding:24px 16px 70px}.hero{display:flex;justify-content:space-between;align-items:flex-end;gap:18px;flex-wrap:wrap;margin-bottom:18px}.hero h1{margin:0;font-size:30px}.hero p{margin:6px 0 0}.tour-badge{padding:8px 12px;border-radius:999px;background:#edfdf3;color:#067647;font-weight:800;font-size:12px}
.team-layout{display:grid;grid-template-columns:340px 1fr;gap:18px}.panel{background:#fff;border:1px solid #e4e7ec;border-radius:18px;padding:18px;box-shadow:0 10px 28px rgba(16,24,40,.06)}.panel h2{margin:0 0 5px}.panel-sub{font-size:12px;color:#667085;line-height:1.5;margin-bottom:15px}
.member-card{border:1px solid #e4e7ec;border-radius:15px;padding:15px;margin-top:10px;background:#fff}.member-card.assigned{border-left:4px solid #12b76a}.member-card.available{background:#fbfcfe}.member-head{display:flex;justify-content:space-between;gap:12px}.member-name{font-weight:900;font-size:16px}.meta{font-size:12px;color:#667085;margin-top:3px;word-break:break-word}.role-pill{display:inline-flex;align-items:center;padding:4px 8px;border-radius:999px;font-size:10px;font-weight:900;background:#eef4ff;color:#175cd3}.role-OWNER{background:#fff6e5;color:#b54708}.role-MANAGER{background:#ecfdf3;color:#027a48}.role-STAFF{background:#f2f4f7;color:#344054}.role-VIEWER{background:#f4f3ff;color:#5925dc}.status-pill{font-size:10px;font-weight:800;color:#067647}.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:9px;margin-top:13px}.form-grid button{grid-column:1/-1}.actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}.btn-small{padding:8px 11px!important;font-size:12px!important}.info{margin-top:15px;padding:12px;border-radius:12px;background:#f5f8fc;color:#475467;font-size:11px;line-height:1.55}.info strong{color:#172033}.section-head{display:flex;justify-content:space-between;align-items:center;gap:10px}.count{font-size:11px;color:#667085}.empty{padding:20px;text-align:center;color:#667085;border:1px dashed #d0d5dd;border-radius:13px;margin-top:10px}.danger-link{color:#b42318!important;background:#fff1f0!important;border-color:#fecdca!important}
@media(max-width:900px){.team-layout{grid-template-columns:1fr}.form-grid{grid-template-columns:1fr}.form-grid button{grid-column:auto}}
</style></head>
<body>
<header class="topbar"><div><div class="eyebrow">GoTM — Tour Team</div><h1><?=saas_h($tour['name']??'Tour')?></h1><div class="sub"><?=saas_h($org['name']??'')?></div></div><div class="nav-actions"><a class="btn ghost" href="dashboard.php">Dashboard</a><a class="btn ghost" href="team.php">Organization Team</a></div></header>
<main class="team-wrap">
<div class="hero"><div><h1>Tour team</h1><p class="muted">Choose people from your organization and give them a role for this tour.</p></div><span class="tour-badge"><?=count(array_filter($members,fn($m)=>!empty($m['assignment_id'])))?> assigned</span></div>
<?php if($ok):?><div class="alert"><?=saas_h($ok)?></div><?php endif;?><?php if($error):?><div class="alert danger"><?=saas_h($error)?></div><?php endif;?>
<div class="team-layout">
<section class="panel">
<div class="section-head"><div><h2>Available members</h2><div class="panel-sub">These people are in your organization but are not assigned to this tour yet.</div></div><span class="count"><?=count(array_filter($members,fn($m)=>empty($m['assignment_id'])))?></span></div>
<?php $available=array_filter($members,fn($m)=>empty($m['assignment_id'])); if(!$available):?><div class="empty">Everyone in the organization is already assigned to this tour.</div><?php endif;?>
<?php foreach($available as $m):?><article class="member-card available"><div class="member-head"><div><div class="member-name"><?=saas_h($m['name'])?></div><div class="meta"><?=saas_h($m['email']?:$m['username'])?></div></div><span class="role-pill"><?=saas_h($m['role'])?></span></div>
<form method="post"><input type="hidden" name="csrf" value="<?=saas_h(saas_csrf())?>"><input type="hidden" name="action" value="save"><input type="hidden" name="member_id" value="<?=$m['member_id']?>"><div class="form-grid"><label>Tour role<select name="role"><?php foreach(['ADMIN','MANAGER','STAFF','VIEWER'] as $r):?><option value="<?=$r?>" <?=$r==='STAFF'?'selected':''?>><?=$r?></option><?php endforeach;?></select></label><label>Status<select name="status"><option value="ACTIVE" selected>ACTIVE</option><option value="INACTIVE">INACTIVE</option></select></label><button class="btn primary wide" type="submit">＋ Assign to this tour</button></div></form></article><?php endforeach;?>
<div class="info"><strong>How it works:</strong> Organization membership is separate from tour membership. A person can be a STAFF in the organization but a MANAGER on this particular tour.</div>
</section>
<section class="panel">
<div class="section-head"><div><h2>Assigned to this tour</h2><div class="panel-sub">These members can access the tour according to their tour role and permissions.</div></div><span class="count"><?=count(array_filter($members,fn($m)=>!empty($m['assignment_id'])))?> assigned</span></div>
<?php $assigned=array_filter($members,fn($m)=>!empty($m['assignment_id'])); if(!$assigned):?><div class="empty">No team members assigned yet.</div><?php endif;?>
<?php foreach($assigned as $m):?><article class="member-card assigned"><div class="member-head"><div><div class="member-name"><?=saas_h($m['name'])?></div><div class="meta"><?=saas_h($m['email']?:$m['username'])?></div></div><div><span class="role-pill role-<?=saas_h($m['tour_role'])?>"><?=saas_h($m['tour_role'])?></span> <span class="status-pill">● <?=saas_h($m['tour_status'])?></span></div></div>
<form method="post"><input type="hidden" name="csrf" value="<?=saas_h(saas_csrf())?>"><input type="hidden" name="action" value="save"><input type="hidden" name="member_id" value="<?=$m['member_id']?>"><div class="form-grid"><label>Tour role<select name="role"><?php foreach(['OWNER','ADMIN','MANAGER','STAFF','VIEWER'] as $r):?><option value="<?=$r?>" <?=$m['tour_role']===$r?'selected':''?>><?=$r?></option><?php endforeach;?></select></label><label>Status<select name="status"><option value="ACTIVE" <?=($m['tour_status']??'ACTIVE')==='ACTIVE'?'selected':''?>>ACTIVE</option><option value="INACTIVE" <?=($m['tour_status']??'ACTIVE')==='INACTIVE'?'selected':''?>>INACTIVE</option></select></label><button class="btn primary wide" type="submit">Save assignment</button></div></form>
<?php if($m['tour_role']!=='OWNER'):?><div class="actions"><form method="post"><input type="hidden" name="csrf" value="<?=saas_h(saas_csrf())?>"><input type="hidden" name="action" value="remove"><input type="hidden" name="member_id" value="<?=$m['member_id']?>"><button class="btn btn-small danger-link" onclick="return confirm('Remove this member from this tour?')">Remove from tour</button></form></div><?php endif;?>
</article><?php endforeach;?>
<div class="info"><strong>Roles:</strong> OWNER = tour owner · ADMIN = full tour administration · MANAGER = day-to-day management · STAFF = operational work · VIEWER = view-focused access. The exact actions still follow GoTM permissions.</div>
</section>
</div></main></body></html>