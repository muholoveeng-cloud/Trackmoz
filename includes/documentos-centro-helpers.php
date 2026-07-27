<?php
/**
 * Centro de documentos por missão (Módulo 4).
 */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/documentos-registry.php';

/** Tipos de documento geráveis com links. */
function tmz_centro_tipos_missao(): array
{
    return [
        'missao_registo'         => ['label' => 'Registo da missão',     'icon' => 'bi-file-text',           'path' => 'missao-registo.php?id=%d'],
        'contrato_transporte'    => ['label' => 'Contrato de transporte','icon' => 'bi-file-earmark-ruled',  'path' => 'contrato-transporte.php?missao=%d'],
        'ordem_transporte'       => ['label' => 'Ordem de transporte', 'icon' => 'bi-truck',               'path' => 'ordem-transporte.php?id=%d'],
        'comprovativo_conclusao' => ['label' => 'Comprovativo conclusão','icon' => 'bi-patch-check',         'path' => 'comprovativo-conclusao.php?id=%d'],
        'fatura'                 => ['label' => 'Factura',               'icon' => 'bi-receipt',             'path' => 'fatura.php?missao=%d'],
        'recibo'                 => ['label' => 'Recibo',                'icon' => 'bi-cash-stack',          'path' => 'recibo.php?missao=%d'],
        'avaliacao'              => ['label' => 'Avaliação',             'icon' => 'bi-star',                'path' => 'avaliacao.php?missao=%d'],
        'termo_responsabilidade' => ['label' => 'Termo responsabilidade','icon' => 'bi-shield-check',        'path' => 'termo-responsabilidade.php?missao=%d'],
        'relatorio_incidente'    => ['label' => 'Relatório incidente',   'icon' => 'bi-exclamation-triangle','path' => 'relatorio-incidente.php?missao=%d'],
    ];
}

function tmz_docs_listar_missao(PDO $conn, int $missaoId): array
{
    if ($missaoId <= 0) {
        return [];
    }
    tmz_docs_bootstrap($conn);
    try {
        $stmt = $conn->prepare(
            'SELECT id, titulo, tipo, numero_documento, tracking_id, status, data_emissao, url_visualizacao
             FROM documentos_sistema WHERE missao_id = :mid ORDER BY data_emissao DESC'
        );
        $stmt->execute([':mid' => $missaoId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function tmz_centro_documentos_render(PDO $conn, int $missaoId, string $basePath = 'documentos/'): string
{
    $emitidos = tmz_docs_listar_missao($conn, $missaoId);
    $porTipo  = [];
    foreach ($emitidos as $doc) {
        $porTipo[$doc['tipo'] ?? ''] = $doc;
    }

    $html = '<div class="list-group list-group-flush tm-doc-centro">';
    foreach (tmz_centro_tipos_missao() as $tipo => $meta) {
        $url  = $basePath . sprintf($meta['path'], $missaoId);
        $doc  = $porTipo[$tipo] ?? null;
        $badge = $doc
            ? '<span class="badge bg-success-subtle text-success border">Emitido</span>'
            : '<span class="badge bg-light text-muted border">Gerar</span>';
        $num = $doc ? '<small class="text-muted d-block">' . e($doc['numero_documento'] ?? '') . '</small>' : '';

        $html .= '<a href="' . e($url) . '" target="_blank" class="list-group-item list-group-item-action d-flex align-items-center gap-2 py-2">';
        $html .= '<i class="bi ' . e($meta['icon']) . ' text-primary"></i>';
        $html .= '<div class="flex-grow-1"><div class="fw-semibold small">' . e($meta['label']) . '</div>' . $num . '</div>';
        $html .= $badge . '</a>';
    }

    if (!empty($emitidos)) {
        $html .= '<div class="list-group-item bg-light py-2 small fw-semibold text-muted">Registos emitidos (' . count($emitidos) . ')</div>';
        foreach ($emitidos as $doc) {
            $href = !empty($doc['url_visualizacao']) ? $doc['url_visualizacao'] : '#';
            $html .= '<div class="list-group-item py-2 small d-flex justify-content-between">';
            $html .= '<span>' . e($doc['titulo'] ?? $doc['tipo']) . '</span>';
            if ($href !== '#') {
                $html .= '<a href="' . e($href) . '" target="_blank" class="text-primary">Abrir</a>';
            }
            $html .= '</div>';
        }
    }

    $html .= '</div>';
    return $html;
}
