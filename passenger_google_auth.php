<?php
declare(strict_types=1);
require __DIR__.'/bootstrap_saas.php';
require __DIR__.'/passenger_auth_helpers.php';

$db=saas_db();
$config=require __DIR__.'/config.php';
$clientId=trim((string)(getenv('GOOGLE_CLIENT_ID')?:''));
$clientSecret=trim((string)(getenv('GOOGLE_CLIENT_SECRET')?:''));
$baseUrl=rtrim((string)($config['app']['base_url']??''),'/');
$redirectUri=$baseUrl.'/passenger_google_auth.php';
$action=($_GET['action']??'')==='callback'?'callback':'start';

function google_fail(string $message): never { http_response_code(400); echo '<!doctype html><html><body style="font-family:Arial;padding:40px"><h2>Google sign-in unavailable</h2><p>'.saas_h($message).'</p><p><a href="passenger_auth.php">Back to login</a></p></body></html>'; exit; }
function google_http_json(string $url,array $post=[]): array {
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_HTTPHEADER=>['Accept: application/json']]);
    if($post){curl_setopt($ch,CURLOPT_POST,true);curl_setopt($ch,CURLOPT_POSTFIELDS,http_build_query($post));curl_setopt($ch,CURLOPT_HTTPHEADER,['Accept: application/json','Content-Type: application/x-www-form-urlencoded']);}
    $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);
    if($body===false||$status<200||$status>=300)throw new RuntimeException('Google authentication service could not be reached.'.($err?' '.$err:''));
    $data=json_decode((string)$body,true);if(!is_array($data))throw new RuntimeException('Invalid response from Google.');return $data;
}
if($clientId===''||$clientSecret===''||$baseUrl==='')google_fail('Google login is not configured. Set GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET and APP_BASE_URL on the server.');

