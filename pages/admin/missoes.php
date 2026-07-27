<?php
session_start();
require_once '../../config/app.php';
require_once '../../config/database.php';

// Verificar se o usuário está logado e é um administrador
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 'admin') {
    header('Location: ../login.php');
    exit();
}

// Definir o status padrão para buscar (todos, abertas, em andamento, concluidas, canceladas)
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'todos';

// Consultar missões conforme filtro
$sql_where = "";
$params = [];

if ($status_filter === 'aberta') {
    $sql_where = "WHERE m.status = 'aberta'";
} elseif ($status_filter === 'em_andamento') {
    $sql_where = "WHERE m.status IN ('em_andamento', 'em_transito', 'em_entrega', 'aguardando_confirmacao')";
} elseif ($status_filter === 'concluida') {
    $sql_where = "WHERE m.status = 'concluida'";
} elseif ($status_filter === 'cancelada') {
    $sql_where = "WHERE m.status = 'cancelada'";
} elseif ($status_filter === 'emergencia') {
    $sql_where = "WHERE m.status = 'emergencia'";
}

$sql = "SELECT m.*,
            e.nome AS nome_empresa,
            CASE WHEN m.caminhoneiro_id IS NOT NULL THEN c.nome ELSE NULL END AS nome_caminhoneiro,
            m.origem,
            m.destino
        FROM missoes m
        LEFT JOIN usuarios e ON m.empresa_id = e.id
        LEFT JOIN usuarios c ON m.caminhoneiro_id = c.id
        $sql_where
        ORDER BY m.data_criacao DESC";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$missoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Contar totais por categoria
$stmt = $conn->query("SELECT COUNT(*) FROM missoes WHERE status = 'aberta'");
$total_abertas = $stmt->fetchColumn();

$stmt = $conn->query("SELECT COUNT(*) FROM missoes WHERE status IN ('em_andamento', 'em_transito', 'em_entrega', 'aguardando_confirmacao')");
$total_em_andamento = $stmt->fetchColumn();

$stmt = $conn->query("SELECT COUNT(*) FROM missoes WHERE status = 'concluida'");
$total_concluidas = $stmt->fetchColumn();

$stmt = $conn->query("SELECT COUNT(*) FROM missoes WHERE status = 'cancelada'");
$total_canceladas = $stmt->fetchColumn();

