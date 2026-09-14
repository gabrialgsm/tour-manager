<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap_saas.php';
function passenger_authenticated(): bool { return (int)($_SESSION['passenger_account_id']??0)>0; }
function passenger_account(): array { static $a=null; if($a!==null)return $a; $id=(int)($_SESSION['passenger_account_id']??0); if($id<=0)return $a=[]; $q=saas_db()->prepare("SELECT pa.id,pa.organization_id,pa.passenger_profile_id,pa.email,pa.status,pp.full_name,pp.phone,pp.email AS profile_email FROM passenger_accounts pa JOIN passenger_profiles pp ON pp.id=pa.passenger_profile_id WHERE pa.id=? AND pa.status='ACTIVE' LIMIT 1"); $q->execute([$id]); return $a=$q->fetch()?:[]; }
function passenger_require_login(): array { $a=passenger_account(); if(!$a){ unset($_SESSION['passenger_account_id'],$_SESSION['passenger_profile_id'],$_SESSION['passenger_organization_id']); saas_redirect('passenger_auth.php'); } return $a; }
function passenger_logout(): void { unset($_SESSION['passenger_account_id'],$_SESSION['passenger_profile_id'],$_SESSION['passenger_organization_id']); }
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
