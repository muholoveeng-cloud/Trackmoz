<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/documento-profissional.php';

function tmz_docs_bootstrap(PDO $conn): void
{
    static $bootstrapped = false;
    if ($bootstrapped) {
        return;
    }

    $conn->exec(
        "CREATE TABLE IF NOT EXISTS documentos_oficiais_missao (
            id INT AUTO_INCREMENT PRIMARY KEY,
            missao_id INT NOT NULL,
            tipo_documento VARCHAR(60) NOT NULL,
            emitido_em DATETIME NOT NULL,
            emitido_por_usuario_id INT NOT NULL,
            bloqueado TINYINT(1) NOT NULL DEFAULT 1,
            UNIQUE KEY uniq_doc_missao_tipo (missao_id, tipo_documento),
            KEY idx_emitido_por (emitido_por_usuario_id),
            KEY idx_emitido_em (emitido_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $conn->exec(
        "CREATE TABLE IF NOT EXISTS documentos_sistema (
            id INT AUTO_INCREMENT PRIMARY KEY,
            titulo VARCHAR(180) NOT NULL,
            tipo VARCHAR(50) NOT NULL,
            numero_documento VARCHAR(80) NOT NULL,
            tracking_id VARCHAR(100) NOT NULL,
            status ENUM('gerado','assinado','cancelado','arquivado') NOT NULL DEFAULT 'gerado',
            data_emissao DATETIME NOT NULL,
            caminho_ficheiro VARCHAR(255) DEFAULT NULL,
            url_visualizacao VARCHAR(255) DEFAULT NULL,
            criado_por INT NOT NULL,
            empresa_id INT DEFAULT NULL,
            cliente_id INT DEFAULT NULL,
            missao_id INT DEFAULT NULL,
            parceria_id INT DEFAULT NULL,
            transportador_id INT DEFAULT NULL,
            condutor_id INT DEFAULT NULL,
            viatura_ref VARCHAR(120) DEFAULT NULL,
            payload_json LONGTEXT DEFAULT NULL,
            data_criacao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            data_atualizacao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_numero_documento (numero_documento),
            UNIQUE KEY uniq_tracking_id (tracking_id),
            KEY idx_tipo_status (tipo, status),
            KEY idx_missao (missao_id),
            KEY idx_empresa (empresa_id),
            KEY idx_parceria (parceria_id),
            KEY idx_transportador (transportador_id),
            KEY idx_data_emissao (data_emissao)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // Migração leve para BDs já existentes
    try {
        if (!table_has_column($conn, 'documentos_sistema', 'parceria_id')) {
            $conn->exec('ALTER TABLE documentos_sistema ADD COLUMN parceria_id INT DEFAULT NULL AFTER missao_id, ADD KEY idx_parceria (parceria_id)');
        }
        if (!table_has_column($conn, 'documentos_sistema', 'transportador_id')) {
            $conn->exec('ALTER TABLE documentos_sistema ADD COLUMN transportador_id INT DEFAULT NULL AFTER parceria_id, ADD KEY idx_transportador (transportador_id)');
        }
    } catch (Throwable $e) {
        error_log('tmz_docs_bootstrap migrate: ' . $e->getMessage());
    }

    $bootstrapped = true;
}

/**
 * Prefixos por tipo para numeração formal.
 */
function tmz_docs_prefix(string $tipo): string
{
    return match ($tipo) {
        'contrato_transporte'      => 'CTR',
        'contrato_parceria'        => 'CPA',
        'ordem_transporte'         => 'GUI',
        'comprovativo_conclusao'   => 'CMP',
        'missao_registo'           => 'MIS',
        'relatorio'                => 'REL',
        'fatura'                   => 'FAT',
        'recibo'                   => 'RCB',
        'termo_responsabilidade'   => 'TRM',
        'relatorio_incidente'      => 'INC',
        'avaliacao'                => 'AVL',
        default                    => 'DOC',
    };
}

function tmz_docs_next_number(PDO $conn, string $tipo): string
{
    tmz_docs_bootstrap($conn);
    $prefix = tmz_docs_prefix($tipo);
    $year = date('Y');

    $stmt = $conn->prepare(
        "SELECT numero_documento
         FROM documentos_sistema
         WHERE tipo = :tipo AND numero_documento LIKE :prefix
         ORDER BY id DESC
         LIMIT 1"
    );
    $stmt->execute([
        ':tipo' => $tipo,
        ':prefix' => $prefix . '-' . $year . '-%',
    ]);
    $last = (string)($stmt->fetchColumn() ?: '');

    $seq = 1;
    if ($last !== '' && preg_match('/-(\d{5})$/', $last, $m)) {
        $seq = (int)$m[1] + 1;
    }

    return sprintf('%s-%s-%05d', $prefix, $year, $seq);
}

/**
 * Upsert lógico para evitar duplicação desnecessária por (tipo + missão + empresa + número).
 */
function tmz_docs_register(PDO $conn, array $data): int
{
    tmz_docs_bootstrap($conn);

    $required = ['titulo', 'tipo', 'numero_documento', 'tracking_id', 'criado_por'];
    foreach ($required as $field) {
        if (!isset($data[$field]) || trim((string)$data[$field]) === '') {
            throw new InvalidArgumentException("Campo obrigatório ausente: {$field}");
        }
    }

    $parceriaId = isset($data['parceria_id']) ? (int)$data['parceria_id'] : null;
    if ($parceriaId !== null && $parceriaId <= 0) {
        $parceriaId = null;
    }

    // Evitar duplicados: por número OU por (tipo + missão) OU por (tipo + parceria)
    $existingId = null;
    if ($parceriaId !== null && ($data['tipo'] ?? '') === 'contrato_parceria') {
        $stmt = $conn->prepare(
            "SELECT id FROM documentos_sistema
             WHERE tipo = 'contrato_parceria' AND parceria_id = :parceria_id
             LIMIT 1"
        );
        $stmt->execute([':parceria_id' => $parceriaId]);
        $existingId = $stmt->fetchColumn();
    }
    if (!$existingId && !empty($data['missao_id'])) {
        $stmt = $conn->prepare(
            "SELECT id FROM documentos_sistema
             WHERE tipo = :tipo AND missao_id = :missao_id
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([
            ':tipo' => $data['tipo'],
            ':missao_id' => (int)$data['missao_id'],
        ]);
        $existingId = $stmt->fetchColumn();
    }
    if (!$existingId) {
        $stmt = $conn->prepare(
            "SELECT id FROM documentos_sistema
             WHERE tipo = :tipo
               AND numero_documento = :numero_documento
               AND (missao_id <=> :missao_id)
               AND (empresa_id <=> :empresa_id)
               AND (parceria_id <=> :parceria_id)
             LIMIT 1"
        );
        $stmt->execute([
            ':tipo' => $data['tipo'],
            ':numero_documento' => $data['numero_documento'],
            ':missao_id' => $data['missao_id'] ?? null,
            ':empresa_id' => $data['empresa_id'] ?? null,
            ':parceria_id' => $parceriaId,
        ]);
        $existingId = $stmt->fetchColumn();
    }

    if ($existingId) {
        $upd = $conn->prepare(
            "UPDATE documentos_sistema
             SET status = :status,
                 data_emissao = :data_emissao,
                 caminho_ficheiro = :caminho_ficheiro,
                 url_visualizacao = :url_visualizacao,
                 criado_por = :criado_por,
                 cliente_id = :cliente_id,
                 transportador_id = COALESCE(:transportador_id, transportador_id),
                 parceria_id = COALESCE(:parceria_id, parceria_id),
                 condutor_id = :condutor_id,
                 viatura_ref = :viatura_ref,
                 payload_json = :payload_json
             WHERE id = :id"
        );
        $upd->execute([
            ':status' => $data['status'] ?? 'gerado',
            ':data_emissao' => $data['data_emissao'] ?? date('Y-m-d H:i:s'),
            ':caminho_ficheiro' => $data['caminho_ficheiro'] ?? null,
            ':url_visualizacao' => $data['url_visualizacao'] ?? null,
            ':criado_por' => (int)$data['criado_por'],
            ':cliente_id' => $data['cliente_id'] ?? null,
            ':transportador_id' => $data['transportador_id'] ?? null,
            ':parceria_id' => $parceriaId,
            ':condutor_id' => $data['condutor_id'] ?? null,
            ':viatura_ref' => $data['viatura_ref'] ?? null,
            ':payload_json' => $data['payload_json'] ?? null,
            ':id' => (int)$existingId,
        ]);
        return (int)$existingId;
    }

    $ins = $conn->prepare(
        "INSERT INTO documentos_sistema
         (titulo, tipo, numero_documento, tracking_id, status, data_emissao, caminho_ficheiro, url_visualizacao,
          criado_por, empresa_id, cliente_id, missao_id, parceria_id, transportador_id, condutor_id, viatura_ref, payload_json)
         VALUES
         (:titulo, :tipo, :numero_documento, :tracking_id, :status, :data_emissao, :caminho_ficheiro, :url_visualizacao,
          :criado_por, :empresa_id, :cliente_id, :missao_id, :parceria_id, :transportador_id, :condutor_id, :viatura_ref, :payload_json)"
    );
    $ins->execute([
        ':titulo' => $data['titulo'],
        ':tipo' => $data['tipo'],
        ':numero_documento' => $data['numero_documento'],
        ':tracking_id' => $data['tracking_id'],
        ':status' => $data['status'] ?? 'gerado',
        ':data_emissao' => $data['data_emissao'] ?? date('Y-m-d H:i:s'),
        ':caminho_ficheiro' => $data['caminho_ficheiro'] ?? null,
        ':url_visualizacao' => $data['url_visualizacao'] ?? null,
        ':criado_por' => (int)$data['criado_por'],
        ':empresa_id' => $data['empresa_id'] ?? null,
        ':cliente_id' => $data['cliente_id'] ?? null,
        ':missao_id' => $data['missao_id'] ?? null,
        ':parceria_id' => $parceriaId,
        ':transportador_id' => $data['transportador_id'] ?? null,
        ':condutor_id' => $data['condutor_id'] ?? null,
        ':viatura_ref' => $data['viatura_ref'] ?? null,
        ':payload_json' => $data['payload_json'] ?? null,
    ]);

    return (int)$conn->lastInsertId();
}

function tmz_docs_find_by_type_mission(PDO $conn, string $tipo, ?int $missaoId, ?int $empresaId): ?array
{
    tmz_docs_bootstrap($conn);
    $stmt = $conn->prepare(
        "SELECT * FROM documentos_sistema
         WHERE tipo = :tipo
           AND (missao_id <=> :missao_id)
           AND (empresa_id <=> :empresa_id)
         ORDER BY id DESC
         LIMIT 1"
    );
    $stmt->execute([
        ':tipo' => $tipo,
        ':missao_id' => $missaoId,
        ':empresa_id' => $empresaId,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function tmz_docs_number_and_tracking(PDO $conn, string $tipo, ?int $missaoId, ?int $empresaId): array
{
    $existing = tmz_docs_find_by_type_mission($conn, $tipo, $missaoId, $empresaId);
    if ($existing) {
        return [
            'numero_documento' => (string)$existing['numero_documento'],
            'tracking_id' => (string)$existing['tracking_id'],
            'id' => (int)$existing['id'],
        ];
    }

    $seed = $missaoId ?? random_int(1, 999999);
    return [
        'numero_documento' => tmz_docs_next_number($conn, $tipo),
        'tracking_id' => tmz_generate_document_id('TRK', $seed),
    ];
}

function tmz_docs_find_by_parceria(PDO $conn, string $tipo, int $parceriaId): ?array
{
    tmz_docs_bootstrap($conn);
    $stmt = $conn->prepare(
        "SELECT * FROM documentos_sistema
         WHERE tipo = :tipo AND parceria_id = :parceria_id
         ORDER BY id DESC
         LIMIT 1"
    );
    $stmt->execute([
        ':tipo' => $tipo,
        ':parceria_id' => $parceriaId,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Cria (ou reutiliza) o registo oficial da missão no explorador.
 */
function tmz_docs_criar_registo_missao(
    PDO $conn,
    int $missaoId,
    int $empresaId,
    int $criadoPor,
    array $payload = [],
    ?int $transportadorId = null
): int {
    tmz_docs_bootstrap($conn);

    if ($transportadorId === null) {
        try {
            $st = $conn->prepare('SELECT transportador_id FROM missoes WHERE id = :id LIMIT 1');
            $st->execute([':id' => $missaoId]);
            $tid = $st->fetchColumn();
            if ($tid !== false && $tid !== null && (int)$tid > 0) {
                $transportadorId = (int)$tid;
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    $ids = tmz_docs_number_and_tracking($conn, 'missao_registo', $missaoId, $empresaId);
    return tmz_docs_register($conn, [
        'titulo' => 'Registo da Missão #' . $missaoId,
        'tipo' => 'missao_registo',
        'numero_documento' => $ids['numero_documento'],
        'tracking_id' => $ids['tracking_id'],
        'status' => 'gerado',
        'data_emissao' => date('Y-m-d H:i:s'),
        'url_visualizacao' => BASE_URL . '/pages/contratante/documentos/missao-registo.php?id=' . $missaoId,
        'criado_por' => $criadoPor,
        'empresa_id' => $empresaId,
        'missao_id' => $missaoId,
        'transportador_id' => $transportadorId,
        'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);
}

/**
 * Cria (ou reutiliza) o contrato de parceria no explorador de documentos.
 */
function tmz_docs_criar_contrato_parceria(PDO $conn, array $parceria, int $criadoPor): int
{
    tmz_docs_bootstrap($conn);

    $parceriaId = (int)($parceria['id'] ?? 0);
    $empresaId = (int)($parceria['empresa_id'] ?? 0);
    $transportadorId = (int)($parceria['transportador_id'] ?? 0);
    if ($parceriaId <= 0 || $empresaId <= 0 || $transportadorId <= 0) {
        throw new InvalidArgumentException('Parceria inválida para documento.');
    }

    $existing = tmz_docs_find_by_parceria($conn, 'contrato_parceria', $parceriaId);
    if ($existing) {
        return (int)$existing['id'];
    }

    $numero = tmz_docs_next_number($conn, 'contrato_parceria');
    $tracking = tmz_generate_document_id('TRK', $parceriaId);

    $nomeEmpresa = (string)($parceria['nome_empresa_contratante'] ?? $parceria['nome_empresa'] ?? '');
    $nomeTransp = (string)($parceria['nome_transportadora'] ?? $parceria['transportador_nome'] ?? '');
    if ($nomeEmpresa === '' || $nomeTransp === '') {
        try {
            $st = $conn->prepare(
                "SELECT pe.nome_empresa AS nome_empresa_contratante,
                        pt.nome_empresa AS nome_transportadora
                 FROM parcerias p
                 LEFT JOIN perfil_empresa pe ON pe.usuario_id = p.empresa_id
                 LEFT JOIN perfil_transportador pt ON pt.usuario_id = p.transportador_id
                 WHERE p.id = :id LIMIT 1"
            );
            $st->execute([':id' => $parceriaId]);
            $nomes = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            if ($nomeEmpresa === '') {
                $nomeEmpresa = (string)($nomes['nome_empresa_contratante'] ?? 'Empresa');
            }
            if ($nomeTransp === '') {
                $nomeTransp = (string)($nomes['nome_transportadora'] ?? 'Transportadora');
            }
        } catch (Throwable $e) {
            if ($nomeEmpresa === '') {
                $nomeEmpresa = 'Empresa';
            }
            if ($nomeTransp === '') {
                $nomeTransp = 'Transportadora';
            }
        }
    }

    $titulo = 'Contrato de Parceria #' . $parceriaId;
    if ($nomeEmpresa !== '' && $nomeTransp !== '') {
        $titulo = 'Contrato de Parceria — ' . $nomeEmpresa . ' × ' . $nomeTransp;
    }

    return tmz_docs_register($conn, [
        'titulo' => $titulo,
        'tipo' => 'contrato_parceria',
        'numero_documento' => $numero,
        'tracking_id' => $tracking,
        'status' => 'assinado',
        'data_emissao' => date('Y-m-d H:i:s'),
        'url_visualizacao' => BASE_URL . '/pages/contratante/documentos/contrato-parceria.php?id=' . $parceriaId,
        'criado_por' => $criadoPor > 0 ? $criadoPor : $empresaId,
        'empresa_id' => $empresaId,
        'transportador_id' => $transportadorId,
        'parceria_id' => $parceriaId,
        'missao_id' => null,
        'payload_json' => json_encode([
            'parceria_id' => $parceriaId,
            'tipo_contrato' => $parceria['tipo_contrato'] ?? null,
            'valor_missao' => $parceria['valor_missao'] ?? null,
            'valor_km' => $parceria['valor_km'] ?? null,
            'valor_mensal' => $parceria['valor_mensal'] ?? null,
            'comissao_plataforma_pct' => $parceria['comissao_plataforma_pct'] ?? null,
            'tipos_carga_permitidos' => $parceria['tipos_carga_permitidos'] ?? null,
            'rotas_cobertas' => $parceria['rotas_cobertas'] ?? null,
            'empresa' => $nomeEmpresa,
            'transportadora' => $nomeTransp,
        ], JSON_UNESCAPED_UNICODE),
    ]);
}

/**
 * Gera documentos em falta (parcerias activas e missões sem registo).
 * Seguro para chamar ao abrir o explorador.
 */
function tmz_docs_backfill_pendentes(PDO $conn, ?int $empresaId = null, ?int $transportadorId = null): array
{
    tmz_docs_bootstrap($conn);
    $criados = ['parcerias' => 0, 'missoes' => 0];

    try {
        $sqlP = "SELECT p.*
                 FROM parcerias p
                 LEFT JOIN documentos_sistema d
                        ON d.tipo = 'contrato_parceria' AND d.parceria_id = p.id
                 WHERE p.status = 'ativa' AND d.id IS NULL";
        $paramsP = [];
        if ($empresaId !== null) {
            $sqlP .= ' AND p.empresa_id = :eid';
            $paramsP[':eid'] = $empresaId;
        }
        if ($transportadorId !== null) {
            $sqlP .= ' AND p.transportador_id = :tid';
            $paramsP[':tid'] = $transportadorId;
        }
        $sqlP .= ' ORDER BY p.id DESC LIMIT 100';
        $st = $conn->prepare($sqlP);
        $st->execute($paramsP);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
            try {
                tmz_docs_criar_contrato_parceria($conn, $p, (int)$p['empresa_id']);
                $criados['parcerias']++;
            } catch (Throwable $e) {
                error_log('backfill contrato_parceria #' . ($p['id'] ?? '?') . ': ' . $e->getMessage());
            }
        }
    } catch (Throwable $e) {
        error_log('backfill parcerias: ' . $e->getMessage());
    }

    try {
        $sqlM = "SELECT m.id, m.empresa_id, m.transportador_id, m.titulo, m.origem, m.destino, m.valor
                 FROM missoes m
                 LEFT JOIN documentos_sistema d
                        ON d.tipo = 'missao_registo' AND d.missao_id = m.id
                 WHERE d.id IS NULL
                   AND m.status NOT IN ('cancelada')";
        $paramsM = [];
        if ($empresaId !== null) {
            $sqlM .= ' AND m.empresa_id = :eid';
            $paramsM[':eid'] = $empresaId;
        }
        if ($transportadorId !== null) {
            $sqlM .= ' AND m.transportador_id = :tid';
            $paramsM[':tid'] = $transportadorId;
        }
        $sqlM .= ' ORDER BY m.id DESC LIMIT 200';
        $st = $conn->prepare($sqlM);
        $st->execute($paramsM);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
            try {
                tmz_docs_criar_registo_missao(
                    $conn,
                    (int)$m['id'],
                    (int)$m['empresa_id'],
                    (int)$m['empresa_id'],
                    [
                        'titulo' => $m['titulo'] ?? null,
                        'origem' => $m['origem'] ?? null,
                        'destino' => $m['destino'] ?? null,
                        'valor' => $m['valor'] ?? null,
                        'backfill' => true,
                    ],
                    !empty($m['transportador_id']) ? (int)$m['transportador_id'] : null
                );
                $criados['missoes']++;
            } catch (Throwable $e) {
                error_log('backfill missao_registo #' . ($m['id'] ?? '?') . ': ' . $e->getMessage());
            }
        }
    } catch (Throwable $e) {
        error_log('backfill missoes: ' . $e->getMessage());
    }

    return $criados;
}

