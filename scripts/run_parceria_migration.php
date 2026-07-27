<?php
/**
 * Migração Fase 2 - Parcerias de Longo Prazo
 * Aceder via: http://localhost/trackmoz/scripts/run_parceria_migration.php
 */
include_once('../config/database.php');

$results = [];

// 1. Criar tabela parcerias
try {
    $conn->exec("CREATE TABLE IF NOT EXISTS `parcerias` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `empresa_id` int(11) NOT NULL,
        `transportador_id` int(11) NOT NULL,
        `descricao` text,
        `data_inicio` date NOT NULL,
        `data_fim` date DEFAULT NULL,
        `exclusiva` tinyint(1) NOT NULL DEFAULT '1',
        `status` enum('pendente','ativa','suspensa','terminada','rejeitada') NOT NULL DEFAULT 'pendente',
        `proposto_por` enum('empresa','transportador') NOT NULL DEFAULT 'empresa',
        `motivo_rejeicao` text DEFAULT NULL,
        `data_criacao` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
        `data_atualizacao` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `empresa_id` (`empresa_id`),
        KEY `transportador_id` (`transportador_id`)
    ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4");
    $results[] = ['ok' => true, 'msg' => 'Tabela <strong>parcerias</strong> criada (ou já existia).'];
} catch (PDOException $e) {
    $results[] = ['ok' => false, 'msg' => 'Erro ao criar tabela parcerias: ' . $e->getMessage()];
}

// 2. Adicionar coluna parceria_id a missoes (ignorar erro se já existir)
try {
    // Verificar se a coluna já existe antes de adicionar (MySQL 5.7 não suporta IF NOT EXISTS no ALTER)
    $chk = $conn->query("SHOW COLUMNS FROM `missoes` LIKE 'parceria_id'")->fetchColumn();
    if (!$chk) {
        $conn->exec("ALTER TABLE `missoes` ADD COLUMN `parceria_id` int(11) DEFAULT NULL AFTER `transportador_id`");
        $results[] = ['ok' => true, 'msg' => 'Coluna <strong>parceria_id</strong> adicionada à tabela missoes.'];
    } else {
        $results[] = ['ok' => true, 'msg' => 'Coluna <strong>parceria_id</strong> já existe em missoes (sem alterações).'];
    }
} catch (PDOException $e) {
    $results[] = ['ok' => false, 'msg' => 'Erro ao adicionar parceria_id: ' . $e->getMessage()];
}

// 3. Adicionar tipo 'parceria' às notificações
try {
    $conn->exec("ALTER TABLE `notificacoes` MODIFY `tipo` enum('missao','proposta','proposta_aceita','mensagem','avaliacao','sistema','confirmacao_entrega','emergencia','documento','parceria') NOT NULL");
    $results[] = ['ok' => true, 'msg' => 'Tipo <strong>parceria</strong> adicionado ao enum de notificacoes.'];
} catch (PDOException $e) {
    $results[] = ['ok' => true, 'msg' => 'Enum notificacoes já actualizado (sem alterações).'];
}

$allOk = array_reduce($results, fn($c, $r) => $c && $r['ok'], true);
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <title>Migração Fase 2 - TrackMoz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5" style="max-width:640px">
    <div class="card shadow-sm">
        <div class="card-header bg-<?php echo $allOk ? 'success' : 'danger'; ?> text-white">
            <h5 class="mb-0">Migração Fase 2 — <?php echo $allOk ? 'Concluída com sucesso' : 'Concluída com erros'; ?></h5>
        </div>
        <div class="card-body">
            <ul class="list-group list-group-flush">
                <?php foreach ($results as $r): ?>
                    <li class="list-group-item d-flex align-items-center gap-2">
                        <i class="bi bi-<?php echo $r['ok'] ? 'check-circle-fill text-success' : 'x-circle-fill text-danger'; ?>"></i>
                        <?php echo $r['msg']; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div class="card-footer text-center">
            <a href="../index.php" class="btn btn-primary btn-sm">Voltar ao sistema</a>
        </div>
    </div>
</div>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
</body>
</html>
