<?php
require __DIR__.'/bootstrap.php';
require_login();
$tid = require_tour();

$departure = trim($_GET['departure'] ?? '');
$bus_id    = (int)($_GET['bus_id'] ?? 0);
$search    = trim($_GET['search'] ?? '');
$format    = strtolower(trim($_GET['format'] ?? 'csv'));

$ids = array_values(array_filter(
    array_map('intval', explode(',', $_GET['ids'] ?? '')),
    fn($id) => $id > 0
));

$sql = "
    SELECT
        p.name,
        p.phone,
        p.address,
        p.blood_group,
        p.emergency_contact,
        p.departure,
        p.room_type,
        p.tour_fee,
        p.discount,
        p.final_fee,
        b.name AS bus_name,
        b.bus_number,
        s.seat_no,
        (
            SELECT r.room_no
            FROM room_assignments ra
            JOIN rooms r ON r.id=ra.room_id
            WHERE ra.passenger_id=p.id
            LIMIT 1
        ) AS room_no,
        COALESCE((
            SELECT SUM(amount)
            FROM payments pay
            WHERE pay.passenger_id=p.id
        ),0) AS paid
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

if ($ids) {
    $sql .= " AND p.id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")";
    $params = array_merge($params, $ids);
}

$sql .= " ORDER BY p.departure, b.id, s.row_no, s.col_no";

$q = db()->prepare($sql);
$q->execute($params);
$rows = $q->fetchAll();

$stamp = date('Y-m-d_H-i-s');
$filenameBase = 'gmjs_passengers_' . $stamp;

$headers = [
    'Name',
    'Phone',
    'Address',
    'Blood Group',
    'Emergency Contact',
    'Departure',
    'Bus',
    'Bus Number',
    'Seat',
    'Room',
    'Room Type',
    'Tour Fee',
    'Discount',
    'Paid',
    'Due'
];

$dataRows = [];
foreach ($rows as $p) {
    $dataRows[] = [
        $p['name'],
        $p['phone'],
        $p['address'],
        $p['blood_group'],
        $p['emergency_contact'],
        $p['departure'],
        $p['bus_name'],
        $p['bus_number'],
        $p['seat_no'],
        $p['room_no'],
        room_label($p['room_type']),
        $p['final_fee'],
        $p['discount'],
        $p['paid'],
        max(0, (float)$p['final_fee'] - (float)$p['paid'])
    ];
}

/* Excel-compatible .xls export */
if ($format === 'excel' || $format === 'xls') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$filenameBase.'.xls"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo '<!doctype html><html><head><meta charset="utf-8"></head><body>';
    echo '<table border="1">';
    echo '<tr>';
    foreach ($headers as $header) {
        echo '<th>'.htmlspecialchars($header, ENT_QUOTES, 'UTF-8').'</th>';
    }
    echo '</tr>';

    foreach ($dataRows as $row) {
        echo '<tr>';
        foreach ($row as $value) {
            echo '<td>'.htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8').'</td>';
        }
        echo '</tr>';
    }

    echo '</table></body></html>';

    audit(
        'EXPORT_PASSENGERS_EXCEL',
        'tour',
        $tid,
        $departure ?: ($bus_id ? 'BUS_'.$bus_id : 'ALL')
    );
    exit;
}

/* CSV export */
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="'.$filenameBase.'.csv"');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');
fputs($out, "\xEF\xBB\xBF");
fputcsv($out, $headers);

foreach ($dataRows as $row) {
    fputcsv($out, $row);
}

fclose($out);

audit(
    'EXPORT_PASSENGERS_CSV',
    'tour',
    $tid,
    $departure ?: ($bus_id ? 'BUS_'.$bus_id : 'ALL')
);
exit;
