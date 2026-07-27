<?php
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');
include_once('../../includes/auth.php');

require_role(['transportador'], '../login.php');

$status = isset($_GET['status']) ? $_GET['status'] : 'ativas';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

$missoes = [];
$total_pages = 1;
$error = null;

try {
    $where = "m.transportador_id = :transportador_id";
    $params = [':transportador_id' => (int)$_SESSION['user_id']];

    if ($status === 'ativas') {
        $where .= " AND m.status IN ('aceita','em_andamento','em_transito','em_entrega','aguardando_confirmacao','delegada')";
    } elseif ($status === 'recebidas') {
        $where .= " AND m.status = 'aguardando_aceitacao_transportadora'";
    } elseif ($status === 'concluidas') {
        $where .= " AND m.status = 'concluida'";
    } elseif ($status === 'canceladas') {
        $where .= " AND m.status = 'cancelada'";
    }

    $sql = "SELECT m.*, pe.nome_empresa
            FROM missoes m
            JOIN usuarios u ON m.empresa_id = u.id
            LEFT JOIN perfil_empresa pe ON u.id = pe.usuario_id
            WHERE $where
            ORDER BY m.data_criacao DESC
            LIMIT :limit OFFSET :offset";

    $stmt = $conn->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, PDO::PARAM_INT);
    }
    $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $missoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $countSql = "SELECT COUNT(*) FROM missoes m WHERE $where";
    $stmt = $conn->prepare($countSql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, PDO::PARAM_INT);
    }
    $stmt->execute();
    $total = (int)$stmt->fetchColumn();
    $total_pages = max(1, (int)ceil($total / $per_page));

} catch (PDOException $e) {
    error_log('Erro ao buscar missões do transportador: ' . $e->getMessage());
    $error = 'Erro ao carregar missões. Por favor, tente novamente.';
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Minhas Missões - Transportador - TrackMoz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
</head>
<body>
    <?php include_once('../../includes/menu.php'); ?>

    <div class="container mt-4">
        <div class="row mb-4">
            <div class="col-md-8">
                <h2>Minhas Missões</h2>
                <p class="text-muted">Acompanhe as missões assumidas pela sua transportadora</p>
            </div>
            <div class="col-md-4">
                <div class="btn-group w-100">
                    <a href="?status=ativas" class="btn btn-<?php echo $status === 'ativas' ? 'primary' : 'outline-primary'; ?>">Ativas</a>
                    <a href="?status=recebidas" class="btn btn-<?php echo $status === 'recebidas' ? 'primary' : 'outline-primary'; ?>">Recebidas</a>
                    <a href="?status=concluidas" class="btn btn-<?php echo $status === 'concluidas' ? 'primary' : 'outline-primary'; ?>">Concluídas</a>
                    <a href="?status=canceladas" class="btn btn-<?php echo $status === 'canceladas' ? 'primary' : 'outline-primary'; ?>">Canceladas</a>
                </div>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="row">
            <?php if (empty($missoes)): ?>
                <div class="col-12">
                    <div class="alert alert-info text-center">
                        <h4>Nenhuma missão encontrada</h4>
                        <p>Não existem missões na categoria selecionada.</p>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($missoes as $missao): ?>
                    <div class="col-md-6 mb-4">
                        <div class="card h-100 fade-in">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-3">
                                    <div>
                                        <h5 class="card-title mb-1"><?php echo htmlspecialchars($missao['titulo'] ?? ''); ?></h5>
                                        <p class="text-muted mb-0"><i class="bi bi-building"></i> <?php echo htmlspecialchars($missao['nome_empresa'] ?? ''); ?></p>
                                    </div>
                                    <?php
                                        $status_label = (string)($missao['status'] ?? '');
                                        $status_class = 'secondary';
                                        switch ($status_label) {
                                            case 'aceita': $status_label = 'Aceita'; $status_class = 'success'; break;
                                            case 'em_andamento': $status_label = 'Em Andamento'; $status_class = 'warning'; break;
                                            case 'em_transito': $status_label = 'Em Trânsito'; $status_class = 'primary'; break;
                                            case 'em_entrega': $status_label = 'Em Entrega'; $status_class = 'info'; break;
                                            case 'aguardando_confirmacao': $status_label = 'Aguardando Confirmação'; $status_class = 'secondary'; break;
                                            case 'aguardando_aceitacao_transportadora': $status_label = 'Recebida'; $status_class = 'info'; break;
                                            case 'concluida': $status_label = 'Concluída'; $status_class = 'success'; break;
                                            case 'cancelada': $status_label = 'Cancelada'; $status_class = 'danger'; break;
                                            default:
                                                $status_label = ucfirst(str_replace('_', ' ', $status_label));
                                                $status_class = 'secondary';
                                        }
                                    ?>
                                    <span class="badge bg-<?php echo $status_class; ?>"><?php echo htmlspecialchars($status_label); ?></span>
                                </div>

                                <div class="mb-3">
                                    <div class="d-flex align-items-center mb-2">
                                        <i class="bi bi-geo-alt text-primary me-2"></i>
                                        <span><?php echo htmlspecialchars($missao['origem'] ?? ''); ?> → <?php echo htmlspecialchars($missao['destino'] ?? ''); ?></span>
                                    </div>
                                    <div class="d-flex align-items-center mb-2">
                                        <i class="bi bi-truck text-primary me-2"></i>
                                        <span><?php echo htmlspecialchars($missao['tipo_veiculo'] ?? ''); ?></span>
                                    </div>
                                    <div class="d-flex align-items-center">
                                        <i class="bi bi-currency-dollar text-primary me-2"></i>
                                        <span>Valor: <?php echo number_format((float)($missao['valor'] ?? 0), 2, ',', '.'); ?> MT</span>
                                    </div>
                                </div>

                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted"><i class="bi bi-clock"></i> <?php echo !empty($missao['data_criacao']) ? date('d/m/Y', strtotime($missao['data_criacao'])) : ''; ?></small>
                                    <a href="<?php echo BASE_URL; ?>/pages/transportador/detalhes-missao.php?id=<?php echo (int)$missao['id']; ?>" class="btn btn-outline-primary">
                                        <i class="bi bi-eye"></i> Ver Detalhes
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php if ($total_pages > 1): ?>
            <nav aria-label="Navegação de páginas" class="mt-4">
                <ul class="pagination justify-content-center">
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                            <a class="page-link" href="?status=<?php echo urlencode($status); ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?php echo BASE_URL; ?>/assets/js/main.js"></script>
</body>
</html>
