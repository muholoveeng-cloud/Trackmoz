<?php
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');
include_once('../../includes/auth.php');
include_once('../../includes/helpers.php');
include_once('../../includes/kyc-helpers.php');
include_once('../../includes/kyc-advertencias-helpers.php');
include_once('../../includes/notificacoes-helpers.php');

require_role(['admin'], '../login.php');
kyc_advertencias_bootstrap($conn);

$success = $error = '';
$adminId = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $uid = (int)($_POST['usuario_id'] ?? 0);
    $acao = (string)($_POST['acao'] ?? '');

    if ($uid <= 0) {
        $error = 'Utilizador inválido.';
    } elseif ($acao === 'advertir') {
        $msg = trim((string)($_POST['mensagem'] ?? ''));
        $dias = (int)($_POST['dias_prazo'] ?? KYC_DIAS_APOS_ADVERTENCIA);
        $res = kyc_enviar_advertencia($conn, $uid, $adminId, $msg, $dias);
        if ($res['ok']) {
            $success = 'Advertência enviada. Prazo até ' . date('d/m/Y', strtotime($res['prazo'])) . '.';
        } else {
            $error = $res['error'] ?? 'Não foi possível enviar a advertência.';
        }
    } elseif ($acao === 'bloquear') {
        $motivo = trim((string)($_POST['motivo'] ?? ''));
        $res = kyc_bloquear_conta($conn, $uid, $adminId, $motivo);
        if ($res['ok']) {
            $success = 'Conta bloqueada. O utilizador deixa de poder autenticar-se.';
        } else {
            $error = $res['error'] ?? 'Falha ao bloquear.';
        }
    } elseif ($acao === 'remover') {
        $motivo = trim((string)($_POST['motivo'] ?? ''));
        $confirmar = trim((string)($_POST['confirmar'] ?? ''));
        if (strtoupper($confirmar) !== 'REMOVER') {
            $error = 'Para remover, escreva REMOVER no campo de confirmação.';
        } else {
            $res = kyc_remover_conta($conn, $uid, $adminId, $motivo);
            if ($res['ok']) {
                $success = 'Conta desactivada (removida da plataforma).';
            } else {
                $error = $res['error'] ?? 'Falha ao remover.';
            }
        }
    } else {
        $error = 'Acção desconhecida.';
    }
}

$filtro = $_GET['filtro'] ?? 'todos';
$listaCompleta = kyc_listar_contas_irregulares($conn);
$totalIrregulares = count($listaCompleta);
$expirados = 0;
$comAdvertencia = 0;
foreach ($listaCompleta as $row) {
    if (!empty($row['prazo_expirado'])) {
        $expirados++;
    }
    if ((int)$row['advertencias'] > 0) {
        $comAdvertencia++;
    }
}

$lista = $listaCompleta;
if ($filtro === 'expirado') {
    $lista = array_values(array_filter($lista, static fn($r) => !empty($r['prazo_expirado'])));
} elseif ($filtro === 'advertidos') {
    $lista = array_values(array_filter($lista, static fn($r) => (int)$r['advertencias'] > 0));
} elseif ($filtro === 'urgentes') {
    $lista = array_values(array_filter($lista, static fn($r) => ($r['nivel'] ?? '') === 'danger'));
}

