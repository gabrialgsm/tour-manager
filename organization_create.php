<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap_saas.php';
saas_require_login();

if (saas_current_organization()) saas_redirect('saas_dashboard.php');
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    saas_check_csrf();
    $name = trim((string)($_POST['name'] ?? ''));
    $slug = saas_slug((string)($_POST['slug'] ?? $name));
    if ($name === '' || mb_strlen($name) > 180) {
        $error = 'Please enter a valid organization name.';
    } else {
        $db = saas_db();
        try {
            $db->beginTransaction();
            $q = $db->prepare('INSERT INTO organizations (name,slug) VALUES (?,?)');
            $q->execute([$name, $slug]);
            $id = (int)$db->lastInsertId();
            $q = $db->prepare("INSERT INTO organization_members (organization_id,user_id,role,status) VALUES (?,?,'OWNER','ACTIVE')");
            $q->execute([$id, saas_user_id()]);
            $db->commit();
            saas_set_context($id, 0);
            saas_audit('organization.created', 'organization', $id, $name);
            saas_redirect('tour_create.php');
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $error = 'Could not create organization. The slug may already be in use.';
        }
    }
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Create organization</title><style>body{font-family:system-ui;background:#f5f7fb;margin:0;color:#172033}.box{max-width:520px;margin:8vh auto;background:#fff;padding:32px;border-radius:16px;box-shadow:0 8px 30px #0001}label{display:block;margin:16px 0 6px;font-weight:600}input,button{width:100%;box-sizing:border-box;padding:12px;border-radius:9px}input{border:1px solid #ccd3df}button{margin-top:22px;border:0;background:#172033;color:#fff;font-weight:700}.err{background:#fff0f0;color:#a22;padding:10px;border-radius:8px}</style></head><body><main class="box"><h1>Create organization</h1><p>Your workspace for tours and operations.</p><?php if($error): ?><div class="err"><?=saas_h($error)?></div><?php endif; ?><form method="post"><input type="hidden" name="csrf" value="<?=saas_h(saas_csrf())?>"><label>Organization name</label><input name="name" required maxlength="180" autofocus><label>Public slug</label><input name="slug" maxlength="180" placeholder="my-organization"><button type="submit">Create organization</button></form></main></body></html>