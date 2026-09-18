<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap_saas.php';
saas_require_login();
$orgId = saas_require_organization();
saas_require_permission('member.manage');

$db = saas_db();
$error = '';
$ok = '';

function team_role_options(): array {
    return ['OWNER','ADMIN','MANAGER','STAFF'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        saas_check_csrf();
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'add') {
            $identity = trim((string)($_POST['identity'] ?? ''));
            $role = strtoupper(trim((string)($_POST['role'] ?? 'STAFF')));
            if ($identity === '') throw new RuntimeException('Enter a username or email.');
            if (!in_array($role, ['ADMIN','MANAGER','STAFF'], true)) throw new RuntimeException('New members cannot be added directly as OWNER.');
            $db->beginTransaction();
            $q = $db->prepare("SELECT id,name,username,email,status FROM users WHERE (username=? OR email=?) LIMIT 1 FOR UPDATE");
            $q->execute([$identity, $identity]);
            $user = $q->fetch();
            if (!$user || $user['status'] !== 'ACTIVE') throw new RuntimeException('Active GoTM user not found. Ask the person to create an account first.');
            $q = $db->prepare("SELECT id,status FROM organization_members WHERE organization_id=? AND user_id=? LIMIT 1 FOR UPDATE");
            $q->execute([$orgId, (int)$user['id']]);
            $existing = $q->fetch();
            if ($existing) {
                if ($existing['status'] === 'ACTIVE') throw new RuntimeException('This user is already a team member.');
                $q = $db->prepare("UPDATE organization_members SET role=?,status='ACTIVE' WHERE id=?");
                $q->execute([$role, (int)$existing['id']]);
                $memberId = (int)$existing['id'];
                $event = 'organization.member_reactivated';
            } else {
                $q = $db->prepare("INSERT INTO organization_members (organization_id,user_id,role,status) VALUES (?,?,?,'ACTIVE')");
                $q->execute([$orgId, (int)$user['id'], $role]);
                $memberId = (int)$db->lastInsertId();
                $event = 'organization.member_added';
            }
            $db->commit();
            saas_audit($event, 'organization_member', $memberId, json_encode(['user_id'=>(int)$user['id'],'role'=>$role], JSON_UNESCAPED_UNICODE));
            $ok = 'Team member added.';
        } elseif ($action === 'update') {
            $memberId = (int)($_POST['member_id'] ?? 0);
            $role = strtoupper(trim((string)($_POST['role'] ?? 'STAFF')));
            $status = strtoupper(trim((string)($_POST['status'] ?? 'ACTIVE')));
            if ($memberId <= 0 || !in_array($role, team_role_options(), true) || !in_array($status, ['ACTIVE','INACTIVE'], true)) throw new RuntimeException('Invalid team member update.');
            $db->beginTransaction();
            $q = $db->prepare("SELECT om.*,u.name,u.username FROM organization_members om JOIN users u ON u.id=om.user_id WHERE om.id=? AND om.organization_id=? LIMIT 1 FOR UPDATE");
            $q->execute([$memberId, $orgId]);
            $member = $q->fetch();
            if (!$member) throw new RuntimeException('Team member not found.');
            if ($member['role'] === 'OWNER' && $role !== 'OWNER') throw new RuntimeException('Transfer ownership before changing the owner role.');
            if ($member['role'] === 'OWNER' && $status !== 'ACTIVE') throw new RuntimeException('The owner must remain active.');
            if ($role === 'OWNER' && $member['role'] !== 'OWNER') throw new RuntimeException('Use Transfer ownership for an ownership change.');
            $q = $db->prepare("UPDATE organization_members SET role=?,status=? WHERE id=? AND organization_id=?");
            $q->execute([$role, $status, $memberId, $orgId]);
            if ($status === 'INACTIVE') {
                $q = $db->prepare("DELETE tm FROM tour_members tm JOIN tours t ON t.id=tm.tour_id WHERE tm.user_id=? AND t.organization_id=?");
                $q->execute([(int)$member['user_id'], $orgId]);
            }
            $db->commit();
            saas_audit('organization.member_status_changed', 'organization_member', $memberId, json_encode(['user_id'=>(int)$member['user_id'],'status'=>$status], JSON_UNESCAPED_UNICODE));
            $ok = 'Member status updated.';
        } elseif ($action === 'transfer') {
            $targetId = (int)($_POST['member_id'] ?? 0);
            if ($targetId <= 0) throw new RuntimeException('Select a team member.');
            $db->beginTransaction();
            $q = $db->prepare("SELECT om.*,u.name,u.username FROM organization_members om JOIN users u ON u.id=om.user_id WHERE om.id=? AND om.organization_id=? AND om.status='ACTIVE' LIMIT 1 FOR UPDATE");
            $q->execute([$targetId, $orgId]);
            $target = $q->fetch();
            if (!$target) throw new RuntimeException('Active team member not found.');
            $q = $db->prepare("SELECT id,user_id FROM organization_members WHERE organization_id=? AND role='OWNER' AND status='ACTIVE' ORDER BY id LIMIT 1 FOR UPDATE");
            $q->execute([$orgId]);
            $owner = $q->fetch();
            if (!$owner) throw new RuntimeException('Organization owner record is missing.');
            if ((int)$owner['id'] === $targetId) throw new RuntimeException('That member is already the owner.');
            $q = $db->prepare("UPDATE organization_members SET role='ADMIN' WHERE id=? AND organization_id=?");
            $q->execute([(int)$owner['id'], $orgId]);
            $q = $db->prepare("UPDATE organization_members SET role='OWNER',status='ACTIVE' WHERE id=? AND organization_id=?");
            $q->execute([$targetId, $orgId]);
            $db->commit();
            saas_audit('organization.owner_transferred', 'organization_member', $targetId, json_encode(['from_user_id'=>(int)$owner['user_id'],'to_user_id'=>(int)$target['user_id']], JSON_UNESCAPED_UNICODE));
            $ok = 'Organization ownership transferred.';
        } elseif ($action === 'remove') {
            $memberId = (int)($_POST['member_id'] ?? 0);
            if ($memberId <= 0) throw new RuntimeException('Invalid member.');
            $db->beginTransaction();
            $q = $db->prepare("SELECT * FROM organization_members WHERE id=? AND organization_id=? LIMIT 1 FOR UPDATE");
            $q->execute([$memberId, $orgId]);
            $member = $q->fetch();
            if (!$member) throw new RuntimeException('Team member not found.');
            if ((int)$member['user_id'] === saas_user_id()) throw new RuntimeException('You cannot remove your own account from this organization.');
            if ($member['role'] === 'OWNER') throw new RuntimeException('Transfer ownership before removing the owner.');
            $q = $db->prepare("DELETE tm FROM tour_members tm JOIN tours t ON t.id=tm.tour_id WHERE tm.user_id=? AND t.organization_id=?");
            $q->execute([(int)$member['user_id'], $orgId]);
            $q = $db->prepare("DELETE FROM organization_members WHERE id=? AND organization_id=?");
            $q->execute([$memberId, $orgId]);
            $db->commit();
            saas_audit('organization.member_removed', 'organization_member', $memberId, json_encode(['user_id'=>(int)$member['user_id']], JSON_UNESCAPED_UNICODE));
            $ok = 'Team member removed.';
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error = $e->getMessage();
    }
}

