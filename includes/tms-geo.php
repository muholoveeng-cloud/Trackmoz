<?php
/**
 * Utilitários geoespaciais TMS.
 */
require_once __DIR__ . '/missao-helpers.php';

function tms_gps_tabelas_prontas(PDO $conn): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    try {
        $stmt = $conn->query("SHOW TABLES LIKE 'gps_locations'");
        $cache = (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        $cache = false;
    }
    return $cache;
}

function tms_distancia_metros(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $r = 6371000;
    $toR = static fn(float $d) => $d * M_PI / 180;
    $dLat = $toR($lat2 - $lat1);
    $dLng = $toR($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2
        + cos($toR($lat1)) * cos($toR($lat2)) * sin($dLng / 2) ** 2;
    return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

function tms_emitir_evento_realtime(
    PDO $conn,
    string $type,
    ?int $missionId,
    ?int $driverId,
    array $payload = []
): void {
    if (!tms_gps_tabelas_prontas($conn)) {
        return;
    }
    try {
        $conn->prepare(
            'INSERT INTO realtime_events (event_type, mission_id, driver_id, payload_json)
             VALUES (:t, :mid, :did, :p)'
        )->execute([
            ':t' => $type,
            ':mid' => $missionId,
            ':did' => $driverId,
            ':p' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
    } catch (Throwable $e) {
        error_log('tms_emitir_evento_realtime: ' . $e->getMessage());
    }
}
