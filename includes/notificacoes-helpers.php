<?php
/**
 * Notificações in-app (Módulo 6).
 */
require_once __DIR__ . '/helpers.php';

/** @return array<string, string> */
function notificacao_filtros_disponiveis(): array
{
    return [
        'todas'      => 'Todas',
        'nao_lidas'  => 'Não lidas',
        'missao'     => 'Missões',
        'proposta'   => 'Propostas',
        'parceria'   => 'Parcerias',
        'mensagem'   => 'Mensagens',
        'checklist'  => 'Checklists',
        'emergencia' => 'Emergências',
        'sistema'    => 'Sistema',
    ];
}

/**
 * Cláusula SQL extra para filtro de notificações.
 */
function notificacao_sql_filtro(string $filtro): string
{
    return match ($filtro) {
        'nao_lidas'  => ' AND lida = 0',
        'missao'     => " AND tipo IN ('missao','documento')",
        'proposta'   => " AND (tipo LIKE 'proposta%' OR tipo = 'proposta_aceita')",
        'parceria'   => " AND tipo = 'parceria'",
        'mensagem'   => " AND tipo = 'mensagem'",
        'checklist'  => " AND tipo = 'checklist'",
        'emergencia' => " AND tipo IN ('emergencia','emergencia_reportada')",
        'sistema'    => " AND tipo IN ('sistema','avaliacao','documento')",
        default      => '',
    };
}

function notificacao_icone(string $tipo): string
{
    return match (true) {
        str_starts_with($tipo, 'proposta') => 'bi-send text-success',
        $tipo === 'missao'                 => 'bi-list-task text-primary',
        $tipo === 'mensagem'               => 'bi-chat-dots text-info',
        $tipo === 'parceria'               => 'bi-handshake text-primary',
        $tipo === 'checklist'              => 'bi-list-check text-success',
        str_contains($tipo, 'emergencia')  => 'bi-exclamation-triangle-fill text-danger',
        $tipo === 'avaliacao'              => 'bi-star-fill text-warning',
        $tipo === 'documento'              => 'bi-file-earmark-pdf text-secondary',
        default                            => 'bi-bell text-danger',
    };
}

/**
 * Envia notificação evitando duplicados recentes.
 */
function notificacao_enviar(
    PDO $conn,
    int $usuarioId,
    string $tipo,
    string $titulo,
    string $mensagem,
    ?string $link = null,
    int $dedupMinutos = 10
): bool {
    if ($usuarioId <= 0) {
        return false;
    }

    try {
        if ($dedupMinutos > 0) {
            $stmt = $conn->prepare(
                "SELECT id FROM notificacoes
                 WHERE usuario_id = :uid AND tipo = :tipo AND titulo = :titulo
                   AND mensagem = :msg
                   AND data_criacao > DATE_SUB(NOW(), INTERVAL :min MINUTE)
                 LIMIT 1"
            );
            $stmt->execute([
                ':uid'  => $usuarioId,
                ':tipo' => $tipo,
                ':titulo' => $titulo,
                ':msg'  => $mensagem,
                ':min'  => $dedupMinutos,
            ]);
            if ($stmt->fetch()) {
                return false;
            }
        }

        $stmt = $conn->prepare(
            'INSERT INTO notificacoes (usuario_id, tipo, titulo, mensagem, link, data_criacao, lida)
             VALUES (:uid, :tipo, :titulo, :msg, :link, NOW(), 0)'
        );
        $stmt->execute([
            ':uid'   => $usuarioId,
            ':tipo'  => $tipo,
            ':titulo'=> $titulo,
            ':msg'   => $mensagem,
            ':link'  => $link,
        ]);
        return true;
    } catch (Throwable $e) {
        error_log('notificacao_enviar: ' . $e->getMessage());
        return false;
    }
}

