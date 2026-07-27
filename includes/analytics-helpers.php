<?php
/**
 * Tracking leve de visitas (página pública) e acessos (login/logout).
 */

if (!function_exists('tmz_analytics_ensure_table')) {
    function tmz_analytics_ensure_table(PDO $conn): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $conn->exec("
                CREATE TABLE IF NOT EXISTS analytics_eventos (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    tipo ENUM('pageview','login','logout','heartbeat') NOT NULL,
                    usuario_id INT NULL DEFAULT NULL,
                    path VARCHAR(255) NULL DEFAULT NULL,
                    ip VARCHAR(45) NULL DEFAULT NULL,
                    user_agent VARCHAR(255) NULL DEFAULT NULL,
                    session_key VARCHAR(64) NULL DEFAULT NULL,
                    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_tipo_data (tipo, criado_em),
                    KEY idx_session_tipo (session_key, tipo, criado_em),
                    KEY idx_usuario_data (usuario_id, criado_em)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (Throwable $e) {
            // silencioso — não quebrar o site
        }
    }
}

if (!function_exists('tmz_analytics_session_key')) {
    function tmz_analytics_session_key(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        if (empty($_SESSION['_tmz_vid'])) {
            try {
                $_SESSION['_tmz_vid'] = bin2hex(random_bytes(16));
            } catch (Throwable $e) {
                $_SESSION['_tmz_vid'] = sha1(uniqid((string)mt_rand(), true));
            }
        }
        return (string)$_SESSION['_tmz_vid'];
    }
}

if (!function_exists('tmz_analytics_client_ip')) {
    function tmz_analytics_client_ip(): string
    {
        $candidates = [
            $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
            $_SERVER['REMOTE_ADDR'] ?? '',
        ];
        foreach ($candidates as $raw) {
            $raw = trim((string)$raw);
            if ($raw === '') continue;
            // X-Forwarded-For pode ter lista
            $ip = trim(explode(',', $raw)[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
        return '';
    }
}

if (!function_exists('tmz_analytics_track')) {
    /**
     * Regista evento. Pageviews são deduplicadas por sessão (~30 min).
     */
    function tmz_analytics_track(PDO $conn, string $tipo, ?int $usuarioId = null, ?string $path = null): void
    {
        $tipo = strtolower(trim($tipo));
        if (!in_array($tipo, ['pageview', 'login', 'logout', 'heartbeat'], true)) {
            return;
        }

        try {
            tmz_analytics_ensure_table($conn);
            $sessionKey = tmz_analytics_session_key();
            $ip = tmz_analytics_client_ip();
            $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
            $path = $path !== null ? substr($path, 0, 255) : substr((string)($_SERVER['REQUEST_URI'] ?? '/'), 0, 255);

            // Evitar spam de pageviews da mesma sessão
            if ($tipo === 'pageview') {
                $stmt = $conn->prepare("
                    SELECT id FROM analytics_eventos
                    WHERE tipo = 'pageview' AND session_key = ?
                      AND criado_em >= (NOW() - INTERVAL 30 MINUTE)
                    LIMIT 1
                ");
                $stmt->execute([$sessionKey]);
                if ($stmt->fetchColumn()) {
                    return;
                }
            }

            // Heartbeat: no máximo 1 por utilizador a cada 2 minutos
            if ($tipo === 'heartbeat' && $usuarioId) {
                $stmt = $conn->prepare("
                    SELECT id FROM analytics_eventos
                    WHERE tipo = 'heartbeat' AND usuario_id = ?
                      AND criado_em >= (NOW() - INTERVAL 2 MINUTE)
                    LIMIT 1
                ");
                $stmt->execute([$usuarioId]);
                if ($stmt->fetchColumn()) {
                    return;
                }
            }

            $stmt = $conn->prepare("
                INSERT INTO analytics_eventos (tipo, usuario_id, path, ip, user_agent, session_key)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$tipo, $usuarioId, $path, $ip ?: null, $ua ?: null, $sessionKey]);
        } catch (Throwable $e) {
            // não interrompe o fluxo da app
        }
    }
}

if (!function_exists('tmz_analytics_resumo_hoje')) {
    /**
     * @return array{
     *   pageviews_hoje:int,
     *   visitantes_unicos_hoje:int,
     *   logins_hoje:int,
     *   logouts_hoje:int,
     *   online_agora:int,
     *   online_lista:array<int,array{id:int,nome:string,tipo:string,ultimo:string}>
     * }
     */
    function tmz_analytics_resumo_hoje(PDO $conn): array
    {
        $out = [
            'pageviews_hoje' => 0,
            'visitantes_unicos_hoje' => 0,
            'logins_hoje' => 0,
            'logouts_hoje' => 0,
            'online_agora' => 0,
            'online_lista' => [],
        ];

        try {
            tmz_analytics_ensure_table($conn);

            $out['pageviews_hoje'] = (int)$conn->query("
                SELECT COUNT(*) FROM analytics_eventos
                WHERE tipo = 'pageview' AND DATE(criado_em) = CURDATE()
            ")->fetchColumn();

            $out['visitantes_unicos_hoje'] = (int)$conn->query("
                SELECT COUNT(DISTINCT session_key) FROM analytics_eventos
                WHERE tipo = 'pageview' AND DATE(criado_em) = CURDATE()
                  AND session_key IS NOT NULL AND session_key <> ''
            ")->fetchColumn();

            $out['logins_hoje'] = (int)$conn->query("
                SELECT COUNT(*) FROM analytics_eventos
                WHERE tipo = 'login' AND DATE(criado_em) = CURDATE()
            ")->fetchColumn();

            $out['logouts_hoje'] = (int)$conn->query("
                SELECT COUNT(*) FROM analytics_eventos
                WHERE tipo = 'logout' AND DATE(criado_em) = CURDATE()
            ")->fetchColumn();

            // Online = utilizadores com login/heartbeat nos últimos 15 min sem logout depois
            $stmt = $conn->query("
                SELECT u.id, u.nome, u.tipo_usuario AS tipo,
                       MAX(a.criado_em) AS ultimo
                FROM analytics_eventos a
                INNER JOIN usuarios u ON u.id = a.usuario_id
                WHERE a.tipo IN ('login','heartbeat')
                  AND a.criado_em >= (NOW() - INTERVAL 15 MINUTE)
                  AND a.usuario_id IS NOT NULL
                  AND NOT EXISTS (
                      SELECT 1 FROM analytics_eventos x
                      WHERE x.tipo = 'logout'
                        AND x.usuario_id = a.usuario_id
                        AND x.criado_em > a.criado_em
                  )
                GROUP BY u.id, u.nome, u.tipo_usuario
                ORDER BY ultimo DESC
                LIMIT 50
            ");
            $lista = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $out['online_lista'] = array_map(static function ($row) {
                return [
                    'id' => (int)$row['id'],
                    'nome' => (string)$row['nome'],
                    'tipo' => (string)$row['tipo'],
                    'ultimo' => (string)$row['ultimo'],
                ];
            }, $lista);
            $out['online_agora'] = count($out['online_lista']);
        } catch (Throwable $e) {
            // defaults
        }

        return $out;
    }
}

if (!function_exists('tmz_analytics_series')) {
    /**
     * Séries para gráficos: hoje por hora + últimos 7 dias.
     *
     * @return array{
     *   horas: array{labels: string[], pageviews: int[], logins: int[], logouts: int[]},
     *   dias: array{labels: string[], pageviews: int[], unicos: int[], logins: int[]}
     * }
     */
    function tmz_analytics_series(PDO $conn): array
    {
        $horas = [
            'labels' => [],
            'pageviews' => array_fill(0, 24, 0),
            'logins' => array_fill(0, 24, 0),
            'logouts' => array_fill(0, 24, 0),
        ];
        for ($h = 0; $h < 24; $h++) {
            $horas['labels'][] = sprintf('%02d:00', $h);
        }

        $dias = [
            'labels' => [],
            'pageviews' => [],
            'unicos' => [],
            'logins' => [],
        ];
        $mapDias = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $label = date('d/m', strtotime($d));
            $dias['labels'][] = $label;
            $dias['pageviews'][] = 0;
            $dias['unicos'][] = 0;
            $dias['logins'][] = 0;
            $mapDias[$d] = 6 - $i;
        }

        try {
            tmz_analytics_ensure_table($conn);

            $stmt = $conn->query("
                SELECT HOUR(criado_em) AS h, tipo, COUNT(*) AS total
                FROM analytics_eventos
                WHERE DATE(criado_em) = CURDATE()
                  AND tipo IN ('pageview','login','logout')
                GROUP BY HOUR(criado_em), tipo
            ");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $h = (int)$row['h'];
                $t = (string)$row['tipo'];
                $n = (int)$row['total'];
                if ($h < 0 || $h > 23) continue;
                if ($t === 'pageview') $horas['pageviews'][$h] = $n;
                if ($t === 'login') $horas['logins'][$h] = $n;
                if ($t === 'logout') $horas['logouts'][$h] = $n;
            }

            $stmt = $conn->query("
                SELECT DATE(criado_em) AS d, tipo, COUNT(*) AS total,
                       COUNT(DISTINCT CASE WHEN tipo = 'pageview' THEN session_key END) AS unicos
                FROM analytics_eventos
                WHERE criado_em >= (CURDATE() - INTERVAL 6 DAY)
                  AND tipo IN ('pageview','login')
                GROUP BY DATE(criado_em), tipo
            ");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $d = (string)$row['d'];
                if (!isset($mapDias[$d])) continue;
                $idx = $mapDias[$d];
                $t = (string)$row['tipo'];
                if ($t === 'pageview') {
                    $dias['pageviews'][$idx] = (int)$row['total'];
                    $dias['unicos'][$idx] = (int)$row['unicos'];
                }
                if ($t === 'login') {
                    $dias['logins'][$idx] = (int)$row['total'];
                }
            }
        } catch (Throwable $e) {
            // defaults vazios
        }

        return ['horas' => $horas, 'dias' => $dias];
    }
}

if (!function_exists('tmz_analytics_dashboard_payload')) {
    function tmz_analytics_dashboard_payload(PDO $conn): array
    {
        $resumo = tmz_analytics_resumo_hoje($conn);
        $series = tmz_analytics_series($conn);
        return array_merge($resumo, ['series' => $series]);
    }
}
