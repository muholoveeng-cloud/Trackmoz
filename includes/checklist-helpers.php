<?php
/**
 * Checklists operacionais para modo condução (Módulo 8).
 */
require_once __DIR__ . '/helpers.php';

function checklist_definicoes(): array
{
    return [
        'pre_viagem' => [
            'titulo' => 'Checklist pré-viagem',
            'items'  => [
                'doc_veiculo'   => 'Documentos do veículo válidos (livrete, seguro, inspecção)',
                'doc_motorista' => 'Carta de condução e documentos pessoais',
                'viatura_ok'    => 'Viatura em condições (pneus, luzes, travões)',
                'carga_prevista'=> 'Tipo e peso da carga confirmados',
                'rota_planeada' => 'Rota e contactos da empresa revistos',
            ],
        ],
        'recolha' => [
            'titulo' => 'Checklist de recolha',
            'items'  => [
                'carga_conferida'  => 'Carga conferida com documento/guia',
                'embalagem_ok'     => 'Embalagem integra, sem danos visíveis',
                'peso_registado'   => 'Peso/volume conforme ordem de transporte',
                'fotos_carga'      => 'Fotos da carga registadas (se aplicável)',
            ],
        ],
        'entrega' => [
            'titulo' => 'Checklist de entrega',
            'items'  => [
                'destinatario_id'  => 'Destinatário identificado',
                'carga_integra'    => 'Carga entregue em bom estado',
                'documentos_assin' => 'Documentos assinados/recolhidos',
                'local_confirmado' => 'Local de entrega confirmado',
            ],
        ],
    ];
}

function checklist_itens(string $fase): array
{
    $defs = checklist_definicoes();
    return $defs[$fase]['items'] ?? [];
}

function checklist_titulo(string $fase): string
{
    $defs = checklist_definicoes();
    return $defs[$fase]['titulo'] ?? 'Checklist';
}

function checklist_registar(PDO $conn, int $missaoId, int $userId, string $fase, array $marcados): bool
{
    $items = checklist_itens($fase);
    if (empty($items)) {
        return false;
    }

    $total = count($items);
    $ok = 0;
    foreach (array_keys($items) as $key) {
        if (!empty($marcados[$key])) {
            $ok++;
        }
    }

    if ($ok < $total) {
        return false;
    }

    $payload = json_encode(['fase' => $fase, 'items' => $marcados], JSON_UNESCAPED_UNICODE);
    $desc = checklist_titulo($fase) . ' concluído (' . $ok . '/' . $total . ')';

    try {
        $stmt = $conn->prepare(
            'INSERT INTO registros_viagem (missao_id, tipo, descricao, data_registro)
             VALUES (:mid, :tipo, :desc, NOW())'
        );
        $stmt->execute([
            ':mid'  => $missaoId,
            ':tipo' => 'checklist_' . $fase,
            ':desc' => $desc . ' | ' . $payload,
        ]);
        if (function_exists('registrar_log')) {
            registrar_log($conn, $userId, 'checklist', 'missao', $missaoId, $desc);
        }
        return true;
    } catch (Throwable $e) {
        error_log('checklist_registar: ' . $e->getMessage());
        return false;
    }
}

function checklist_fase_concluida(PDO $conn, int $missaoId, string $fase): bool
{
    try {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) FROM registros_viagem
             WHERE missao_id = :mid AND tipo = :tipo"
        );
        $stmt->execute([':mid' => $missaoId, ':tipo' => 'checklist_' . $fase]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function checklist_estado_missao(PDO $conn, int $missaoId): array
{
    return [
        'pre_viagem' => checklist_fase_concluida($conn, $missaoId, 'pre_viagem'),
        'recolha'    => checklist_fase_concluida($conn, $missaoId, 'recolha'),
        'entrega'    => checklist_fase_concluida($conn, $missaoId, 'entrega'),
    ];
}
