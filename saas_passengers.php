<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap_saas.php';
saas_require_login();
$tourId=saas_require_tour();
$orgId=saas_current_organization_id();
saas_require_permission('passenger.create');
$db=saas_db();
$error=''; $ok='';

$search=trim((string)($_GET['q']??''));
$profiles=[];
if($search!==''){
    $like='%'.$search.'%';
    $q=$db->prepare("SELECT id,full_name,phone,email FROM passenger_profiles WHERE organization_id=? AND (full_name LIKE ? OR phone LIKE ? OR email LIKE ?) ORDER BY full_name LIMIT 20");
    $q->execute([$orgId,$like,$like,$like]);
    $profiles=$q->fetchAll();
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        saas_check_csrf();
        $profileId=(int)($_POST['profile_id']??0);
        $name=trim((string)($_POST['full_name']??''));
        $phone=trim((string)($_POST['phone']??''));
        $email=trim((string)($_POST['email']??''));
        $fee=max(0,(float)($_POST['fee']??0));
        $discount=max(0,(float)($_POST['discount']??0));

        if($profileId>0){
            $q=$db->prepare('SELECT id,full_name,phone,email FROM passenger_profiles WHERE id=? AND organization_id=?');
            $q->execute([$profileId,$orgId]);
            $profile=$q->fetch();
            if(!$profile) throw new RuntimeException('Passenger profile not found.');
        }else{
            if($name==='') throw new RuntimeException('Passenger name is required.');
            if($phone==='') $phone=null;
            if($email==='') $email=null;
            $db->beginTransaction();
            $q=$db->prepare('INSERT INTO passenger_profiles(organization_id,full_name,phone,email) VALUES(?,?,?,?)');
            $q->execute([$orgId,$name,$phone,$email]);
            $profileId=(int)$db->lastInsertId();
            $db->commit();
        }

        if($discount>$fee) throw new RuntimeException('Discount cannot exceed fee.');

        $db->beginTransaction();
        $q=$db->prepare('SELECT id FROM tour_passengers WHERE tour_id=? AND passenger_profile_id=? FOR UPDATE');
        $q->execute([$tourId,$profileId]);
        if($q->fetch()) throw new RuntimeException('This passenger is already registered for this tour.');

        $q=$db->prepare("INSERT INTO tour_passengers(tour_id,passenger_profile_id,registration_source,fee,discount,status) VALUES(?,?, 'ADMIN',?,?, 'ACTIVE')");
        $q->execute([$tourId,$profileId,$fee,$discount]);
        $id=(int)$db->lastInsertId();
        $db->commit();
        saas_audit('passenger.created','tour_passenger',$id,json_encode(['profile_id'=>$profileId,'reused_profile'=>$profileId>0],JSON_UNESCAPED_UNICODE));
        $ok='Passenger registered successfully.';
    }catch(Throwable $e){
        if($db->inTransaction()) $db->rollBack();
        $error=$e->getMessage()?:'Could not register passenger.';
    }
}

