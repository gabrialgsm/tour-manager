<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap_saas.php';

function saas_feature_catalog(): array {
    return [
        'food' => ['label'=>'Food / Menu','kind'=>'select','description'=>'Meal or menu selection for each passenger.'],
        'tshirt' => ['label'=>'T-Shirt','kind'=>'select','description'=>'Collect passenger T-shirt size.'],
        'train' => ['label'=>'Train Ticket','kind'=>'text','description'=>'Optional train ticket details.'],
        'launch' => ['label'=>'Launch Ticket','kind'=>'text','description'=>'Optional launch ticket details.'],
        'custom' => ['label'=>'Custom Feature','kind'=>'custom','description'=>'A flexible custom option for this tour.'],
    ];
}
function saas_feature(int $tourId,string $key): array {
    $db=saas_db();$q=$db->prepare('SELECT * FROM tour_features WHERE tour_id=? AND feature_key=? LIMIT 1');$q->execute([$tourId,$key]);return $q->fetch()?:[];
}
function saas_enabled_features(int $tourId): array {
    $q=saas_db()->prepare('SELECT tf.*,tc.label,tc.kind,tc.description FROM tour_features tf LEFT JOIN (SELECT ? tour_id) x ON 1=1 WHERE tf.tour_id=? AND tf.enabled=1 ORDER BY tf.id');
    $q->execute([$tourId,$tourId]);$rows=$q->fetchAll();$catalog=saas_feature_catalog();
    foreach($rows as &$r){$c=$catalog[$r['feature_key']]??['label'=>$r['feature_key'],'kind'=>'custom','description'=>''];$r['label']=$c['label'];$r['kind']=$c['kind'];$r['description']=$c['description'];$r['config']=json_decode((string)$r['config_json'],true)?:[];}return $rows;
}
function saas_feature_options(int $featureId): array {
    $q=saas_db()->prepare("SELECT * FROM tour_feature_options WHERE tour_feature_id=? AND status='ACTIVE' ORDER BY sort_order,id");$q->execute([$featureId]);return $q->fetchAll();
}
