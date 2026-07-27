<?php
/**
 * KYC / verificação anti-fraude — visitante → documentos → análise → verificado.
 */
require_once __DIR__ . '/helpers.php';

function kyc_bootstrap(PDO $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $cols = $conn->query('SHOW COLUMNS FROM usuarios')->fetchAll(PDO::FETCH_COLUMN);
        $criouEstado = false;
        if (!in_array('estado_kyc', $cols, true)) {
            $conn->exec(
                "ALTER TABLE usuarios ADD COLUMN estado_kyc VARCHAR(30) NOT NULL DEFAULT 'visitante'
                 COMMENT 'visitante|dados_pendentes|em_analise|verificado|rejeitado' AFTER status"
            );
            $criouEstado = true;
        }
        if (!in_array('verificado', $cols, true)) {
            $conn->exec('ALTER TABLE usuarios ADD COLUMN verificado TINYINT(1) NOT NULL DEFAULT 0');
        }
        if (!in_array('kyc_dados_completos', $cols, true)) {
            $conn->exec('ALTER TABLE usuarios ADD COLUMN kyc_dados_completos TINYINT(1) NOT NULL DEFAULT 0');
        }
        if (!in_array('kyc_enviado_em', $cols, true)) {
            $conn->exec('ALTER TABLE usuarios ADD COLUMN kyc_enviado_em TIMESTAMP NULL DEFAULT NULL');
        }
        if (!in_array('kyc_verificado_em', $cols, true)) {
            $conn->exec('ALTER TABLE usuarios ADD COLUMN kyc_verificado_em TIMESTAMP NULL DEFAULT NULL');
        }
        if (!in_array('kyc_verificado_por', $cols, true)) {
            $conn->exec('ALTER TABLE usuarios ADD COLUMN kyc_verificado_por INT NULL DEFAULT NULL');
        }
        if (!in_array('kyc_motivo_rejeicao', $cols, true)) {
            $conn->exec('ALTER TABLE usuarios ADD COLUMN kyc_motivo_rejeicao TEXT NULL');
        }

        // Admin sempre verificado
        $conn->exec("UPDATE usuarios SET estado_kyc = 'verificado', verificado = 1 WHERE tipo_usuario = 'admin'");

        // Na primeira instalação da coluna: contas já activas ficam verificadas (não bloquear operação actual)
        if ($criouEstado) {
            $conn->exec(
                "UPDATE usuarios SET estado_kyc = 'verificado', verificado = 1, kyc_dados_completos = 1
                 WHERE status = 'ativo' AND tipo_usuario IN ('caminhoneiro','empresa','transportador')"
            );
            $conn->exec(
                "UPDATE usuarios SET estado_kyc = 'visitante', verificado = 0
                 WHERE status = 'pendente' AND tipo_usuario IN ('caminhoneiro','empresa','transportador')"
            );
        }

        // Expandir enum de documentos se necessário
        try {
            $col = $conn->query("SHOW COLUMNS FROM documentos LIKE 'tipo_documento'")->fetch(PDO::FETCH_ASSOC);
            $type = (string)($col['Type'] ?? '');
            if ($type !== '' && stripos($type, 'registro_empresa') === false) {
                $conn->exec(
                    "ALTER TABLE documentos MODIFY COLUMN tipo_documento
                     ENUM('bi','cnh','alvara','registro_empresa','outros') NOT NULL"
                );
            }
        } catch (Throwable $e) {
            error_log('kyc_bootstrap docs enum: ' . $e->getMessage());
        }

        // Uma vez: revalidar contas antigas (docs em falta = não operam + lembrete)
        try {
            $flagKey = 'kyc_revalidacao_docs_v1';
            $tem = false;
            try {
                $tem = (bool)$conn->query(
                    "SELECT 1 FROM configuracoes WHERE chave = " . $conn->quote($flagKey) . " LIMIT 1"
                )->fetchColumn();
            } catch (Throwable $e) {
                $tem = false;
            }
            if (!$tem) {
                // Evitar recursão: marcar flag antes
                try {
                    $conn->exec(
                        "INSERT INTO configuracoes (chave, valor, descricao) VALUES ("
                        . $conn->quote($flagKey) . ", '1', 'Revalidação KYC documentos')"
                    );
                } catch (Throwable $e) {
                    try {
                        $conn->exec(
                            "CREATE TABLE IF NOT EXISTS configuracoes (
                                id INT AUTO_INCREMENT PRIMARY KEY,
                                chave VARCHAR(100) UNIQUE,
                                valor TEXT,
                                descricao VARCHAR(255) NULL,
                                data_atualizacao TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                            )"
                        );
                        $conn->exec(
                            "INSERT IGNORE INTO configuracoes (chave, valor, descricao) VALUES ("
                            . $conn->quote($flagKey) . ", '1', 'Revalidação KYC documentos')"
                        );
                    } catch (Throwable $e2) { /* ignore */ }
                }
                // Revalidação lazy por utilizador ao login; aqui só desmarca "verificado" sem docs
                $uids = $conn->query(
                    "SELECT id, tipo_usuario FROM usuarios
                     WHERE status = 'ativo' AND verificado = 1
                       AND tipo_usuario IN ('caminhoneiro','empresa','transportador')"
                )->fetchAll(PDO::FETCH_ASSOC);
                foreach ($uids as $row) {
                    $docs = kyc_estado_documentos($conn, (int)$row['id'], (string)$row['tipo_usuario']);
                    if (empty($docs['todos_aprovados'])) {
                        $conn->prepare(
                            "UPDATE usuarios SET verificado = 0,
                                estado_kyc = IF(estado_kyc = 'verificado', 'dados_pendentes', estado_kyc)
                             WHERE id = ?"
                        )->execute([(int)$row['id']]);
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('kyc_bootstrap revalidacao: ' . $e->getMessage());
        }
    } catch (Throwable $e) {
        error_log('kyc_bootstrap: ' . $e->getMessage());
    }
}

/**
 * Documentos obrigatórios por tipo de utilizador.
 * @return array<string,string> codigo => label
 */
function kyc_documentos_obrigatorios(string $tipoUsuario): array
{
    return match ($tipoUsuario) {
        'caminhoneiro' => [
            'bi'  => 'Bilhete de Identidade',
            'cnh' => 'Carta de Condução (CNH)',
        ],
        'empresa' => [
            'bi'               => 'BI do responsável legal',
            'registro_empresa' => 'NUIT / Registo comercial',
            'alvara'           => 'Alvará / Licença de actividade',
        ],
        'transportador' => [
            'bi'               => 'BI do responsável legal',
            'registro_empresa' => 'NUIT / Registo comercial',
            'alvara'           => 'Alvará / Licença de actividade',
        ],
        default => [],
    };
}

function kyc_estado_label(?string $estado): string
{
    return match ($estado) {
        'visitante' => 'Visitante',
        'dados_pendentes' => 'Dados incompletos',
        'em_analise' => 'Em análise',
        'verificado' => 'Verificado',
        'rejeitado' => 'Rejeitado — reenvie documentos',
        default => 'Visitante',
    };
}

function kyc_obter_estado(PDO $conn, int $userId): array
{
    kyc_bootstrap($conn);
    $stmt = $conn->prepare(
        'SELECT id, tipo_usuario, status, estado_kyc, verificado, kyc_dados_completos,
                kyc_enviado_em, kyc_verificado_em, kyc_motivo_rejeicao
         FROM usuarios WHERE id = ?'
    );
    $stmt->execute([$userId]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u) {
        return ['ok' => false, 'estado' => null, 'pode_operar' => false, 'erros' => ['Utilizador não encontrado.']];
    }

    $estado = $u['estado_kyc'] ?: 'visitante';
    // Admin sempre opera
    if (($u['tipo_usuario'] ?? '') === 'admin') {
        return [
            'ok' => true,
            'estado' => 'verificado',
            'pode_operar' => true,
            'usuario' => $u,
            'erros' => [],
            'faltam_docs' => [],
            'docs' => [],
        ];
    }

    $docs = kyc_estado_documentos($conn, $userId, (string)$u['tipo_usuario']);
    $faltam = [];
    foreach ($docs['obrigatorios'] as $codigo => $label) {
        $st = $docs['por_tipo'][$codigo]['status'] ?? null;
        if ($st !== 'aprovado') {
            $faltam[$codigo] = $label . ($st ? ' (' . $st . ')' : ' (em falta)');
        }
    }

    // Auto-corrigir estado conforme documentos reais (inclui contas antigas)
    if (!empty($faltam)) {
        if (in_array($estado, ['verificado', ''], true) || (int)$u['verificado'] === 1) {
            $novoEstado = $docs['todos_enviados'] ? 'em_analise' : 'dados_pendentes';
            try {
                $conn->prepare(
                    "UPDATE usuarios SET estado_kyc = ?, verificado = 0 WHERE id = ?"
                )->execute([$novoEstado, $userId]);
                $estado = $novoEstado;
                $u['estado_kyc'] = $novoEstado;
                $u['verificado'] = 0;
            } catch (Throwable $e) {
                $estado = 'dados_pendentes';
            }
        }
    } elseif ($docs['todos_aprovados'] && (int)($u['kyc_dados_completos'] ?? 0) === 1) {
        // Docs OK + dados OK → garantir verificado
        if ($estado !== 'verificado' || (int)$u['verificado'] !== 1) {
            try {
                $conn->prepare(
                    "UPDATE usuarios SET estado_kyc = 'verificado', verificado = 1,
                        kyc_verificado_em = COALESCE(kyc_verificado_em, NOW()) WHERE id = ?"
                )->execute([$userId]);
                $estado = 'verificado';
                $u['verificado'] = 1;
            } catch (Throwable $e) { /* ignore */ }
        }
    }

    // Operar = conta activa + TODOS os docs obrigatórios aprovados
    $podeOperar = ($u['status'] === 'ativo') && empty($faltam);

    $erros = [];
    if (!$podeOperar) {
        if ($u['status'] !== 'ativo') {
            $erros[] = 'A sua conta não está activa.';
        } elseif ($estado === 'em_analise' && $docs['todos_enviados']) {
            $erros[] = 'Documentos em análise pela administração. Ainda não pode negociar ou publicar missões.';
        } elseif ($estado === 'rejeitado') {
            $erros[] = 'Documentos rejeitados. Corrija e reenvie na verificação da conta.';
        } else {
            $lista = implode(', ', array_values($faltam));
            $erros[] = 'Faltam documentos obrigatórios aprovados: ' . $lista . '. Anexe-os para poder operar.';
        }
    }

    return [
        'ok' => true,
        'estado' => $estado,
        'pode_operar' => $podeOperar,
        'usuario' => $u,
        'docs' => $docs,
        'faltam_docs' => $faltam,
        'erros' => $erros,
    ];
}

function kyc_estado_documentos(PDO $conn, int $userId, string $tipoUsuario): array
{
    $obrigatorios = kyc_documentos_obrigatorios($tipoUsuario);
    $porTipo = [];
    try {
        $stmt = $conn->prepare(
            'SELECT id, tipo_documento, status, bloqueado, nome_arquivo, caminho_arquivo, data_upload
             FROM documentos WHERE usuario_id = ? ORDER BY data_upload DESC'
        );
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $t = $row['tipo_documento'];
            // manter o mais recente por tipo
            if (!isset($porTipo[$t])) {
                $porTipo[$t] = $row;
            }
        }
    } catch (Throwable $e) {
        error_log('kyc_estado_documentos: ' . $e->getMessage());
    }

    $todosEnviados = true;
    $todosAprovados = true;
    foreach ($obrigatorios as $codigo => $_label) {
        $st = $porTipo[$codigo]['status'] ?? null;
        if ($st === null) {
            $todosEnviados = false;
            $todosAprovados = false;
        } elseif ($st !== 'aprovado') {
            $todosAprovados = false;
            if ($st === 'rejeitado') {
                $todosEnviados = false; // precisa reenviar
            }
        }
    }

    return [
        'obrigatorios' => $obrigatorios,
        'por_tipo' => $porTipo,
        'todos_enviados' => $todosEnviados && !empty($obrigatorios),
        'todos_aprovados' => $todosAprovados && !empty($obrigatorios),
    ];
}

