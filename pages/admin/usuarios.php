<?php
session_start();
require_once '../../config/app.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

require_role(['admin'], '../login.php');

// Definir o status padrão para buscar (todos, pendentes, ativos, inativos)
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'todos';

// Consultar usuários conforme filtro
$sql_where = "";
$params = [];

if ($status_filter === 'pendente') {
    $sql_where = "WHERE u.status = 'pendente'";
} elseif ($status_filter === 'ativo') {
    $sql_where = "WHERE u.status = 'ativo'";
} elseif ($status_filter === 'inativo') {
    $sql_where = "WHERE u.status = 'inativo'";
} elseif ($status_filter === 'caminhoneiro') {
    $sql_where = "WHERE u.tipo_usuario = 'caminhoneiro'";
} elseif ($status_filter === 'empresa') {
    $sql_where = "WHERE u.tipo_usuario = 'empresa'";
}

$sql = "SELECT u.*, 
            CASE 
                WHEN u.tipo_usuario = 'caminhoneiro' THEN pc.tipo_veiculo
                WHEN u.tipo_usuario = 'empresa' THEN pe.nome_empresa
                ELSE NULL
            END as detalhe_perfil
        FROM usuarios u
        LEFT JOIN perfil_caminhoneiro pc ON u.id = pc.usuario_id
        LEFT JOIN perfil_empresa pe ON u.id = pe.usuario_id
        $sql_where
        ORDER BY u.nome ASC";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Contar totais por categoria
$stmt = $conn->query("SELECT COUNT(*) FROM usuarios WHERE status = 'pendente'");
$total_pendentes = $stmt->fetchColumn();

$stmt = $conn->query("SELECT COUNT(*) FROM usuarios WHERE status = 'ativo'");
$total_ativos = $stmt->fetchColumn();

$stmt = $conn->query("SELECT COUNT(*) FROM usuarios WHERE tipo_usuario = 'caminhoneiro'");
$total_caminhoneiros = $stmt->fetchColumn();

$stmt = $conn->query("SELECT COUNT(*) FROM usuarios WHERE tipo_usuario = 'empresa'");
$total_empresas = $stmt->fetchColumn();

