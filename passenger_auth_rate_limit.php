<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';

const PASSENGER_AUTH_RATE_WINDOW = 900;
const PASSENGER_AUTH_IP_LIMIT = 20;
const PASSENGER_AUTH_EMAIL_LIMIT = 8;
const PASSENGER_AUTH_BLOCK = 900;
const PASSENGER_RESET_IP_LIMIT = 10;
const PASSENGER_RESET_EMAIL_LIMIT = 5;
const PASSENGER_RESET_BLOCK = 900;
const PASSENGER_OAUTH_IP_LIMIT = 20;
const PASSENGER_OAUTH_BLOCK = 900;
const ADMIN_AUTH_IP_LIMIT = 20;
const ADMIN_AUTH_IDENTITY_LIMIT = 8;
const ADMIN_AUTH_BLOCK = 900;

function passenger_auth_rate_key(string $kind,string $value): string {
    return hash_hmac('sha256',strtoupper($kind).'|'.strtolower(trim($value)),saas_app_key());
}
function passenger_auth_client_ip(): string {
    return substr(trim((string)($_SERVER['REMOTE_ADDR']??'')),0,45);
}
function passenger_auth_rate_blocked(string $kind,string $value,int $limit): bool {
    if($value==='')return false;
    $q=saas_db()->prepare('SELECT attempts,window_started_at,blocked_until FROM passenger_auth_rate_limits WHERE rate_key=? LIMIT 1');
    $q->execute([passenger_auth_rate_key($kind,$value)]);$r=$q->fetch();
    if(!$r)return false;
    $now=time();
    if(!empty($r['blocked_until'])&&strtotime((string)$r['blocked_until'])>$now)return true;
    $window=strtotime((string)$r['window_started_at']);
    return $window>0&&$window+PASSENGER_AUTH_RATE_WINDOW>$now&&(int)$r['attempts']>=$limit;
}
function passenger_auth_rate_limited(string $email): bool {
    return passenger_auth_rate_blocked('IP',passenger_auth_client_ip(),PASSENGER_AUTH_IP_LIMIT)||passenger_auth_rate_blocked('EMAIL',$email,PASSENGER_AUTH_EMAIL_LIMIT);
}
function passenger_auth_rate_record(string $kind,string $value,int $limit,int $blockSeconds): void {
    if($value==='')return;
    $db=saas_db();$now=date('Y-m-d H:i:s');$key=passenger_auth_rate_key($kind,$value);
    $db->beginTransaction();
    try{
        $db->prepare('INSERT IGNORE INTO passenger_auth_rate_limits(rate_key,attempts,window_started_at,last_attempt_at) VALUES(?,0,?,?)')->execute([$key,$now,$now]);
        $q=$db->prepare('SELECT attempts,window_started_at,blocked_until FROM passenger_auth_rate_limits WHERE rate_key=? FOR UPDATE');
        $q->execute([$key]);$r=$q->fetch();
        if(!$r)throw new RuntimeException('Rate-limit state unavailable.');
        $started=strtotime((string)$r['window_started_at']);
        $inWindow=$started>0&&$started+PASSENGER_AUTH_RATE_WINDOW>$now;
        $attempts=$inWindow?(int)$r['attempts']:0;
        $window=$inWindow?(string)$r['window_started_at']:$now;
        $attempts++;
        $blocked=$attempts>=$limit?date('Y-m-d H:i:s',time()+$blockSeconds):null;
        $u=$db->prepare('UPDATE passenger_auth_rate_limits SET attempts=?,window_started_at=?,blocked_until=?,last_attempt_at=?,updated_at=NOW() WHERE rate_key=?');
        $u->execute([$attempts,$window,$blocked,$now,$key]);
        $db->commit();
    }catch(Throwable $e){
        if($db->inTransaction())$db->rollBack();
        throw $e;
    }
}
function passenger_auth_rate_fail(string $email): void {
    passenger_auth_rate_record('IP',passenger_auth_client_ip(),PASSENGER_AUTH_IP_LIMIT,PASSENGER_AUTH_BLOCK);
    passenger_auth_rate_record('EMAIL',$email,PASSENGER_AUTH_EMAIL_LIMIT,PASSENGER_AUTH_BLOCK);
}
function passenger_auth_rate_success(string $email): void {
    $db=saas_db();foreach([['IP',passenger_auth_client_ip()],['EMAIL',$email]] as [$kind,$value]){if($value==='')continue;$db->prepare('DELETE FROM passenger_auth_rate_limits WHERE rate_key=?')->execute([passenger_auth_rate_key($kind,$value)]);}
}
function passenger_reset_rate_limited(string $email): bool {
    return passenger_auth_rate_blocked('RESET_IP',passenger_auth_client_ip(),PASSENGER_RESET_IP_LIMIT)||passenger_auth_rate_blocked('RESET_EMAIL',$email,PASSENGER_RESET_EMAIL_LIMIT);
}
function passenger_reset_rate_fail(string $email): void {
    passenger_auth_rate_record('RESET_IP',passenger_auth_client_ip(),PASSENGER_RESET_IP_LIMIT,PASSENGER_RESET_BLOCK);
    passenger_auth_rate_record('RESET_EMAIL',$email,PASSENGER_RESET_EMAIL_LIMIT,PASSENGER_RESET_BLOCK);
}
function passenger_reset_rate_success(string $email): void {
    $db=saas_db();foreach([['RESET_IP',passenger_auth_client_ip()],['RESET_EMAIL',$email]] as [$kind,$value]){if($value==='')continue;$db->prepare('DELETE FROM passenger_auth_rate_limits WHERE rate_key=?')->execute([passenger_auth_rate_key($kind,$value)]);}
}
function passenger_oauth_rate_limited(): bool {
    return passenger_auth_rate_blocked('OAUTH_IP',passenger_auth_client_ip(),PASSENGER_OAUTH_IP_LIMIT);
}
function passenger_oauth_rate_fail(): void {
    passenger_auth_rate_record('OAUTH_IP',passenger_auth_client_ip(),PASSENGER_OAUTH_IP_LIMIT,PASSENGER_OAUTH_BLOCK);
}
function passenger_oauth_rate_success(): void {
    $value=passenger_auth_client_ip();
    if($value!=='')saas_db()->prepare('DELETE FROM passenger_auth_rate_limits WHERE rate_key=?')->execute([passenger_auth_rate_key('OAUTH_IP',$value)]);
}
function admin_auth_rate_limited(string $identity): bool {
    return passenger_auth_rate_blocked('ADMIN_IP',passenger_auth_client_ip(),ADMIN_AUTH_IP_LIMIT)||passenger_auth_rate_blocked('ADMIN_IDENTITY',$identity,ADMIN_AUTH_IDENTITY_LIMIT);
}
function admin_auth_rate_fail(string $identity): void {
    passenger_auth_rate_record('ADMIN_IP',passenger_auth_client_ip(),ADMIN_AUTH_IP_LIMIT,ADMIN_AUTH_BLOCK);
    passenger_auth_rate_record('ADMIN_IDENTITY',$identity,ADMIN_AUTH_IDENTITY_LIMIT,ADMIN_AUTH_BLOCK);
}
function admin_auth_rate_success(string $identity): void {
    $db=saas_db();foreach([['ADMIN_IP',passenger_auth_client_ip()],['ADMIN_IDENTITY',$identity]] as [$kind,$value]){if($value==='')continue;$db->prepare('DELETE FROM passenger_auth_rate_limits WHERE rate_key=?')->execute([passenger_auth_rate_key($kind,$value)]);}
}
function passenger_auth_rate_cleanup(): void {
    if(random_int(1,100)===1)saas_db()->exec("DELETE FROM passenger_auth_rate_limits WHERE updated_at<DATE_SUB(NOW(),INTERVAL 2 DAY)");
}