$q=$db->prepare('SELECT tp.id,tp.status,tp.fee,tp.discount,pp.full_name,pp.phone,pp.email FROM tour_passengers tp JOIN passenger_profiles pp ON pp.id=tp.passenger_profile_id WHERE tp.tour_id=? AND pp.organization_id=? ORDER BY tp.id DESC');
$q->execute([$tourId,$orgId]);
$passengers=$q->fetchAll();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Passengers</title><style>body{font-family:system-ui;background:#f5f7fb;margin:0;color:#172033}.wrap{max-width:1150px;margin:30px auto;padding:0 18px}.grid{display:grid;grid-template-columns:390px 1fr;gap:20px}.box{background:#fff;padding:22px;border-radius:14px;box-shadow:0 5px 20px #0001}label{display:block;margin:11px 0 5px;font-weight:600}input,button{width:100%;box-sizing:border-box;padding:11px;border:1px solid #ccd3df;border-radius:8px}button{margin-top:15px;background:#172033;color:#fff;border:0;font-weight:700;cursor:pointer}.secondary{background:#eef2f7;color:#172033;margin-top:8px}.err{background:#fff0f0;color:#a22;padding:10px;border-radius:8px;margin-bottom:12px}.ok{background:#eefbf2;color:#176b35;padding:10px;border-radius:8px;margin-bottom:12px}.search{display:flex;gap:8px}.search input{flex:1}.results{margin-top:12px;border:1px solid #e3e7ee;border-radius:10px;overflow:hidden}.profile{display:block;padding:11px 12px;border-bottom:1px solid #eee;text-decoration:none;color:#172033}.profile:last-child{border-bottom:0}.profile:hover{background:#f7f9fc}.muted{color:#667085;font-size:13px}table{width:100%;border-collapse:collapse}th,td{padding:10px;border-bottom:1px solid #eee;text-align:left;vertical-align:top}.pill{display:inline-block;padding:4px 8px;border-radius:999px;background:#eef2f7;font-size:12px;font-weight:700}@media(max-width:800px){.grid{grid-template-columns:1fr}}@media(max-width:600px){table{font-size:13px;display:block;overflow-x:auto}}</style></head><body><main class="wrap"><p><a href="saas_dashboard.php">← Dashboard</a></p><h1>Passengers</h1><?php if($error):?><div class="err"><?=saas_h($error)?></div><?php endif;?><?php if($ok):?><div class="ok"><?=saas_h($ok)?></div><?php endif;?><div class="grid"><section class="box"><h2>Register passenger</h2><p class="muted">Search an existing organization passenger to reuse their profile, or create a new profile.</p><form method="get"><label>Find existing passenger</label><div class="search"><input name="q" value="<?=saas_h($search)?>" placeholder="Name, phone or email"><button type="submit">Search</button></div></form><?php if($search!==''):?><div class="results"><?php if($profiles): foreach($profiles as $p):?><a class="profile" href="#" onclick="useProfile(<?= (int)$p['id']?>,<?=json_encode($p['full_name'])?>,<?=json_encode($p['phone']??'')?>,<?=json_encode($p['email']??'')?>);return false;"><strong><?=saas_h($p['full_name'])?></strong><br><span class="muted"><?=saas_h($p['phone']??'')?><?=!empty($p['email'])?' · '.saas_h($p['email']):''?></span></a><?php endforeach; else:?><div class="profile">No matching passenger profile found.</div><?php endif;?></div><?php endif;?><form method="post" id="passengerForm"><input type="hidden" name="csrf" value="<?=saas_h(saas_csrf())?>"><input type="hidden" name="profile_id" id="profile_id" value="0"><div id="reuseNote" class="muted" style="margin-top:12px"></div><label>Full name</label><input name="full_name" id="full_name" required><label>Phone</label><input name="phone" id="phone"><label>Email</label><input type="email" name="email" id="email"><label>Tour fee</label><input type="number" min="0" step="0.01" name="fee" value="0"><label>Discount</label><input type="number" min="0" step="0.01" name="discount" value="0"><button>Register passenger</button></form></section><section class="box"><h2>Registered passengers</h2><table><tr><th>Name</th><th>Phone</th><th>Fee</th><th>Due</th><th>Status</th></tr><?php foreach($passengers as $p): $due=max(0,(float)$p['fee']-(float)$p['discount']);$pq=$db->prepare('SELECT COALESCE(SUM(amount),0) FROM payments WHERE tour_passenger_id=?');$pq->execute([$p['id']]);$due-=min($due,(float)$pq->fetchColumn());?><tr><td><?=saas_h($p['full_name'])?></td><td><?=saas_h($p['phone']??'')?></td><td><?=number_format((float)$p['fee'],2)?></td><td><?=number_format(max(0,$due),2)?></td><td><span class="pill"><?=saas_h($p['status'])?></span></td></tr><?php endforeach;?></table><?php if(!$passengers):?><p>No passengers registered yet.</p><?php endif;?></section></div></main><script>function useProfile(id,name,phone,email){document.getElementById('profile_id').value=id;document.getElementById('full_name').value=name;document.getElementById('phone').value=phone;document.getElementById('email').value=email;document.getElementById('reuseNote').textContent='Existing passenger profile selected. Only profile data is reused; this tour gets a new booking, payment, seat, room and ticket state.';window.scrollTo({top:document.getElementById('passengerForm').offsetTop-20,behavior:'smooth'});}</script></body></html>