<?php
/**
 * Disputas comerciais — ciclo completo (RN54–RN56+)
 *
 * Fluxo: aberta → em_analise → encerrada
 * Partes: empresa | transportador | caminhoneiro (participantes da missão)
 * Admin: assume, media, pede evidências, decide e encerra.
 */
require_once __DIR__ . '/missao-helpers.php';
require_once __DIR__ . '/helpers.php';

const DISPUTA_CATEGORIAS = [
    'pagamento'       => 'Pagamento / valores',
    'carga_danificada'=> 'Carga danificada',
    'atraso'          => 'Atraso na entrega',
    'nao_entrega'     => 'Não entrega / extravio',
    'documentacao'    => 'Documentação',
    'comportamento'   => 'Comportamento / conduta',
    'outro'           => 'Outro',
];

const DISPUTA_RESULTADOS = [
    'favor_reclamante' => 'A favor do reclamante',
    'favor_reclamado'  => 'A favor da outra parte',
    'acordo'           => 'Acordo entre as partes',
    'improcedente'     => 'Improcedente',
    'arquivada'        => 'Arquivada sem decisão',
];

function disputas_tabela_existe(PDO $conn): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    try {
        $stmt = $conn->query("SHOW TABLES LIKE 'disputas'");
        $cache = (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        $cache = false;
    }
    return $cache;
}

/**
 * Garante colunas e tabelas auxiliares (mensagens + evidências).
 */
