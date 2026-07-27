<?php
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');
include_once('../../includes/auth.php');

require_role(['caminhoneiro', 'transportador'], '../login.php');

$user_id = (int)$_SESSION['user_id'];
$msg_ok  = $msg_err = '';

// Cancelar (retirar) uma proposta
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancelar_proposta'], $_POST['proposta_id'])) {
    $pid = (int)$_POST['proposta_id'];
    $stmt = $conn->prepare(
        "SELECT id FROM propostas WHERE id = :id AND caminhoneiro_id = :uid AND status = 'pendente'"
    );
    $stmt->execute([':id' => $pid, ':uid' => $user_id]);
    if ($stmt->rowCount()) {
        $conn->prepare("DELETE FROM propostas WHERE id = :id")->execute([':id' => $pid]);
        $msg_ok = 'Proposta retirada com sucesso.';
    } else {
        $msg_err = 'Não foi possível retirar a proposta (já pode ter sido aceite ou rejeitada).';
    }
}

// Buscar propostas do utilizador
$stmt = $conn->prepare(
    "SELECT p.id, p.status, p.valor, p.observacoes, p.data_criacao,
            m.id AS missao_id, m.titulo AS missao_titulo,
            m.origem, m.destino, m.prazo_entrega, m.empresa_id,
            u.nome AS empresa_nome
     FROM propostas p
     JOIN missoes m ON p.missao_id = m.id
     JOIN usuarios u ON m.empresa_id = u.id
     WHERE p.caminhoneiro_id = :uid
     ORDER BY p.data_criacao DESC"
);
$stmt->execute([':uid' => $user_id]);
$propostas = $stmt->fetchAll(PDO::FETCH_ASSOC);

$counts = [
    'todas'      => count($propostas),
    'pendente'   => count(array_filter($propostas, fn($p) => $p['status'] === 'pendente')),
    'aceita'     => count(array_filter($propostas, fn($p) => $p['status'] === 'aceita')),
    'rejeitada'  => count(array_filter($propostas, fn($p) => $p['status'] === 'rejeitada')),
];

$filtro = $_GET['filtro'] ?? 'todas';
if ($filtro !== 'todas') {
    $propostas_view = array_values(array_filter($propostas, fn($p) => $p['status'] === $filtro));
} else {
    $propostas_view = $propostas;
}

