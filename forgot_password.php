<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/mail.php';
require __DIR__.'/passenger_auth_rate_limit.php';

$db=saas_db(); $token=trim((string)($_GET['token']??$_POST['token']??'')); $error=''; $ok='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  saas_check_csrf();
  if($token!==''){
   if(!preg_match('/^[a-f0-9]{64}$/',$token)) throw new RuntimeException('This reset link is invalid or expired.');
   $q=$db->prepare("SELECT apr.id,apr.user_id,u.status FROM admin_password_resets apr JOIN users u ON u.id=apr.user_id WHERE apr.token_hash=? AND apr.used_at IS NULL AND apr.expires_at>NOW() LIMIT 1");
   $q->execute([hash('sha256',$token)]); $row=$q->fetch();
   if(!$row || $row['status']!=='ACTIVE') throw new RuntimeException('This reset link is invalid or expired.');
   $pw=(string)($_POST['password']??''); $pw2=(string)($_POST['password_confirm']??'');
   if(strlen($pw)<10) throw new RuntimeException('Password must be at least 10 characters.');
   if(!hash_equals($pw,$pw2)) throw new RuntimeException('Passwords do not match.');
   $db->beginTransaction();
   $db->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($pw,PASSWORD_DEFAULT),(int)$row['user_id']]);
   $db->prepare('UPDATE admin_password_resets SET used_at=NOW() WHERE id=?')->execute([(int)$row['id']]);
   $db->commit(); $ok='Your password has been reset successfully. You can now sign in.'; $token='';
  } else {
   $email=strtolower(trim((string)($_POST['email']??'')));
   if(!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid email address.');
   $key='reset:'.$email;
   if(admin_auth_rate_limited($key)) throw new RuntimeException('Too many reset requests. Please try again later.');
   admin_auth_rate_fail($key);
   $q=$db->prepare("SELECT id FROM users WHERE LOWER(email)=LOWER(?) AND status='ACTIVE' LIMIT 2"); $q->execute([$email]); $rows=$q->fetchAll();
   if(count($rows)===1){
    $uid=(int)$rows[0]['id']; $raw=bin2hex(random_bytes(32));
    $db->prepare('UPDATE admin_password_resets SET used_at=NOW() WHERE user_id=? AND used_at IS NULL')->execute([$uid]);
    $db->prepare('INSERT INTO admin_password_resets(user_id,token_hash,expires_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 30 MINUTE))')->execute([$uid,hash('sha256',$raw)]);
    $base=rtrim((string)($config['app']['base_url']??''),'/');
    if($base==='') $base=(isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'?'https':'http').'://'.($_SERVER['HTTP_HOST']??'');
    $url=$base.'/forgot_password.php?token='.rawurlencode($raw);
    $html='<div style="font-family:Arial,sans-serif;max-width:560px;margin:auto"><h2>Reset your GoTM password</h2><p>We received a request to reset your GoTM account password.</p><p><a href="'.saas_h($url).'" style="display:inline-block;padding:12px 20px;background:#155eef;color:#fff;text-decoration:none;border-radius:8px">Reset Password</a></p><p>This link expires in 30 minutes and can be used once.</p><p>If you did not request this, you can ignore this email.</p></div>';
    $text="Reset your GoTM password\n\nOpen this link:\n".$url."\n\nThis link expires in 30 minutes and can be used once.";
    try{saas_send_email($email,'Reset your GoTM password',$html,$text);}catch(Throwable $mailError){error_log('Admin password reset email failed: '.$mailError->getMessage());}
   }
   $ok='If an active GoTM account exists for that email, a password reset email has been sent.';
  }
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();$error=$e->getMessage();}
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Reset Password — GoTM</title><link rel="stylesheet" href="assets/app.css"><style>.login-card{max-width:520px}.reset-link{display:block;margin-top:14px;text-align:center;font-weight:700}.msg{padding:11px;border-radius:10px;margin-bottom:14px}.msg.ok{background:#ecfdf3;color:#067647}.msg.err{background:#fef3f2;color:#b42318}</style></head><body class="login-page"><div class="login-card"><div class="brand">TOUR MANAGER</div><h1><?= $token!==''?'Set new password':'Forgot password?' ?></h1><?php if($error):?><div class="msg err"><?=saas_h($error)?></div><?php endif;?><?php if($ok):?><div class="msg ok"><?=saas_h($ok)?></div><?php endif;?><?php if($token!==''):?><p class="muted">Choose a new password for your GoTM account.</p><form method="post"><input type="hidden" name="csrf" value="<?=saas_h(saas_csrf())?>"><input type="hidden" name="token" value="<?=saas_h($token)?>"><label>New password<input type="password" name="password" minlength="10" required autocomplete="new-password"></label><label>Confirm password<input type="password" name="password_confirm" minlength="10" required autocomplete="new-password"></label><button class="btn primary wide" type="submit">Reset password</button></form><?php elseif($ok===''):?><p class="muted">Enter your account email and we'll send you a secure reset link.</p><form method="post"><input type="hidden" name="csrf" value="<?=saas_h(saas_csrf())?>"><label>Email<input type="email" name="email" required autocomplete="email"></label><button class="btn primary wide" type="submit">Send reset email</button></form><?php endif;?><a class="reset-link" href="login.php">← Back to sign in</a></div></body></html>
