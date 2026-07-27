<?php
require_once __DIR__ . '/../config/database.php';
try {
    $conn = getConnection();

    // ── 1. Emergências (substitui historico_emergencias) ──
    $conn->exec("CREATE TABLE IF NOT EXISTS emergencias (
        id INT AUTO_INCREMENT PRIMARY KEY,
        missao_id INT NOT NULL,
        caminhoneiro_id INT NOT NULL,
        tipo ENUM('acidente','avaria','furo','problema_carga','roubo','saude','fiscalizacao','atraso_grave','outro') NOT NULL,
        descricao TEXT NOT NULL,
        gravidade ENUM('baixa','media','alta','critica') NOT NULL DEFAULT 'media',
        latitude DECIMAL(10,8) DEFAULT NULL,
        longitude DECIMAL(11,8) DEFAULT NULL,
        anexo_url VARCHAR(500) DEFAULT NULL,
        anexo_tipo VARCHAR(100) DEFAULT NULL,
        status ENUM('aberta','em_atendimento','resolvida','cancelada') NOT NULL DEFAULT 'aberta',
        resposta_admin TEXT DEFAULT NULL,
        resolvido_por INT DEFAULT NULL,
        data_criacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        data_atualizacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_missao (missao_id),
        INDEX idx_caminhoneiro (caminhoneiro_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "Tabela emergencias criada.\n";

    // ── 2. OTP Codes para entrega ──
    $conn->exec("CREATE TABLE IF NOT EXISTS otp_codes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        missao_id INT NOT NULL UNIQUE,
        codigo VARCHAR(10) NOT NULL,
        destinatario_telefone VARCHAR(20) DEFAULT NULL,
        destinatario_email VARCHAR(120) DEFAULT NULL,
        expira_em TIMESTAMP NOT NULL,
        usado TINYINT(1) DEFAULT 0,
        usado_em TIMESTAMP NULL DEFAULT NULL,
        usado_por VARCHAR(255) DEFAULT NULL,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_missao (missao_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "Tabela otp_codes criada.\n";

    // ── 3. Confirmação de entrega (ePOD) ──
    $conn->exec("CREATE TABLE IF NOT EXISTS entregas_confirmacao (
        id INT AUTO_INCREMENT PRIMARY KEY,
        missao_id INT NOT NULL,
        motorista_id INT NOT NULL,
        empresa_id INT NOT NULL,
        metodo ENUM('otp','destinatario_cadastrado','manual_assistida') NOT NULL,
        nome_recebedor VARCHAR(255) DEFAULT NULL,
        documento_recebedor VARCHAR(50) DEFAULT NULL,
        telefone_recebedor VARCHAR(20) DEFAULT NULL,
        assinatura_url VARCHAR(500) DEFAULT NULL,
        foto_carga_url VARCHAR(500) DEFAULT NULL,
        foto_doc_url VARCHAR(500) DEFAULT NULL,
        otp_usado VARCHAR(10) DEFAULT NULL,
        latitude DECIMAL(10,8) DEFAULT NULL,
        longitude DECIMAL(11,8) DEFAULT NULL,
        estado_carga ENUM('sem_danos','com_danos','parcial','recusada') DEFAULT 'sem_danos',
        observacoes TEXT DEFAULT NULL,
        data_confirmacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_missao (missao_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "Tabela entregas_confirmacao criada.\n";

    // ── 4. Avaliações do destinatário ──
    $conn->exec("CREATE TABLE IF NOT EXISTS avaliacoes_entrega (
        id INT AUTO_INCREMENT PRIMARY KEY,
        missao_id INT NOT NULL,
        entrega_id INT NOT NULL,
        nota_geral TINYINT UNSIGNED DEFAULT NULL,
        nota_pontualidade TINYINT UNSIGNED DEFAULT NULL,
        nota_estado_carga TINYINT UNSIGNED DEFAULT NULL,
        nota_comunicacao TINYINT UNSIGNED DEFAULT NULL,
        comentario TEXT DEFAULT NULL,
        problema_reportado TEXT DEFAULT NULL,
        data_criacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_missao (missao_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "Tabela avaliacoes_entrega criada.\n";

    // ── 5. Destinatários cadastrados (clientes frequentes) ──
    $conn->exec("CREATE TABLE IF NOT EXISTS destinatarios (
        id INT AUTO_INCREMENT PRIMARY KEY,
        empresa_id INT DEFAULT NULL,
        nome VARCHAR(255) NOT NULL,
        telefone VARCHAR(20) DEFAULT NULL,
        email VARCHAR(120) DEFAULT NULL,
        nuit_documento VARCHAR(50) DEFAULT NULL,
        endereco TEXT DEFAULT NULL,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_empresa (empresa_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "Tabela destinatarios criada.\n";

    // ── 6. Adicionar campo modo_confirmacao_entrega à missao ──
    $cols = $conn->query("SHOW COLUMNS FROM missoes LIKE 'modo_confirmacao_entrega'")->fetch();
    if (!$cols) {
        $conn->exec("ALTER TABLE missoes ADD COLUMN modo_confirmacao_entrega ENUM('otp','destinatario_cadastrado','manual_assistida') DEFAULT 'manual_assistida'");
        echo "Coluna modo_confirmacao_entrega adicionada a missoes.\n";
    }

    // ── 7. Adicionar campo destinatario_id à missao ──
    $cols = $conn->query("SHOW COLUMNS FROM missoes LIKE 'destinatario_id'")->fetch();
    if (!$cols) {
        $conn->exec("ALTER TABLE missoes ADD COLUMN destinatario_id INT DEFAULT NULL AFTER modo_confirmacao_entrega");
        echo "Coluna destinatario_id adicionada a missoes.\n";
    }

    echo "\nMigration fluxo operacional concluida com sucesso.\n";
} catch (Throwable $e) {
    echo "Erro: " . $e->getMessage() . "\n";
}
