<?php
declare(strict_types=1);
require __DIR__.'/bootstrap_saas.php';
if (saas_authenticated()) saas_redirect('saas_dashboard.php');
$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    saas_check_csrf();
    $identity=trim((string)($_POST['identity']??''));
    $password=(string)($_POST['password']??'');
    $q=saas_db()->prepare("SELECT id,password_hash,status FROM users WHERE (username=? OR email=?) LIMIT 1");
    $q->execute([$identity,$identity]);
    $u=$q->fetch();
    if ($u && $u['status']==='ACTIVE' && password_verify($password,$u['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user_id']=(int)$u['id'];
        $_SESSION['saas_csrf']=bin2hex(random_bytes(32));
        saas_db()->prepare("UPDATE users SET last_login_at=NOW() WHERE id=?")->execute([(int)$u['id']]);
        saas_redirect('saas_dashboard.php');
    }
    $error='Invalid username/email or password.';
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Tour Manager — Sign in</title><link rel="stylesheet" href="assets/app.css"></head><body class="login-page"><div class="login-card"><div class="brand">TOUR MANAGER</div><h1>Welcome back</h1><p class="muted">Sign in to manage your tours.</p><?php if($error):?><div class="alert danger"><?=saas_h($error)?></div><?php endif;?><form method="post"><input type="hidden" name="csrf" value="<?=saas_h(saas_csrf())?>"><label>Username or email<input name="identity" required autocomplete="username"></label><label>Password<input type="password" name="password" required autocomplete="current-password"></label><button class="btn primary wide" type="submit">Sign in</button></form></div></body></html>