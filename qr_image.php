<?php
require __DIR__.'/bootstrap.php';
$pid=(int)($_GET['id']??0);
$size=max(100,min(800,(int)($_GET['size']??180)));
if($pid<=0){http_response_code(400);exit('Invalid QR request');}
$url=qr_url($pid);
$cacheDir=__DIR__.'/storage/qr';
if(!is_dir($cacheDir)) @mkdir($cacheDir,0750,true);
$key=hash('sha256',$url.'|'.$size);
$file=$cacheDir.'/'.$key.'.png';

if(is_file($file) && filemtime($file)>time()-86400*30){
    header('Content-Type: image/png'); header('Cache-Control: public,max-age=86400');
    readfile($file); exit;
}
$api='https://api.qrserver.com/v1/create-qr-code/?size='.$size.'x'.$size.'&margin=8&data='.rawurlencode($url);
$data=false;
if(function_exists('curl_init')){
    $ch=curl_init($api);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_USERAGENT=>'GMJS-Tour-Manager/1.0']);
    $data=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    if($code!==200)$data=false;
}
if($data===false && ini_get('allow_url_fopen')){
    $ctx=stream_context_create(['http'=>['timeout'=>15,'header'=>"User-Agent: GMJS-Tour-Manager/1.0\r\n"],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
    $data=@file_get_contents($api,false,$ctx);
}
if($data===false){
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "QR image service is temporarily unavailable. Check the server's outbound HTTPS connection.";
    exit;
}
@file_put_contents($file,$data,LOCK_EX);
header('Content-Type: image/png'); header('Cache-Control: public,max-age=86400');
echo $data;
