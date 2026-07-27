<?php
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');

// Verificar se o usuário está logado e é um administrador
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 'admin') {
    header('Location: ../login.php');
    exit();
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Iniciar transação
        $conn->beginTransaction();
        
        // 1. Atualizar estrutura da tabela
        $conn->exec("ALTER TABLE perfil_caminhoneiro MODIFY COLUMN tipo_veiculo VARCHAR(50) DEFAULT 'Não informado'");
        $conn->exec("ALTER TABLE perfil_caminhoneiro MODIFY COLUMN placa_veiculo VARCHAR(20) DEFAULT 'Não informado'");
        $conn->exec("ALTER TABLE perfil_caminhoneiro MODIFY COLUMN capacidade_carga DECIMAL(10,2) DEFAULT 0.00");
        
        // 2. Encontrar usuários caminhoneiros sem perfil
        $sql = "INSERT INTO perfil_caminhoneiro (usuario_id, disponibilidade, tipo_veiculo, placa_veiculo)
                SELECT id, 'indisponivel', 'Não informado', 'Não informado' FROM usuarios 
                WHERE tipo_usuario = 'caminhoneiro' 
                AND id NOT IN (SELECT usuario_id FROM perfil_caminhoneiro)";
        $perfis_criados = $conn->exec($sql);
        
        // 3. Corrigir valores NULL para campos essenciais
        $sql = "UPDATE perfil_caminhoneiro 
                SET tipo_veiculo = 'Não informado' 
                WHERE tipo_veiculo IS NULL OR tipo_veiculo = ''";
        $tipos_corrigidos = $conn->exec($sql);
        
        $sql = "UPDATE perfil_caminhoneiro 
                SET placa_veiculo = 'Não informado' 
                WHERE placa_veiculo IS NULL OR placa_veiculo = ''";
        $placas_corrigidas = $conn->exec($sql);
        
        $sql = "UPDATE perfil_caminhoneiro 
                SET capacidade_carga = 0 
                WHERE capacidade_carga IS NULL";
        $capacidades_corrigidas = $conn->exec($sql);
        
        $sql = "UPDATE perfil_caminhoneiro 
                SET descricao_veiculo = 'Não informado' 
                WHERE descricao_veiculo IS NULL OR descricao_veiculo = ''";
        $descricoes_corrigidas = $conn->exec($sql);
        
        // 4. Atualizar data de localização para os que não têm
        $sql = "UPDATE perfil_caminhoneiro 
                SET ultima_atualizacao_local = NOW() 
                WHERE ultima_atualizacao_local IS NULL";
        $localizacoes_corrigidas = $conn->exec($sql);
        
        // Confirmar as alterações
        $conn->commit();
        
        $message = "Correções aplicadas com sucesso:<br>
                    - {$perfis_criados} perfis criados<br>
                    - {$tipos_corrigidos} tipos de veículo corrigidos<br>
                    - {$placas_corrigidas} placas corrigidas<br>
                    - {$capacidades_corrigidas} capacidades corrigidas<br>
                    - {$descricoes_corrigidas} descrições corrigidas<br>
                    - {$localizacoes_corrigidas} localizações atualizadas";
    } catch (PDOException $e) {
        $conn->rollBack();
        $error = "Erro ao aplicar correções: " . $e->getMessage();
    }
}

// Buscar perfis de caminhoneiros para exibir
$sql = "SELECT u.id, u.nome, u.email, pc.tipo_veiculo, pc.placa_veiculo, 
               pc.capacidade_carga, pc.numero_cnh, pc.disponibilidade  
        FROM usuarios u 
        JOIN perfil_caminhoneiro pc ON u.id = pc.usuario_id
        WHERE u.tipo_usuario = 'caminhoneiro'";
$stmt = $conn->query($sql);
$perfis = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Corrigir Perfis de Caminhoneiros - TrackMoz Admin</title>
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
            <!-- Sidebar -->
            <div class="col-md-2 admin-sidebar d-none d-md-block p-0">
                <div class="d-flex flex-column p-3">
                    <ul class="nav flex-column">
                        <li class="nav-item"><a class="nav-link" href="dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a></li>
                        <li class="nav-item"><a class="nav-link" href="usuarios.php"><i class="bi bi-people"></i> Usuários</a></li>
                        <li class="nav-item"><a class="nav-link" href="missoes.php"><i class="bi bi-list-task"></i> Missões</a></li>
                        <li class="nav-item"><a class="nav-link active" href="corrigir-perfis.php"><i class="bi bi-tools"></i> Corrigir Perfis</a></li>
                    </ul>
                </div>
            </div>

            <!-- Content -->
            <main class="col-md-10 ms-sm-auto col-lg-10 px-md-4 py-4">
                <div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Corrigir Perfis de Caminhoneiros</h1>
                </div>

                <?php if (!empty($message)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php echo $message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php endif; ?>

                <?php if (!empty($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php endif; ?>

                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Correção de Perfis</h5>
                    </div>
                    <div class="card-body">
                        <p>Esta ferramenta corrige problemas comuns nos perfis de caminhoneiros:</p>
                        <ul>
                            <li>Cria perfis para caminhoneiros que não têm</li>
                            <li>Corrige campos vazios ou nulos</li>
                            <li>Atualiza informações de localização</li>
                        </ul>
                        <form method="post" action="">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-tools"></i> Aplicar Correções
                            </button>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Perfis de Caminhoneiros</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Nome</th>
                                        <th>Email</th>
                                        <th>Tipo de Veículo</th>
                                        <th>Placa</th>
                                        <th>Capacidade</th>
                                        <th>CNH</th>
                                        <th>Disponibilidade</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($perfis as $perfil): ?>
                                    <tr>
                                        <td><?php echo $perfil['id']; ?></td>
                                        <td><?php echo htmlspecialchars($perfil['nome']); ?></td>
                                        <td><?php echo htmlspecialchars($perfil['email']); ?></td>
                                        <td><?php echo htmlspecialchars($perfil['tipo_veiculo']); ?></td>
                                        <td><?php echo htmlspecialchars($perfil['placa_veiculo']); ?></td>
                                        <td><?php echo number_format($perfil['capacidade_carga'], 2, ',', '.'); ?> kg</td>
                                        <td><?php echo htmlspecialchars($perfil['numero_cnh'] ?: 'Não informado'); ?></td>
                                        <td>
                                            <span class="badge bg-<?php 
                                                echo match($perfil['disponibilidade']) {
                                                    'disponivel' => 'success',
                                                    'ocupado' => 'warning',
                                                    'manutencao' => 'danger',
                                                    default => 'secondary'
                                                };
                                            ?>">
                                                <?php echo ucfirst($perfil['disponibilidade']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 