<?php
declare(strict_types=1);
require __DIR__.'/bootstrap_saas.php';
require __DIR__.'/passenger_auth_helpers.php';
$db=saas_db();
if(passenger_authenticated())saas_redirect('passenger_dashboard.php');
$error='';$ok='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  saas_check_csrf();
  $email=strtolower(trim((string)($_POST['email']??'')));$password=(string)($_POST['password']??'');
  if(!filter_var($email,FILTER_VALIDATE_EMAIL)||$password==='')throw new RuntimeException('Enter your email and password.');
  $q=$db->prepare("SELECT pa.* FROM passenger_accounts pa WHERE LOWER(pa.email)=LOWER(?) AND pa.status='ACTIVE' LIMIT 2");$q->execute([$email]);$rows=$q->fetchAll();
  if(count($rows)!==1||empty($rows[0]['password_hash'])||!password_verify($password,$rows[0]['password_hash']))throw new RuntimeException('Invalid login credentials.');
  $a=$rows[0];session_regenerate_id(true);$_SESSION['passenger_account_id']=(int)$a['id'];$_SESSION['passenger_profile_id']=(int)$a['passenger_profile_id'];$_SESSION['passenger_organization_id']=(int)$a['organization_id'];
  $db->prepare('UPDATE passenger_accounts SET last_login_at=NOW() WHERE id=?')->execute([(int)$a['id']]);saas_redirect('passenger_dashboard.php');
 }catch(Throwable $e){$error=$e->getMessage();}
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Passenger Login</title><style>body{font-family:Arial;background:#f4f7fb;margin:0;color:#172033}.wrap{max-width:460px;margin:55px auto;padding:16px}.card{background:#fff;border-radius:18px;padding:25px;box-shadow:0 8px 30px #0001}label{display:block;font-weight:700;margin:12px 0 6px}input,button{width:100%;padding:12px;border:1px solid #d0d5dd;border-radius:9px;font:inherit}button{background:#155eef;color:#fff;border-color:#155eef;font-weight:700;margin-top:14px}.msg{padding:11px;border-radius:9px;margin-bottom:14px}.err{background:#fef3f2;color:#b42318}.muted{color:#667085;font-size:13px}</style></head><body><main class="wrap"><div class="card"><h1>Passenger Login</h1><?php if($error):?><div class="msg err"><?=saas_h($error)?></div><?php endif;?><p class="muted">Use the email connected to your passenger profile.</p><form method="post"><input type="hidden" name="csrf" value="<?=saas_h(saas_csrf())?>"><label>Email</label><input type="email" name="email" required autocomplete="username"><label>Password</label><input type="password" name="password" required autocomplete="current-password"><button>Log in</button></form></div></main></body></html>