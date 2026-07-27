<?php
/**
 * Funções utilitárias partilhadas — URLs, escape, status, documentos.
 */

if (!defined('BASE_URL') && file_exists(__DIR__ . '/../config/app.php')) {
    require_once __DIR__ . '/../config/app.php';
}

function ensure_session_started(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

function e(?string $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * Flash messages (persistem por 1 redirect).
 * - flash_set('success', '...')
 * - $msg = flash_get('success')
 */
function flash_set(string $key, string $message): void
{
    ensure_session_started();
    if (!isset($_SESSION['_flash'])) {
        $_SESSION['_flash'] = [];
    }
    $_SESSION['_flash'][$key] = $message;
}

function flash_get(string $key): ?string
{
    ensure_session_started();
    if (!isset($_SESSION['_flash'][$key])) {
        return null;
    }
    $val = (string)$_SESSION['_flash'][$key];
    unset($_SESSION['_flash'][$key]);
    return $val;
}

/**
 * CSRF
 * - Em formulários: echo csrf_field()
 * - Em handlers POST: require_csrf()
 */
function csrf_token(): string
{
    ensure_session_started();
    if (empty($_SESSION['_csrf_token']) || !is_string($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf_token'];
}

function csrf_field(): string
{
    $t = csrf_token();
    return '<input type="hidden" name="csrf_token" value="' . e($t) . '">';
}

function csrf_validate(?string $provided): bool
{
    ensure_session_started();
    $expected = $_SESSION['_csrf_token'] ?? null;
    if (!is_string($expected) || $expected === '') {
        return false;
    }
    if (!is_string($provided) || $provided === '') {
        return false;
    }
    return hash_equals($expected, $provided);
}

function require_csrf(): void
{
    $provided = $_POST['csrf_token'] ?? null;
    if (!csrf_validate(is_string($provided) ? $provided : null)) {
        http_response_code(419);
        echo 'CSRF token inválido.';
        exit;
    }
}

function require_csrf_json(): void
{
    $provided = null;
    if (isset($_POST['csrf_token']) && is_string($_POST['csrf_token'])) {
        $provided = $_POST['csrf_token'];
    } elseif (isset($_SERVER['HTTP_X_CSRF_TOKEN']) && is_string($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        $provided = $_SERVER['HTTP_X_CSRF_TOKEN'];
    }

    if (!csrf_validate($provided)) {
        http_response_code(419);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'ok'      => false,
            'message' => 'Sessão expirada. Recarregue a página e tente novamente.',
            'error'   => 'CSRF token inválido',
        ]);
        exit;
    }
}

/** URL pública de ficheiro em uploads/ */
function upload_url(string $subdir, string $filename): string
{
    $subdir   = trim(str_replace('\\', '/', $subdir), '/');
    $filename = ltrim(str_replace('\\', '/', $filename), '/');
    return BASE_URL . '/uploads/' . $subdir . '/' . rawurlencode(basename($filename));
}

/** Caminho absoluto no disco para um ficheiro em uploads/ */
function upload_path(string $subdir, string $filename): string
{
    $base = realpath(__DIR__ . '/../uploads') ?: (__DIR__ . '/../uploads');
    return $base . DIRECTORY_SEPARATOR . trim($subdir, '/\\') . DIRECTORY_SEPARATOR . basename($filename);
}

/** Página segura de visualização de documento de utilizador */
function documento_view_url(int $documentoId): string
{
    return BASE_URL . '/pages/ver-documento.php?id=' . $documentoId;
}

/** Documento de missão (tabela documentos_missao) */
function documento_missao_url(string $arquivo): string
{
    return upload_url('documentos_missao', $arquivo);
}

function status_missao_label(string $status): string
{
    return match ($status) {
        'aberta'                 => 'Aberta',
        'em_negociacao'          => 'Em negociação',
        'aceita'                 => 'Agendada',
        'em_andamento'           => 'Em andamento',
        'em_transito'            => 'Em trânsito',
        'em_entrega'             => 'Em entrega',
        'emergencia_reportada'   => 'Emergência reportada',
        'aguardando_confirmacao' => 'Aguard. confirmação',
        'entrega_confirmada'     => 'Entrega confirmada',
        'concluida'              => 'Concluída',
        'cancelada'              => 'Cancelada',
        'emergencia'             => 'Emergência',
        'chegou_destino'         => 'Chegou ao destino',
        'aguardando_codigo'      => 'Aguardando código',
        'codigo_validado'        => 'Código validado',
        'entrega_recusada'       => 'Entrega recusada',
        'entrega_divergencia'    => 'Entrega com divergência',
        default                  => ucfirst(str_replace('_', ' ', $status)),
    };
}

function status_missao_badge(string $status): string
{
    return match ($status) {
        'aberta', 'em_negociacao'     => 'warning',
        'aceita', 'em_andamento',
        'em_transito', 'em_entrega'  => 'primary',
        'aguardando_confirmacao'    => 'secondary',
        'entrega_confirmada'        => 'success',
        'concluida'                 => 'success',
        'cancelada'                 => 'danger',
        'emergencia_reportada'      => 'danger',
        'emergencia'                => 'danger',
        default                     => 'secondary',
    };
}

/** HTML do badge de estado da missão (pronto a imprimir). */
function status_missao_badge_html(string $status): string
{
    $cls = status_missao_badge($status);
    return '<span class="tm-soft-badge tm-soft-' . e($cls) . '">' . e(status_missao_label($status)) . '</span>';
}

function status_documento_badge(string $status): string
{
    return match ($status) {
        'pendente'  => 'warning',
        'aprovado'  => 'success',
        'rejeitado' => 'danger',
        default     => 'secondary',
    };
}

/** Extensões que podem ser pré-visualizadas no browser */
function documento_pode_previsualizar(string $filename): bool
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'], true);
}

function documento_tipo_mime(string $filename): string
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return match ($ext) {
        'jpg', 'jpeg' => 'image/jpeg',
        'png'         => 'image/png',
        'gif'         => 'image/gif',
        'webp'        => 'image/webp',
        'pdf'         => 'application/pdf',
        default       => 'application/octet-stream',
    };
}

function table_has_column(PDO $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
    );
    $stmt->execute([':t' => $table, ':c' => $column]);
    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Registar log de auditoria no sistema.
 */
function registrar_log(PDO $conn, ?int $usuarioId, string $tipoAcao, string $entidade, ?int $entidadeId, string $descricao, ?array $dadosAnteriores = null): void
{
    try {
        $stmt = $conn->prepare(
            "INSERT INTO logs_sistema
             (usuario_id, tipo_acao, entidade, entidade_id, descricao, dados_anteriores, ip_address, user_agent)
             VALUES (:uid, :acao, :entidade, :eid, :desc, :dados, :ip, :ua)"
        );
        $stmt->execute([
            ':uid'      => $usuarioId,
            ':acao'     => $tipoAcao,
            ':entidade' => $entidade,
            ':eid'      => $entidadeId,
            ':desc'     => $descricao,
            ':dados'    => $dadosAnteriores ? json_encode($dadosAnteriores, JSON_UNESCAPED_UNICODE) : null,
            ':ip'       => $_SERVER['REMOTE_ADDR'] ?? null,
            ':ua'       => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    } catch (PDOException $e) {
        error_log('Erro ao registrar log: ' . $e->getMessage());
    }
}
