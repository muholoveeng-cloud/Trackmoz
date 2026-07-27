<?php
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');
include_once('../../includes/auth.php');
include_once('../../includes/geocode.php');
include_once('../../includes/otp-entrega.php');
include_once('../../includes/helpers.php');
include_once('../../includes/timeline-helpers.php');
include_once('../../includes/checklist-helpers.php');
include_once('../../includes/documentos-centro-helpers.php');
include_once('../../includes/reputacao-helpers.php');
include_once('../../includes/disputas-helpers.php');
include_once('../../includes/sms-helpers.php');
include_once('../../includes/regras-negocio.php');

require_role(['empresa'], '../login.php');

if (!isset($_GET['id'])) { header('Location: missoes.php'); exit; }

$missao_id  = (int)$_GET['id'];
$empresa_id = (int)$_SESSION['user_id'];
$error = $success = '';
$jaAvaliouMotorista = false;
$jaAvaliouTransportador = false;
$faltaAvaliar = false;
$minhaAvaliacao = null;
$minhasAvaliacoes = [];
if (isset($_GET['error']))   { $error   = $_GET['error']; }
if (isset($_GET['success'])) { $success = $_GET['success']; }

try {
    $stmt = $conn->prepare(
        "SELECT m.*,
                (SELECT COUNT(*) FROM propostas WHERE missao_id = m.id) AS total_propostas,
                (SELECT COUNT(*) FROM propostas WHERE missao_id = m.id AND status = 'aceita') AS propostas_aceitas,
                u.nome AS nome_caminhoneiro, u.telefone AS telefone_caminhoneiro,
                u.email AS email_caminhoneiro,
                pc.avaliacao_media, pc.total_entregas, pc.tipo_veiculo AS veiculo_caminhoneiro,
                lo.latitude AS origem_lat, lo.longitude AS origem_lng,
                ld.latitude AS destino_lat, ld.longitude AS destino_lng
         FROM missoes m
         LEFT JOIN usuarios u              ON m.caminhoneiro_id = u.id
         LEFT JOIN perfil_caminhoneiro pc  ON m.caminhoneiro_id = pc.usuario_id
         LEFT JOIN locais lo ON m.local_origem_id = lo.id
         LEFT JOIN locais ld ON m.local_destino_id = ld.id
         WHERE m.id = :id AND m.empresa_id = :eid"
    );
    $stmt->execute([':id' => $missao_id, ':eid' => $empresa_id]);
    $missao = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$missao) { header('Location: missoes.php'); exit; }

    $disputaMissao = null;
    $podeAbrirDisputa = false;
    if (disputas_tabela_existe($conn)) {
        $dst = $conn->prepare('SELECT * FROM disputas WHERE missao_id = :id ORDER BY created_at DESC LIMIT 1');
        $dst->execute([':id' => $missao_id]);
        $disputaMissao = $dst->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($missao['status'] === 'concluida') {
            $dispCheck = validar_missao_pode_disputar($conn, $missao_id, $empresa_id, 'empresa');
            $podeAbrirDisputa = $dispCheck['ok'];
        }
    }

    garantir_locais_missao($conn, $missao_id);
    $stmt->execute([':id' => $missao_id, ':eid' => $empresa_id]);
    $missao = enriquecer_missao_mapa($stmt->fetch(PDO::FETCH_ASSOC));

    $stmt2 = $conn->prepare(
        "SELECT p.*, u.nome AS nome_caminhoneiro, u.telefone AS tel,
                pc.avaliacao_media, pc.total_entregas
         FROM propostas p
         JOIN usuarios u ON p.caminhoneiro_id = u.id
         LEFT JOIN perfil_caminhoneiro pc ON p.caminhoneiro_id = pc.usuario_id
         WHERE p.missao_id = :mid
         ORDER BY p.data_criacao DESC"
    );
    $stmt2->execute([':mid' => $missao_id]);
    $propostas = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    $otpInfo = otp_info_missao($conn, $missao_id);
    $otpTelefone = otp_telefone_missao($conn, $missao_id);
    $otpCodigoExibir = null;
    if (isset($_SESSION['otp_missao_' . $missao_id])) {
        $otpCodigoExibir = $_SESSION['otp_missao_' . $missao_id];
        unset($_SESSION['otp_missao_' . $missao_id]);
    }
    if ($otpCodigoExibir === null) {
        $otpCodigoExibir = otp_codigo_texto_activo($conn, $missao_id);
    }

    $jaAvaliouMotorista = !empty($missao['caminhoneiro_id'])
        && reputacao_ja_avaliou($conn, $missao_id, $empresa_id, (int)$missao['caminhoneiro_id']);
    $jaAvaliouTransportador = !empty($missao['transportador_id'])
        && reputacao_ja_avaliou($conn, $missao_id, $empresa_id, (int)$missao['transportador_id']);
    $faltaAvaliar = (in_array($missao['status'] ?? '', ['concluida', 'entrega_confirmada', 'aguardando_confirmacao'], true))
        && (
            (!empty($missao['caminhoneiro_id']) && !$jaAvaliouMotorista)
            || (!empty($missao['transportador_id']) && !$jaAvaliouTransportador)
        );
    $minhaAvaliacao = null;
    if ($jaAvaliouMotorista || $jaAvaliouTransportador) {
        try {
            $stAv = $conn->prepare(
                'SELECT nota, comentario, data_avaliacao, avaliado_id FROM avaliacoes
                 WHERE missao_id = :m AND avaliador_id = :a ORDER BY data_avaliacao DESC'
            );
            $stAv->execute([':m' => $missao_id, ':a' => $empresa_id]);
            $minhasAvaliacoes = $stAv->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $minhaAvaliacao = $minhasAvaliacoes[0] ?? null;
        } catch (Throwable $e) { /* ignore */ }
    }

} catch (PDOException $e) {
    error_log('detalhes-missao: ' . $e->getMessage());
    $error = 'Erro ao carregar detalhes.';
}

