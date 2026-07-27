<?php
/**
 * Script para corrigir perfis de caminhoneiros
 * 
 * Este script pode ser executado via linha de comando ou navegador
 * para aplicar correções nos perfis de caminhoneiros
 */

// Definir cabeçalho para exibição em navegador
header('Content-Type: text/plain');

echo "=================================================\n";
echo "CORREÇÃO DE PERFIS DE CAMINHONEIROS - MOÇAMISSION\n";
echo "=================================================\n\n";

// Incluir configuração do banco de dados
require_once __DIR__ . '/../config/database.php';

try {
    echo "1. Verificando estrutura da tabela...\n";
    // Modificar colunas para aceitar valores padrão
    $conn->exec("ALTER TABLE perfil_caminhoneiro MODIFY COLUMN tipo_veiculo VARCHAR(50) DEFAULT 'Não informado'");
    $conn->exec("ALTER TABLE perfil_caminhoneiro MODIFY COLUMN placa_veiculo VARCHAR(20) DEFAULT 'Não informado'");
    $conn->exec("ALTER TABLE perfil_caminhoneiro MODIFY COLUMN capacidade_carga DECIMAL(10,2) DEFAULT 0.00");
    $conn->exec("ALTER TABLE perfil_caminhoneiro MODIFY COLUMN descricao_veiculo TEXT DEFAULT NULL");
    echo "   Estrutura da tabela atualizada com sucesso.\n\n";
    
    echo "2. Verificando caminhoneiros sem perfil...\n";
    // Encontrar e criar perfis para caminhoneiros que não têm
    $sql = "INSERT INTO perfil_caminhoneiro (usuario_id, disponibilidade, tipo_veiculo, placa_veiculo)
            SELECT id, 'indisponivel', 'Não informado', 'Não informado' FROM usuarios 
            WHERE tipo_usuario = 'caminhoneiro' 
            AND id NOT IN (SELECT usuario_id FROM perfil_caminhoneiro)";
    $result = $conn->exec($sql);
    echo "   {$result} perfis criados para caminhoneiros sem perfil.\n\n";
    
    echo "3. Verificando campos vazios ou NULL...\n";
    // Corrigir valores NULL para o tipo de veículo
    $sql = "UPDATE perfil_caminhoneiro 
            SET tipo_veiculo = 'Não informado' 
            WHERE tipo_veiculo IS NULL OR tipo_veiculo = ''";
    $result = $conn->exec($sql);
    echo "   {$result} perfis atualizados com tipo de veículo.\n";
    
    // Corrigir valores NULL para placa
    $sql = "UPDATE perfil_caminhoneiro 
            SET placa_veiculo = 'Não informado' 
            WHERE placa_veiculo IS NULL OR placa_veiculo = ''";
    $result = $conn->exec($sql);
    echo "   {$result} perfis atualizados com placa de veículo.\n";
    
    // Corrigir valores NULL para capacidade de carga
    $sql = "UPDATE perfil_caminhoneiro 
            SET capacidade_carga = 0 
            WHERE capacidade_carga IS NULL";
    $result = $conn->exec($sql);
    echo "   {$result} perfis atualizados com capacidade de carga.\n";
    
    // Corrigir valores NULL para descrição do veículo
    $sql = "UPDATE perfil_caminhoneiro 
            SET descricao_veiculo = 'Não informado' 
            WHERE descricao_veiculo IS NULL OR descricao_veiculo = ''";
    $result = $conn->exec($sql);
    echo "   {$result} perfis atualizados com descrição do veículo.\n\n";
    
    echo "4. Atualizando informações de localização...\n";
    // Atualizar data de localização para os que não têm
    $sql = "UPDATE perfil_caminhoneiro 
            SET ultima_atualizacao_local = NOW() 
            WHERE ultima_atualizacao_local IS NULL";
    $result = $conn->exec($sql);
    echo "   {$result} perfis atualizados com data de localização.\n\n";
    
    echo "5. Verificando resultados...\n";
    // Contar perfis existentes
    $sql = "SELECT COUNT(*) FROM perfil_caminhoneiro";
    $stmt = $conn->query($sql);
    $total_perfis = $stmt->fetchColumn();
    
    // Contar perfis completos (com todos os campos preenchidos)
    $sql = "SELECT COUNT(*) FROM perfil_caminhoneiro 
            WHERE tipo_veiculo IS NOT NULL 
            AND tipo_veiculo != '' 
            AND placa_veiculo IS NOT NULL 
            AND placa_veiculo != '' 
            AND capacidade_carga IS NOT NULL 
            AND numero_cnh IS NOT NULL 
            AND numero_cnh != ''";
    $stmt = $conn->query($sql);
    $perfis_completos = $stmt->fetchColumn();
    
    echo "   Total de perfis: {$total_perfis}\n";
    echo "   Perfis completos: {$perfis_completos}\n";
    echo "   Perfis incompletos: " . ($total_perfis - $perfis_completos) . "\n\n";
    
    echo "=================================================\n";
    echo "CORREÇÃO CONCLUÍDA COM SUCESSO!\n";
    echo "=================================================\n";
    
} catch (PDOException $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
    exit(1);
} 