$stmt = $conn->query("SELECT COUNT(*) FROM usuarios WHERE tipo_usuario = 'admin'");
$total_admins = $stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciamento de Usuários - TrackMoz Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin.css">
    <style>
        .filter-card {
            border-left: 4px solid;
        }
        .filter-pendente { border-left-color: #ffc107; }
        .filter-ativo { border-left-color: #198754; }
        .filter-caminhoneiro { border-left-color: #0d6efd; }
        .filter-empresa { border-left-color: #6f42c1; }
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
                            <a class="nav-link active" href="usuarios.php">
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
                <?php if (isset($_GET['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php
                        switch ($_GET['success']) {
                            case 1:
                                echo "Usuário adicionado com sucesso!";
                                break;
                            case 2:
                                echo "Usuário aprovado com sucesso!";
                                break;
                            case 3:
                                echo "Usuário rejeitado com sucesso!";
                                break;
                            case 4:
                                echo "Usuário excluído com sucesso!";
                                break;
                            case 5:
                                echo "Usuário atualizado com sucesso!";
                                break;
                        }
                        ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($_GET['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php
                        if (isset($_GET['msg'])) {
                            echo htmlspecialchars($_GET['msg']);
                        } else {
                            switch ($_GET['error']) {
                                case 1:
                                    echo "Erro ao aprovar usuário!";
                                    break;
                                case 2:
                                    echo "Erro ao rejeitar usuário!";
                                    break;
                            }
                        }
                        ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Gerenciamento de Usuários</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#novoUsuarioModal">
                                <i class="bi bi-person-plus"></i> Novo Usuário
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
                        <div class="card filter-card filter-pendente h-100 <?php echo $status_filter === 'pendente' ? 'border-warning bg-warning bg-opacity-10' : ''; ?>">
                            <a href="?status=pendente" class="text-decoration-none">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="text-muted mb-1">Usuários Pendentes</h6>
                                            <h3 class="mb-0"><?php echo $total_pendentes; ?></h3>
                                        </div>
                                        <div class="bg-warning bg-opacity-25 p-3 rounded">
                                            <i class="bi bi-hourglass-split text-warning fs-4"></i>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="card filter-card filter-ativo h-100 <?php echo $status_filter === 'ativo' ? 'border-success bg-success bg-opacity-10' : ''; ?>">
                            <a href="?status=ativo" class="text-decoration-none">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="text-muted mb-1">Usuários Ativos</h6>
                                            <h3 class="mb-0"><?php echo $total_ativos; ?></h3>
                                        </div>
                                        <div class="bg-success bg-opacity-25 p-3 rounded">
                                            <i class="bi bi-person-check text-success fs-4"></i>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="card filter-card filter-caminhoneiro h-100 <?php echo $status_filter === 'caminhoneiro' ? 'border-primary bg-primary bg-opacity-10' : ''; ?>">
                            <a href="?status=caminhoneiro" class="text-decoration-none">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="text-muted mb-1">Caminhoneiros</h6>
                                            <h3 class="mb-0"><?php echo $total_caminhoneiros; ?></h3>
                                        </div>
                                        <div class="bg-primary bg-opacity-25 p-3 rounded">
                                            <i class="bi bi-truck text-primary fs-4"></i>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="card filter-card filter-empresa h-100 <?php echo $status_filter === 'empresa' ? 'border-primary bg-primary bg-opacity-10' : ''; ?>">
                            <a href="?status=empresa" class="text-decoration-none">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="text-muted mb-1">Empresas</h6>
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

                <!-- Search Bar & Status Filters -->
                <div class="row mb-4">
                    <div class="col-md-6 mb-3">
                        <div class="input-group">
                            <input type="text" class="form-control" placeholder="Buscar usuário..." id="searchInput">
                            <button class="btn btn-outline-secondary" type="button">
                                <i class="bi bi-search"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-md-6 mb-3 text-md-end">
                        <div class="btn-group">
                            <a href="?status=todos" class="btn btn-outline-secondary <?php echo $status_filter === 'todos' ? 'active' : ''; ?>">
                                Todos
                            </a>
                            <a href="?status=pendente" class="btn btn-outline-secondary <?php echo $status_filter === 'pendente' ? 'active' : ''; ?>">
                                Pendentes
                            </a>
                            <a href="?status=ativo" class="btn btn-outline-secondary <?php echo $status_filter === 'ativo' ? 'active' : ''; ?>">
                                Ativos
                            </a>
                            <a href="?status=inativo" class="btn btn-outline-secondary <?php echo $status_filter === 'inativo' ? 'active' : ''; ?>">
                                Inativos
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Users Table -->
                <div class="card shadow-sm mb-4">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>ID</th>
                                        <th>Nome</th>
                                        <th>Email</th>
                                        <th>Tipo</th>
                                        <th>Status</th>
                                        <th>Data Registro</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($usuarios)): ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-4">Nenhum usuário encontrado.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($usuarios as $usuario): ?>
                                            <tr>
                                                <td><?php echo $usuario['id']; ?></td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="avatar me-2 bg-light rounded-circle p-2">
                                                            <i class="bi bi-person text-secondary"></i>
                                                        </div>
                                                        <div>
                                                            <h6 class="mb-0"><?php echo htmlspecialchars($usuario['nome']); ?></h6>
                                                            <?php if ($usuario['detalhe_perfil']): ?>
                                                                <small class="text-muted"><?php echo htmlspecialchars($usuario['detalhe_perfil']); ?></small>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td><?php echo htmlspecialchars($usuario['email']); ?></td>
                                                <td>
                                                    <span class="badge bg-<?php 
                                                        echo $usuario['tipo_usuario'] === 'admin' ? 'danger' : 
                                                            ($usuario['tipo_usuario'] === 'caminhoneiro' ? 'primary' : 'info'); 
                                                    ?> rounded-pill">
                                                        <?php echo ucfirst($usuario['tipo_usuario']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?php 
                                                        echo $usuario['status'] === 'ativo' ? 'success' : 
                                                            ($usuario['status'] === 'pendente' ? 'warning' : 'secondary'); 
                                                    ?>">
                                                        <?php echo ucfirst($usuario['status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php echo date('d/m/Y', strtotime($usuario['data_registro'])); ?>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <a href="ver-usuario.php?id=<?php echo $usuario['id']; ?>" class="btn btn-outline-secondary">
                                                            <i class="bi bi-eye"></i>
                                                        </a>
                                                        <?php if ($usuario['status'] === 'pendente'): ?>
                                                            <a href="aprovar-usuario.php?id=<?php echo $usuario['id']; ?>" class="btn btn-outline-success">
                                                                <i class="bi bi-check-lg"></i>
                                                            </a>
                                                            <a href="rejeitar-usuario.php?id=<?php echo $usuario['id']; ?>" class="btn btn-outline-danger">
                                                                <i class="bi bi-x-lg"></i>
                                                            </a>
                                                        <?php else: ?>
                                                            <a href="editar-usuario.php?id=<?php echo $usuario['id']; ?>" class="btn btn-outline-primary">
                                                                <i class="bi bi-pencil"></i>
                                                            </a>
                                                            <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteModal<?php echo $usuario['id']; ?>">
                                                                <i class="bi bi-trash"></i>
                                                            </button>
                                                        <?php endif; ?>
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

    <!-- Modal Novo Usuário -->
    <div class="modal fade" id="novoUsuarioModal" tabindex="-1" aria-labelledby="novoUsuarioModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="novoUsuarioModalLabel">Novo Usuário</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="formNovoUsuario" action="adicionar-usuario.php" method="POST">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="nome" class="form-label">Nome</label>
                                <input type="text" class="form-control" id="nome" name="nome" required>
                            </div>
                            <div class="col-md-6">
                                <label for="tipo_usuario" class="form-label">Tipo de Usuário</label>
                                <select class="form-select" id="tipo_usuario" name="tipo_usuario" required>
                                    <option value="" selected disabled>Selecione o tipo</option>
                                    <option value="admin">Administrador</option>
                                    <option value="caminhoneiro">Caminhoneiro</option>
                                    <option value="transportador">Transportador</option>
                                    <option value="empresa">Empresa</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="email" name="email" required>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="telefone" class="form-label">Telefone</label>
                                <input type="text" class="form-control" id="telefone" name="telefone">
                            </div>
                            <div class="col-md-6">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status" required>
                                    <option value="ativo">Ativo</option>
                                    <option value="pendente">Pendente</option>
                                    <option value="inativo">Inativo</option>
                                </select>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="senha" class="form-label">Senha</label>
                                <input type="password" class="form-control" id="senha" name="senha" required>
                            </div>
                            <div class="col-md-6">
                                <label for="confirmar_senha" class="form-label">Confirmar Senha</label>
                                <input type="password" class="form-control" id="confirmar_senha" name="confirmar_senha" required>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" form="formNovoUsuario" class="btn btn-primary">Adicionar Usuário</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <?php foreach ($usuarios as $usuario): ?>
    <div class="modal fade" id="deleteModal<?php echo $usuario['id']; ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirmar Exclusão</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Tem certeza que deseja excluir o usuário <strong><?php echo htmlspecialchars($usuario['nome']); ?></strong>?</p>
                    <p class="text-danger">Esta ação não pode ser desfeita!</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <a href="excluir-usuario.php?id=<?php echo $usuario['id']; ?>" class="btn btn-danger">Confirmar Exclusão</a>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

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