<?php
require_once __DIR__ . '/bootstrap.php';

$orgId = saas_require_organization();
$tourId = saas_require_tour();
saas_require_permission('bus.manage');
$db = saas_db();

$error = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    saas_check_csrf();
    try {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'delete_bus') {
            $busId = (int) ($_POST['bus_id'] ?? 0);
            $q = $db->prepare('SELECT name FROM buses WHERE id=? AND tour_id=?');
            $q->execute([$busId, $tourId]);
            $bus = $q->fetch();
            if (!$bus) {
                throw new RuntimeException('Bus not found.');
            }

            $q = $db->prepare('SELECT COUNT(*) FROM passenger_seat_assignments WHERE bus_id=?');
            $q->execute([$busId]);
            if ((int) $q->fetchColumn() > 0) {
                throw new RuntimeException('This bus has assigned passengers. Remove seat assignments before deleting it.');
            }

            $db->beginTransaction();
            $db->prepare('DELETE FROM seats WHERE bus_id=?')->execute([$busId]);
            $db->prepare('DELETE FROM buses WHERE id=? AND tour_id=?')->execute([$busId, $tourId]);
            $db->commit();

            saas_audit('bus.deleted', 'bus', $busId, json_encode(['name' => $bus['name']]));
            $ok = 'Bus deleted.';
        } elseif ($action === 'update_bus') {
            $busId = (int) ($_POST['bus_id'] ?? 0);
            $name = trim((string) ($_POST['name'] ?? ''));
            $number = trim((string) ($_POST['bus_number'] ?? ''));

            if (!$busId || $name === '') {
                throw new RuntimeException('Bus name is required.');
            }

            $q = $db->prepare('SELECT id FROM buses WHERE id=? AND tour_id=?');
            $q->execute([$busId, $tourId]);
            if (!$q->fetch()) {
                throw new RuntimeException('Bus not found.');
            }

            $q = $db->prepare('UPDATE buses SET name=?,bus_number=? WHERE id=? AND tour_id=?');
            $q->execute([$name, $number !== '' ? $number : null, $busId, $tourId]);
            saas_audit('bus.updated', 'bus', $busId, json_encode(['name' => $name, 'bus_number' => $number]));
            $ok = 'Bus updated.';
        } elseif ($action === 'create_bus') {
            $name = trim((string) ($_POST['name'] ?? ''));
            $number = trim((string) ($_POST['bus_number'] ?? ''));
            $layout = (string) ($_POST['layout_type'] ?? '2+2');
            $rows = max(1, (int) ($_POST['normal_rows'] ?? 0));
            $front = max(0, (int) ($_POST['front_single_count'] ?? 0));
            $last = max(1, (int) ($_POST['last_row_seats'] ?? 0));

            $perMap = [
                '1+2' => 3,
                '2+1' => 3,
                '2+2' => 4,
                '2+3' => 5,
                'LEGACY5' => 5,
            ];
            $per = $perMap[$layout] ?? 4;

            if ($name === '') {
                throw new RuntimeException('Bus name is required.');
            }

            $total = $front + ($rows * $per) + $last;
            $db->beginTransaction();

            $busOrgColumn = (int) $db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='buses' AND COLUMN_NAME='organization_id'")->fetchColumn() > 0;
            if ($busOrgColumn) {
                $q = $db->prepare("INSERT INTO buses(organization_id,tour_id,name,bus_number,layout_type,total_seats,normal_rows,front_single_count,last_row_seats,status) VALUES(?,?,?,?,?,?,?,?,?,'ACTIVE')");
                $q->execute([$orgId, $tourId, $name, $number !== '' ? $number : null, $layout, $total, $rows, $front, $last]);
            } else {
                $q = $db->prepare("INSERT INTO buses(tour_id,name,bus_number,layout_type,total_seats,normal_rows,front_single_count,last_row_seats,status) VALUES(?,?,?,?,?,?,?,?,'ACTIVE')");
                $q->execute([$tourId, $name, $number !== '' ? $number : null, $layout, $total, $rows, $front, $last]);
            }

            $busId = (int) $db->lastInsertId();

            $seatTourColumn = (int) $db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='seats' AND COLUMN_NAME='tour_id'")->fetchColumn() > 0;
            $seatOrgColumn = (int) $db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='seats' AND COLUMN_NAME='organization_id'")->fetchColumn() > 0;
            $seatCodeColumn = (int) $db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='seats' AND COLUMN_NAME='seat_code'")->fetchColumn() > 0;
            $seatNoColumn = (int) $db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='seats' AND COLUMN_NAME='seat_no'")->fetchColumn() > 0;

            $columns = [];
            if ($seatOrgColumn) {
                $columns[] = 'organization_id';
            }
            if ($seatTourColumn) {
                $columns[] = 'tour_id';
            }
            $columns[] = 'bus_id';
            if ($seatCodeColumn) {
                $columns[] = 'seat_code';
            }
            if ($seatNoColumn) {
                $columns[] = 'seat_no';
            }
            $columns[] = 'row_no';
            $columns[] = 'position';
            $columns[] = 'status';

            $placeholders = rtrim(str_repeat('?,', count($columns)), ',');
            $q = $db->prepare('INSERT INTO seats(' . implode(',', $columns) . ') VALUES(' . $placeholders . ')');

            $insertSeat = function (string $code, int $row, string $position) use ($q, $seatOrgColumn, $seatTourColumn, $seatCodeColumn, $seatNoColumn, $orgId, $tourId, $busId): void {
                $params = [];
                if ($seatOrgColumn) {
                    $params[] = $orgId;
                }
                if ($seatTourColumn) {
                    $params[] = $tourId;
                }
                $params[] = $busId;
                if ($seatCodeColumn) {
                    $params[] = $code;
                }
                if ($seatNoColumn) {
                    $params[] = $code;
                }
                $params[] = $row;
                $params[] = $position;
                $params[] = 'AVAILABLE';
                $q->execute($params);
            };

            $n = 0;
            for ($i = 1; $i <= $front; $i++) {
                $n++;
                $insertSeat('S' . $i, 0, 'FRONT_SINGLE');
            }
            for ($r = 1; $r <= $rows; $r++) {
                for ($p = 1; $p <= $per; $p++) {
                    $n++;
                    $position = $p <= intdiv($per + 1, 2) ? 'LEFT' : 'RIGHT';
                    $insertSeat(chr(64 + $r) . $p, $r, $position);
                }
            }
            $lastRow = $rows + 1;
            for ($p = 1; $p <= $last; $p++) {
                $n++;
                $insertSeat('L' . $p, $lastRow, 'LAST');
            }

            $db->commit();
            saas_audit('bus.created', 'bus', $busId, json_encode(['name' => $name, 'layout' => $layout]));
            $ok = 'Bus created with ' . $n . ' seats.';
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error = $e->getMessage();
    }
}