$tiposLabel = [
    'caminhoneiro'  => 'Motorista',
    'empresa'       => 'Empresa',
    'transportador' => 'Transportador',
];
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contas irregulares — TrackMoz Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin.css">
    <style>
        .tm-irreg-card { border-left: 4px solid #ffc107; }
        .tm-irreg-card.danger { border-left-color: #dc3545; }
        .tm-doc-missing { font-size: .85rem; }
    </style>
</head>
<body>
<?php include_once('../../includes/menu.php'); ?>

<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h1 class="h3 mb-1"><i class="bi bi-exclamation-octagon text-danger"></i> Contas irregulares</h1>
            <p class="text-muted mb-0">
                Utilizadores activos sem documentação regularizada.
                Envie advertências com prazo; se não regularizarem, pode bloquear ou remover a conta.
            </p>
        </div>
        <a href="verificar-documentos.php?status=pendente" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-file-earmark-check"></i> Ver documentos
        </a>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show"><?php echo e($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show"><?php echo e($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <a href="?filtro=todos" class="text-decoration-none">
                <div class="card filter-card <?php echo $filtro === 'todos' ? 'border-warning' : ''; ?>">
                    <div class="card-body">
                        <div class="text-muted small">Total irregulares</div>
                        <div class="fs-3 fw-bold"><?php echo (int)$totalIrregulares; ?></div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a href="?filtro=advertidos" class="text-decoration-none">
                <div class="card <?php echo $filtro === 'advertidos' ? 'border-warning' : ''; ?>">
                    <div class="card-body">
                        <div class="text-muted small">Já advertidos</div>
                        <div class="fs-3 fw-bold text-warning"><?php echo $comAdvertencia; ?></div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a href="?filtro=expirado" class="text-decoration-none">
                <div class="card <?php echo $filtro === 'expirado' ? 'border-danger' : ''; ?>">
                    <div class="card-body">
                        <div class="text-muted small">Prazo expirado (remover)</div>
                        <div class="fs-3 fw-bold text-danger"><?php echo $expirados; ?></div>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <div class="mb-3">
        <div class="btn-group btn-group-sm">
            <a class="btn btn-outline-secondary <?php echo $filtro === 'todos' ? 'active' : ''; ?>" href="?filtro=todos">Todos</a>
            <a class="btn btn-outline-secondary <?php echo $filtro === 'advertidos' ? 'active' : ''; ?>" href="?filtro=advertidos">Advertidos</a>
            <a class="btn btn-outline-secondary <?php echo $filtro === 'expirado' ? 'active' : ''; ?>" href="?filtro=expirado">Prazo expirado</a>
            <a class="btn btn-outline-secondary <?php echo $filtro === 'urgentes' ? 'active' : ''; ?>" href="?filtro=urgentes">Urgentes</a>
        </div>
    </div>

    <?php if (empty($lista)): ?>
        <div class="alert alert-success mb-0">
            <i class="bi bi-check-circle"></i> Nenhuma conta irregular neste filtro.
        </div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($lista as $row):
                $u = $row['usuario'];
                $uid = (int)$u['id'];
                $nivel = $row['nivel'] === 'danger' ? 'danger' : 'warning';
                $hist = kyc_historico_advertencias($conn, $uid);
                $faltamTxt = implode(', ', array_values($row['faltam_docs'] ?: ['documentação incompleta']));
                ?>
                <div class="col-lg-6">
                    <div class="card tm-irreg-card <?php echo e($nivel); ?> h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between gap-2 mb-2">
                                <div>
                                    <h2 class="h5 mb-0"><?php echo e($u['nome']); ?></h2>
                                    <div class="small text-muted">
                                        <?php echo e($tiposLabel[$u['tipo_usuario']] ?? $u['tipo_usuario']); ?>
                                        · <?php echo e($u['email']); ?>
                                        <?php if (!empty($u['telefone'])): ?>
                                            · <?php echo e($u['telefone']); ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <span class="badge bg-<?php echo e($nivel); ?>">
                                    <?php echo e(kyc_estado_label($row['estado_kyc'])); ?>
                                </span>
                            </div>

                            <p class="tm-doc-missing mb-2">
                                <strong>Falta:</strong> <?php echo e($faltamTxt); ?>
                            </p>

                            <ul class="list-unstyled small mb-3">
                                <li>
                                    <i class="bi bi-calendar3"></i>
                                    Conta há <?php echo (int)($row['dias_sem_docs'] ?? 0); ?> dia(s)
                                </li>
                                <li>
                                    <i class="bi bi-megaphone"></i>
                                    Advertências: <strong><?php echo (int)$row['advertencias']; ?></strong>
                                </li>
                                <?php if (!empty($row['prazo'])): ?>
                                    <li class="<?php echo !empty($row['prazo_expirado']) ? 'text-danger fw-semibold' : ''; ?>">
                                        <i class="bi bi-hourglass-split"></i>
                                        Prazo:
                                        <?php echo date('d/m/Y', strtotime($row['prazo'])); ?>
                                        <?php if (!empty($row['prazo_expirado'])): ?>
                                            — EXPIRADO
                                        <?php elseif ($row['dias_prazo'] !== null): ?>
                                            (<?php echo (int)$row['dias_prazo']; ?> dia(s) restantes)
                                        <?php endif; ?>
                                    </li>
                                <?php else: ?>
                                    <li class="text-muted"><i class="bi bi-hourglass"></i> Ainda sem prazo de advertência</li>
                                <?php endif; ?>
                            </ul>

                            <div class="d-flex flex-wrap gap-2 mb-2">
                                <button type="button" class="btn btn-sm btn-warning"
                                        data-bs-toggle="modal" data-bs-target="#modalAdvertir<?php echo $uid; ?>">
                                    <i class="bi bi-megaphone"></i> Advertir
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-dark"
                                        data-bs-toggle="modal" data-bs-target="#modalBloquear<?php echo $uid; ?>">
                                    <i class="bi bi-lock"></i> Bloquear
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-danger"
                                        data-bs-toggle="modal" data-bs-target="#modalRemover<?php echo $uid; ?>"
                                    <?php if (empty($row['pode_remover'])): ?>
                                        title="Recomendado após advertência ou prazo"
                                    <?php endif; ?>>
                                    <i class="bi bi-person-x"></i> Remover
                                </button>
                                <a class="btn btn-sm btn-outline-primary"
                                   href="verificar-documentos.php?usuario_id=<?php echo $uid; ?>">
                                    Docs
                                </a>
                                <a class="btn btn-sm btn-outline-secondary"
                                   href="ver-usuario.php?id=<?php echo $uid; ?>">
                                    Perfil
                                </a>
                            </div>

                            <?php if ($hist): ?>
                                <details class="small">
                                    <summary>Histórico de advertências (<?php echo count($hist); ?>)</summary>
                                    <ul class="mt-2 mb-0">
                                        <?php foreach ($hist as $h): ?>
                                            <li class="mb-1">
                                                <?php echo date('d/m/Y H:i', strtotime($h['criada_em'])); ?>
                                                — prazo <?php echo $h['prazo_ate'] ? date('d/m/Y', strtotime($h['prazo_ate'])) : '—'; ?>
                                                <br><span class="text-muted"><?php echo e(mb_strimwidth($h['mensagem'], 0, 120, '…')); ?></span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </details>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Modal Advertir -->
                <div class="modal fade" id="modalAdvertir<?php echo $uid; ?>" tabindex="-1">
                    <div class="modal-dialog">
                        <form method="post" class="modal-content">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="acao" value="advertir">
                            <input type="hidden" name="usuario_id" value="<?php echo $uid; ?>">
                            <div class="modal-header">
                                <h5 class="modal-title">Advertir <?php echo e($u['nome']); ?></h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <p class="small text-muted">
                                    O utilizador recebe notificação e fica com prazo para regularizar.
                                    Se ultrapassar o prazo, pode bloquear ou remover a conta.
                                </p>
                                <div class="mb-3">
                                    <label class="form-label">Mensagem</label>
                                    <textarea name="mensagem" class="form-control" rows="4"
                                              placeholder="Deixe em branco para mensagem padrão…">A sua conta está irregular: faltam documentos obrigatórios (<?php echo e($faltamTxt); ?>). Regularize em «Verificação da conta». Caso contrário, a conta poderá ser bloqueada ou removida.</textarea>
                                </div>
                                <div class="mb-0">
                                    <label class="form-label">Prazo (dias)</label>
                                    <input type="number" name="dias_prazo" class="form-control" min="1" max="60"
                                           value="<?php echo (int)KYC_DIAS_APOS_ADVERTENCIA; ?>">
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                <button type="submit" class="btn btn-warning">Enviar advertência</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Modal Bloquear -->
                <div class="modal fade" id="modalBloquear<?php echo $uid; ?>" tabindex="-1">
                    <div class="modal-dialog">
                        <form method="post" class="modal-content">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="acao" value="bloquear">
                            <input type="hidden" name="usuario_id" value="<?php echo $uid; ?>">
                            <div class="modal-header">
                                <h5 class="modal-title">Bloquear conta</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <p>O utilizador <strong><?php echo e($u['nome']); ?></strong> deixa de poder entrar.</p>
                                <label class="form-label">Motivo (opcional)</label>
                                <textarea name="motivo" class="form-control" rows="3"
                                          placeholder="Documentação irregular / prazo expirado…"></textarea>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                <button type="submit" class="btn btn-dark">Bloquear</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Modal Remover -->
                <div class="modal fade" id="modalRemover<?php echo $uid; ?>" tabindex="-1">
                    <div class="modal-dialog">
                        <form method="post" class="modal-content">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="acao" value="remover">
                            <input type="hidden" name="usuario_id" value="<?php echo $uid; ?>">
                            <div class="modal-header bg-danger text-white">
                                <h5 class="modal-title">Remover conta</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <p class="mb-2">
                                    Desactiva permanentemente a conta de <strong><?php echo e($u['nome']); ?></strong>
                                    (status inactivo). O histórico fica no sistema.
                                </p>
                                <div class="mb-3">
                                    <label class="form-label">Motivo</label>
                                    <textarea name="motivo" class="form-control" rows="2"
                                              placeholder="Não regularizou documentação no prazo…"></textarea>
                                </div>
                                <div class="mb-0">
                                    <label class="form-label">Escreva <code>REMOVER</code> para confirmar</label>
                                    <input type="text" name="confirmar" class="form-control" required autocomplete="off">
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                <button type="submit" class="btn btn-danger">Remover conta</button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
