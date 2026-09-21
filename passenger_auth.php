<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/passenger_auth_helpers.php';
require __DIR__.'/passenger_auth_rate_limit.php';
$db=saas_db();
if(passenger_authenticated())saas_redirect('passenger_dashboard.php');
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  saas_check_csrf();$email=strtolower(trim((string)($_POST['email']??'')));$password=(string)($_POST['password']??'');
  if(!filter_var($email,FILTER_VALIDATE_EMAIL)||$password==='')throw new RuntimeException('Enter your email and password.');
  if(passenger_auth_rate_limited($email))throw new RuntimeException('Too many login attempts. Please wait 15 minutes and try again.');
  $q=$db->prepare("SELECT pa.* FROM passenger_accounts pa WHERE LOWER(pa.email)=LOWER(?) LIMIT 2");$q->execute([$email]);$rows=$q->fetchAll();
  $valid=false;$a=$rows[0]??null;
  if(count($rows)===1 && $a && $a['status']==='ACTIVE' && !empty($a['password_hash']))$valid=password_verify($password,(string)$a['password_hash']);
  else password_verify($password,'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llCk0k9VQ2J4lq6Qw0Wq');
  if(!$valid){passenger_auth_rate_fail($email);throw new RuntimeException('Invalid login credentials.');}
  passenger_auth_rate_success($email);
  session_regenerate_id(true);
  passenger_clear_session();
  unset($_SESSION['user_id'],$_SESSION['organization_id'],$_SESSION['tour_id']);
  $_SESSION['passenger_account_id']=(int)$a['id'];$_SESSION['passenger_profile_id']=(int)$a['passenger_profile_id'];$_SESSION['passenger_organization_id']=(int)$a['organization_id'];
  $_SESSION['saas_csrf']=bin2hex(random_bytes(32));
  passenger_start_session((int)$a['id']);
  $db->prepare('UPDATE passenger_accounts SET last_login_at=NOW() WHERE id=?')->execute([(int)$a['id']]);
  saas_redirect('passenger_dashboard.php');
 }catch(Throwable $e){$error=$e->getMessage();if($error==='')$error='Invalid login credentials.';}
}
?><style>:root{--navy:#0B2A49;--gold:#F7AB17;--line:#e4e7ec}*{box-sizing:border-box}body{margin:0;font-family:Inter,system-ui,-apple-system,Segoe UI,Arial;background:linear-gradient(135deg,#eef4fa,#fff);color:#142033}.wrap{min-height:100vh;display:grid;place-items:center;padding:24px}.card{width:min(440px,100%);background:#fff;border:1px solid var(--line);border-radius:24px;padding:30px;box-shadow:0 20px 60px #0b2a4915}.brand{display:flex;align-items:center;gap:10px;color:var(--navy);font-weight:950;font-size:20px}.brand-mark{width:42px;height:42px;border-radius:12px;background:var(--navy);color:var(--gold);display:grid;place-items:center}.sub{color:#667085;font-size:14px}.oauth{display:block;text-align:center;text-decoration:none;padding:12px;border:1px solid var(--line);border-radius:11px;font-weight:800;margin:10px 0}.facebook{background:#1877f2;color:#fff;border-color:#1877f2}.or{display:flex;align-items:center;gap:10px;color:#98a2b3;font-size:12px;margin:18px 0}.or:before,.or:after{content:"";height:1px;background:var(--line);flex:1}label{display:block;font-size:12px;font-weight:900;margin:12px 0 6px}input,button{width:100%;padding:13px;border:1px solid #d0d5dd;border-radius:11px;font:inherit}button{background:var(--navy);color:#fff;border-color:var(--navy);font-weight:900;margin-top:14px;cursor:pointer}.msg{padding:12px;border-radius:11px;margin-bottom:14px}.err{background:#fef3f2;color:#b42318}.reset{display:block;text-align:center;margin-top:16px;font-weight:800;color:var(--navy)}a{color:var(--navy)}</style><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Passenger Login</title><style>body{font-family:system-ui,Arial;background:#f4f7fb;margin:0;color:#172033}.wrap{max-width:460px;margin:55px auto;padding:16px}.card{background:#fff;border-radius:18px;padding:25px;box-shadow:0 8px 30px #0001}label{display:block;font-weight:700;margin:12px 0 6px}input,button,.oauth{width:100%;box-sizing:border-box;padding:12px;border:1px solid #d0d5dd;border-radius:9px;font:inherit}.primary{background:#155eef;color:#fff;border-color:#155eef;font-weight:700;margin-top:14px}.oauth{display:block;text-align:center;text-decoration:none;color:#172033;font-weight:700;margin:10px 0}.facebook{background:#1877f2;color:#fff;border-color:#1877f2}.reset{display:block;text-align:center;margin-top:14px}.or{text-align:center;color:#98a2b3;font-size:13px;margin:12px 0}.msg{padding:11px;border-radius:9px;margin-bottom:14px}.err{background:#fef3f2;color:#b42318}.muted{color:#667085;font-size:13px}a{color:#155eef}</style></head><body><main class="wrap"><div class="card"><h1>Passenger Login</h1><?php if($error):?><div class="msg err"><?=saas_h($error)?></div><?php endif;?><p class="muted">Use your passenger account email/password, or continue with a social login.</p><a class="oauth" href="passenger_google_auth.php?action=start">Continue with Google</a><a class="oauth facebook" href="passenger_facebook_auth.php?action=start">Continue with Facebook</a><div class="or">OR</div><form method="post"><input type="hidden" name="csrf" value="<?=saas_h(saas_csrf())?>"><label>Email</label><input type="email" name="email" required autocomplete="username"><label>Password</label><input type="password" name="password" required autocomplete="current-password"><button class="primary">Log in</button></form><a class="reset" href="passenger_password_reset.php">Forgot password?</a></div></main></body></html>