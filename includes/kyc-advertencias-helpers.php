<?php
/**
 * Contas irregulares (KYC), advertências e remoção pelo admin.
 */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/kyc-helpers.php';
require_once __DIR__ . '/notificacoes-helpers.php';

/** Dias após a 1ª advertência sem regularizar (sugestão de remoção). */
const KYC_DIAS_APOS_ADVERTENCIA = 7;
/** Dias com conta activa sem docs aprovados antes de alertar o admin. */
const KYC_DIAS_SEM_DOCS_ALERTA = 3;

function kyc_advertencias_bootstrap(PDO $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    kyc_bootstrap($conn);

    try {
        $conn->exec(
            "CREATE TABLE IF NOT EXISTS kyc_advertencias (
                id INT AUTO_INCREMENT PRIMARY KEY,
                usuario_id INT NOT NULL,
                admin_id INT NOT NULL,
                mensagem TEXT NOT NULL,
                prazo_ate DATE NULL,
                criada_em TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                lida_pelo_user TINYINT(1) NOT NULL DEFAULT 0,
                KEY idx_usuario (usuario_id),
                KEY idx_prazo (prazo_ate)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $cols = $conn->query('SHOW COLUMNS FROM usuarios')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('kyc_prazo_regularizacao', $cols, true)) {
            $conn->exec('ALTER TABLE usuarios ADD COLUMN kyc_prazo_regularizacao DATE NULL');
        }
        if (!in_array('kyc_advertencias_count', $cols, true)) {
            $conn->exec('ALTER TABLE usuarios ADD COLUMN kyc_advertencias_count INT NOT NULL DEFAULT 0');
        }
    } catch (Throwable $e) {
        error_log('kyc_advertencias_bootstrap: ' . $e->getMessage());
    }
}

/**
 * Lista contas activas com situação KYC irregular.
 *
 * @return list<array<string,mixed>>
 */
function kyc_listar_contas_irregulares(PDO $conn): array
{
    kyc_advertencias_bootstrap($conn);

    $stmt = $conn->query(
        "SELECT id, nome, email, telefone, tipo_usuario, status, estado_kyc, verificado,
                kyc_dados_completos, kyc_enviado_em, kyc_prazo_regularizacao, kyc_advertencias_count,
                data_registro
         FROM usuarios
         WHERE status = 'ativo'
           AND tipo_usuario IN ('caminhoneiro','empresa','transportador')
         ORDER BY kyc_prazo_regularizacao IS NULL, kyc_prazo_regularizacao ASC, data_registro ASC"
    );

    $lista = [];
    $hoje = new DateTimeImmutable('today');

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
        $info = kyc_obter_estado($conn, (int)$u['id']);
        if (!empty($info['pode_operar'])) {
            continue;
        }

        $faltam = $info['faltam_docs'] ?? [];
        $prazo = $u['kyc_prazo_regularizacao'] ?? null;
        $diasPrazo = null;
        $prazoExpirado = false;
        if ($prazo) {
            $dt = new DateTimeImmutable($prazo);
            $diasPrazo = (int)$hoje->diff($dt)->format('%r%a');
            $prazoExpirado = $diasPrazo < 0;
        }

        $diasSemDocs = null;
        if (!empty($u['data_registro'])) {
            $reg = new DateTimeImmutable($u['data_registro']);
            $diasSemDocs = (int)$reg->diff($hoje)->days;
        }

        $nivel = 'warning';
        if ($prazoExpirado || (int)($u['kyc_advertencias_count'] ?? 0) >= 2) {
            $nivel = 'danger';
        } elseif ((int)($u['kyc_advertencias_count'] ?? 0) >= 1) {
            $nivel = 'warning';
        }

        $lista[] = [
            'usuario'         => $u,
            'estado_kyc'      => $info['estado'] ?? $u['estado_kyc'],
            'faltam_docs'     => $faltam,
            'advertencias'    => (int)($u['kyc_advertencias_count'] ?? 0),
            'prazo'           => $prazo,
            'dias_prazo'      => $diasPrazo,
            'prazo_expirado'  => $prazoExpirado,
            'dias_sem_docs'   => $diasSemDocs,
            'pode_remover'    => $prazoExpirado || (int)($u['kyc_advertencias_count'] ?? 0) >= 1
                || ($diasSemDocs !== null && $diasSemDocs >= KYC_DIAS_SEM_DOCS_ALERTA),
            'nivel'           => $nivel,
            'motivo'          => $info['erros'][0] ?? 'Documentação incompleta',
        ];
    }

    return $lista;
}

function kyc_contar_contas_irregulares(PDO $conn): int
{
    return count(kyc_listar_contas_irregulares($conn));
}

function kyc_contar_prazo_expirado(PDO $conn): int
{
    $n = 0;
    foreach (kyc_listar_contas_irregulares($conn) as $row) {
        if (!empty($row['prazo_expirado'])) {
            $n++;
        }
    }
    return $n;
}

/**
 * Envia advertência ao utilizador e define prazo de regularização.
 *
 * @return array{ok:bool,error?:string,prazo?:string}
 */
