<?php
/**
 * API Alertas do Motorista
 * GET: atrasos, lembretes de prazo, missões próximas (≤20km), timers
 * POST opcional: latitude, longitude (actualiza posição e melhora proximidade)
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

include_once(__DIR__ . '/../config/app.php');
include_once(__DIR__ . '/../config/database.php');
include_once(__DIR__ . '/../includes/tms-geo.php');
include_once(__DIR__ . '/../includes/notificacoes-helpers.php');
include_once(__DIR__ . '/../includes/helpers.php');

if (!isset($_SESSION['user_id']) || ($_SESSION['user_type'] ?? '') !== 'caminhoneiro') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Não autenticado']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$RAIO_M = 20000; // 20 km

// Actualizar GPS se enviado
$input = $_GET;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $json = $raw ? json_decode($raw, true) : null;
    if (is_array($json)) {
        $input = array_merge($input, $json);
    } else {
        $input = array_merge($input, $_POST);
    }
}

$lat = isset($input['latitude']) ? (float)$input['latitude'] : null;
$lng = isset($input['longitude']) ? (float)$input['longitude'] : null;

if ($lat !== null && $lng !== null
    && $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180) {
    try {
        $conn->prepare(
            'UPDATE perfil_caminhoneiro
             SET ultima_localizacao_lat = :lat, ultima_localizacao_lng = :lng,
                 latitude = :lat2, longitude = :lng2,
                 ultima_atualizacao_local = NOW()
             WHERE usuario_id = :uid'
        )->execute([
            ':lat' => $lat, ':lng' => $lng,
            ':lat2' => $lat, ':lng2' => $lng,
            ':uid' => $userId,
        ]);
    } catch (Throwable $e) {
        try {
            $conn->prepare(
                'UPDATE perfil_caminhoneiro
                 SET ultima_localizacao_lat = :lat, ultima_localizacao_lng = :lng,
                     ultima_atualizacao_local = NOW()
                 WHERE usuario_id = :uid'
            )->execute([':lat' => $lat, ':lng' => $lng, ':uid' => $userId]);
        } catch (Throwable $e2) {
            error_log('driver-alerts gps: ' . $e2->getMessage());
        }
    }
}

// Posição do motorista
$gps = ['lat' => null, 'lng' => null, 'age_min' => null];
try {
    $stmt = $conn->prepare(
        "SELECT COALESCE(ultima_localizacao_lat, latitude) AS lat,
                COALESCE(ultima_localizacao_lng, longitude) AS lng,
                ultima_atualizacao_local
         FROM perfil_caminhoneiro WHERE usuario_id = :uid"
    );
    $stmt->execute([':uid' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && $row['lat'] !== null && $row['lng'] !== null) {
        $gps['lat'] = (float)$row['lat'];
        $gps['lng'] = (float)$row['lng'];
        if (!empty($row['ultima_atualizacao_local'])) {
            $gps['age_min'] = (int)max(0, (time() - strtotime($row['ultima_atualizacao_local'])) / 60);
        }
    }
} catch (Throwable $e) {
    error_log('driver-alerts perfil: ' . $e->getMessage());
}

if ($lat !== null && $lng !== null) {
    $gps['lat'] = $lat;
    $gps['lng'] = $lng;
    $gps['age_min'] = 0;
}

$alerts = [];
$timers = [];
$statusAtivos = "('aceita','em_andamento','em_transito','em_entrega','aguardando_confirmacao','emergencia_reportada','emergencia')";

// ── Nova atribuição (ex.: transportadora → independente): popup modal ──
try {
    $stmt = $conn->prepare(
        "SELECT m.id, m.titulo, m.status, m.origem, m.destino, m.valor,
                m.data_atribuicao_motorista, m.transportador_id, m.empresa_id,
                COALESCE(pt.nome_empresa, ut.nome, pe.nome_empresa, ue.nome) AS origem_nome
         FROM missoes m
         LEFT JOIN perfil_transportador pt ON pt.usuario_id = m.transportador_id
         LEFT JOIN usuarios ut ON ut.id = m.transportador_id
         LEFT JOIN perfil_empresa pe ON pe.usuario_id = m.empresa_id
         LEFT JOIN usuarios ue ON ue.id = m.empresa_id
         WHERE m.caminhoneiro_id = :uid
           AND m.status IN $statusAtivos
           AND (
                (m.data_atribuicao_motorista IS NOT NULL
                    AND m.data_atribuicao_motorista >= DATE_SUB(NOW(), INTERVAL 3 DAY))
                OR (m.data_atribuicao_motorista IS NULL
                    AND m.data_atualizacao >= DATE_SUB(NOW(), INTERVAL 1 DAY))
           )
         ORDER BY COALESCE(m.data_atribuicao_motorista, m.data_atualizacao) DESC
         LIMIT 5"
    );
    $stmt->execute([':uid' => $userId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $mid = (int)$m['id'];
        $link = BASE_URL . '/pages/caminhoneiro/detalhes-missao.php?id=' . $mid;
        $quem = trim((string)($m['origem_nome'] ?? ''));
        if ($quem === '') {
            $quem = !empty($m['transportador_id']) ? 'Uma transportadora' : 'Uma empresa';
        }
        $msg = sprintf(
            '%s atribuiu-lhe a missão "%s" (%s → %s). Abra agora para ver detalhes e avançar.',
            $quem,
            (string)$m['titulo'],
            (string)$m['origem'],
            (string)$m['destino']
        );
        $alerts[] = [
            'id' => 'atribuicao-' . $mid,
            'tipo' => 'atribuicao',
            'priority' => 'high',
            'mode' => 'modal',
            'titulo' => 'Nova missão atribuída',
            'mensagem' => $msg,
            'link' => $link,
            'sound' => 'urgent',
            'missao_id' => $mid,
            'require_ack' => true,
            'cta' => 'Abrir missão',
        ];
    }
} catch (Throwable $e) {
    error_log('driver-alerts atribuicao: ' . $e->getMessage());
}

// ── Missões activas: atrasos, lembretes, timers ──
try {
    $stmt = $conn->prepare(
        "SELECT m.id, m.titulo, m.status, m.origem, m.destino, m.prazo_entrega,
                m.modo_conducao_ativo, m.tempo_conducao_acumulado_seg, m.data_inicio_conducao
         FROM missoes m
         WHERE m.caminhoneiro_id = :uid
           AND m.status IN $statusAtivos
         ORDER BY m.prazo_entrega IS NULL, m.prazo_entrega ASC"
    );
    $stmt->execute([':uid' => $userId]);
    $activas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $hoje = new DateTime('today');
    foreach ($activas as $m) {
        $mid = (int)$m['id'];
        $titulo = (string)$m['titulo'];
        $link = BASE_URL . '/pages/caminhoneiro/detalhes-missao.php?id=' . $mid;
        $dias = null;
        $atrasada = false;

        if (!empty($m['prazo_entrega'])) {
            $prazo = new DateTime($m['prazo_entrega']);
            $dias = (int)$hoje->diff($prazo)->format('%r%a');
            $atrasada = $dias < 0;

            if ($atrasada) {
                $msg = sprintf(
                    'A missão "%s" está atrasada há %d dia(s). Prazo: %s.',
                    $titulo,
                    abs($dias),
                    $prazo->format('d/m/Y')
                );
                $alerts[] = [
                    'id' => 'atraso-' . $mid,
                    'tipo' => 'atraso',
                    'priority' => 'high',
                    'titulo' => 'Missão em atraso',
                    'mensagem' => $msg,
                    'link' => $link,
                    'sound' => 'urgent',
                    'missao_id' => $mid,
                ];
                notificacao_enviar($conn, $userId, 'missao', 'Missão em atraso', $msg, $link, 360);
            } elseif ($dias === 0) {
                $msg = sprintf('A missão "%s" vence hoje (%s).', $titulo, $prazo->format('d/m/Y'));
                $alerts[] = [
                    'id' => 'lembrete-hoje-' . $mid,
                    'tipo' => 'lembrete',
                    'priority' => 'high',
                    'titulo' => 'Prazo termina hoje',
                    'mensagem' => $msg,
                    'link' => $link,
                    'sound' => 'alert',
                    'missao_id' => $mid,
                ];
                notificacao_enviar($conn, $userId, 'missao', 'Prazo termina hoje', $msg, $link, 360);
            } elseif ($dias === 1) {
                $msg = sprintf('A missão "%s" vence amanhã (%s).', $titulo, $prazo->format('d/m/Y'));
                $alerts[] = [
                    'id' => 'lembrete-1d-' . $mid,
                    'tipo' => 'lembrete',
                    'priority' => 'medium',
                    'titulo' => 'Lembrete: prazo amanhã',
                    'mensagem' => $msg,
                    'link' => $link,
                    'sound' => 'soft',
                    'missao_id' => $mid,
                ];
                notificacao_enviar($conn, $userId, 'missao', 'Lembrete: prazo amanhã', $msg, $link, 720);
            } elseif ($dias <= 3) {
                $msg = sprintf('Faltam %d dias para o prazo da missão "%s" (%s).', $dias, $titulo, $prazo->format('d/m/Y'));
                $alerts[] = [
                    'id' => 'lembrete-' . $dias . 'd-' . $mid,
                    'tipo' => 'lembrete',
                    'priority' => 'low',
                    'titulo' => 'Lembrete de prazo',
                    'mensagem' => $msg,
                    'link' => $link,
                    'sound' => 'soft',
                    'missao_id' => $mid,
                ];
            }
        }

        $tempoSeg = (int)($m['tempo_conducao_acumulado_seg'] ?? 0);
        $modoAtivo = (int)($m['modo_conducao_ativo'] ?? 0) === 1;
        if ($modoAtivo && !empty($m['data_inicio_conducao'])) {
            $inicio = strtotime($m['data_inicio_conducao']);
            if ($inicio) {
                $tempoSeg += max(0, time() - $inicio);
            }
        }

        $timers[] = [
            'missao_id' => $mid,
            'titulo' => $titulo,
            'status' => $m['status'],
            'origem' => $m['origem'],
            'destino' => $m['destino'],
            'prazo_entrega' => $m['prazo_entrega'],
            'dias_restantes' => $dias,
            'atrasada' => $atrasada,
            'modo_conducao_ativo' => $modoAtivo,
            'tempo_conducao_seg' => $tempoSeg,
            'link' => $link,
            'modo_link' => BASE_URL . '/pages/caminhoneiro/modo-direcao.php?missao_id=' . $mid,
        ];
    }
} catch (Throwable $e) {
    error_log('driver-alerts activas: ' . $e->getMessage());
}

// ── Missões abertas próximas (≤ 20 km da origem) ──
if ($gps['lat'] !== null && $gps['lng'] !== null) {
    try {
        $stmt = $conn->prepare(
            "SELECT m.id, m.titulo, m.origem, m.destino, m.valor, m.data_criacao,
                    lo.latitude AS origem_lat, lo.longitude AS origem_lng,
                    u.nome AS empresa_nome
             FROM missoes m
             INNER JOIN locais lo ON lo.id = m.local_origem_id
             LEFT JOIN usuarios u ON u.id = m.empresa_id
             WHERE m.status = 'aberta'
               AND m.caminhoneiro_id IS NULL
               AND lo.latitude IS NOT NULL AND lo.longitude IS NOT NULL
               AND NOT EXISTS (
                   SELECT 1 FROM propostas p
                   WHERE p.missao_id = m.id AND p.caminhoneiro_id = :uid
               )
             ORDER BY m.data_criacao DESC
             LIMIT 40"
        );
        $stmt->execute([':uid' => $userId]);
        $candidatas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $proximas = [];
        foreach ($candidatas as $m) {
            $dist = tms_distancia_metros(
                $gps['lat'], $gps['lng'],
                (float)$m['origem_lat'], (float)$m['origem_lng']
            );
            if ($dist <= $RAIO_M) {
                $m['_dist'] = $dist;
                $proximas[] = $m;
            }
        }
        usort($proximas, static fn($a, $b) => $a['_dist'] <=> $b['_dist']);
        $proximas = array_slice($proximas, 0, 8);

        foreach ($proximas as $m) {
            $mid = (int)$m['id'];
            $km = round($m['_dist'] / 1000, 1);
            $link = BASE_URL . '/pages/caminhoneiro/missao.php?id=' . $mid;
            $msg = sprintf(
                'Nova missão a %.1f km: "%s" (%s → %s).',
                $km,
                $m['titulo'],
                $m['origem'],
                $m['destino']
            );
            $alerts[] = [
                'id' => 'proxima-' . $mid,
                'tipo' => 'proxima',
                'priority' => 'medium',
                'titulo' => 'Frete próximo (' . $km . ' km)',
                'mensagem' => $msg,
                'link' => $link,
                'sound' => 'alert',
                'missao_id' => $mid,
                'distancia_km' => $km,
                'valor' => (float)($m['valor'] ?? 0),
                'empresa' => $m['empresa_nome'] ?? null,
            ];
            // Dedup longo: não spammar a cada poll
            notificacao_enviar($conn, $userId, 'missao', 'Frete próximo (' . $km . ' km)', $msg, $link, 720);
        }
    } catch (Throwable $e) {
        error_log('driver-alerts proximas: ' . $e->getMessage());
    }
}

$unread = 0;
try {
    $unread = notificacao_contar_nao_lidas($conn, $userId);
} catch (Throwable $e) {
    // ignore
}

// Prioridade: high first
usort($alerts, static function ($a, $b) {
    $order = ['high' => 0, 'medium' => 1, 'low' => 2];
    return ($order[$a['priority']] ?? 9) <=> ($order[$b['priority']] ?? 9);
});

echo json_encode([
    'ok' => true,
    'unread' => $unread,
    'gps' => $gps,
    'raio_km' => $RAIO_M / 1000,
    'alerts' => $alerts,
    'timers' => $timers,
    'server_time' => date('c'),
], JSON_UNESCAPED_UNICODE);
