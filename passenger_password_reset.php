<?php
declare(strict_types=1);
require __DIR__.'/bootstrap_saas.php';
require __DIR__.'/passenger_auth_helpers.php';
require __DIR__.'/passenger_auth_rate_limit.php';
require __DIR__.'/saas_mail.php';

$db=saas_db();
$token=trim((string)($_GET['token']??$_POST['token']??''));
$error='';
$ok='';

if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  saas_check_csrf();

  if($token!==''){
   if(!preg_match('/^[a-f0-9]{64}$/',$token))throw new RuntimeException('This reset link is invalid or expired.');

   $q=$db->prepare("SELECT ppr.id,ppr.passenger_account_id,pa.status FROM passenger_password_resets ppr JOIN passenger_accounts pa ON pa.id=ppr.passenger_account_id WHERE ppr.token_hash=? AND ppr.used_at IS NULL AND ppr.expires_at>NOW() LIMIT 1");
   $q->execute([hash('sha256',$token)]);
   $r=$q->fetch();
   if(!$r||$r['status']!=='ACTIVE')throw new RuntimeException('This reset link is invalid or expired.');

   $pw=(string)($_POST['password']??'');
   $pw2=(string)($_POST['password_confirm']??'');
   if(strlen($pw)<8)throw new RuntimeException('Password must be at least 8 characters.');
   if(!hash_equals($pw,$pw2))throw new RuntimeException('Passwords do not match.');

   $db->beginTransaction();
   $db->prepare('UPDATE passenger_accounts SET password_hash=? WHERE id=?')->execute([password_hash($pw,PASSWORD_DEFAULT),(int)$r['passenger_account_id']]);
   $db->prepare('UPDATE passenger_password_resets SET used_at=NOW() WHERE id=?')->execute([(int)$r['id']]);
   $db->prepare('UPDATE passenger_sessions SET revoked_at=COALESCE(revoked_at,NOW()) WHERE passenger_account_id=? AND revoked_at IS NULL')->execute([(int)$r['passenger_account_id']]);
   $db->commit();
   $ok='Your password has been reset. You can now log in.';
   $token='';
  }else{
   $email=strtolower(trim((string)($_POST['email']??'')));
   if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Enter a valid email address.');
   if(passenger_reset_rate_limited($email))throw new RuntimeException('Too many reset requests. Please try again later.');
   passenger_reset_rate_fail($email);

   $q=$db->prepare("SELECT id FROM passenger_accounts WHERE LOWER(email)=LOWER(?) AND status='ACTIVE' LIMIT 2");
   $q->execute([$email]);
   $rows=$q->fetchAll();

   if(count($rows)===1){
    $accountId=(int)$rows[0]['id'];
    $raw=bin2hex(random_bytes(32));
    $db->prepare('UPDATE passenger_password_resets SET used_at=NOW() WHERE passenger_account_id=? AND used_at IS NULL')->execute([$accountId]);
    $db->prepare('INSERT INTO passenger_password_resets(passenger_account_id,token_hash,expires_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 30 MINUTE))')->execute([$accountId,hash('sha256',$raw)]);

    $config=require __DIR__.'/config.php';
    $base=rtrim((string)($config['app']['base_url']??''),'/');
    if($base==='')$base=(isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'?'https':'http').'://'.($_SERVER['HTTP_HOST']??'');
    $resetUrl=rtrim($base,'/').'/passenger_password_reset.php?token='.rawurlencode($raw);

    $html='<!doctype html><html><body style="font-family:Arial,sans-serif;line-height:1.6;color:#172033"><div style="max-width:560px;margin:30px auto;padding:28px;border:1px solid #e5e7eb;border-radius:14px"><h2 style="margin-top:0">Reset your GoTM password</h2><p>We received a request to reset the password for your passenger account.</p><p><a href="'.saas_h($resetUrl).'" style="display:inline-block;padding:12px 18px;background:#0B2A49;color:#fff;text-decoration:none;border-radius:8px">Reset Password</a></p><p>This link expires in 30 minutes and can be used only once.</p><p style="font-size:13px;color:#667085">If you did not request this, you can safely ignore this email.</p></div></body></html>';
    $text="Reset your GoTM password\n\nOpen this link to reset your password:\n".$resetUrl."\n\nThis link expires in 30 minutes and can be used only once.\n\nIf you did not request this, you can safely ignore this email.";

    try{
      saas_send_email($email,'Reset your GoTM password',$html,$text);
    }catch(Throwable $mailError){
      error_log('Passenger password reset email failed: '.$mailError->getMessage());
    }
   }

   // Always return the same public response for existing/non-existing accounts.
   $ok='If an active passenger account exists for that email, a password reset email has been sent.';
  }
 }catch(Throwable $e){
  if($db->inTransaction())$db->rollBack();
  $error=$e->getMessage();
 }
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Reset Password — GoTM</title><style>body{font-family:system-ui,Arial;background:#f4f7fb;margin:0;color:#172033}.wrap{max-width:480px;margin:55px auto;padding:16px}.card{background:#fff;border-radius:18px;padding:25px;box-shadow:0 8px 30px #0001}label{display:block;font-weight:700;margin:12px 0 6px}input,button{width:100%;box-sizing:border-box;padding:12px;border:1px solid #d0d5dd;border-radius:9px;font:inherit}button{background:#0B2A49;color:#fff;border-color:#0B2A49;font-weight:700;margin-top:14px}.msg{padding:11px;border-radius:9px;margin-bottom:14px}.err{background:#fef3f2;color:#b42318}.ok{background:#ecfdf3;color:#067647}.muted{color:#667085;font-size:13px}a{color:#0B2A49}</style></head><body><main class="wrap"><div class="card"><p><a href="passenger_auth.php">← Passenger Login</a></p><h1>Reset Password</h1><?php if($error):?><div class="msg err"><?=saas_h($error)?></div><?php endif;?><?php if($ok):?><div class="msg ok"><?=saas_h($ok)?></div><?php endif;?><?php if($token!==''):?><form method="post"><input type="hidden" name="csrf" value="<?=saas_h(saas_csrf())?>"><input type="hidden" name="token" value="<?=saas_h($token)?>"><label>New password</label><input type="password" name="password" minlength="8" required autocomplete="new-password"><label>Confirm password</label><input type="password" name="password_confirm" minlength="8" required autocomplete="new-password"><button>Reset password</button></form><?php elseif($ok===''):?><form method="post"><input type="hidden" name="csrf" value="<?=saas_h(saas_csrf())?>"><label>Email</label><input type="email" name="email" required autocomplete="email"><button>Send Reset Email</button></form><?php endif;?></div></main></body></html>