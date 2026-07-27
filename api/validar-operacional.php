<?php
/**
 * API: Validar operacional pré-missão
 * POST: missao_id, csrf_token
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/validacao-operacional.php';

session_start();

function json_out(bool $ok, string $msg = '', array $extra = []): void {
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
    exit;
}

if (!isset($_SESSION['user_id']) || ($_SESSION['user_type'] ?? '') !== 'caminhoneiro') {
    json_out(false, 'Não autorizado.');
}

require_csrf_json();

$uid = (int)$_SESSION['user_id'];
$missao_id = isset($_POST['missao_id']) ? (int)$_POST['missao_id'] : 0;

try {
    $conn = getConnection();

    // Verificar se a missão pertence ao caminhoneiro
    $stmt = $conn->prepare("SELECT id, status FROM missoes WHERE id = :mid AND caminhoneiro_id = :uid");
    $stmt->execute([':mid' => $missao_id, ':uid' => $uid]);
    $missao = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$missao) {
        json_out(false, 'Missão não encontrada.');
    }

    $resultado = validar_operacional_missao($conn, $uid);

    if ($resultado['ok']) {
        json_out(true, 'Validação operacional aprovada. Pode iniciar a condução.', [
            'veiculo' => $resultado['veiculo'] ? ['matricula' => $resultado['veiculo']['matricula'], 'marca' => $resultado['veiculo']['marca']] : null
        ]);
    } else {
        json_out(false, 'Validação operacional reprovada. Resolva os problemas antes de iniciar.', [
            'erros' => $resultado['erros']
        ]);
    }

} catch (Throwable $e) {
    error_log('validar-operacional: ' . $e->getMessage());
    json_out(false, 'Erro interno.');
}
