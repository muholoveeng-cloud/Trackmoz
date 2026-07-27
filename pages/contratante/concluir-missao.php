<?php
/**
 * Confirmar conclusão da missão + avaliar motorista (estrelas e comentário).
 */
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');
include_once('../../includes/auth.php');
include_once('../../includes/helpers.php');
include_once('../../includes/documentos-registry.php');
include_once('../../includes/penalizacoes-helpers.php');
include_once('../../includes/reputacao-helpers.php');

require_role(['empresa'], '../login.php');

if (!isset($_GET['id']) && !isset($_POST['missao_id'])) {
    header('Location: ' . BASE_URL . '/pages/contratante/missoes.php');
    exit;
}

$missao_id  = (int)($_POST['missao_id'] ?? $_GET['id'] ?? 0);
$empresa_id = (int)$_SESSION['user_id'];
$error = '';
$successFlash = '';

try {
    $stmt = $conn->prepare(
        "SELECT m.id, m.status, m.titulo, m.caminhoneiro_id, m.transportador_id,
                u.nome AS nome_caminhoneiro,
                COALESCE(pt.nome_empresa, ut.nome) AS nome_transportador
         FROM missoes m
         LEFT JOIN usuarios u ON u.id = m.caminhoneiro_id
         LEFT JOIN usuarios ut ON ut.id = m.transportador_id
         LEFT JOIN perfil_transportador pt ON pt.usuario_id = m.transportador_id
         WHERE m.id = :id AND m.empresa_id = :eid
         LIMIT 1"
    );
    $stmt->execute([':id' => $missao_id, ':eid' => $empresa_id]);
    $missao = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('concluir-missao load: ' . $e->getMessage());
    $missao = false;
}

if (!$missao) {
    header('Location: ' . BASE_URL . '/pages/contratante/missoes.php');
    exit;
}

$jaAvaliouMotorista = !empty($missao['caminhoneiro_id'])
    && reputacao_ja_avaliou($conn, $missao_id, $empresa_id, (int)$missao['caminhoneiro_id']);
$jaAvaliouTransportador = !empty($missao['transportador_id'])
    && reputacao_ja_avaliou($conn, $missao_id, $empresa_id, (int)$missao['transportador_id']);

$podeConcluir = ($missao['status'] === 'aguardando_confirmacao');
$estadoAvaliavel = in_array($missao['status'], ['aguardando_confirmacao', 'concluida', 'entrega_confirmada'], true);
$podeAvaliarMotorista = $estadoAvaliavel && !empty($missao['caminhoneiro_id']) && !$jaAvaliouMotorista;
$podeAvaliarTransportador = $estadoAvaliavel && !empty($missao['transportador_id']) && !$jaAvaliouTransportador;
$podeAvaliar = $podeAvaliarMotorista || $podeAvaliarTransportador;

