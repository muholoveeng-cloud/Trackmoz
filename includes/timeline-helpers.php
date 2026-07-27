<?php
/**
 * Timeline operacional de missões (Módulo 5).
 */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/missao-helpers.php';

function timeline_label_evento(string $tipo, string $descricao = ''): array
{
    $map = [
        'aceitacao'              => ['Proposta aceita', 'bi-check-circle-fill'],
        'checklist_pre_viagem'   => ['Checklist pré-viagem', 'bi-list-check'],
        'checklist_recolha'      => ['Checklist recolha', 'bi-box-seam'],
        'checklist_entrega'      => ['Checklist entrega', 'bi-clipboard-check'],
        'confirmacao_entrega'    => ['Entrega confirmada', 'bi-patch-check-fill'],
        'chegou_origem'          => ['Chegada ao ponto de recolha', 'bi-geo'],
        'recolheu'               => ['Carga recolhida', 'bi-box-seam-fill'],
        'chegada_destino'        => ['Chegada ao destino', 'bi-flag-fill'],
        'emergencia'             => ['Emergência reportada', 'bi-exclamation-triangle-fill'],
        'proposta_enviada'       => ['Proposta enviada', 'bi-send'],
        'proposta_aceita'        => ['Proposta aceite', 'bi-hand-thumbs-up'],
        'proposta_rejeitada'     => ['Proposta rejeitada', 'bi-x-circle'],
        'contrato_gerado'        => ['Contrato gerado', 'bi-file-earmark-ruled'],
        'documento_emitido'      => ['Documento emitido', 'bi-file-earmark-pdf'],
    ];

    if (isset($map[$tipo])) {
        return ['titulo' => $map[$tipo][0], 'icon' => $map[$tipo][1], 'detalhe' => $descricao];
    }

    if (str_starts_with($tipo, 'checklist_')) {
        return ['titulo' => ucfirst(str_replace('_', ' ', $tipo)), 'icon' => 'bi-list-check', 'detalhe' => $descricao];
    }

    return ['titulo' => ucfirst(str_replace('_', ' ', $tipo)), 'icon' => 'bi-signpost', 'detalhe' => $descricao];
}

