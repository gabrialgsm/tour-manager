<?php
declare(strict_types=1);
require __DIR__.'/bootstrap_saas.php';
require __DIR__.'/passenger_auth_helpers.php';
$db=saas_db();
$slug=trim((string)($_GET['slug']??$_POST['slug']??''));
$token=trim((string)($_GET['token']??$_POST['token']??''));
function register_target(PDO $db,string $slug,string $token): array {
 if($slug===''||!preg_match('/^[a-f0-9]{64}$/',$token))return [];
 $q=$db->prepare("SELECT tp.id,tp.organization_id,tp.passenger_profile_id,pp.full_name,pp.email,t.name AS tour_name,t.slug FROM tour_passengers tp JOIN passenger_profiles pp ON pp.id=tp.passenger_profile_id JOIN tours t ON t.id=tp.tour_id JOIN organizations o ON o.id=t.organization_id WHERE t.slug=? AND t.status IN ('ACTIVE','DRAFT') AND o.status='ACTIVE' AND tp.status='ACTIVE' AND pp.email IS NOT NULL AND tp.booking_access_token_hash=? AND tp.booking_access_token_revoked_at IS NULL LIMIT 1");
 $q->execute([$slug,hash('sha256',$token)]);return $q->fetch()?:[];
}
$target=register_target($db,$slug,$token);$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  saas_check_csrf();
  if(!$target)throw new RuntimeException('This booking link is invalid or revoked.');
  $pw=(string)($_POST['password']??'');$pw2=(string)($_POST['password_confirm']??'');
  if(strlen($pw)<8)throw new RuntimeException('Password must be at least 8 characters.');
  if(!hash_equals($pw,$pw2))throw new RuntimeException('Passwords do not match.');
  $q=$db->prepare('SELECT id FROM passenger_accounts WHERE organization_id=? AND passenger_profile_id=? LIMIT 1');
  $q->execute([(int)$target['organization_id'],(int)$target['passenger_profile_id']]);
  if($q->fetch())throw new RuntimeException('An account already exists. Please log in.');
  $db->beginTransaction();
  $q=$db->prepare("INSERT INTO passenger_accounts(organization_id,passenger_profile_id,email,password_hash,status,email_verified_at) VALUES(?,?,?,?, 'ACTIVE',NOW())");
  $q->execute([(int)$target['organization_id'],(int)$target['passenger_profile_id'],strtolower(trim((string)$target['email'])),password_hash($pw,PASSWORD_DEFAULT)]);
  $db->commit();
  saas_redirect('passenger_auth.php');
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();$error=$e->getMessage();}
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Create Passenger Account</title><style>body{font-family:system-ui,Arial;background:#f4f7fb;margin:0;color:#172033}.wrap{max-width:460px;margin:55px auto;padding:16px}.card{background:#fff;border-radius:18px;padding:25px;box-shadow:0 8px 30px #0001}label{display:block;font-weight:700;margin:12px 0 6px}input,button{width:100%;box-sizing:border-box;padding:12px;border:1px solid #d0d5dd;border-radius:9px;font:inherit}button{background:#155eef;color:#fff;border-color:#155eef;font-weight:700;margin-top:14px}.err{padding:11px;border-radius:9px;background:#fef3f2;color:#b42318;margin-bottom:14px}.muted{color:#667085;font-size:13px}a{color:#155eef}</style></head><body><main class="wrap"><div class="card"><h1>Create Account</h1><?php if($error):?><div class="err"><?=saas_h($error)?></div><?php endif;?><?php if($target):?><p class="muted">Booking: <strong><?=saas_h($target['tour_name'])?></strong><br><?=saas_h($target['full_name'])?> · <?=saas_h($target['email'])?></p><form method="post"><input type="hidden" name="csrf" value="<?=saas_h(saas_csrf())?>"><input type="hidden" name="slug" value="<?=saas_h($slug)"><input type="hidden" name="token" value="<?=saas_h($token)"><label>Password</label><input type="password" name="password" minlength="8" required autocomplete="new-password"><label>Confirm password</label><input type="password" name="password_confirm" minlength="8" required autocomplete="new-password"><button>Create account</button></form><p class="muted">Your password is stored securely. This account will only access bookings belonging to your passenger profile.</p><?php else:?><p>This booking link is invalid, revoked, or inactive.</p><?php endif;?></div></main></body></html>