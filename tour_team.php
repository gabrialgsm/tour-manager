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
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Tour Team — <?=saas_h($tour['name']??'Tour')?></title><link rel="stylesheet" href="assets/app.css"><style>.team-wrap{max-width:1120px;margin:auto;padding:24px 16px 70px}.hero{display:flex;justify-content:space-between;gap:15px;align-items:end;flex-wrap:wrap;margin-bottom:18px}.hero h1{font-size:29px}.member-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px}.member{background:#fff;border:1px solid var(--gm-line,#e1eee6);border-radius:16px;padding:16px;box-shadow:var(--gm-shadow,0 12px 35px rgba(20,92,50,.08))}.member.assigned{border-left:4px solid #39c957}.name{font-weight:900}.meta{font-size:12px;color:#718096;margin-top:3px}.formgrid{display:grid;grid-template-columns:1fr 1fr;gap:9px;margin-top:14px}.formgrid button{grid-column:1/-1}.assigned .remove{margin-top:8px}.role{display:inline-block;padding:4px 8px;border-radius:999px;font-size:10px;font-weight:900;background:#eef6ff;color:#1d4ed8}.empty{padding:25px;text-align:center;color:#718096;background:#fff;border:1px dashed #cdd9d1;border-radius:14px}@media(max-width:700px){.member-grid{grid-template-columns:1fr}.formgrid{grid-template-columns:1fr}}</style></head><body><header class="topbar"><div><div class="eyebrow">GoTM — Tour Team</div><h1><?=saas_h($tour['name']??'Tour')?></h1><div class="sub"><?=saas_h($org['name']??'')?></div></div><div class="nav-actions"><a class="btn ghost" href="dashboard.php">Dashboard</a><a class="btn ghost" href="team.php">Organization Team</a></div></header><main class="team-wrap"><div class="hero"><div><h1>Tour team</h1><p class="muted">Assign organization members to this tour with tour-specific roles.</p></div><span class="badge"><?=count(array_filter($members,fn($m)=>!empty($m['assignment_id'])))?> assigned</span></div><?php if($ok):?><div class="alert"><?=saas_h($ok)?></div><?php endif;?><?php if($error):?><div class="alert danger"><?=saas_h($error)?></div><?php endif;?><div class="member-grid"><?php foreach($members as $m):?><article class="member <?=$m['assignment_id']?'assigned':''?>"><div class="name"><?=saas_h($m['name'])?></div><div class="meta"><?=saas_h($m['email']?:$m['username'])?><?php if($m['assignment_id']):?> · <span class="role"><?=saas_h($m['tour_role'])?></span><?php endif;?></div><form method="post"><input type="hidden" name="csrf" value="<?=saas_h(saas_csrf())?>"><input type="hidden" name="action" value="save"><input type="hidden" name="member_id" value="<?=$m['member_id']?>"><div class="formgrid"><label>Tour role<select name="role"><?php foreach(['OWNER','ADMIN','MANAGER','STAFF','VIEWER'] as $r):?><option <?=$m['tour_role']===$r?'selected':''?>><?=$r?></option><?php endforeach;?></select></label><label>Status<select name="status"><option value="ACTIVE" <?=($m['tour_status']??'ACTIVE')==='ACTIVE'?'selected':''?>>ACTIVE</option><option value="INACTIVE" <?=($m['tour_status']??'ACTIVE')==='INACTIVE'?'selected':''?>>INACTIVE</option></select></label><button class="btn primary" type="submit"><?=($m['assignment_id']?'Save assignment':'Assign to tour')?></button></div></form><?php if($m['assignment_id']&&$m['tour_role']!=='OWNER'):?><form method="post" class="remove"><input type="hidden" name="csrf" value="<?=saas_h(saas_csrf())?>"><input type="hidden" name="action" value="remove"><input type="hidden" name="action" value="remove"><input type="hidden" name="member_id" value="<?=$m['member_id']?>"><button class="btn danger-link wide" onclick="return confirm('Remove this member from this tour?')">Remove from tour</button></form><?php endif;?></article><?php endforeach;?></div></main></body></html>