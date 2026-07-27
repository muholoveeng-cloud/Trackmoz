<?php
/**
 * MIGRAÇÃO: Parceria Profissional + Financeiro + Documentos
 * Expande parcerias, cria negociações, facturas, pagamentos, explorador de documentos
 */
require_once __DIR__ . '/../config/database.php';

try {
    $conn = getConnection();
    $conn->beginTransaction();

    echo "=== 1. Expandir tabela parcerias ===\n";

    // Novos estados da parceria
    $conn->exec("ALTER TABLE parcerias
        MODIFY status ENUM('rascunho','pedido_enviado','em_negociacao',
            'aguardando_aprovacao_empresa','aguardando_aprovacao_transportador',
            'aguardando_validacao_admin','ativa','suspensa','expirada','cancelada',
            'pendente','terminada','rejeitada') NOT NULL DEFAULT 'rascunho'");
    echo "Status ENUM expandido.\n";

    $novosCamposParceria = [
        'tipo_contrato' => "ENUM('por_missao','por_km','mensalidade','misto','tabela') DEFAULT 'por_missao'",
        'valor_missao' => "DECIMAL(12,2) DEFAULT NULL",
        'valor_km' => "DECIMAL(10,4) DEFAULT NULL",
        'valor_mensal' => "DECIMAL(12,2) DEFAULT NULL",
        'comissao_plataforma_pct' => "DECIMAL(5,2) DEFAULT 0",
        'condicoes_pagamento' => "VARCHAR(100) DEFAULT '30_dias'",
        'sla_resposta_horas' => "INT DEFAULT 24",
        'penalidade_atraso_pct' => "DECIMAL(5,2) DEFAULT 0",
        'responsabilidade_carga' => "ENUM('contratante','transportador','seguro') DEFAULT 'seguro'",
        'tipos_carga_permitidos' => "TEXT DEFAULT NULL",
        'rotas_cobertas' => "TEXT DEFAULT NULL",
        'documento_contrato_url' => "VARCHAR(500) DEFAULT NULL",
        'versao_contrato' => "INT DEFAULT 1",
        'aprovado_por_empresa' => "TINYINT(1) DEFAULT 0",
        'aprovado_por_transportador' => "TINYINT(1) DEFAULT 0",
        'validado_por_admin' => "TINYINT(1) DEFAULT 0",
        'data_aprovacao_empresa' => "DATETIME DEFAULT NULL",
        'data_aprovacao_transportador' => "DATETIME DEFAULT NULL",
        'data_validacao_admin' => "DATETIME DEFAULT NULL",
        'requer_validacao_admin' => "TINYINT(1) DEFAULT 0",
        'observacoes_negociacao' => "TEXT DEFAULT NULL",
    ];

    foreach ($novosCamposParceria as $campo => $def) {
        $stmt = $conn->query("SHOW COLUMNS FROM parcerias LIKE '$campo'");
        if (!$stmt->fetch()) {
            $conn->exec("ALTER TABLE parcerias ADD COLUMN $campo $def");
            echo "Coluna $campo adicionada.\n";
        } else {
            echo "Coluna $campo ja existe.\n";
        }
    }

    // Migrar status existentes
    $conn->exec("UPDATE parcerias SET status = 'pedido_enviado' WHERE status = 'pendente' AND aprovado_por_empresa = 0 AND aprovado_por_transportador = 0");
    $conn->exec("UPDATE parcerias SET status = 'ativa' WHERE status = 'terminada' AND aprovado_por_empresa = 1 AND aprovado_por_transportador = 1");
    echo "Status migrados.\n";

    echo "\n=== 2. Criar tabela parceria_negociacoes ===\n";
    $conn->exec("CREATE TABLE IF NOT EXISTS parceria_negociacoes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        parceria_id INT NOT NULL,
        proposto_por ENUM('empresa','transportador','admin') NOT NULL,
        proposto_por_usuario_id INT NOT NULL,
        versao INT NOT NULL DEFAULT 1,
        campo_alterado VARCHAR(50) DEFAULT NULL,
        valor_anterior TEXT DEFAULT NULL,
        valor_novo TEXT DEFAULT NULL,
        comentario TEXT DEFAULT NULL,
        documento_url VARCHAR(500) DEFAULT NULL,
        data_criacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_parceria (parceria_id),
        INDEX idx_versao (parceria_id, versao)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "Tabela parceria_negociacoes criada.\n";

    echo "\n=== 3. Criar tabela facturas ===\n";
    $conn->exec("CREATE TABLE IF NOT EXISTS facturas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        missao_id INT NOT NULL,
        parceria_id INT DEFAULT NULL,
        empresa_id INT NOT NULL COMMENT 'contratante',
        transportador_id INT NOT NULL,
        numero_factura VARCHAR(50) NOT NULL,
        data_emissao DATE NOT NULL,
        data_vencimento DATE NOT NULL,
        descricao_servico TEXT DEFAULT NULL,
        origem VARCHAR(255) DEFAULT NULL,
        destino VARCHAR(255) DEFAULT NULL,
        distancia_km DECIMAL(10,2) DEFAULT NULL,
        valor_base DECIMAL(12,2) NOT NULL DEFAULT 0,
        valor_km DECIMAL(12,2) DEFAULT 0,
        taxas DECIMAL(12,2) DEFAULT 0,
        penalidades DECIMAL(12,2) DEFAULT 0,
        descontos DECIMAL(12,2) DEFAULT 0,
        comissao_plataforma DECIMAL(12,2) DEFAULT 0,
        imposto DECIMAL(12,2) DEFAULT 0,
        valor_total DECIMAL(12,2) NOT NULL DEFAULT 0,
        status ENUM('proforma','emitida','enviada','aprovada','paga','atrasada','cancelada','em_disputa') DEFAULT 'proforma',
        aprovada_por_empresa TINYINT(1) DEFAULT 0,
        documento_url VARCHAR(500) DEFAULT NULL,
        observacoes TEXT DEFAULT NULL,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_numero (numero_factura),
        INDEX idx_missao (missao_id),
        INDEX idx_parceria (parceria_id),
        INDEX idx_empresa (empresa_id),
        INDEX idx_transportador (transportador_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "Tabela facturas criada.\n";

    echo "\n=== 4. Criar tabela pagamentos_missao ===\n";
    $conn->exec("CREATE TABLE IF NOT EXISTS pagamentos_missao (
        id INT AUTO_INCREMENT PRIMARY KEY,
        missao_id INT NOT NULL,
        factura_id INT DEFAULT NULL,
        parceria_id INT DEFAULT NULL,
        empresa_id INT NOT NULL,
        transportador_id INT NOT NULL,
        tipo_pagamento ENUM('por_missao','por_km','mensalidade','misto') NOT NULL,
        valor_base DECIMAL(12,2) NOT NULL DEFAULT 0,
        valor_km DECIMAL(12,2) DEFAULT 0,
        distancia_km DECIMAL(10,2) DEFAULT NULL,
        taxas DECIMAL(12,2) DEFAULT 0,
        penalidades DECIMAL(12,2) DEFAULT 0,
        descontos DECIMAL(12,2) DEFAULT 0,
        comissao_plataforma DECIMAL(12,2) DEFAULT 0,
        imposto DECIMAL(12,2) DEFAULT 0,
        valor_total DECIMAL(12,2) NOT NULL DEFAULT 0,
        status ENUM('pendente','aguardando_conclusao','aguardando_entrega',
            'facturado','aguardando_pagamento','pago_parcialmente','pago',
            'em_atraso','cancelado','em_disputa') DEFAULT 'pendente',
        data_vencimento DATE DEFAULT NULL,
        data_pagamento DATE DEFAULT NULL,
        comprovativo_url VARCHAR(500) DEFAULT NULL,
        observacoes TEXT DEFAULT NULL,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_missao (missao_id),
        INDEX idx_factura (factura_id),
        INDEX idx_status (status),
        INDEX idx_empresa (empresa_id),
        INDEX idx_transportador (transportador_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "Tabela pagamentos_missao criada.\n";

    echo "\n=== 5. Criar tabela documentos_explorador ===\n";
    $conn->exec("CREATE TABLE IF NOT EXISTS documentos_explorador (
        id INT AUTO_INCREMENT PRIMARY KEY,
        entidade_tipo ENUM('missao','contrato','factura','recibo',
            'prova_entrega','guia_transporte','documento_carga',
            'documento_frota','documento_motorista','comprovativo_pagamento',
            'outro') NOT NULL,
        entidade_id INT DEFAULT NULL,
        entidade_subtipo VARCHAR(50) DEFAULT NULL,
        titulo VARCHAR(255) NOT NULL,
        descricao TEXT DEFAULT NULL,
        arquivo_url VARCHAR(500) NOT NULL,
        arquivo_tipo VARCHAR(100) DEFAULT NULL,
        arquivo_tamanho INT DEFAULT NULL,
        usuario_id INT DEFAULT NULL,
        visibilidade ENUM('publico','privado','empresa','transportador','admin') DEFAULT 'privado',
        relacionado_missao_id INT DEFAULT NULL,
        relacionado_contrato_id INT DEFAULT NULL,
        tags VARCHAR(255) DEFAULT NULL,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_entidade (entidade_tipo, entidade_id),
        INDEX idx_usuario (usuario_id),
        INDEX idx_missao (relacionado_missao_id),
        INDEX idx_contrato (relacionado_contrato_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "Tabela documentos_explorador criada.\n";

    echo "\n=== 6. Adicionar campos financeiros e operacionais a missoes ===\n";
    $camposMissao = [
        'valor_base' => "DECIMAL(12,2) DEFAULT NULL",
        'valor_km' => "DECIMAL(10,4) DEFAULT NULL",
        'distancia_km' => "DECIMAL(10,2) DEFAULT NULL",
        'taxas' => "DECIMAL(12,2) DEFAULT 0",
        'penalidades' => "DECIMAL(12,2) DEFAULT 0",
        'descontos' => "DECIMAL(12,2) DEFAULT 0",
        'comissao_plataforma' => "DECIMAL(12,2) DEFAULT 0",
        'imposto' => "DECIMAL(12,2) DEFAULT 0",
        'valor_total' => "DECIMAL(12,2) DEFAULT NULL",
        'tipo_carga' => "VARCHAR(100) DEFAULT NULL",
        'peso_kg' => "DECIMAL(10,2) DEFAULT NULL",
        'volume_m3' => "DECIMAL(10,3) DEFAULT NULL",
        'transportador_id' => "INT DEFAULT NULL",
        'veiculo_id' => "INT DEFAULT NULL",
        'motorista_id' => "INT DEFAULT NULL",
        'data_atribuicao_transportador' => "DATETIME DEFAULT NULL",
        'data_atribuicao_motorista' => "DATETIME DEFAULT NULL",
        'data_atribuicao_veiculo' => "DATETIME DEFAULT NULL",
        'previsao_recolha' => "DATETIME DEFAULT NULL",
        'previsao_entrega' => "DATETIME DEFAULT NULL",
        'instrucoes_especiais' => "TEXT DEFAULT NULL",
        'delegada_por' => "INT DEFAULT NULL COMMENT 'empresa_id que delegou'",
        'delegada_para' => "INT DEFAULT NULL COMMENT 'transportador_id delegado'",
        'delegada_em' => "DATETIME DEFAULT NULL",
        'delegada_mensagem' => "TEXT DEFAULT NULL",
    ];
    foreach ($camposMissao as $campo => $def) {
        $stmt = $conn->query("SHOW COLUMNS FROM missoes LIKE '$campo'");
        if (!$stmt->fetch()) {
            $conn->exec("ALTER TABLE missoes ADD COLUMN $campo $def");
            echo "Coluna $campo adicionada a missoes.\n";
        } else {
            echo "Coluna $campo ja existe em missoes.\n";
        }
    }

    echo "\n=== 7. Adicionar campos fiscais a perfil_empresa e perfil_transportador ===\n";
    $stmt = $conn->query("SHOW COLUMNS FROM perfil_empresa LIKE 'nuit'");
    if (!$stmt->fetch()) {
        $conn->exec("ALTER TABLE perfil_empresa ADD COLUMN nuit VARCHAR(20) DEFAULT NULL, ADD COLUMN endereco_fiscal TEXT DEFAULT NULL, ADD COLUMN cidade_sede VARCHAR(100) DEFAULT NULL");
        echo "Campos fiscais adicionados a perfil_empresa.\n";
    } else {
        echo "Campos fiscais ja existem em perfil_empresa.\n";
    }
    $stmt = $conn->query("SHOW COLUMNS FROM perfil_transportador LIKE 'nuit'");
    if (!$stmt->fetch()) {
        $conn->exec("ALTER TABLE perfil_transportador ADD COLUMN nuit VARCHAR(20) DEFAULT NULL, ADD COLUMN endereco_fiscal TEXT DEFAULT NULL, ADD COLUMN cidade_sede VARCHAR(100) DEFAULT NULL");
        echo "Campos fiscais adicionados a perfil_transportador.\n";
    } else {
        echo "Campos fiscais ja existem em perfil_transportador.\n";
    }

    echo "\n=== 8. Atualizar ENUM notificacoes ===\n";
    $conn->exec("ALTER TABLE notificacoes
        MODIFY tipo ENUM('missao','proposta','proposta_aceita','mensagem','avaliacao',
            'sistema','confirmacao_entrega','emergencia','documento','parceria',
            'contrato_negociacao','contrato_aprovado','factura','pagamento',
            'motorista_atribuido','viatura_atribuida','missao_recebida') NOT NULL");
    echo "ENUM notificacoes atualizado.\n";

    $conn->commit();
    echo "\n=== MIGRACAO CONCLUIDA COM SUCESSO ===\n";
} catch (Throwable $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    echo "ERRO: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}
