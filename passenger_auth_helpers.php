<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap_saas.php';
function passenger_authenticated(): bool { return (int)($_SESSION['passenger_account_id']??0)>0; }
function passenger_account(): array { static $a=null; if($a!==null)return $a; $id=(int)($_SESSION['passenger_account_id']??0); if($id<=0)return $a=[]; $q=saas_db()->prepare("SELECT pa.id,pa.organization_id,pa.passenger_profile_id,pa.email,pa.status,pp.full_name,pp.phone,pp.email AS profile_email FROM passenger_accounts pa JOIN passenger_profiles pp ON pp.id=pa.passenger_profile_id WHERE pa.id=? AND pa.status='ACTIVE' LIMIT 1"); $q->execute([$id]); return $a=$q->fetch()?:[]; }
function passenger_require_login(): array { $a=passenger_account(); if(!$a){ unset($_SESSION['passenger_account_id'],$_SESSION['passenger_profile_id'],$_SESSION['passenger_organization_id']); saas_redirect('passenger_auth.php'); } return $a; }
function passenger_logout(): void { unset($_SESSION['passenger_account_id'],$_SESSION['passenger_profile_id'],$_SESSION['passenger_organization_id']); }
