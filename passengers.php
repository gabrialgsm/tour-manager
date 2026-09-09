<?php
require __DIR__.'/bootstrap.php';
require_login();
$tid = require_tour();

/* Actions */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'delete') {
            $id = (int)$_POST['id'];
            $q = db()->prepare("UPDATE passengers SET status='CANCELLED' WHERE id=? AND tour_id=?");
            $q->execute([$id, $tid]);
            audit('DELETE_PASSENGER', 'passenger', $id);
            flash('success', 'Passenger cancelled and seat released.');
        } elseif ($action === 'restore') {
            $id = (int)$_POST['id'];
            $q = db()->prepare("UPDATE passengers SET status='ACTIVE' WHERE id=? AND tour_id=?");
            $q->execute([$id, $tid]);
            flash('success', 'Passenger restored.');
        }
    } catch (Throwable $e) {
        flash('danger', $e->getMessage());
    }

    redirect('passengers.php');
}

/* Filters */
$departure = trim($_GET['departure'] ?? '');
$bus_id    = (int)($_GET['bus_id'] ?? 0);
$search    = trim($_GET['search'] ?? '');

/* Departure filter options */
$q = db()->prepare("
    SELECT DISTINCT p.departure
    FROM passengers p
    WHERE p.tour_id=? AND p.status='ACTIVE'
      AND p.departure IS NOT NULL AND p.departure <> ''
    ORDER BY p.departure
");
$q->execute([$tid]);
$departures = $q->fetchAll(PDO::FETCH_COLUMN);

/* Bus filter options */
$q = db()->prepare("
    SELECT DISTINCT b.id, b.name, b.bus_number
    FROM passengers p
    JOIN buses b ON b.id=p.bus_id
    WHERE p.tour_id=? AND p.status='ACTIVE'
    ORDER BY b.name, b.id
");
$q->execute([$tid]);
$buses = $q->fetchAll();

/* Passenger list */
$sql = "
    SELECT p.*, b.name bus_name, b.bus_number, s.seat_no,
           COALESCE((SELECT SUM(amount) FROM payments WHERE passenger_id=p.id),0) paid
    FROM passengers p
    JOIN buses b ON b.id=p.bus_id
    JOIN seats s ON s.id=p.seat_id
    WHERE p.tour_id=? AND p.status='ACTIVE'
";
$params = [$tid];

if ($departure !== '') {
    $sql .= " AND p.departure=?";
    $params[] = $departure;
}

if ($bus_id > 0) {
    $sql .= " AND p.bus_id=?";
    $params[] = $bus_id;
}

if ($search !== '') {
    $sql .= " AND (
        p.name LIKE ? OR p.phone LIKE ? OR p.address LIKE ? OR
        p.departure LIKE ? OR b.name LIKE ? OR b.bus_number LIKE ? OR
        s.seat_no LIKE ?
    )";
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like, $like, $like, $like);
}

$sql .= " ORDER BY b.id, s.row_no, s.col_no";

$q = db()->prepare($sql);
$q->execute($params);
$rows = $q->fetchAll();

$q = db()->prepare("SELECT COUNT(*) FROM passengers WHERE tour_id=? AND status='CANCELLED'");
$q->execute([$tid]);
$cancelled = (int)$q->fetchColumn();

/* Keep current filters for export links */
$filterQuery = http_build_query([
    'departure' => $departure,
    'bus_id'    => $bus_id ?: '',
    'search'    => $search
]);
?>
<!doctype html>
<html>
<head>
<?php include __DIR__.'/partials/head.php'; ?>
<style>
.passenger-filters{
    display:grid;
    grid-template-columns:minmax(180px,1fr) minmax(180px,1fr) minmax(220px,1.4fr) auto;
    gap:10px;
    align-items:end;
    margin:14px 0 18px;
}
.passenger-filters .filter-group{display:flex;flex-direction:column;gap:6px}
.passenger-filters label{font-size:12px;font-weight:700;opacity:.75}
.passenger-filters select,
.passenger-filters input{
    width:100%;
    min-height:42px;
    box-sizing:border-box;
}
.filter-actions{display:flex;gap:8px;flex-wrap:wrap}
.export-actions{display:flex;gap:8px;flex-wrap:wrap}
.result-count{font-size:13px;opacity:.7;margin:0 0 12px}
@media(max-width:900px){
    .passenger-filters{grid-template-columns:1fr 1fr}
}
@media(max-width:600px){
    .passenger-filters{grid-template-columns:1fr}
}
</style>
</head>
<body>
<?php include __DIR__.'/partials/nav.php'; ?>

<main class="wrap">
<?php flash_render(); ?>

