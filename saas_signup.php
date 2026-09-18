<?php
declare(strict_types=1);
require __DIR__.'/bootstrap_saas.php';
require __DIR__.'/passenger_auth_rate_limit.php';
if (saas_authenticated()) saas_redirect('saas_dashboard.php');
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        saas_check_csrf();
        $name=trim((string)($_POST['name']??''));
        $username=trim((string)($_POST['username']??''));
        $email=trim(strtolower((string)($_POST['email']??'')));
        $password=(string)($_POST['password']??'');
        if($name===''||mb_strlen($name)>180) throw new RuntimeException('Please enter your name.');
        if(!preg_match('/^[A-Za-z0-9._-]{3,80}$/',$username)) throw new RuntimeException('Username must be 3–80 characters and use letters, numbers, dots, underscores or hyphens.');
        if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Please enter a valid email address.');
        if(strlen($password)<10) throw new RuntimeException('Password must be at least 10 characters.');
        if(admin_auth_rate_limited($email!==''?$email:$username)) throw new RuntimeException('Too many attempts. Please wait and try again.');
        $db=saas_db();$db->beginTransaction();
        $q=$db->prepare('SELECT id FROM users WHERE username=? OR (?<>"" AND email=?) LIMIT 1 FOR UPDATE');
        $q->execute([$username,$email,$email]);
        if($q->fetch()){admin_auth_rate_fail($email!==''?$email:$username);throw new RuntimeException('Unable to create the account. Username or email may already exist.');}
        $q=$db->prepare('INSERT INTO users(name,username,email,password_hash,status) VALUES(?,?,?,?,\'ACTIVE\')');
        $q->execute([$name,$username,$email?:null,password_hash($password,PASSWORD_DEFAULT)]);
        $uid=(int)$db->lastInsertId();
        $db->commit();
        admin_auth_rate_success($email!==''?$email:$username);
        session_regenerate_id(true);
        unset($_SESSION['passenger_account_id'],$_SESSION['passenger_profile_id'],$_SESSION['passenger_organization_id'],$_SESSION['passenger_session_id']);
        $_SESSION['user_id']=$uid;$_SESSION['organization_id']=0;$_SESSION['tour_id']=0;$_SESSION['saas_csrf']=bin2hex(random_bytes(32));
        saas_redirect('organization_create.php');
    }catch(Throwable $e){if(isset($db)&&$db->inTransaction())$db->rollBack();$error=$e->getMessage();}
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>GoTM — Start free</title><link rel="stylesheet" href="assets/app.css"></head><body class="login-page"><div class="login-card"><div class="brand">GoTM — GoZyraa Tour Management</div><h1>Start free</h1><p class="muted">Create your GoTM account and set up your first organization.</p><?php if($error):?><div class="alert danger"><?=saas_h($error)?></div><?php endif;?><form method="post"><input type="hidden" name="csrf" value="<?=saas_h(saas_csrf())?>"><label>Your name<input name="name" required maxlength="180" autocomplete="name"></label><label>Username<input name="username" required maxlength="80" autocomplete="username"></label><label>Email <span class="muted">(recommended)</span><input type="email" name="email" maxlength="190" autocomplete="email"></label><label>Password<input type="password" name="password" minlength="10" required autocomplete="new-password"></label><button class="btn primary wide" type="submit">Create free account</button></form><p class="hint">Already have an account? <a href="saas_login.php">Sign in</a></p></div></body></html>