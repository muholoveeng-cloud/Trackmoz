<?php
/**
 * Actualização de estados da viagem pelo condutor (JSON).
 * Devolve mensagens claras com sugestão quando o erro é do utilizador/dados.
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

include_once('../../config/app.php');
include_once('../../config/database.php');
include_once('../../includes/auth.php');
include_once('../../includes/helpers.php');
include_once('../../includes/missao-helpers.php');
include_once('../../includes/motorista-regras.php');

function viagem_json_erro(string $message, ?string $solucao = null, int $http = 200): void
{
    http_response_code($http);
    $out = ['success' => false, 'message' => $message];
    if ($solucao) {
        $out['solucao'] = $solucao;
        $out['message'] = $message . "\n\n💡 " . $solucao;
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

function viagem_garantir_enum_status(PDO $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $stmt = $conn->query("SHOW COLUMNS FROM missoes LIKE 'status_viagem'");
        $col = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$col) {
            return;
        }
        $type = (string)($col['Type'] ?? '');
        $needed = [
            'nao_iniciada', 'a_caminho_recolha', 'aguardando_recolha', 'carga_recolhida',
            'em_transito', 'coleta', 'entrega', 'finalizada',
        ];
        $missing = false;
        foreach ($needed as $v) {
            if (stripos($type, "'$v'") === false) {
                $missing = true;
                break;
            }
        }
        if ($missing) {
            $conn->exec(
                "ALTER TABLE missoes
                 MODIFY COLUMN status_viagem ENUM(
                    'nao_iniciada','a_caminho_recolha','aguardando_recolha','carga_recolhida',
                    'em_transito','coleta','entrega','finalizada'
                 ) DEFAULT 'nao_iniciada'"
            );
        }
    } catch (Throwable $e) {
        error_log('viagem_garantir_enum_status: ' . $e->getMessage());
    }
}

function viagem_mapear_erro_pdo(PDOException $e): array
{
    $msg = $e->getMessage();
    if (stripos($msg, 'status_viagem') !== false || stripos($msg, 'Data truncated') !== false) {
        return [
            'O estado da viagem não é compatível com a base de dados.',
            'Actualize a página e tente novamente. Se persistir, contacte o suporte TrackMoz.',
        ];
    }
    if (stripos($msg, 'Unknown column') !== false) {
        return [
            'Falta uma coluna necessária na base de dados.',
            'Peça ao administrador para correr as migrações do sistema (TMS / GPS).',
        ];
    }
    if (stripos($msg, 'historico_localizacao') !== false) {
        return [
            'Não foi possível guardar a localização.',
            'Active o GPS e tente outra vez. A acção da missão pode ser repetida.',
        ];
    }
    return [
        'Não foi possível concluir esta acção neste momento.',
        'Verifique a ligação à internet e tente novamente. Se o erro continuar, contacte o suporte.',
    ];
}

if (!is_caminhoneiro_logged_in()) {
    viagem_json_erro('Sessão expirada ou sem permissão.', 'Volte a iniciar sessão como motorista e tente de novo.', 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    viagem_json_erro('Pedido inválido.', 'Use o botão da app (não abra este endereço directamente).', 405);
}

$missao_id = (int)($_POST['missao_id'] ?? 0);
$acao      = trim((string)($_POST['acao'] ?? ''));
$etapa     = $_POST['etapa'] ?? null;
$lat       = isset($_POST['latitude']) && $_POST['latitude'] !== '' ? (float)$_POST['latitude'] : null;
$lng       = isset($_POST['longitude']) && $_POST['longitude'] !== '' ? (float)$_POST['longitude'] : null;

if ($missao_id <= 0 || $acao === '') {
    viagem_json_erro(
        'Faltam dados para processar esta acção.',
        'Abra a missão pelo Modo Condução e use o botão correspondente (ex.: «Cheguei ao ponto de recolha»).'
    );
}

try {
    viagem_garantir_enum_status($conn);

    $stmt = $conn->prepare('SELECT * FROM missoes WHERE id = ? AND caminhoneiro_id = ?');
    $stmt->execute([$missao_id, $_SESSION['user_id']]);
    $missao = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$missao) {
        viagem_json_erro(
            'Missão não encontrada ou não está atribuída a si.',
            'Confirme no painel se a missão ainda está activa e se foi atribuída ao seu perfil.'
        );
    }

    $statusMissao = (string)($missao['status'] ?? '');
    $statusViagem = (string)($missao['status_viagem'] ?? 'nao_iniciada');

    // Validações de fluxo (erros do utilizador)
    $acoesValidas = ['iniciar', 'chegou_origem', 'recolheu', 'atualizar', 'chegada_destino', 'aguardar_codigo', 'concluir'];
    if (!in_array($acao, $acoesValidas, true)) {
        viagem_json_erro('Acção desconhecida.', 'Actualize a página do Modo Condução e tente novamente.');
    }

    if (in_array($statusMissao, ['concluida', 'entrega_confirmada', 'cancelada'], true)) {
        viagem_json_erro(
            'Esta missão já está fechada (' . status_missao_label($statusMissao) . ').',
            'Já não é possível alterar o estado. Consulte o histórico da missão no painel.'
        );
    }

    if ($acao === 'iniciar') {
        $validacao = validar_motorista_pode_iniciar_missao($conn, (int)$_SESSION['user_id'], $missao_id);
        if (!$validacao['ok']) {
            $erro = $validacao['erros'][0] ?? 'Não pode iniciar esta missão.';
            viagem_json_erro(
                $erro,
                $validacao['solucao'] ?? 'Complete os dados em falta no perfil/documentos ou aguarde a atribuição correcta da missão.'
            );
        }
        if (!in_array($statusMissao, ['aceita', 'em_andamento'], true)
            && !in_array($statusViagem, ['nao_iniciada', 'a_caminho_recolha', ''], true)) {
            // permitir reinício suave se já a caminho
        }
    }

    if ($acao === 'chegou_origem') {
        if (in_array($statusViagem, ['aguardando_recolha', 'carga_recolhida', 'em_transito', 'coleta', 'entrega', 'finalizada'], true)) {
            viagem_json_erro(
                'A chegada à recolha já foi registada (estado: ' . status_operacional_missao_label($missao) . ').',
                'Se já está no local, use «Confirmar recolha da carga». Se ainda não recolheu, avance para o próximo botão disponível.'
            );
        }
        if ($lat === null || $lng === null) {
            viagem_json_erro(
                'Não temos a sua localização GPS para confirmar a chegada.',
                'Active a localização no telemóvel/browser, aguarde o sinal GPS (ponto verde) e volte a clicar em «Cheguei ao ponto de recolha».'
            );
        }
    }

    if ($acao === 'recolheu') {
        if (!in_array($statusViagem, ['aguardando_recolha', 'a_caminho_recolha', 'nao_iniciada', 'coleta'], true)
            && $statusMissao !== 'em_andamento') {
            // allow if waiting at pickup
        }
        if (in_array($statusViagem, ['carga_recolhida', 'em_transito', 'entrega', 'finalizada'], true)) {
            viagem_json_erro(
                'A recolha da carga já foi confirmada.',
                'Siga agora para o destino e use «Cheguei ao destino» quando chegar.'
            );
        }
        if (!in_array($statusViagem, ['aguardando_recolha', 'a_caminho_recolha', 'nao_iniciada', 'coleta'], true)) {
            viagem_json_erro(
                'Ainda não registou a chegada ao ponto de recolha.',
                'Clique primeiro em «Cheguei ao ponto de recolha» e só depois em «Confirmar recolha da carga».'
            );
        }
    }

    if ($acao === 'chegada_destino') {
        if (!in_array($statusViagem, ['carga_recolhida', 'em_transito', 'coleta'], true)) {
            viagem_json_erro(
                'Só pode registar a chegada ao destino depois de confirmar a recolha da carga.',
                'No ponto de recolha: 1) Cheguei à recolha → 2) Confirmar recolha → 3) depois siga para o destino.'
            );
        }
        if ($lat === null || $lng === null) {
            viagem_json_erro(
                'Falta localização GPS para confirmar a chegada ao destino.',
                'Active o GPS, aguarde o sinal e clique novamente em «Cheguei ao destino».'
            );
        }
    }

    if ($acao === 'aguardar_codigo') {
        if (!in_array($statusViagem, ['entrega', 'em_transito', 'carga_recolhida'], true)
            && !in_array($statusMissao, ['em_entrega', 'em_transito'], true)) {
            viagem_json_erro(
                'Registe primeiro a chegada ao destino.',
                'Clique em «Cheguei ao destino» e só depois confirme a entrega com o código OTP.'
            );
        }
    }

    // Guardar GPS (não bloqueia a acção se a tabela falhar)
    if ($lat !== null && $lng !== null) {
        try {
            $conn->prepare(
                'UPDATE perfil_caminhoneiro SET ultima_localizacao_lat = ?, ultima_localizacao_lng = ?, ultima_atualizacao_local = NOW() WHERE usuario_id = ?'
            )->execute([$lat, $lng, $_SESSION['user_id']]);
        } catch (Throwable $e) {
            error_log('atualizar-status-viagem perfil gps: ' . $e->getMessage());
        }
        try {
            if (table_has_column($conn, 'historico_localizacao', 'missao_id')) {
                $conn->prepare(
                    'INSERT INTO historico_localizacao (usuario_id, missao_id, latitude, longitude, data_registro) VALUES (?, ?, ?, ?, NOW())'
                )->execute([$_SESSION['user_id'], $missao_id, $lat, $lng]);
            } else {
                $conn->prepare(
                    'INSERT INTO historico_localizacao (usuario_id, latitude, longitude, data_registro) VALUES (?, ?, ?, NOW())'
                )->execute([$_SESSION['user_id'], $lat, $lng]);
            }
        } catch (Throwable $e) {
            error_log('atualizar-status-viagem historico: ' . $e->getMessage());
        }
    }

    $novoStatus = null;
    $statusEntrega = null;
    $mensagem = 'Actualizado';
    $tipoReg = $acao;

    switch ($acao) {
        case 'iniciar':
            $novoStatus = 'em_andamento';
            $conn->prepare("UPDATE missoes SET status = 'em_andamento', status_viagem = 'a_caminho_recolha', data_inicio = COALESCE(data_inicio, NOW()) WHERE id = ?")
                ->execute([$missao_id]);
            registar_evento_viagem($conn, $missao_id, 'inicio_viagem', 'Condutor iniciou a viagem');
            $mensagem = 'Viagem iniciada — siga para o ponto de recolha.';
            break;

        case 'chegou_origem':
            $novoStatus = 'em_andamento';
            $conn->prepare("UPDATE missoes SET status = 'em_andamento', status_viagem = 'aguardando_recolha' WHERE id = ?")
                ->execute([$missao_id]);
            registar_evento_viagem($conn, $missao_id, 'chegou_origem', 'Chegou ao ponto de recolha');
            $mensagem = 'Chegada à recolha registada. Confirme agora a recolha da carga.';
            break;

        case 'recolheu':
            $novoStatus = 'em_transito';
            $sql = "UPDATE missoes SET status = 'em_transito', status_viagem = 'carga_recolhida'";
            if (table_has_column($conn, 'missoes', 'data_coleta')) {
                $sql .= ', data_coleta = NOW()';
            }
            $sql .= ' WHERE id = ?';
            $conn->prepare($sql)->execute([$missao_id]);
            registar_evento_viagem($conn, $missao_id, 'carga_recolhida', 'Encomenda recolhida');
            $mensagem = 'Recolha confirmada — pode seguir para o destino.';
            break;

        case 'atualizar':
            if ($etapa === 'entrega') {
                $novoStatus = 'em_transito';
                $sql = "UPDATE missoes SET status = 'em_transito', status_viagem = 'em_transito'";
                if (table_has_column($conn, 'missoes', 'data_coleta')) {
                    $sql .= ', data_coleta = COALESCE(data_coleta, NOW())';
                }
                $sql .= ' WHERE id = ?';
                $conn->prepare($sql)->execute([$missao_id]);
                registar_evento_viagem($conn, $missao_id, 'em_transito', 'Em trânsito para o destino');
            }
            $mensagem = 'Etapa actualizada.';
            break;

        case 'chegada_destino':
            $novoStatus = 'em_entrega';
            $statusEntrega = 'chegou_destino';
            $sets = [
                "status = 'em_entrega'",
                "status_viagem = 'entrega'",
                "status_entrega = 'chegou_destino'",
            ];
            if (table_has_column($conn, 'missoes', 'data_chegada')) {
                $sets[] = 'data_chegada = NOW()';
            }
            if (table_has_column($conn, 'missoes', 'chegada_destino')) {
                $sets[] = 'chegada_destino = NOW()';
            }
            $conn->prepare('UPDATE missoes SET ' . implode(', ', $sets) . ' WHERE id = ?')
                ->execute([$missao_id]);
            registar_evento_viagem($conn, $missao_id, 'chegada_destino', 'Chegou ao destino');
            $mensagem = 'Chegada ao destino registada. Pode confirmar a entrega com OTP.';
            break;

        case 'aguardar_codigo':
            $novoStatus = 'aguardando_confirmacao';
            $statusEntrega = 'aguardando_codigo';
            $conn->prepare("UPDATE missoes SET status = 'aguardando_confirmacao', status_entrega = 'aguardando_codigo' WHERE id = ?")
                ->execute([$missao_id]);
            registar_evento_viagem($conn, $missao_id, 'aguardando_codigo', 'Aguardando código de confirmação');
            $mensagem = 'Aguardando código do destinatário.';
            break;

        case 'concluir':
            $novoStatus = 'concluida';
            $sql = "UPDATE missoes SET status = 'concluida', status_viagem = 'finalizada'";
            if (table_has_column($conn, 'missoes', 'data_chegada')) {
                $sql .= ', data_chegada = COALESCE(data_chegada, NOW())';
            }
            $sql .= ' WHERE id = ?';
            $conn->prepare($sql)->execute([$missao_id]);
            registar_evento_viagem($conn, $missao_id, 'concluida', 'Viagem concluída pelo condutor');
            $mensagem = 'Missão concluída.';
            break;
    }

    if ($novoStatus) {
        try {
            notificar_mudanca_status_missao($conn, $missao_id, $novoStatus);
        } catch (Throwable $e) {
            error_log('atualizar-status-viagem notificar: ' . $e->getMessage());
        }
    }

    $stmtSv = $conn->prepare('SELECT status_viagem, status FROM missoes WHERE id = ?');
    $stmtSv->execute([$missao_id]);
    $svRow = $stmtSv->fetch(PDO::FETCH_ASSOC) ?: [];

    echo json_encode([
        'success'        => true,
        'message'        => $mensagem,
        'status'         => $novoStatus ?? ($svRow['status'] ?? null),
        'status_viagem'  => $svRow['status_viagem'] ?? null,
        'status_entrega' => $statusEntrega,
        'acao'           => $tipoReg,
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log('atualizar-status-viagem: ' . $e->getMessage());
    [$msg, $sol] = viagem_mapear_erro_pdo($e);
    viagem_json_erro($msg, $sol);
} catch (Throwable $e) {
    error_log('atualizar-status-viagem: ' . $e->getMessage());
    viagem_json_erro(
        'Ocorreu um erro inesperado ao processar a acção.',
        'Actualize a página e tente novamente. Se continuar, contacte o suporte com o código da missão.'
    );
}
