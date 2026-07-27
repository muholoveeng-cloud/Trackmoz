<?php
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');
include_once('../../includes/auth.php');
include_once('../../includes/helpers.php');
include_once('../../includes/otp-entrega.php');

require_role(['transportador'], '../login.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . '/pages/transportador/missoes.php');
    exit;
}

$missao_id = isset($_POST['missao_id']) ? (int)$_POST['missao_id'] : 0;
$acao = isset($_POST['acao']) ? (string)$_POST['acao'] : '';
$transportador_id = (int)($_SESSION['user_id'] ?? 0);
$otpCodigo = preg_replace('/\D/', '', (string)($_POST['otp'] ?? ''));

if ($missao_id <= 0 || $acao === '') {
    header('Location: ' . BASE_URL . '/pages/transportador/missoes.php?error=' . rawurlencode('Dados inválidos'));
    exit;
}

try {
    $stmt = $conn->prepare(
        "SELECT id, status, titulo, status_entrega, modo_confirmacao_entrega
         FROM missoes WHERE id = :id AND transportador_id = :tid"
    );
    $stmt->execute([':id' => $missao_id, ':tid' => $transportador_id]);
    $missao = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$missao) {
        header('Location: ' . BASE_URL . '/pages/transportador/missoes.php?error=' . rawurlencode('Missão não encontrada'));
        exit;
    }

    $status_atual = (string)$missao['status'];

    $transicoes = [
        'iniciar' => ['from' => ['aceita'], 'to' => 'em_andamento', 'status_viagem' => 'a_caminho_recolha', 'ts_field' => 'data_inicio'],
        'em_transito' => ['from' => ['em_andamento'], 'to' => 'em_transito', 'status_viagem' => 'em_transito', 'ts_field' => 'data_coleta'],
        'em_entrega' => ['from' => ['em_transito'], 'to' => 'em_entrega', 'status_viagem' => 'entrega', 'ts_field' => null],
        'finalizar' => ['from' => ['em_entrega', 'em_transito'], 'to' => 'aguardando_confirmacao', 'status_viagem' => 'finalizada', 'ts_field' => 'chegada_destino', 'requer_otp' => true],
        'concluir' => ['from' => ['aguardando_confirmacao'], 'to' => 'concluida', 'status_viagem' => 'finalizada', 'ts_field' => 'data_chegada'],
    ];

    if (!isset($transicoes[$acao])) {
        header('Location: ' . BASE_URL . '/pages/transportador/detalhes-missao.php?id=' . $missao_id . '&error=' . rawurlencode('Acção inválida'));
        exit;
    }

    $regra = $transicoes[$acao];
    if (!in_array($status_atual, $regra['from'], true)) {
        header('Location: ' . BASE_URL . '/pages/transportador/detalhes-missao.php?id=' . $missao_id . '&error=' . rawurlencode('Transição não permitida'));
        exit;
    }

    // OTP obrigatório ao finalizar entrega (prova de recebimento)
    if (!empty($regra['requer_otp'])) {
        $modo = (string)($missao['modo_confirmacao_entrega'] ?? 'otp');
        $jaValidado = (($missao['status_entrega'] ?? '') === 'codigo_validado');

        if ($modo === 'otp' && !$jaValidado) {
            if (strlen($otpCodigo) !== 6) {
                header('Location: ' . BASE_URL . '/pages/transportador/detalhes-missao.php?id=' . $missao_id
                    . '&error=' . rawurlencode('Introduza o código OTP de 6 dígitos do destinatário para finalizar a entrega.'));
                exit;
            }
            $otpRes = otp_validar_codigo($conn, $missao_id, $otpCodigo, $transportador_id);
            if (empty($otpRes['ok'])) {
                $msg = $otpRes['error'] ?? $otpRes['message'] ?? 'Código OTP inválido.';
                header('Location: ' . BASE_URL . '/pages/transportador/detalhes-missao.php?id=' . $missao_id
                    . '&error=' . rawurlencode($msg));
                exit;
            }
            otp_marcar_usado($conn, $missao_id, 'transportador:' . $transportador_id);
            try {
                $conn->prepare("UPDATE missoes SET status_entrega = 'codigo_validado' WHERE id = ?")
                     ->execute([$missao_id]);
            } catch (Throwable $e) { /* ignore */ }
        }
    }

    $setParts = ["status = :status", "status_viagem = :status_viagem", "data_atualizacao = NOW()"];
    try {
        $cols = $conn->query('SHOW COLUMNS FROM missoes')->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('ultima_atualizacao', $cols, true)) {
            $setParts[] = 'ultima_atualizacao = NOW()';
        }
    } catch (Throwable $e) { /* ignore */ }

    if (!empty($regra['ts_field'])) {
        $setParts[] = $regra['ts_field'] . ' = NOW()';
    }

    $sql = 'UPDATE missoes SET ' . implode(', ', $setParts)
         . ' WHERE id = :id AND transportador_id = :transportador_id';
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':status' => $regra['to'],
        ':status_viagem' => $regra['status_viagem'],
        ':id' => $missao_id,
        ':transportador_id' => $transportador_id,
    ]);

    $descricoes = [
        'iniciar' => 'Missão iniciada pelo transportador (dispatch)',
        'em_transito' => 'Carga recolhida — missão em trânsito',
        'em_entrega' => 'Missão em entrega',
        'finalizar' => 'Entrega finalizada com OTP — aguardando confirmação da empresa',
        'concluir' => 'Missão marcada como concluída pelo transportador',
    ];

    try {
        $conn->prepare(
            'INSERT INTO registros_viagem (missao_id, tipo, descricao, data_registro)
             VALUES (:missao_id, :tipo, :descricao, NOW())'
        )->execute([
            ':missao_id' => $missao_id,
            ':tipo' => 'status',
            ':descricao' => $descricoes[$acao] ?? $acao,
        ]);
    } catch (Throwable $e) { /* ignore */ }

    header('Location: ' . BASE_URL . '/pages/transportador/detalhes-missao.php?id=' . $missao_id
        . '&success=' . rawurlencode('Estado actualizado com sucesso'));
    exit;

} catch (PDOException $e) {
    error_log('Erro ao atualizar status da missão (transportador): ' . $e->getMessage());
    header('Location: ' . BASE_URL . '/pages/transportador/detalhes-missao.php?id=' . $missao_id
        . '&error=' . rawurlencode('Erro ao actualizar estado'));
    exit;
}
