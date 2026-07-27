<?php
include_once __DIR__ . '/../config/database.php';
include_once __DIR__ . '/../includes/missao-helpers.php';

$conn->exec(
    "INSERT INTO missoes (empresa_id, titulo, origem, destino, tipo_veiculo, tipo_carga, valor, descricao, prazo_entrega, status)
     VALUES (3, 'Teste auto', 'Maputo', 'Gaza - Xai-Xai', 'caminhao', 'geral', 500, 'x', '2026-06-15', 'aberta')"
);
$id = (int)$conn->lastInsertId();
pos_criacao_missao($conn, $id, -25.97, 32.57, -25.04, 33.64, 'Maputo', 'Gaza - Xai-Xai', 'aberta');
$row = $conn->query("SELECT codigo_missao, local_origem_id FROM missoes WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
echo json_encode($row, JSON_PRETTY_PRINT) . "\n";
$conn->exec("DELETE FROM locais WHERE id IN (SELECT local_origem_id FROM missoes WHERE id = $id)");
$conn->exec("DELETE FROM missoes WHERE id = $id");
echo "OK\n";