function kyc_pode_operar(PDO $conn, int $userId): array
{
    $info = kyc_obter_estado($conn, $userId);
    if (!$info['ok']) {
        return ['ok' => false, 'erros' => $info['erros']];
    }
    if (!empty($info['pode_operar'])) {
        return ['ok' => true, 'erros' => [], 'estado' => $info['estado']];
    }
    return [
        'ok' => false,
        'erros' => $info['erros'],
        'estado' => $info['estado'],
        'faltam_docs' => $info['faltam_docs'] ?? [],
        'solucao' => 'Abra Verificação da conta, complete os dados legais e envie os documentos obrigatórios.',
    ];
}

/**
 * Marca conta como visitante após aprovação admin (pode fazer login).
 */
function kyc_marcar_visitante(PDO $conn, int $userId): void
{
    kyc_bootstrap($conn);
    $conn->prepare(
        "UPDATE usuarios SET status = 'ativo', estado_kyc = 'visitante', verificado = 0,
            kyc_dados_completos = 0, kyc_enviado_em = NULL, kyc_verificado_em = NULL,
            kyc_verificado_por = NULL, kyc_motivo_rejeicao = NULL
         WHERE id = ?"
    )->execute([$userId]);
}

function kyc_marcar_dados_completos(PDO $conn, int $userId): void
{
    kyc_bootstrap($conn);
    $conn->prepare(
        "UPDATE usuarios SET kyc_dados_completos = 1,
            estado_kyc = CASE WHEN estado_kyc = 'verificado' THEN 'verificado' ELSE 'dados_pendentes' END
         WHERE id = ?"
    )->execute([$userId]);
}