if (!$podeConcluir && !$podeAvaliar) {
    header('Location: ' . BASE_URL . '/pages/contratante/detalhes-missao.php?id=' . $missao_id);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $nota = (int)($_POST['avaliacao'] ?? 0);
    $comentario = trim((string)($_POST['comentario_avaliacao'] ?? ''));
    $notaTransp = (int)($_POST['avaliacao_transportador'] ?? 0);
    $comentarioTransp = trim((string)($_POST['comentario_transportador'] ?? ''));

    if ($podeAvaliarMotorista && ($nota < 1 || $nota > 5)) {
        $error = 'Seleccione uma classificação de 1 a 5 estrelas para o motorista.';
    } elseif ($podeAvaliarTransportador && !$podeAvaliarMotorista && ($notaTransp < 1 || $notaTransp > 5)) {
        $error = 'Seleccione uma classificação de 1 a 5 estrelas para a transportadora.';
    } else {
        try {
            if ($podeConcluir) {
                $conn->beginTransaction();

                $conn->prepare(
                    "UPDATE missoes
                     SET status = 'concluida', status_viagem = 'finalizada',
                         data_chegada = NOW(), data_atualizacao = NOW()
                     WHERE id = :id AND empresa_id = :eid AND status = 'aguardando_confirmacao'"
                )->execute([':id' => $missao_id, ':eid' => $empresa_id]);

                try {
                    $conn->prepare(
                        "INSERT INTO registros_viagem (missao_id, tipo, descricao, data_registro)
                         VALUES (:mid, 'confirmacao_entrega', 'Entrega confirmada pela empresa', NOW())"
                    )->execute([':mid' => $missao_id]);
                } catch (Throwable $e) { /* tabela opcional */ }

                if (!empty($missao['caminhoneiro_id'])) {
                    $conn->prepare(
                        "UPDATE perfil_caminhoneiro
                         SET total_entregas = total_entregas + 1, disponibilidade = 'disponivel'
                         WHERE usuario_id = :cid"
                    )->execute([':cid' => (int)$missao['caminhoneiro_id']]);
                }

                $responsavel_id = null;
                $responsavel_tipo = null;
                if (!empty($missao['transportador_id'])) {
                    $responsavel_id = (int)$missao['transportador_id'];
                    $responsavel_tipo = 'transportador';
                } elseif (!empty($missao['caminhoneiro_id'])) {
                    $responsavel_id = (int)$missao['caminhoneiro_id'];
                    $responsavel_tipo = 'caminhoneiro';
                }

                if ($responsavel_id) {
                    try {
                        $conn->prepare(
                            "INSERT INTO notificacoes (usuario_id, tipo, titulo, mensagem, link, data_criacao, lida)
                             VALUES (:uid, 'confirmacao_entrega', 'Entrega confirmada', :msg, :link, NOW(), 0)"
                        )->execute([
                            ':uid'  => $responsavel_id,
                            ':msg'  => 'A empresa confirmou a entrega da missão #' . $missao_id . '.',
                            ':link' => BASE_URL . '/pages/' . $responsavel_tipo . '/detalhes-missao.php?id=' . $missao_id,
                        ]);
                    } catch (Throwable $e) { /* ignore */ }
                }

                $conn->commit();
                try {
                    penalizacao_verificar_atraso_missao($conn, $missao_id);
                } catch (Throwable $e) { /* ignore */ }

                try {
                    tmz_docs_bootstrap($conn);
                    $mapa = [
                        'comprovativo_conclusao' => BASE_URL . '/pages/contratante/documentos/comprovativo-conclusao.php?id=' . $missao_id,
                        'fatura' => BASE_URL . '/pages/contratante/documentos/fatura.php?missao=' . $missao_id,
                        'recibo' => BASE_URL . '/pages/contratante/documentos/recibo.php?missao=' . $missao_id,
                    ];
                    foreach ($mapa as $tipo => $url) {
                        $ids = tmz_docs_number_and_tracking($conn, $tipo, $missao_id, $empresa_id);
                        tmz_docs_register($conn, [
                            'titulo' => ucfirst(str_replace('_', ' ', $tipo)) . ' - Missão #' . $missao_id,
                            'tipo' => $tipo,
                            'numero_documento' => $ids['numero_documento'],
                            'tracking_id' => $ids['tracking_id'],
                            'status' => 'assinado',
                            'data_emissao' => date('Y-m-d H:i:s'),
                            'url_visualizacao' => $url,
                            'criado_por' => $empresa_id,
                            'empresa_id' => $empresa_id,
                            'missao_id' => $missao_id,
                            'condutor_id' => !empty($missao['caminhoneiro_id']) ? (int)$missao['caminhoneiro_id'] : null,
                        ]);
                    }
                } catch (Throwable $e) {
                    error_log('Automação docs concluir_missao: ' . $e->getMessage());
                }
            }

            if ($podeAvaliarMotorista && $nota >= 1 && !empty($missao['caminhoneiro_id'])) {
                $res = reputacao_registrar_avaliacao(
                    $conn,
                    $missao_id,
                    $empresa_id,
                    (int)$missao['caminhoneiro_id'],
                    $nota,
                    $comentario
                );
                if (!$res['ok'] && str_contains((string)($res['error'] ?? ''), 'Já avaliou') === false) {
                    $error = $res['error'] ?? 'Não foi possível guardar a avaliação do motorista.';
                }
            }

            if ($error === '' && $podeAvaliarTransportador && $notaTransp >= 1 && !empty($missao['transportador_id'])) {
                $resT = reputacao_registrar_avaliacao(
                    $conn,
                    $missao_id,
                    $empresa_id,
                    (int)$missao['transportador_id'],
                    $notaTransp,
                    $comentarioTransp
                );
                if (!$resT['ok'] && str_contains((string)($resT['error'] ?? ''), 'Já avaliou') === false) {
                    $error = $resT['error'] ?? 'Não foi possível guardar a avaliação da transportadora.';
                }
            }

            if ($error === '') {
                $partes = [];
                if ($podeConcluir) {
                    $partes[] = 'Missão concluída';
                }
                if ($nota >= 1) {
                    $partes[] = 'motorista avaliado';
                }
                if ($notaTransp >= 1) {
                    $partes[] = 'transportadora avaliada';
                }
                $msg = $partes ? (implode(' · ', $partes) . '.') : 'Avaliação registada. Obrigado!';
                header('Location: ' . BASE_URL . '/pages/contratante/detalhes-missao.php?id=' . $missao_id
                    . '&success=' . rawurlencode($msg));
                exit;
            }
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            error_log('concluir-missao POST: ' . $e->getMessage());
            $error = 'Erro ao processar. Tente novamente.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $podeConcluir ? 'Confirmar conclusão' : 'Avaliar motorista'; ?> — TrackMoz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
</head>
<body>
<?php include_once('../../includes/menu.php'); ?>

<div class="container py-4" style="max-width:640px">
    <a href="detalhes-missao.php?id=<?php echo $missao_id; ?>" class="btn btn-sm btn-outline-secondary mb-3">
        <i class="bi bi-arrow-left"></i> Voltar à missão
    </a>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-4">
            <h1 class="h4 mb-1">
                <i class="bi bi-star-fill text-warning me-2"></i>
                <?php echo $podeConcluir ? 'Confirmar entrega e avaliar' : 'Avaliar serviço'; ?>
            </h1>
            <p class="text-muted mb-4">
                Missão: <strong><?php echo e($missao['titulo'] ?? ('#' . $missao_id)); ?></strong>
                <?php if (!empty($missao['nome_caminhoneiro'])): ?>
                    · Motorista: <strong><?php echo e($missao['nome_caminhoneiro']); ?></strong>
                <?php endif; ?>
                <?php if (!empty($missao['nome_transportador'])): ?>
                    · Transportadora: <strong><?php echo e($missao['nome_transportador']); ?></strong>
                <?php endif; ?>
            </p>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo e($error); ?></div>
            <?php endif; ?>

            <?php if (!$podeAvaliar && !$podeConcluir): ?>
                <div class="alert alert-info mb-0">Já avaliou esta missão. Obrigado!</div>
            <?php else: ?>
            <form method="POST" action="" id="formAvaliar">
                <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
                <input type="hidden" name="missao_id" value="<?php echo $missao_id; ?>">

                <?php if ($podeAvaliarMotorista): ?>
                <div class="mb-4 p-3 border rounded">
                    <label class="form-label fw-semibold">Motorista <?php echo e($missao['nome_caminhoneiro'] ?? ''); ?> *</label>
                    <?php echo reputacao_estrelas_input_html('avaliacao_input', 'avaliacao'); ?>
                    <textarea class="form-control mt-2" name="comentario_avaliacao" rows="2"
                              placeholder="Comentário sobre o motorista (opcional)"></textarea>
                </div>
                <?php elseif ($jaAvaliouMotorista && !empty($missao['caminhoneiro_id'])): ?>
                    <div class="alert alert-success py-2 small">Motorista já avaliado.</div>
                <?php endif; ?>

                <?php if ($podeAvaliarTransportador): ?>
                <div class="mb-4 p-3 border rounded bg-light">
                    <label class="form-label fw-semibold">
                        Transportadora <?php echo e($missao['nome_transportador'] ?? ''); ?>
                        <?php echo $podeAvaliarMotorista ? '(opcional)' : '*'; ?>
                    </label>
                    <?php echo reputacao_estrelas_input_html('avaliacao_transportador_input', 'avaliacao_transportador'); ?>
                    <div class="form-text">Avalie o serviço da transportadora parceira (pontualidade, comunicação, cumprimento do contrato).</div>
                    <textarea class="form-control mt-2" name="comentario_transportador" rows="2"
                              placeholder="Comentário sobre a transportadora (opcional)"></textarea>
                </div>
                <?php elseif ($jaAvaliouTransportador && !empty($missao['transportador_id'])): ?>
                    <div class="alert alert-success py-2 small">Transportadora já avaliada.</div>
                <?php endif; ?>

                <?php if ($podeConcluir): ?>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="confirma" required>
                    <label class="form-check-label" for="confirma">
                        Confirmo que a entrega foi concluída e as mercadorias foram recebidas.
                    </label>
                </div>
                <?php endif; ?>

                <button type="submit" class="btn btn-success w-100">
                    <i class="bi bi-check-circle me-1"></i>
                    <?php echo $podeConcluir ? 'Confirmar conclusão' : 'Enviar avaliação'; ?>
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php echo reputacao_estrelas_input_script(); ?>
<script>
document.getElementById('formAvaliar')?.addEventListener('submit', function (e) {
    var input = document.getElementById('avaliacao_input');
    if (input && (!input.value || parseInt(input.value, 10) < 1)) {
        e.preventDefault();
        alert('Seleccione uma classificação de 1 a 5 estrelas para o motorista.');
        return;
    }
    var inputT = document.getElementById('avaliacao_transportador_input');
    var soTransp = !input && inputT;
    if (soTransp && (!inputT.value || parseInt(inputT.value, 10) < 1)) {
        e.preventDefault();
        alert('Seleccione uma classificação de 1 a 5 estrelas para a transportadora.');
    }
});
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
