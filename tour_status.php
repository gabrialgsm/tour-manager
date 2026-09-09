<?php
require __DIR__.'/bootstrap.php';require __DIR__.'/bootstrap_auth.php'; require_login(); $tid=require_tour(); $t=tour_row($tid);
if($_SERVER['REQUEST_METHOD']==='POST'){check_csrf();try{
 $action=$_POST['action']??'';
 if($action==='close' && $t['status']==='ACTIVE'){ require_super_admin();
   db()->prepare("UPDATE tours SET status='CLOSED' WHERE id=?")->execute([$tid]); audit('CLOSE_TOUR','tour',$tid,$t['name']); flash('success','Tour closed.'); redirect('tour_manage.php');
 }
 if($action==='archive' && $t['status']==='CLOSED'){ require_super_admin();
   db()->prepare("UPDATE tours SET status='ARCHIVED' WHERE id=?")->execute([$tid]); audit('ARCHIVE_TOUR','tour',$tid,$t['name']); flash('success','Tour archived.'); redirect('tour_manage.php');
 }
} catch(Throwable $e){flash('danger',$e->getMessage());} redirect('tour_status.php');}
?>
<!doctype html><html><head><?php include __DIR__.'/partials/head.php';?><style>.status{display:inline-block;padding:7px 12px;border-radius:999px;font-weight:900;background:#eaf8ef;color:#13783e}.status.closed{background:#fff4df;color:#a15e00}.status.archived{background:#eef2f7;color:#475569}</style></head><body><?php include __DIR__.'/partials/nav.php';?><main class="wrap"><?php flash_render();?>
<section class="card"><div class="head"><div><div class="eyebrow">TOUR LIFECYCLE</div><h2><?=h($t['name'])?></h2><p class="muted"><?=h($t['start_date'])?> → <?=h($t['end_date'])?></p></div><span class="status <?=strtolower($t['status'])?>"><?=h($t['status'])?></span></div>
<?php if($t['status']==='ACTIVE'):?><div class="alert">Closing a tour prevents it from being selected as the active tour. Existing records remain intact.</div><?php if(is_super_admin()):?><form method="post" onsubmit="return confirm('Close this tour?')"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="close"><button class="btn danger">🔒 Close Tour</button></form><?php endif;?>
<?php elseif($t['status']==="CLOSED"):?><div class="alert">This tour is closed. Archive it only when you no longer expect operational edits.</div><form method="post" onsubmit="return confirm('Archive this closed tour?')"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="archive"><button class="btn danger">📦 Archive Tour</button></form>
<?php else:?><p class="muted">This tour is archived and should be treated as historical data.</p><?php endif;?>
</section></main></body></html>