function status_missao(string $s, int $props = 0): array {
    return match($s) {
        'aberta'                 => $props > 0
                                    ? ['Em Negociação', 'warning',   'bi-chat-dots-fill']
                                    : ['Publicada',     'success',   'bi-broadcast'],
        'aceita'                 => ['Aceita',          'success',   'bi-check-circle-fill'],
        'em_andamento'           => ['Em Execução',     'warning',   'bi-truck'],
        'em_transito'            => ['Em Trânsito',     'primary',   'bi-truck'],
        'em_entrega'             => ['Em Entrega',      'info',      'bi-box-arrow-in-down'],
        'aguardando_confirmacao' => ['Ag. Confirmação', 'secondary', 'bi-hourglass-split'],
        'concluida'              => ['Concluída',       'success',   'bi-patch-check-fill'],
        'cancelada'              => ['Cancelada',       'danger',    'bi-x-circle'],
        'emergencia_reportada'   => ['Emergência',      'danger',    'bi-exclamation-triangle-fill'],
        'entrega_confirmada'       => ['Entrega Conf.',   'success',   'bi-clipboard-check-fill'],
        'emergencia'               => ['EMERGÊNCIA',      'danger',    'bi-exclamation-triangle-fill'],
        default                    => [ucfirst($s),       'secondary', 'bi-circle'],
    };
}

[$slabel, $sclass, $sicon] = status_missao(
    (string)($missao['status'] ?? ''),
    (int)($missao['total_propostas'] ?? 0)
);

$ativo = in_array($missao['status'] ?? '', ['aceita','em_andamento','em_transito','em_entrega','aguardando_confirmacao','emergencia_reportada','emergencia']);

