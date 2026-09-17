<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap_saas.php';

function passenger_google_http_json(string $url): array {
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_HTTPHEADER=>['Accept: application/json']]);
    $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    if($body===false||$status<200||$status>=300)throw new RuntimeException('Google identity verification service is unavailable.');
    $data=json_decode((string)$body,true);
    if(!is_array($data))throw new RuntimeException('Invalid Google identity response.');
    return $data;
}
function passenger_google_b64url(string $v): string { return base64_decode(strtr($v,'-_','+/').'===',true)?:''; }
function passenger_google_id_token(string $jwt,string $clientId): array {
    $parts=explode('.',$jwt);if(count($parts)!==3)throw new RuntimeException('Invalid Google identity token.');
    $header=json_decode(passenger_google_b64url($parts[0]),true);$payload=json_decode(passenger_google_b64url($parts[1]),true);
    if(!is_array($header)||!is_array($payload)||($header['alg']??'')!=='RS256'||empty($header['kid']))throw new RuntimeException('Invalid Google identity token.');
    $keys=passenger_google_http_json('https://www.googleapis.com/oauth2/v3/certs');$key=null;
    foreach(($keys['keys']??[]) as $k){if(($k['kid']??'')===$header['kid']){$key=$k;break;}}
    if(!$key||empty($key['n'])||empty($key['e']))throw new RuntimeException('Google signing key is unavailable.');
    $mod=passenger_google_b64url((string)$key['n']);$exp=passenger_google_b64url((string)$key['e']);
    $der="\x30";
    $encodeInt=function(string $x): string {$x=ltrim($x,"\0");if($x===''||ord($x[0])>127)$x="\0".$x;$l=strlen($x);return "\x02".($l<128?chr($l):"\x81".chr($l)).$x;};
    $rsa=$encodeInt($mod).$encodeInt($exp);$der.=(strlen($rsa)<128?chr(strlen($rsa)):"\x81".chr(strlen($rsa))).$rsa;$der="\x30".(strlen($der)<128?chr(strlen($der)):"\x81".chr(strlen($der))).$der;$der="\x00".$der;$der="\x03".(strlen($der)<128?chr(strlen($der)):"\x82".pack('n',strlen($der))).$der;
    $oid="\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";$spki="\x30".(strlen($oid.$der)<128?chr(strlen($oid.$der)):"\x81".chr(strlen($oid.$der))).$oid.$der;$pem="-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode("\x30".(strlen($spki)<128?chr(strlen($spki)):"\x81".chr(strlen($spki))).$spki),64,"\n")."-----END PUBLIC KEY-----\n";
    $signature=passenger_google_b64url($parts[2]);if(strlen($signature)!==256||!openssl_verify($parts[0].'.'.$parts[1],$signature,$pem,OPENSSL_ALGO_SHA256))throw new RuntimeException('Google identity verification failed.');
    $now=time();$iss=$payload['iss']??'';$aud=$payload['aud']??'';if($aud!==$clientId||!in_array($iss,['https://accounts.google.com','accounts.google.com'],true))throw new RuntimeException('Google identity verification failed.');
    if(!isset($payload['exp'])||!is_numeric($payload['exp'])||(int)$payload['exp']<$now||isset($payload['iat'])&&(!is_numeric($payload['iat'])||(int)$payload['iat']>$now+60))throw new RuntimeException('Google identity token expired or invalid.');
    return $payload;
}
