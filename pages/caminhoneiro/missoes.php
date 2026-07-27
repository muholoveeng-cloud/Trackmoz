<?php
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');
include_once('../../includes/auth.php');
include_once('../../includes/motorista-regras.php');
include_once('../../includes/helpers.php');

require_role(['caminhoneiro'], '../login.php');

$status = isset($_GET['status']) ? $_GET['status'] : 'aberta';
if ($status === 'andamento') {
    $status = 'em_andamento';
}
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

$missoes = [];
$total_pages = 0;
$error = null;
$caminhoneiro_id = (int)$_SESSION['user_id'];

$statusOperacionais = missoes_status_operacionais_ativos();
$placeholdersOp = implode(',', array_fill(0, count($statusOperacionais), '?'));

try {
    if ($status === 'em_andamento') {
        $sql = "SELECT m.*, pe.nome_empresa, u.telefone as telefone_empresa,
                (SELECT COUNT(*) FROM propostas WHERE missao_id = m.id) as total_propostas
                FROM missoes m
                JOIN usuarios u ON m.empresa_id = u.id
                LEFT JOIN perfil_empresa pe ON u.id = pe.usuario_id
                WHERE m.caminhoneiro_id = ?
                AND m.status IN ({$placeholdersOp})
                ORDER BY m.ultima_atualizacao DESC
                LIMIT ? OFFSET ?";
        $params = array_merge([$caminhoneiro_id], $statusOperacionais, [$per_page, $offset]);
        $stmt = $conn->prepare($sql);
        foreach ($params as $i => $val) {
            $stmt->bindValue($i + 1, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
    } elseif ($status === 'agendada') {
        $sql = "SELECT m.*, pe.nome_empresa, u.telefone as telefone_empresa,
                (SELECT COUNT(*) FROM propostas WHERE missao_id = m.id) as total_propostas
                FROM missoes m
                JOIN usuarios u ON m.empresa_id = u.id
                LEFT JOIN perfil_empresa pe ON u.id = pe.usuario_id
                WHERE m.caminhoneiro_id = ?
                AND m.status = 'aceita'
                ORDER BY m.prazo_entrega ASC, m.data_criacao DESC
                LIMIT ? OFFSET ?";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(1, $caminhoneiro_id, PDO::PARAM_INT);
        $stmt->bindValue(2, $per_page, PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, PDO::PARAM_INT);
    } else {
        $sql = "SELECT m.*, pe.nome_empresa, u.telefone as telefone_empresa,
                (SELECT COUNT(*) FROM propostas WHERE missao_id = m.id) as total_propostas
                FROM missoes m
                JOIN usuarios u ON m.empresa_id = u.id
                LEFT JOIN perfil_empresa pe ON u.id = pe.usuario_id
                WHERE m.status = ?
                ORDER BY m.data_criacao DESC
                LIMIT ? OFFSET ?";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(1, $status);
        $stmt->bindValue(2, $per_page, PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, PDO::PARAM_INT);
    }

    $stmt->execute();
    $missoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($status === 'em_andamento') {
        $sqlCount = "SELECT COUNT(*) FROM missoes WHERE caminhoneiro_id = ? AND status IN ({$placeholdersOp})";
        $stmt = $conn->prepare($sqlCount);
        $stmt->execute(array_merge([$caminhoneiro_id], $statusOperacionais));
    } elseif ($status === 'agendada') {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM missoes WHERE caminhoneiro_id = ? AND status = 'aceita'");
        $stmt->execute([$caminhoneiro_id]);
    } else {
        $stmt = $conn->prepare('SELECT COUNT(*) FROM missoes WHERE status = ?');
        $stmt->execute([$status]);
    }
    $total_missoes = (int)$stmt->fetchColumn();
    $total_pages = (int)ceil($total_missoes / $per_page);

    $temMissaoActiva = motorista_tem_missao_ativa($conn, $caminhoneiro_id);
} catch (PDOException $e) {
    error_log('Erro ao buscar missões: ' . $e->getMessage());
    $error = 'Erro ao carregar missões. Por favor, tente novamente.';
    $temMissaoActiva = false;
}

$tituloPagina = match ($status) {
    'aberta'        => 'Disponíveis',
    'em_andamento'  => 'Em Execução',
    'agendada'      => 'Agendadas',
    default         => ucfirst($status),
};
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Missões — TrackMoz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
</head>
<body>
    <?php include_once('../../includes/menu.php'); ?>

    <div class="container mt-4">
        <div class="row mb-4">
            <div class="col-md-8">
                <h2>Missões <?php echo htmlspecialchars($tituloPagina); ?></h2>
                <p class="text-muted">Encontre oportunidades ou acompanhe as suas missões</p>
            </div>
            <div class="col-md-4">
                <div class="btn-group w-100 flex-wrap">
                    <a href="?status=aberta" class="btn btn-sm btn-<?php echo $status === 'aberta' ? 'primary' : 'outline-primary'; ?>">Disponíveis</a>
                    <a href="?status=agendada" class="btn btn-sm btn-<?php echo $status === 'agendada' ? 'primary' : 'outline-primary'; ?>">Agendadas</a>
                    <a href="?status=andamento" class="btn btn-sm btn-<?php echo $status === 'em_andamento' ? 'primary' : 'outline-primary'; ?>">Em Execução</a>
                </div>
            </div>
        </div>

        <?php if ($temMissaoActiva && $status === 'agendada'): ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle me-1"></i>
                Tem missões agendadas. Só pode iniciar a próxima após concluir a missão em execução.
            </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if (empty($missoes)): ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-inbox" style="font-size:3rem"></i>
                <p class="mt-2">Nenhuma missão nesta categoria.</p>
            </div>
        <?php else: ?>
        <div class="row">
            <?php foreach ($missoes as $missao):
                $st = $missao['status'] ?? '';
                $labelOp = status_operacional_missao_label($missao);
                missao_garantir_colunas_operacionais($conn);
                $modoMissao = motorista_pode_modo_conducao($conn, $caminhoneiro_id, $missao);
                $mostrarModo = $modoMissao['ok'] || in_array($st, ['aceita', 'em_andamento', 'em_transito', 'em_entrega', 'aguardando_confirmacao'], true);
            ?>
                <div class="col-md-6 mb-4">
                    <div class="card h-100 fade-in">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div>
                                    <h5 class="card-title mb-1"><?php echo htmlspecialchars($missao['titulo'] ?? ''); ?></h5>
                                    <p class="text-muted mb-0">
                                        <i class="bi bi-building"></i> <?php echo htmlspecialchars($missao['nome_empresa'] ?? ''); ?>
                                    </p>
                                </div>
                                <span class="badge bg-<?php echo status_missao_badge($st); ?>">
                                    <?php echo htmlspecialchars($labelOp); ?>
                                </span>
                            </div>

                            <div class="mb-3">
                                <div class="d-flex align-items-center mb-2">
                                    <i class="bi bi-geo-alt text-primary me-2"></i>
                                    <span><?php echo htmlspecialchars($missao['origem'] ?? ''); ?> → <?php echo htmlspecialchars($missao['destino'] ?? ''); ?></span>
                                </div>
                                <div class="d-flex align-items-center">
                                    <i class="bi bi-currency-dollar text-primary me-2"></i>
                                    <span><?php echo number_format($missao['valor'] ?? 0, 2, ',', '.'); ?> MT</span>
                                </div>
                            </div>

                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <small class="text-muted">
                                    <i class="bi bi-clock"></i>
                                    <?php echo isset($missao['data_criacao']) ? date('d/m/Y', strtotime($missao['data_criacao'])) : ''; ?>
                                </small>
                                <div class="d-flex gap-2">
                                    <?php if ($st === 'aberta'): ?>
                                        <a href="enviar-proposta.php?id=<?php echo (int)$missao['id']; ?>" class="btn btn-primary btn-sm">
                                            <i class="bi bi-send"></i> Proposta
                                        </a>
                                    <?php else: ?>
                                        <a href="detalhes-missao.php?id=<?php echo (int)$missao['id']; ?>" class="btn btn-outline-secondary btn-sm">
                                            <i class="bi bi-eye"></i> Detalhes
                                        </a>
                                        <?php if ($mostrarModo && $modoMissao['ok']): ?>
                                        <a href="modo-direcao.php?missao_id=<?php echo (int)$missao['id']; ?>" class="btn btn-primary btn-sm">
                                            <i class="bi bi-truck"></i> <?php echo htmlspecialchars(botao_modo_conducao_label($missao)); ?>
                                        </a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($total_pages > 1): ?>
            <nav aria-label="Paginação" class="mt-4">
                <ul class="pagination justify-content-center">
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                            <a class="page-link" href="?status=<?php echo urlencode($status === 'em_andamento' ? 'andamento' : $status); ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
