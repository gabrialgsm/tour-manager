<?php
require __DIR__.'/bootstrap.php';
require __DIR__.'/passenger_auth_rate_limit.php';
if (saas_authenticated()) saas_redirect('tours.php');
$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        saas_check_csrf();
        $identity=trim((string)($_POST['identity']??''));
        $password=(string)($_POST['password']??'');
        if($identity===''||$password==='')throw new RuntimeException('Invalid username/email or password.');
        if(admin_auth_rate_limited($identity))throw new RuntimeException('Too many login attempts. Please wait 15 minutes and try again.');
        $q=saas_db()->prepare("SELECT id,password_hash,status FROM users WHERE (username=? OR email=?) LIMIT 1");
        $q->execute([$identity,$identity]);
        $u=$q->fetch();
        $valid=false;
        if ($u && $u['status']==='PENDING') throw new RuntimeException('Please verify your email address before signing in.');
        if ($u && $u['status']==='ACTIVE') $valid=password_verify($password,(string)$u['password_hash']);
        else password_verify($password,'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llCk0k9VQ2J4lq6Qw0Wq');
        if (!$valid) { admin_auth_rate_fail($identity); throw new RuntimeException('Invalid username/email or password.'); }
        admin_auth_rate_success($identity);
        session_regenerate_id(true);
        unset($_SESSION['passenger_account_id'],$_SESSION['passenger_profile_id'],$_SESSION['passenger_organization_id'],$_SESSION['passenger_session_id']);
        $_SESSION['user_id']=(int)$u['id'];
        $_SESSION['organization_id']=0;
        $_SESSION['tour_id']=0;
        $_SESSION['saas_csrf']=bin2hex(random_bytes(32));
        saas_db()->prepare("UPDATE users SET last_login_at=NOW() WHERE id=?")->execute([(int)$u['id']]);
        saas_redirect('tours.php');
    } catch(Throwable $e) { $error=$e->getMessage(); }
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>GoTM — Sign in</title><link rel="stylesheet" href="assets/app.css"></head><body class="login-page"><div class="login-card"><div class="brand">TOUR MANAGER</div><h1>Welcome back</h1><p class="muted">Sign in to manage your tours.</p><?php if($error):?><div class="alert danger"><?=saas_h($error)?></div><?php endif;?><form method="post"><input type="hidden" name="csrf" value="<?=saas_h(saas_csrf())?>"><label>Username or email<input name="identity" required autocomplete="username"></label><label>Password<input type="password" name="password" required autocomplete="current-password"></label><button class="btn primary wide" type="submit">Sign in</button></form></div></body></html>