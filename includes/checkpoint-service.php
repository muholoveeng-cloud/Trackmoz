<?php
/**
 * Check-in automático por proximidade GPS (raio 100m).
 */
require_once __DIR__ . '/tms-geo.php';
require_once __DIR__ . '/missao-helpers.php';

const TMS_CHECKPOINT_RAIO_M = 100;

/**
 * @return array|null Checkpoint criado ou null
 */
function tms_processar_checkpoints(PDO $conn, int $missionId, int $driverId, float $lat, float $lng): ?array
{
    if (!tms_gps_tabelas_prontas($conn)) {
        return null;
    }

    $stmt = $conn->prepare(
        'SELECT m.status, m.status_viagem, m.status_entrega,
                lo.latitude AS olat, lo.longitude AS olng,
                ld.latitude AS dlat, ld.longitude AS dlng
         FROM missoes m
         LEFT JOIN locais lo ON m.local_origem_id = lo.id
         LEFT JOIN locais ld ON m.local_destino_id = ld.id
         WHERE m.id = :id AND m.caminhoneiro_id = :did'
    );
    $stmt->execute([':id' => $missionId, ':did' => $driverId]);
    $m = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$m || in_array($m['status'], ['concluida', 'cancelada'], true)) {
        return null;
    }

    $distOrigem  = ($m['olat'] && $m['olng'])
        ? tms_distancia_metros($lat, $lng, (float)$m['olat'], (float)$m['olng']) : null;
    $distDestino = ($m['dlat'] && $m['dlng'])
        ? tms_distancia_metros($lat, $lng, (float)$m['dlat'], (float)$m['dlng']) : null;

    $sv = $m['status_viagem'] ?? 'nao_iniciada';
    $checkpoint = null;

    if ($distOrigem !== null && $distOrigem <= TMS_CHECKPOINT_RAIO_M) {
        if (!in_array($sv, ['aguardando_recolha', 'carga_recolhida', 'em_transito', 'entrega', 'finalizada'], true)) {
            $checkpoint = tms_registar_checkpoint($conn, $missionId, $driverId, 'chegou_recolha', $lat, $lng, $distOrigem);
            $conn->prepare(
                "UPDATE missoes SET status = 'em_andamento', status_viagem = 'aguardando_recolha' WHERE id = :id"
            )->execute([':id' => $missionId]);
            registar_evento_viagem($conn, $missionId, 'checkpoint', 'Chegou ao local de recolha (automático)');
        }
    } elseif ($distOrigem !== null && $distOrigem > TMS_CHECKPOINT_RAIO_M * 2
        && $sv === 'aguardando_recolha') {
        $checkpoint = tms_registar_checkpoint($conn, $missionId, $driverId, 'carga_recolhida', $lat, $lng, $distOrigem);
        $conn->prepare(
            "UPDATE missoes SET status = 'em_transito', status_viagem = 'carga_recolhida', data_coleta = COALESCE(data_coleta, NOW()) WHERE id = :id"
        )->execute([':id' => $missionId]);
        registar_evento_viagem($conn, $missionId, 'checkpoint', 'Carga recolhida — saiu do local de recolha (automático)');
    }

    if ($distDestino !== null && $distDestino <= TMS_CHECKPOINT_RAIO_M
        && in_array($sv, ['carga_recolhida', 'em_transito'], true)) {
        if (!tms_checkpoint_existe($conn, $missionId, 'chegou_destino')) {
            $checkpoint = tms_registar_checkpoint($conn, $missionId, $driverId, 'chegou_destino', $lat, $lng, $distDestino);
            $setEntrega = coluna_existe($conn, 'missoes', 'status_entrega')
                ? ", status_entrega = 'chegou_destino'"
                : '';
            $conn->prepare(
                "UPDATE missoes SET status = 'em_entrega', status_viagem = 'entrega',
                 data_chegada = COALESCE(data_chegada, NOW()){$setEntrega} WHERE id = :id"
            )->execute([':id' => $missionId]);
            registar_evento_viagem($conn, $missionId, 'checkpoint', 'Chegou ao destino (automático)');
        }
    }

    return $checkpoint;
}

function tms_checkpoint_existe(PDO $conn, int $missionId, string $tipo): bool
{
    $stmt = $conn->prepare(
        'SELECT COUNT(*) FROM mission_checkpoints WHERE mission_id = :mid AND tipo = :t'
    );
    $stmt->execute([':mid' => $missionId, ':t' => $tipo]);
    return (bool)$stmt->fetchColumn();
}

function tms_registar_checkpoint(
    PDO $conn,
    int $missionId,
    int $driverId,
    string $tipo,
    float $lat,
    float $lng,
    float $distM
): array {
    $conn->prepare(
        'INSERT INTO mission_checkpoints
         (mission_id, driver_id, tipo, latitude, longitude, distancia_m, automatico)
         VALUES (:mid, :did, :t, :lat, :lng, :d, 1)'
    )->execute([
        ':mid' => $missionId, ':did' => $driverId, ':t' => $tipo,
        ':lat' => $lat, ':lng' => $lng, ':d' => $distM,
    ]);

    tms_emitir_evento_realtime($conn, 'checkpoint', $missionId, $driverId, [
        'tipo' => $tipo, 'distancia_m' => round($distM, 1),
    ]);

    return ['tipo' => $tipo, 'distancia_m' => round($distM, 1)];
}

/**
 * @return list<array<string, mixed>>
 */
function tms_listar_checkpoints(PDO $conn, int $missionId): array
{
    if (!tms_gps_tabelas_prontas($conn)) {
        return [];
    }
    $stmt = $conn->prepare(
        'SELECT tipo, latitude, longitude, distancia_m, automatico, created_at
         FROM mission_checkpoints WHERE mission_id = :mid ORDER BY created_at ASC'
    );
    $stmt->execute([':mid' => $missionId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
