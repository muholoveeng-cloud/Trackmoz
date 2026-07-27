<?php
/**
 * Penalizações de reputação — RN40 a RN43
 */
require_once __DIR__ . '/reputacao-helpers.php';
require_once __DIR__ . '/missao-helpers.php';

const PENALIZACAO_RECUSA_IMPACTO = 0.15;
const PENALIZACAO_ABANDONO_IMPACTO = 0.50;
const PENALIZACAO_ATRASO_IMPACTO = 0.20;
const PENALIZACAO_LIMITE_RECUSAS = 5;
const PENALIZACAO_MEDIA_SUSPENSAO = 2.5;
const PENALIZACAO_MIN_AVALIACOES_SUSPENSAO = 5;

function penalizacoes_tabela_existe(PDO $conn): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    try {
        $stmt = $conn->query("SHOW TABLES LIKE 'penalizacoes_reputacao'");
        $cache = (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        $cache = false;
    }
    return $cache;
}

function registrar_penalizacao(
    PDO $conn,
    int $usuarioId,
    string $tipo,
    string $motivo,
    ?int $missaoId = null,
    float $impacto = 0.10
): void {
    if (!penalizacoes_tabela_existe($conn)) {
        return;
    }
    try {
        $conn->prepare(
            'INSERT INTO penalizacoes_reputacao (usuario_id, missao_id, tipo, impacto, motivo)
             VALUES (:uid, :mid, :tipo, :imp, :mot)'
        )->execute([
            ':uid' => $usuarioId,
            ':mid' => $missaoId,
            ':tipo' => $tipo,
            ':imp' => $impacto,
            ':mot' => $motivo,
        ]);
        penalizacao_atualizar_perfil($conn, $usuarioId);
        penalizacao_verificar_suspensao($conn, $usuarioId);
    } catch (Throwable $e) {
        error_log('registrar_penalizacao: ' . $e->getMessage());
    }
}

function penalizacao_atualizar_perfil(PDO $conn, int $usuarioId): void
{
    $rep = reputacao_utilizador($conn, $usuarioId);
    try {
        $stmt = $conn->prepare(
            'UPDATE perfil_caminhoneiro SET avaliacao_media = :m WHERE usuario_id = :id'
        );
        $stmt->execute([':m' => $rep['media'], ':id' => $usuarioId]);
    } catch (Throwable $e) {
        // perfil pode não existir
    }
    try {
        $stmt = $conn->prepare(
            'UPDATE perfil_transportador SET avaliacao_media = :m WHERE usuario_id = :id'
        );
        $stmt->execute([':m' => $rep['media'], ':id' => $usuarioId]);
    } catch (Throwable $e) {
        // perfil pode não existir
    }
}

/**
 * RN40 — Recusa excessiva reduz reputação.
 */
function penalizacao_registar_recusa(PDO $conn, int $usuarioId, ?int $missaoId, string $motivo = ''): void
{
    if (coluna_existe($conn, 'usuarios', 'recusas_consecutivas')) {
        $conn->prepare('UPDATE usuarios SET recusas_consecutivas = recusas_consecutivas + 1 WHERE id = :id')
            ->execute([':id' => $usuarioId]);
        $stmt = $conn->prepare('SELECT recusas_consecutivas FROM usuarios WHERE id = :id');
        $stmt->execute([':id' => $usuarioId]);
        $recusas = (int)$stmt->fetchColumn();
    } else {
        $recusas = contar_recusas_recentes($conn, $usuarioId);
    }

    if ($recusas >= PENALIZACAO_LIMITE_RECUSAS) {
        registrar_penalizacao(
            $conn,
            $usuarioId,
            'recusa_excessiva',
            $motivo ?: "Recusas excessivas ({$recusas} no período recente)",
            $missaoId,
            PENALIZACAO_RECUSA_IMPACTO
        );
        if (coluna_existe($conn, 'usuarios', 'recusas_consecutivas')) {
            $conn->prepare('UPDATE usuarios SET recusas_consecutivas = 0 WHERE id = :id')
                ->execute([':id' => $usuarioId]);
        }
    }
}

function contar_recusas_recentes(PDO $conn, int $usuarioId): int
{
    $total = 0;
    try {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) FROM missoes
             WHERE transportador_id = :uid
               AND status = 'recusada_pelo_transportador'
               AND data_atualizacao >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
        $stmt->execute([':uid' => $usuarioId]);
        $total += (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        // ignore
    }
    return $total;
}

/**
 * RN41 — Abandono da missão.
 */
function penalizacao_registar_abandono(PDO $conn, int $usuarioId, int $missaoId, string $motivo): void
{
    registrar_penalizacao($conn, $usuarioId, 'abandono_missao', $motivo, $missaoId, PENALIZACAO_ABANDONO_IMPACTO);
}

