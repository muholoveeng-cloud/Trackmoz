<?php
session_start();
include_once('../../config/database.php');

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 'admin') {
    header('Location: ../login.php');
    exit();
}

$backupDir = realpath(__DIR__ . '/../../storage') ?: __DIR__ . '/../../storage';
$backupDir .= DIRECTORY_SEPARATOR . 'backups';

if (!is_dir($backupDir)) {
    mkdir($backupDir, 0755, true);
}

function exportarBaseDados(PDO $conn): string
{
    $output = "-- TrackMoz Database Backup\n";
    $output .= '-- Gerado em: ' . date('Y-m-d H:i:s') . "\n\n";
    $output .= "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";

    $tables = $conn->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tables as $table) {
        $create = $conn->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM);
        $output .= "DROP TABLE IF EXISTS `$table`;\n";
        $output .= $create[1] . ";\n\n";

        $rows = $conn->query("SELECT * FROM `$table`");
        while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
            $cols = array_map(static fn($c) => "`$c`", array_keys($row));
            $vals = array_map(static function ($v) use ($conn) {
                return $v === null ? 'NULL' : $conn->quote((string)$v);
            }, array_values($row));
            $output .= 'INSERT INTO `' . $table . '` (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ");\n";
        }
        $output .= "\n";
    }

    $output .= "SET FOREIGN_KEY_CHECKS=1;\n";
    return $output;
}

function nomeBackupValido(string $file): bool
{
    return (bool)preg_match('/^trackmoz_backup_[\d_-]+\.sql$/', $file);
}

$mensagem = '';
$tipoMensagem = 'success';

if (isset($_GET['download'])) {
    $file = basename($_GET['download']);
    $path = $backupDir . DIRECTORY_SEPARATOR . $file;
    if (nomeBackupValido($file) && is_file($path)) {
        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }
    http_response_code(404);
    exit('Ficheiro não encontrado.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['gerar_backup'])) {
        try {
            $filename = 'trackmoz_backup_' . date('Y-m-d_His') . '.sql';
            $conteudo = exportarBaseDados($conn);
            file_put_contents($backupDir . DIRECTORY_SEPARATOR . $filename, $conteudo);
            $mensagem = 'Backup criado com sucesso: ' . $filename;
        } catch (Exception $e) {
            $mensagem = 'Erro ao gerar backup: ' . $e->getMessage();
            $tipoMensagem = 'danger';
        }
    } elseif (isset($_POST['eliminar_backup'])) {
        $file = basename($_POST['eliminar_backup']);
        $path = $backupDir . DIRECTORY_SEPARATOR . $file;
        if (nomeBackupValido($file) && is_file($path) && unlink($path)) {
            $mensagem = 'Backup eliminado.';
        } else {
            $mensagem = 'Não foi possível eliminar o ficheiro.';
            $tipoMensagem = 'danger';
        }
    }
}

$backups = [];
foreach (glob($backupDir . DIRECTORY_SEPARATOR . 'trackmoz_backup_*.sql') ?: [] as $path) {
    $backups[] = [
        'nome' => basename($path),
        'tamanho' => filesize($path),
        'data' => filemtime($path),
    ];
}
usort($backups, static fn($a, $b) => $b['data'] <=> $a['data']);
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backup da Base de Dados - TrackMoz Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="../../assets/css/admin.css">
    <style>
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
                        <li class="nav-item"><a class="nav-link" href="registros.php"><i class="bi bi-journals"></i> Logs do Sistema</a></li>
                        <li class="nav-item"><a class="nav-link active" href="backup.php"><i class="bi bi-cloud-arrow-down"></i> Backup</a></li>
                    </ul>
                </div>
            </div>

            <main class="col-md-10 ms-sm-auto col-lg-10 px-md-4 py-4">
                <div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Backup da Base de Dados</h1>
                </div>

                <?php if ($mensagem): ?>
                    <div class="alert alert-<?php echo htmlspecialchars($tipoMensagem); ?> alert-dismissible fade show">
                        <?php echo htmlspecialchars($mensagem); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="row mb-4">
                    <div class="col-lg-6">
                        <div class="card">
                            <div class="card-header">
                                <i class="bi bi-database-down"></i> Gerar novo backup
                            </div>
                            <div class="card-body">
                                <p class="text-muted">
                                    Exporta todas as tabelas da base <strong><?php echo htmlspecialchars(DB_NAME); ?></strong>
                                    para um ficheiro SQL em <code>storage/backups/</code>.
                                </p>
                                <form method="post">
                                    <button type="submit" name="gerar_backup" value="1" class="btn btn-primary">
                                        <i class="bi bi-cloud-arrow-down"></i> Gerar backup agora
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="card border-warning">
                            <div class="card-header bg-warning text-dark">
                                <i class="bi bi-exclamation-triangle"></i> Aviso
                            </div>
                            <div class="card-body">
                                <p class="mb-0 small">
                                    Guarde os backups num local seguro. Para restaurar, importe o ficheiro .sql no phpMyAdmin
                                    ou via linha de comandos MySQL. A geração pode demorar em bases de dados grandes.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">Backups disponíveis</div>
                    <div class="card-body p-0">
                        <?php if (empty($backups)): ?>
                            <p class="text-center text-muted py-5 mb-0">Ainda não existem backups. Clique em «Gerar backup agora».</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Ficheiro</th>
                                            <th>Data</th>
                                            <th>Tamanho</th>
                                            <th class="text-end">Ações</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($backups as $b): ?>
                                            <tr>
                                                <td><code><?php echo htmlspecialchars($b['nome']); ?></code></td>
                                                <td><?php echo date('d/m/Y H:i:s', $b['data']); ?></td>
                                                <td><?php echo number_format($b['tamanho'] / 1024, 1, ',', '.'); ?> KB</td>
                                                <td class="text-end text-nowrap">
                                                    <a href="?download=<?php echo urlencode($b['nome']); ?>" class="btn btn-sm btn-outline-primary">
                                                        <i class="bi bi-download"></i> Descarregar
                                                    </a>
                                                    <form method="post" class="d-inline" onsubmit="return confirm('Eliminar este backup?');">
                                                        <input type="hidden" name="eliminar_backup" value="<?php echo htmlspecialchars($b['nome']); ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