if($action==='start'){
    $state=bin2hex(random_bytes(32));
    $_SESSION['passenger_google_oauth_state']=$state;
    $_SESSION['passenger_google_oauth_started']=time();
    $slug=trim((string)($_GET['slug']??''));$token=trim((string)($_GET['token']??''));
    unset($_SESSION['passenger_google_booking']);
    if($slug!==''&&preg_match('/^[a-f0-9]{64}$/',$token)){
        $q=$db->prepare("SELECT tp.id,tp.passenger_profile_id,tp.organization_id,t.slug FROM tour_passengers tp JOIN tours t ON t.id=tp.tour_id JOIN organizations o ON o.id=t.organization_id WHERE t.slug=? AND tp.booking_access_token_hash=? AND tp.booking_access_token_revoked_at IS NULL AND tp.status='ACTIVE' AND o.status='ACTIVE' LIMIT 1");
        $q->execute([$slug,hash('sha256',$token)]);$booking=$q->fetch();
        if($booking)$_SESSION['passenger_google_booking']=['tp_id'=>(int)$booking['id'],'profile_id'=>(int)$booking['passenger_profile_id'],'organization_id'=>(int)$booking['organization_id'],'slug'=>$booking['slug']];
    }
    $params=['client_id'=>$clientId,'redirect_uri'=>$redirectUri,'response_type'=>'code','scope'=>'openid email profile','access_type'=>'online','state'=>$state,'prompt'=>'select_account'];
    header('Location: https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query($params));exit;
}

$state=(string)($_GET['state']??'');$expected=(string)($_SESSION['passenger_google_oauth_state']??'');$started=(int)($_SESSION['passenger_google_oauth_started']??0);
unset($_SESSION['passenger_google_oauth_state'],$_SESSION['passenger_google_oauth_started']);
if($state===''||$expected===''||!hash_equals($expected,$state)||$started<time()-600)google_fail('The Google sign-in session expired. Please try again.');
if(isset($_GET['error']))google_fail('Google sign-in was cancelled or denied.');
$code=(string)($_GET['code']??'');if($code==='')google_fail('Google did not return an authorization code.');
try{
    $tokenResponse=google_http_json('https://oauth2.googleapis.com/token',['code'=>$code,'client_id'=>$clientId,'client_secret'=>$clientSecret,'redirect_uri'=>$redirectUri,'grant_type'=>'authorization_code']);
    $idToken=(string)($tokenResponse['id_token']??'');if($idToken==='')throw new RuntimeException('Google did not return an identity token.');
    $info=google_http_json('https://oauth2.googleapis.com/tokeninfo?id_token='.rawurlencode($idToken));
    if((string)($info['aud']??'')!==$clientId||($info['iss']??'')!=='https://accounts.google.com')throw new RuntimeException('Google identity verification failed.');
    $email=strtolower(trim((string)($info['email']??'')));$subject=trim((string)($info['sub']??''));
    if($email===''||$subject===''||filter_var($email,FILTER_VALIDATE_EMAIL)===false||($info['email_verified']??'false')!=='true')throw new RuntimeException('A verified Google email is required.');
    $booking=$_SESSION['passenger_google_booking']??null;unset($_SESSION['passenger_google_booking']);

    $db->beginTransaction();
    $q=$db->prepare("SELECT pa.id,pa.organization_id,pa.passenger_profile_id,pa.email,pa.status FROM passenger_auth_identities i JOIN passenger_accounts pa ON pa.id=i.passenger_account_id WHERE i.provider='GOOGLE' AND i.provider_subject=? LIMIT 2 FOR UPDATE");$q->execute([$subject]);$identityRows=$q->fetchAll();
    if(count($identityRows)>1)throw new RuntimeException('This Google identity is linked more than once. Contact the organizer.');
    $account=null;
    if($identityRows){$account=$identityRows[0];if($account['status']!=='ACTIVE')throw new RuntimeException('This passenger account is not active.');if(strtolower((string)$account['email'])!==$email)throw new RuntimeException('Google email does not match the linked account.');}
    elseif($booking){
        $q=$db->prepare("SELECT pa.id,pa.organization_id,pa.passenger_profile_id,pa.email,pa.status FROM passenger_accounts pa WHERE pa.organization_id=? AND pa.passenger_profile_id=? LIMIT 1 FOR UPDATE");$q->execute([$booking['organization_id'],$booking['profile_id']]);$account=$q->fetch()?:null;
        if($account&&$account['status']!=='ACTIVE')throw new RuntimeException('The passenger account is not active.');
        if($account&&strtolower((string)$account['email'])!==$email)throw new RuntimeException('This Google email does not match the passenger profile email.');
        if(!$account){
            $q=$db->prepare("SELECT email FROM passenger_accounts WHERE organization_id=? AND LOWER(email)=LOWER(?) LIMIT 1 FOR UPDATE");$q->execute([$booking['organization_id'],$email]);if($q->fetch())throw new RuntimeException('This email already belongs to another passenger account in this organization.');
            $hash=password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT);
            $q=$db->prepare("INSERT INTO passenger_accounts(organization_id,passenger_profile_id,email,password_hash,status,email_verified_at) VALUES(?,?,?,?, 'ACTIVE',NOW())");$q->execute([$booking['organization_id'],$booking['profile_id'],$email,$hash]);$account=['id'=>(int)$db->lastInsertId(),'organization_id'=>$booking['organization_id'],'passenger_profile_id'=>$booking['profile_id'],'email'=>$email,'status'=>'ACTIVE'];
        }
    } else {
        $q=$db->prepare("SELECT pa.id,pa.organization_id,pa.passenger_profile_id,pa.email,pa.status FROM passenger_accounts pa WHERE LOWER(pa.email)=LOWER(?) LIMIT 2 FOR UPDATE");$q->execute([$email]);$rows=$q->fetchAll();if(count($rows)!==1)throw new RuntimeException('We could not uniquely identify your passenger account. Open your tour booking link first, or contact the organizer.');$account=$rows[0];if($account['status']!=='ACTIVE')throw new RuntimeException('This passenger account is not active.');
    }
    if(!$account)throw new RuntimeException('Passenger account could not be resolved.');
    $q=$db->prepare("SELECT passenger_profile_id,organization_id FROM passenger_accounts WHERE id=? LIMIT 1 FOR UPDATE");$q->execute([(int)$account['id']]);$verifiedAccount=$q->fetch();if(!$verifiedAccount)throw new RuntimeException('Passenger account not found.');
    if($booking&&((int)$verifiedAccount['organization_id']!==(int)$booking['organization_id']||(int)$verifiedAccount['passenger_profile_id']!==(int)$booking['profile_id']))throw new RuntimeException('This Google account cannot be linked to this booking.');
    $q=$db->prepare("SELECT id FROM passenger_auth_identities WHERE passenger_account_id=? AND provider='GOOGLE' LIMIT 1 FOR UPDATE");$q->execute([(int)$account['id']]);$existingIdentity=$q->fetchColumn();
    if($existingIdentity){$q=$db->prepare("UPDATE passenger_auth_identities SET provider_subject=?,provider_email=?,updated_at=NOW() WHERE id=?");$q->execute([$subject,$email,(int)$existingIdentity]);}
    else {$q=$db->prepare("SELECT id FROM passenger_auth_identities WHERE provider='GOOGLE' AND provider_subject=? LIMIT 1 FOR UPDATE");$q->execute([$subject]);if($q->fetch())throw new RuntimeException('This Google account is already linked to another passenger.');$q=$db->prepare("INSERT INTO passenger_auth_identities(passenger_account_id,provider,provider_subject,provider_email) VALUES(?, 'GOOGLE',?,?)");$q->execute([(int)$account['id'],$subject,$email]);}
    $q=$db->prepare('UPDATE passenger_accounts SET last_login_at=NOW(),email_verified_at=COALESCE(email_verified_at,NOW()) WHERE id=?');$q->execute([(int)$account['id']]);
    $db->commit();
    session_regenerate_id(true);$_SESSION['passenger_account_id']=(int)$account['id'];$_SESSION['passenger_profile_id']=(int)$verifiedAccount['passenger_profile_id'];$_SESSION['passenger_organization_id']=(int)$verifiedAccount['organization_id'];
    saas_redirect('passenger_dashboard.php');
}catch(Throwable $e){if($db->inTransaction())$db->rollBack();google_fail($e->getMessage());}