/**
 * Após upload: se dados + docs obrigatórios enviados → em_analise + notificar admins.
 */
function kyc_apos_envio_documento(PDO $conn, int $userId): void
{
    kyc_bootstrap($conn);
    $stmt = $conn->prepare('SELECT tipo_usuario, nome, kyc_dados_completos, estado_kyc FROM usuarios WHERE id = ?');
    $stmt->execute([$userId]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u || ($u['estado_kyc'] ?? '') === 'verificado') {
        return;
    }

    $docs = kyc_estado_documentos($conn, $userId, (string)$u['tipo_usuario']);
    if (!(int)$u['kyc_dados_completos']) {
        // ainda falta formulário
        $conn->prepare("UPDATE usuarios SET estado_kyc = 'dados_pendentes', verificado = 0 WHERE id = ?")
             ->execute([$userId]);
        return;
    }

    if ($docs['todos_enviados']) {
        $conn->prepare(
            "UPDATE usuarios SET estado_kyc = 'em_analise', verificado = 0, kyc_enviado_em = COALESCE(kyc_enviado_em, NOW()) WHERE id = ?"
        )->execute([$userId]);

        kyc_notificar_admins(
            $conn,
            'Verificação pendente — ' . ($u['nome'] ?? 'Utilizador'),
            ($u['nome'] ?? 'Um utilizador') . ' (' . $u['tipo_usuario'] . ') enviou documentos para análise KYC.',
            BASE_URL . '/pages/admin/verificar-documentos.php?usuario_id=' . $userId
        );
    } else {
        $conn->prepare("UPDATE usuarios SET estado_kyc = 'dados_pendentes', verificado = 0 WHERE id = ?")
             ->execute([$userId]);
    }
}

