<?php
/**
 * API: Stream SSE de eventos em tempo real.
 * GET: last_id=0&mission_id= (opcional)
 */
session_start();
include_once('../config/database.php');
include_once('../includes/localizacao-service.php');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit;
}

$user_id   = (int)$_SESSION['user_id'];
$user_type = $_SESSION['user_type'] ?? '';
$lastId    = isset($_GET['last_id']) ? (int)$_GET['last_id'] : 0;
$missionId = isset($_GET['mission_id']) ? (int)$_GET['mission_id'] : 0;

// Libertar o lock da sessão: o stream fica aberto ~60s e, sem isto,
// qualquer outra página PHP (ex.: Voltar) fica bloqueada à espera.
session_write_close();

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

if (ob_get_level()) {
    ob_end_clean();
}

ignore_user_abort(true);
set_time_limit(0);

$maxIter = 30;
$iter = 0;

while ($iter < $maxIter && !connection_aborted()) {
    try {
        if (!tms_gps_tabelas_prontas($conn)) {
            echo "data: " . json_encode(['ok' => false, 'error' => 'Tabelas TMS não migradas']) . "\n\n";
            flush();
            break;
        }

        $sql = 'SELECT id, event_type, mission_id, driver_id, payload_json, created_at
                FROM realtime_events WHERE id > :lid';
        $params = [':lid' => $lastId];

        if ($missionId > 0) {
            $sql .= ' AND mission_id = :mid';
            $params[':mid'] = $missionId;
        } elseif ($user_type === 'empresa') {
            $sql .= ' AND mission_id IN (SELECT id FROM missoes WHERE empresa_id = :uid)';
            $params[':uid'] = $user_id;
        } elseif ($user_type === 'transportador') {
            $sql .= ' AND mission_id IN (SELECT id FROM missoes WHERE transportador_id = :uid)';
            $params[':uid'] = $user_id;
        } elseif ($user_type === 'caminhoneiro') {
            $sql .= ' AND (driver_id = :uid OR mission_id IN (SELECT id FROM missoes WHERE caminhoneiro_id = :uid2))';
            $params[':uid'] = $user_id;
            $params[':uid2'] = $user_id;
        }

        $sql .= ' ORDER BY id ASC LIMIT 50';
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($events as $ev) {
            $lastId = (int)$ev['id'];
            $payload = [
                'id'         => $lastId,
                'type'       => $ev['event_type'],
                'mission_id' => $ev['mission_id'] ? (int)$ev['mission_id'] : null,
                'driver_id'  => $ev['driver_id'] ? (int)$ev['driver_id'] : null,
                'data'       => json_decode($ev['payload_json'] ?? '{}', true),
                'created_at' => $ev['created_at'],
            ];
            echo "id: {$lastId}\n";
            echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
            flush();
        }

        if (!$events) {
            echo ": heartbeat\n\n";
            flush();
        }
    } catch (Throwable $e) {
        error_log('realtime-stream: ' . $e->getMessage());
    }

    sleep(2);
    $iter++;
}

echo "data: " . json_encode(['ok' => true, 'reconnect' => true, 'last_id' => $lastId]) . "\n\n";
flush();
