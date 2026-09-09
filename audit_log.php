<?php
require __DIR__.'/bootstrap.php';require __DIR__.'/bootstrap_auth.php'; require_login(); require_permission('audit.view'); $tid=require_tour();
$q=db()->prepare("SELECT a.*,COALESCE(ad.username,'System') admin_name FROM audit_log a LEFT JOIN admins ad ON ad.id=a.admin_id WHERE (a.entity_type IS NULL OR a.entity_type<>'') ORDER BY a.id DESC LIMIT 500");
$q->execute(); $rows=$q->fetchAll();
?>
<!doctype html><html><head><?php include __DIR__.'/partials/head.php';?><style>
.log-action{font-weight:800;color:#147d43}.details{max-width:420px;word-break:break-word}
</style></head><body><?php include __DIR__.'/partials/nav.php';?><main class="wrap">
<section class="card"><div class="head"><div><div class="eyebrow">AUDIT</div><h2>Activity Log</h2><p class="muted">Latest 500 actions</p></div><a class="btn secondary" href="admin_tools.php">Back</a></div>
<div class="table-wrap responsive-table"><table><thead><tr><th>Time</th><th>Admin</th><th>Action</th><th>Entity</th><th>Details</th></tr></thead><tbody>
<?php foreach($rows as $r):?><tr><td><?=h($r['created_at'])?></td><td><?=h($r['admin_name'])?></td><td class="log-action"><?=h($r['action'])?></td><td><?=h(($r['entity_type']??'').' '.($r['entity_id']??''))?></td><td class="details"><?=h($r['details']??'')?></td></tr><?php endforeach;?>
</tbody></table></div></section></main></body></html>