function kyc_notificar_admins(PDO $conn, string $titulo, string $mensagem, ?string $link = null): void
{
    try {
        $admins = $conn->query("SELECT id FROM usuarios WHERE tipo_usuario = 'admin' AND status = 'ativo'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($admins as $aid) {
            if (function_exists('notificar_usuario')) {
                notificar_usuario($conn, (int)$aid, 'alerta', $titulo, $mensagem, $link);
            } else {
                $conn->prepare(
                    'INSERT INTO notificacoes (usuario_id, tipo, titulo, mensagem, link) VALUES (?,?,?,?,?)'
                )->execute([(int)$aid, 'alerta', $titulo, $mensagem, $link]);
            }
        }
    } catch (Throwable $e) {
        error_log('kyc_notificar_admins: ' . $e->getMessage());
    }
}

/**
 * Quando admin aprova/rejeita um doc — reavalia KYC completo.
 */
function kyc_reavaliar_apos_doc(PDO $conn, int $userId, ?int $adminId = null): array
{
    kyc_bootstrap($conn);
    $stmt = $conn->prepare('SELECT tipo_usuario, nome, kyc_dados_completos FROM usuarios WHERE id = ?');
    $stmt->execute([$userId]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u) {
        return ['ok' => false, 'verificado' => false];
    }

    $docs = kyc_estado_documentos($conn, $userId, (string)$u['tipo_usuario']);

    // Algum rejeitado?
    $temRejeitado = false;
    foreach ($docs['obrigatorios'] as $codigo => $_l) {
        if (($docs['por_tipo'][$codigo]['status'] ?? '') === 'rejeitado') {
            $temRejeitado = true;
            break;
        }
    }

    if ($docs['todos_aprovados'] && (int)$u['kyc_dados_completos'] === 1) {
        $conn->prepare(
            "UPDATE usuarios SET estado_kyc = 'verificado', verificado = 1,
                kyc_verificado_em = NOW(), kyc_verificado_por = ?, kyc_motivo_rejeicao = NULL
             WHERE id = ?"
        )->execute([$adminId, $userId]);

        // Marcar perfil empresa/transportador
        try {
            if ($u['tipo_usuario'] === 'empresa') {
                $conn->prepare('UPDATE perfil_empresa SET verificada = 1 WHERE usuario_id = ?')->execute([$userId]);
            } elseif ($u['tipo_usuario'] === 'transportador') {
                $conn->prepare('UPDATE perfil_transportador SET verificada = 1 WHERE usuario_id = ?')->execute([$userId]);
            }
        } catch (Throwable $e) { /* ignore */ }

        if (function_exists('notificar_usuario')) {
            notificar_usuario(
                $conn,
                $userId,
                'sucesso',
                'Conta verificada',
                'A sua identidade foi aprovada. Já pode operar normalmente na TrackMoz (missões e negociações).',
                BASE_URL . '/index.php'
            );
        }
        return ['ok' => true, 'verificado' => true, 'estado' => 'verificado'];
    }

    if ($temRejeitado) {
        $conn->prepare(
            "UPDATE usuarios SET estado_kyc = 'rejeitado', verificado = 0 WHERE id = ?"
        )->execute([$userId]);
        return ['ok' => true, 'verificado' => false, 'estado' => 'rejeitado'];
    }

    if ($docs['todos_enviados'] && (int)$u['kyc_dados_completos'] === 1) {
        $conn->prepare("UPDATE usuarios SET estado_kyc = 'em_analise', verificado = 0 WHERE id = ?")
             ->execute([$userId]);
        return ['ok' => true, 'verificado' => false, 'estado' => 'em_analise'];
    }

    $conn->prepare("UPDATE usuarios SET estado_kyc = 'dados_pendentes', verificado = 0 WHERE id = ?")
         ->execute([$userId]);
    return ['ok' => true, 'verificado' => false, 'estado' => 'dados_pendentes'];
}

