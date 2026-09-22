<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
saas_require_login();

if (saas_current_organization()) saas_redirect('dashboard.php');
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    saas_check_csrf();
    $name = trim((string)($_POST['name'] ?? ''));
    $slugInput = trim((string)($_POST['slug'] ?? ''));
    $slug = saas_slug($slugInput !== '' ? $slugInput : $name);
    if ($name === '' || mb_strlen($name) > 180) {
        $error = 'Please enter a valid organization name.';
    } else {
        $db = saas_db();
        try {
            $db->beginTransaction();
            $q = $db->prepare('SELECT id FROM organizations WHERE slug=? LIMIT 1');
            $q->execute([$slug]);
            if ($q->fetch()) {
                throw new RuntimeException('The public slug is already in use. Please choose a different slug.');
            }

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
            $msg = $e->getMessage();
            $error = str_contains(strtolower($msg), 'slug')
                ? $msg
                : 'Could not create organization. Please check the details and try again.';
        }
    }
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Create organization</title>
<style>
body{font-family:system-ui;background:#f5f7fb;margin:0;color:#172033}
.box{max-width:520px;margin:8vh auto;background:#fff;padding:32px;border-radius:16px;box-shadow:0 8px 30px #0001}
label{display:block;margin:16px 0 6px;font-weight:600}
input,button{width:100%;box-sizing:border-box;padding:12px;border-radius:9px}
input{border:1px solid #ccd3df}
input:focus{outline:0;border-color:#5a8dee;box-shadow:0 0 0 4px #5a8dee18}
button{margin-top:22px;border:0;background:#172033;color:#fff;font-weight:700;cursor:pointer}
button:disabled{opacity:.55;cursor:not-allowed}
.err{background:#fff0f0;color:#a22;padding:10px;border-radius:8px}
.hint{margin-top:7px;font-size:13px;color:#718096}
.slug-status{min-height:20px;margin-top:7px;font-size:13px;font-weight:600}
.slug-status.available{color:#16803c}
.slug-status.unavailable{color:#c53030}
.slug-status.checking{color:#718096}
</style>
</head>
<body>
<main class="box">
<h1>Create organization</h1>
<p>Your workspace for tours and operations.</p>
<?php if($error): ?><div class="err"><?=saas_h($error)?></div><?php endif; ?>
<form method="post" id="organizationForm" novalidate>
<input type="hidden" name="csrf" value="<?=saas_h(saas_csrf())?>">
<label for="organizationName">Organization name</label>
<input id="organizationName" name="name" required maxlength="180" autofocus autocomplete="organization">

<label for="organizationSlug">Public slug</label>
<input id="organizationSlug" name="slug" maxlength="180" placeholder="my-organization" autocomplete="off" spellcheck="false">
<div class="hint">Auto-filled from your organization name. You can edit it.</div>
<div id="slugStatus" class="slug-status" aria-live="polite"></div>

<button type="submit" id="createOrganizationBtn">Create organization</button>
</form>
</main>
<script>
(function(){
  const nameInput=document.getElementById('organizationName');
  const slugInput=document.getElementById('organizationSlug');
  const status=document.getElementById('slugStatus');
  const button=document.getElementById('createOrganizationBtn');
  const form=document.getElementById('organizationForm');

  let manuallyEdited=false;
  let lastAutoSlug='';
  let timer=null;
  let requestId=0;
  let available=false;

  function slugify(value){
    return String(value || '')
      .normalize('NFKD')
      .replace(/[\u0300-\u036f]/g,'')
      .toLowerCase()
      .replace(/[^a-z0-9]+/g,'-')
      .replace(/^-+|-+$/g,'')
      .slice(0,180);
  }

  function setStatus(type,message){
    status.className='slug-status '+type;
    status.textContent=message || '';
  }

  function updateAutoSlug(){
    if(manuallyEdited) return;
    const next=slugify(nameInput.value);
    slugInput.value=next;
    lastAutoSlug=next;
    scheduleCheck();
  }

  async function checkSlug(){
    const slug=slugify(slugInput.value);
    if(slugInput.value!==slug) slugInput.value=slug;

    available=false;
    if(!slug){
      setStatus('','');
      button.disabled=false;
      return;
    }
    if(slug.length<2){
      setStatus('unavailable','Slug must be at least 2 characters.');
      button.disabled=true;
      return;
    }

    const current=++requestId;
    setStatus('checking','Checking availability…');
    button.disabled=true;

    try{
      const response=await fetch('organization_slug_check.php?slug='+encodeURIComponent(slug),{
        headers:{'Accept':'application/json'},
        credentials:'same-origin',
        cache:'no-store'
      });
      const data=await response.json();
      if(current!==requestId) return;
      available=Boolean(data.available);
      setStatus(
        available ? 'available' : 'unavailable',
        data.message || (available ? 'Slug is available.' : 'Slug is not available.')
      );
      button.disabled=!available;
    }catch(error){
      if(current!==requestId) return;
      available=false;
      setStatus('unavailable','Could not check the slug. Please try again.');
      button.disabled=true;
    }
  }

  function scheduleCheck(){
    clearTimeout(timer);
    timer=setTimeout(checkSlug,350);
  }

  nameInput.addEventListener('input', updateAutoSlug);

  slugInput.addEventListener('input', function(){
    // The first real edit switches this field to manual mode.
    // After that, changing the organization name will never overwrite it.
    if(slugInput.value !== lastAutoSlug) manuallyEdited=true;
    slugInput.value=slugify(slugInput.value);
    scheduleCheck();
  });

  form.addEventListener('submit',function(event){
    const slug=slugify(slugInput.value);
    slugInput.value=slug;

    if(!available){
      event.preventDefault();
      checkSlug();
      slugInput.focus();
    }
  });

  // Initial page load: generate the slug once from the current name.
  if(nameInput.value && !slugInput.value){
    updateAutoSlug();
  }else if(slugInput.value){
    lastAutoSlug=slugify(slugInput.value);
    slugInput.value=lastAutoSlug;
    scheduleCheck();
  }
})();
</script>
</body>
</html>