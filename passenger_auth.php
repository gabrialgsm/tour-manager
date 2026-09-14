<?php
declare(strict_types=1);
require __DIR__.'/bootstrap_saas.php';
$db=saas_db();
if(!empty($_SESSION['passenger_account_id'])) saas_redirect('passenger_dashboard.php');
$error='';$ok='';$mode=((string)($_GET['mode']??'login'))==='register'?'register':'login';
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  saas_check_csrf();$action=(string)($_POST['action']??'');
  if($action==='register'){
   $mode='register';$email=strtolower(trim((string)($_POST['email']??'')));$password=(string)($_POST['password']??'');
   if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Enter a valid email address.');
   if(strlen($password)<8)throw new RuntimeException('Password must be at least 8 characters.');
   $q=$db->prepare('SELECT id FROM passenger_accounts WHERE LOWER(email)=? LIMIT 1');$q->execute([$email]);if($q->fetch())throw new RuntimeException('An account already exists for this email. Please log in.');
   $q=$db->prepare('SELECT id,organization_id,phone,email FROM passenger_profiles WHERE LOWER(email)=? ORDER BY id DESC LIMIT 1');$q->execute([$email]);$profile=$q->fetch();
   if(!$profile)throw new RuntimeException('No passenger profile was found for this email. Ask the tour organizer to add your email first.');
   $q=$db->prepare('INSERT INTO passenger_accounts(passenger_profile_id,organization_id,email,phone,password_hash,email_verified_at) VALUES(?,?,?,?,?,NOW())');$q->execute([(int)$profile['id'],(int)$profile['organization_id'],$email,$profile['phone']??null,password_hash($password,PASSWORD_DEFAULT)]);
   $ok='Account created. You can now log in.';$mode='login';
  }elseif($action==='login'){
   $login=strtolower(trim((string)($_POST['login']??'')));$password=(string)($_POST['password']??'');
   if($login===''||$password==='')throw new RuntimeException('Email/phone and password are required.');
   $q=$db->prepare("SELECT pa.*,pp.full_name FROM passenger_accounts pa JOIN passenger_profiles pp ON pp.id=pa.passenger_profile_id WHERE (LOWER(pa.email)=? OR pa.phone=?) AND pa.status='ACTIVE' LIMIT 1");$q->execute([$login,$login]);$account=$q->fetch();
   if(!$account||empty($account['password_hash'])||!password_verify($password,$account['password_hash']))throw new RuntimeException('Invalid login credentials.');
   session_regenerate_id(true);$_SESSION['passenger_account_id']=(int)$account['id'];$_SESSION['passenger_profile_id']=(int)$account['passenger_profile_id'];$_SESSION['passenger_organization_id']=(int)$account['organization_id'];
   $db->prepare('UPDATE passenger_accounts SET last_login_at=NOW() WHERE id=?')->execute([(int)$account['id']]);saas_redirect('passenger_dashboard.php');
  }else throw new RuntimeException('Invalid action.');
 }catch(Throwable $e){$error=$e->getMessage();}
}
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Passenger Account</title><style>body{font-family:Arial,sans-serif;background:#f4f7fb;margin:0;color:#172033}.wrap{max-width:460px;margin:55px auto;padding:16px}.card{background:#fff;border-radius:18px;padding:25px;box-shadow:0 8px 30px #0001}label{display:block;font-weight:700;margin:12px 0 6px}input,button{width:100%;padding:12px;border:1px solid #d0d5dd;border-radius:9px;font:inherit}button{background:#155eef;color:#fff;border-color:#155eef;font-weight:700;margin-top:14px}.msg{padding:11px;border-radius:9px;margin-bottom:14px}.ok{background:#ecfdf3;color:#067647}.err{background:#fef3f2;color:#b42318}.muted{color:#667085;font-size:13px}a{color:#155eef}</style></head><body><main class="wrap"><div class="card"><h1><?= $mode==='register'?'Create passenger account':'Passenger login'?></h1><?php if($ok):?><div class="msg ok"><?=saas_h($ok)?></div><?php endif;?><?php if($error):?><div class="msg err"><?=saas_h($error)?></div><?php endif;?><?php if($mode==='register'):?><p class="muted">Your tour organizer must already have your passenger email in the system. Your account is linked to that existing profile.</p><form method="post"><input type="hidden" name="csrf" value="<?=saas_h(saas_csrf())?>"><input type="hidden" name="action" value="register"><label>Email</label><input type="email" name="email" required autocomplete="email"><label>Password</label><input type="password" name="password" minlength="8" required autocomplete="new-password"><button>Create account</button></form><p><a href="passenger_auth.php">Already have an account? Log in</a></p><?php else:?><p class="muted">Use the email or phone connected to your passenger account.</p><form method="post"><input type="hidden" name="csrf" value="<?=saas_h(saas_csrf())?>"><input type="hidden" name="action" value="login"><label>Email or phone</label><input name="login" required autocomplete="username"><label>Password</label><input type="password" name="password" required autocomplete="current-password"><button>Log in</button></form><p><a href="passenger_auth.php?mode=register">Create an account</a></p><?php endif;?></div></main></body></html>