function kyc_enviar_advertencia(
    PDO $conn,
    int $usuarioId,
    int $adminId,
    string $mensagem,
    ?int $diasPrazo = null
): array {
    kyc_advertencias_bootstrap($conn);

    $diasPrazo = $diasPrazo ?? KYC_DIAS_APOS_ADVERTENCIA;
    $mensagem = trim($mensagem);
    if ($mensagem === '') {
        $mensagem = 'A sua conta está irregular: faltam documentos obrigatórios aprovados. '
            . 'Regularize em Verificação da conta. Caso contrário, a conta poderá ser bloqueada ou removida.';
    }

    $stmt = $conn->prepare(
        "SELECT id, nome, status, tipo_usuario FROM usuarios WHERE id = ?"
    );
    $stmt->execute([$usuarioId]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u || ($u['tipo_usuario'] ?? '') === 'admin') {
        return ['ok' => false, 'error' => 'Utilizador inválido.'];
    }
    if (($u['status'] ?? '') !== 'ativo') {
        return ['ok' => false, 'error' => 'Só é possível advertir contas activas.'];
    }

    $prazo = (new DateTimeImmutable('today'))->modify('+' . max(1, $diasPrazo) . ' days')->format('Y-m-d');

    $conn->prepare(
        'INSERT INTO kyc_advertencias (usuario_id, admin_id, mensagem, prazo_ate)
         VALUES (?,?,?,?)'
    )->execute([$usuarioId, $adminId, $mensagem, $prazo]);

    $conn->prepare(
        'UPDATE usuarios SET
            kyc_prazo_regularizacao = ?,
            kyc_advertencias_count = COALESCE(kyc_advertencias_count, 0) + 1
         WHERE id = ?'
    )->execute([$prazo, $usuarioId]);

    $msgNotif = $mensagem . "\n\nPrazo para regularizar: " . date('d/m/Y', strtotime($prazo))
        . '. Após esta data a administração pode bloquear ou remover a conta.';

    notificar_usuario(
        $conn,
        $usuarioId,
        'alerta',
        'Advertência: regularize a sua conta',
        $msgNotif,
        kyc_url_verificacao()
    );

    if (function_exists('registrar_log')) {
        registrar_log(
            $conn,
            $adminId,
            'advertencia_kyc',
            'usuario',
            $usuarioId,
            'Advertência KYC enviada. Prazo: ' . $prazo
        );
    }

    return ['ok' => true, 'prazo' => $prazo];
}

/**
 * Bloqueia conta irregular (não pode autenticar-se).
 */
function kyc_bloquear_conta(PDO $conn, int $usuarioId, int $adminId, string $motivo = ''): array
{
    kyc_advertencias_bootstrap($conn);
    $stmt = $conn->prepare('SELECT id, tipo_usuario, status FROM usuarios WHERE id = ?');
    $stmt->execute([$usuarioId]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u || ($u['tipo_usuario'] ?? '') === 'admin') {
        return ['ok' => false, 'error' => 'Utilizador inválido.'];
    }

    $conn->prepare("UPDATE usuarios SET status = 'bloqueado' WHERE id = ?")->execute([$usuarioId]);

    $motivo = trim($motivo) ?: 'Conta bloqueada por documentação irregular / prazo expirado.';
    notificar_usuario(
        $conn,
        $usuarioId,
        'alerta',
        'Conta bloqueada',
        $motivo . ' Contacte o suporte se já regularizou os documentos.',
        kyc_url_verificacao()
    );

    if (function_exists('registrar_log')) {
        registrar_log($conn, $adminId, 'bloquear', 'usuario', $usuarioId, $motivo);
    }

    return ['ok' => true];
}

/**
 * Remove (desactiva) conta irregular — autoridade do admin.
 * Soft-delete: status=inativo (mantém histórico).
 */
function kyc_remover_conta(PDO $conn, int $usuarioId, int $adminId, string $motivo = ''): array
{
    kyc_advertencias_bootstrap($conn);
    $stmt = $conn->prepare('SELECT id, tipo_usuario, status, nome FROM usuarios WHERE id = ?');
    $stmt->execute([$usuarioId]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u || ($u['tipo_usuario'] ?? '') === 'admin') {
        return ['ok' => false, 'error' => 'Utilizador inválido.'];
    }

    $motivo = trim($motivo) ?: 'Conta removida por não regularização documental no prazo.';

    $conn->prepare("UPDATE usuarios SET status = 'inativo', verificado = 0, estado_kyc = 'rejeitado' WHERE id = ?")
         ->execute([$usuarioId]);

    notificar_usuario(
        $conn,
        $usuarioId,
        'alerta',
        'Conta desactivada',
        $motivo,
        null
    );

    if (function_exists('registrar_log')) {
        registrar_log($conn, $adminId, 'remover_kyc', 'usuario', $usuarioId, $motivo);
    }

    return ['ok' => true];
}

/**
 * Histórico de advertências de um utilizador.
 */
function kyc_historico_advertencias(PDO $conn, int $usuarioId): array
{
    kyc_advertencias_bootstrap($conn);
    $stmt = $conn->prepare(
        "SELECT a.*, u.nome AS admin_nome
         FROM kyc_advertencias a
         LEFT JOIN usuarios u ON u.id = a.admin_id
         WHERE a.usuario_id = ?
         ORDER BY a.criada_em DESC"
    );
    $stmt->execute([$usuarioId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
