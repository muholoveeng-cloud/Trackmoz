<?php
/**
 * OTP de confirmação de entrega — geração, hash, tentativas e auditoria.
 */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/missao-helpers.php';

const OTP_TENTATIVAS_MAX = 5;
const OTP_EXPIRACAO_HORAS_PADRAO = 48;

function otp_entrega_bootstrap(PDO $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $conn->exec("
            CREATE TABLE IF NOT EXISTS otp_codes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                missao_id INT NOT NULL,
                codigo VARCHAR(6) DEFAULT NULL,
                codigo_hash VARCHAR(255) DEFAULT NULL,
                destinatario_telefone VARCHAR(20) DEFAULT NULL,
                destinatario_email VARCHAR(100) DEFAULT NULL,
                gerado_em TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                expira_em TIMESTAMP NULL NOT NULL,
                usado TINYINT(1) DEFAULT 0,
                usado_em TIMESTAMP NULL DEFAULT NULL,
                usado_por VARCHAR(100) DEFAULT NULL,
                tentativas INT DEFAULT 0,
                bloqueado TINYINT(1) DEFAULT 0,
                bloqueado_ate TIMESTAMP NULL DEFAULT NULL,
                gerado_por INT DEFAULT NULL,
                regenerado_por INT DEFAULT NULL,
                regenerado_em TIMESTAMP NULL DEFAULT NULL,
                UNIQUE KEY uq_missao (missao_id),
                KEY idx_expira (expira_em)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $conn->exec("
            CREATE TABLE IF NOT EXISTS otp_tentativas (
                id INT AUTO_INCREMENT PRIMARY KEY,
                missao_id INT NOT NULL,
                codigo_tentado VARCHAR(20) DEFAULT NULL,
                sucesso TINYINT(1) DEFAULT 0,
                ip VARCHAR(45) DEFAULT NULL,
                user_agent VARCHAR(255) DEFAULT NULL,
                usuario_id INT DEFAULT NULL,
                latitude DECIMAL(10,8) DEFAULT NULL,
                longitude DECIMAL(11,8) DEFAULT NULL,
                criado_em TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_missao (missao_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $cols = $conn->query('SHOW COLUMNS FROM missoes')->fetchAll(PDO::FETCH_COLUMN);

        if (!in_array('status_entrega', $cols, true)) {
            $conn->exec("ALTER TABLE missoes ADD COLUMN status_entrega VARCHAR(40) DEFAULT NULL AFTER status_viagem");
        }
        if (!in_array('modo_confirmacao_entrega', $cols, true)) {
            $conn->exec("ALTER TABLE missoes ADD COLUMN modo_confirmacao_entrega VARCHAR(30) DEFAULT 'otp' AFTER status_entrega");
        }
        if (!in_array('otp_expiracao_horas', $cols, true)) {
            $conn->exec('ALTER TABLE missoes ADD COLUMN otp_expiracao_horas INT DEFAULT 48 AFTER modo_confirmacao_entrega');
        }

        $otpCols = $conn->query('SHOW COLUMNS FROM otp_codes')->fetchAll(PDO::FETCH_COLUMN);
        foreach ([
            'codigo_hash'     => 'VARCHAR(255) DEFAULT NULL',
            'tentativas'      => 'INT DEFAULT 0',
            'bloqueado'       => 'TINYINT(1) DEFAULT 0',
            'bloqueado_ate'   => 'TIMESTAMP NULL DEFAULT NULL',
            'gerado_por'      => 'INT DEFAULT NULL',
            'regenerado_por'  => 'INT DEFAULT NULL',
            'regenerado_em'   => 'TIMESTAMP NULL DEFAULT NULL',
        ] as $col => $def) {
            if (!in_array($col, $otpCols, true)) {
                $conn->exec("ALTER TABLE otp_codes ADD COLUMN {$col} {$def}");
            }
        }

        // Schema legado: codigo era NOT NULL — permitir NULL e guardar texto só enquanto activo
        try {
            $colCodigo = $conn->query("SHOW COLUMNS FROM otp_codes LIKE 'codigo'")->fetch(PDO::FETCH_ASSOC);
            if ($colCodigo && strtoupper((string)($colCodigo['Null'] ?? '')) === 'NO') {
                $conn->exec('ALTER TABLE otp_codes MODIFY COLUMN codigo VARCHAR(10) NULL DEFAULT NULL');
            }
        } catch (Throwable $e) {
            error_log('otp_entrega_bootstrap codigo nullable: ' . $e->getMessage());
        }
    } catch (PDOException $e) {
        error_log('otp_entrega_bootstrap: ' . $e->getMessage());
    }
}

function otp_gerar_codigo(): string
{
    return str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
}

function otp_calcular_expiracao(?string $prazoEntrega, int $horasConfig): string
{
    $porHoras = date('Y-m-d H:i:s', strtotime('+' . max(1, $horasConfig) . ' hours'));
    if ($prazoEntrega && strtotime($prazoEntrega) > time()) {
        $porPrazo = date('Y-m-d H:i:s', strtotime($prazoEntrega . ' 23:59:59'));
        return strtotime($porPrazo) < strtotime($porHoras) ? $porPrazo : $porHoras;
    }
    return $porHoras;
}

/**
 * Gera ou regenera OTP para uma missão. Retorna código em texto (só para empresa/admin).
 *
 * @return array{ok: bool, codigo?: string, expira_em?: string, error?: string}
 */
function otp_gerar_para_missao(PDO $conn, int $missao_id, int $gerado_por, bool $regenerar = false): array
{
    otp_entrega_bootstrap($conn);

    $stmt = $conn->prepare('SELECT id, prazo_entrega, otp_expiracao_horas FROM missoes WHERE id = ?');
    $stmt->execute([$missao_id]);
    $missao = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$missao) {
        return ['ok' => false, 'error' => 'Missão não encontrada'];
    }

    $horas = (int)($missao['otp_expiracao_horas'] ?? OTP_EXPIRACAO_HORAS_PADRAO);
    $codigo = otp_gerar_codigo();
    $hash   = password_hash($codigo, PASSWORD_DEFAULT);
    $expira = otp_calcular_expiracao($missao['prazo_entrega'] ?? null, $horas);

    $stmt = $conn->prepare('SELECT id FROM otp_codes WHERE missao_id = ?');
    $stmt->execute([$missao_id]);
    $existe = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existe) {
        $conn->prepare("
            UPDATE otp_codes SET
                codigo = ?,
                codigo_hash = ?,
                expira_em = ?,
                usado = 0,
                usado_em = NULL,
                usado_por = NULL,
                tentativas = 0,
                bloqueado = 0,
                bloqueado_ate = NULL,
                regenerado_por = ?,
                regenerado_em = NOW()
            WHERE missao_id = ?
        ")->execute([$codigo, $hash, $expira, $gerado_por, $missao_id]);
    } else {
        $conn->prepare("
            INSERT INTO otp_codes (missao_id, codigo, codigo_hash, expira_em, gerado_por)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([$missao_id, $codigo, $hash, $expira, $gerado_por]);
    }

    $conn->prepare("UPDATE missoes SET modo_confirmacao_entrega = 'otp' WHERE id = ?")
         ->execute([$missao_id]);

    registrar_log(
        $conn,
        $gerado_por,
        $regenerar ? 'otp_regenerar' : 'otp_gerar',
        'missao',
        $missao_id,
        ($regenerar ? 'OTP regenerado' : 'OTP gerado') . ' para entrega'
    );

    return ['ok' => true, 'codigo' => $codigo, 'expira_em' => $expira];
}

/**
 * @return array{ok: bool, error?: string, bloqueado?: bool}
 */
function otp_registrar_tentativa(
    PDO $conn,
    int $missao_id,
    string $codigo_tentado,
    bool $sucesso,
    ?int $usuario_id = null,
    ?float $latitude = null,
    ?float $longitude = null
): array {
    otp_entrega_bootstrap($conn);

    $ip  = $_SERVER['REMOTE_ADDR'] ?? null;
    $ua  = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : null;

    $conn->prepare("
        INSERT INTO otp_tentativas (missao_id, codigo_tentado, sucesso, ip, user_agent, usuario_id, latitude, longitude)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $missao_id,
        substr($codigo_tentado, 0, 20),
        $sucesso ? 1 : 0,
        $ip,
        $ua,
        $usuario_id,
        $latitude,
        $longitude,
    ]);

    if ($sucesso) {
        return ['ok' => true];
    }

    $stmt = $conn->prepare('SELECT tentativas, bloqueado FROM otp_codes WHERE missao_id = ?');
    $stmt->execute([$missao_id]);
    $otp = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$otp) {
        return ['ok' => false, 'error' => 'Código OTP não configurado para esta missão'];
    }

    $tentativas = (int)$otp['tentativas'] + 1;
    $bloqueado  = $tentativas >= OTP_TENTATIVAS_MAX;

    $conn->prepare('UPDATE otp_codes SET tentativas = ?, bloqueado = ?, bloqueado_ate = ? WHERE missao_id = ?')
         ->execute([
             $tentativas,
             $bloqueado ? 1 : 0,
             $bloqueado ? date('Y-m-d H:i:s', strtotime('+1 hour')) : null,
             $missao_id,
         ]);

    if ($bloqueado) {
        $stmt = $conn->prepare('SELECT empresa_id, titulo FROM missoes WHERE id = ?');
        $stmt->execute([$missao_id]);
        $m = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($m && !empty($m['empresa_id'])) {
            notificar_usuario(
                $conn,
                (int)$m['empresa_id'],
                'alerta',
                'OTP bloqueado — Missão #' . $missao_id,
                'Foram excedidas ' . OTP_TENTATIVAS_MAX . ' tentativas incorrectas de confirmação de entrega.',
                BASE_URL . '/pages/contratante/detalhes-missao.php?id=' . $missao_id
            );
        }
        registrar_log($conn, $usuario_id, 'otp_bloqueado', 'missao', $missao_id, 'OTP bloqueado após tentativas inválidas');
        return ['ok' => false, 'error' => 'Código bloqueado após várias tentativas incorrectas. Contacte a empresa.', 'bloqueado' => true];
    }

    $restantes = OTP_TENTATIVAS_MAX - $tentativas;
    return ['ok' => false, 'error' => "Código incorrecto. Restam {$restantes} tentativa(s)."];
}

/**
 * Valida OTP digitado pelo motorista.
 *
 * @return array{ok: bool, error?: string}
 */
function otp_validar_codigo(PDO $conn, int $missao_id, string $codigo, ?int $usuario_id = null, ?float $lat = null, ?float $lng = null): array
{
    otp_entrega_bootstrap($conn);

    $codigo = preg_replace('/\D/', '', $codigo);
    if (strlen($codigo) !== 6) {
        return ['ok' => false, 'error' => 'Código deve ter 6 dígitos'];
    }

    $stmt = $conn->prepare('SELECT * FROM otp_codes WHERE missao_id = ?');
    $stmt->execute([$missao_id]);
    $otp = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$otp) {
        return ['ok' => false, 'error' => 'Código de entrega não configurado. Contacte a empresa.'];
    }

    if ((int)$otp['usado'] === 1) {
        return ['ok' => false, 'error' => 'Este código já foi utilizado'];
    }

    if ((int)$otp['bloqueado'] === 1) {
        if (!empty($otp['bloqueado_ate']) && strtotime($otp['bloqueado_ate']) > time()) {
            otp_registrar_tentativa($conn, $missao_id, $codigo, false, $usuario_id, $lat, $lng);
            return ['ok' => false, 'error' => 'Confirmação bloqueada. Contacte a empresa para regenerar o código.'];
        }
        $conn->prepare('UPDATE otp_codes SET bloqueado = 0, tentativas = 0, bloqueado_ate = NULL WHERE missao_id = ?')
             ->execute([$missao_id]);
    }

    if (strtotime($otp['expira_em']) < time()) {
        otp_registrar_tentativa($conn, $missao_id, $codigo, false, $usuario_id, $lat, $lng);
        return ['ok' => false, 'error' => 'Código expirado. Peça à empresa um novo código.'];
    }

    $hash = $otp['codigo_hash'] ?? '';
    if ($hash === '' && !empty($otp['codigo'])) {
        $valido = hash_equals($otp['codigo'], $codigo);
    } else {
        $valido = password_verify($codigo, $hash);
    }

    if (!$valido) {
        return otp_registrar_tentativa($conn, $missao_id, $codigo, false, $usuario_id, $lat, $lng);
    }

    otp_registrar_tentativa($conn, $missao_id, '******', true, $usuario_id, $lat, $lng);

    return ['ok' => true];
}

