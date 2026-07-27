<?php
/**
 * Regras de disponibilidade e execução de missões para motoristas.
 */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/missao-helpers.php';

/** Estados em que a missão está activa/em execução (só uma por motorista). */
function missoes_status_operacionais_ativos(): array
{
    return [
        'em_andamento',
        'em_transito',
        'em_entrega',
        'emergencia_reportada',
        'emergencia',
        'aguardando_confirmacao',
    ];
}

/** Missões aceites mas ainda não iniciadas (agendadas). */
function missoes_status_agendados(): array
{
    return ['aceita'];
}

/** Estados finais — sem modo condução. */
function missoes_status_finais(): array
{
    return ['concluida', 'cancelada', 'entrega_confirmada'];
}

/** Estados onde o botão de modo condução deve aparecer. */
function missoes_status_modo_conducao(): array
{
    return array_merge(
        missoes_status_operacionais_ativos(),
        missoes_status_agendados()
    );
}

/**
 * Retorna a missão activa do motorista (se existir), excluindo opcionalmente uma missão.
 *
 * @return array<string,mixed>|null
 */
function motorista_missao_ativa(PDO $conn, int $user_id, ?int $exclude_missao_id = null): ?array
{
    missao_garantir_colunas_operacionais($conn);

    $statuses = missoes_status_operacionais_ativos();
    $placeholders = implode(',', array_fill(0, count($statuses), '?'));

    $extra = coluna_existe($conn, 'missoes', 'status_entrega') ? ', status_entrega' : '';

    $sql = "SELECT id, titulo, status, status_viagem{$extra}
            FROM missoes
            WHERE caminhoneiro_id = ?
            AND status IN ({$placeholders})";
    $params = array_merge([$user_id], $statuses);

    if ($exclude_missao_id !== null && $exclude_missao_id > 0) {
        $sql .= ' AND id != ?';
        $params[] = $exclude_missao_id;
    }

    $sql .= ' ORDER BY COALESCE(data_inicio, data_inicio_conducao, ultima_atualizacao) DESC LIMIT 1';

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function motorista_tem_missao_ativa(PDO $conn, int $user_id, ?int $exclude_missao_id = null): bool
{
    return motorista_missao_ativa($conn, $user_id, $exclude_missao_id) !== null;
}

/**
 * Valida se o motorista pode iniciar/conduzir uma missão específica.
 *
 * @return array{ok: bool, erros: string[], missao_ativa: ?array}
 */
function validar_motorista_pode_iniciar_missao(PDO $conn, int $user_id, int $missao_id): array
{
    $activa = motorista_missao_ativa($conn, $user_id, $missao_id);
    $erros = [];

    if ($activa !== null) {
        $erros[] = 'Você já possui uma missão em andamento. Finalize a missão actual antes de iniciar outra.';
    }

    return [
        'ok'           => empty($erros),
        'erros'        => $erros,
        'missao_ativa' => $activa,
    ];
}

/**
 * Indica se o motorista pode ver/usar o modo condução nesta missão.
 *
 * @return array{ok: bool, motivo: string}
 */
function motorista_pode_modo_conducao(PDO $conn, int $user_id, array $missao): array
{
    missao_garantir_colunas_operacionais($conn);

    $missao_id = (int)($missao['id'] ?? 0);
    $status = (string)($missao['status'] ?? '');
    $caminhoneiro_id = (int)($missao['caminhoneiro_id'] ?? 0);
    $propostaAceita = ($missao['status_proposta'] ?? '') === 'aceita';

    $statusEntrega = ['aguardando_confirmacao', 'em_entrega'];

    if (in_array($status, missoes_status_finais(), true)) {
        return ['ok' => false, 'motivo' => 'Missão já concluída ou cancelada.'];
    }

    $atribuida = $caminhoneiro_id === $user_id;
    if (!$atribuida && !($status === 'aberta' && $propostaAceita)) {
        return ['ok' => false, 'motivo' => 'Missão não atribuída a si.'];
    }

    $permiteStatus = in_array($status, missoes_status_modo_conducao(), true)
        || in_array($status, $statusEntrega, true);

    if (!$permiteStatus && !($status === 'aberta' && $propostaAceita)) {
        return ['ok' => false, 'motivo' => 'Estado da missão não permite modo condução.'];
    }

    // Missão já em curso neste registo — sempre permitir continuar
    if (!empty($missao['modo_conducao_ativo']) || !empty($missao['data_inicio_conducao'])) {
        return ['ok' => true, 'motivo' => ''];
    }

    if (in_array($status, missoes_status_operacionais_ativos(), true)
        || in_array($status, $statusEntrega, true)) {
        return ['ok' => true, 'motivo' => ''];
    }

    // Missão aceite/agendada — permitir se não houver outra em execução
    if ($status === 'aceita' || ($status === 'aberta' && $propostaAceita)) {
        $validacao = validar_motorista_pode_iniciar_missao($conn, $user_id, $missao_id);
        if (!$validacao['ok']) {
            return ['ok' => false, 'motivo' => $validacao['erros'][0] ?? 'Outra missão em andamento.'];
        }
        return ['ok' => true, 'motivo' => ''];
    }

    return ['ok' => true, 'motivo' => ''];
}

/**
 * Label legível para estado operacional combinado (status + status_entrega).
 */
function status_operacional_missao_label(array $missao): string
{
    $entrega = $missao['status_entrega'] ?? '';
    if ($entrega !== '') {
        return match ($entrega) {
            'chegou_destino'           => 'Chegou ao destino',
            'aguardando_codigo'        => 'Aguardando código de confirmação',
            'codigo_validado'          => 'Código validado',
            'entrega_confirmada'       => 'Entrega confirmada',
            'entrega_recusada'         => 'Entrega recusada',
            'entrega_divergencia'      => 'Entrega com divergência',
            default                    => status_missao_label($entrega),
        };
    }

    $viagem = $missao['status_viagem'] ?? '';
    if ($viagem !== '' && $viagem !== 'nao_iniciada') {
        return match ($viagem) {
            'a_caminho_recolha'  => 'A caminho da recolha',
            'aguardando_recolha' => 'Aguardando recolha',
            'carga_recolhida'    => 'Carga recolhida',
            'em_transito'        => 'Em trânsito',
            'emergencia'         => 'Emergência reportada',
            default              => status_missao_label($missao['status'] ?? ''),
        };
    }

    $status = $missao['status'] ?? '';
    if ($status === 'aceita') {
        return 'Agendada — pronta para condução';
    }

    return status_missao_label($status);
}

/** Texto do botão de modo condução conforme estado da missão. */
function botao_modo_conducao_label(array $missao): string
{
    if (!empty($missao['modo_conducao_ativo'])) {
        return 'Continuar viagem';
    }
    if (!empty($missao['data_inicio_conducao'])) {
        return 'Retomar condução';
    }
    if (($missao['status'] ?? '') === 'aceita') {
        return 'Entrar no modo condução';
    }
    return 'Entrar no modo condução';
}