<section class="card">
    <div class="head">
        <h2>Passenger List</h2>

        <div class="row-actions">
            <a class="btn secondary" href="passenger_print.php?tour_id=<?=$tid?>">Print List</a>
            <button class="btn secondary" onclick="printSelectedVouchers()">Payment Vouchers</button>
            <button class="btn primary" onclick="printSelected()">Print Selected Tickets</button>
            <a class="btn primary" href="passenger_form.php">+ Passenger</a>
        </div>
    </div>

    <!-- Filters -->
    <form method="get" class="passenger-filters" id="filterForm">
        <div class="filter-group">
            <label for="departure">Departure From</label>
            <select name="departure" id="departure">
                <option value="">All Departures</option>
                <?php foreach($departures as $d): ?>
                    <option value="<?=h($d)?>" <?=$departure===$d?'selected':''?>>
                        <?=h($d)?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-group">
            <label for="bus_id">Bus</label>
            <select name="bus_id" id="bus_id">
                <option value="">All Buses</option>
                <?php foreach($buses as $b): ?>
                    <option value="<?=$b['id']?>" <?=$bus_id===(int)$b['id']?'selected':''?>>
                        <?=h($b['name'])?><?=!empty($b['bus_number'])?' — '.h($b['bus_number']):''?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-group">
            <label for="search">Search</label>
            <input id="search" name="search" value="<?=h($search)?>" placeholder="Name, phone, seat, bus...">
        </div>

        <div class="filter-actions">
            <button class="btn primary" type="submit">Filter</button>
            <a class="btn secondary" href="passengers.php">Reset</a>
        </div>
    </form>

    <!-- Export buttons: export exactly the current filters -->
    <div class="export-actions" style="margin-bottom:12px">
        <a class="btn secondary" href="passenger_export.php?format=csv&amp;<?=$filterQuery?>">Export CSV</a>
        <a class="btn primary" href="passenger_export.php?format=excel&amp;<?=$filterQuery?>">Export Excel</a>
    </div>

    <p class="result-count">
        Showing <b><?=count($rows)?></b> active passenger<?=count($rows)==1?'':'s'?>
        <?php if($departure || $bus_id || $search): ?> for the selected filter(s).<?php endif; ?>
    </p>

    <div class="table-wrap responsive-table">
        <table id="passTable">
            <thead>
                <tr>
                    <th><input type="checkbox" id="all"></th>
                    <th>Seat</th>
                    <th>Name</th>
                    <th>Phone</th>
                    <th>Departure</th>
                    <th>Bus</th>
                    <th>Room</th>
                    <th>Fee</th>
                    <th>Paid</th>
                    <th>Due</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach($rows as $p):
                $due = max(0, (float)$p['final_fee'] - (float)$p['paid']);
            ?>
                <tr>
                    <td data-label="Select">
                        <input type="checkbox" class="ticket-check" value="<?=$p['id']?>">
                    </td>
                    <td data-label="Seat"><?=h($p['seat_no'])?></td>
                    <td data-label="Name"><b><?=h($p['name'])?></b></td>
                    <td data-label="Phone"><?=h($p['phone'])?></td>
                    <td data-label="Departure"><?=h($p['departure'])?></td>
                    <td data-label="Bus"><?=h($p['bus_name'])?></td>
                    <td data-label="Room"><?=h(room_label($p['room_type']))?></td>
                    <td data-label="Fee"><?=money($p['final_fee'])?></td>
                    <td data-label="Paid"><?=money($p['paid'])?></td>
                    <td data-label="Due">
                        <?=$due
                            ? '<span class="badge due">'.money($due).'</span>'
                            : '<span class="badge">Paid</span>'?>
                    </td>
                    <td data-label="Actions">
                        <a class="btn secondary" href="passenger_form.php?id=<?=$p['id']?>">Edit</a>
                        <a class="btn primary" href="ticket.php?id=<?=$p['id']?>">Ticket</a>
                        <form method="post" style="display:inline"
                              onsubmit="return confirm('Cancel this passenger and release the seat?')">
                            <input type="hidden" name="csrf" value="<?=h(csrf())?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?=$p['id']?>">
                            <button class="btn danger">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
</main>

<script>
document.getElementById('all').onchange = e =>
    document.querySelectorAll('.ticket-check').forEach(x => x.checked = e.target.checked);

function printSelected(){
    let ids = [...document.querySelectorAll('.ticket-check:checked')].map(x => x.value);
    if(!ids.length) return alert('Select at least one passenger.');
    location.href = 'tickets.php?ids=' + ids.join(',');
}

function printSelectedVouchers(){
    let ids = [...document.querySelectorAll('.ticket-check:checked')].map(x => x.value);
    if(!ids.length) return alert('Select at least one passenger.');
    location.href = 'payment_vouchers.php?ids=' + ids.join(',');
}

/* Auto-submit when a filter is changed */
document.getElementById('departure').addEventListener('change', () => {
    document.getElementById('filterForm').submit();
});
document.getElementById('bus_id').addEventListener('change', () => {
    document.getElementById('filterForm').submit();
});
</script>
</body>
</html>