$q = $db->prepare("SELECT om.id,om.user_id,om.role,om.status,om.created_at,u.name,u.username,u.email,COUNT(DISTINCT tm.id) tour_count
                   FROM organization_members om
                   JOIN users u ON u.id=om.user_id
                   LEFT JOIN tour_members tm ON tm.user_id=om.user_id AND tm.status='ACTIVE'
                   LEFT JOIN tours t ON t.id=tm.tour_id AND t.organization_id=om.organization_id
                   WHERE om.organization_id=?
                   GROUP BY om.id,om.user_id,om.role,om.status,om.created_at,u.name,u.username,u.email
                   ORDER BY CASE om.role WHEN 'OWNER' THEN 1 WHEN 'ADMIN' THEN 2 WHEN 'MANAGER' THEN 3 ELSE 4 END,u.name");
$q->execute([$orgId]);
$members = $q->fetchAll();
$org = saas_current_organization();
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Team — <?=saas_h($org['name']??'GoTM')?></title><link rel="stylesheet" href="assets/app.css">
<style>.team-wrap{max-width:1180px;margin:auto;padding:24px 16px 70px}.team-hero{display:flex;justify-content:space-between;gap:18px;align-items:end;flex-wrap:wrap;margin-bottom:18px}.team-hero h1{font-size:30px}.team-grid{display:grid;grid-template-columns:360px 1fr;gap:18px}.panel{background:#fff;border:1px solid var(--gm-line,#e1eee6);border-radius:18px;padding:18px;box-shadow:var(--gm-shadow,0 12px 35px rgba(20,92,50,.08))}.member{border:1px solid #e5ece8;border-radius:14px;padding:14px;margin:10px 0}.member-head{display:flex;justify-content:space-between;gap:10px;align-items:start}.member-name{font-weight:900}.meta{font-size:12px;color:#718096;margin-top:3px}.member-actions{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:12px}.member-actions .full{grid-column:1/-1}.role-owner{background:#fff8e8;color:#9a6500}.role-admin{background:#eef6ff;color:#1d4ed8}.role-manager{background:#f0fdf4;color:#15803d}.role-staff{background:#f3f4f6;color:#374151}.status-off{opacity:.62}.danger-link{background:#fff0f2;color:#b4233a}.transfer{margin-top:18px;padding-top:16px;border-top:1px solid #e5ece8}@media(max-width:900px){.team-grid{grid-template-columns:1fr}}</style></head>
<body><header class="topbar"><div><div class="eyebrow">GoTM — Organization</div><h1><?=saas_h($org['name']??'Organization')?></h1><div class="sub">Team & access management</div></div><div class="nav-actions"><a class="btn ghost" href="saas_dashboard.php">Dashboard</a><a class="btn ghost" href="saas_logout.php">Logout</a></div></header>
<main class="team-wrap"><div class="team-hero"><div><h1>Team management</h1><p class="muted">Add existing GoTM users, control organization roles, and manage access safely.</p></div><span class="badge"><?=count($members)?> members</span></div>
<?php if($ok):?><div class="alert"><?=saas_h($ok)?></div><?php endif;?><?php if($error):?><div class="alert danger"><?=saas_h($error)?></div><?php endif;?>
<div class="team-grid"><section class="panel"><h2>Add team member</h2><p class="muted">The person must already have a GoTM account. Use their username or email.</p><form method="post"><input type="hidden" name="csrf" value="<?=saas_h(saas_csrf())?>"><input type="hidden" name="action" value="add"><label>Username or email<input name="identity" required autocomplete="off"></label><label>Organization role<select name="role"><option>STAFF</option><option>MANAGER</option><option>ADMIN</option></select></label><button class="btn primary wide" type="submit">Add to organization</button></form>
<div class="transfer"><h3>Transfer ownership</h3><p class="muted">The current owner becomes ADMIN and the selected active member becomes OWNER.</p><form method="post"><input type="hidden" name="csrf" value="<?=saas_h(saas_csrf())?>"><input type="hidden" name="action" value="transfer"><select name="member_id" required><option value="">Select active member</option><?php foreach($members as $m): if($m['status']==='ACTIVE'&&$m['role']!=='OWNER'):?><option value="<?=$m['id']?>"><?=saas_h($m['name'])?> — <?=saas_h($m['role'])?></option><?php endif;endforeach;?></select><button class="btn secondary wide" type="submit">Transfer ownership</button></form></div></section>
<section class="panel"><h2>Organization members</h2><?php foreach($members as $m):?><article class="member <?=$m['status']==='INACTIVE'?'status-off':''?>"><div class="member-head"><div><div class="member-name"><?=saas_h($m['name'])?></div><div class="meta"><?=saas_h($m['email']?:$m['username'])?> · <?=saas_h($m['tour_count'])?> active tour assignment(s)</div></div><span class="badge role-<?=strtolower(saas_h($m['role']))?>"><?=saas_h($m['role'])?></span></div>
<?php if($m['role']==='OWNER'):?><p class="muted">Owner access cannot be disabled or downgraded here.</p><?php else:?><div class="member-actions"><form method="post"><input type="hidden" name="csrf" value="<?=saas_h(saas_csrf())?>"><input type="hidden" name="action" value="update"><input type="hidden" name="member_id" value="<?=$m['id']?>"><select name="role"><option <?=$m['role']==='ADMIN'?'selected':''?>>ADMIN</option><option <?=$m['role']==='MANAGER'?'selected':''?>>MANAGER</option><option <?=$m['role']==='STAFF'?'selected':''?>>STAFF</option></select><select name="status"><option value="ACTIVE" <?=$m['status']==='ACTIVE'?'selected':''?>>ACTIVE</option><option value="INACTIVE" <?=$m['status']==='INACTIVE'?'selected':''?>>INACTIVE</option></select><button class="btn secondary wide">Save access</button></form><form method="post"><input type="hidden" name="csrf" value="<?=saas_h(saas_csrf())?>"><input type="hidden" name="action" value="remove"><input type="hidden" name="member_id" value="<?=$m['id']?>"><button class="btn danger-link wide" onclick="return confirm('Remove this member from the organization?')">Remove</button></form></div><?php endif;?></article><?php endforeach;?></section></div></main></body></html>