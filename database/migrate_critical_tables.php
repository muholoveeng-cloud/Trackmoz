<?php
/**
 * Migration CRÍTICA - Tabelas Faltantes
 * 
 * Esta migration adiciona as tabelas que são referenciadas no código
 * mas não existem no schema principal, bloqueando funcionalidades críticas.
 * 
 * Tabelas a criar:
 * - veiculos (para caminhoneiros)
 * - destinatarios (para missões)
 * - otp_codes (para confirmação de entrega via OTP)
 * - entregas_confirmacao (para gravar confirmações de entrega)
 * 
 * Também adiciona campos faltantes na tabela missoes para:
 * - Modo condução
 * - Confirmação de entrega
 * - Relacionamentos com veículos e destinatários
 */

include_once __DIR__ . '/../config/database.php';

echo "=== Migration CRÍTICA - Tabelas Faltantes ===\n\n";

try {
    $conn = getConnection();
    
    // 1. Criar tabela veiculos
    echo "1. Criando tabela 'veiculos'...\n";
    $conn->exec("
        CREATE TABLE IF NOT EXISTS veiculos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT NOT NULL,
            placa VARCHAR(20) NOT NULL UNIQUE,
            tipo_veiculo VARCHAR(50) DEFAULT NULL,
            marca VARCHAR(100) DEFAULT NULL,
            modelo VARCHAR(100) DEFAULT NULL,
            ano INT DEFAULT NULL,
            capacidade_carga DECIMAL(10,2) DEFAULT NULL,
            cor VARCHAR(50) DEFAULT NULL,
            status ENUM('ativo','inativo','manutencao') DEFAULT 'ativo',
            data_criacao TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            data_atualizacao TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_usuario_id (usuario_id),
            KEY idx_placa (placa)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "   ✓ Tabela 'veiculos' criada com sucesso\n\n";
    
    // 2. Criar tabela destinatarios
    echo "2. Criando tabela 'destinatarios'...\n";
    $conn->exec("
        CREATE TABLE IF NOT EXISTS destinatarios (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(100) NOT NULL,
            telefone VARCHAR(20) DEFAULT NULL,
            email VARCHAR(100) DEFAULT NULL,
            documento VARCHAR(50) DEFAULT NULL,
            endereco VARCHAR(255) DEFAULT NULL,
            cidade VARCHAR(100) DEFAULT NULL,
            provincia VARCHAR(100) DEFAULT NULL,
            data_criacao TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_nome (nome)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "   ✓ Tabela 'destinatarios' criada com sucesso\n\n";
    
    // 3. Criar tabela otp_codes
    echo "3. Criando tabela 'otp_codes'...\n";
    $conn->exec("
        CREATE TABLE IF NOT EXISTS otp_codes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            missao_id INT NOT NULL,
            codigo VARCHAR(6) NOT NULL,
            gerado_em TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            expira_em TIMESTAMP NULL NOT NULL,
            usado TINYINT(1) DEFAULT 0,
            usado_em TIMESTAMP NULL DEFAULT NULL,
            usado_por VARCHAR(100) DEFAULT NULL,
            KEY idx_missao_id (missao_id),
            KEY idx_codigo (codigo),
            KEY idx_expira_em (expira_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "   ✓ Tabela 'otp_codes' criada com sucesso\n\n";
    
    // 4. Criar tabela entregas_confirmacao
    echo "4. Criando tabela 'entregas_confirmacao'...\n";
    $conn->exec("
        CREATE TABLE IF NOT EXISTS entregas_confirmacao (
            id INT AUTO_INCREMENT PRIMARY KEY,
            missao_id INT NOT NULL,
            motorista_id INT NOT NULL,
            empresa_id INT NOT NULL,
            metodo ENUM('otp','destinatario_cadastrado','manual_assistida') NOT NULL,
            nome_recebedor VARCHAR(100) DEFAULT NULL,
            documento_recebedor VARCHAR(50) DEFAULT NULL,
            telefone_recebedor VARCHAR(20) DEFAULT NULL,
            assinatura_url VARCHAR(255) DEFAULT NULL,
            foto_carga_url VARCHAR(255) DEFAULT NULL,
            foto_doc_url VARCHAR(255) DEFAULT NULL,
            otp_usado VARCHAR(6) DEFAULT NULL,
            latitude DECIMAL(10,8) DEFAULT NULL,
            longitude DECIMAL(10,8) DEFAULT NULL,
            estado_carga ENUM('sem_danos','com_danos','parcial','recusada') DEFAULT 'sem_danos',
            observacoes TEXT DEFAULT NULL,
            data_confirmacao TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_missao_id (missao_id),
            KEY idx_motorista_id (motorista_id),
            KEY idx_empresa_id (empresa_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "   ✓ Tabela 'entregas_confirmacao' criada com sucesso\n\n";
    
    // 5. Adicionar campos faltantes em missoes
    echo "5. Adicionando campos de modo condução em 'missoes'...\n";
    
    $columns = $conn->query("SHOW COLUMNS FROM missoes")->fetchAll(PDO::FETCH_COLUMN);
    
    if (!in_array('modo_conducao_ativo', $columns)) {
        $conn->exec("ALTER TABLE missoes ADD COLUMN modo_conducao_ativo TINYINT(1) DEFAULT 0");
        echo "   ✓ Campo 'modo_conducao_ativo' adicionado\n";
    } else {
        echo "   - Campo 'modo_conducao_ativo' já existe\n";
    }
    
    if (!in_array('data_inicio_conducao', $columns)) {
        $conn->exec("ALTER TABLE missoes ADD COLUMN data_inicio_conducao TIMESTAMP NULL DEFAULT NULL");
        echo "   ✓ Campo 'data_inicio_conducao' adicionado\n";
    } else {
        echo "   - Campo 'data_inicio_conducao' já existe\n";
    }
    
    if (!in_array('data_pausa_conducao', $columns)) {
        $conn->exec("ALTER TABLE missoes ADD COLUMN data_pausa_conducao TIMESTAMP NULL DEFAULT NULL");
        echo "   ✓ Campo 'data_pausa_conducao' adicionado\n";
    } else {
        echo "   - Campo 'data_pausa_conducao' já existe\n";
    }
    
    if (!in_array('data_retomada_conducao', $columns)) {
        $conn->exec("ALTER TABLE missoes ADD COLUMN data_retomada_conducao TIMESTAMP NULL DEFAULT NULL");
        echo "   ✓ Campo 'data_retomada_conducao' adicionado\n";
    } else {
        echo "   - Campo 'data_retomada_conducao' já existe\n";
    }
    
    if (!in_array('tempo_conducao_acumulado_seg', $columns)) {
        $conn->exec("ALTER TABLE missoes ADD COLUMN tempo_conducao_acumulado_seg INT DEFAULT 0");
        echo "   ✓ Campo 'tempo_conducao_acumulado_seg' adicionado\n";
    } else {
        echo "   - Campo 'tempo_conducao_acumulado_seg' já existe\n";
    }
    
    echo "\n";
    
    echo "6. Adicionando campos de confirmação de entrega em 'missoes'...\n";
    
    if (!in_array('modo_confirmacao_entrega', $columns)) {
        $conn->exec("ALTER TABLE missoes ADD COLUMN modo_confirmacao_entrega ENUM('otp','destinatario_cadastrado','manual_assistida') DEFAULT 'manual_assistida'");
        echo "   ✓ Campo 'modo_confirmacao_entrega' adicionado\n";
    } else {
        echo "   - Campo 'modo_confirmacao_entrega' já existe\n";
    }
    
    echo "\n";
    
    echo "7. Adicionando campos de relacionamento em 'missoes'...\n";
    
    if (!in_array('veiculo_id', $columns)) {
        $conn->exec("ALTER TABLE missoes ADD COLUMN veiculo_id INT DEFAULT NULL");
        $conn->exec("ALTER TABLE missoes ADD KEY idx_veiculo_id (veiculo_id)");
        echo "   ✓ Campo 'veiculo_id' adicionado\n";
    } else {
        echo "   - Campo 'veiculo_id' já existe\n";
    }
    
    if (!in_array('destinatario_id', $columns)) {
        $conn->exec("ALTER TABLE missoes ADD COLUMN destinatario_id INT DEFAULT NULL");
        $conn->exec("ALTER TABLE missoes ADD KEY idx_destinatario_id (destinatario_id)");
        echo "   ✓ Campo 'destinatario_id' adicionado\n";
    } else {
        echo "   - Campo 'destinatario_id' já existe\n";
    }
    
    echo "\n";
    echo "=== Migration CRÍTICA concluída com sucesso ===\n";
    echo "\nResumo:\n";
    echo "- Tabela 'veiculos': criada\n";
    echo "- Tabela 'destinatarios': criada\n";
    echo "- Tabela 'otp_codes': criada\n";
    echo "- Tabela 'entregas_confirmacao': criada\n";
    echo "- Campos de modo condução em 'missoes': adicionados\n";
    echo "- Campos de confirmação de entrega em 'missoes': adicionados\n";
    echo "- Campos de relacionamento em 'missoes': adicionados\n";
    
} catch (PDOException $e) {
    echo "\n❌ ERRO na migration: " . $e->getMessage() . "\n";
    exit(1);
}