function notificacao_contar_nao_lidas(PDO $conn, int $usuarioId): int
{
    if ($usuarioId <= 0) {
        return 0;
    }
    try {
        $stmt = $conn->prepare('SELECT COUNT(*) FROM notificacoes WHERE usuario_id = :uid AND lida = 0');
        $stmt->execute([':uid' => $usuarioId]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function notificar_proposta_nova(PDO $conn, int $missaoId, int $caminhoneiroId, float $valor): void
{
    try {
        $stmt = $conn->prepare(
            'SELECT m.titulo, m.empresa_id, u.nome AS motorista
             FROM missoes m
             JOIN usuarios u ON u.id = :cid
             WHERE m.id = :mid'
        );
        $stmt->execute([':mid' => $missaoId, ':cid' => $caminhoneiroId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || empty($row['empresa_id'])) {
            return;
        }

        $valorFmt = number_format($valor, 2, ',', '.') . ' MT';
        $link = BASE_URL . '/pages/contratante/detalhes-missao.php?id=' . $missaoId;
        notificacao_enviar(
            $conn,
            (int)$row['empresa_id'],
            'proposta',
            'Nova proposta recebida',
            ($row['motorista'] ?? 'Motorista') . ' enviou proposta de ' . $valorFmt . ' para "' . ($row['titulo'] ?? '') . '".',
            $link
        );
    } catch (Throwable $e) {
        error_log('notificar_proposta_nova: ' . $e->getMessage());
    }
}

function notificar_checklist_missao(PDO $conn, int $missaoId, string $fase): void
{
    $labels = [
        'pre_viagem' => 'pré-viagem',
        'recolha'    => 'recolha',
        'entrega'    => 'entrega',
    ];
    $label = $labels[$fase] ?? $fase;

    try {
        $stmt = $conn->prepare(
            'SELECT titulo, empresa_id, caminhoneiro_id, transportador_id FROM missoes WHERE id = :id'
        );
        $stmt->execute([':id' => $missaoId]);
        $m = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$m) {
            return;
        }

        $msg = 'Checklist de ' . $label . ' concluído na missão "' . ($m['titulo'] ?? '') . '".';
        $linkEmpresa = BASE_URL . '/pages/contratante/detalhes-missao.php?id=' . $missaoId;
        $linkTransp  = BASE_URL . '/pages/transportador/detalhes-missao.php?id=' . $missaoId;

        if (!empty($m['empresa_id'])) {
            notificacao_enviar($conn, (int)$m['empresa_id'], 'checklist', 'Checklist ' . $label, $msg, $linkEmpresa, 30);
        }
        if (!empty($m['transportador_id'])) {
            notificacao_enviar($conn, (int)$m['transportador_id'], 'checklist', 'Checklist ' . $label, $msg, $linkTransp, 30);
        }
    } catch (Throwable $e) {
        error_log('notificar_checklist_missao: ' . $e->getMessage());
    }
}

function notificar_mudanca_status_missao(PDO $conn, int $missaoId, string $novoStatus): void
{
    try {
        $cols = 'titulo, empresa_id, caminhoneiro_id, transportador_id';
        if (table_has_column($conn, 'missoes', 'codigo_missao')) {
            $cols .= ', codigo_missao';
        }
        $stmt = $conn->prepare("SELECT {$cols} FROM missoes WHERE id = :id");
        $stmt->execute([':id' => $missaoId]);
        $m = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$m) {
            return;
        }

        $label = status_missao_label($novoStatus);
        $cod   = !empty($m['codigo_missao']) ? $m['codigo_missao'] : ('#' . $missaoId);
        $msg   = "A missão {$cod} ({$m['titulo']}) passou para: {$label}.";

        $map = [
            (int)($m['empresa_id'] ?? 0)       => BASE_URL . '/pages/contratante/detalhes-missao.php?id=' . $missaoId,
            (int)($m['caminhoneiro_id'] ?? 0)  => BASE_URL . '/pages/caminhoneiro/detalhes-missao.php?id=' . $missaoId,
            (int)($m['transportador_id'] ?? 0) => BASE_URL . '/pages/transportador/detalhes-missao.php?id=' . $missaoId,
        ];

        foreach ($map as $uid => $link) {
            if ($uid > 0) {
                notificacao_enviar($conn, $uid, 'missao', 'Actualização de missão', $msg, $link, 5);
            }
        }
    } catch (Throwable $e) {
        error_log('notificar_mudanca_status_missao: ' . $e->getMessage());
    }
}

/** Compatibilidade com código existente. */
function notificar_usuario(PDO $conn, int $usuarioId, string $tipo, string $titulo, string $mensagem, ?string $link = null): void
{
    notificacao_enviar($conn, $usuarioId, $tipo, $titulo, $mensagem, $link);
}
