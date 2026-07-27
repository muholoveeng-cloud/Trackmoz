<?php
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');
include_once('../../includes/auth.php');
include_once('../../includes/kpi-helpers.php');

require_role(['transportador'], '../login.php');

$kpi = kpi_transportador($conn, (int)$_SESSION['user_id']);

// Buscar estatísticas básicas (legado — mantido para links)
$stats = [
    'missoes_disponiveis' => 0,
    'propostas_enviadas' => 0,
    'contratos_ativos' => 0
];

try {
    // Reutilizando estrutura atual (missões abertas e propostas por usuário)
    $stmt = $conn->prepare("SELECT COUNT(*) FROM missoes WHERE status = 'aberta'");
    $stmt->execute();
    $stats['missoes_disponiveis'] = (int)$stmt->fetchColumn();

    $stmt = $conn->prepare("SELECT COUNT(*) FROM propostas WHERE caminhoneiro_id = :id");
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $stats['propostas_enviadas'] = (int)$stmt->fetchColumn();

    $stmt = $conn->prepare(
        "SELECT COUNT(*) FROM parcerias
         WHERE transportador_id = :id AND status = 'ativa'
           AND (data_fim IS NULL OR data_fim >= CURDATE())"
    );
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $stats['contratos_ativos'] = (int)$stmt->fetchColumn();

    $stmt = $conn->prepare(
        "SELECT COUNT(*) FROM parcerias
         WHERE transportador_id = :id
           AND status IN ('pedido_enviado','em_negociacao','aguardando_aprovacao_transportador','pendente')"
    );
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $stats['parcerias_pendentes'] = (int)$stmt->fetchColumn();
} catch (PDOException $e) {
    error_log('Erro ao buscar estatísticas do transportador: ' . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Transportador - TrackMoz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
</head>
<body>
    <?php include_once('../../includes/menu.php'); ?>

    <div class="container mt-4">
        <div class="row mb-4">
            <div class="col-md-8">
                <h2>Área do Transportador</h2>
                <p class="text-muted">Bem-vindo, <?php echo htmlspecialchars($_SESSION['user_name'] ?? ''); ?>.</p>
            </div>
        </div>

        <?php
        echo kpi_render_cards($kpi, [
            'missoes_ativas'     => ['label' => 'Missões activas'],
            'missoes_pendentes'  => ['label' => 'Aguardando resposta'],
            'missoes_concluidas' => ['label' => 'Concluídas'],
            'frota_ativa'        => ['label' => 'Viaturas activas'],
            'motoristas_ativos'  => ['label' => 'Motoristas'],
            'parcerias_ativas'   => ['label' => 'Parcerias'],
            'receita_estimada'   => ['label' => 'Receita estimada', 'format' => 'money'],
        ]);
        ?>

        <div class="row g-3 mt-2">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted mb-2">Missões Disponíveis</h6>
                                <h3 class="mb-0"><?php echo $stats['missoes_disponiveis']; ?></h3>
                            </div>
                            <div class="bg-primary bg-opacity-10 p-3 rounded">
                                <i class="bi bi-list-task text-primary fs-4"></i>
                            </div>
                        </div>
                        <a href="<?php echo BASE_URL; ?>/pages/caminhoneiro/missoes.php" class="btn btn-link p-0 mt-3">Ver missões <i class="bi bi-arrow-right"></i></a>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted mb-2">Propostas Enviadas</h6>
                                <h3 class="mb-0"><?php echo $stats['propostas_enviadas']; ?></h3>
                            </div>
                            <div class="bg-success bg-opacity-10 p-3 rounded">
                                <i class="bi bi-send text-success fs-4"></i>
                            </div>
                        </div>
                        <a href="<?php echo BASE_URL; ?>/pages/caminhoneiro/propostas.php" class="btn btn-link p-0 mt-3">Ver propostas <i class="bi bi-arrow-right"></i></a>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted mb-2">Parcerias Activas</h6>
                                <h3 class="mb-0">
                                    <?php echo $stats['contratos_ativos']; ?>
                                    <?php if (!empty($stats['parcerias_pendentes'])): ?>
                                        <span class="badge bg-warning text-dark fs-6 ms-1">
                                            <?php echo $stats['parcerias_pendentes']; ?> pendente<?php echo $stats['parcerias_pendentes'] > 1 ? 's' : ''; ?>
                                        </span>
                                    <?php endif; ?>
                                </h3>
                            </div>
                            <div class="bg-warning bg-opacity-10 p-3 rounded">
                                <i class="bi bi-handshake text-warning fs-4"></i>
                            </div>
                        </div>
                        <a href="<?php echo BASE_URL; ?>/pages/transportador/parcerias.php" class="btn btn-link p-0 mt-3">
                            Ver parcerias <i class="bi bi-arrow-right"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?php echo BASE_URL; ?>/assets/js/main.js"></script>
</body>
</html>
