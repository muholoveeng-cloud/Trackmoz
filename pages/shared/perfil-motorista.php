<?php
/**
 * Perfil público do motorista — visível a empresa, transportador e admin.
 * Usado a partir de propostas e missões para ver reputação e comentários.
 */
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');
include_once('../../includes/auth.php');
include_once('../../includes/helpers.php');
include_once('../../includes/reputacao-helpers.php');

require_role(['empresa', 'transportador', 'admin', 'caminhoneiro'], '../login.php');

$motoristaId = (int)($_GET['id'] ?? 0);
$missaoRef   = (int)($_GET['missao'] ?? 0);
$viewerType  = (string)($_SESSION['user_type'] ?? '');
$viewerId    = (int)($_SESSION['user_id'] ?? 0);

if ($motoristaId <= 0) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

// Motorista a ver o próprio perfil → página completa
if ($viewerType === 'caminhoneiro' && $viewerId === $motoristaId) {
    header('Location: ' . BASE_URL . '/pages/caminhoneiro/perfil.php');
    exit;
}
if ($viewerType === 'caminhoneiro') {
    http_response_code(403);
    echo 'Sem permissão para ver este perfil.';
    exit;
}

$usuario = null;
$perfil  = null;
try {
    $stmt = $conn->prepare(
        "SELECT u.id, u.nome, u.telefone, u.foto_perfil, u.data_registro, u.status, u.tipo_usuario
         FROM usuarios u
         WHERE u.id = :id AND u.tipo_usuario = 'caminhoneiro'
         LIMIT 1"
    );
    $stmt->execute([':id' => $motoristaId]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($usuario) {
        $st = $conn->prepare('SELECT * FROM perfil_caminhoneiro WHERE usuario_id = :id LIMIT 1');
        $st->execute([':id' => $motoristaId]);
        $perfil = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $e) {
    error_log('perfil-motorista: ' . $e->getMessage());
}

if (!$usuario) {
    http_response_code(404);
    echo 'Motorista não encontrado.';
    exit;
}

$reputacao  = reputacao_utilizador($conn, $motoristaId);
$comentarios = reputacao_comentarios($conn, $motoristaId, 30);

$missoesConcluidas = 0;
try {
    $st = $conn->prepare(
        "SELECT COUNT(*) FROM missoes
         WHERE caminhoneiro_id = :id AND status IN ('concluida','entrega_confirmada')"
    );
    $st->execute([':id' => $motoristaId]);
    $missoesConcluidas = (int)$st->fetchColumn();
} catch (Throwable $e) { /* ignore */ }

if ($missaoRef > 0) {
    $voltar = match ($viewerType) {
        'empresa' => BASE_URL . '/pages/contratante/propostas.php?missao=' . $missaoRef,
        'transportador' => BASE_URL . '/pages/transportador/detalhes-missao.php?id=' . $missaoRef,
        default => BASE_URL . '/pages/contratante/detalhes-missao.php?id=' . $missaoRef,
    };
} else {
    $voltar = match ($viewerType) {
        'empresa' => BASE_URL . '/pages/contratante/dashboard.php',
        'transportador' => BASE_URL . '/pages/transportador/dashboard.php',
        default => BASE_URL . '/index.php',
    };
}

$foto = !empty($usuario['foto_perfil'])
    ? BASE_URL . '/' . ltrim((string)$usuario['foto_perfil'], '/')
    : '';
$disp = (string)($perfil['disponibilidade'] ?? '—');
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($usuario['nome']); ?> — Perfil do Motorista</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <style>
        .pm-hero {
            background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 55%, #0ea5e9 140%);
            color: #fff; border-radius: 1rem; padding: 1.5rem;
        }
        .pm-avatar {
            width: 88px; height: 88px; border-radius: 50%;
            object-fit: cover; border: 3px solid rgba(255,255,255,.35);
            background: rgba(255,255,255,.12);
        }
        .pm-avatar-ph {
            width: 88px; height: 88px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            background: rgba(255,255,255,.12); font-size: 2.2rem;
            border: 3px solid rgba(255,255,255,.35);
        }
        .pm-stat {
            background: #fff; border-radius: .75rem; padding: 1rem;
            border: 1px solid #e2e8f0; text-align: center; height: 100%;
        }
        .pm-stat b { display: block; font-size: 1.35rem; color: #0f172a; }
        .pm-stat span { font-size: .72rem; color: #64748b; text-transform: uppercase; letter-spacing: .04em; }
        .pm-review {
            border: 1px solid #e2e8f0; border-radius: .75rem;
            padding: 1rem 1.1rem; margin-bottom: .75rem; background: #fff;
        }
        .pm-review .who { font-weight: 600; color: #0f172a; }
        .pm-review .when { font-size: .78rem; color: #94a3b8; }
    </style>
</head>
<body>
<?php include_once('../../includes/menu.php'); ?>

<div class="container py-4" style="max-width:920px">
    <div class="mb-3">
        <a href="<?php echo e($voltar); ?>" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Voltar
        </a>
    </div>

    <div class="pm-hero mb-4">
        <div class="d-flex flex-wrap align-items-center gap-3">
            <?php if ($foto): ?>
                <img src="<?php echo e($foto); ?>" alt="" class="pm-avatar">
            <?php else: ?>
                <div class="pm-avatar-ph"><i class="bi bi-person-fill"></i></div>
            <?php endif; ?>
            <div class="flex-fill">
                <h1 class="h4 mb-1"><?php echo e($usuario['nome']); ?></h1>
                <div class="d-flex flex-wrap gap-2 align-items-center small opacity-90">
                    <?php echo reputacao_badge_html($reputacao); ?>
                    <span><?php echo reputacao_estrelas_html((float)$reputacao['media']); ?></span>
                    <?php if (($usuario['status'] ?? '') === 'activo' || ($usuario['status'] ?? '') === 'aprovado'): ?>
                        <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">Conta activa</span>
                    <?php endif; ?>
                </div>
                <div class="mt-2 small opacity-75">
                    <?php if (!empty($usuario['telefone'])): ?>
                        <i class="bi bi-telephone me-1"></i><?php echo e($usuario['telefone']); ?>
                        <span class="mx-2">·</span>
                    <?php endif; ?>
                    Membro desde <?php echo e(date('m/Y', strtotime($usuario['data_registro'] ?? 'now'))); ?>
                </div>
            </div>
            <?php if ($viewerType === 'empresa' && $missaoRef > 0): ?>
                <a class="btn btn-light btn-sm"
                   href="<?php echo BASE_URL; ?>/pages/chat.php?user=<?php echo $motoristaId; ?>&missao=<?php echo $missaoRef; ?>">
                    <i class="bi bi-chat-dots"></i> Mensagem
                </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="pm-stat">
                <b><?php echo number_format((float)$reputacao['media'], 1, ',', '.'); ?></b>
                <span>Média</span>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="pm-stat">
                <b><?php echo (int)$reputacao['total']; ?></b>
                <span>Avaliações</span>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="pm-stat">
                <b><?php echo max($missoesConcluidas, (int)($perfil['total_entregas'] ?? 0)); ?></b>
                <span>Entregas</span>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="pm-stat">
                <b class="text-capitalize" style="font-size:1rem"><?php echo e($disp !== '' ? $disp : '—'); ?></b>
                <span>Disponibilidade</span>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-md-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h6 fw-semibold mb-3"><i class="bi bi-truck me-2 text-primary"></i>Veículo / docs</h2>
                    <dl class="row small mb-0">
                        <dt class="col-5 text-muted">Tipo</dt>
                        <dd class="col-7"><?php echo e($perfil['tipo_veiculo'] ?? '—'); ?></dd>
                        <dt class="col-5 text-muted">Capacidade</dt>
                        <dd class="col-7">
                            <?php
                            $cap = (float)($perfil['capacidade_carga'] ?? 0);
                            echo $cap > 0 ? number_format($cap, 0, ',', '.') . ' kg' : '—';
                            ?>
                        </dd>
                        <dt class="col-5 text-muted">CNH</dt>
                        <dd class="col-7">
                            <?php
                            $cnh = trim((string)($perfil['numero_cnh'] ?? ''));
                            if ($cnh !== '') {
                                echo e(substr($cnh, 0, 3) . '***');
                            } else {
                                echo '—';
                            }
                            $exp = $perfil['validade_cnh'] ?? $perfil['cnh_validade'] ?? null;
                            if ($exp) {
                                $expTs = strtotime((string)$exp);
                                $ok = $expTs && $expTs >= time();
                                echo ' <span class="badge bg-' . ($ok ? 'success' : 'danger') . '">'
                                    . ($ok ? 'válida' : 'expirada') . '</span>';
                            }
                            ?>
                        </dd>
                    </dl>
                    <?php if (!empty($perfil['descricao_veiculo']) && $perfil['descricao_veiculo'] !== 'Não informado'): ?>
                        <hr>
                        <p class="small text-secondary mb-0"><?php echo nl2br(e($perfil['descricao_veiculo'])); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-7">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h2 class="h6 fw-semibold mb-3">
                        <i class="bi bi-chat-quote me-2 text-warning"></i>O que outros dizem
                        <span class="badge bg-light text-dark ms-1"><?php echo count($comentarios); ?></span>
                    </h2>
                    <?php if (empty($comentarios)): ?>
                        <p class="text-muted small mb-0">Ainda sem comentários públicos. A média reflecte o histórico de entregas quando disponível.</p>
                    <?php else: ?>
                        <?php foreach ($comentarios as $c): ?>
                            <div class="pm-review">
                                <div class="d-flex justify-content-between gap-2 align-items-start mb-1">
                                    <div>
                                        <span class="who"><?php echo e($c['avaliador']); ?></span>
                                        <div><?php echo reputacao_estrelas_html((float)$c['nota']); ?></div>
                                    </div>
                                    <span class="when">
                                        <?php echo $c['data'] ? e(date('d/m/Y', strtotime($c['data']))) : ''; ?>
                                    </span>
                                </div>
                                <?php if ($c['comentario'] !== ''): ?>
                                    <p class="small mb-0 text-secondary">"<?php echo e($c['comentario']); ?>"</p>
                                <?php else: ?>
                                    <p class="small mb-0 text-muted fst-italic">Sem comentário escrito.</p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
