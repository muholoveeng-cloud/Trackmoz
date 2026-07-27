<?php
session_start();
include_once('../../config/database.php');

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 'admin') {
    header('Location: ../login.php');
    exit();
}

$categoria = isset($_GET['categoria']) ? $_GET['categoria'] : 'todos';
$pagina = max(1, (int)($_GET['pagina'] ?? 1));
$porPagina = 25;
$offset = ($pagina - 1) * $porPagina;

$partes = [];

if ($categoria === 'todos' || $categoria === 'viagem') {
    $filtroChecklist = $categoria === 'viagem' ? "WHERE rv.tipo NOT LIKE 'checklist_%'" : '';
    $partes[] = "
        SELECT 'viagem' AS categoria, rv.tipo AS evento,
               COALESCE(rv.descricao, rv.tipo) AS descricao,
               rv.data_registro AS data,
               CONCAT('Missão #', m.id, ': ', m.titulo) AS referencia,
               NULL AS usuario
        FROM registros_viagem rv
        INNER JOIN missoes m ON rv.missao_id = m.id
        $filtroChecklist
    ";
}

if ($categoria === 'checklist') {
    $partes[] = "
        SELECT 'checklist' AS categoria, rv.tipo AS evento,
               COALESCE(rv.descricao, rv.tipo) AS descricao,
               rv.data_registro AS data,
               CONCAT('Missão #', m.id, ': ', m.titulo) AS referencia,
               NULL AS usuario
        FROM registros_viagem rv
        INNER JOIN missoes m ON rv.missao_id = m.id
        WHERE rv.tipo LIKE 'checklist_%'
    ";
}

if ($categoria === 'todos' || $categoria === 'missao') {
    $partes[] = "
        SELECT 'missao' AS categoria, 'criacao' AS evento,
               m.titulo AS descricao, m.data_criacao AS data,
               CONCAT('Missão #', m.id) AS referencia, u.nome AS usuario
        FROM missoes m
        INNER JOIN usuarios u ON m.empresa_id = u.id
    ";
}

if ($categoria === 'todos' || $categoria === 'proposta') {
    $partes[] = "
        SELECT 'proposta' AS categoria, p.status AS evento,
               CONCAT('Proposta para missão #', p.missao_id) AS descricao,
               p.data_criacao AS data,
               CONCAT('Missão #', p.missao_id) AS referencia, u.nome AS usuario
        FROM propostas p
        INNER JOIN usuarios u ON p.caminhoneiro_id = u.id
    ";
}

if ($categoria === 'todos' || $categoria === 'documento') {
    $partes[] = "
        SELECT 'documento' AS categoria, d.status AS evento,
               CONCAT('Documento: ', d.tipo_documento) AS descricao,
               d.data_upload AS data,
               d.nome_arquivo AS referencia, u.nome AS usuario
        FROM documentos d
        INNER JOIN usuarios u ON d.usuario_id = u.id
    ";
}

if ($categoria === 'todos' || $categoria === 'usuario') {
    $partes[] = "
        SELECT 'usuario' AS categoria, u.tipo_usuario AS evento,
               CONCAT('Novo utilizador: ', u.nome) AS descricao,
               u.data_registro AS data,
               u.email AS referencia, u.nome AS usuario
        FROM usuarios u
        WHERE u.tipo_usuario != 'admin'
    ";
}

$registros = [];
$totalRegistros = 0;

