<?php
require_once __DIR__ . '/bootstrap_saas.php';
saas_require_login();
$orgs = saas_organizations_for_user(saas_user_id());
if ($orgs) { saas_set_context((int)$orgs[0]['id'], 0); saas_redirect('saas_dashboard.php'); }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    saas_check_csrf();
    $name = trim((string)($_POST['name'] ?? ''));
    $slug = saas_slug((string)($_POST['slug'] ?? $name));
    if ($name === '') $error = 'Organization name is required.';
    else {
        try {
            $db = saas_db(); $db->beginTransaction();
            $q = $db->prepare('INSERT INTO organizations (name,slug) VALUES (?,?)'); $q->execute([$name,$slug]);
            $id = (int)$db->lastInsertId();
            $q = $db->prepare("INSERT INTO organization_members (organization_id,user_id,role,status) VALUES (?,?,'OWNER','ACTIVE')"); $q->execute([$id,saas_user_id()]);
            $db->commit(); saas_set_context($id,0); saas_redirect('tour_create.php');
        } catch (Throwable $e) {
            if (isset($db) && $db->inTransaction()) $db->rollBack();
            $error = 'Could not create organization. The slug may already be in use.';
        }
    }
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Create organization</title><style>body{font-family:Arial,sans-serif;background:#f5f7fb;margin:0}.box{max-width:520px;margin:70px auto;background:#fff;padding:32px;border-radius:14px;box-shadow:0 8px 30px #0001}input,button{width:100%;padding:12px;margin-top:8px;box-sizing:border-box}button{cursor:pointer}.err{color:#b42318;margin:12px 0}</style></head><body><main class="box"><h1>Create organization</h1><p>Set up the organization that will manage its tours.</p><?php if($error): ?><div class="err"><?= saas_h($error) ?></div><?php endif; ?><form method="post"><input type="hidden" name="csrf" value="<?= saas_h(saas_csrf()) ?>"><label>Name</label><input name="name" required><label>Slug</label><input name="slug" placeholder="my-organization"><button type="submit">Create organization</button></form></main></body></html>
