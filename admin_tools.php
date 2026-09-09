<?php
require __DIR__.'/bootstrap.php';require __DIR__.'/bootstrap_auth.php';require_login();$tid=require_tour();$t=tour_row($tid);
?><!doctype html><html><head><?php include __DIR__.'/partials/head.php';?><style>
.tools{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.tool{padding:18px;border:1px solid #dfeae4;border-radius:16px;background:#fff;text-decoration:none;color:inherit}.tool h3{margin:0 0 6px}.tool p{color:#71857d;font-size:13px;min-height:38px}@media(max-width:700px){.tools{grid-template-columns:1fr}}
</style></head><body><?php include __DIR__.'/partials/nav.php';?><main class="wrap"><section class="card">
<div class="eyebrow">ADMIN TOOLS</div><h1>Tour Operations & Safety</h1><p class="muted"><?=h($t['name'])?> · Role: <b><?=h(admin_role())?></b></p>
<div class="tools">
<?php if(can('backup')):?><a class="tool" href="backup.php"><h3>💾 Database Backup</h3><p>Download a complete SQL backup.</p></a><?php endif;?>
<?php if(can('audit.view')):?><a class="tool" href="audit_log.php"><h3>🧾 Activity Log</h3><p>Review administrative actions.</p></a><?php endif;?>
<a class="tool" href="checkin_dashboard.php"><h3>🚌 Tour-day Check-in</h3><p>Live outbound/return check-in counts.</p></a>
<a class="tool" href="bulk_passengers.php"><h3>☑️ Bulk Passenger Tools</h3><p>Bulk ticket printing and export.</p></a>
<a class="tool" href="passenger_export.php"><h3>📊 Passenger Export</h3><p>Excel-compatible CSV export.</p></a>
<?php if(is_super_admin()):?><a class="tool" href="tour_manage.php"><h3>🗂 Tour Management</h3><p>Create and delete tours.</p></a><a class="tool" href="admin_users.php"><h3>👥 Admin Users</h3><p>Create users, disable accounts and change roles.</p></a><?php endif;?>
<?php if(can('tour.manage')):?><a class="tool" href="tour_status.php"><h3>🔒 Tour Status</h3><p>Operational tour lifecycle controls.</p></a><?php endif;?>
</div></section></main></body></html>