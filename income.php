<?php
require __DIR__.'/bootstrap.php';
require __DIR__.'/bootstrap_auth.php';
require_login();

$tid = require_tour();

$editId = (int)($_GET['edit'] ?? 0);
$edit = null;

if ($editId) {
    $q = db()->prepare("
        SELECT *
        FROM incomes
        WHERE id=? AND tour_id=?
    ");
    $q->execute([$editId, $tid]);
    $edit = $q->fetch();

    if (!$edit) {
        redirect('income.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    check_csrf();

    try {

        $action = $_POST['action'] ?? 'add';

        if ($action === 'delete') {

            $id = (int)$_POST['id'];

            db()->prepare("
                DELETE FROM incomes
                WHERE id=? AND tour_id=?
            ")->execute([$id, $tid]);

            audit(
                'DELETE_INCOME',
                'income',
                $id
            );

            flash('success', 'Income deleted.');

        } elseif ($action === 'update') {

            $id = (int)$_POST['id'];

            $category = trim($_POST['category'] ?? '');
            $amount = max(0, (float)($_POST['amount'] ?? 0));
            $description = trim($_POST['description'] ?? '');
            $receivedFrom = trim($_POST['received_from'] ?? '');
            $incomeDate = $_POST['income_date'] ?? date('Y-m-d');

            if ($category === '') {
                throw new Exception('Income category is required.');
            }

            if ($amount <= 0) {
                throw new Exception('Income amount must be greater than 0.');
            }

            db()->prepare("
                UPDATE incomes
                SET
                    category=?,
                    description=?,
                    amount=?,
                    received_from=?,
                    income_date=?
                WHERE id=? AND tour_id=?
            ")->execute([
                $category,
                $description,
                $amount,
                $receivedFrom,
                $incomeDate,
                $id,
                $tid
            ]);

            audit(
                'UPDATE_INCOME',
                'income',
                $id,
                $category.' · '.money($amount)
            );

            flash('success', 'Income updated.');

        } else {

            $category = trim($_POST['category'] ?? '');
            $amount = max(0, (float)($_POST['amount'] ?? 0));
            $description = trim($_POST['description'] ?? '');
            $receivedFrom = trim($_POST['received_from'] ?? '');
            $incomeDate = $_POST['income_date'] ?? date('Y-m-d');

            if ($category === '') {
                throw new Exception('Income category is required.');
            }

            if ($amount <= 0) {
                throw new Exception('Income amount must be greater than 0.');
            }

            db()->prepare("
                INSERT INTO incomes
                (
                    tour_id,
                    category,
                    description,
                    amount,
                    received_from,
                    income_date,
                    created_by
                )
                VALUES(?,?,?,?,?,?,?)
            ")->execute([
                $tid,
                $category,
                $description,
                $amount,
                $receivedFrom,
                $incomeDate,
                $_SESSION['admin_id'] ?? null
            ]);

            $id = (int)db()->lastInsertId();

            audit(
                'ADD_INCOME',
                'income',
                $id,
                $category.' · '.money($amount)
            );

            flash('success', 'Income added.');
        }

    } catch (Throwable $e) {

        flash('danger', $e->getMessage());
    }

    redirect('income.php');
}


/* -------------------------------------------------------
   Income history
------------------------------------------------------- */

$q = db()->prepare("
    SELECT *
    FROM incomes
    WHERE tour_id=?
    ORDER BY income_date DESC, id DESC
");

$q->execute([$tid]);

$rows = $q->fetchAll();

$totalIncome = 0;

foreach ($rows as $row) {
    $totalIncome += (float)$row['amount'];
}

?>
<!doctype html>

<html>

<head>
    <?php include __DIR__.'/partials/head.php'; ?>

    <style>

        .income-total {
            border:1px solid #dfeee4;
            background:#f7fcf9;
            border-radius:14px;
            padding:18px;
        }

        .income-total span {
            display:block;
            color:#64748b;
            font-size:.9rem;
            margin-bottom:4px;
        }

        .income-total b {
            display:block;
            color:#075c38;
            font-size:1.55rem;
        }

        .income-category {
            font-weight:700;
            color:#075c38;
        }

        @media(max-width:700px) {

            .income-form-grid {
                display:block;
            }

            .income-form-grid label {
                margin-bottom:12px;
            }

        }

    </style>

</head>

<body>

<?php include __DIR__.'/partials/nav.php'; ?>

<main class="wrap">

<?php flash_render(); ?>


<section class="card">

    <div class="head">

        <div>

            <div class="eyebrow">
                TOUR INCOME
            </div>

            <h1>
                <?= $edit ? 'Edit Income' : 'Add Income' ?>
            </h1>

            <p class="muted">
                Record income other than passenger ticket payments.
            </p>

        </div>

    </div>


    <form method="post">

        <input
            type="hidden"
            name="csrf"
            value="<?=h(csrf())?>"
        >

        <input
            type="hidden"
            name="action"
            value="<?= $edit ? 'update' : 'add' ?>"
        >

        <?php if ($edit): ?>

            <input
                type="hidden"
                name="id"
                value="<?= (int)$edit['id'] ?>"
            >

        <?php endif; ?>


        <div class="grid2">

            <label>
                Income Category

                <select name="category" required>

                    <?php

                    $categories = [
                        'T-Shirt Sales',
                        'Donation',
                        'Sponsorship',
                        'Other Income'
                    ];

                    $selectedCategory =
                        $edit['category'] ?? '';

                    ?>

                    <option value="">
                        Select Category
                    </option>

                    <?php foreach ($categories as $category): ?>

                        <option
                            value="<?=h($category)?>"
                            <?= $selectedCategory === $category ? 'selected' : '' ?>
                        >
                            <?=h($category)?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </label>


            <label>
                Amount

                <input
                    name="amount"
                    type="number"
                    min="0"
                    step="0.01"
                    required
                    placeholder="0.00"
                    value="<?=h($edit['amount'] ?? '')?>"
                >

            </label>


            <label>
                Received From / Source

                <input
                    name="received_from"
                    placeholder="e.g. T-shirt buyers / Donor name"
                    value="<?=h($edit['received_from'] ?? '')?>"
                >

            </label>


            <label>
                Date

                <input
                    name="income_date"
                    type="date"
                    required
                    value="<?=h(
                        $edit['income_date']
                        ?? date('Y-m-d')
                    )?>"
                >

            </label>

        </div>


        <label>

            Description

            <input
                name="description"
                placeholder="Optional details"
                value="<?=h($edit['description'] ?? '')?>"
            >

        </label>


        <div class="row-actions">

            <button class="btn primary">

                <?= $edit
                    ? 'Update Income'
                    : 'Save Income'
                ?>

            </button>


            <?php if ($edit): ?>

                <a
                    class="btn secondary"
                    href="income.php"
                >
                    Cancel
                </a>

            <?php endif; ?>

        </div>

    </form>

</section>



<section class="card">

    <div class="head">

        <div>

            <div class="eyebrow">
                TOUR FINANCE
            </div>

            <h2>
                Other Income
            </h2>

            <p class="muted">
                Income received outside passenger ticket payments.
            </p>

        </div>

    </div>


    <div class="income-total">

        <span>
            Total Other Income
        </span>

        <b>
            <?=money($totalIncome)?>
        </b>

    </div>

</section>



<section class="card">

    <div class="head">

        <h2>
            Income History
        </h2>

    </div>


    <div class="table-wrap responsive-table">

        <table>

            <thead>

                <tr>

                    <th>Date</th>

                    <th>Category</th>

                    <th>Received From</th>

                    <th>Description</th>

                    <th>Amount</th>

                    <th>Actions</th>

                </tr>

            </thead>


            <tbody>

            <?php if (!$rows): ?>

                <tr>

                    <td
                        colspan="6"
                        class="muted"
                        style="text-align:center;padding:30px"
                    >
                        No income recorded yet.

                    </td>

                </tr>

            <?php else: ?>


                <?php foreach ($rows as $x): ?>

                    <tr>

                        <td data-label="Date">
                            <?=h($x['income_date'])?>
                        </td>


                        <td
                            data-label="Category"
                            class="income-category"
                        >
                            <?=h($x['category'])?>
                        </td>


                        <td data-label="Received From">
                            <?=h($x['received_from'])?>
                        </td>


                        <td data-label="Description">
                            <?=h($x['description'])?>
                        </td>


                        <td data-label="Amount">
                            <?=money($x['amount'])?>
                        </td>


                        <td
                            data-label="Actions"
                            class="nowrap"
                        >

                            <a
                                class="btn secondary"
                                href="income.php?edit=<?=$x['id']?>"
                            >
                                Edit
                            </a>


                            <form
                                method="post"
                                style="display:inline"
                                onsubmit="return confirm('Delete this income?')"
                            >

                                <input
                                    type="hidden"
                                    name="csrf"
                                    value="<?=h(csrf())?>"
                                >

                                <input
                                    type="hidden"
                                    name="action"
                                    value="delete"
                                >

                                <input
                                    type="hidden"
                                    name="id"
                                    value="<?=$x['id']?>"
                                >

                                <button
                                    class="btn danger"
                                    type="submit"
                                >
                                    Delete
                                </button>

                            </form>

                        </td>

                    </tr>

                <?php endforeach; ?>

            <?php endif; ?>

            </tbody>

        </table>

    </div>

</section>


</main>

</body>

</html>