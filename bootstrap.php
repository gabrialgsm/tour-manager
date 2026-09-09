<?php
declare(strict_types=1);
$config=require __DIR__.'/config.php';
date_default_timezone_set($config['app']['timezone']??'Asia/Dhaka');
session_name('GMJSSESSID');
session_set_cookie_params(['httponly'=>true,'secure'=>(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'),'samesite'=>'Lax']);
session_start();
function db():PDO{static $p;global $config;if(!$p){$d=$config['db'];$p=new PDO("mysql:host={$d['host']};dbname={$d['name']};charset={$d['charset']}",$d['user'],$d['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);}return $p;}
function h($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function redirect(string $u):never{header('Location: '.$u);exit;}
function is_logged_in():bool{return !empty($_SESSION['admin_id']);}
function require_login():void{if(!is_logged_in())redirect('login.php');}
function csrf():string{if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));return $_SESSION['csrf'];}
function check_csrf():void{if(!hash_equals($_SESSION['csrf']??'',$_POST['csrf']??'')){http_response_code(419);exit('Invalid CSRF token');}}
function flash(?string $type=null,?string $msg=null){if($msg!==null){$_SESSION['flash']=['type'=>$type,'msg'=>$msg];return;}$f=$_SESSION['flash']??null;unset($_SESSION['flash']);return $f;}
function money($n):string{return '৳'.number_format((float)$n,2);}
function room_label(string $v):string{return ['AC_COUPLE'=>'AC Couple','NON_AC_COUPLE'=>'Non-AC Couple','AC_4_BED'=>'AC 4 Bed','NON_AC_4_BED'=>'Non-AC 4 Bed','AC_TWIN_BED'=>'AC Twin Bed'][$v]??$v;}

function setting(string $key,$default=''){
    static $cache=[];
    if(array_key_exists($key,$cache)) return $cache[$key];
    try{
        $q=db()->prepare("SELECT setting_value FROM settings WHERE setting_key=? LIMIT 1");
        $q->execute([$key]);
        $v=$q->fetchColumn();
        return $cache[$key]=($v===false ? $default : $v);
    }catch(Throwable $e){
        return $cache[$key]=$default;
    }
}

function audit(string $action,?string $type=null,?int $id=null,?string $details=null):void{try{$q=db()->prepare("INSERT INTO audit_log(admin_id,action,entity_type,entity_id,details) VALUES(?,?,?,?,?)");$q->execute([$_SESSION['admin_id']??null,$action,$type,$id,$details]);}catch(Throwable $e){}}
function tours():array{return db()->query("SELECT * FROM tours ORDER BY id DESC")->fetchAll();}
function current_tour_id():int{$id=(int)($_SESSION['tour_id']??0);if($id){$q=db()->prepare("SELECT id FROM tours WHERE id=?");$q->execute([$id]);if($q->fetch())return $id;} $id=(int)(db()->query("SELECT id FROM tours WHERE status='ACTIVE' ORDER BY id DESC LIMIT 1")->fetchColumn()?:0);if($id)$_SESSION['tour_id']=$id;return $id;}
function require_tour():int{$id=current_tour_id();if(!$id)redirect('tour_manage.php');return $id;}
function tour_row(int $id):array{$q=db()->prepare("SELECT * FROM tours WHERE id=?");$q->execute([$id]);return $q->fetch()?:[];}

function qr_secret():string{
    global $config;
    return hash('sha256', (string)($config['db']['pass']??'').'|'.($config['app']['name']??'GMJS').'|GMJS-QR-V1');
}
function qr_token(int $passengerId):string{
    $payload=(string)$passengerId;
    $sig=hash_hmac('sha256',$payload,qr_secret());
    return $payload.'.'.$sig;
}
function qr_verify(string $token):int{
    $parts=explode('.',$token,2);
    if(count($parts)!==2 || !ctype_digit($parts[0])) return 0;
    if(!hash_equals(hash_hmac('sha256',$parts[0],qr_secret()),$parts[1])) return 0;
    return (int)$parts[0];
}
function app_base_url():string{
    global $config;
    if(!empty($config['app']['base_url'])) return rtrim($config['app']['base_url'],'/');
    $scheme=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http';
    $host=$_SERVER['HTTP_HOST']??'localhost';
    $base=rtrim(dirname($_SERVER['SCRIPT_NAME']??'/'),'/\\');
    return $scheme.'://'.$host.($base==='/'?'':$base);
}
function qr_url(int $passengerId):string{
    return app_base_url().'/qr.php?t='.rawurlencode(qr_token($passengerId));
}
function qr_image_url(int $passengerId,int $size=180):string{
    return app_base_url().'/qr_image.php?id='.$passengerId.'&size='.$size;
}
function passenger_payment_totals(int $passengerId):array{
    $q=db()->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE passenger_id=?");
    $q->execute([$passengerId]);
    $paid=(float)$q->fetchColumn();
    return [$paid,$paid];
}
function passenger_checked_in(int $passengerId):bool{
    try{
        $q=db()->prepare("SELECT 1 FROM audit_log WHERE action='CHECK_IN' AND entity_type='passenger' AND entity_id=? ORDER BY id DESC LIMIT 1");
        $q->execute([$passengerId]);
        return (bool)$q->fetchColumn();
    }catch(Throwable $e){return false;}
}

function public_contact(array $t):array{
  $contacts=[];
  try{ $q=db()->prepare("SELECT id,label,phone,whatsapp FROM tour_contacts WHERE tour_id=? AND active=1 ORDER BY sort_order,id"); $q->execute([(int)$t['id']]); $contacts=$q->fetchAll(); }catch(Throwable $e){}
  if(!$contacts && (($t['contact_phone']??'')||($t['contact_whatsapp']??''))) $contacts=[['id'=>0,'label'=>'Contact','phone'=>$t['contact_phone']??'','whatsapp'=>$t['contact_whatsapp']??'']];
  return ['contacts'=>$contacts,'text'=>$t['contact_text']??'Booking করতে আমাদের সাথে যোগাযোগ করুন।'];
}
function flash_render():void{if($f=flash()):?><div class="alert <?=$f['type']==='danger'?'danger':''?>"><?=h($f['msg'])?></div><?php endif;}

if (!is_dir(__DIR__.'/storage')) { @mkdir(__DIR__.'/storage',0750,true); }
ini_set('display_errors','0');
ini_set('log_errors','1');
ini_set('error_log',__DIR__.'/storage/php-error.log');
set_exception_handler(function(Throwable $e){
    error_log((string)$e);
    http_response_code(500);
    if (defined('JSON_REQUEST') && JSON_REQUEST) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false,'error'=>'An internal server error occurred.']);
        exit;
    }
    echo '<!doctype html><html><head><meta charset="utf-8"><title>GMJS Tour Manager</title><link rel="stylesheet" href="assets/app.css"></head><body class="login-page"><div class="login-card"><div class="brand">GMJS TOUR MANAGER</div><h1>Something went wrong</h1><p class="muted">The system could not complete this request. Please try again or contact the administrator.</p><a class="btn primary wide" href="dashboard.php">Back to Dashboard</a></div></body></html>';
});