// Parceiros activos para delegação
$parceiros = [];
try {
    $stmtP = $conn->prepare(
        "SELECT p.id AS parceria_id, p.transportador_id, pt.nome_empresa, pt.telefone_comercial, u.nome
         FROM parcerias p
         LEFT JOIN perfil_transportador pt ON p.transportador_id = pt.usuario_id
         LEFT JOIN usuarios u ON p.transportador_id = u.id
         WHERE p.empresa_id = :eid AND p.status = 'ativa'
         ORDER BY pt.nome_empresa, u.nome"
    );
    $stmtP->execute([':eid' => $empresa_id]);
    $parceiros = $stmtP->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { error_log('parceiros query: ' . $e->getMessage()); }

// Linha do tempo de estados
$timeline = [
    ['aberta',          'Publicada',      'bi-broadcast'],
    ['aceita',          'Aceite',         'bi-handshake'],
    ['em_andamento',    'Em Execução',    'bi-truck'],
    ['concluida',       'Concluída',      'bi-patch-check-fill'],
];
$ordem_status = ['aberta'=>0,'em_negociacao'=>0,'aceita'=>1,'em_andamento'=>2,'em_transito'=>2,'em_entrega'=>2,'emergencia_reportada'=>2,'aguardando_confirmacao'=>3,'entrega_confirmada'=>3,'concluida'=>4,'cancelada'=>-1,'emergencia'=>2];
$nivel_atual  = $ordem_status[$missao['status'] ?? 'aberta'] ?? 0;
$timelineEventos = timeline_eventos_missao($conn, $missao_id);
$checklistEstado = checklist_estado_missao($conn, $missao_id);
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($missao['titulo'] ?? 'Missão'); ?> — TrackMoz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
    <style>
        #mapaMissaoDetalhe { height: 300px; border-radius: .5rem; }
        .detail-label { font-size: .72rem; text-transform: uppercase; letter-spacing: .05em;
                        color: #888; font-weight: 600; margin-bottom: 2px; }
        .detail-value { font-size: .95rem; font-weight: 500; color: #222; }
        .info-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 1.2rem; }

        /* Timeline */
        .timeline-steps { display: flex; align-items: center; gap: 0; }
        .tl-step { display: flex; flex-direction: column; align-items: center; flex: 1; position: relative; }
        .tl-step:not(:last-child)::after {
            content: '';
            position: absolute;
            top: 18px;
            left: 50%;
            width: 100%;
            height: 2px;
            background: #dee2e6;
            z-index: 0;
        }
        .tl-step.done:not(:last-child)::after { background: #0d6efd; }
        .tl-dot { width: 36px; height: 36px; border-radius: 50%; display: flex;
                  align-items: center; justify-content: center; font-size: 1rem;
                  border: 2px solid #dee2e6; background: #fff; z-index: 1; position: relative; }
        .tl-step.done .tl-dot { background: #0d6efd; border-color: #0d6efd; color: #fff; }
        .tl-step.current .tl-dot { background: #fff; border-color: #0d6efd; color: #0d6efd; box-shadow: 0 0 0 4px rgba(13,110,253,.15); }
        .tl-label { font-size: .68rem; margin-top: 5px; color: #888; text-align: center; }
        .tl-step.done .tl-label, .tl-step.current .tl-label { color: #0d6efd; font-weight: 600; }

        .proposta-card { border-radius: 12px; border: 1px solid #e8ecf0;
                         transition: box-shadow .15s; }
        .proposta-card:hover { box-shadow: 0 4px 14px rgba(0,0,0,.08); }
        .proposta-card.aceita-card { border-color: #28a745; background: #f0fff4; }

        .action-pill { border-radius: 10px; font-size: .85rem; }

        @media (max-width: 576px) {
            .info-grid { grid-template-columns: 1fr 1fr; }
            .tl-label { font-size: .6rem; }
        }
    </style>
</head>
<body>
<?php include_once('../../includes/menu.php'); ?>

<div class="container py-4">

    <!-- Breadcrumb + título -->
    <nav aria-label="breadcrumb" class="mb-2">
        <ol class="breadcrumb small">
            <li class="breadcrumb-item"><a href="missoes.php" class="text-decoration-none">Missões</a></li>
            <li class="breadcrumb-item active text-truncate" style="max-width:200px">
                <?php echo htmlspecialchars($missao['titulo'] ?? ''); ?>
            </li>
        </ol>
    </nav>

    <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
        <div>
            <h4 class="mb-1 fw-bold"><?php echo htmlspecialchars($missao['titulo'] ?? ''); ?></h4>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <?php if (!empty($missao['codigo_missao'])): ?>
                    <span class="badge bg-dark"><?php echo e($missao['codigo_missao']); ?></span>
                <?php endif; ?>
                <span class="badge bg-<?php echo $sclass; ?> fs-6">
                    <i class="bi <?php echo $sicon; ?> me-1"></i><?php echo $slabel; ?>
                </span>
                <span class="text-muted small">
                    <i class="bi bi-calendar me-1"></i>
                    <?php echo date('d/m/Y', strtotime($missao['data_criacao'])); ?>
                </span>
            </div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <?php if ($ativo): ?>
                <a href="rastrear-missao.php?id=<?php echo $missao_id; ?>"
                   class="btn btn-primary action-pill">
                    <i class="bi bi-geo-alt-fill me-1"></i>Rastrear
                </a>
            <?php endif; ?>
            <?php if ($missao['caminhoneiro_id'] ?? null): ?>
                <a href="<?php echo BASE_URL; ?>/pages/chat.php?user=<?php echo (int)$missao['caminhoneiro_id']; ?>&missao=<?php echo $missao_id; ?>"
                   class="btn btn-outline-primary action-pill">
                    <i class="bi bi-chat me-1"></i>Chat
                </a>
            <?php endif; ?>
            <?php if (in_array($missao['status'], ['em_andamento','em_transito','em_entrega','emergencia_reportada'], true)): ?>
                <a href="<?php echo BASE_URL; ?>/pages/admin/emergencias.php?missao_id=<?php echo $missao_id; ?>"
                   class="btn btn-outline-danger action-pill">
                    <i class="bi bi-exclamation-triangle me-1"></i>Emergências
                </a>
            <?php endif; ?>
            <?php if ($missao['status'] === 'entrega_confirmada' || $missao['status'] === 'concluida'): ?>
                <a href="<?php echo BASE_URL; ?>/pages/admin/entrega-comprovante.php?missao_id=<?php echo $missao_id; ?>"
                   class="btn btn-outline-dark action-pill" target="_blank">
                    <i class="bi bi-file-earmark-text me-1"></i>Comprovante
                </a>
            <?php endif; ?>
            <?php if (!empty($parceiros) && in_array($missao['status'], ['aberta','em_negociacao','aceita','em_andamento'], true)): ?>
                <button type="button" class="btn btn-outline-success action-pill" data-bs-toggle="modal" data-bs-target="#modalDelegar">
                    <i class="bi bi-handshake me-1"></i>Delegar a Parceiro
                </button>
            <?php endif; ?>
            <a href="missoes.php" class="btn btn-outline-secondary action-pill">
                <i class="bi bi-arrow-left me-1"></i>Voltar
            </a>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <!-- Linha do tempo (só para missões não canceladas) -->
    <?php if (!in_array($missao['status'] ?? '', ['cancelada'])): ?>
    <div class="card border-0 shadow-sm mb-4 p-3 p-md-4">
        <div class="timeline-steps">
            <?php foreach ($timeline as $i => [$key, $label, $icon]):
                $nivel_passo = $ordem_status[$key] ?? $i;
                $done    = $nivel_atual > $nivel_passo;
                $current = $nivel_atual === $nivel_passo;
            ?>
            <div class="tl-step <?php echo $done ? 'done' : ($current ? 'current' : ''); ?>">
                <div class="tl-dot">
                    <i class="bi <?php echo $done ? 'bi-check-lg' : $icon; ?>"></i>
                </div>
                <div class="tl-label"><?php echo $label; ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($timelineEventos)): ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-transparent border-bottom pt-3 pb-2">
            <h6 class="mb-0 fw-semibold"><i class="bi bi-clock-history me-2 text-primary"></i>Histórico operacional</h6>
        </div>
        <div class="card-body">
            <?php echo timeline_render_html($timelineEventos); ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="row g-4">

        <!-- Coluna principal -->
        <div class="col-lg-8">

            <?php
            $mapa_missao_id = $missao_id;
            $mapa_origem_lat = $missao['origem_lat'] ?? null;
            $mapa_origem_lng = $missao['origem_lng'] ?? null;
            $mapa_destino_lat = $missao['destino_lat'] ?? null;
            $mapa_destino_lng = $missao['destino_lng'] ?? null;
            $mapa_origem_txt = $missao['origem'] ?? '';
            $mapa_destino_txt = $missao['destino'] ?? '';
            $mapa_poll_missao_id = ($ativo && !empty($missao['caminhoneiro_id'])) ? $missao_id : null;
            include __DIR__ . '/../../includes/mapa-missao-widget.php';
            ?>

            <!-- Detalhes da carga e rota -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-transparent border-bottom pt-3 pb-2">
                    <h6 class="mb-0 fw-semibold"><i class="bi bi-info-circle me-2 text-primary"></i>Detalhes da Missão</h6>
                </div>
                <div class="card-body">
                    <!-- Rota em destaque -->
                    <div class="d-flex align-items-center gap-3 p-3 rounded-3 bg-light mb-4">
                        <div class="text-center">
                            <div class="small text-muted mb-1">Origem</div>
                            <div class="fw-semibold"><?php echo htmlspecialchars($missao['origem'] ?? '—'); ?></div>
                        </div>
                        <div class="flex-fill text-center text-muted">
                            <i class="bi bi-arrow-right fs-4"></i>
                        </div>
                        <div class="text-center">
                            <div class="small text-muted mb-1">Destino</div>
                            <div class="fw-semibold"><?php echo htmlspecialchars($missao['destino'] ?? '—'); ?></div>
                        </div>
                    </div>

                    <div class="info-grid">
                        <div>
                            <div class="detail-label">Tipo de Veículo</div>
                            <div class="detail-value"><i class="bi bi-truck me-1 text-primary"></i><?php echo htmlspecialchars($missao['tipo_veiculo'] ?? '—'); ?></div>
                        </div>
                        <div>
                            <div class="detail-label">Tipo de Carga</div>
                            <div class="detail-value"><i class="bi bi-box me-1 text-primary"></i><?php echo htmlspecialchars($missao['tipo_carga'] ?? '—'); ?></div>
                        </div>
                        <div>
                            <div class="detail-label">Valor</div>
                            <div class="detail-value fw-bold text-success">
                                <?php echo $missao['valor'] ? number_format((float)$missao['valor'], 2, ',', '.') . ' MT' : '—'; ?>
                            </div>
                        </div>
                        <div>
                            <div class="detail-label">Prazo de Entrega</div>
                            <div class="detail-value">
                                <?php echo $missao['prazo_entrega'] ? date('d/m/Y', strtotime($missao['prazo_entrega'])) : '—'; ?>
                            </div>
                        </div>
                        <?php if ($missao['peso_carga']): ?>
                        <div>
                            <div class="detail-label">Peso</div>
                            <div class="detail-value"><?php echo number_format((float)$missao['peso_carga'], 0, ',', '.'); ?> kg</div>
                        </div>
                        <?php endif; ?>
                        <div>
                            <div class="detail-label">Criada em</div>
                            <div class="detail-value small"><?php echo date('d/m/Y H:i', strtotime($missao['data_criacao'])); ?></div>
                        </div>
                    </div>

                    <?php if (!empty($missao['descricao'])): ?>
                        <hr class="my-3">
                        <div class="detail-label">Descrição</div>
                        <p class="mb-0 text-secondary"><?php echo nl2br(htmlspecialchars($missao['descricao'])); ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Motorista responsável -->
            <?php if ($missao['caminhoneiro_id'] ?? null): ?>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-transparent border-bottom pt-3 pb-2">
                    <h6 class="mb-0 fw-semibold"><i class="bi bi-person-badge me-2 text-primary"></i>Motorista Responsável</h6>
                </div>
                <div class="card-body">
                    <div class="d-flex flex-wrap align-items-center gap-3">
                        <div class="rounded-circle bg-primary bg-opacity-10 d-flex align-items-center justify-content-center"
                             style="width:56px;height:56px;flex-shrink:0">
                            <i class="bi bi-person-fill text-primary fs-3"></i>
                        </div>
                        <div class="flex-fill">
                            <div class="fw-semibold fs-6"><?php echo htmlspecialchars($missao['nome_caminhoneiro'] ?? '—'); ?></div>
                            <div class="d-flex flex-wrap gap-3 mt-1 small text-muted">
                                <span><i class="bi bi-star-fill text-warning me-1"></i><?php echo number_format((float)($missao['avaliacao_media'] ?? 0), 1); ?></span>
                                <span><i class="bi bi-truck me-1"></i><?php echo (int)($missao['total_entregas'] ?? 0); ?> entregas</span>
                                <?php if ($missao['telefone_caminhoneiro']): ?>
                                    <a href="tel:<?php echo htmlspecialchars($missao['telefone_caminhoneiro']); ?>" class="text-decoration-none">
                                        <i class="bi bi-telephone me-1"></i><?php echo htmlspecialchars($missao['telefone_caminhoneiro']); ?>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="d-flex gap-2 flex-wrap">
                            <a href="<?php echo BASE_URL; ?>/pages/shared/perfil-motorista.php?id=<?php echo (int)$missao['caminhoneiro_id']; ?>&missao=<?php echo $missao_id; ?>"
                               class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-person-badge me-1"></i>Ver perfil
                            </a>
                            <a href="<?php echo BASE_URL; ?>/pages/chat.php?user=<?php echo (int)$missao['caminhoneiro_id']; ?>&missao=<?php echo $missao_id; ?>"
                               class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-chat me-1"></i>Mensagem
                            </a>
                            <?php if ($ativo): ?>
                                <a href="rastrear-missao.php?id=<?php echo $missao_id; ?>"
                                   class="btn btn-sm btn-primary">
                                    <i class="bi bi-geo-alt-fill me-1"></i>Rastrear
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($missao['status'] === 'aguardando_confirmacao'): ?>
                        <div class="alert alert-warning mt-3 mb-0 d-flex flex-wrap align-items-center gap-2">
                            <i class="bi bi-hourglass-split fs-5"></i>
                            <div class="flex-fill">
                                <strong>Entrega reportada!</strong> Confirme a conclusão e avalie motorista / transportadora.
                            </div>
                            <a href="concluir-missao.php?id=<?php echo $missao_id; ?>" class="btn btn-success btn-sm">
                                <i class="bi bi-star me-1"></i>Confirmar e avaliar
                            </a>
                        </div>
                    <?php elseif ($faltaAvaliar && in_array($missao['status'], ['concluida', 'entrega_confirmada'], true)): ?>
                        <div class="alert alert-primary mt-3 mb-0 d-flex flex-wrap align-items-center gap-2">
                            <i class="bi bi-star fs-5"></i>
                            <div class="flex-fill">
                                <strong>Missão concluída.</strong> Ainda pode avaliar
                                <?php
                                $faltas = [];
                                if (!empty($missao['caminhoneiro_id']) && !$jaAvaliouMotorista) $faltas[] = 'motorista';
                                if (!empty($missao['transportador_id']) && !$jaAvaliouTransportador) $faltas[] = 'transportadora';
                                echo e(implode(' e ', $faltas));
                                ?>.
                            </div>
                            <a href="concluir-missao.php?id=<?php echo $missao_id; ?>" class="btn btn-warning btn-sm text-dark">
                                <i class="bi bi-star-fill me-1"></i>Avaliar agora
                            </a>
                        </div>
                    <?php elseif (!empty($minhasAvaliacoes)): ?>
                        <div class="alert alert-success mt-3 mb-0">
                            <div class="fw-semibold mb-1">As suas avaliações</div>
                            <?php foreach ($minhasAvaliacoes as $av): ?>
                                <div class="mb-1">
                                    <?php echo reputacao_estrelas_html((float)$av['nota']); ?>
                                    <span class="ms-1"><?php echo (int)$av['nota']; ?>/5</span>
                                    <?php if (!empty($av['comentario'])): ?>
                                        <span class="small">— "<?php echo e($av['comentario']); ?>"</span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (empty($missao['caminhoneiro_id']) && !empty($missao['transportador_id']) && $faltaAvaliar && ($missao['status'] ?? '') === 'concluida'): ?>
            <div class="alert alert-primary d-flex flex-wrap align-items-center gap-2 mb-4">
                <div class="flex-fill">Avalie a transportadora parceira desta missão.</div>
                <a href="concluir-missao.php?id=<?php echo $missao_id; ?>" class="btn btn-warning btn-sm text-dark">
                    <i class="bi bi-star-fill me-1"></i>Avaliar transportadora
                </a>
            </div>
            <?php endif; ?>

            <!-- Propostas -->
            <?php if (!empty($propostas)): ?>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-transparent border-bottom pt-3 pb-2 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-semibold">
                        <i class="bi bi-send me-2 text-primary"></i>Propostas Recebidas
                    </h6>
                    <span class="badge bg-primary"><?php echo count($propostas); ?></span>
                </div>
                <div class="card-body p-0">
                    <?php foreach ($propostas as $p):
                        $aceita = $p['status'] === 'aceita';
                    ?>
                    <div class="proposta-card <?php echo $aceita ? 'aceita-card' : ''; ?> m-3 p-3">
                        <div class="d-flex flex-wrap align-items-start justify-content-between gap-2">
                            <div>
                                <div class="fw-semibold"><?php echo htmlspecialchars($p['nome_caminhoneiro']); ?></div>
                                <div class="small text-muted d-flex gap-3 mt-1">
                                    <span><i class="bi bi-star-fill text-warning me-1"></i><?php echo number_format((float)($p['avaliacao_media']??0),1); ?></span>
                                    <span><?php echo (int)($p['total_entregas']??0); ?> entregas</span>
                                    <?php if ($p['tel']): ?>
                                        <a href="tel:<?php echo htmlspecialchars($p['tel']); ?>" class="text-decoration-none text-muted">
                                            <i class="bi bi-telephone me-1"></i><?php echo htmlspecialchars($p['tel']); ?>
                                        </a>
                                    <?php endif; ?>
                                </div>
                                <?php if ($p['observacoes']): ?>
                                    <div class="small text-muted mt-1 fst-italic">"<?php echo htmlspecialchars($p['observacoes']); ?>"</div>
                                <?php endif; ?>
                            </div>
                            <div class="text-end">
                                <div class="fw-bold text-success fs-5"><?php echo number_format((float)$p['valor'],2,',','.'); ?> MT</div>
                                <span class="badge bg-<?php echo $aceita?'success':'secondary'; ?> mt-1">
                                    <?php echo $aceita ? 'Aceite' : ucfirst($p['status']); ?>
                                </span>
                            </div>
                        </div>
                        <div class="d-flex gap-2 mt-2 flex-wrap">
                            <a href="<?php echo BASE_URL; ?>/pages/shared/perfil-motorista.php?id=<?php echo (int)$p['caminhoneiro_id']; ?>&missao=<?php echo $missao_id; ?>"
                               class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-person-badge me-1"></i>Ver perfil e avaliações
                            </a>
                            <?php if (!$aceita && $missao['status'] === 'aberta'): ?>
                                <a href="aceitar-proposta.php?proposta=<?php echo (int)$p['id']; ?>&missao=<?php echo $missao_id; ?>"
                                   class="btn btn-sm btn-success"
                                   onclick="return confirm('Aceitar proposta de <?php echo htmlspecialchars($p['nome_caminhoneiro'],ENT_QUOTES); ?>?')">
                                    <i class="bi bi-check-circle me-1"></i>Aceitar
                                </a>
                                <a href="<?php echo BASE_URL; ?>/pages/chat.php?user=<?php echo (int)$p['caminhoneiro_id']; ?>&missao=<?php echo $missao_id; ?>"
                                   class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-chat me-1"></i>Negociar
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Coluna lateral -->
        <div class="col-lg-4">

            <!-- Código OTP de entrega (só empresa) -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-transparent border-bottom pt-3 pb-2">
                    <h6 class="mb-0 fw-semibold"><i class="bi bi-key me-2 text-success"></i>Código de Entrega (OTP)</h6>
                </div>
                <div class="card-body">
                    <p class="small text-muted mb-2">Partilhe este código com o destinatário. O motorista <strong>não</strong> deve vê-lo antes da entrega.</p>
                    <p class="small text-muted mb-2"><i class="bi bi-info-circle me-1"></i><?php echo htmlspecialchars(sms_modo_descricao()); ?></p>
                    <div id="otpDisplay" class="text-center py-2">
                        <?php if ($otpInfo): ?>
                            <?php if ($otpInfo['usado']): ?>
                                <span class="badge bg-success">Código já utilizado</span>
                            <?php elseif ($otpInfo['bloqueado']): ?>
                                <span class="badge bg-danger">Bloqueado (<?php echo (int)$otpInfo['tentativas']; ?> tentativas)</span>
                            <?php elseif ($otpInfo['expirado']): ?>
                                <span class="badge bg-warning text-dark">Expirado</span>
                            <?php else: ?>
                                <span class="badge bg-primary">Activo — expira <?php echo date('d/m/Y H:i', strtotime($otpInfo['expira_em'])); ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-muted small">Nenhum código gerado</span>
                        <?php endif; ?>
                    </div>
                    <div id="otpCodigoBox" class="alert alert-success text-center fw-bold fs-4 <?php echo empty($otpCodigoExibir) ? 'd-none' : ''; ?> mb-2"><?php echo htmlspecialchars((string)($otpCodigoExibir ?? '')); ?></div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold mb-1">Telefone do destinatário</label>
                        <input type="tel" class="form-control form-control-sm" id="otpTelefone"
                               placeholder="Ex: 84 123 4567" inputmode="tel"
                               value="<?php echo htmlspecialchars($otpTelefone ?? ''); ?>">
                    </div>
                    <div class="d-grid gap-2 mb-2">
                        <button type="button" class="btn btn-success btn-sm" id="btnEnviarOtpWhatsapp" disabled>
                            <i class="bi bi-whatsapp me-1"></i>Enviar por WhatsApp
                        </button>
                        <button type="button" class="btn btn-outline-primary btn-sm" id="btnEnviarOtpSms" disabled>
                            <i class="bi bi-chat-dots me-1"></i>Enviar por SMS (app)
                        </button>
                    </div>
                    <div id="otpEnvioMsg" class="small text-muted mb-2"></div>
                    <button type="button" class="btn btn-outline-success btn-sm w-100" id="btnRegenerarOtp">
                        <i class="bi bi-arrow-clockwise me-1"></i>Gerar / Regenerar código
                    </button>
                    <input type="hidden" id="otpMissaoId" value="<?php echo $missao_id; ?>">
                    <input type="hidden" id="otpCsrf" value="<?php echo csrf_token(); ?>">
                    <input type="hidden" id="otpCodigoAtual" value="<?php echo htmlspecialchars((string)($otpCodigoExibir ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                </div>
            </div>

            <!-- Checklists operacionais -->
            <?php if (array_filter($checklistEstado)): ?>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-transparent border-bottom pt-3 pb-2">
                    <h6 class="mb-0 fw-semibold"><i class="bi bi-clipboard-check me-2 text-success"></i>Checklists</h6>
                </div>
                <div class="card-body">
                    <ul class="list-unstyled mb-0 small">
                        <?php
                        $checklistLabels = [
                            'pre_viagem' => 'Pré-viagem',
                            'recolha' => 'Recolha',
                            'entrega' => 'Entrega',
                        ];
                        foreach ($checklistLabels as $fase => $label):
                            $ok = !empty($checklistEstado[$fase]);
                        ?>
                        <li class="d-flex align-items-center mb-2">
                            <i class="bi bi-<?php echo $ok ? 'check-circle-fill text-success' : 'circle text-muted'; ?> me-2"></i>
                            <?php echo htmlspecialchars($label); ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <?php endif; ?>

            <!-- Acções -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-transparent border-bottom pt-3 pb-2">
                    <h6 class="mb-0 fw-semibold"><i class="bi bi-lightning me-2 text-warning"></i>Acções</h6>
                </div>
                <div class="card-body d-grid gap-2">
                    <?php
                    $podeApagar = validar_missao_pode_apagar($missao);
                    if ($missao['status'] === 'aberta'): ?>
                        <a href="propostas.php?missao=<?php echo $missao_id; ?>" class="btn btn-primary">
                            <i class="bi bi-list-check me-1"></i>Ver Propostas
                            <span class="badge bg-light text-primary ms-1"><?php echo $missao['total_propostas']; ?></span>
                        </a>
                        <a href="editar-missao.php?id=<?php echo $missao_id; ?>" class="btn btn-outline-secondary">
                            <i class="bi bi-pencil me-1"></i>Editar Missão
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($podeApagar['ok'])): ?>
                        <a href="apagar-missao.php?id=<?php echo $missao_id; ?>" class="btn btn-outline-danger">
                            <i class="bi bi-trash me-1"></i>Apagar / Retirar do ar
                        </a>
                    <?php elseif ($missao['status'] === 'aberta'): ?>
                        <button type="button" class="btn btn-outline-secondary" disabled
                                title="<?php echo e(regras_erro_mensagem($podeApagar)); ?>">
                            <i class="bi bi-trash me-1"></i>Apagar (indisponível)
                        </button>
                    <?php endif; ?>
                    <?php if ($missao['status'] === 'concluida'): ?>
                        <div class="alert alert-success mb-0 text-center py-2">
                            <i class="bi bi-patch-check-fill me-1"></i>Missão concluída com sucesso!
                        </div>
                        <?php if ($disputaMissao && $disputaMissao['status'] !== 'encerrada'): ?>
                            <a href="<?php echo BASE_URL; ?>/pages/shared/disputa.php?id=<?php echo (int)$disputaMissao['id']; ?>"
                               class="btn btn-warning btn-sm">
                                <i class="bi bi-shield-exclamation me-1"></i>
                                Disputa <?php echo e(disputa_status_label($disputaMissao['status'])); ?> (#<?php echo (int)$disputaMissao['id']; ?>)
                            </a>
                        <?php elseif ($disputaMissao && $disputaMissao['status'] === 'encerrada'): ?>
                            <a href="<?php echo BASE_URL; ?>/pages/shared/disputa.php?id=<?php echo (int)$disputaMissao['id']; ?>"
                               class="btn btn-outline-secondary btn-sm">
                                <i class="bi bi-shield-check me-1"></i>Ver disputa encerrada
                            </a>
                        <?php elseif ($podeAbrirDisputa): ?>
                            <button type="button" class="btn btn-outline-warning" data-bs-toggle="modal" data-bs-target="#modalDisputa">
                                <i class="bi bi-shield-exclamation me-1"></i>Abrir disputa
                            </button>
                        <?php endif; ?>
                    <?php elseif ($missao['status'] === 'cancelada'): ?>
                        <div class="alert alert-secondary mb-0 text-center py-2">
                            <i class="bi bi-x-circle me-1"></i>Missão cancelada / retirada do ar
                        </div>
                        <a href="nova-missao.php" class="btn btn-primary">
                            <i class="bi bi-plus-circle me-1"></i>Nova Missão
                        </a>
                    <?php elseif ($ativo && empty($podeApagar['ok'])): ?>
                        <div class="alert alert-info mb-0 py-2 text-center small">
                            <i class="bi bi-truck me-1"></i>Missão em andamento
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Centro de documentos -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-transparent border-bottom pt-3 pb-2 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-semibold"><i class="bi bi-folder2-open me-2 text-secondary"></i>Centro de documentos</h6>
                    <a href="documentos/explorador.php?missao_id=<?php echo $missao_id; ?>" class="small">Explorador</a>
                </div>
                <div class="card-body p-0">
                    <?php echo tmz_centro_documentos_render($conn, $missao_id); ?>
                </div>
            </div>

            <!-- Documentos (legado — atalhos rápidos) -->
            <div class="card border-0 shadow-sm mb-4 d-none">
                <div class="card-header bg-transparent border-bottom pt-3 pb-2">
                    <h6 class="mb-0 fw-semibold"><i class="bi bi-file-earmark me-2 text-secondary"></i>Documentos</h6>
                </div>
                <div class="card-body d-grid gap-2">
                    <a class="btn btn-outline-secondary btn-sm text-start" target="_blank"
                       href="documentos/missao-registo.php?id=<?php echo $missao_id; ?>">
                        <i class="bi bi-file-text me-2"></i>Registo da Missão
                    </a>
                    <?php if ((int)($missao['propostas_aceitas']??0) > 0 || $missao['status'] !== 'aberta'): ?>
                        <a class="btn btn-outline-secondary btn-sm text-start" target="_blank"
                           href="documentos/contrato-transporte.php?missao=<?php echo $missao_id; ?>">
                            <i class="bi bi-file-earmark-check me-2"></i>Contrato de Transporte
                        </a>
                        <a class="btn btn-outline-secondary btn-sm text-start" target="_blank"
                           href="documentos/ordem-transporte.php?id=<?php echo $missao_id; ?>">
                            <i class="bi bi-file-earmark-arrow-up me-2"></i>Ordem de Transporte
                        </a>
                    <?php endif; ?>
                    <?php if ($missao['status'] === 'concluida'): ?>
                        <a class="btn btn-outline-success btn-sm text-start" target="_blank"
                           href="documentos/comprovativo-conclusao.php?id=<?php echo $missao_id; ?>">
                            <i class="bi bi-file-earmark-check2 me-2"></i>Comprovativo de Conclusão
                        </a>
                        <a class="btn btn-outline-secondary btn-sm text-start" target="_blank"
                           href="documentos/fatura.php?missao=<?php echo $missao_id; ?>">
                            <i class="bi bi-receipt me-2"></i>Factura
                        </a>
                        <a class="btn btn-outline-secondary btn-sm text-start" target="_blank"
                           href="documentos/recibo.php?missao=<?php echo $missao_id; ?>">
                            <i class="bi bi-cash-coin me-2"></i>Recibo
                        </a>
                        <a class="btn btn-outline-secondary btn-sm text-start" target="_blank"
                           href="documentos/avaliacao.php?missao=<?php echo $missao_id; ?>">
                            <i class="bi bi-star me-2"></i>Avaliação
                        </a>
                    <?php endif; ?>
                    <a class="btn btn-outline-secondary btn-sm text-start" target="_blank"
                       href="documentos/termo-responsabilidade.php?missao=<?php echo $missao_id; ?>">
                        <i class="bi bi-shield-check me-2"></i>Termo de Responsabilidade
                    </a>
                    <a class="btn btn-outline-secondary btn-sm text-start" target="_blank"
                       href="documentos/relatorio-incidente.php?missao=<?php echo $missao_id; ?>">
                        <i class="bi bi-exclamation-octagon me-2"></i>Relatório de Incidente
                    </a>
                    <a class="btn btn-outline-secondary btn-sm text-start"
                       href="documentos/explorador.php?missao_id=<?php echo $missao_id; ?>">
                        <i class="bi bi-folder2-open me-2"></i>Explorador de Documentos
                    </a>
                </div>
            </div>

            <!-- Resumo stats -->
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between py-2 border-bottom">
                        <span class="small text-muted">Propostas recebidas</span>
                        <span class="badge bg-primary"><?php echo $missao['total_propostas']; ?></span>
                    </div>
                    <div class="d-flex justify-content-between py-2 border-bottom">
                        <span class="small text-muted">Última actualização</span>
                        <span class="small"><?php echo date('d/m H:i', strtotime($missao['data_atualizacao'] ?? $missao['data_criacao'])); ?></span>
                    </div>
                    <?php if ($missao['prazo_entrega']): ?>
                    <div class="d-flex justify-content-between py-2">
                        <span class="small text-muted">Prazo</span>
                        <span class="small fw-semibold <?php echo strtotime($missao['prazo_entrega']) < time() && $missao['status'] !== 'concluida' ? 'text-danger' : ''; ?>">
                            <?php echo date('d/m/Y', strtotime($missao['prazo_entrega'])); ?>
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Disputa -->
<div class="modal fade" id="modalDisputa" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-shield-exclamation me-2 text-warning"></i>Abrir disputa</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted">Apenas missões concluídas. Descreva o problema (mín. 20 caracteres) e escolha a categoria.</p>
                <form id="formDisputa">
                    <input type="hidden" name="missao_id" value="<?php echo $missao_id; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                    <div class="mb-2">
                        <label class="form-label">Categoria *</label>
                        <select name="categoria" class="form-select" required>
                            <?php foreach (DISPUTA_CATEGORIAS as $k => $lab): ?>
                                <option value="<?php echo e($k); ?>"><?php echo e($lab); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Prioridade</label>
                        <select name="prioridade" class="form-select">
                            <option value="normal">Normal</option>
                            <option value="alta">Alta</option>
                            <option value="urgente">Urgente</option>
                        </select>
                    </div>
                    <label class="form-label">Motivo da disputa *</label>
                    <textarea name="motivo" class="form-control" rows="4" required minlength="20"
                              placeholder="Descreva o problema com detalhe (mín. 20 caracteres)…"></textarea>
                </form>
                <div id="msgDisputa" class="mt-2 small"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-warning" id="btnSubmeterDisputa">
                    <i class="bi bi-send me-1"></i>Submeter disputa
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal Delegar a Parceiro -->
<div class="modal fade" id="modalDelegar" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-handshake me-2 text-success"></i>Delegar Missão a Parceiro</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted">Seleccione um transportador parceiro para assumir esta missão:</p>
                <div class="list-group">
                    <?php foreach ($parceiros as $par): ?>
                        <button type="button"
                                class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
                                onclick="delegarMissao(<?php echo (int)$par['transportador_id']; ?>, '<?php echo addslashes(e($par['nome_empresa'] ?? $par['nome'])); ?>')">
                            <div>
                                <div class="fw-semibold"><?php echo e($par['nome_empresa'] ?? $par['nome']); ?></div>
                                <?php if ($par['telefone_comercial']): ?>
                                    <div class="small text-muted"><i class="bi bi-telephone me-1"></i><?php echo e($par['telefone_comercial']); ?></div>
                                <?php endif; ?>
                            </div>
                            <i class="bi bi-chevron-right text-muted"></i>
                        </button>
                    <?php endforeach; ?>
                </div>
                <div class="mt-3">
                    <label class="form-label small text-muted">Mensagem opcional ao parceiro</label>
                    <textarea id="delegarMensagem" class="form-control form-control-sm" rows="2" placeholder="Ex: Preferência por entrega antes das 14h..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="<?php echo BASE_URL; ?>/assets/js/mapa-missao-detalhe.js"></script>
<script>
const CSRF_TOKEN = <?php echo json_encode(csrf_token()); ?>;
async function delegarMissao(transportadorId, nomeParceiro) {
    if (!confirm('Confirma delegar esta missão a ' + nomeParceiro + '?')) return;
    const mensagem = document.getElementById('delegarMensagem').value;
    const form = new FormData();
    form.append('missao_id', <?php echo $missao_id; ?>);
    form.append('transportador_id', transportadorId);
    form.append('mensagem', mensagem);
    form.append('csrf_token', CSRF_TOKEN);
    try {
        const r = await fetch('<?php echo BASE_URL; ?>/api/missao-delegar.php', { method: 'POST', body: form });
        const d = await r.json();
        alert(d.message);
        if (d.success) location.reload();
    } catch(e) {
        alert('Erro de ligação. Tente novamente.');
    }
}

const btnRegenerarOtp = document.getElementById('btnRegenerarOtp');
let otpCodigoVisivel = document.getElementById('otpCodigoAtual').value;

function otpAbrirUrl(url) {
    if (!url) return false;
    const a = document.createElement('a');
    a.href = url;
    a.target = '_blank';
    a.rel = 'noopener noreferrer';
    document.body.appendChild(a);
    a.click();
    a.remove();
    return true;
}

function otpActualizarBotoesEnvio() {
    const tel = (document.getElementById('otpTelefone')?.value || '').trim();
    const temCodigo = !!otpCodigoVisivel;
    const activo = temCodigo && tel.replace(/\D/g, '').length >= 9;
    ['btnEnviarOtpWhatsapp', 'btnEnviarOtpSms'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.disabled = !activo;
    });
}

async function otpEnviarDestinatario(abrir) {
    const tel = document.getElementById('otpTelefone').value.trim();
    if (!tel) {
        alert('Indique o telefone do destinatário (ex: 84 123 4567).');
        return;
    }
    if (!otpCodigoVisivel) {
        alert('Gere o código OTP primeiro (botão Gerar / Regenerar).');
        return;
    }
    const form = new FormData();
    form.append('missao_id', document.getElementById('otpMissaoId').value);
    form.append('telefone', tel);
    form.append('codigo', otpCodigoVisivel);
    form.append('csrf_token', document.getElementById('otpCsrf').value);
    const msgEl = document.getElementById('otpEnvioMsg');
    msgEl.innerHTML = '<span class="text-primary">A preparar envio…</span>';
    try {
        const r = await fetch('<?php echo BASE_URL; ?>/api/entrega-otp-enviar.php', {
            method: 'POST',
            body: form,
            credentials: 'same-origin'
        });
        let d;
        try { d = await r.json(); } catch (_) {
            msgEl.innerHTML = '<span class="text-danger">Resposta inválida do servidor.</span>';
            return;
        }
        if (!d.ok) {
            msgEl.innerHTML = '<span class="text-danger">' + (d.error || 'Erro ao enviar') + '</span>';
            return;
        }
        if (d.enviado_automatico) {
            msgEl.innerHTML = '<span class="text-success">' + (d.message || 'SMS enviado.') + '</span>';
            return;
        }
        const url = abrir === 'whatsapp' ? d.whatsapp_url : d.sms_url;
        if (url) {
            otpAbrirUrl(url);
            msgEl.innerHTML = '<span class="text-success">Abriu ' + (abrir === 'whatsapp' ? 'WhatsApp' : 'SMS') + '. Confirme o envio na app.</span>'
                + ' <a href="' + url + '" target="_blank" rel="noopener">Abrir novamente</a>';
        } else {
            msgEl.innerHTML = '<span class="text-danger">Não foi possível criar o link. Verifique o telefone.</span>';
        }
    } catch (e) {
        msgEl.innerHTML = '<span class="text-danger">Erro de ligação</span>';
    }
}

document.getElementById('otpTelefone')?.addEventListener('input', otpActualizarBotoesEnvio);
document.getElementById('btnEnviarOtpWhatsapp')?.addEventListener('click', () => otpEnviarDestinatario('whatsapp'));
document.getElementById('btnEnviarOtpSms')?.addEventListener('click', () => otpEnviarDestinatario('sms'));

if (btnRegenerarOtp) {
    btnRegenerarOtp.addEventListener('click', async () => {
        if (!confirm('Gerar novo código OTP? O código anterior deixará de funcionar.')) return;
        btnRegenerarOtp.disabled = true;
        const form = new FormData();
        form.append('missao_id', document.getElementById('otpMissaoId').value);
        form.append('regenerar', '1');
        form.append('csrf_token', document.getElementById('otpCsrf').value);
        const tel = document.getElementById('otpTelefone')?.value.trim();
        if (tel) form.append('telefone', tel);
        const msgEl = document.getElementById('otpEnvioMsg');
        if (msgEl) msgEl.innerHTML = '<span class="text-primary">A gerar código…</span>';
        try {
            const r = await fetch('<?php echo BASE_URL; ?>/api/entrega-otp-generate.php', {
                method: 'POST',
                body: form,
                credentials: 'same-origin'
            });
            let d;
            try { d = await r.json(); } catch (_) {
                alert('Resposta inválida do servidor. Recarregue a página.');
                return;
            }
            const box = document.getElementById('otpCodigoBox');
            if (d.ok) {
                box.textContent = d.codigo;
                box.classList.remove('d-none');
                otpCodigoVisivel = d.codigo;
                document.getElementById('otpCodigoAtual').value = d.codigo;
                const disp = document.getElementById('otpDisplay');
                if (disp) {
                    disp.innerHTML = '<span class="badge bg-primary">Activo — expira '
                        + (d.expira_em ? new Date(d.expira_em.replace(' ', 'T')).toLocaleString('pt-PT') : '')
                        + '</span>';
                }
                otpActualizarBotoesEnvio();
                let msg = 'Novo código: ' + d.codigo;
                if (d.sms && d.sms.enviado_automatico) {
                    msg += '\n\nSMS enviado automaticamente ao destinatário.';
                } else {
                    msg += '\n\nAgora use «Enviar por WhatsApp» ou «Enviar por SMS» com o telefone do destinatário.';
                }
                if (msgEl) {
                    msgEl.innerHTML = '<span class="text-success">Código gerado. Use WhatsApp/SMS para enviar.</span>';
                }
                alert(msg);
            } else {
                const err = d.error || d.detail || 'Erro ao gerar código';
                if (msgEl) msgEl.innerHTML = '<span class="text-danger">' + err + '</span>';
                alert(err);
            }
        } catch (e) {
            alert('Erro de ligação ao gerar OTP.');
        } finally {
            btnRegenerarOtp.disabled = false;
        }
    });
}
<?php if (!empty($otpCodigoExibir)): ?>
otpCodigoVisivel = <?php echo json_encode($otpCodigoExibir); ?>;
document.getElementById('otpCodigoAtual').value = otpCodigoVisivel;
otpActualizarBotoesEnvio();
<?php else: ?>
otpActualizarBotoesEnvio();
<?php endif; ?>

document.getElementById('btnSubmeterDisputa')?.addEventListener('click', async () => {
    const form = document.getElementById('formDisputa');
    const motivo = form?.querySelector('[name=motivo]')?.value.trim();
    if (!motivo) {
        alert('Indique o motivo da disputa.');
        return;
    }
    if (!confirm('Confirma abertura de disputa para esta missão?')) return;
    const msg = document.getElementById('msgDisputa');
    msg.textContent = 'A submeter…';
    try {
        const r = await fetch('<?php echo BASE_URL; ?>/api/disputa-criar.php', {
            method: 'POST',
            body: new FormData(form)
        });
        const d = await r.json();
        if (d.success) {
            if (d.redirect) location.href = d.redirect;
            else location.reload();
        } else {
            msg.innerHTML = '<span class="text-danger">' + (d.message || 'Erro') + '</span>';
        }
    } catch (e) {
        msg.innerHTML = '<span class="text-danger">Erro de ligação</span>';
    }
});
</script>
</body>
</html>
