<?php
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');
include_once('../../includes/auth.php');
include_once('../../includes/missao-helpers.php');

require_role(['empresa'], '../login.php');

$status   = $_GET['status'] ?? 'todas';
if ($status === 'concluidas') {
    $status = 'concluida';
}
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 10;
$offset   = ($page - 1) * $per_page;
$missoes  = [];
$total_pages = 1;
$error    = null;

$temPropostas = true;
try {
    $conn->query('SELECT 1 FROM propostas LIMIT 1');
} catch (PDOException $e) {
    $temPropostas = false;
    error_log('missoes.php: tabela propostas indisponível — ' . $e->getMessage());
}

$subPropostas = $temPropostas
    ? "(SELECT COUNT(*) FROM propostas WHERE missao_id = m.id)"
    : '0';
$subAceitas = $temPropostas
    ? "(SELECT COUNT(*) FROM propostas WHERE missao_id = m.id AND status = 'aceita')"
    : '0';

try {
    // Stats gerais para o header
    $st = $conn->prepare(
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status = 'aberta' THEN 1 ELSE 0 END) AS abertas,
            SUM(CASE WHEN status IN ('em_andamento','em_transito','em_entrega','aceita') THEN 1 ELSE 0 END) AS em_curso,
            SUM(CASE WHEN status = 'concluida' THEN 1 ELSE 0 END) AS concluidas,
            SUM(CASE WHEN status = 'aguardando_confirmacao' THEN 1 ELSE 0 END) AS aguardando
         FROM missoes WHERE empresa_id = :eid"
    );
    $st->execute([':eid' => $_SESSION['user_id']]);
    $stats = $st->fetch(PDO::FETCH_ASSOC);

    // Query principal
    $where = "m.empresa_id = :empresa_id";
    $params = [':empresa_id' => $_SESSION['user_id']];

    if ($status !== 'todas') {
        if ($status === 'em_andamento') {
            $where .= " AND m.status IN ('aceita','em_andamento','em_transito','em_entrega')";
        } else {
            $where .= " AND m.status = :status";
            $params[':status'] = $status;
        }
    }

    $stmt = $conn->prepare(
        "SELECT m.*,
                {$subPropostas} AS total_propostas,
                {$subAceitas} AS propostas_aceitas,
                u.nome AS nome_caminhoneiro
         FROM missoes m
         LEFT JOIN usuarios u ON m.caminhoneiro_id = u.id
         WHERE $where
         ORDER BY m.data_criacao DESC
         LIMIT " . (int)$per_page . " OFFSET " . (int)$offset
    );
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, PDO::PARAM_STR);
    }
    $stmt->execute();
    $missoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $cnt = $conn->prepare("SELECT COUNT(*) FROM missoes m WHERE $where");
    foreach ($params as $k => $v) $cnt->bindValue($k, $v, PDO::PARAM_STR);
    $cnt->execute();
    $total_pages = max(1, (int)ceil($cnt->fetchColumn() / $per_page));

} catch (PDOException $e) {
    error_log('missoes.php [empresa_id=' . ($_SESSION['user_id'] ?? '?') . ']: ' . $e->getMessage());
    $error = 'Erro ao carregar missões. Por favor, tente novamente.';
    $stats = ['total'=>0,'abertas'=>0,'em_curso'=>0,'concluidas'=>0,'aguardando'=>0];
}

