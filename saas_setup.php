<?php
declare(strict_types=1);
require __DIR__.'/bootstrap_saas.php';
if (saas_authenticated()) saas_redirect('saas_dashboard.php');
$count=(int)saas_db()->query("SELECT COUNT(*) FROM users")->fetchColumn();
if($count>0) saas_redirect('saas_login.php');
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 saas_check_csrf();
 $name=trim((string)($_POST['name']??'')); $username=trim((string)($_POST['username']??'')); $email=trim((string)($_POST['email']??'')); $password=(string)($_POST['password']??'');
 if($name===''||$username===''||strlen($password)<10){$error='Name, username and a password of at least 10 characters are required.';}
 elseif($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL)){$error='Please enter a valid email address.';}
 else{
  $pdo=saas_db(); $pdo->beginTransaction();
  try{
   $q=$pdo->prepare("INSERT INTO users(name,username,email,password_hash) VALUES(?,?,?,?)");
   $q->execute([$name,$username,$email?:null,password_hash($password,PASSWORD_DEFAULT)]);
   $uid=(int)$pdo->lastInsertId(); session_regenerate_id(true); $_SESSION['user_id']=$uid; $_SESSION['saas_csrf']=bin2hex(random_bytes(32)); $pdo->commit(); saas_redirect('organization_create.php');
  }catch(Throwable $e){$pdo->rollBack();$error='Unable to create the account. Username or email may already exist.';}
 }
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Tour Manager — Setup</title><link rel="stylesheet" href="assets/app.css"></head><body class="login-page"><div class="login-card"><div class="brand">TOUR MANAGER</div><h1>Create your account</h1><p class="muted">Your account will become the owner of your first organization.</p><?php if($error):?><div class="alert danger"><?=saas_h($error)?></div><?php endif;?><form method="post"><input type="hidden" name="csrf" value="<?=saas_h(saas_csrf())?>"><label>Your name<input name="name" required autocomplete="name"></label><label>Username<input name="username" required autocomplete="username"></label><label>Email <span class="muted">(optional)</span><input type="email" name="email" autocomplete="email"></label><label>Password<input type="password" name="password" minlength="10" required autocomplete="new-password"></label><button class="btn primary wide">Create account</button></form></div></body></html>