<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap_saas.php';

const PASSENGER_SESSION_TTL = 2592000; // 30 days absolute lifetime.
const PASSENGER_SESSION_IDLE = 43200;  // 12 hours inactivity timeout.

function passenger_session_token(): string { return (string)($_SESSION['passenger_session_token'] ?? ''); }

function passenger_clear_session(): void {
    unset($_SESSION['passenger_account_id'],$_SESSION['passenger_profile_id'],$_SESSION['passenger_organization_id'],$_SESSION['passenger_session_token']);
}

function passenger_start_session(int $accountId): void {
    if($accountId<=0) throw new RuntimeException('Invalid passenger account.');
    $db=saas_db();
    $token=bin2hex(random_bytes(32));
    $hash=hash('sha256',$token);
    $ip=substr((string)($_SERVER['REMOTE_ADDR']??''),0,45);
    $ua=substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,500);
    $q=$db->prepare("INSERT INTO passenger_sessions(passenger_account_id,session_hash,expires_at,ip_address,user_agent) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 30 DAY),?,?)");
    $q->execute([$accountId,$hash,$ip!==''?$ip:null,$ua!==''?$ua:null]);
    $_SESSION['passenger_session_token']=$token;
}

function passenger_revoke_current_session(): void {
    $token=passenger_session_token();
    if($token!==''){
        $q=saas_db()->prepare('UPDATE passenger_sessions SET revoked_at=COALESCE(revoked_at,NOW()) WHERE session_hash=?');
        $q->execute([hash('sha256',$token)]);
    }
    passenger_clear_session();
}

function passenger_account(): array {
    static $a=null;
    if($a!==null)return $a;
    $db=saas_db();
    $token=passenger_session_token();
    $id=(int)($_SESSION['passenger_account_id']??0);
    if($token!==''){
        $q=$db->prepare("SELECT pa.id,pa.organization_id,pa.passenger_profile_id,pa.email,pa.status,pp.full_name,pp.phone,pp.email AS profile_email FROM passenger_sessions ps JOIN passenger_accounts pa ON pa.id=ps.passenger_account_id JOIN passenger_profiles pp ON pp.id=pa.passenger_profile_id WHERE ps.session_hash=? AND ps.revoked_at IS NULL AND ps.expires_at>NOW() AND ps.last_seen_at>=DATE_SUB(NOW(),INTERVAL 12 HOUR) AND pa.status='ACTIVE' LIMIT 1");
        $q->execute([hash('sha256',$token)]);
        $a=$q->fetch()?:[];
        if($a){
            if($id>0 && $id!==(int)$a['id']){ passenger_revoke_current_session(); return $a=[]; }
            $q=$db->prepare("UPDATE passenger_sessions SET last_seen_at=NOW() WHERE session_hash=? AND last_seen_at<DATE_SUB(NOW(),INTERVAL 5 MINUTE)");
            $q->execute([hash('sha256',$token)]);
            $_SESSION['passenger_account_id']=(int)$a['id'];$_SESSION['passenger_profile_id']=(int)$a['passenger_profile_id'];$_SESSION['passenger_organization_id']=(int)$a['organization_id'];
            return $a;
        }
        passenger_clear_session();
        return $a=[];
    }
    // Backward-compatible one-time upgrade for sessions created before DB-backed sessions.
    if($id<=0)return $a=[];
    $q=$db->prepare("SELECT pa.id,pa.organization_id,pa.passenger_profile_id,pa.email,pa.status,pp.full_name,pp.phone,pp.email AS profile_email FROM passenger_accounts pa JOIN passenger_profiles pp ON pp.id=pa.passenger_profile_id WHERE pa.id=? AND pa.status='ACTIVE' LIMIT 1");
    $q->execute([$id]);
    $a=$q->fetch()?:[];
    if($a){
        try{ passenger_start_session((int)$a['id']); }
        catch(Throwable $e){ passenger_clear_session(); return $a=[]; }
    } else passenger_clear_session();
    return $a;
}

function passenger_authenticated(): bool { return passenger_account()!==[]; }

function passenger_require_login(): array {
    $a=passenger_account();
    if(!$a){ passenger_clear_session(); saas_redirect('passenger_auth.php'); }
    return $a;
}

function passenger_logout(): void { passenger_revoke_current_session(); }

function passenger_booking(int $tourId): array {
    $a=passenger_require_login();
    if($tourId<=0){ http_response_code(404); exit('Tour not found.'); }
    $q=saas_db()->prepare("SELECT tp.id tp_id,tp.status,tp.fee,tp.discount,pp.full_name,pp.phone,pp.email profile_email,t.id tour_id,t.name tour_name,t.slug,t.start_date,t.end_date,t.booking_fee,o.id organization_id,o.currency,o.name organization_name FROM tour_passengers tp JOIN passenger_profiles pp ON pp.id=tp.passenger_profile_id JOIN tours t ON t.id=tp.tour_id JOIN organizations o ON o.id=t.organization_id WHERE tp.tour_id=? AND tp.passenger_profile_id=? AND t.organization_id=? AND o.status='ACTIVE' LIMIT 1");
    $q->execute([$tourId,(int)$a['passenger_profile_id'],(int)$a['organization_id']]);
    $b=$q->fetch();
    if(!$b||$b['status']!=='ACTIVE'){ http_response_code(404); exit('Active booking not found.'); }
    $b['account_id']=(int)$a['id']; $b['passenger_profile_id']=(int)$a['passenger_profile_id'];
    return $b;
}