$q = $db->prepare('SELECT * FROM buses WHERE tour_id=? ORDER BY id');
$q->execute([$tourId]);
$buses = $q->fetchAll();

$selectedId = (int) ($_GET['bus_id'] ?? 0);
if (!$selectedId && $buses) {
    $selectedId = (int) $buses[0]['id'];
}
$selected = null;
foreach ($buses as $busItem) {
    if ((int) $busItem['id'] === $selectedId) {
        $selected = $busItem;
        break;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Buses &amp; Seats — GoTM</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f5f7fb;color:#172033;font-family:Inter,Arial,sans-serif}.wrap{max-width:1320px;margin:0 auto;padding:26px 24px 60px}.top{display:flex;align-items:center;justify-content:space-between;gap:15px;margin-bottom:20px}.crumb{font-size:12px;color:#667085;margin-bottom:6px}.title h1{margin:0;font-size:30px}.title p{margin:6px 0 0;color:#667085}.btn{display:inline-flex;align-items:center;gap:6px;padding:10px 14px;border:1px solid #d0d5dd;border-radius:10px;background:#fff;color:#172033;text-decoration:none;font-weight:800;font-size:12px;cursor:pointer}.btn.primary{background:#155eef;color:#fff;border-color:#155eef}.btn.danger{color:#b42318;border-color:#fda29b;background:#fff}.alert{padding:12px 15px;border-radius:12px;margin-bottom:18px;font-size:13px}.msg{background:#ecfdf3;color:#067647}.err{background:#fef3f2;color:#b42318}.grid-main{display:grid;grid-template-columns:minmax(400px,.9fr) minmax(520px,1.35fr);gap:18px;align-items:start}.card{background:#fff;border:1px solid #e5e7eb;border-radius:18px;padding:20px;margin-bottom:18px;box-shadow:0 8px 30px #1018280a}.card h2{margin:0 0 15px;font-size:19px}.sub{color:#667085;font-size:12px;margin:-8px 0 15px}.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.field.full{grid-column:1/-1}.field label{display:block;font-size:12px;font-weight:800;margin-bottom:5px}.field input,.field select{width:100%;padding:11px 12px;border:1px solid #d0d5dd;border-radius:10px;font:inherit;background:#fff}.actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}.bus-list{display:grid;gap:9px}.bus-row{display:flex;align-items:center;justify-content:space-between;gap:12px;border:1px solid #eaecf0;border-radius:13px;padding:12px}.bus-row.selected{border-color:#84adff;background:#f5f9ff}.bus-info{min-width:0}.bus-info strong{display:block;font-size:14px}.bus-info small{display:block;color:#667085;margin-top:3px}.chips{display:flex;gap:6px;flex-wrap:wrap;margin-top:6px}.chip{font-size:10px;font-weight:800;background:#f2f4f7;border-radius:99px;padding:4px 7px}.chip.green{background:#dcfae6;color:#067647}.row-actions{display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end}.row-actions form{margin:0}.seat-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:15px}.seat-head h2{margin:0 0 5px}.seat-meta{text-align:right;color:#667085;font-size:12px}.seat-map{border:1px solid #e5e7eb;border-radius:15px;background:#fafbfc;padding:15px;max-height:660px;overflow:auto}.seat-row{display:grid;grid-template-columns:minmax(0,1fr) 30px minmax(0,1fr);gap:8px;margin:8px 0}.seat-side{display:flex;gap:7px;min-width:0}.aisle{border-left:1px dashed #cfd4dc;border-right:1px dashed #cfd4dc;border-radius:7px}.seat{min-width:0;flex:1;border:1px solid #86efac;border-radius:9px;background:#dcfae6;padding:8px 4px;text-align:center;font-size:11px;font-weight:900}.seat.occupied{background:#d1fadf;border-color:#34d399}.seat small{display:block;font-size:8px;font-weight:600;color:#475467;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:3px}.front{display:flex;justify-content:center;margin-bottom:10px;padding:8px;background:#f2f4f7;border-radius:10px;color:#667085;font-size:11px;font-weight:800}.legend{display:flex;gap:12px;flex-wrap:wrap;margin-top:12px;font-size:11px;color:#667085}.dot{display:inline-block;width:9px;height:9px;border-radius:50%;background:#dcfae6;border:1px solid #86efac;margin-right:4px}.dot.o{background:#d1fadf;border-color:#34d399}.empty{padding:35px;text-align:center;border:1px dashed #d0d5dd;border-radius:14px;color:#667085}@media(max-width:1000px){.grid-main{grid-template-columns:1fr}.seat-map{max-height:none}.seat-meta{text-align:left}}@media(max-width:600px){.wrap{padding:18px 12px}.top{align-items:flex-start;flex-direction:column}.form-grid{grid-template-columns:1fr}.field.full{grid-column:auto}.bus-row{align-items:flex-start;flex-direction:column}.row-actions{justify-content:flex-start}}
</style>
</head>
<body>
<main class="wrap">
<div class="top">
<div class="title"><div class="crumb">Tour dashboard &nbsp;›&nbsp; Buses &amp; Seats</div><h1>🚌 Buses &amp; Seats</h1><p>Manage tour buses, seat layouts and passenger seating.</p></div>
<a class="btn" href="dashboard.php">← Back to Tour Dashboard</a>
</div>

<?php if ($ok !== ''): ?><div class="alert msg"><?php echo saas_h($ok); ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="alert err"><?php echo saas_h($error); ?></div><?php endif; ?>

<div class="grid-main">
<div>
<section class="card">
<h2>➕ Add New Bus</h2>
<div class="sub">Create a bus and automatically generate its seats.</div>
<form method="post">
<input type="hidden" name="csrf" value="<?php echo saas_h(saas_csrf()); ?>">
<input type="hidden" name="action" value="create_bus">
<div class="form-grid">
<div class="field full"><label>Bus name *</label><input name="name" placeholder="Premium Coach" required></div>
<div class="field full"><label>Bus number</label><input name="bus_number" placeholder="Dhaka Metro BA-11-2222"></div>
<div class="field"><label>Layout</label><select name="layout_type"><option value="2+2">2 + 2</option><option value="2+3">2 + 3</option><option value="1+2">1 + 2 Premium</option><option value="2+1">2 + 1 Premium</option><option value="LEGACY5">Legacy 5-seat</option></select></div>
<div class="field"><label>Normal rows</label><input type="number" name="normal_rows" min="1" value="10" required></div>
<div class="field"><label>Front single seats</label><input type="number" name="front_single_count" min="0" value="0"></div>
<div class="field"><label>Last row seats</label><input type="number" name="last_row_seats" min="1" value="4" required></div>
</div>
<div class="actions"><button class="btn primary" type="submit">Create Bus &amp; Seats</button></div>
</form>
</section>

<section class="card">
<h2>Your Buses <span class="chip"><?php echo count($buses); ?></span></h2>
<div class="sub">Select a bus to preview its seat plan on the right.</div>
<div class="bus-list">
<?php foreach ($buses as $b): ?>
<div class="bus-row <?php echo (int)$b['id'] === $selectedId ? 'selected' : ''; ?>">
<div class="bus-info"><strong><?php echo saas_h($b['name']); ?></strong><small><?php echo saas_h($b['bus_number'] ?? 'No bus number'); ?></small><div class="chips"><span class="chip"><?php echo saas_h($b['layout_type']); ?></span><span class="chip green"><?php echo saas_h($b['total_seats']); ?> seats</span><span class="chip">Active</span></div></div>
<div class="row-actions"><a class="btn" href="buses.php?bus_id=<?php echo (int)$b['id']; ?>">View</a><a class="btn" href="buses.php?bus_id=<?php echo (int)$b['id']; ?>#edit">Edit</a><form method="post" onsubmit="return confirm('Delete this bus and its seats? This is only allowed when no passenger is assigned.');"><input type="hidden" name="csrf" value="<?php echo saas_h(saas_csrf()); ?>"><input type="hidden" name="action" value="delete_bus"><input type="hidden" name="bus_id" value="<?php echo (int)$b['id']; ?>"><button class="btn danger" type="submit">Delete</button></form></div>
</div>
<?php endforeach; ?>
<?php if (!$buses): ?><div class="empty">No buses yet. Create your first bus above.</div><?php endif; ?>
</div>
</section>

<?php if ($selected): ?>
<section class="card" id="edit">
<h2>✎ Edit Bus</h2>
<div class="sub">Update the bus identity without disturbing existing seat assignments.</div>
<form method="post">
<input type="hidden" name="csrf" value="<?php echo saas_h(saas_csrf()); ?>">
<input type="hidden" name="action" value="update_bus">
<input type="hidden" name="bus_id" value="<?php echo (int)$selected['id']; ?>">
<div class="form-grid">
<div class="field"><label>Bus name *</label><input name="name" value="<?php echo saas_h($selected['name']); ?>" required></div>
<div class="field"><label>Bus number</label><input name="bus_number" value="<?php echo saas_h($selected['bus_number'] ?? ''); ?>"></div>
</div>
<div class="actions"><button class="btn primary" type="submit">Save Changes</button><a class="btn" href="buses.php?bus_id=<?php echo (int)$selected['id']; ?>">Cancel</a></div>
</form>
</section>
<?php endif; ?>
</div>

<div>
<section class="card">
<div class="seat-head"><div><h2>Seat Plan</h2><?php if ($selected): ?><div class="sub">Live seat preview for <strong><?php echo saas_h($selected['name']); ?></strong></div><?php endif; ?></div><?php if ($selected): ?><div class="seat-meta"><?php echo saas_h($selected['bus_number'] ?? ''); ?><br><strong><?php echo saas_h($selected['layout_type']); ?> · <?php echo saas_h($selected['total_seats']); ?> seats</strong></div><?php endif; ?></div>
<?php if ($selected): ?>
<?php
$sq = $db->prepare("SELECT s.id,s.seat_code,s.row_no,pp.full_name FROM seats s LEFT JOIN passenger_seat_assignments pa ON pa.seat_id=s.id AND pa.bus_id=s.bus_id LEFT JOIN tour_passengers tp ON tp.id=pa.tour_passenger_id LEFT JOIN passenger_profiles pp ON pp.id=tp.passenger_profile_id WHERE s.bus_id=? ORDER BY s.row_no,s.id");
$sq->execute([(int)$selected['id']]);
$selectedSeats = $sq->fetchAll();
$seatRows = [];
foreach ($selectedSeats as $ss) {
    $seatRows[(int)$ss['row_no']][] = $ss;
}
?>
<div class="front">FRONT / DRIVER</div>
<div class="seat-map">
<?php foreach ($seatRows as $rn => $items): ?>
<?php
$layout = strtolower(str_replace('x', '+', trim((string)$selected['layout_type'])));
$parts = preg_split('/s*+s*/', $layout);
$leftCount = $rn === 0 ? count($items) : (count($parts) >= 2 ? min(max(1, (int)$parts[0]), max(1, count($items) - 1)) : max(1, (int)ceil(count($items) / 2)));
$left = array_slice($items, 0, $leftCount);
$right = array_slice($items, $leftCount);
?>
<div class="seat-row">
<div class="seat-side">
<?php foreach ($left as $s): ?>
<div class="seat <?php echo !empty($s['full_name']) ? 'occupied' : ''; ?>"><strong><?php echo saas_h($s['seat_code']); ?></strong><small><?php echo saas_h(!empty($s['full_name']) ? mb_substr($s['full_name'], 0, 13) : 'Available'); ?></small></div>
<?php endforeach; ?>
</div>
<div class="aisle"></div>
<div class="seat-side">
<?php foreach ($right as $s): ?>
<div class="seat <?php echo !empty($s['full_name']) ? 'occupied' : ''; ?>"><strong><?php echo saas_h($s['seat_code']); ?></strong><small><?php echo saas_h(!empty($s['full_name']) ? mb_substr($s['full_name'], 0, 13) : 'Available'); ?></small></div>
<?php endforeach; ?>
</div>
</div>
<?php endforeach; ?>
</div>
<div class="legend"><span><i class="dot"></i> Available</span><span><i class="dot o"></i> Occupied</span></div>
<div class="actions"><a class="btn primary" href="seat_plan.php?bus_id=<?php echo (int)$selected['id']; ?>">Open Full Seat Plan</a><a class="btn" href="dashboard.php">Back to Dashboard</a></div>
<?php else: ?>
<div class="empty">Select a bus from the list to see its seat plan here.</div>
<?php endif; ?>
</section>
</div>
</div>
</main>
</body>
</html>