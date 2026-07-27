<?php
/**
 * API: Transportador atribuir motorista e veículo à missão
 * POST: missao_id, modo=frota|independente, motorista_id?, veiculo_id?, caminhoneiro_id?, previsoes, csrf_token
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/regras-negocio.php';
require_once __DIR__ . '/../includes/frota-helpers.php';
require_once __DIR__ . '/../includes/motorista-regras.php';
require_once __DIR__ . '/../includes/notificacoes-helpers.php';

session_start();

$uid = (int)($_SESSION['user_id'] ?? 0);
if (!$uid || ($_SESSION['user_type'] ?? '') !== 'transportador') {
    echo json_encode(['success' => false, 'message' => 'Não autorizado.']);
    exit;
}

require_csrf_json();

$missao_id = (int)($_POST['missao_id'] ?? 0);
$modo = ($_POST['modo'] ?? 'frota') === 'independente' ? 'independente' : 'frota';
$motorista_id = !empty($_POST['motorista_id']) ? (int)$_POST['motorista_id'] : null;
$veiculo_id = !empty($_POST['veiculo_id']) ? (int)$_POST['veiculo_id'] : null;
$caminhoneiro_id = !empty($_POST['caminhoneiro_id']) ? (int)$_POST['caminhoneiro_id'] : null;

if ($missao_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Missão inválida.']);
    exit;
}

try {
    $conn = getConnection();

    $stmt = $conn->prepare('SELECT * FROM missoes WHERE id = :id AND transportador_id = :tid');
    $stmt->execute([':id' => $missao_id, ':tid' => $uid]);
    $missao = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$missao) {
        echo json_encode(['success' => false, 'message' => 'Missão não encontrada.']);
        exit;
    }

    if (!in_array($missao['status'], ['aceita', 'em_andamento', 'aguardando_aceitacao_transportadora'], true)) {
        echo json_encode(['success' => false, 'message' => 'Não é possível atribuir equipa neste estado.']);
        exit;
    }

    // Se ainda está só "recebida", aceitar implicitamente ao atribuir
    $novoStatus = 'em_andamento';

    if ($modo === 'independente') {
        if ($caminhoneiro_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Seleccione um motorista independente.']);
            exit;
        }

        $st = $conn->prepare(
            "SELECT u.id, u.nome, u.status, pc.disponibilidade
             FROM usuarios u
             LEFT JOIN perfil_caminhoneiro pc ON pc.usuario_id = u.id
             WHERE u.id = :id AND u.tipo_usuario = 'caminhoneiro'
             LIMIT 1"
        );
        $st->execute([':id' => $caminhoneiro_id]);
        $cam = $st->fetch(PDO::FETCH_ASSOC);
        if (!$cam) {
            echo json_encode(['success' => false, 'message' => 'Motorista independente não encontrado.']);
            exit;
        }
        if (in_array((string)($cam['status'] ?? ''), ['suspenso', 'rejeitado', 'inativo', 'bloqueado'], true)) {
            echo json_encode(['success' => false, 'message' => 'Este motorista não está apto a operar.']);
            exit;
        }

        if (motorista_tem_missao_ativa($conn, $caminhoneiro_id, $missao_id)) {
            echo json_encode(['success' => false, 'message' => 'Este motorista já tem outra missão activa.']);
            exit;
        }

        $chk = validar_motorista_nova_missao($conn, $caminhoneiro_id);
        if (!$chk['ok'] && motorista_tem_missao_ativa($conn, $caminhoneiro_id)) {
            echo json_encode(['success' => false, 'message' => 'Este motorista já tem uma missão activa.']);
            exit;
        }
        if (!$chk['ok']) {
            $erros = array_values(array_filter($chk['erros'] ?? [], static function ($e) {
                return stripos((string)$e, 'missão activ') === false
                    && stripos((string)$e, 'missão ativa') === false;
            }));
            if (!empty($erros)) {
                echo json_encode(['success' => false, 'message' => implode(' ', $erros)]);
                exit;
            }
        }

        $sql = "UPDATE missoes SET
                    caminhoneiro_id = :cid,
                    motorista_id = NULL,
                    veiculo_id = NULL,
                    data_atribuicao_motorista = NOW(),
                    status = :st,
                    data_atualizacao = NOW()";
        $params = [':cid' => $caminhoneiro_id, ':st' => $novoStatus, ':id' => $missao_id];

        if (!empty($_POST['previsao_recolha'])) {
            $sql .= ', previsao_recolha = :pr';
            $params[':pr'] = $_POST['previsao_recolha'];
        }
        if (!empty($_POST['previsao_entrega'])) {
            $sql .= ', previsao_entrega = :pe';
            $params[':pe'] = $_POST['previsao_entrega'];
        }
        $sql .= ' WHERE id = :id';
        $conn->prepare($sql)->execute($params);

        // Motorista passa a ocupado — não pode ficar "indisponível" durante a missão
        try {
            $conn->prepare(
                "UPDATE perfil_caminhoneiro SET disponibilidade = 'ocupado' WHERE usuario_id = :id"
            )->execute([':id' => $caminhoneiro_id]);
        } catch (Throwable $e) {
            error_log('disponibilidade ocupado independente: ' . $e->getMessage());
        }

        try {
            notificar_usuario(
                $conn,
                $caminhoneiro_id,
                'missao',
                'Nova Missão Atribuída',
                'A transportadora atribuiu-lhe a missão "' . $missao['titulo'] . '" (' . $missao['origem'] . ' → ' . $missao['destino'] . '). Abra a missão agora.',
                BASE_URL . '/pages/caminhoneiro/detalhes-missao.php?id=' . $missao_id
            );
        } catch (Throwable $e) {
            error_log('notif independente: ' . $e->getMessage());
        }

        try {
            notificar_usuario(
                $conn,
                (int)$missao['empresa_id'],
                'missao',
                'Equipa Atribuída',
                'A transportadora atribuiu um motorista independente à missão "' . $missao['titulo'] . '".',
                BASE_URL . '/pages/contratante/detalhes-missao.php?id=' . $missao_id
            );
        } catch (Throwable $e) {
            error_log('notif empresa: ' . $e->getMessage());
        }

        registrar_log($conn, $uid, 'actualizar', 'missao', $missao_id, 'Atribuido independente caminhoneiro=' . $caminhoneiro_id);
        echo json_encode(['success' => true, 'message' => 'Motorista independente atribuído com sucesso.']);
        exit;
    }

    // ---- Modo frota ----
    if (!$motorista_id || !$veiculo_id) {
        echo json_encode(['success' => false, 'message' => 'Seleccione motorista e viatura da frota.']);
        exit;
    }

    if (!transportador_motorista_pertence($conn, $uid, $motorista_id)) {
        echo json_encode(['success' => false, 'message' => 'Motorista não encontrado ou não pertence à sua transportadora.']);
        exit;
    }

    if (!transportador_veiculo_pertence($conn, $uid, $veiculo_id)) {
        echo json_encode(['success' => false, 'message' => 'Veículo não encontrado ou não pertence à sua frota.']);
        exit;
    }

    $validAtrib = validar_atribuicao_equipa($conn, $veiculo_id, $motorista_id, $missao_id);
    if (!$validAtrib['ok']) {
        echo json_encode(['success' => false, 'message' => regras_erro_mensagem($validAtrib)]);
        exit;
    }

    $campos = [
        'motorista_id = :mid',
        'data_atribuicao_motorista = NOW()',
        'veiculo_id = :vid',
        'data_atribuicao_veiculo = NOW()',
        "status = 'em_andamento'",
        'data_atualizacao = NOW()',
    ];
    $params = [
        ':id'  => $missao_id,
        ':mid' => $motorista_id,
        ':vid' => $veiculo_id,
    ];

    if (!empty($_POST['previsao_recolha'])) {
        $campos[] = 'previsao_recolha = :pr';
        $params[':pr'] = $_POST['previsao_recolha'];
    }
    if (!empty($_POST['previsao_entrega'])) {
        $campos[] = 'previsao_entrega = :pe';
        $params[':pe'] = $_POST['previsao_entrega'];
    }

    $conn->prepare('UPDATE missoes SET ' . implode(', ', $campos) . ' WHERE id = :id')
         ->execute($params);

    // Frota interna pode não ter conta de utilizador — notificar só a empresa
    try {
        notificar_usuario(
            $conn,
            (int)$missao['empresa_id'],
            'missao',
            'Equipa Atribuída',
            'A transportadora atribuiu motorista e viatura à missão "' . $missao['titulo'] . '".',
            BASE_URL . '/pages/contratante/detalhes-missao.php?id=' . $missao_id
        );
    } catch (Throwable $e) {
        error_log('notif empresa frota: ' . $e->getMessage());
    }

    registrar_log($conn, $uid, 'actualizar', 'missao', $missao_id, 'Atribuido motorista_frota=' . $motorista_id . ' veiculo=' . $veiculo_id);
    echo json_encode(['success' => true, 'message' => 'Equipa atribuída com sucesso.']);

} catch (Throwable $e) {
    error_log('missao-atribuir-equipa: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro interno.']);
}