function missao_status(string $s, int $props = 0): array {
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
        'emergencia'             => ['Emergência',      'danger',    'bi-exclamation-triangle-fill'],
        default                  => [ucfirst($s),       'secondary', 'bi-circle'],
    };
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Minhas Missões — TrackMoz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <style>
        .stat-card { border: none; border-radius: 12px; transition: transform .15s, box-shadow .15s; }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0,0,0,.08); }
        .stat-icon { width: 48px; height: 48px; border-radius: 12px;
                     display: flex; align-items: center; justify-content: center; font-size: 1.3rem; }
        .filter-tabs .nav-link { border-radius: 8px; padding: 7px 14px;
                                  color: #555; font-weight: 500; font-size: .875rem; }
        .filter-tabs .nav-link.active { background: #0d6efd; color: #fff; }
        .filter-tabs .nav-link:not(.active):hover { background: #f0f4ff; }
        .missao-row { border-radius: 12px; border: 1px solid #e8ecf0;
                      transition: box-shadow .15s, border-color .15s; background: #fff; }
        .missao-row:hover { box-shadow: 0 4px 16px rgba(13,110,253,.1); border-color: #b8d0ff; }
        .rota-badge { font-size: .78rem; background: #f0f4ff; color: #3a5fc8;
                      border-radius: 20px; padding: 3px 10px; white-space: nowrap; }
        .propostas-pill { font-size: .75rem; }
        .action-btn { font-size: .8rem; padding: 5px 12px; border-radius: 8px; }
        @media (max-width: 576px) {
            .stat-card .stat-num { font-size: 1.4rem; }
            .missao-row { padding: .75rem !important; }
            .d-none-mobile { display: none !important; }
            .action-bar { flex-wrap: wrap; gap: 6px; }
        }
    </style>
</head>
<body>
<?php include_once('../../includes/menu.php'); ?>

<div class="container py-4">

    <!-- Header -->
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
        <div>
            <h4 class="mb-0 fw-bold">Minhas Missões</h4>
            <p class="text-muted mb-0 small">Gerencie e acompanhe todas as suas missões</p>
        </div>
        <a href="nova-missao.php" class="btn btn-primary">
            <i class="bi bi-plus-circle me-1"></i> Nova Missão
        </a>
    </div>

    <!-- Stats cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card stat-card p-3 shadow-sm">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon bg-primary bg-opacity-10">
                        <i class="bi bi-list-task text-primary"></i>
                    </div>
                    <div>
                        <div class="fw-bold fs-4"><?php echo (int)$stats['total']; ?></div>
                        <div class="small text-muted">Total</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card stat-card p-3 shadow-sm">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon bg-success bg-opacity-10">
                        <i class="bi bi-broadcast text-success"></i>
                    </div>
                    <div>
                        <div class="fw-bold fs-4"><?php echo (int)$stats['abertas']; ?></div>
                        <div class="small text-muted">Abertas</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card stat-card p-3 shadow-sm">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon bg-warning bg-opacity-10">
                        <i class="bi bi-truck text-warning"></i>
                    </div>
                    <div>
                        <div class="fw-bold fs-4"><?php echo (int)$stats['em_curso']; ?></div>
                        <div class="small text-muted">Em Curso</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card stat-card p-3 shadow-sm">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon bg-success bg-opacity-10">
                        <i class="bi bi-patch-check text-success"></i>
                    </div>
                    <div>
                        <div class="fw-bold fs-4"><?php echo (int)$stats['concluidas']; ?></div>
                        <div class="small text-muted">Concluídas</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filtros -->
    <div class="d-flex flex-wrap gap-2 mb-4 filter-tabs">
        <?php
        $filtros = [
            'todas'                 => ['Todas',       'bi-grid'],
            'aberta'                => ['Abertas',     'bi-broadcast'],
            'em_andamento'          => ['Em Curso',    'bi-truck'],
            'aguardando_confirmacao'=> ['Aguardando',  'bi-hourglass-split'],
            'concluida'             => ['Concluídas',  'bi-patch-check'],
            'cancelada'             => ['Canceladas',  'bi-x-circle'],
        ];
        foreach ($filtros as $key => [$label, $icon]):
        ?>
        <a href="?status=<?php echo $key; ?>"
           class="nav-link <?php echo $status === $key ? 'active' : ''; ?>">
            <i class="bi <?php echo $icon; ?> me-1"></i><?php echo $label; ?>
        </a>
        <?php endforeach; ?>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <!-- Lista de missões -->
    <?php if (empty($missoes)): ?>
        <div class="text-center py-5">
            <i class="bi bi-inbox" style="font-size:3.5rem;color:#ccc"></i>
            <h5 class="mt-3 text-muted">Nenhuma missão encontrada</h5>
            <p class="text-muted small">Não existem missões na categoria seleccionada.</p>
            <?php if ($status !== 'todas'): ?>
                <a href="?status=todas" class="btn btn-outline-primary">Ver todas</a>
            <?php else: ?>
                <a href="nova-missao.php" class="btn btn-primary">
                    <i class="bi bi-plus-circle me-1"></i>Criar Missão
                </a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="d-flex flex-column gap-3">
            <?php foreach ($missoes as $m):
                [$slabel, $sclass, $sicon] = missao_status((string)$m['status'], (int)$m['total_propostas']);
                $ativo = in_array($m['status'], ['aceita','em_andamento','em_transito','em_entrega','aguardando_confirmacao','emergencia']);
            ?>
            <div class="missao-row p-3 p-md-4">
                <div class="d-flex flex-wrap align-items-start gap-3">

                    <!-- Ícone de status -->
                    <div class="d-none d-md-flex align-items-center justify-content-center rounded-3
                                bg-<?php echo $sclass; ?> bg-opacity-10"
                         style="width:48px;height:48px;flex-shrink:0">
                        <i class="bi <?php echo $sicon; ?> text-<?php echo $sclass; ?> fs-5"></i>
                    </div>

                    <!-- Info principal -->
                    <div class="flex-fill min-w-0">
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                            <h6 class="mb-0 fw-semibold text-truncate" style="max-width:300px">
                                <?php echo htmlspecialchars($m['titulo']); ?>
                            </h6>
                            <span class="badge bg-<?php echo $sclass; ?>">
                                <i class="bi <?php echo $sicon; ?> me-1"></i><?php echo $slabel; ?>
                            </span>
                            <?php if ($m['status'] === 'emergencia'): ?>
                                <span class="badge bg-danger"><i class="bi bi-exclamation-triangle-fill"></i></span>
                            <?php endif; ?>
                        </div>

                        <div class="d-flex flex-wrap align-items-center gap-3 text-muted small">
                            <span class="rota-badge">
                                <i class="bi bi-arrow-right me-1"></i>
                                <?php echo htmlspecialchars($m['origem']); ?> → <?php echo htmlspecialchars($m['destino']); ?>
                            </span>
                            <span><i class="bi bi-truck me-1"></i><?php echo htmlspecialchars($m['tipo_veiculo'] ?? ''); ?></span>
                            <span class="d-none-mobile">
                                <i class="bi bi-calendar me-1"></i><?php echo date('d/m/Y', strtotime($m['data_criacao'])); ?>
                            </span>
                            <?php if ($m['valor']): ?>
                                <span class="fw-semibold text-dark">
                                    <?php echo number_format((float)$m['valor'], 0, ',', '.'); ?> MT
                                </span>
                            <?php endif; ?>
                        </div>

                        <?php if ($m['nome_caminhoneiro']): ?>
                            <div class="mt-1 small text-muted">
                                <i class="bi bi-person-fill me-1 text-primary"></i>
                                <?php echo htmlspecialchars($m['nome_caminhoneiro']); ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Propostas + acções -->
                    <div class="d-flex flex-wrap align-items-center gap-2 action-bar ms-auto">
                        <?php if ((int)$m['total_propostas'] > 0): ?>
                            <span class="badge bg-info propostas-pill">
                                <i class="bi bi-send me-1"></i><?php echo $m['total_propostas']; ?> proposta<?php echo $m['total_propostas'] > 1 ? 's' : ''; ?>
                            </span>
                        <?php endif; ?>

                        <?php if ($ativo): ?>
                            <a href="rastrear-missao.php?id=<?php echo (int)$m['id']; ?>"
                               class="btn btn-sm btn-primary action-btn">
                                <i class="bi bi-geo-alt-fill me-1"></i>Rastrear
                            </a>
                        <?php endif; ?>

                        <a href="detalhes-missao.php?id=<?php echo (int)$m['id']; ?>"
                           class="btn btn-sm btn-outline-primary action-btn">
                            <i class="bi bi-eye me-1"></i>Ver
                        </a>
                        <?php if (empty($m['caminhoneiro_id']) && empty($m['motorista_id'])
                            && !in_array($m['status'], ['concluida','cancelada','em_transito','em_entrega','aguardando_confirmacao','emergencia','emergencia_reportada'], true)): ?>
                            <a href="apagar-missao.php?id=<?php echo (int)$m['id']; ?>"
                               class="btn btn-sm btn-outline-danger action-btn" title="Apagar / retirar do ar">
                                <i class="bi bi-trash"></i>
                            </a>
                        <?php endif; ?>

                        <!-- Documentos dropdown -->
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-secondary action-btn dropdown-toggle"
                                    type="button" data-bs-toggle="dropdown">
                                <i class="bi bi-file-earmark"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                <li><a class="dropdown-item" target="_blank"
                                       href="documentos/missao-registo.php?id=<?php echo (int)$m['id']; ?>">
                                    <i class="bi bi-file-text me-2"></i>Registo da Missão
                                </a></li>
                                <?php if ((int)$m['propostas_aceitas'] > 0 || $m['status'] !== 'aberta'): ?>
                                    <li><a class="dropdown-item" target="_blank"
                                           href="documentos/contrato-transporte.php?missao=<?php echo (int)$m['id']; ?>">
                                        <i class="bi bi-file-earmark-check me-2"></i>Contrato
                                    </a></li>
                                    <li><a class="dropdown-item" target="_blank"
                                           href="documentos/ordem-transporte.php?id=<?php echo (int)$m['id']; ?>">
                                        <i class="bi bi-file-earmark-arrow-up me-2"></i>Ordem de Transporte
                                    </a></li>
                                <?php endif; ?>
                                <?php if ($m['status'] === 'concluida'): ?>
                                    <li><a class="dropdown-item" target="_blank"
                                           href="documentos/comprovativo-conclusao.php?id=<?php echo (int)$m['id']; ?>">
                                        <i class="bi bi-file-earmark-check2 me-2"></i>Comprovativo
                                    </a></li>
                                    <li><a class="dropdown-item" target="_blank"
                                           href="documentos/fatura.php?missao=<?php echo (int)$m['id']; ?>">
                                        <i class="bi bi-receipt me-2"></i>Factura
                                    </a></li>
                                    <li><a class="dropdown-item" target="_blank"
                                           href="documentos/recibo.php?missao=<?php echo (int)$m['id']; ?>">
                                        <i class="bi bi-cash-coin me-2"></i>Recibo
                                    </a></li>
                                <?php endif; ?>
                                <li><a class="dropdown-item" target="_blank"
                                       href="documentos/termo-responsabilidade.php?missao=<?php echo (int)$m['id']; ?>">
                                    <i class="bi bi-shield-check me-2"></i>Termo Responsabilidade
                                </a></li>
                                <li><a class="dropdown-item" target="_blank"
                                       href="documentos/relatorio-incidente.php?missao=<?php echo (int)$m['id']; ?>">
                                    <i class="bi bi-exclamation-octagon me-2"></i>Relatório Incidente
                                </a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Paginação -->
    <?php if ($total_pages > 1): ?>
        <nav class="mt-4">
            <ul class="pagination justify-content-center">
                <?php if ($page > 1): ?>
                    <li class="page-item">
                        <a class="page-link" href="?status=<?php echo $status; ?>&page=<?php echo $page-1; ?>">
                            <i class="bi bi-chevron-left"></i>
                        </a>
                    </li>
                <?php endif; ?>
                <?php for ($i = max(1,$page-2); $i <= min($total_pages,$page+2); $i++): ?>
                    <li class="page-item <?php echo $i===$page?'active':''; ?>">
                        <a class="page-link" href="?status=<?php echo $status; ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
                    </li>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?>
                    <li class="page-item">
                        <a class="page-link" href="?status=<?php echo $status; ?>&page=<?php echo $page+1; ?>">
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
        </nav>
    <?php endif; ?>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
