<?php
require __DIR__.'/bootstrap.php';require __DIR__.'/bootstrap_auth.php';
require_login();
$tid = require_tour();

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        echo json_encode(['ok'=>true, 'csrf'=>csrf()]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok'=>false,'message'=>'Method not allowed.']);
        exit;
    }

    check_csrf();require_permission('seat.swap');

    $sourceSeatId = (int)($_POST['source_seat_id'] ?? 0);
    $targetSeatId = (int)($_POST['target_seat_id'] ?? 0);

    if (!$sourceSeatId || !$targetSeatId) {
        throw new Exception('Invalid source or target seat.');
    }

    if ($sourceSeatId === $targetSeatId) {
        echo json_encode(['ok'=>true,'message'=>'No seat change was made.','assignments'=>[]]);
        exit;
    }

    $pdo = db();
    $pdo->beginTransaction();

    // Lock both seat rows in a deterministic order to avoid concurrent swap deadlocks.
    $firstSeat = min($sourceSeatId, $targetSeatId);
    $secondSeat = max($sourceSeatId, $targetSeatId);

    $q = $pdo->prepare(
        "SELECT s.id,s.bus_id,s.seat_no,b.name AS bus_name
         FROM seats s
         JOIN buses b ON b.id=s.bus_id
         WHERE s.id IN (?,?) AND b.tour_id=?
         ORDER BY s.id
         FOR UPDATE"
    );
    $q->execute([$firstSeat,$secondSeat,$tid]);
    $seatRows = $q->fetchAll(PDO::FETCH_ASSOC);

    if (count($seatRows) !== 2) {
        throw new Exception('One or both seats are invalid for this tour.');
    }

    $seatById = [];
    foreach ($seatRows as $s) $seatById[(int)$s['id']] = $s;

    $sourceSeat = $seatById[$sourceSeatId];
    $targetSeat = $seatById[$targetSeatId];

    // Only active passengers participate in the seat assignment.
    $q = $pdo->prepare(
        "SELECT id,bus_id,seat_id,name,status
         FROM passengers
         WHERE tour_id=? AND status='ACTIVE' AND seat_id IN (?,?)
         FOR UPDATE"
    );
    $q->execute([$tid,$sourceSeatId,$targetSeatId]);
    $passengers = $q->fetchAll(PDO::FETCH_ASSOC);

    $bySeat = [];
    foreach ($passengers as $p) {
        $sid = (int)$p['seat_id'];
        if (isset($bySeat[$sid])) {
            throw new Exception('Seat data is inconsistent: more than one active passenger is assigned to a seat.');
        }
        $bySeat[$sid] = $p;
    }

    $sourcePassenger = $bySeat[$sourceSeatId] ?? null;
    $targetPassenger = $bySeat[$targetSeatId] ?? null;

    if (!$sourcePassenger) {
        throw new Exception('The source seat no longer has an active passenger. Please refresh the page.');
    }

    // Move source passenger into target seat.
    $q = $pdo->prepare(
        "UPDATE passengers
         SET bus_id=?, seat_id=?
         WHERE id=? AND tour_id=? AND status='ACTIVE'"
    );
    $q->execute([
        (int)$targetSeat['bus_id'],
        (int)$targetSeat['id'],
        (int)$sourcePassenger['id'],
        $tid
    ]);

    // If target was occupied, complete the swap by moving it into source seat.
    if ($targetPassenger) {
        $q->execute([
            (int)$sourceSeat['bus_id'],
            (int)$sourceSeat['id'],
            (int)$targetPassenger['id'],
            $tid
        ]);
    }

    audit(
        'SWAP_SEATS',
        'passenger',
        (int)$sourcePassenger['id'],
        $sourceSeat['seat_no'].' → '.$targetSeat['seat_no'].
        ($targetPassenger ? ' / '.$targetSeat['seat_no'].' → '.$sourceSeat['seat_no'] : '')
    );

    $pdo->commit();

    $assignments = [
        (string)$sourcePassenger['id'] => [
            'seat_id'=>(int)$targetSeat['id'],
            'bus_id'=>(int)$targetSeat['bus_id'],
            'seat_no'=>$targetSeat['seat_no'],
            'bus_name'=>$targetSeat['bus_name']
        ]
    ];

    if ($targetPassenger) {
        $assignments[(string)$targetPassenger['id']] = [
            'seat_id'=>(int)$sourceSeat['id'],
            'bus_id'=>(int)$sourceSeat['bus_id'],
            'seat_no'=>$sourceSeat['seat_no'],
            'bus_name'=>$sourceSeat['bus_name']
        ];
    }

    echo json_encode([
        'ok'=>true,
        'message'=>$targetPassenger ? 'Seats swapped successfully.' : 'Passenger moved successfully.',
        'assignments'=>$assignments
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();

    http_response_code(400);
    echo json_encode([
        'ok'=>false,
        'message'=>$e->getMessage()
    ]);
}
