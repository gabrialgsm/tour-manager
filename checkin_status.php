<?php
require __DIR__.'/bootstrap.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

try{
    $ids=array_values(array_filter(array_map('intval',preg_split('/[,\s]+/',(string)($_GET['ids']??'')))));
    if(!$ids){echo json_encode(['ok'=>true,'checked'=>[]]);exit;}
    $ids=array_slice(array_unique($ids),0,500);
    $ph=implode(',',array_fill(0,count($ids),'?'));
    $sql="SELECT a.entity_id,a.action
          FROM audit_log a
          INNER JOIN (
            SELECT entity_id,MAX(id) max_id
            FROM audit_log
            WHERE entity_type='passenger'
              AND entity_id IN ($ph)
              AND action IN ('CHECK_IN_OUTBOUND','CHECK_IN_OUTBOUND_UNDO')
            GROUP BY entity_id
          ) x ON x.max_id=a.id";
    $q=db()->prepare($sql);$q->execute($ids);
    $checked=[];
    foreach($q->fetchAll() as $r){
        if($r['action']==='CHECK_IN_OUTBOUND')$checked[(string)$r['entity_id']]=true;
    }
    echo json_encode(['ok'=>true,'checked'=>$checked],JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){
    http_response_code(400);
    echo json_encode(['ok'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);
}
