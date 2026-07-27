<?php
/**
 * API: Delegar missão (ex.: do feed público) a um transportador parceiro.
 * POST: missao_id, transportador_id, mensagem?, csrf_token
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notificacoes-helpers.php';
require_once __DIR__ . '/../includes/documentos-registry.php';

session_start();

function json_out(bool $ok, string $msg = '', array $extra = []): void
{
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_type'] ?? '', ['empresa', 'admin'], true)) {
    json_out(false, 'Não autorizado.');
}

require_csrf_json();

$empresa_id       = (int)$_SESSION['user_id'];
$missao_id        = isset($_POST['missao_id']) ? (int)$_POST['missao_id'] : 0;
$transportador_id = isset($_POST['transportador_id']) ? (int)$_POST['transportador_id'] : 0;
$mensagem         = trim($_POST['mensagem'] ?? '');

if ($missao_id <= 0 || $transportador_id <= 0) {
    json_out(false, 'Dados incompletos.');
}

try {
    $conn = getConnection();

    $stmt = $conn->prepare(
        "SELECT id, titulo, status, caminhoneiro_id, motorista_id, transportador_id,
                parceria_id, empresa_id, status_viagem
         FROM missoes WHERE id = :mid AND empresa_id = :eid"
    );
    $stmt->execute([':mid' => $missao_id, ':eid' => $empresa_id]);
    $missao = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$missao) {
        json_out(false, 'Missão não encontrada ou não pertence à sua empresa.');
    }

    $status = (string)($missao['status'] ?? '');
    $bloqueados = [
        'em_transito', 'em_entrega', 'aguardando_confirmacao',
        'concluida', 'cancelada', 'entrega_confirmada',
        'emergencia', 'emergencia_reportada',
    ];
    if (in_array($status, $bloqueados, true)) {
        json_out(false, 'Não é possível delegar: a missão já está em curso, concluída ou cancelada.');
    }

    if (!in_array($status, ['aberta', 'em_negociacao', 'aceita', 'em_andamento', 'aguardando_aceitacao_transportadora'], true)) {
        json_out(false, 'Não é possível delegar missão no estado actual: ' . status_missao_label($status));
    }

    if (!empty($missao['caminhoneiro_id']) || !empty($missao['motorista_id'])) {
        json_out(false, 'Não é possível delegar: já existe motorista atribuído. Retire a atribuição ou aguarde conclusão.');
    }

    $sv = (string)($missao['status_viagem'] ?? 'nao_iniciada');
    if (in_array($sv, ['carga_recolhida', 'em_transito', 'coleta', 'entrega', 'finalizada'], true)) {
        json_out(false, 'Não é possível delegar: a viagem/recolha já avançou.');
    }

    $stmt = $conn->prepare(
        "SELECT id FROM parcerias
         WHERE empresa_id = :eid AND transportador_id = :tid AND status = 'ativa'
         LIMIT 1"
    );
    $stmt->execute([':eid' => $empresa_id, ':tid' => $transportador_id]);
    $parceria = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$parceria) {
        json_out(false, 'Não existe parceria activa com este transportador.');
    }
    $parceria_id = (int)$parceria['id'];

    if ((int)$missao['parceria_id'] === $parceria_id && (int)$missao['transportador_id'] === $transportador_id) {
        json_out(false, 'Esta missão já está delegada a este transportador.');
    }

    // Garantir status de parceria no ENUM
    try {
        $stCol = $conn->query("SHOW COLUMNS FROM missoes LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
        $type = (string)($stCol['Type'] ?? '');
        if ($type !== '' && stripos($type, 'aguardando_aceitacao_transportadora') === false) {
            $conn->exec(
                "ALTER TABLE missoes MODIFY COLUMN status ENUM(
                    'aberta','em_negociacao','aceita','em_andamento','em_transito','em_entrega',
                    'emergencia_reportada','aguardando_confirmacao','entrega_confirmada',
                    'concluida','cancelada','emergencia','aguardando_aceitacao_transportadora',
                    'recusada_pelo_transportador'
                ) NULL"
            );
        }
    } catch (Throwable $e) {
        // ignore
    }

    $conn->beginTransaction();

    // Retirar do feed: rejeitar propostas pendentes do mercado público
    try {
        $conn->prepare(
            "UPDATE propostas SET status = 'rejeitada'
             WHERE missao_id = :mid AND status IN ('pendente','aceita')"
        )->execute([':mid' => $missao_id]);
    } catch (Throwable $e) {
        // tabela opcional
    }

    // Passa para o parceiro — aguarda aceitação da transportadora
    $novoStatus = 'aguardando_aceitacao_transportadora';
    try {
        $conn->prepare(
            "UPDATE missoes SET
                transportador_id = :tid,
                parceria_id = :pid,
                caminhoneiro_id = NULL,
                motorista_id = NULL,
                veiculo_id = NULL,
                status = :st,
                data_atualizacao = NOW(),
                ultima_atualizacao = NOW()
             WHERE id = :mid AND empresa_id = :eid"
        )->execute([
            ':tid' => $transportador_id,
            ':pid' => $parceria_id,
            ':st'  => $novoStatus,
            ':mid' => $missao_id,
            ':eid' => $empresa_id,
        ]);
    } catch (PDOException $e) {
        // Fallback sem ultima_atualizacao / status enum
        if (stripos($e->getMessage(), 'aguardando_aceitacao_transportadora') !== false) {
            $novoStatus = 'aceita';
        }
        $conn->prepare(
            "UPDATE missoes SET
                transportador_id = :tid,
                parceria_id = :pid,
                caminhoneiro_id = NULL,
                status = :st,
                data_atualizacao = NOW()
             WHERE id = :mid AND empresa_id = :eid"
        )->execute([
            ':tid' => $transportador_id,
            ':pid' => $parceria_id,
            ':st'  => $novoStatus,
            ':mid' => $missao_id,
            ':eid' => $empresa_id,
        ]);
    }

    $conn->commit();

    // Garantir registo documental e associar ao parceiro
    try {
        tmz_docs_criar_registo_missao(
            $conn,
            $missao_id,
            $empresa_id,
            $empresa_id,
            [
                'titulo' => $missao['titulo'] ?? null,
                'delegada' => true,
                'parceria_id' => $parceria_id,
            ],
            $transportador_id
        );
        $conn->prepare(
            "UPDATE documentos_sistema
             SET transportador_id = :tid, parceria_id = COALESCE(parceria_id, :pid)
             WHERE missao_id = :mid AND tipo = 'missao_registo'"
        )->execute([
            ':tid' => $transportador_id,
            ':pid' => $parceria_id,
            ':mid' => $missao_id,
        ]);
    } catch (Throwable $e) {
        error_log('missao-delegar docs: ' . $e->getMessage());
    }

    $link = BASE_URL . '/pages/transportador/detalhes-missao.php?id=' . $missao_id;
    try {
        notificar_usuario(
            $conn,
            $transportador_id,
            'parceria',
            'Nova missão delegada',
            "A empresa delegou a missão '{$missao['titulo']}' (#{$missao_id}) a si."
                . ($mensagem !== '' ? "\nMensagem: {$mensagem}" : ''),
            $link
        );
    } catch (Throwable $e) {
        error_log('missao-delegar notif: ' . $e->getMessage());
    }

    try {
        registrar_log(
            $conn,
            $empresa_id,
            'delegar_missao',
            'missao',
            $missao_id,
            "Missão #{$missao_id} delegada ao transportador #{$transportador_id} via parceria #{$parceria_id}",
            [
                'transportador_anterior' => $missao['transportador_id'],
                'parceria_anterior' => $missao['parceria_id'],
                'status_anterior' => $status,
            ]
        );
    } catch (Throwable $e) {
        // ignore
    }

    json_out(true, 'Missão delegada com sucesso. O parceiro foi notificado e pode aceitar a missão.');

} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof PDO && $conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log('missao-delegar: ' . $e->getMessage());
    json_out(false, 'Erro interno ao delegar. Tente novamente.');
}
