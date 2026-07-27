<?php
/**
 * API de Controle de Condução
 * Ações: iniciar, pausar, retomar, concluir
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/missao-helpers.php';
require_once __DIR__ . '/../includes/motorista-regras.php';

session_start();

function json_out(bool $ok, string $msg = '', array $extra = []): void {
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
    exit;
}

if (!isset($_SESSION['user_id']) || ($_SESSION['user_type'] ?? '') !== 'caminhoneiro') {
    json_out(false, 'Não autorizado.');
}

require_csrf_json();

$uid       = (int)$_SESSION['user_id'];
$missao_id = isset($_POST['missao_id']) ? (int)$_POST['missao_id'] : 0;
$acao      = $_POST['acao'] ?? '';
$latitude  = isset($_POST['latitude'])  ? (float)$_POST['latitude']  : null;
$longitude = isset($_POST['longitude']) ? (float)$_POST['longitude'] : null;

if ($missao_id <= 0) {
    json_out(false, 'ID da missão inválido.');
}
if (!in_array($acao, ['iniciar','pausar','retomar','concluir'], true)) {
    json_out(false, 'Ação inválida.');
}

try {
    $conn = getConnection();

    // Verificar se a missão pertence ao caminhoneiro e está em estado válido
    $stmt = $conn->prepare(
        "SELECT id, status, modo_conducao_ativo, data_inicio_conducao, data_pausa_conducao,
                data_retomada_conducao, tempo_conducao_acumulado_seg, empresa_id
         FROM missoes WHERE id = :mid AND caminhoneiro_id = :uid"
    );
    $stmt->execute([':mid' => $missao_id, ':uid' => $uid]);
    $missao = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$missao) {
        json_out(false, 'Missão não encontrada ou não atribuída a si.');
    }

    $statusAtual = $missao['status'];
    $modoAtivo   = (bool)($missao['modo_conducao_ativo'] ?? 0);

    $statusPermitidosIniciar = missoes_status_modo_conducao();
    $statusPermitidosPausar  = missoes_status_operacionais_ativos();
    $statusPermitidosRetomar = missoes_status_operacionais_ativos();

    $novoStatus = null;
    $novoModo   = null;
    $novoTempo  = (int)($missao['tempo_conducao_acumulado_seg'] ?? 0);
    $novoDataInicio = $missao['data_inicio_conducao'];
    $novoDataPausa  = $missao['data_pausa_conducao'];
    $novoDataRetomada = $missao['data_retomada_conducao'];

    switch ($acao) {
        case 'iniciar':
            if (!in_array($statusAtual, $statusPermitidosIniciar, true)) {
                json_out(false, 'Não é possível iniciar condução no estado actual: ' . status_missao_label($statusAtual));
            }
            if ($modoAtivo) {
                json_out(false, 'O modo de condução já está activo.');
            }
            $validacao = validar_motorista_pode_iniciar_missao($conn, $uid, $missao_id);
            if (!$validacao['ok']) {
                json_out(false, $validacao['erros'][0] ?? 'Não pode iniciar esta missão.');
            }
            $novoModo = 1;
            $novoStatus = $statusAtual === 'aceita' ? 'em_andamento' : $statusAtual;
            $novoDataInicio = date('Y-m-d H:i:s');
            if ($statusAtual === 'aceita') {
                $conn->prepare("UPDATE missoes SET status_viagem = 'a_caminho_recolha' WHERE id = ?")->execute([$missao_id]);
            }
            try {
                $conn->prepare("UPDATE perfil_caminhoneiro SET disponibilidade = 'ocupado' WHERE usuario_id = ?")
                     ->execute([$uid]);
            } catch (PDOException $e) {
                error_log('conducao-control disponibilidade: ' . $e->getMessage());
            }
            break;

        case 'pausar':
            if (!$modoAtivo) {
                json_out(false, 'O modo de condução não está activo.');
            }
            if (!in_array($statusAtual, $statusPermitidosPausar, true)) {
                json_out(false, 'Não é possível pausar no estado actual.');
            }
            // Calcular tempo decorrido desde início/retomada até agora
            $ref = $missao['data_retomada_conducao'] ?: $missao['data_inicio_conducao'];
            if ($ref) {
                $decorridos = time() - strtotime($ref);
                $novoTempo += max(0, $decorridos);
            }
            $novoModo = 0;
            $novoDataPausa = date('Y-m-d H:i:s');
            // Status permanece o mesmo (em_andamento/em_transito) — só o modo muda
            $novoStatus = $statusAtual;
            break;

        case 'retomar':
            if ($modoAtivo) {
                json_out(false, 'O modo de condução já está activo.');
            }
            if (!in_array($statusAtual, $statusPermitidosRetomar, true)) {
                json_out(false, 'Não é possível retomar no estado actual: ' . status_missao_label($statusAtual));
            }
            $novoModo = 1;
            $novoDataRetomada = date('Y-m-d H:i:s');
            $novoStatus = in_array($statusAtual, ['em_andamento','em_transito','em_entrega'], true) ? $statusAtual : 'em_andamento';
            break;

        case 'concluir':
            if (!in_array($statusAtual, array_merge(missoes_status_operacionais_ativos(), ['aguardando_confirmacao']), true)) {
                json_out(false, 'Não é possível concluir condução neste estado.');
            }
            // Calcular tempo final acumulado
            if ($modoAtivo) {
                $ref = $missao['data_retomada_conducao'] ?: $missao['data_inicio_conducao'];
                if ($ref) {
                    $decorridos = time() - strtotime($ref);
                    $novoTempo += max(0, $decorridos);
                }
            }
            $novoModo = 0;
            $novoStatus = 'em_entrega';
            break;
    }

    // Actualizar BD
    $sql = "UPDATE missoes SET
                status = :status,
                modo_conducao_ativo = :modo,
                tempo_conducao_acumulado_seg = :tempo,
                ultima_atualizacao = NOW()";
    $params = [
        ':status' => $novoStatus,
        ':modo'   => $novoModo,
        ':tempo'  => $novoTempo,
        ':mid'    => $missao_id,
    ];

    if ($novoDataInicio !== $missao['data_inicio_conducao']) {
        $sql .= ", data_inicio_conducao = :dini";
        $params[':dini'] = $novoDataInicio;
    }
    if ($novoDataPausa !== $missao['data_pausa_conducao']) {
        $sql .= ", data_pausa_conducao = :dpau";
        $params[':dpau'] = $novoDataPausa;
    }
    if ($novoDataRetomada !== $missao['data_retomada_conducao']) {
        $sql .= ", data_retomada_conducao = :dret";
        $params[':dret'] = $novoDataRetomada;
    }
    $sql .= " WHERE id = :mid";

    $conn->prepare($sql)->execute($params);

    // Log
    registrar_log($conn, $uid, 'conducao_' . $acao, 'missao', $missao_id,
        "Condução {$acao} para missão #{$missao_id}",
        ['status_anterior' => $statusAtual, 'status_novo' => $novoStatus, 'modo_anterior' => $modoAtivo]
    );

    // Notificar empresa
    $empresaId = (int)($missao['empresa_id'] ?? 0);
    if ($empresaId && in_array($acao, ['iniciar','pausar','concluir'], true)) {
        $label = status_missao_label($novoStatus);
        notificar_usuario($conn, $empresaId, 'missao',
            'Actualização de missão',
            "O motorista alterou o estado da missão #{$missao_id} para: {$label} (ação: {$acao}).",
            BASE_URL . '/pages/contratante/detalhes-missao.php?id=' . $missao_id
        );
    }

    // Registo de viagem (localização)
    if ($latitude !== null && $longitude !== null) {
        try {
            $conn->prepare("INSERT INTO historico_localizacao (usuario_id, latitude, longitude) VALUES (:uid, :lat, :lng)")
                 ->execute([':uid' => $uid, ':lat' => $latitude, ':lng' => $longitude]);
        } catch (PDOException $e) {
            error_log('conducao-control: erro ao guardar localizacao: ' . $e->getMessage());
        }
    }

    json_out(true, 'Ação registada com sucesso.', [
        'acao' => $acao,
        'status' => $novoStatus,
        'modo_conducao_ativo' => $novoModo,
        'tempo_acumulado_seg' => $novoTempo,
        'status_label' => status_missao_label($novoStatus),
    ]);

} catch (Throwable $e) {
    error_log('conducao-control: ' . $e->getMessage());
    json_out(false, 'Erro interno. Tente novamente.');
}
