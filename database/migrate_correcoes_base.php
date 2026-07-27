<?php
/**
 * FASE 1 — Correções de Base
 * 1. Fix ENUM missoes.status (adicionar emergencia_reportada, entrega_confirmada)
 * 2. Adicionar campos de controle de condução à missoes
 * 3. Criar tabela logs_sistema
 * 4. Criar tabela veiculos (frota profissional)
 * 5. Criar tabela veiculo_documentos
 * 6. Migrar dados de historico_emergencias → emergencias
 */
require_once __DIR__ . '/../config/database.php';
try {
    $conn = getConnection();
    $conn->beginTransaction();

    echo "=== 1. Corrigir ENUM missoes.status ===\n";
    $stmt = $conn->query("SHOW COLUMNS FROM missoes LIKE 'status'");
    $col = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($col && strpos($col['Type'], 'emergencia_reportada') === false) {
        $conn->exec("ALTER TABLE missoes MODIFY COLUMN status ENUM('aberta','em_negociacao','aceita','em_andamento','em_transito','em_entrega','emergencia_reportada','aguardando_confirmacao','entrega_confirmada','concluida','cancelada','emergencia') NULL");
        echo "ENUM missoes.status atualizado.\n";
    } else {
        echo "ENUM ja contem os novos valores.\n";
    }

    echo "\n=== 2. Adicionar campos de controle de condução ===\n";
    $camposConducao = [
        'modo_conducao_ativo' => "TINYINT(1) DEFAULT 0",
        'data_inicio_conducao' => "DATETIME DEFAULT NULL",
        'data_pausa_conducao' => "DATETIME DEFAULT NULL",
        'data_retomada_conducao' => "DATETIME DEFAULT NULL",
        'tempo_conducao_acumulado_seg' => "INT DEFAULT 0 COMMENT 'Tempo total de condução em segundos'",
    ];
    foreach ($camposConducao as $campo => $def) {
        $stmt = $conn->query("SHOW COLUMNS FROM missoes LIKE '$campo'");
        if (!$stmt->fetch()) {
            $conn->exec("ALTER TABLE missoes ADD COLUMN $campo $def");
            echo "Coluna $campo adicionada.\n";
        } else {
            echo "Coluna $campo ja existe.\n";
        }
    }

    echo "\n=== 3. Criar tabela logs_sistema ===\n";
    $conn->exec("CREATE TABLE IF NOT EXISTS logs_sistema (
        id INT AUTO_INCREMENT PRIMARY KEY,
        usuario_id INT DEFAULT NULL,
        tipo_acao VARCHAR(50) NOT NULL,
        entidade VARCHAR(50) NOT NULL,
        entidade_id INT DEFAULT NULL,
        descricao TEXT NOT NULL,
        dados_anteriores JSON DEFAULT NULL,
        ip_address VARCHAR(45) DEFAULT NULL,
        user_agent VARCHAR(255) DEFAULT NULL,
        data_criacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_usuario (usuario_id),
        INDEX idx_entidade (entidade, entidade_id),
        INDEX idx_tipo_acao (tipo_acao),
        INDEX idx_data (data_criacao)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "Tabela logs_sistema criada.\n";

    echo "\n=== 4. Criar tabela veiculos (frota profissional) ===\n";
    $conn->exec("CREATE TABLE IF NOT EXISTS veiculos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        proprietario_id INT NOT NULL COMMENT 'transportador_id ou empresa_id',
        proprietario_tipo ENUM('transportador','empresa') NOT NULL DEFAULT 'transportador',
        matricula VARCHAR(20) NOT NULL,
        marca VARCHAR(50) DEFAULT NULL,
        modelo VARCHAR(50) DEFAULT NULL,
        ano INT DEFAULT NULL,
        chassis VARCHAR(50) DEFAULT NULL,
        capacidade_kg DECIMAL(10,2) DEFAULT NULL,
        peso_bruto_kg DECIMAL(10,2) DEFAULT NULL,
        tipo ENUM('camiao','semi_reboque','reboque','furgao','pickup','motociclo','outro') NOT NULL DEFAULT 'camiao',
        combustivel ENUM('diesel','gasolina','eletrico','hibrido','gasoleo','outro') DEFAULT 'diesel',
        estado_operacional ENUM('ativo','manutencao','inativo','avariado','vendido') NOT NULL DEFAULT 'ativo',
        km_atual INT DEFAULT 0,
        motorista_id INT DEFAULT NULL,
        data_aquisicao DATE DEFAULT NULL,
        observacoes TEXT DEFAULT NULL,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_matricula (matricula),
        INDEX idx_proprietario (proprietario_id, proprietario_tipo),
        INDEX idx_estado (estado_operacional),
        INDEX idx_motorista (motorista_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "Tabela veiculos criada.\n";

    // Migrar veiculos existentes de transportador_veiculos
    $stmt = $conn->query("SELECT COUNT(*) FROM veiculos");
    if ($stmt->fetchColumn() == 0) {
        $conn->exec("INSERT INTO veiculos (proprietario_id, proprietario_tipo, matricula, tipo, capacidade_kg, estado_operacional, criado_em)
            SELECT transportador_id, 'transportador', placa, tipo_veiculo, capacidade_carga,
                   CASE status WHEN 'ativo' THEN 'ativo' WHEN 'manutencao' THEN 'manutencao' ELSE 'inativo' END,
                   COALESCE(data_criacao, NOW())
            FROM transportador_veiculos");
        echo "Veiculos migrados de transportador_veiculos.\n";
    }

    echo "\n=== 5. Criar tabela veiculo_documentos ===\n";
    $conn->exec("CREATE TABLE IF NOT EXISTS veiculo_documentos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        veiculo_id INT NOT NULL,
        tipo ENUM('livrete','titulo','seguro','inspecao','licenca','certificado','outro') NOT NULL,
        numero_documento VARCHAR(100) DEFAULT NULL,
        data_emissao DATE DEFAULT NULL,
        data_validade DATE NOT NULL,
        arquivo_url VARCHAR(500) DEFAULT NULL,
        observacoes TEXT DEFAULT NULL,
        alerta_enviado_30 TINYINT(1) DEFAULT 0,
        alerta_enviado_15 TINYINT(1) DEFAULT 0,
        alerta_enviado_7 TINYINT(1) DEFAULT 0,
        alerta_enviado_expirado TINYINT(1) DEFAULT 0,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_veiculo (veiculo_id),
        INDEX idx_validade (data_validade),
        INDEX idx_tipo (tipo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "Tabela veiculo_documentos criada.\n";

    echo "\n=== 6. Criar tabela manutencoes ===\n";
    $conn->exec("CREATE TABLE IF NOT EXISTS manutencoes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        veiculo_id INT NOT NULL,
        tipo ENUM('preventiva','corretiva','revisao','troca_oleo','pneus','outro') NOT NULL,
        oficina VARCHAR(100) DEFAULT NULL,
        data DATE NOT NULL,
        km_atual INT DEFAULT NULL,
        valor DECIMAL(10,2) DEFAULT 0,
        pecas_substituuidas TEXT DEFAULT NULL,
        observacoes TEXT DEFAULT NULL,
        responsavel VARCHAR(100) DEFAULT NULL,
        proxima_manutencao_km INT DEFAULT NULL,
        proxima_manutencao_data DATE DEFAULT NULL,
        arquivo_url VARCHAR(500) DEFAULT NULL,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_veiculo (veiculo_id),
        INDEX idx_data (data),
        INDEX idx_proxima_km (proxima_manutencao_km)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "Tabela manutencoes criada.\n";

    echo "\n=== 7. Criar tabela abastecimentos ===\n";
    $conn->exec("CREATE TABLE IF NOT EXISTS abastecimentos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        veiculo_id INT NOT NULL,
        motorista_id INT DEFAULT NULL,
        data DATE NOT NULL,
        posto VARCHAR(100) DEFAULT NULL,
        litros DECIMAL(8,2) NOT NULL,
        valor_total DECIMAL(10,2) NOT NULL,
        km_atual INT NOT NULL,
        tipo_combustivel ENUM('diesel','gasolina','eletrico','hibrido','gasoleo','outro') DEFAULT 'diesel',
        observacoes TEXT DEFAULT NULL,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_veiculo (veiculo_id),
        INDEX idx_motorista (motorista_id),
        INDEX idx_data (data)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "Tabela abastecimentos criada.\n";

    echo "\n=== 8. Criar tabela pneus ===\n";
    $conn->exec("CREATE TABLE IF NOT EXISTS pneus (
        id INT AUTO_INCREMENT PRIMARY KEY,
        veiculo_id INT NOT NULL,
        posicao ENUM('dianteiro_esquerdo','dianteiro_direito','traseiro_esquerdo','traseiro_direito','estepe','outro') NOT NULL,
        marca VARCHAR(50) DEFAULT NULL,
        modelo VARCHAR(50) DEFAULT NULL,
        medida VARCHAR(30) DEFAULT NULL,
        data_instalacao DATE DEFAULT NULL,
        data_remocao DATE DEFAULT NULL,
        km_instalacao INT DEFAULT 0,
        km_remocao INT DEFAULT NULL,
        estado ENUM('novo','bom','regular','desgastado','substituido','danificado') DEFAULT 'novo',
        profundidade_mm DECIMAL(4,2) DEFAULT NULL,
        observacoes TEXT DEFAULT NULL,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_veiculo (veiculo_id),
        INDEX idx_estado (estado)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "Tabela pneus criada.\n";

    echo "\n=== 9. Criar tabela motorista_documentos ===\n";
    $conn->exec("CREATE TABLE IF NOT EXISTS motorista_documentos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        motorista_id INT NOT NULL COMMENT 'usuario_id do caminhoneiro',
        tipo ENUM('cnh','certificacao','formacao','exame_medico','outro') NOT NULL,
        numero_documento VARCHAR(100) DEFAULT NULL,
        data_emissao DATE DEFAULT NULL,
        data_validade DATE NOT NULL,
        categoria VARCHAR(20) DEFAULT NULL,
        arquivo_url VARCHAR(500) DEFAULT NULL,
        alerta_enviado_30 TINYINT(1) DEFAULT 0,
        alerta_enviado_15 TINYINT(1) DEFAULT 0,
        alerta_enviado_7 TINYINT(1) DEFAULT 0,
        alerta_enviado_expirado TINYINT(1) DEFAULT 0,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_motorista (motorista_id),
        INDEX idx_validade (data_validade),
        INDEX idx_tipo (tipo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "Tabela motorista_documentos criada.\n";

    echo "\n=== 10. Criar tabela custos_operacionais ===\n";
    $conn->exec("CREATE TABLE IF NOT EXISTS custos_operacionais (
        id INT AUTO_INCREMENT PRIMARY KEY,
        missao_id INT DEFAULT NULL,
        veiculo_id INT DEFAULT NULL,
        tipo ENUM('combustivel','manutencao','portagem','estacionamento','multa','outro') NOT NULL,
        descricao VARCHAR(255) DEFAULT NULL,
        valor DECIMAL(10,2) NOT NULL,
        data DATE NOT NULL,
        comprovante_url VARCHAR(500) DEFAULT NULL,
        registrado_por INT DEFAULT NULL,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_missao (missao_id),
        INDEX idx_veiculo (veiculo_id),
        INDEX idx_tipo (tipo),
        INDEX idx_data (data)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "Tabela custos_operacionais criada.\n";

    echo "\n=== 11. Migrar dados de historico_emergencias ===\n";
    $stmt = $conn->query("SELECT COUNT(*) FROM historico_emergencias");
    $countHist = $stmt->fetchColumn();
    if ($countHist > 0) {
        $conn->exec("INSERT INTO emergencias (missao_id, caminhoneiro_id, tipo, descricao, gravidade, latitude, longitude, status, data_criacao)
            SELECT he.missao_id, he.caminhoneiro_id, 'outro', 'Emergencia reportada (migracao)', 'media',
                   he.latitude, he.longitude, 'resolvida', he.data_registro
            FROM historico_emergencias he
            WHERE NOT EXISTS (SELECT 1 FROM emergencias e WHERE e.missao_id = he.missao_id AND e.caminhoneiro_id = he.caminhoneiro_id AND DATE(e.data_criacao) = DATE(he.data_registro))");
        echo "$countHist registros migrados de historico_emergencias.\n";
    } else {
        echo "Nenhum registro para migrar.\n";
    }

    echo "\n=== 12. Adicionar funcao de log ao sistema ===\n";
    echo "Funcao sera criada em includes/helpers.php.\n";

    $conn->commit();
    echo "\n=== MIGRATION CONCLUIDA COM SUCESSO ===\n";
} catch (Throwable $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    echo "ERRO: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}