if (!empty($partes)) {
    $sqlBase = '(' . implode(') UNION ALL (', $partes) . ')';

    $stmt = $conn->query("SELECT COUNT(*) FROM ($sqlBase) AS logs");
    $totalRegistros = (int)$stmt->fetchColumn();

    $sql = "SELECT * FROM ($sqlBase) AS logs ORDER BY data DESC LIMIT $porPagina OFFSET $offset";
    $stmt = $conn->query($sql);
    $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$totalPaginas = max(1, (int)ceil($totalRegistros / $porPagina));

$labelsCategoria = [
    'todos' => 'Todos',
    'viagem' => 'Viagens',
    'checklist' => 'Checklists',
    'missao' => 'Missões',
    'proposta' => 'Propostas',
    'documento' => 'Documentos',
    'usuario' => 'Utilizadores',
];

$labelsEvento = [
    'checklist_pre_viagem' => 'Pré-viagem',
    'checklist_recolha' => 'Recolha',
    'checklist_entrega' => 'Entrega',
];
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logs do Sistema - TrackMoz Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="../../assets/css/admin.css">
    <style>
        .badge-viagem { background-color: #0d6efd; }
        .badge-checklist { background-color: #6610f2; }
        .badge-missao { background-color: #198754; }
        .badge-proposta { background-color: #6f42c1; }
        .badge-documento { background-color: #fd7e14; }
        .badge-usuario { background-color: #20c997; }
    </style>
</head>
<body>
    <?php include_once('../../includes/menu.php'); ?>

    <div class="container-fluid">
        <div class="row">
            <div class="col-md-2 admin-sidebar d-none d-md-block p-0">
                <div class="d-flex flex-column p-3">
                    <ul class="nav flex-column">
                        <li class="nav-item"><a class="nav-link" href="dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a></li>
                        <li class="nav-item"><a class="nav-link" href="usuarios.php"><i class="bi bi-people"></i> Usuários</a></li>
                        <li class="nav-item"><a class="nav-link" href="missoes.php"><i class="bi bi-list-task"></i> Missões</a></li>
                        <li class="nav-item"><a class="nav-link" href="relatorios.php"><i class="bi bi-graph-up"></i> Relatórios</a></li>
                        <li class="nav-item"><a class="nav-link" href="mensagens.php"><i class="bi bi-chat-left"></i> Mensagens</a></li>
                        <li class="nav-item"><a class="nav-link" href="avaliacoes.php"><i class="bi bi-star"></i> Avaliações</a></li>
                        <li class="nav-item"><a class="nav-link" href="configuracoes.php"><i class="bi bi-gear"></i> Configurações</a></li>
                    </ul>
                    <hr class="text-white-50">
                    <h6 class="text-white-50 px-3 mt-3 mb-2 text-uppercase">Sistema</h6>
                    <ul class="nav flex-column">
                        <li class="nav-item"><a class="nav-link active" href="registros.php"><i class="bi bi-journals"></i> Logs do Sistema</a></li>
                        <li class="nav-item"><a class="nav-link" href="backup.php"><i class="bi bi-cloud-arrow-down"></i> Backup</a></li>
                    </ul>
                </div>
            </div>

            <main class="col-md-10 ms-sm-auto col-lg-10 px-md-4 py-4">
                <div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Logs do Sistema</h1>
                </div>

                <div class="card mb-4">
                    <div class="card-body">
                        <form method="get" class="row g-3 align-items-end">
                            <div class="col-md-4">
                                <label for="categoria" class="form-label">Categoria</label>
                                <select name="categoria" id="categoria" class="form-select">
                                    <?php foreach ($labelsCategoria as $valor => $label): ?>
                                        <option value="<?php echo htmlspecialchars($valor); ?>" <?php echo $categoria === $valor ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-primary w-100">Filtrar</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span>Registos de atividade</span>
                        <span class="badge bg-secondary"><?php echo $totalRegistros; ?> total</span>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($registros)): ?>
                            <p class="text-center text-muted py-5 mb-0">Nenhum registo encontrado.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Data</th>
                                            <th>Categoria</th>
                                            <th>Evento</th>
                                            <th>Descrição</th>
                                            <th>Referência</th>
                                            <th>Utilizador</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($registros as $reg):
                                            $catExibir = $reg['categoria'];
                                            if ($catExibir === 'viagem' && str_starts_with($reg['evento'] ?? '', 'checklist_')) {
                                                $catExibir = 'checklist';
                                            }
                                            $eventoExibir = $labelsEvento[$reg['evento'] ?? ''] ?? ($reg['evento'] ?? '—');
                                        ?>
                                            <tr>
                                                <td class="text-nowrap"><?php echo date('d/m/Y H:i', strtotime($reg['data'])); ?></td>
                                                <td>
                                                    <span class="badge badge-<?php echo htmlspecialchars($catExibir); ?>">
                                                        <?php echo htmlspecialchars(ucfirst($catExibir)); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo htmlspecialchars($eventoExibir); ?></td>
                                                <td><?php echo htmlspecialchars($reg['descricao'] ?? '—'); ?></td>
                                                <td><small><?php echo htmlspecialchars($reg['referencia'] ?? '—'); ?></small></td>
                                                <td><?php echo htmlspecialchars($reg['usuario'] ?? '—'); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if ($totalPaginas > 1): ?>
                        <div class="card-footer">
                            <nav>
                                <ul class="pagination pagination-sm mb-0 justify-content-center">
                                    <?php for ($p = 1; $p <= $totalPaginas; $p++): ?>
                                        <li class="page-item <?php echo $p === $pagina ? 'active' : ''; ?>">
                                            <a class="page-link" href="?categoria=<?php echo urlencode($categoria); ?>&pagina=<?php echo $p; ?>"><?php echo $p; ?></a>
                                        </li>
                                    <?php endfor; ?>
                                </ul>
                            </nav>
                        </div>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
