<?php
session_start();
include_once('../../config/database.php');

// Verificar se o usuário está logado e é um administrador
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 'admin') {
    header('Location: ../login.php');
    exit();
}

// Definir o tipo padrão para buscar (todos, motoristas, empresas)
$filter_type = isset($_GET['tipo']) ? $_GET['tipo'] : 'todos';
$star_filter = isset($_GET['estrelas']) ? (int)$_GET['estrelas'] : 0;

// Consultar avaliações conforme filtro
$sql_where = [];
$params = [];

if ($filter_type === 'motorista') {
    $sql_where[] = "u_avaliado.tipo_usuario = 'caminhoneiro'";
} elseif ($filter_type === 'empresa') {
    $sql_where[] = "u_avaliado.tipo_usuario = 'empresa'";
}

if ($star_filter > 0) {
    $sql_where[] = "a.nota = :nota";
    $params[':nota'] = $star_filter;
}

$where_clause = !empty($sql_where) ? "WHERE " . implode(' AND ', $sql_where) : "";

$sql = "SELECT a.*,
            u_avaliador.nome AS nome_avaliador,
            u_avaliado.nome AS nome_avaliado,
            u_avaliado.tipo_usuario AS tipo_avaliado
        FROM avaliacoes a
        LEFT JOIN usuarios u_avaliador ON a.avaliador_id = u_avaliador.id
        LEFT JOIN usuarios u_avaliado ON a.avaliado_id = u_avaliado.id
        $where_clause
        ORDER BY a.id DESC";

$stmt = $conn->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$avaliacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Contar totais por categoria
$stmt = $conn->query("SELECT COUNT(*) FROM avaliacoes");
$total_avaliacoes = $stmt->fetchColumn();