$stmt = $conn->query("SELECT COUNT(*) FROM missoes WHERE status = 'emergencia'");
$total_emergencias = $stmt->fetchColumn() ?? 0;
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciamento de Missões - TrackMoz Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin.css">
    <style>
        .filter-card {
            border-left: 4px solid;
        }
        .filter-aberta { border-left-color: #0d6efd; }
        .filter-em-andamento { border-left-color: #ffc107; }
        .filter-concluida { border-left-color: #198754; }
        .filter-cancelada { border-left-color: #dc3545; }
        .filter-emergencia { border-left-color: #dc3545; }
        .status-badge {
            padding: 0.5rem;
            border-radius: 4px;
        }
        .status-aberta { background-color: #cfe2ff; color: #084298; }
        .status-em-andamento { background-color: #fff3cd; color: #856404; }
        .status-em_transito { background-color: #d1ecf1; color: #0c5460; }
        .status-em_entrega { background-color: #d1ecf1; color: #0c5460; }
        .status-aguardando_confirmacao { background-color: #e2e3e5; color: #383d41; }
        .status-concluida { background-color: #d1e7dd; color: #0f5132; }
        .status-cancelada { background-color: #f8d7da; color: #842029; }
        .status-emergencia { background-color: #f8d7da; color: #842029; font-weight: bold; }
    </style>
</head>
<body>
    <?php include_once('../../includes/menu.php'); ?>

    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-2 admin-sidebar d-none d-md-block p-0">
                <div class="d-flex flex-column p-3">
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link" href="dashboard.php">
                                <i class="bi bi-speedometer2"></i> Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="usuarios.php">
                                <i class="bi bi-people"></i> Usuários
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="missoes.php">
                                <i class="bi bi-list-task"></i> Missões
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="relatorios.php">
                                <i class="bi bi-graph-up"></i> Relatórios
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="mensagens.php">
                                <i class="bi bi-chat-left"></i> Mensagens
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="avaliacoes.php">
                                <i class="bi bi-star"></i> Avaliações
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="configuracoes.php">
                                <i class="bi bi-gear"></i> Configurações
                            </a>
                        </li>
                    </ul>
                    
                    <hr class="text-white-50">
                    
                    <h6 class="text-white-50 px-3 mt-3 mb-2 text-uppercase">Sistema</h6>
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link" href="registros.php">
                                <i class="bi bi-journals"></i> Logs do Sistema
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="backup.php">
                                <i class="bi bi-cloud-arrow-down"></i> Backup
                            </a>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Content -->
            <main class="col-md-10 ms-sm-auto col-lg-10 px-md-4 py-4">
                <div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Gerenciamento de Missões</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#novaMissaoModal">
                                <i class="bi bi-plus-circle"></i> Nova Missão
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-file-earmark-excel"></i> Exportar
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Filtros -->
                <div class="row mb-4">
                    <div class="col-md-3 mb-3">
                        <div class="card filter-card filter-aberta h-100 <?php echo $status_filter === 'aberta' ? 'bg-primary bg-opacity-10' : ''; ?>">
                            <a href="?status=aberta" class="text-decoration-none">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="text-muted mb-1">Missões Abertas</h6>
                                            <h3 class="mb-0"><?php echo $total_abertas; ?></h3>
                                        </div>
                                        <div class="bg-primary bg-opacity-25 p-3 rounded">
                                            <i class="bi bi-clipboard-plus text-primary fs-4"></i>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="card filter-card filter-em-andamento h-100 <?php echo $status_filter === 'em_andamento' ? 'bg-warning bg-opacity-10' : ''; ?>">
                            <a href="?status=em_andamento" class="text-decoration-none">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="text-muted mb-1">Em Andamento</h6>
                                            <h3 class="mb-0"><?php echo $total_em_andamento; ?></h3>
                                        </div>
                                        <div class="bg-warning bg-opacity-25 p-3 rounded">
                                            <i class="bi bi-truck text-warning fs-4"></i>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="card filter-card filter-concluida h-100 <?php echo $status_filter === 'concluida' ? 'bg-success bg-opacity-10' : ''; ?>">
                            <a href="?status=concluida" class="text-decoration-none">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="text-muted mb-1">Concluídas</h6>
                                            <h3 class="mb-0"><?php echo $total_concluidas; ?></h3>
                                        </div>
                                        <div class="bg-success bg-opacity-25 p-3 rounded">
                                            <i class="bi bi-check-circle text-success fs-4"></i>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="card filter-card filter-cancelada h-100 <?php echo $status_filter === 'cancelada' ? 'bg-danger bg-opacity-10' : ''; ?>">
                            <a href="?status=cancelada" class="text-decoration-none">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="text-muted mb-1">Canceladas</h6>
                                            <h3 class="mb-0"><?php echo $total_canceladas; ?></h3>
                                        </div>
                                        <div class="bg-danger bg-opacity-25 p-3 rounded">
                                            <i class="bi bi-x-circle text-danger fs-4"></i>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="card filter-card filter-emergencia h-100 <?php echo $status_filter === 'emergencia' ? 'bg-danger bg-opacity-10' : ''; ?>">
                            <a href="?status=emergencia" class="text-decoration-none">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="text-muted mb-1">Emergências</h6>
                                            <h3 class="mb-0"><?php echo $total_emergencias; ?></h3>
                                        </div>
                                        <div class="bg-danger bg-opacity-25 p-3 rounded">
                                            <i class="bi bi-exclamation-triangle text-danger fs-4"></i>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Search Bar & Status Filters -->
                <div class="row mb-4">
                    <div class="col-md-6 mb-3">
                        <div class="input-group">
                            <input type="text" class="form-control" placeholder="Buscar missão..." id="searchInput">
                            <button class="btn btn-outline-secondary" type="button">
                                <i class="bi bi-search"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-md-6 mb-3 text-md-end">
                        <div class="btn-group">
                            <a href="?status=todos" class="btn btn-outline-secondary <?php echo $status_filter === 'todos' ? 'active' : ''; ?>">
                                Todas
                            </a>
                            <a href="?status=aberta" class="btn btn-outline-primary <?php echo $status_filter === 'aberta' ? 'active' : ''; ?>">
                                Abertas
                            </a>
                            <a href="?status=em_andamento" class="btn btn-outline-warning <?php echo $status_filter === 'em_andamento' ? 'active' : ''; ?>">
                                Em Andamento
                            </a>
                            <a href="?status=concluida" class="btn btn-outline-success <?php echo $status_filter === 'concluida' ? 'active' : ''; ?>">
                                Concluídas
                            </a>
                            <a href="?status=emergencia" class="btn btn-outline-danger <?php echo $status_filter === 'emergencia' ? 'active' : ''; ?>">
                                Emergências
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Missions Table -->
                <div class="card shadow-sm mb-4">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>ID</th>
                                        <th>Título</th>
                                        <th>Empresa</th>
                                        <th>Origem - Destino</th>
                                        <th>Caminhoneiro</th>
                                        <th>Valor (MZN)</th>
                                        <th>Status</th>
                                        <th>Data</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($missoes)): ?>
                                        <tr>
                                            <td colspan="9" class="text-center py-4">Nenhuma missão encontrada.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($missoes as $missao): ?>
                                            <tr>
                                                <td><?php echo $missao['id']; ?></td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="avatar me-2 bg-light rounded p-2">
                                                            <i class="bi bi-box text-secondary"></i>
                                                        </div>
                                                        <div>
                                                            <h6 class="mb-0"><?php echo htmlspecialchars($missao['titulo']); ?></h6>
                                                            <small class="text-muted"><?php
                                                                $detalhesCarga = array_filter([
                                                                    $missao['tipo_carga'] ?? null,
                                                                    isset($missao['peso_carga']) && $missao['peso_carga'] !== '' && $missao['peso_carga'] !== null
                                                                        ? $missao['peso_carga'] . ' kg' : null,
                                                                ]);
                                                                echo htmlspecialchars($detalhesCarga ? implode(' - ', $detalhesCarga) : '—');
                                                            ?></small>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td><?php echo htmlspecialchars($missao['nome_empresa'] ?? 'N/A'); ?></td>
                                                <td>
                                                    <?php 
                                                    $origem = $missao['origem'] ?? 'Não especificado';
                                                    $destino = $missao['destino'] ?? 'Não especificado';
                                                    echo htmlspecialchars($origem) . ' - ' . htmlspecialchars($destino); 
                                                    ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($missao['nome_caminhoneiro'] ?? 'Não atribuído'); ?></td>
                                                <td><?php echo number_format($missao['valor'], 2, ',', '.'); ?></td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $missao['status']; ?>">
                                                        <?php 
                                                            switch($missao['status']) {
                                                                case 'aberta': echo 'Aberta'; break;
                                                                case 'em_andamento': echo 'Em Andamento'; break;
                                                                case 'em_transito': echo 'Em Trânsito'; break;
                                                                case 'em_entrega': echo 'Em Entrega'; break;
                                                                case 'aguardando_confirmacao': echo 'Aguardando Confirmação'; break;
                                                                case 'concluida': echo 'Concluída'; break;
                                                                case 'cancelada': echo 'Cancelada'; break;
                                                                case 'emergencia': echo 'Emergência'; break;
                                                                default: echo ucfirst($missao['status']);
                                                            }
                                                        ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <small>
                                                        <i class="bi bi-calendar3"></i> <?php echo date('d/m/Y', strtotime($missao['data_criacao'])); ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <a href="ver-missao.php?id=<?php echo $missao['id']; ?>" class="btn btn-outline-secondary" title="Ver">
                                                            <i class="bi bi-eye"></i>
                                                        </a>
                                                        <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteModal<?php echo $missao['id']; ?>">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Pagination -->
                <nav aria-label="Page navigation">
                    <ul class="pagination justify-content-center">
                        <li class="page-item disabled">
                            <a class="page-link" href="#" tabindex="-1" aria-disabled="true">Anterior</a>
                        </li>
                        <li class="page-item active"><a class="page-link" href="#">1</a></li>
                        <li class="page-item"><a class="page-link" href="#">2</a></li>
                        <li class="page-item"><a class="page-link" href="#">3</a></li>
                        <li class="page-item">
                            <a class="page-link" href="#">Próxima</a>
                        </li>
                    </ul>
                </nav>
            </main>
        </div>
    </div>

    <!-- Modal Nova Missão -->
    <div class="modal fade" id="novaMissaoModal" tabindex="-1" aria-labelledby="novaMissaoModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="novaMissaoModalLabel">Nova Missão</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="formNovaMissao" action="<?php echo BASE_URL; ?>/pages/contratante/nova-missao.php" method="GET">
                        <div class="mb-3">
                            <label for="titulo" class="form-label">Título</label>
                            <input type="text" class="form-control" id="titulo" name="titulo" required>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="empresa_id" class="form-label">Empresa</label>
                                <select class="form-select" id="empresa_id" name="empresa_id" required>
                                    <option value="" selected disabled>Selecione a empresa</option>
                                    <!-- Aqui viriam as empresas do banco de dados -->
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="tipo_carga" class="form-label">Tipo de Carga</label>
                                <input type="text" class="form-control" id="tipo_carga" name="tipo_carga" required>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="origem" class="form-label">Local de Origem</label>
                                <input type="text" class="form-control" id="origem" name="origem" required>
                            </div>
                            <div class="col-md-6">
                                <label for="destino" class="form-label">Local de Destino</label>
                                <input type="text" class="form-control" id="destino" name="destino" required>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label for="peso_carga" class="form-label">Peso da Carga (kg)</label>
                                <input type="number" step="0.01" class="form-control" id="peso_carga" name="peso_carga" required>
                            </div>
                            <div class="col-md-4">
                                <label for="valor" class="form-label">Valor (MZN)</label>
                                <input type="number" step="0.01" class="form-control" id="valor" name="valor" required>
                            </div>
                            <div class="col-md-4">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status" required>
                                    <option value="aberta">Aberta</option>
                                    <option value="em_andamento">Em Andamento</option>
                                    <option value="concluida">Concluída</option>
                                    <option value="cancelada">Cancelada</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="descricao" class="form-label">Descrição</label>
                            <textarea class="form-control" id="descricao" name="descricao" rows="3"></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" form="formNovaMissao" class="btn btn-primary">Adicionar Missão</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Filtro de pesquisa
        document.getElementById('searchInput').addEventListener('keyup', function() {
            const searchValue = this.value.toLowerCase();
            const tableRows = document.querySelectorAll('tbody tr');
            
            tableRows.forEach(row => {
                const text = row.textContent.toLowerCase();
                if (text.includes(searchValue)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
    </script>
</body>
</html> 