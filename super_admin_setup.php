<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
saas_require_login();
$db=saas_db();
$uid=saas_user_id();
$exists=(int)$db->query('SELECT COUNT(*) FROM super_admins')->fetchColumn()>0;
$msg='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        saas_check_csrf();
        if($exists) throw new RuntimeException('A Super Admin already exists.');
        $db->beginTransaction();
        $q=$db->prepare('INSERT INTO super_admins(user_id,created_by) VALUES(?,?)');
        $q->execute([$uid,$uid]);
        $db->commit();
        saas_audit('super_admin.bootstrap','super_admin',null,'Initial Super Admin granted to user '.$uid);
        $exists=true;$msg='Super Admin access activated for your account.';
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();$error=$e->getMessage();}
}
$user=saas_current_user();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>GoTM — Super Admin Setup</title><link rel="stylesheet" href="assets/app.css"><style>body{background:#f5f7fb}.wrap{max-width:620px;margin:70px auto;padding:20px}.card{background:#fff;border:1px solid #e5e7eb;border-radius:18px;padding:28px;box-shadow:0 10px 30px #0000000a}.ok{background:#ecfdf3;color:#067647;padding:12px;border-radius:10px}.err{background:#fef3f2;color:#b42318;padding:12px;border-radius:10px}.btn{display:inline-block;margin-top:16px}</style></head><body><main class="wrap"><section class="card"><div class="eyebrow">GOTM SYSTEM ADMIN</div><h1>Super Admin Setup</h1><p>You are signed in as <b><?=saas_h($user['name']??'')?></b> (<?=saas_h($user['email']??$user['username']??'')?>).</p><?php if($msg):?><div class="ok"><?=saas_h($msg)?></div><a class="btn primary" href="super_admin.php">Open Super Admin</a><?php elseif($error):?><div class="err"><?=saas_h($error)?></div><?php elseif($exists):?><div class="err">A Super Admin is already configured. Your account does not have access.</div><a class="btn" href="dashboard.php">Back to dashboard</a><?php else:?><p>This one-time bootstrap grants Super Admin access to the currently signed-in account. After activation, this page is locked.</p><form method="post"><input type="hidden" name="csrf" value="<?=saas_h(saas_csrf())?>"><button class="btn primary" type="submit">Activate Super Admin</button></form><?php endif;?></section></main></body></html>