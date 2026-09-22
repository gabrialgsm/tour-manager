<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/mail.php';
$error=''; $ok='';
$token=trim((string)($_GET['token']??''));
if ($token!=='') {
 try {
  $hash=hash('sha256',$token); $db=saas_db();
  $q=$db->prepare('SELECT sev.id,sev.user_id,u.email FROM signup_email_verifications sev JOIN users u ON u.id=sev.user_id WHERE sev.token_hash=? AND sev.used_at IS NULL AND sev.expires_at>=NOW() LIMIT 1');
  $q->execute([$hash]); $row=$q->fetch();
  if(!$row) throw new RuntimeException('This verification link is invalid or expired.');
  $db->beginTransaction();
  $db->prepare('UPDATE users SET status=\'ACTIVE\',email_verified_at=NOW() WHERE id=?')->execute([(int)$row['user_id']]);
  $db->prepare('UPDATE signup_email_verifications SET used_at=NOW() WHERE id=?')->execute([(int)$row['id']]);
  $db->commit(); $ok='Email verified successfully. You can now sign in.';
 } catch(Throwable $e) { if(isset($db)&&$db->inTransaction())$db->rollBack(); $error=$e->getMessage(); }
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>GoTM — Verify email</title><link rel="stylesheet" href="assets/app.css"></head><body class="login-page"><div class="login-card"><div class="brand">GoTM — GoZyraa Tour Management</div><h1>Email verification</h1><?php if($ok):?><div class="alert success"><?=saas_h($ok)?></div><p class="hint"><a href="login.php">Sign in to continue</a></p><?php else:?><p class="muted"><?=saas_h($error?:'Please check your email and open the verification link.')?></p><p class="hint"><a href="login.php">Back to sign in</a></p><?php endif;?></div></body></html>