function kyc_url_verificacao(): string
{
    return BASE_URL . '/pages/shared/verificacao-conta.php';
}

/**
 * Lembrete persistente ao utilizador com docs em falta (dedupe 12h).
 */
function kyc_sincronizar_lembrete_utilizador(PDO $conn, int $userId): void
{
    if ($userId <= 0) {
        return;
    }

    $info = kyc_obter_estado($conn, $userId);
    if (!$info['ok'] || ($info['usuario']['tipo_usuario'] ?? '') === 'admin') {
        return;
    }

    require_once __DIR__ . '/notificacoes-helpers.php';

    $titulo = 'Lembrete: documentos obrigatórios';

    if (!empty($info['pode_operar'])) {
        try {
            $conn->prepare(
                "UPDATE notificacoes SET lida = 1
                 WHERE usuario_id = ? AND titulo = ? AND lida = 0"
            )->execute([$userId, $titulo]);
        } catch (Throwable $e) { /* ignore */ }
        return;
    }

    $faltam = $info['faltam_docs'] ?? [];
    if (empty($faltam) && ($info['estado'] ?? '') === 'em_analise') {
        $mensagem = 'Os seus documentos estão em análise. Aguarde a aprovação da administração.';
    } else {
        $lista = $faltam ? implode('; ', array_values($faltam)) : 'documentos em falta';
        $mensagem = 'Para operar na TrackMoz (missões/propostas) precisa anexar e ter aprovados: '
            . $lista . '. Abra Verificação da conta.';
    }

    try {
        $stmt = $conn->prepare(
            "SELECT id FROM notificacoes
             WHERE usuario_id = ? AND titulo = ? AND lida = 0
               AND data_criacao > DATE_SUB(NOW(), INTERVAL 12 HOUR)
             LIMIT 1"
        );
        $stmt->execute([$userId, $titulo]);
        $existente = $stmt->fetchColumn();
        if ($existente) {
            $conn->prepare('UPDATE notificacoes SET mensagem = ?, link = ?, data_criacao = NOW() WHERE id = ?')
                 ->execute([$mensagem, kyc_url_verificacao(), $existente]);
        } else {
            notificar_usuario(
                $conn,
                $userId,
                'alerta',
                $titulo,
                $mensagem,
                kyc_url_verificacao()
            );
        }
    } catch (Throwable $e) {
        error_log('kyc_sincronizar_lembrete_utilizador: ' . $e->getMessage());
    }
}

/**
 * Reavalia em massa contas activas (útil após activar regras KYC).
 * @return array{bloqueados:int,ok:int}
 */
function kyc_revalidar_contas_activas(PDO $conn): array
{
    kyc_bootstrap($conn);
    $bloqueados = 0;
    $ok = 0;
    $stmt = $conn->query(
        "SELECT id FROM usuarios
         WHERE status = 'ativo'
           AND tipo_usuario IN ('caminhoneiro','empresa','transportador')"
    );
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $uid) {
        $info = kyc_obter_estado($conn, (int)$uid);
        if (!empty($info['pode_operar'])) {
            $ok++;
        } else {
            $bloqueados++;
            kyc_sincronizar_lembrete_utilizador($conn, (int)$uid);
        }
    }
    return ['bloqueados' => $bloqueados, 'ok' => $ok];
}