$stmt = $conn->query("SELECT COUNT(*) FROM avaliacoes a 
                      JOIN usuarios u ON a.avaliado_id = u.id 
                      WHERE u.tipo_usuario = 'caminhoneiro'");
$total_motoristas = $stmt->fetchColumn();

$stmt = $conn->query("SELECT COUNT(*) FROM avaliacoes a 
                      JOIN usuarios u ON a.avaliado_id = u.id 
                      WHERE u.tipo_usuario = 'empresa'");
$total_empresas = $stmt->fetchColumn();

// Calcular média geral
$stmt = $conn->query("SELECT AVG(nota) FROM avaliacoes");
$media_geral = $stmt->fetchColumn();
if ($media_geral === null) {
    $media_geral = 0;
}

// Contar avaliações por estrela
$stmt = $conn->query("SELECT nota, COUNT(*) as total FROM avaliacoes GROUP BY nota ORDER BY nota DESC");
$contagem_estrelas = $stmt->fetchAll(PDO::FETCH_ASSOC);
$total_por_estrela = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];

foreach ($contagem_estrelas as $item) {
    $total_por_estrela[$item['nota']] = $item['total'];
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciamento de Avaliações - TrackMoz Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="../../assets/css/admin.css">
    <style>
        .filter-card {
            border-left: 4px solid;
        }
        .filter-media { border-left-color: #fd7e14; }
        .filter-motorista { border-left-color: #0d6efd; }
        .filter-empresa { border-left-color: #6f42c1; }
        .star-rating {
            color: #ffc107;
        }
        .star-rating .bi-star-fill.inactive {
            color: #e2e2e2;
        }
        .review-card {
            border-left: 4px solid;
            transition: all 0.2s;
        }
        .review-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }
        .review-5 { border-left-color: #198754; }
        .review-4 { border-left-color: #20c997; }
        .review-3 { border-left-color: #fd7e14; }
        .review-2 { border-left-color: #ffc107; }
        .review-1 { border-left-color: #dc3545; }
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
                            <a class="nav-link" href="missoes.php">
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
                            <a class="nav-link active" href="avaliacoes.php">
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
                    <h1 class="h2">Gerenciamento de Avaliações</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#novaAvaliacaoModal">
                                <i class="bi bi-plus-circle"></i> Nova Avaliação
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-file-earmark-excel"></i> Exportar
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Estatísticas -->
                <div class="row mb-4">
                    <div class="col-md-4 mb-3">
                        <div class="card filter-card filter-media h-100 <?php echo $filter_type === 'todos' && $star_filter === 0 ? 'border-warning bg-warning bg-opacity-10' : ''; ?>">
                            <a href="?tipo=todos" class="text-decoration-none">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="text-muted mb-1">Avaliações Totais</h6>
                                            <h3 class="mb-0"><?php echo $total_avaliacoes; ?></h3>
                                            <div class="mt-2">
                                                <span class="star-rating">
                                                    <?php 
                                                    $media_arredondada = round($media_geral);
                                                    for ($i = 1; $i <= 5; $i++) {
                                                        echo '<i class="bi bi-star-fill ' . ($i <= $media_arredondada ? '' : 'inactive') . '"></i>';
                                                    }
                                                    ?>
                                                </span>
                                                <span class="ms-1">(<?php echo number_format($media_geral, 1); ?>)</span>
                                            </div>
                                        </div>
                                        <div class="bg-warning bg-opacity-25 p-3 rounded">
                                            <i class="bi bi-star-half text-warning fs-4"></i>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="card filter-card filter-motorista h-100 <?php echo $filter_type === 'motorista' ? 'border-primary bg-primary bg-opacity-10' : ''; ?>">
                            <a href="?tipo=motorista" class="text-decoration-none">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="text-muted mb-1">Avaliações de Motoristas</h6>
                                            <h3 class="mb-0"><?php echo $total_motoristas; ?></h3>
                                        </div>
                                        <div class="bg-primary bg-opacity-25 p-3 rounded">
                                            <i class="bi bi-truck text-primary fs-4"></i>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="card filter-card filter-empresa h-100 <?php echo $filter_type === 'empresa' ? 'border-purple bg-purple bg-opacity-10' : ''; ?>">
                            <a href="?tipo=empresa" class="text-decoration-none">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="text-muted mb-1">Avaliações de Empresas</h6>
                                            <h3 class="mb-0"><?php echo $total_empresas; ?></h3>
                                        </div>
                                        <div class="bg-info bg-opacity-25 p-3 rounded">
                                            <i class="bi bi-building text-info fs-4"></i>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Filtros por estrelas -->
                <div class="row mb-4">
                    <div class="col-12 mb-3">
                        <div class="card">
                            <div class="card-body">
                                <h6 class="card-title">Filtrar por classificação</h6>
                                <div class="d-flex flex-wrap gap-2 mt-3">
                                    <a href="?tipo=<?php echo $filter_type; ?>&estrelas=0" class="btn <?php echo $star_filter === 0 ? 'btn-secondary' : 'btn-outline-secondary'; ?>">
                                        Todas
                                    </a>
                                    <?php for ($i = 5; $i >= 1; $i--): ?>
                                    <a href="?tipo=<?php echo $filter_type; ?>&estrelas=<?php echo $i; ?>" class="btn <?php echo $star_filter === $i ? 'btn-warning' : 'btn-outline-warning'; ?>">
                                        <?php echo $i; ?> <i class="bi bi-star-fill"></i>
                                        <span class="ms-1 badge bg-secondary"><?php echo $total_por_estrela[$i]; ?></span>
                                    </a>
                                    <?php endfor; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Search Bar & Filters -->
                <div class="row mb-4">
                    <div class="col-md-6 mb-3">
                        <div class="input-group">
                            <input type="text" class="form-control" placeholder="Buscar avaliação..." id="searchInput">
                            <button class="btn btn-outline-secondary" type="button">
                                <i class="bi bi-search"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-md-6 mb-3 text-md-end">
                        <div class="btn-group">
                            <a href="?tipo=todos" class="btn btn-outline-secondary <?php echo $filter_type === 'todos' ? 'active' : ''; ?>">
                                Todas
                            </a>
                            <a href="?tipo=motorista" class="btn btn-outline-primary <?php echo $filter_type === 'motorista' ? 'active' : ''; ?>">
                                Motoristas
                            </a>
                            <a href="?tipo=empresa" class="btn btn-outline-info <?php echo $filter_type === 'empresa' ? 'active' : ''; ?>">
                                Empresas
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Avaliações -->
                <div class="card shadow-sm mb-4">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>ID</th>
                                        <th>Avaliado</th>
                                        <th>Avaliador</th>
                                        <th>Pontuação</th>
                                        <th>Comentário</th>
                                        <th>Missão</th>
                                        <th>Data</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($avaliacoes)): ?>
                                        <tr>
                                            <td colspan="8" class="text-center py-4">Nenhuma avaliação encontrada.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($avaliacoes as $avaliacao): ?>
                                            <tr>
                                                <td><?php echo $avaliacao['id']; ?></td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="avatar me-2 rounded-circle bg-light p-2">
                                                            <i class="bi <?php echo $avaliacao['tipo_avaliado'] === 'caminhoneiro' ? 'bi-truck' : 'bi-building'; ?> text-secondary"></i>
                                                        </div>
                                                        <div>
                                                            <h6 class="mb-0"><?php echo htmlspecialchars($avaliacao['nome_avaliado']); ?></h6>
                                                            <small class="text-muted">
                                                                <?php echo $avaliacao['tipo_avaliado'] === 'caminhoneiro' ? 'Motorista' : 'Empresa'; ?>
                                                            </small>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars($avaliacao['nome_avaliador']); ?>
                                                </td>
                                                <td>
                                                    <div class="star-rating">
                                                        <?php 
                                                        $pontuacao = $avaliacao['nota'];
                                                        for ($i = 1; $i <= 5; $i++) {
                                                            echo '<i class="bi bi-star-fill ' . ($i <= $pontuacao ? '' : 'inactive') . '"></i>';
                                                        }
                                                        ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="text-truncate" style="max-width: 200px;">
                                                        <?php echo htmlspecialchars($avaliacao['comentario']); ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php if ($avaliacao['missao_id']): ?>
                                                        <a href="ver-missao.php?id=<?php echo $avaliacao['missao_id']; ?>" class="btn btn-sm btn-outline-secondary">
                                                            #<?php echo $avaliacao['missao_id']; ?>
                                                        </a>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">N/A</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <small>
                                                        <?php echo isset($avaliacao['data_avaliacao']) ? date('d/m/Y', strtotime($avaliacao['data_avaliacao'])) : date('d/m/Y', strtotime($avaliacao['data'] ?? 'now')); ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#viewModal<?php echo $avaliacao['id']; ?>">
                                                            <i class="bi bi-eye"></i>
                                                        </button>
                                                        <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteModal<?php echo $avaliacao['id']; ?>">
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

    <!-- Modal Nova Avaliação -->
    <div class="modal fade" id="novaAvaliacaoModal" tabindex="-1" aria-labelledby="novaAvaliacaoModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="novaAvaliacaoModalLabel">Nova Avaliação</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="formNovaAvaliacao" action="adicionar-avaliacao.php" method="POST">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="avaliador_id" class="form-label">Avaliador</label>
                                <select class="form-select" id="avaliador_id" name="avaliador_id" required>
                                    <option value="" selected disabled>Selecione o avaliador</option>
                                    <!-- Aqui viriam os usuários do banco de dados -->
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="avaliado_id" class="form-label">Avaliado</label>
                                <select class="form-select" id="avaliado_id" name="avaliado_id" required>
                                    <option value="" selected disabled>Selecione o avaliado</option>
                                    <!-- Aqui viriam os usuários do banco de dados -->
                                </select>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="missao_id" class="form-label">Missão</label>
                                <select class="form-select" id="missao_id" name="missao_id" required>
                                    <option value="" selected disabled>Selecione a missão</option>
                                    <!-- Aqui viriam as missões do banco de dados -->
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="nota" class="form-label">Pontuação</label>
                                <div class="rating-input">
                                    <div class="btn-group w-100">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <input type="radio" class="btn-check" name="nota" id="star<?php echo $i; ?>" value="<?php echo $i; ?>" <?php echo $i == 5 ? 'checked' : ''; ?>>
                                        <label class="btn btn-outline-warning" for="star<?php echo $i; ?>">
                                            <?php echo $i; ?> <i class="bi bi-star-fill"></i>
                                        </label>
                                        <?php endfor; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="comentario" class="form-label">Comentário</label>
                            <textarea class="form-control" id="comentario" name="comentario" rows="3" required></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" form="formNovaAvaliacao" class="btn btn-primary">Adicionar Avaliação</button>
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