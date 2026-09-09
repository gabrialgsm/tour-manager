<?php
require __DIR__ . '/../bootstrap.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

try {
    $busId = (int)($_GET['bus_id'] ?? 0);

    if ($busId <= 0) {
        http_response_code(400);
        echo json_encode(['ok'=>false, 'message'=>'Invalid bus ID.']);
        exit;
    }

    /*
     * Return the traditional bare array so existing callers continue
     * to work. The dashboard JS also accepts wrapped responses.
     */
    $q = db()->prepare(
        "SELECT s.id,s.bus_id,s.seat_no,s.row_no,s.col_no,
                p.id AS passenger_id
         FROM seats s
         LEFT JOIN passengers p
           ON p.seat_id=s.id
          AND p.status='ACTIVE'
         WHERE s.bus_id=?
         ORDER BY s.row_no,s.col_no,s.id"
    );
    $q->execute([$busId]);

    echo json_encode($q->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok'=>false,
        'message'=>'Unable to load seat data.'
    ], JSON_UNESCAPED_UNICODE);
}