/**
 * RN42 — Atraso na entrega.
 */
function penalizacao_verificar_atraso_missao(PDO $conn, int $missaoId): void
{
    $stmt = $conn->prepare(
        'SELECT m.prazo_entrega, m.data_chegada, m.caminhoneiro_id, m.transportador_id, m.titulo
         FROM missoes m WHERE m.id = :id AND m.status = :st'
    );
    $stmt->execute([':id' => $missaoId, ':st' => 'concluida']);
    $m = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$m || empty($m['prazo_entrega'])) {
        return;
    }

    $prazo = new DateTime($m['prazo_entrega']);
    $chegada = !empty($m['data_chegada']) ? new DateTime($m['data_chegada']) : new DateTime();

    if ($chegada > $prazo) {
        $alvo = (int)($m['caminhoneiro_id'] ?: $m['transportador_id'] ?: 0);
        if ($alvo > 0) {
            registrar_penalizacao(
                $conn,
                $alvo,
                'atraso_entrega',
                'Entrega após o prazo para a missão «' . ($m['titulo'] ?? '') . '»',
                $missaoId,
                PENALIZACAO_ATRASO_IMPACTO
            );
        }
    }
}

/**
 * RN43 — Avaliações muito baixas podem suspender empresas/utilizadores.
 * @return array{suspenso: bool, motivo: ?string}
 */
function penalizacao_verificar_suspensao(PDO $conn, int $usuarioId): array
{
    $rep = reputacao_utilizador($conn, $usuarioId);
    if ($rep['total'] < PENALIZACAO_MIN_AVALIACOES_SUSPENSAO) {
        return ['suspenso' => false, 'motivo' => null];
    }
    if ($rep['media'] >= PENALIZACAO_MEDIA_SUSPENSAO) {
        return ['suspenso' => false, 'motivo' => null];
    }

    $stmt = $conn->prepare('SELECT status, tipo_usuario FROM usuarios WHERE id = :id');
    $stmt->execute([':id' => $usuarioId]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u || $u['status'] === 'bloqueado') {
        return ['suspenso' => true, 'motivo' => 'Conta já suspensa por baixa reputação.'];
    }

    $conn->prepare("UPDATE usuarios SET status = 'bloqueado' WHERE id = :id")
        ->execute([':id' => $usuarioId]);

    try {
        $conn->prepare(
            "INSERT INTO notificacoes (usuario_id, tipo, titulo, mensagem)
             VALUES (:uid, 'sistema', 'Conta suspensa', :msg)"
        )->execute([
            ':uid' => $usuarioId,
            ':msg' => 'A sua conta foi suspensa devido à reputação baixa (média '
                . number_format($rep['media'], 1, ',', '.') . '). Contacte o suporte.',
        ]);

        $admins = $conn->query("SELECT id FROM usuarios WHERE tipo_usuario = 'admin' AND status = 'ativo'");
        foreach ($admins->fetchAll(PDO::FETCH_COLUMN) as $adminId) {
            $conn->prepare(
                "INSERT INTO notificacoes (usuario_id, tipo, titulo, mensagem, link)
                 VALUES (:uid, 'alerta', 'Suspensão automática', :msg, :link)"
            )->execute([
                ':uid' => $adminId,
                ':msg' => 'Utilizador #' . $usuarioId . ' suspenso automaticamente (reputação '
                    . number_format($rep['media'], 1, ',', '.') . ').',
                ':link' => (defined('BASE_URL') ? BASE_URL : '') . '/pages/admin/usuarios.php',
            ]);
        }
    } catch (Throwable $e) {
        error_log('penalizacao_verificar_suspensao notif: ' . $e->getMessage());
    }

    return [
        'suspenso' => true,
        'motivo' => 'Reputação abaixo de ' . PENALIZACAO_MEDIA_SUSPENSAO . ' — conta suspensa automaticamente.',
    ];
}

/**
 * Aplica impacto de penalização à média (subtrai do cálculo via registo).
 */
function penalizacao_media_ajustada(PDO $conn, int $usuarioId): float
{
    $rep = reputacao_utilizador($conn, $usuarioId);
    $media = (float)$rep['media'];
    if (!penalizacoes_tabela_existe($conn)) {
        return $media;
    }
    $stmt = $conn->prepare(
        'SELECT COALESCE(SUM(impacto), 0) FROM penalizacoes_reputacao WHERE usuario_id = :id'
    );
    $stmt->execute([':id' => $usuarioId]);
    $penal = (float)$stmt->fetchColumn();
    return max(0, round($media - $penal, 1));
}
