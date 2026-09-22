<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

saas_require_login();
$tourId = saas_require_tour();
$db = saas_db();
$orgId = (int)saas_current_organization()['id'];
$expenseOrgColumn = (int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='expenses' AND COLUMN_NAME='organization_id'")->fetchColumn() > 0;
$error = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        saas_check_csrf();
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'update_expense') {
            saas_require_permission('expense.edit');
            $id = (int)($_POST['expense_id'] ?? 0);
            $category = trim((string)($_POST['category'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $amount = round((float)($_POST['amount'] ?? 0), 2);
            $date = trim((string)($_POST['expense_date'] ?? ''));

            if ($id <= 0) throw new RuntimeException('Expense not found.');
            if ($category === '') throw new RuntimeException('Expense category is required.');
            if ($amount <= 0) throw new RuntimeException('Expense amount must be greater than zero.');
            if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $date)) throw new RuntimeException('Invalid expense date.');

            $db->beginTransaction();
            $q = $db->prepare('SELECT id,category,description,amount,expense_date FROM expenses WHERE id=? AND tour_id=? FOR UPDATE');
            $q->execute([$id, $tourId]);
            $old = $q->fetch();
            if (!$old) throw new RuntimeException('Expense not found for this tour.');

            if ($expenseOrgColumn) {
                $q = $db->prepare('UPDATE expenses SET organization_id=?,category=?,description=?,amount=?,expense_date=? WHERE id=? AND tour_id=?');
                $q->execute([$orgId,$category,$description !== '' ? $description : null,$amount,$date,$id,$tourId]);
            } else {
                $q = $db->prepare('UPDATE expenses SET category=?,description=?,amount=?,expense_date=? WHERE id=? AND tour_id=?');
                $q->execute([$category,$description !== '' ? $description : null,$amount,$date,$id,$tourId]);
            }
            $db->commit();
            saas_audit('expense.updated','expense',$id,json_encode([
                'before'=>['category'=>$old['category'],'amount'=>(float)$old['amount'],'expense_date'=>$old['expense_date']],
                'after'=>['category'=>$category,'amount'=>$amount,'expense_date'=>$date]
            ],JSON_UNESCAPED_UNICODE));
            $ok = 'Expense updated successfully.';
        } elseif ($action === 'create_expense') {
            saas_require_permission('expense.create');
            $category = trim((string)($_POST['category'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $amount = round((float)($_POST['amount'] ?? 0), 2);
            $date = trim((string)($_POST['expense_date'] ?? date('Y-m-d')));

            if ($category === '') {
                throw new RuntimeException('Expense category is required.');
            }
            if ($amount <= 0) {
                throw new RuntimeException('Expense amount must be greater than zero.');
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                throw new RuntimeException('Invalid expense date.');
            }

            if ($expenseOrgColumn) {
                $q = $db->prepare(
                    'INSERT INTO expenses(organization_id,tour_id,category,description,amount,expense_date,created_by)
                     VALUES(?,?,?,?,?,?,?)'
                );
                $q->execute([
                    $orgId,
                    $tourId,
                    $category,
                    $description !== '' ? $description : null,
                    $amount,
                    $date,
                    saas_user_id(),
                ]);
            } else {
                $q = $db->prepare(
                    'INSERT INTO expenses(tour_id,category,description,amount,expense_date,created_by)
                     VALUES(?,?,?,?,?,?)'
                );
                $q->execute([
                    $tourId,
                    $category,
                    $description !== '' ? $description : null,
                    $amount,
                    $date,
                    saas_user_id(),
                ]);
            }
            $id = (int)$db->lastInsertId();
            saas_audit(
                'expense.created',
                'expense',
                $id,
                json_encode(['category' => $category, 'amount' => $amount], JSON_UNESCAPED_UNICODE)
            );
            $ok = 'Expense recorded successfully.';
        } elseif ($action === 'delete_expense') {
            saas_require_permission('expense.delete');
            $id = (int)($_POST['expense_id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('Expense not found.');
            }

            $db->beginTransaction();
            $q = $db->prepare(
                'SELECT id,category,amount FROM expenses
                 WHERE id=? AND tour_id=? FOR UPDATE'
            );
            $q->execute([$id, $tourId]);
            $expense = $q->fetch();
            if (!$expense) {
                throw new RuntimeException('Expense not found for this tour.');
            }

            $q = $db->prepare('DELETE FROM expenses WHERE id=? AND tour_id=?');
            $q->execute([$id, $tourId]);
            $db->commit();

            saas_audit(
                'expense.deleted',
                'expense',
                $id,
                json_encode(
                    ['category' => $expense['category'], 'amount' => (float)$expense['amount']],
                    JSON_UNESCAPED_UNICODE
                )
            );
            $ok = 'Expense deleted.';
        } else {
            throw new RuntimeException('Invalid action.');
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error = $e->getMessage() ?: 'Could not process the expense.';
    }
}

$q = $db->prepare(
    'SELECT e.*, u.name AS created_by_name
     FROM expenses e
     LEFT JOIN users u ON u.id=e.created_by
     WHERE e.tour_id=?
     ORDER BY e.expense_date DESC, e.id DESC
     LIMIT 200'
);
$q->execute([$tourId]);
$expenses = $q->fetchAll();

$q = $db->prepare('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE tour_id=?');
$q->execute([$tourId]);
$total = (float)$q->fetchColumn();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Expenses — <?=saas_h(saas_current_tour()['name'])?></title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#f4f7fb;color:#172033;font-family:Inter,Arial,sans-serif}
.wrap{max-width:1180px;margin:25px auto;padding:0 16px}
.box{background:#fff;border-radius:16px;padding:20px;margin-bottom:18px;box-shadow:0 6px 22px #0000000b}
.grid{display:grid;grid-template-columns:340px 1fr;gap:18px}
label{display:block;font-size:13px;font-weight:700;margin:10px 0 5px}
input,textarea,button{width:100%;padding:11px;border:1px solid #d0d5dd;border-radius:9px;font:inherit}
textarea{min-height:80px;resize:vertical}
button{margin-top:12px;background:#172033;color:#fff;border-color:#172033;cursor:pointer;font-weight:700}
.danger{background:#fff;color:#b42318;border-color:#fecdca;margin:0;width:auto;padding:7px 10px}.edit-btn{background:#eef4ff;color:#155eef;border-color:#c7d7fe;margin:0;width:auto;padding:7px 10px}.action-row{display:flex;gap:7px;flex-wrap:wrap}.modal{position:fixed;inset:0;background:#0b1f3388;display:none;align-items:center;justify-content:center;padding:18px;z-index:1000}.modal.open{display:flex}.modal-card{width:min(560px,100%);background:#fff;border-radius:18px;padding:22px;box-shadow:0 20px 60px #0003}.modal-actions{display:flex;gap:10px}.modal-actions button{margin:0}.modal-actions .cancel{background:#fff;color:#172033;border-color:#d0d5dd}
.msg{padding:11px;border-radius:9px;margin-bottom:15px}
.ok{background:#ecfdf3;color:#067647}
.err{background:#fef3f2;color:#b42318}
.stat{border:1px solid #eaecf0;border-radius:12px;padding:15px;margin-bottom:14px}
.stat b{display:block;font-size:28px;margin-top:5px}
.tablewrap{overflow:auto}
table{width:100%;border-collapse:collapse}
th,td{padding:11px;border-bottom:1px solid #eee;text-align:left;vertical-align:top;white-space:nowrap}
.muted{color:#667085;font-size:13px}
@media(max-width:800px){.grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<main class="wrap">
<p><a href="dashboard.php">← Dashboard</a></p>
<div style="display:flex;justify-content:space-between;align-items:center;gap:10px"><h1>Expenses</h1><div style="display:flex;gap:7px"><a class="export-btn" target="_blank" href="export.php?type=expenses&format=pdf">Print / PDF</a><a class="export-btn" href="export.php?type=expenses&format=excel">Excel</a></div></div>
<p class="muted">Track tour costs and keep the total visible for the current tour.</p>

<?php if($ok):?><div class="msg ok"><?=saas_h($ok)?></div><?php endif;?>
<?php if($error):?><div class="msg err"><?=saas_h($error)?></div><?php endif;?>

<div class="grid">
<section class="box">
<h2>Record expense</h2>
<div class="stat"><span class="muted">Total expenses</span><b><?=number_format($total,2)?></b></div>
<?php if(saas_can('expense.create')):?>
<form method="post">
<input type="hidden" name="csrf" value="<?=saas_h(saas_csrf())?>">
<input type="hidden" name="action" value="create_expense">
<label>Category</label>
<input name="category" placeholder="Transport, hotel, food..." required>
<label>Description</label>
<textarea name="description" placeholder="Optional details"></textarea>
<label>Amount</label>
<input type="number" name="amount" min="0.01" step="0.01" required>
<label>Date</label>
<input type="date" name="expense_date" value="<?=saas_h(date('Y-m-d'))?>" required>
<button>Save expense</button>
</form>
<?php else:?>
<p class="muted">You do not have permission to record expenses.</p>
<?php endif;?>
</section>

<section class="box">
<h2>Expense history</h2>
<div class="tablewrap">
<table>
<tr><th>Date</th><th>Category</th><th>Description</th><th>Amount</th><th>Created by</th><th>Action</th></tr>
<?php foreach($expenses as $expense):?>
<tr>
<td><?=saas_h($expense['expense_date'])?></td>
<td><?=saas_h($expense['category'])?></td>
<td><?=saas_h($expense['description'] ?? '')?></td>
<td><strong><?=number_format((float)$expense['amount'],2)?></strong></td>
<td><?=saas_h($expense['created_by_name'] ?? 'System')?></td>
<td>
<div class="action-row">
<?php if(saas_can('expense.edit')):?><button type="button" class="edit-btn" data-edit-expense='<?=saas_h(json_encode(['id'=>(int)$expense['id'],'category'=>(string)$expense['category'],'description'=>(string)($expense['description']??''),'amount'=>(string)$expense['amount'],'date'=>(string)$expense['expense_date']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))?>'>Edit</button><?php endif;?>
<?php if(saas_can('expense.delete')):?>
<form method="post" onsubmit="return confirm('Delete this expense?')">
<input type="hidden" name="csrf" value="<?=saas_h(saas_csrf())?>">
<input type="hidden" name="action" value="delete_expense">
<input type="hidden" name="expense_id" value="<?=$expense['id']?>">
<button class="danger">Delete</button>
</form>
<?php endif;?>
<?php if(!saas_can('expense.edit')&&!saas_can('expense.delete')):?>—<?php endif;?>
</div>
</td>
</tr>
<?php endforeach;?>
</table>
<?php if(!$expenses):?><p class="muted">No expenses recorded yet.</p><?php endif;?>
</div>
</section>
</div>
</main>
<div class="modal" id="expenseEditModal" aria-hidden="true">
  <div class="modal-card">
    <h2 style="margin-top:0">Edit expense</h2>
    <form method="post">
      <input type="hidden" name="csrf" value="<?=saas_h(saas_csrf())?>">
      <input type="hidden" name="action" value="update_expense">
      <input type="hidden" name="expense_id" id="editExpenseId">
      <label>Category</label><input name="category" id="editExpenseCategory" required>
      <label>Description</label><textarea name="description" id="editExpenseDescription"></textarea>
      <label>Amount</label><input type="number" name="amount" id="editExpenseAmount" min="0.01" step="0.01" required>
      <label>Date</label><input type="date" name="expense_date" id="editExpenseDate" required>
      <div class="modal-actions"><button type="submit">Save changes</button><button type="button" class="cancel" id="closeExpenseEdit">Cancel</button></div>
    </form>
  </div>
</div>
<script>
(function(){
 const modal=document.getElementById('expenseEditModal');
 const close=()=>{modal.classList.remove('open');modal.setAttribute('aria-hidden','true')};
 document.querySelectorAll('[data-edit-expense]').forEach(btn=>{
  btn.addEventListener('click',()=>{
   const d=JSON.parse(btn.dataset.editExpense);
   document.getElementById('editExpenseId').value=d.id;
   document.getElementById('editExpenseCategory').value=d.category||'';
   document.getElementById('editExpenseDescription').value=d.description||'';
   document.getElementById('editExpenseAmount').value=d.amount||'';
   document.getElementById('editExpenseDate').value=d.date||'';
   modal.classList.add('open');modal.setAttribute('aria-hidden','false');
   document.getElementById('editExpenseCategory').focus();
  });
 });
 document.getElementById('closeExpenseEdit').addEventListener('click',close);
 modal.addEventListener('click',e=>{if(e.target===modal)close()});
 document.addEventListener('keydown',e=>{if(e.key==='Escape')close()});
})();
</script>
</body>
</html>