function otp_marcar_usado(PDO $conn, int $missao_id, string $usado_por): void
{
    $conn->prepare('UPDATE otp_codes SET usado = 1, usado_em = NOW(), usado_por = ?, codigo = NULL WHERE missao_id = ?')
         ->execute([$usado_por, $missao_id]);
}

/**
 * Devolve o código em texto (só para empresa reenviar). Null se usado/inexistente.
 */
function otp_codigo_texto_activo(PDO $conn, int $missao_id): ?string
{
    otp_entrega_bootstrap($conn);
    try {
        $stmt = $conn->prepare(
            'SELECT codigo, usado, expira_em FROM otp_codes WHERE missao_id = ? LIMIT 1'
        );
        $stmt->execute([$missao_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int)($row['usado'] ?? 0) === 1) {
            return null;
        }
        if (!empty($row['expira_em']) && strtotime($row['expira_em']) < time()) {
            return null;
        }
        $codigo = preg_replace('/\D/', '', (string)($row['codigo'] ?? ''));
        return strlen($codigo) === 6 ? $codigo : null;
    } catch (Throwable $e) {
        return null;
    }
}

/** Distância em metros entre dois pontos GPS (Haversine). */
function gps_distancia_metros(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $r = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/**
 * Verifica proximidade ao destino (raio padrão 500m).
 *
 * @return array{ok: bool, distancia_m?: float, error?: string}
 */
function validar_proximidade_destino(PDO $conn, int $missao_id, float $lat, float $lng, int $raioMetros = 500): array
{
    $stmt = $conn->prepare("
        SELECT ld.latitude AS dest_lat, ld.longitude AS dest_lng
        FROM missoes m
        LEFT JOIN locais ld ON m.local_destino_id = ld.id
        WHERE m.id = ?
    ");
    $stmt->execute([$missao_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || $row['dest_lat'] === null || $row['dest_lng'] === null) {
        return ['ok' => true, 'distancia_m' => null];
    }

    $dist = gps_distancia_metros($lat, $lng, (float)$row['dest_lat'], (float)$row['dest_lng']);
    if ($dist > $raioMetros) {
        return [
            'ok'          => false,
            'distancia_m' => round($dist),
            'error'       => 'Você está a ' . round($dist) . 'm do destino. Aproxime-se para confirmar a entrega.',
        ];
    }

    return ['ok' => true, 'distancia_m' => round($dist)];
}

/** Metadados OTP para exibição na empresa (sem revelar código). */
function otp_info_missao(PDO $conn, int $missao_id): ?array
{
    otp_entrega_bootstrap($conn);
    $stmt = $conn->prepare('SELECT * FROM otp_codes WHERE missao_id = ?');
    $stmt->execute([$missao_id]);
    $otp = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$otp) {
        return null;
    }
    return [
        'expira_em'  => $otp['expira_em'],
        'usado'      => (int)$otp['usado'] === 1,
        'bloqueado'  => (int)$otp['bloqueado'] === 1,
        'tentativas' => (int)$otp['tentativas'],
        'expirado'   => strtotime($otp['expira_em']) < time(),
    ];
}
