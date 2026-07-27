<?php
// Script para corrigir os perfis de caminhoneiros
// Este script deve ser executado pelo administrador ou durante a manutenção do sistema

// Incluir a configuração do banco de dados
require_once '../config/database.php';

// Configurar cabeçalho para exibir resultados como texto
header('Content-Type: text/plain');

echo "Iniciando correção dos perfis de caminhoneiros...\n\n";

try {
    // Iniciar transação
    $conn->beginTransaction();
    
    echo "1. Verificando a estrutura da tabela...\n";
    // Modificar a coluna tipo_veiculo para aceitar NULL
    $conn->exec("ALTER TABLE perfil_caminhoneiro MODIFY COLUMN tipo_veiculo VARCHAR(50) DEFAULT NULL");
    echo "   Estrutura atualizada com sucesso.\n\n";
    
    echo "2. Verificando caminhoneiros sem perfil...\n";
    // Encontrar e criar perfis para caminhoneiros que não têm
    $sql = "INSERT INTO perfil_caminhoneiro (usuario_id, disponibilidade)
            SELECT id, 'indisponivel' FROM usuarios 
            WHERE tipo_usuario = 'caminhoneiro' 
            AND id NOT IN (SELECT usuario_id FROM perfil_caminhoneiro)";
    $result = $conn->exec($sql);
    echo "   {$result} perfis criados para caminhoneiros sem perfil.\n\n";
    
    echo "3. Verificando campos NULL em perfis existentes...\n";
    // Corrigir valores NULL para o tipo de veículo
    $sql = "UPDATE perfil_caminhoneiro 
            SET tipo_veiculo = 'Não informado' 
            WHERE tipo_veiculo IS NULL";
    $result = $conn->exec($sql);
    echo "   {$result} perfis atualizados com tipo de veículo.\n\n";
    
    // Corrigir valores NULL para localização
    $sql = "UPDATE perfil_caminhoneiro 
            SET ultima_atualizacao_local = NOW() 
            WHERE ultima_atualizacao_local IS NULL";
    $result = $conn->exec($sql);
    echo "   {$result} perfis atualizados com data de localização.\n\n";
    
    // Confirmar as alterações
    $conn->commit();
    
    echo "4. Exibindo perfis de caminhoneiros:\n";
    $sql = "SELECT u.id, u.nome, u.email, pc.tipo_veiculo, pc.disponibilidade  
            FROM usuarios u 
            JOIN perfil_caminhoneiro pc ON u.id = pc.usuario_id
            WHERE u.tipo_usuario = 'caminhoneiro'";
    $stmt = $conn->query($sql);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "   " . str_pad("ID", 5) . str_pad("Nome", 25) . str_pad("Email", 30) . 
         str_pad("Tipo Veículo", 20) . "Disponibilidade\n";
    echo "   " . str_repeat("-", 90) . "\n";
    
    foreach ($results as $row) {
        echo "   " . str_pad($row['id'], 5) . 
             str_pad(substr($row['nome'], 0, 22), 25) . 
             str_pad(substr($row['email'], 0, 27), 30) . 
             str_pad(substr($row['tipo_veiculo'] ?? 'Não informado', 0, 17), 20) . 
             ($row['disponibilidade'] ?? 'indisponivel') . "\n";
    }
    
    echo "\nCorreção concluída com sucesso!\n";
    
} catch (PDOException $e) {
    // Reverter alterações em caso de erro
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    
    echo "ERRO: " . $e->getMessage() . "\n\n";
    echo "As alterações foram revertidas devido a um erro.\n";
} 