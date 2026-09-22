<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
saas_require_login();
$orgId=saas_require_organization();$id=(int)($_GET['id']??0);$db=saas_db();
$q=$db->prepare("SELECT t.id FROM tours t INNER JOIN tour_members tm ON tm.tour_id=t.id WHERE t.id=? AND t.organization_id=? AND tm.user_id=? AND tm.status='ACTIVE' LIMIT 1");$q->execute([$id,$orgId,saas_user_id()]);
if(!$q->fetchColumn()){http_response_code(403);exit('Tour not found or access denied.');}
saas_set_context($orgId,$id);
$next=(string)($_GET['next']??'dashboard.php');$allowed=['dashboard.php','tour_settings.php','passengers.php','rooms.php','buses.php','tour_team.php'];
if(!in_array($next,$allowed,true))$next='dashboard.php';
saas_redirect($next);