function status_proposta(string $s): array {
    return match($s) {
        'pendente'  => ['À espera de resposta', 'warning',   'bi-hourglass-split'],
        'aceita'    => ['Aceite! Missão atribuída', 'success', 'bi-check-circle-fill'],
        'rejeitada' => ['Não seleccionado',      'danger',   'bi-x-circle-fill'],
        default     => [ucfirst($s),             'secondary','bi-circle'],
    };
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Minhas Propostas — TrackMoz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <style>
        .stat-card  { border: none; border-radius: 12px; transition: transform .15s, box-shadow .15s; }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0,0,0,.08); }
        .stat-icon  { width: 44px; height: 44px; border-radius: 11px;
                      display: flex; align-items: center; justify-content: center; font-size: 1.2rem; }
        .filter-tabs .nav-link { border-radius: 8px; padding: 6px 14px;
                                  color: #555; font-weight: 500; font-size: .85rem; }
        .filter-tabs .nav-link.active { background: #0d6efd; color: #fff; }
        .filter-tabs .nav-link:not(.active):hover { background: #f0f4ff; }
        .prop-card  { border-radius: 12px; border: 1px solid #e8ecf0;
                      transition: box-shadow .15s, border-color .15s; }
        .prop-card:hover { box-shadow: 0 4px 16px rgba(13,110,253,.1); border-color: #b8d0ff; }
        .prop-card.status-aceita    { border-color: #28a745; background: #f0fff4; }
        .prop-card.status-rejeitada { border-color: #dc3545; background: #fff8f8; opacity: .85; }
        .rota-pill  { font-size: .75rem; background: #f0f4ff; color: #3a5fc8;
                      border-radius: 20px; padding: 3px 10px; }
        .info-row   { font-size: .82rem; color: #555; }
    </style>
</head>
<body>
<?php include_once('../../includes/menu.php'); ?>

<div class="container py-4">

    <!-- Header -->
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
        <div>
            <h4 class="mb-0 fw-bold">Minhas Propostas</h4>
            <p class="text-muted mb-0 small">Propostas que enviaste a contratantes</p>
        </div>
        <a href="missoes.php" class="btn btn-primary">
            <i class="bi bi-search me-1"></i>Ver Missões Disponíveis
        </a>
    </div>

    <?php if ($msg_ok):  ?>
        <div class="alert alert-success d-flex align-items-center gap-2">
            <i class="bi bi-check-circle-fill"></i><?php echo htmlspecialchars($msg_ok); ?>
        </div>
    <?php endif; ?>
    <?php if ($msg_err): ?>
        <div class="alert alert-danger d-flex align-items-center gap-2">
            <i class="bi bi-exclamation-triangle-fill"></i><?php echo htmlspecialchars($msg_err); ?>
        </div>
    <?php endif; ?>

    <!-- Estatísticas -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card stat-card p-3 shadow-sm">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon bg-primary bg-opacity-10">
                        <i class="bi bi-send text-primary"></i>
                    </div>
                    <div>
                        <div class="fw-bold fs-4"><?php echo $counts['todas']; ?></div>
                        <div class="small text-muted">Enviadas</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card stat-card p-3 shadow-sm">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon bg-warning bg-opacity-10">
                        <i class="bi bi-hourglass-split text-warning"></i>
                    </div>
                    <div>
                        <div class="fw-bold fs-4"><?php echo $counts['pendente']; ?></div>
                        <div class="small text-muted">Pendentes</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card stat-card p-3 shadow-sm">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon bg-success bg-opacity-10">
                        <i class="bi bi-check-circle text-success"></i>
                    </div>
                    <div>
                        <div class="fw-bold fs-4"><?php echo $counts['aceita']; ?></div>
                        <div class="small text-muted">Aceites</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card stat-card p-3 shadow-sm">
                <div class="d-flex align-items-center gap-3">
                    <div class="stat-icon bg-danger bg-opacity-10">
                        <i class="bi bi-x-circle text-danger"></i>
                    </div>
                    <div>
                        <div class="fw-bold fs-4"><?php echo $counts['rejeitada']; ?></div>
                        <div class="small text-muted">Rejeitadas</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filtros -->
    <div class="d-flex flex-wrap gap-2 mb-4 filter-tabs">
        <?php
        $filtros_nav = [
            'todas'     => ['Todas',      'bi-grid'],
            'pendente'  => ['Pendentes',  'bi-hourglass-split'],
            'aceita'    => ['Aceites',    'bi-check-circle'],
            'rejeitada' => ['Rejeitadas', 'bi-x-circle'],
        ];
        foreach ($filtros_nav as $key => [$label, $icon]):
        ?>
        <a href="?filtro=<?php echo $key; ?>"
           class="nav-link <?php echo $filtro === $key ? 'active' : ''; ?>">
            <i class="bi <?php echo $icon; ?> me-1"></i><?php echo $label; ?>
            <span class="ms-1 badge bg-<?php echo $filtro === $key ? 'white text-primary' : 'secondary'; ?> opacity-75">
                <?php echo $counts[$key]; ?>
            </span>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Lista de propostas -->
    <?php if (empty($propostas_view)): ?>
        <div class="text-center py-5">
            <i class="bi bi-inbox" style="font-size:3.5rem;color:#ccc"></i>
            <h5 class="mt-3 text-muted">Nenhuma proposta encontrada</h5>
            <?php if ($filtro !== 'todas'): ?>
                <a href="?filtro=todas" class="btn btn-outline-primary mt-2">Ver todas</a>
            <?php else: ?>
                <a href="missoes.php" class="btn btn-primary mt-2">
                    <i class="bi bi-search me-1"></i>Explorar Missões
                </a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="d-flex flex-column gap-3">
            <?php foreach ($propostas_view as $p):
                [$slabel, $sclass, $sicon] = status_proposta($p['status']);
                $pendente = $p['status'] === 'pendente';
                $aceita   = $p['status'] === 'aceita';
            ?>
            <div class="prop-card p-3 p-md-4 status-<?php echo $p['status']; ?>">
                <div class="d-flex flex-wrap align-items-start gap-3">

                    <!-- Ícone de estado -->
                    <div class="d-none d-md-flex align-items-center justify-content-center rounded-3
                                bg-<?php echo $sclass; ?> bg-opacity-10"
                         style="width:48px;height:48px;flex-shrink:0">
                        <i class="bi <?php echo $sicon; ?> text-<?php echo $sclass; ?> fs-5"></i>
                    </div>

                    <!-- Info principal -->
                    <div class="flex-fill min-w-0">
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                            <span class="fw-semibold text-truncate" style="max-width:280px">
                                <?php echo htmlspecialchars($p['missao_titulo']); ?>
                            </span>
                            <span class="badge bg-<?php echo $sclass; ?>">
                                <i class="bi <?php echo $sicon; ?> me-1"></i><?php echo $slabel; ?>
                            </span>
                        </div>

                        <div class="d-flex flex-wrap align-items-center gap-3 info-row">
                            <span class="rota-pill">
                                <i class="bi bi-arrow-right me-1"></i>
                                <?php echo htmlspecialchars($p['origem']); ?> → <?php echo htmlspecialchars($p['destino']); ?>
                            </span>
                            <span>
                                <i class="bi bi-building me-1 text-muted"></i><?php echo htmlspecialchars($p['empresa_nome']); ?>
                            </span>
                            <span>
                                <i class="bi bi-calendar me-1 text-muted"></i><?php echo date('d/m/Y', strtotime($p['data_criacao'])); ?>
                            </span>
                        </div>

                        <?php if ($p['observacoes']): ?>
                            <div class="mt-2 small text-muted fst-italic">
                                "<?php echo htmlspecialchars($p['observacoes']); ?>"
                            </div>
                        <?php endif; ?>

                        <?php if ($aceita): ?>
                            <div class="mt-2">
                                <span class="badge bg-success bg-opacity-75">
                                    <i class="bi bi-truck me-1"></i>A missão foi-te atribuída — podes iniciar!
                                </span>
                                <a href="detalhes-missao.php?id=<?php echo (int)$p['missao_id']; ?>"
                                   class="btn btn-sm btn-success ms-2">
                                    <i class="bi bi-arrow-right me-1"></i>Ver Missão
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Valor + acções -->
                    <div class="d-flex flex-column align-items-end gap-2 ms-auto text-end flex-shrink-0">
                        <div class="fw-bold text-success fs-5">
                            <?php echo number_format((float)$p['valor'], 0, ',', '.'); ?> MT
                        </div>

                        <div class="d-flex gap-2 flex-wrap justify-content-end">
                            <!-- Chat -->
                            <a href="<?php echo BASE_URL; ?>/pages/chat.php?user=<?php echo (int)$p['empresa_id']; ?>&missao=<?php echo (int)$p['missao_id']; ?>"
                               class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-chat me-1"></i>Chat
                            </a>

                            <!-- Retirar proposta (só se pendente) -->
                            <?php if ($pendente): ?>
                            <form method="POST" onsubmit="return confirm('Retirar esta proposta?')">
                                <input type="hidden" name="proposta_id" value="<?php echo (int)$p['id']; ?>">
                                <input type="hidden" name="cancelar_proposta" value="1">
                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                    <i class="bi bi-x-circle me-1"></i>Retirar
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