function disputas_bootstrap(PDO $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    if (!disputas_tabela_existe($conn)) {
        try {
            $conn->exec(
                "CREATE TABLE IF NOT EXISTS disputas (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    missao_id INT NOT NULL,
                    aberto_por INT NOT NULL,
                    motivo TEXT NOT NULL,
                    categoria VARCHAR(40) NULL,
                    prioridade ENUM('normal','alta','urgente') NOT NULL DEFAULT 'normal',
                    status ENUM('aberta','em_analise','encerrada') NOT NULL DEFAULT 'aberta',
                    resultado VARCHAR(40) NULL,
                    resolucao TEXT NULL,
                    admin_notas TEXT NULL,
                    assumido_por INT NULL,
                    assumido_em TIMESTAMP NULL,
                    encerrado_por INT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    atualizado_em TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    encerrado_em TIMESTAMP NULL,
                    KEY idx_missao (missao_id),
                    KEY idx_status (status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        } catch (Throwable $e) {
            error_log('disputas_bootstrap create: ' . $e->getMessage());
            return;
        }
    }

    try {
        $cols = $conn->query('SHOW COLUMNS FROM disputas')->fetchAll(PDO::FETCH_COLUMN);
        $adds = [
            'categoria'    => "ALTER TABLE disputas ADD COLUMN categoria VARCHAR(40) NULL AFTER motivo",
            'prioridade'   => "ALTER TABLE disputas ADD COLUMN prioridade ENUM('normal','alta','urgente') NOT NULL DEFAULT 'normal' AFTER categoria",
            'resultado'    => "ALTER TABLE disputas ADD COLUMN resultado VARCHAR(40) NULL AFTER status",
            'admin_notas'  => "ALTER TABLE disputas ADD COLUMN admin_notas TEXT NULL AFTER resolucao",
            'assumido_por' => "ALTER TABLE disputas ADD COLUMN assumido_por INT NULL AFTER admin_notas",
            'assumido_em'  => "ALTER TABLE disputas ADD COLUMN assumido_em TIMESTAMP NULL AFTER assumido_por",
            'atualizado_em'=> "ALTER TABLE disputas ADD COLUMN atualizado_em TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
        ];
        foreach ($adds as $col => $sql) {
            if (!in_array($col, $cols, true)) {
                try {
                    $conn->exec($sql);
                } catch (Throwable $e) { /* ignore */ }
            }
        }
    } catch (Throwable $e) {
        error_log('disputas_bootstrap cols: ' . $e->getMessage());
    }

    try {
        $conn->exec(
            "CREATE TABLE IF NOT EXISTS disputa_mensagens (
                id INT AUTO_INCREMENT PRIMARY KEY,
                disputa_id INT NOT NULL,
                usuario_id INT NOT NULL,
                mensagem TEXT NOT NULL,
                interno TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_disputa (disputa_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $conn->exec(
            "CREATE TABLE IF NOT EXISTS disputa_evidencias (
                id INT AUTO_INCREMENT PRIMARY KEY,
                disputa_id INT NOT NULL,
                usuario_id INT NOT NULL,
                nome_arquivo VARCHAR(255) NOT NULL,
                caminho_arquivo VARCHAR(500) NOT NULL,
                descricao VARCHAR(500) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_disputa (disputa_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (Throwable $e) {
        error_log('disputas_bootstrap aux: ' . $e->getMessage());
    }
}

function disputa_status_label(?string $status): string
{
    return match ($status) {
        'aberta' => 'Aberta',
        'em_analise' => 'Em análise',
        'encerrada' => 'Encerrada',
        default => $status ?: '—',
    };
}

function disputa_status_badge(?string $status): string
{
    return match ($status) {
        'aberta' => 'danger',
        'em_analise' => 'warning',
        'encerrada' => 'success',
        default => 'secondary',
    };
}

function disputa_categoria_label(?string $cat): string
{
    return DISPUTA_CATEGORIAS[$cat ?? ''] ?? ($cat ?: 'Não classificada');
}

function disputa_resultado_label(?string $res): string
{
    return DISPUTA_RESULTADOS[$res ?? ''] ?? ($res ?: '—');
}

function disputa_url_missao_para_tipo(string $tipo, int $missaoId): string
{
    $base = defined('BASE_URL') ? BASE_URL : '';
    return match ($tipo) {
        'caminhoneiro' => $base . '/pages/caminhoneiro/detalhes-missao.php?id=' . $missaoId,
        'transportador' => $base . '/pages/transportador/detalhes-missao.php?id=' . $missaoId,
        'empresa' => $base . '/pages/contratante/detalhes-missao.php?id=' . $missaoId,
        default => $base . '/pages/shared/disputa.php?missao_id=' . $missaoId,
    };
}

function disputa_url_detalhe(int $disputaId, string $viewerType = 'admin'): string
{
    $base = defined('BASE_URL') ? BASE_URL : '';
    if ($viewerType === 'admin') {
        return $base . '/pages/admin/disputas.php?id=' . $disputaId;
    }
    return $base . '/pages/shared/disputa.php?id=' . $disputaId;
}

/**
 * Notifica participantes da missão + opener (exceto actor).
 *
 * @param list<int>|null $extraIds
 */
function disputa_notificar_partes(
    PDO $conn,
    array $disputa,
    array $missao,
    string $titulo,
    string $mensagem,
    ?int $excetoUserId = null,
    bool $incluirAdmins = false
): void {
    $ids = [];
    foreach (['empresa_id', 'transportador_id', 'caminhoneiro_id'] as $col) {
        if (!empty($missao[$col])) {
            $ids[] = (int)$missao[$col];
        }
    }
    if (!empty($disputa['aberto_por'])) {
        $ids[] = (int)$disputa['aberto_por'];
    }
    if ($incluirAdmins) {
        try {
            $admins = $conn->query("SELECT id FROM usuarios WHERE tipo_usuario='admin' AND status='ativo'")
                ->fetchAll(PDO::FETCH_COLUMN);
            foreach ($admins as $aid) {
                $ids[] = (int)$aid;
            }
        } catch (Throwable $e) { /* ignore */ }
    }

    $ids = array_unique(array_filter($ids));
    foreach ($ids as $uid) {
        if ($excetoUserId && $uid === $excetoUserId) {
            continue;
        }
        try {
            $st = $conn->prepare('SELECT tipo_usuario FROM usuarios WHERE id = ?');
            $st->execute([$uid]);
            $tipo = (string)$st->fetchColumn();
            $link = $tipo === 'admin'
                ? disputa_url_detalhe((int)$disputa['id'], 'admin')
                : disputa_url_detalhe((int)$disputa['id'], $tipo);

            if (function_exists('notificar_usuario')) {
                require_once __DIR__ . '/notificacoes-helpers.php';
                notificar_usuario($conn, $uid, 'disputa', $titulo, $mensagem, $link);
            } else {
                $conn->prepare(
                    "INSERT INTO notificacoes (usuario_id, tipo, titulo, mensagem, link)
                     VALUES (?,?,?,?,?)"
                )->execute([$uid, 'disputa', $titulo, $mensagem, $link]);
            }
        } catch (Throwable $e) {
            error_log('disputa_notificar_partes: ' . $e->getMessage());
        }
    }
}

/**
 * RN54 — Apenas missões concluídas originam disputa.
 */
function validar_missao_pode_disputar(PDO $conn, int $missaoId, int $userId, string $userType): array
{
    disputas_bootstrap($conn);
    if (!disputas_tabela_existe($conn)) {
        return ['ok' => false, 'erros' => ['Módulo de disputas não disponível.']];
    }

    $stmt = $conn->prepare(
        'SELECT id, status, empresa_id, transportador_id, caminhoneiro_id, titulo, origem, destino
         FROM missoes WHERE id = :id'
    );
    $stmt->execute([':id' => $missaoId]);
    $missao = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$missao) {
        return ['ok' => false, 'erros' => ['Missão não encontrada.']];
    }
    if ($missao['status'] !== 'concluida') {
        return ['ok' => false, 'erros' => ['Apenas missões concluídas podem originar disputa.']];
    }

    $participa = $userType === 'admin'
        || (int)($missao['empresa_id'] ?? 0) === $userId
        || (int)($missao['transportador_id'] ?? 0) === $userId
        || (int)($missao['caminhoneiro_id'] ?? 0) === $userId;
    if (!$participa) {
        return ['ok' => false, 'erros' => ['Sem permissão para disputar esta missão.']];
    }

    $stmt = $conn->prepare(
        "SELECT COUNT(*) FROM disputas WHERE missao_id = :id AND status != 'encerrada'"
    );
    $stmt->execute([':id' => $missaoId]);
    if ((int)$stmt->fetchColumn() > 0) {
        return ['ok' => false, 'erros' => ['Já existe uma disputa aberta para esta missão.']];
    }

    return ['ok' => true, 'erros' => [], 'missao' => $missao];
}

/**
 * RN55 — Motivo obrigatório. Categoria opcional.
 */
function disputa_criar(
    PDO $conn,
    int $missaoId,
    int $userId,
    string $motivo,
    string $categoria = 'outro',
    string $prioridade = 'normal'
): array {
    disputas_bootstrap($conn);
    $motivo = trim($motivo);
    if ($motivo === '' || mb_strlen($motivo) < 20) {
        return ['ok' => false, 'erros' => ['Descreva o motivo com pelo menos 20 caracteres.']];
    }
    if (!isset(DISPUTA_CATEGORIAS[$categoria])) {
        $categoria = 'outro';
    }
    if (!in_array($prioridade, ['normal', 'alta', 'urgente'], true)) {
        $prioridade = 'normal';
    }

    $stmt = $conn->prepare('SELECT tipo_usuario FROM usuarios WHERE id = :id');
    $stmt->execute([':id' => $userId]);
    $tipo = (string)($stmt->fetchColumn() ?: '');

    $validacao = validar_missao_pode_disputar($conn, $missaoId, $userId, $tipo);
    if (!$validacao['ok']) {
        return $validacao;
    }
    $missao = $validacao['missao'];

    $hasCat = in_array('categoria', $conn->query('SHOW COLUMNS FROM disputas')->fetchAll(PDO::FETCH_COLUMN), true);
    if ($hasCat) {
        $conn->prepare(
            'INSERT INTO disputas (missao_id, aberto_por, motivo, categoria, prioridade, status)
             VALUES (?,?,?,?,?,?)'
        )->execute([$missaoId, $userId, $motivo, $categoria, $prioridade, 'aberta']);
    } else {
        $conn->prepare(
            'INSERT INTO disputas (missao_id, aberto_por, motivo, status) VALUES (?,?,?,?)'
        )->execute([$missaoId, $userId, $motivo, 'aberta']);
    }

    $disputaId = (int)$conn->lastInsertId();
    $disputa = ['id' => $disputaId, 'aberto_por' => $userId, 'missao_id' => $missaoId];

    disputa_mensagem_interna(
        $conn,
        $disputaId,
        $userId,
        'Disputa aberta: ' . disputa_categoria_label($categoria) . "\n\n" . $motivo,
        false
    );

    disputa_notificar_partes(
        $conn,
        $disputa,
        $missao,
        'Nova disputa na missão #' . $missaoId,
        mb_substr($motivo, 0, 160),
        $userId,
        true
    );

    if (function_exists('registrar_log')) {
        registrar_log($conn, $userId, 'abrir_disputa', 'disputa', $disputaId, 'Disputa aberta na missão #' . $missaoId);
    }

    return ['ok' => true, 'disputa_id' => $disputaId];
}

/**
 * Admin assume o caso → status em_analise.
 */
function disputa_assumir(PDO $conn, int $disputaId, int $adminId): array
{
    disputas_bootstrap($conn);
    $d = disputa_obter($conn, $disputaId);
    if (!$d) {
        return ['ok' => false, 'erros' => ['Disputa não encontrada.']];
    }
    if ($d['status'] === 'encerrada') {
        return ['ok' => false, 'erros' => ['Disputa já encerrada.']];
    }

    $conn->prepare(
        "UPDATE disputas SET status = 'em_analise', assumido_por = ?, assumido_em = NOW() WHERE id = ?"
    )->execute([$adminId, $disputaId]);

    $d['status'] = 'em_analise';
    disputa_mensagem_interna(
        $conn,
        $disputaId,
        $adminId,
        'Administração assumiu a disputa e iniciou a análise.',
        false
    );

    $missao = disputa_obter_missao($conn, (int)$d['missao_id']);
    if ($missao) {
        disputa_notificar_partes(
            $conn,
            $d,
            $missao,
            'Disputa em análise',
            'A disputa #' . $disputaId . ' está a ser analisada pela administração.',
            $adminId
        );
    }

    if (function_exists('registrar_log')) {
        registrar_log($conn, $adminId, 'assumir_disputa', 'disputa', $disputaId, 'Caso assumido');
    }

    return ['ok' => true];
}

/**
 * RN56 — Apenas administrador encerra disputa (com resultado).
 */
function disputa_encerrar(
    PDO $conn,
    int $disputaId,
    int $adminId,
    string $resolucao,
    string $resultado = 'acordo'
): array {
    disputas_bootstrap($conn);
    $resolucao = trim($resolucao);
    if ($resolucao === '' || mb_strlen($resolucao) < 15) {
        return ['ok' => false, 'erros' => ['A resolução deve ter pelo menos 15 caracteres.']];
    }
    if (!isset(DISPUTA_RESULTADOS[$resultado])) {
        return ['ok' => false, 'erros' => ['Resultado da decisão inválido.']];
    }

    $disputa = disputa_obter($conn, $disputaId);
    if (!$disputa) {
        return ['ok' => false, 'erros' => ['Disputa não encontrada.']];
    }
    if ($disputa['status'] === 'encerrada') {
        return ['ok' => false, 'erros' => ['Disputa já encerrada.']];
    }

    $cols = $conn->query('SHOW COLUMNS FROM disputas')->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('resultado', $cols, true)) {
        $conn->prepare(
            "UPDATE disputas SET status = 'encerrada', resultado = ?, resolucao = ?,
                encerrado_por = ?, encerrado_em = NOW() WHERE id = ?"
        )->execute([$resultado, $resolucao, $adminId, $disputaId]);
    } else {
        $conn->prepare(
            "UPDATE disputas SET status = 'encerrada', resolucao = ?, encerrado_por = ?, encerrado_em = NOW()
             WHERE id = ?"
        )->execute([$resolucao, $adminId, $disputaId]);
    }

    disputa_mensagem_interna(
        $conn,
        $disputaId,
        $adminId,
        'Disputa encerrada — ' . disputa_resultado_label($resultado) . ":\n\n" . $resolucao,
        false
    );

    $missao = disputa_obter_missao($conn, (int)$disputa['missao_id']);
    if ($missao) {
        disputa_notificar_partes(
            $conn,
            $disputa,
            $missao,
            'Disputa encerrada',
            'Resultado: ' . disputa_resultado_label($resultado) . '. ' . mb_substr($resolucao, 0, 120),
            $adminId
        );
    }

    if (function_exists('registrar_log')) {
        registrar_log(
            $conn,
            $adminId,
            'encerrar_disputa',
            'disputa',
            $disputaId,
            disputa_resultado_label($resultado) . ' — ' . mb_substr($resolucao, 0, 200)
        );
    }

    return ['ok' => true];
}

function disputa_obter(PDO $conn, int $disputaId): ?array
{
    disputas_bootstrap($conn);
    if (!disputas_tabela_existe($conn)) {
        return null;
    }
    $stmt = $conn->prepare(
        "SELECT d.*,
                m.titulo, m.origem, m.destino, m.status AS missao_status,
                m.empresa_id, m.transportador_id, m.caminhoneiro_id, m.valor, m.valor_total,
                u.nome AS aberto_por_nome, u.tipo_usuario AS aberto_por_tipo, u.email AS aberto_por_email,
                adm.nome AS encerrado_por_nome,
                asum.nome AS assumido_por_nome,
                emp.nome AS empresa_nome,
                mot.nome AS motorista_nome,
                tr.nome AS transportador_nome
         FROM disputas d
         INNER JOIN missoes m ON d.missao_id = m.id
         INNER JOIN usuarios u ON d.aberto_por = u.id
         LEFT JOIN usuarios adm ON d.encerrado_por = adm.id
         LEFT JOIN usuarios asum ON d.assumido_por = asum.id
         LEFT JOIN usuarios emp ON m.empresa_id = emp.id
         LEFT JOIN usuarios mot ON m.caminhoneiro_id = mot.id
         LEFT JOIN usuarios tr ON m.transportador_id = tr.id
         WHERE d.id = ?"
    );
    $stmt->execute([$disputaId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function disputa_obter_missao(PDO $conn, int $missaoId): ?array
{
    $stmt = $conn->prepare(
        'SELECT id, status, empresa_id, transportador_id, caminhoneiro_id, titulo, origem, destino
         FROM missoes WHERE id = ?'
    );
    $stmt->execute([$missaoId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function disputa_utilizador_pode_ver(array $disputa, int $userId, string $userType): bool
{
    if ($userType === 'admin') {
        return true;
    }
    return (int)($disputa['aberto_por'] ?? 0) === $userId
        || (int)($disputa['empresa_id'] ?? 0) === $userId
        || (int)($disputa['transportador_id'] ?? 0) === $userId
        || (int)($disputa['caminhoneiro_id'] ?? 0) === $userId;
}

function disputa_utilizador_pode_interagir(array $disputa, int $userId, string $userType): bool
{
    if ($disputa['status'] === 'encerrada') {
        return false;
    }
    return disputa_utilizador_pode_ver($disputa, $userId, $userType);
}

/**
 * @return list<array<string,mixed>>
 */
function disputas_listar(PDO $conn, ?string $status = null, ?string $q = null, int $limit = 100): array
{
    disputas_bootstrap($conn);
    if (!disputas_tabela_existe($conn)) {
        return [];
    }

    $where = [];
    $params = [];
    if ($status && in_array($status, ['aberta', 'em_analise', 'encerrada'], true)) {
        $where[] = 'd.status = :st';
        $params[':st'] = $status;
    }
    if ($q !== null && trim($q) !== '') {
        $where[] = '(m.titulo LIKE :q OR u.nome LIKE :q2 OR CAST(d.id AS CHAR) = :qid OR CAST(d.missao_id AS CHAR) = :qid2 OR d.motivo LIKE :q3)';
        $like = '%' . trim($q) . '%';
        $params[':q'] = $like;
        $params[':q2'] = $like;
        $params[':q3'] = $like;
        $params[':qid'] = trim($q);
        $params[':qid2'] = trim($q);
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $stmt = $conn->prepare(
        "SELECT d.*, m.titulo AS missao_titulo, m.origem, m.destino,
                u.nome AS aberto_por_nome, u.tipo_usuario AS aberto_por_tipo,
                emp.nome AS empresa_nome, mot.nome AS motorista_nome
         FROM disputas d
         INNER JOIN missoes m ON d.missao_id = m.id
         INNER JOIN usuarios u ON d.aberto_por = u.id
         LEFT JOIN usuarios emp ON m.empresa_id = emp.id
         LEFT JOIN usuarios mot ON m.caminhoneiro_id = mot.id
         {$whereSql}
         ORDER BY
            FIELD(d.status, 'aberta', 'em_analise', 'encerrada'),
            FIELD(IFNULL(d.prioridade,'normal'), 'urgente', 'alta', 'normal'),
            d.created_at DESC
         LIMIT {$limit}"
    );
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * @return array{aberta:int,em_analise:int,encerrada:int,total:int,urgente:int}
 */
function disputas_contagens(PDO $conn): array
{
    disputas_bootstrap($conn);
    $out = ['aberta' => 0, 'em_analise' => 0, 'encerrada' => 0, 'total' => 0, 'urgente' => 0];
    if (!disputas_tabela_existe($conn)) {
        return $out;
    }
    try {
        foreach ($conn->query("SELECT status, COUNT(*) c FROM disputas GROUP BY status")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[$r['status']] = (int)$r['c'];
            $out['total'] += (int)$r['c'];
        }
        try {
            $out['urgente'] = (int)$conn->query(
                "SELECT COUNT(*) FROM disputas WHERE prioridade='urgente' AND status != 'encerrada'"
            )->fetchColumn();
        } catch (Throwable $e) { /* ignore */ }
    } catch (Throwable $e) { /* ignore */ }
    return $out;
}

function disputa_mensagem_interna(
    PDO $conn,
    int $disputaId,
    int $userId,
    string $mensagem,
    bool $interno = false
): array {
    disputas_bootstrap($conn);
    $mensagem = trim($mensagem);
    if ($mensagem === '') {
        return ['ok' => false, 'erros' => ['Mensagem vazia.']];
    }
    try {
        $conn->prepare(
            'INSERT INTO disputa_mensagens (disputa_id, usuario_id, mensagem, interno) VALUES (?,?,?,?)'
        )->execute([$disputaId, $userId, $mensagem, $interno ? 1 : 0]);
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'erros' => ['Não foi possível gravar a mensagem.']];
    }
}

function disputa_adicionar_mensagem(
    PDO $conn,
    int $disputaId,
    int $userId,
    string $userType,
    string $mensagem,
    bool $interno = false
): array {
    disputas_bootstrap($conn);
    $d = disputa_obter($conn, $disputaId);
    if (!$d) {
        return ['ok' => false, 'erros' => ['Disputa não encontrada.']];
    }
    if (!disputa_utilizador_pode_interagir($d, $userId, $userType)) {
        return ['ok' => false, 'erros' => ['Sem permissão ou disputa encerrada.']];
    }
    if ($interno && $userType !== 'admin') {
        $interno = false;
    }
    if (mb_strlen(trim($mensagem)) < 2) {
        return ['ok' => false, 'erros' => ['Mensagem demasiado curta.']];
    }

    // Auto-passar a em_analise se admin responde a disputa aberta
    if ($userType === 'admin' && $d['status'] === 'aberta') {
        disputa_assumir($conn, $disputaId, $userId);
    }

    $res = disputa_mensagem_interna($conn, $disputaId, $userId, $mensagem, $interno);
    if (!$res['ok']) {
        return $res;
    }

    if (!$interno) {
        $missao = disputa_obter_missao($conn, (int)$d['missao_id']);
        if ($missao) {
            disputa_notificar_partes(
                $conn,
                $d,
                $missao,
                'Nova mensagem na disputa #' . $disputaId,
                mb_substr(trim($mensagem), 0, 140),
                $userId,
                $userType !== 'admin'
            );
        }
    }

    return ['ok' => true];
}

/**
 * @return list<array<string,mixed>>
 */
function disputa_listar_mensagens(PDO $conn, int $disputaId, bool $incluirInternas = false): array
{
    disputas_bootstrap($conn);
    try {
        $sql = "SELECT m.*, u.nome AS autor_nome, u.tipo_usuario AS autor_tipo
                FROM disputa_mensagens m
                INNER JOIN usuarios u ON u.id = m.usuario_id
                WHERE m.disputa_id = ?";
        if (!$incluirInternas) {
            $sql .= ' AND m.interno = 0';
        }
        $sql .= ' ORDER BY m.created_at ASC';
        $stmt = $conn->prepare($sql);
        $stmt->execute([$disputaId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

function disputa_adicionar_evidencia(
    PDO $conn,
    int $disputaId,
    int $userId,
    string $userType,
    array $file,
    string $descricao = ''
): array {
    disputas_bootstrap($conn);
    $d = disputa_obter($conn, $disputaId);
    if (!$d) {
        return ['ok' => false, 'erros' => ['Disputa não encontrada.']];
    }
    if (!disputa_utilizador_pode_interagir($d, $userId, $userType)) {
        return ['ok' => false, 'erros' => ['Sem permissão ou disputa encerrada.']];
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'erros' => ['Ficheiro inválido.']];
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx'];
    if (!in_array($ext, $allowed, true)) {
        return ['ok' => false, 'erros' => ['Tipo de ficheiro não permitido. Use PDF, imagem ou DOC.']];
    }
    if (($file['size'] ?? 0) > 8 * 1024 * 1024) {
        return ['ok' => false, 'erros' => ['Ficheiro demasiado grande (máx. 8 MB).']];
    }

    $dir = dirname(__DIR__) . '/uploads/disputas/' . $disputaId . '/';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return ['ok' => false, 'erros' => ['Não foi possível criar pasta de uploads.']];
    }
    $novo = 'ev_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . $novo)) {
        return ['ok' => false, 'erros' => ['Falha no upload.']];
    }

    $rel = 'uploads/disputas/' . $disputaId . '/' . $novo;
    $conn->prepare(
        'INSERT INTO disputa_evidencias (disputa_id, usuario_id, nome_arquivo, caminho_arquivo, descricao)
         VALUES (?,?,?,?,?)'
    )->execute([$disputaId, $userId, $file['name'], $rel, trim($descricao) ?: null]);

    disputa_mensagem_interna(
        $conn,
        $disputaId,
        $userId,
        'Evidência anexada: ' . $file['name'] . (trim($descricao) ? ' — ' . trim($descricao) : ''),
        false
    );

    return ['ok' => true, 'caminho' => $rel];
}

/**
 * @return list<array<string,mixed>>
 */
function disputa_listar_evidencias(PDO $conn, int $disputaId): array
{
    disputas_bootstrap($conn);
    try {
        $stmt = $conn->prepare(
            "SELECT e.*, u.nome AS autor_nome
             FROM disputa_evidencias e
             INNER JOIN usuarios u ON u.id = e.usuario_id
             WHERE e.disputa_id = ?
             ORDER BY e.created_at DESC"
        );
        $stmt->execute([$disputaId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

function disputa_da_missao(PDO $conn, int $missaoId): ?array
{
    disputas_bootstrap($conn);
    if (!disputas_tabela_existe($conn)) {
        return null;
    }
    $stmt = $conn->prepare('SELECT * FROM disputas WHERE missao_id = ? ORDER BY created_at DESC LIMIT 1');
    $stmt->execute([$missaoId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}
