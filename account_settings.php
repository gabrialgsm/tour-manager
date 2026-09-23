<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
saas_require_login();
$db=saas_db();
$user=saas_current_user();
$error='';
$ok='';

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        saas_check_csrf();
        $action=(string)($_POST['action']??'profile');

        if($action==='profile'){
            $name=trim((string)($_POST['name']??''));
            $email=trim(strtolower((string)($_POST['email']??'')));

            if($name==='') throw new RuntimeException('Name is required.');
            if(mb_strlen($name)>180) throw new RuntimeException('Name is too long.');
            if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Invalid email.');

            $q=$db->prepare("SELECT id FROM users WHERE email=? AND id<>? LIMIT 1");
            $q->execute([$email?:null,saas_user_id()]);
            if($q->fetch()) throw new RuntimeException('Email is already in use.');

            $q=$db->prepare('UPDATE users SET name=?,email=? WHERE id=?');
            $q->execute([$name,$email?:null,saas_user_id()]);
            $ok='Profile information updated successfully.';
            $user=saas_current_user();

        }elseif($action==='password'){
            $currentPassword=(string)($_POST['current_password']??'');
            $newPassword=(string)($_POST['new_password']??'');
            $confirmPassword=(string)($_POST['confirm_password']??'');

            if($currentPassword===''||$newPassword===''||$confirmPassword==='') throw new RuntimeException('Please fill in all password fields.');
            if(!password_verify($currentPassword,(string)($user['password_hash']??''))){
                $q=$db->prepare('SELECT password_hash FROM users WHERE id=? LIMIT 1');
                $q->execute([saas_user_id()]);
                $hash=(string)($q->fetchColumn()??'');
                if($hash===''||!password_verify($currentPassword,$hash)) throw new RuntimeException('Current password is incorrect.');
            }
            if(strlen($newPassword)<10) throw new RuntimeException('New password must be at least 10 characters.');
            if($newPassword!==$confirmPassword) throw new RuntimeException('New password and confirmation do not match.');
            if(password_verify($newPassword,(string)($user['password_hash']??''))) throw new RuntimeException('New password must be different from your current password.');

            $q=$db->prepare('UPDATE users SET password_hash=? WHERE id=?');
            $q->execute([password_hash($newPassword,PASSWORD_DEFAULT),saas_user_id()]);
            session_regenerate_id(true);
            $_SESSION['user_id']=saas_user_id();
            $_SESSION['saas_csrf']=bin2hex(random_bytes(32));
            $ok='Password updated successfully.';
        }
    }catch(Throwable $e){$error=$e->getMessage();}
}

$q=$db->prepare('SELECT id,name,username,email,status,created_at,password_hash FROM users WHERE id=? LIMIT 1');
$q->execute([saas_user_id()]);
$user=$q->fetch()?:$user;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Account settings — GoTM</title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#f5f7fb;font-family:Inter,Arial,sans-serif;color:#172033}
.settings-grid{max-width:820px;margin:32px auto;display:grid;gap:18px}
.box{background:#fff;padding:28px;border-radius:18px;box-shadow:0 8px 30px #0001}
.box h1,.box h2{color:#0b2a49;margin:0 0 8px}
.box h1{font-size:25px}.box h2{font-size:18px}
.box p{margin:7px 0}.muted{color:#667085;font-size:13px}
label{display:block;font-size:13px;font-weight:700;margin:15px 0}
input{width:100%;padding:11px;border:1px solid #d0d5dd;border-radius:9px;font:inherit;margin-top:6px}
input[readonly]{background:#f8fafc;color:#475467;cursor:not-allowed}
.hint{display:block;color:#667085;font-size:11px;font-weight:500;margin-top:6px}
button,a{padding:10px 14px;border-radius:9px;font-weight:800}
button{border:0;background:#155eef;color:#fff;cursor:pointer}
.actions{display:flex;gap:8px;margin-top:20px}
.actions a{border:1px solid #d0d5dd;color:#172033;text-decoration:none}
.msg{padding:11px;border-radius:9px;margin-bottom:14px}
.ok{background:#ecfdf3;color:#067647}.err{background:#fef3f2;color:#b42318}
.divider{height:1px;background:#eaecf0;margin:22px 0}
.security-note{padding:11px 12px;background:#f8fafc;border:1px solid #eaecf0;border-radius:10px;color:#667085;font-size:12px}
</style>
</head>
<body>
<main class="settings-grid">
<section class="box">
<p><a href="tours.php">← Back to tours</a></p>
<h1>Account settings</h1>
<p class="muted">Update your GoTM profile information. Organization and tour access are managed separately.</p>

<?php if($ok):?><div class="msg ok"><?=saas_h($ok)?></div><?php endif;?>
<?php if($error):?><div class="msg err"><?=saas_h($error)?></div><?php endif;?>

<form method="post">
<input type="hidden" name="csrf" value="<?=saas_h(saas_csrf())?>">
<input type="hidden" name="action" value="profile">

<label>Name
<input name="name" value="<?=saas_h($user['name']??'')?>" maxlength="180" autocomplete="name" required>
</label>

<label>Username
<input value="<?=saas_h($user['username']??'')?>" readonly aria-readonly="true">
<span class="hint">Username cannot be changed from Account settings.</span>
</label>

<label>Email
<input type="email" name="email" value="<?=saas_h($user['email']??'')?>" maxlength="190" autocomplete="email">
</label>

<div class="actions">
<button type="submit">Save changes</button>
<a href="tours.php">Cancel</a>
</div>
</form>
</section>

<section class="box">
<h2>Change password</h2>
<p class="muted">For security, enter your current password before choosing a new one.</p>

<form method="post">
<input type="hidden" name="csrf" value="<?=saas_h(saas_csrf())?>">
<input type="hidden" name="action" value="password">

<label>Current password
<input type="password" name="current_password" required autocomplete="current-password">
</label>

<label>New password
<input type="password" name="new_password" minlength="10" required autocomplete="new-password">
<span class="hint">Use at least 10 characters.</span>
</label>

<label>Confirm new password
<input type="password" name="confirm_password" minlength="10" required autocomplete="new-password">
</label>

<div class="actions">
<button type="submit">Update password</button>
</div>
</form>
</section>
</main>
</body>
</html>