function timeline_eventos_missao(PDO $conn, int $missaoId): array
{
    $eventos = [];
    if ($missaoId <= 0) {
        return $eventos;
    }

    $stmt = $conn->prepare('SELECT * FROM missoes WHERE id = ? LIMIT 1');
    $stmt->execute([$missaoId]);
    $m = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$m) {
        return $eventos;
    }

    $chaves = [];
    $add = static function (string $data, string $titulo, string $detalhe = '', string $icon = 'bi-circle') use (&$eventos, &$chaves) {
        if ($data === '' || $data === '0000-00-00 00:00:00') {
            return;
        }
        $k = $titulo . '|' . substr($data, 0, 16);
        if (isset($chaves[$k])) {
            return;
        }
        $chaves[$k] = true;
        $eventos[] = ['data' => $data, 'titulo' => $titulo, 'detalhe' => $detalhe, 'icon' => $icon];
    };

    $add($m['data_criacao'] ?? '', 'Missão publicada', $m['titulo'] ?? '', 'bi-broadcast');
    $add($m['data_atribuicao_transportador'] ?? '', 'Atribuída à transportadora', '', 'bi-building');
    $add($m['data_atribuicao_motorista'] ?? '', 'Motorista atribuído', '', 'bi-person-check');
    $add($m['data_inicio_conducao'] ?? $m['data_inicio'] ?? '', 'Condução iniciada', '', 'bi-truck');
    $add($m['data_coleta'] ?? '', 'Carga recolhida', '', 'bi-box-seam');
    $add($m['chegada_destino'] ?? $m['data_chegada'] ?? '', 'Chegada ao destino', '', 'bi-geo-alt-fill');
    if (($m['status'] ?? '') === 'concluida' || ($m['status'] ?? '') === 'entrega_confirmada') {
        $add($m['data_conclusao'] ?? $m['ultima_atualizacao'] ?? '', 'Missão concluída', '', 'bi-patch-check-fill');
    }

    try {
        $stmt = $conn->prepare(
            'SELECT p.valor, p.status, p.data_criacao, p.data_atualizacao, u.nome
             FROM propostas p JOIN usuarios u ON p.caminhoneiro_id = u.id
             WHERE p.missao_id = :id ORDER BY p.data_criacao ASC'
        );
        $stmt->execute([':id' => $missaoId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $nome = $p['nome'] ?? 'Motorista';
            $valor = number_format((float)($p['valor'] ?? 0), 2, ',', '.') . ' MT';
            if (($p['status'] ?? '') === 'aceita') {
                $add($p['data_atualizacao'] ?? $p['data_criacao'] ?? '', 'Proposta aceite', "{$nome} — {$valor}", 'bi-hand-thumbs-up');
            } elseif (($p['status'] ?? '') === 'rejeitada') {
                $add($p['data_atualizacao'] ?? $p['data_criacao'] ?? '', 'Proposta rejeitada', $nome, 'bi-x-circle');
            } else {
                $add($p['data_criacao'] ?? '', 'Proposta recebida', "{$nome} — {$valor}", 'bi-send');
            }
        }
    } catch (Throwable $e) {
        // opcional
    }

    try {
        $stmt = $conn->prepare(
            'SELECT tipo, descricao, data_registro FROM registros_viagem
             WHERE missao_id = :id ORDER BY data_registro ASC'
        );
        $stmt->execute([':id' => $missaoId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $rv) {
            $tipo = (string)($rv['tipo'] ?? 'evento');
            $lab  = timeline_label_evento($tipo, (string)($rv['descricao'] ?? ''));
            $add($rv['data_registro'] ?? '', $lab['titulo'], $lab['detalhe'], $lab['icon']);
        }
    } catch (Throwable $e) {
        // opcional
    }

    try {
        $stmt = $conn->prepare(
            "SELECT tipo, titulo, numero_documento, data_emissao FROM documentos_sistema
             WHERE missao_id = :id ORDER BY data_emissao ASC"
        );
        $stmt->execute([':id' => $missaoId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $doc) {
            $add(
                $doc['data_emissao'] ?? '',
                'Documento emitido',
                ($doc['titulo'] ?? $doc['tipo']) . ' — ' . ($doc['numero_documento'] ?? ''),
                'bi-file-earmark-pdf'
            );
        }
    } catch (Throwable $e) {
        // opcional
    }

    try {
        $stmt = $conn->prepare(
            "SELECT tipo_acao, descricao, data_criacao FROM logs_sistema
             WHERE entidade = 'missao' AND entidade_id = :id
             ORDER BY data_criacao ASC LIMIT 40"
        );
        $stmt->execute([':id' => $missaoId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $log) {
            $add(
                $log['data_criacao'] ?? '',
                ucfirst((string)($log['tipo_acao'] ?? 'Registo')),
                (string)($log['descricao'] ?? ''),
                'bi-journal-text'
            );
        }
    } catch (Throwable $e) {
        // opcional
    }

    usort($eventos, fn($a, $b) => strtotime($a['data']) <=> strtotime($b['data']));
    return $eventos;
}

function timeline_render_html(array $eventos): string
{
    if (empty($eventos)) {
        return '<p class="text-muted mb-0">Sem eventos registados.</p>';
    }
    $html = '<ul class="list-unstyled mb-0 tm-timeline">';
    foreach ($eventos as $ev) {
        $ts = strtotime($ev['data']);
        $when = $ts ? date('d/m/Y H:i', $ts) : e($ev['data']);
        $html .= '<li class="d-flex gap-3 mb-3 pb-3 border-bottom tm-timeline-item">';
        $html .= '<div class="text-primary tm-timeline-icon"><i class="bi ' . e($ev['icon']) . ' fs-5"></i></div>';
        $html .= '<div><div class="fw-semibold">' . e($ev['titulo']) . '</div>';
        if (($ev['detalhe'] ?? '') !== '') {
            $html .= '<div class="small text-muted">' . e($ev['detalhe']) . '</div>';
        }
        $html .= '<div class="small text-secondary">' . e($when) . '</div></div></li>';
    }
    $html .= '</ul>';
    return $html;
}
