<?php
/**
 * Apagar / retirar missão do ar (empresa).
 * Só permitido se ainda não tiver motorista aceite nem recolha iniciada.
 */
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');
include_once('../../includes/auth.php');
include_once('../../includes/helpers.php');
include_once('../../includes/regras-negocio.php');
include_once('../../includes/missao-helpers.php');

require_role(['empresa'], '../login.php');

$missao_id  = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
$empresa_id = (int)$_SESSION['user_id'];

if ($missao_id <= 0) {
    header('Location: missoes.php');
    exit;
}

try {
    $stmt = $conn->prepare(
        "SELECT id, titulo, status, caminhoneiro_id, motorista_id, transportador_id, status_viagem
         FROM missoes
         WHERE id = :id AND empresa_id = :eid
         LIMIT 1"
    );
    $stmt->execute([':id' => $missao_id, ':eid' => $empresa_id]);
    $missao = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$missao) {
        header('Location: missoes.php');
        exit;
    }

    $validacao = validar_missao_pode_apagar($missao);
    if (!$validacao['ok']) {
        header('Location: detalhes-missao.php?id=' . $missao_id . '&error=' . rawurlencode(regras_erro_mensagem($validacao)));
        exit;
    }

    // Confirmação em GET → mostra página
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        ?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Apagar missão — TrackMoz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
</head>
<body>
<?php include_once('../../includes/menu.php'); ?>
<div class="container py-4" style="max-width:560px">
    <div class="card border-0 shadow-sm">
        <div class="card-body p-4">
            <h1 class="h5 mb-2 text-danger"><i class="bi bi-trash me-2"></i>Apagar / retirar missão</h1>
            <p class="text-muted">
                Vai retirar do ar a missão
                <strong><?php echo e($missao['titulo'] ?? ('#' . $missao_id)); ?></strong>.
            </p>
            <p class="small">
                Só é permitido porque ainda <strong>não tem motorista aceite</strong> e a recolha
                <strong>não foi iniciada</strong>. Missões duplicadas ou publicadas por engano podem ser removidas aqui.
            </p>
            <form method="POST" class="d-flex gap-2">
                <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                <input type="hidden" name="id" value="<?php echo $missao_id; ?>">
                <a href="detalhes-missao.php?id=<?php echo $missao_id; ?>" class="btn btn-outline-secondary">Cancelar</a>
                <button type="submit" class="btn btn-danger flex-fill"
                        onclick="return confirm('Confirma apagar esta missão? Esta acção não pode ser desfeita facilmente.');">
                    <i class="bi bi-trash me-1"></i>Confirmar apagar
                </button>
            </form>
        </div>
    </div>
</div>
</body>
</html>
        <?php
        exit;
    }

    require_csrf();

    $conn->beginTransaction();

    // Contar propostas — se limpa (aberta sem propostas), hard delete; senão soft cancel
    $stc = $conn->prepare('SELECT COUNT(*) FROM propostas WHERE missao_id = ?');
    $stc->execute([$missao_id]);
    $nProp = (int)$stc->fetchColumn();

    $hardOk = ($missao['status'] === 'aberta' && $nProp === 0 && empty($missao['transportador_id']));

    if ($hardOk) {
        try {
            $conn->prepare('DELETE FROM documentos_missao WHERE missao_id = ?')->execute([$missao_id]);
        } catch (Throwable $e) { /* ignore */ }
        try {
            $conn->prepare('DELETE FROM registros_viagem WHERE missao_id = ?')->execute([$missao_id]);
        } catch (Throwable $e) { /* ignore */ }
        $conn->prepare('DELETE FROM missoes WHERE id = ? AND empresa_id = ?')
             ->execute([$missao_id, $empresa_id]);
        $conn->commit();
        header('Location: missoes.php?success=' . rawurlencode('Missão apagada com sucesso.'));
        exit;
    }

    $conn->prepare("UPDATE missoes SET status = 'cancelada', data_atualizacao = NOW() WHERE id = :id")
         ->execute([':id' => $missao_id]);

    try {
        $conn->prepare(
            "UPDATE propostas SET status = 'rejeitada'
             WHERE missao_id = :mid AND status IN ('pendente','aceita')"
        )->execute([':mid' => $missao_id]);
    } catch (Throwable $e) { /* ignore */ }

    try {
        registar_evento_viagem($conn, $missao_id, 'cancelamento', 'Missão retirada do ar pela empresa');
    } catch (Throwable $e) { /* ignore */ }

    if (!empty($missao['transportador_id'])) {
        try {
            require_once '../../includes/notificacoes-helpers.php';
            notificar_usuario(
                $conn,
                (int)$missao['transportador_id'],
                'missao',
                'Missão retirada',
                'A empresa retirou do ar a missão "' . ($missao['titulo'] ?? '#' . $missao_id) . '".',
                BASE_URL . '/pages/transportador/missoes.php'
            );
        } catch (Throwable $e) { /* ignore */ }
    }

    $conn->commit();
    header('Location: missoes.php?success=' . rawurlencode('Missão retirada do ar (cancelada).'));
    exit;

} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log('apagar-missao: ' . $e->getMessage());
    header('Location: detalhes-missao.php?id=' . $missao_id . '&error=' . rawurlencode('Erro ao apagar a missão.'));
    exit;
}
