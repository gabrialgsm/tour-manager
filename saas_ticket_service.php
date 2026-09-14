<?php
declare(strict_types=1);

/**
 * Issue one active QR ticket for a passenger.
 * Caller must already be inside a transaction and should lock the passenger.
 * When $forceReissue is true, an existing ISSUED ticket is revoked first.
 */
function saas_issue_ticket(PDO $db, int $tourId, int $passengerId, int $organizationId, string $defaultTemplate = 'classic', bool $forceReissue = false): array
{
    $q = $db->prepare("SELECT tp.id,tp.status,t.organization_id FROM tour_passengers tp JOIN tours t ON t.id=tp.tour_id WHERE tp.id=? AND tp.tour_id=? AND t.organization_id=? FOR UPDATE");
    $q->execute([$passengerId, $tourId, $organizationId]);
    $passenger = $q->fetch();
    if (!$passenger || $passenger['status'] !== 'ACTIVE') {
        throw new RuntimeException('Passenger is not active.');
    }

    $q = $db->prepare("SELECT * FROM ticket_instances WHERE tour_passenger_id=? AND tour_id=? AND organization_id=? AND status='ISSUED' ORDER BY id DESC LIMIT 1 FOR UPDATE");
    $q->execute([$passengerId, $tourId, $organizationId]);
    $current = $q->fetch();
    if ($current && !$forceReissue) {
        return $current;
    }

    if ($current) {
        $q = $db->prepare("UPDATE ticket_instances SET qr_token_revoked_at=NOW(),status='VOID',voided_at=NOW(),updated_at=NOW() WHERE id=?");
        $q->execute([(int)$current['id']]);
    }

    $template = in_array($defaultTemplate, ['classic','travel','event'], true) ? $defaultTemplate : 'classic';
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $ticketNumber = 'TM-'.date('Ym').'-'.strtoupper(bin2hex(random_bytes(4)));
        $qrToken = hash_hmac('sha256', $ticketNumber, saas_app_key());
        $hash = hash('sha256', $qrToken);
        try {
            $q = $db->prepare("INSERT INTO ticket_instances(tour_passenger_id,organization_id,tour_id,ticket_number,qr_token_hash,status,template_key) VALUES(?,?,?,?,?,'ISSUED',?)");
            $q->execute([$passengerId,$organizationId,$tourId,$ticketNumber,$hash,$template]);
            $id = (int)$db->lastInsertId();
            return ['id'=>$id,'ticket_number'=>$ticketNumber,'status'=>'ISSUED','qr_token_hash'=>$hash];
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) !== 1062 || $attempt === 4) {
                throw $e;
            }
        }
    }
    throw new RuntimeException('Could not generate a unique ticket number.');
}
