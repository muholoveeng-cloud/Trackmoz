<?php
/**
 * KPIs operacionais reutilizáveis (Módulos 9–11).
 */
require_once __DIR__ . '/helpers.php';

function kpi_empresa(PDO $conn, int $empresaId): array
{
    $kpi = [
        'total_missoes'      => 0,
        'missoes_abertas'    => 0,
        'missoes_andamento'  => 0,
        'missoes_concluidas' => 0,
        'missoes_atrasadas'  => 0,
        'emergencias'        => 0,
        'receita_total'      => 0.0,
        'parcerias_ativas'   => 0,
    ];
    if ($empresaId <= 0) {
        return $kpi;
    }

    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total,
            SUM(CASE WHEN status = 'aberta' THEN 1 ELSE 0 END) AS abertas,
            SUM(CASE WHEN status IN ('aceita','em_andamento','em_transito','em_entrega','aguardando_confirmacao') THEN 1 ELSE 0 END) AS andamento,
            SUM(CASE WHEN status IN ('concluida','entrega_confirmada') THEN 1 ELSE 0 END) AS concluidas,
            SUM(CASE WHEN status NOT IN ('concluida','entrega_confirmada','cancelada')
                      AND prazo_entrega IS NOT NULL AND prazo_entrega < CURDATE() THEN 1 ELSE 0 END) AS atrasadas,
            SUM(CASE WHEN status IN ('emergencia','emergencia_reportada') THEN 1 ELSE 0 END) AS emergencias,
            SUM(CASE WHEN status IN ('concluida','entrega_confirmada') THEN COALESCE(valor,0) ELSE 0 END) AS receita
         FROM missoes WHERE empresa_id = :id"
    );
    $stmt->execute([':id' => $empresaId]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $kpi['total_missoes']      = (int)($r['total'] ?? 0);
    $kpi['missoes_abertas']    = (int)($r['abertas'] ?? 0);
    $kpi['missoes_andamento']  = (int)($r['andamento'] ?? 0);
    $kpi['missoes_concluidas'] = (int)($r['concluidas'] ?? 0);
    $kpi['missoes_atrasadas']  = (int)($r['atrasadas'] ?? 0);
    $kpi['emergencias']        = (int)($r['emergencias'] ?? 0);
    $kpi['receita_total']      = (float)($r['receita'] ?? 0);

    $stmt = $conn->prepare(
        "SELECT COUNT(*) FROM parcerias
         WHERE empresa_id = :id AND status = 'ativa'
           AND (data_fim IS NULL OR data_fim >= CURDATE())"
    );
    $stmt->execute([':id' => $empresaId]);
    $kpi['parcerias_ativas'] = (int)$stmt->fetchColumn();

    return $kpi;
}

function kpi_transportador(PDO $conn, int $transportadorId): array
{
    $kpi = [
        'missoes_ativas'     => 0,
        'missoes_concluidas' => 0,
        'missoes_pendentes'  => 0,
        'frota_ativa'        => 0,
        'motoristas_ativos'  => 0,
        'parcerias_ativas'   => 0,
        'receita_estimada'   => 0.0,
    ];
    if ($transportadorId <= 0) {
        return $kpi;
    }

    $stmt = $conn->prepare(
        "SELECT
            SUM(CASE WHEN status IN ('aceita','em_andamento','em_transito','em_entrega','aguardando_confirmacao','aguardando_aceitacao_transportadora') THEN 1 ELSE 0 END) AS ativas,
            SUM(CASE WHEN status IN ('concluida','entrega_confirmada') THEN 1 ELSE 0 END) AS concluidas,
            SUM(CASE WHEN status = 'aguardando_aceitacao_transportadora' THEN 1 ELSE 0 END) AS pendentes,
            SUM(CASE WHEN status IN ('concluida','entrega_confirmada') THEN COALESCE(valor,0) ELSE 0 END) AS receita
         FROM missoes WHERE transportador_id = :id"
    );
    $stmt->execute([':id' => $transportadorId]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $kpi['missoes_ativas']     = (int)($r['ativas'] ?? 0);
    $kpi['missoes_concluidas'] = (int)($r['concluidas'] ?? 0);
    $kpi['missoes_pendentes']  = (int)($r['pendentes'] ?? 0);
    $kpi['receita_estimada']   = (float)($r['receita'] ?? 0);

    try {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) FROM veiculos
             WHERE proprietario_id = :id AND proprietario_tipo = 'transportador' AND estado_operacional = 'ativo'"
        );
        $stmt->execute([':id' => $transportadorId]);
        $kpi['frota_ativa'] = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        $kpi['frota_ativa'] = 0;
    }

    try {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) FROM transportador_motoristas WHERE transportador_id = :id AND status = 'ativo'"
        );
        $stmt->execute([':id' => $transportadorId]);
        $kpi['motoristas_ativos'] = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        $kpi['motoristas_ativos'] = 0;
    }

    $stmt = $conn->prepare(
        "SELECT COUNT(*) FROM parcerias
         WHERE transportador_id = :id AND status = 'ativa'
           AND (data_fim IS NULL OR data_fim >= CURDATE())"
    );
    $stmt->execute([':id' => $transportadorId]);
    $kpi['parcerias_ativas'] = (int)$stmt->fetchColumn();

    return $kpi;
}

function kpi_render_cards(array $kpi, array $map): string
{
    $html = '<div class="row g-3 tm-kpi-grid">';
    foreach ($map as $key => $meta) {
        $val = $kpi[$key] ?? 0;
        if (($meta['format'] ?? '') === 'money') {
            $display = number_format((float)$val, 2, ',', '.') . ' MT';
        } elseif (($meta['format'] ?? '') === 'decimal') {
            $display = number_format((float)$val, 1, ',', '.');
        } else {
            $display = (string)(int)$val;
        }
        $icon  = $meta['icon']  ?? 'bi-bar-chart';
        $color = $meta['color'] ?? 'blue';
        $hint  = $meta['hint']  ?? '';
        $html .= '<div class="col-xl-2 col-md-4 col-sm-6">';
        $html .= '<div class="card border-0 h-100 tm-kpi-card tm-kpi-' . e($color) . '">';
        $html .= '<div class="card-body">';
        $html .= '<div class="d-flex align-items-start justify-content-between mb-2">';
        $html .= '<div class="tm-kpi-label">' . e($meta['label']) . '</div>';
        $html .= '<span class="tm-kpi-icon"><i class="bi ' . e($icon) . '"></i></span>';
        $html .= '</div>';
        $html .= '<div class="tm-kpi-value">' . e($display) . '</div>';
        if ($hint !== '') {
            $html .= '<div class="tm-kpi-hint">' . e($hint) . '</div>';
        }
        $html .= '</div></div></div>';
    }
    $html .= '</div>';
    return $html;
}

/**
 * Série mensal de missões (últimos N meses) para gráficos.
 * $roleFilter: ['empresa_id'=>id] | ['caminhoneiro_id'=>id] | ['transportador_id'=>id]
 */
function kpi_serie_mensal(PDO $conn, array $roleFilter, int $meses = 6): array
{
    $labels = [];
    $criadas = [];
    $concluidas = [];
    $meses = max(3, min(12, $meses));

    $where = '1=1';
    $params = [];
    foreach (['empresa_id', 'caminhoneiro_id', 'transportador_id'] as $col) {
        if (!empty($roleFilter[$col])) {
            $where = "$col = :rid";
            $params[':rid'] = (int)$roleFilter[$col];
            break;
        }
    }

    $mesesPt = [1=>'Jan',2=>'Fev',3=>'Mar',4=>'Abr',5=>'Mai',6=>'Jun',7=>'Jul',8=>'Ago',9=>'Set',10=>'Out',11=>'Nov',12=>'Dez'];
    for ($i = $meses - 1; $i >= 0; $i--) {
        $dt = new DateTime('first day of this month');
        $dt->modify("-{$i} months");
        $ym = $dt->format('Y-m');
        $labels[] = ($mesesPt[(int)$dt->format('n')] ?? $dt->format('M')) . '/' . $dt->format('y');

        $stmt = $conn->prepare(
            "SELECT
                SUM(CASE WHEN DATE_FORMAT(data_criacao, '%Y-%m') = :ym THEN 1 ELSE 0 END) AS criadas,
                SUM(CASE WHEN status IN ('concluida','entrega_confirmada')
                          AND DATE_FORMAT(COALESCE(data_atualizacao, data_criacao), '%Y-%m') = :ym2
                     THEN 1 ELSE 0 END) AS concluidas
             FROM missoes WHERE $where"
        );
        $stmt->execute($params + [':ym' => $ym, ':ym2' => $ym]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $criadas[] = (int)($r['criadas'] ?? 0);
        $concluidas[] = (int)($r['concluidas'] ?? 0);
    }

    return ['labels' => $labels, 'criadas' => $criadas, 'concluidas' => $concluidas];
}

/**
 * Distribuição por status para doughnut charts.
 */
function kpi_distribuicao_status(PDO $conn, array $roleFilter): array
{
    $where = '1=1';
    $params = [];
    foreach (['empresa_id', 'caminhoneiro_id', 'transportador_id'] as $col) {
        if (!empty($roleFilter[$col])) {
            $where = "$col = :rid";
            $params[':rid'] = (int)$roleFilter[$col];
            break;
        }
    }

    $stmt = $conn->prepare(
        "SELECT status, COUNT(*) AS total FROM missoes WHERE $where GROUP BY status ORDER BY total DESC"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $labels = [];
    $values = [];
    $map = [
        'aberta' => 'Aberta',
        'aceita' => 'Aceite',
        'em_andamento' => 'Em andamento',
        'em_transito' => 'Em trânsito',
        'em_entrega' => 'Em entrega',
        'aguardando_confirmacao' => 'Aguard. confirmação',
        'concluida' => 'Concluída',
        'entrega_confirmada' => 'Entrega confirmada',
        'cancelada' => 'Cancelada',
        'emergencia' => 'Emergência',
        'emergencia_reportada' => 'Emergência',
    ];
    foreach ($rows as $row) {
        $st = (string)$row['status'];
        $labels[] = $map[$st] ?? ucfirst(str_replace('_', ' ', $st));
        $values[] = (int)$row['total'];
    }
    return ['labels' => $labels, 'values' => $values];
}

/**
 * Taxa de conclusão e receita dos últimos 30 dias.
 */
function kpi_resumo_periodo(PDO $conn, array $roleFilter, int $dias = 30): array
{
    $where = '1=1';
    $params = [];
    foreach (['empresa_id', 'caminhoneiro_id', 'transportador_id'] as $col) {
        if (!empty($roleFilter[$col])) {
            $where = "$col = :rid";
            $params[':rid'] = (int)$roleFilter[$col];
            break;
        }
    }

    $dias = max(7, min(90, $dias));
    $stmt = $conn->prepare(
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status IN ('concluida','entrega_confirmada') THEN 1 ELSE 0 END) AS concluidas,
            SUM(CASE WHEN status IN ('concluida','entrega_confirmada') THEN COALESCE(valor,0) ELSE 0 END) AS receita,
            SUM(CASE WHEN status NOT IN ('concluida','entrega_confirmada','cancelada')
                      AND prazo_entrega IS NOT NULL AND prazo_entrega < CURDATE() THEN 1 ELSE 0 END) AS atrasadas
         FROM missoes
         WHERE $where AND data_criacao >= DATE_SUB(CURDATE(), INTERVAL {$dias} DAY)"
    );
    try {
        $stmt->execute($params);
        $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return ['total' => 0, 'concluidas' => 0, 'receita' => 0.0, 'atrasadas' => 0, 'taxa' => 0.0];
    }

    $total = (int)($r['total'] ?? 0);
    $concluidas = (int)($r['concluidas'] ?? 0);
    return [
        'total'      => $total,
        'concluidas' => $concluidas,
        'receita'    => (float)($r['receita'] ?? 0),
        'atrasadas'  => (int)($r['atrasadas'] ?? 0),
        'taxa'       => $total > 0 ? round(($concluidas / $total) * 100, 1) : 0.0,
    ];
}

function kpi_caminhoneiro(PDO $conn, int $motoristaId): array
{
    $kpi = [
        'missoes_ativas'     => 0,
        'missoes_concluidas' => 0,
        'propostas_pendentes'=> 0,
        'avaliacao_media'    => 0.0,
        'total_entregas'     => 0,
        'receita_estimada'   => 0.0,
    ];
    if ($motoristaId <= 0) {
        return $kpi;
    }

    $stmt = $conn->prepare(
        "SELECT
            SUM(CASE WHEN status IN ('aceita','em_andamento','em_transito','em_entrega','aguardando_confirmacao') THEN 1 ELSE 0 END) AS ativas,
            SUM(CASE WHEN status IN ('concluida','entrega_confirmada') THEN 1 ELSE 0 END) AS concluidas,
            SUM(CASE WHEN status IN ('concluida','entrega_confirmada') THEN COALESCE(valor,0) ELSE 0 END) AS receita
         FROM missoes WHERE caminhoneiro_id = :id"
    );
    $stmt->execute([':id' => $motoristaId]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $kpi['missoes_ativas']     = (int)($r['ativas'] ?? 0);
    $kpi['missoes_concluidas'] = (int)($r['concluidas'] ?? 0);
    $kpi['receita_estimada']   = (float)($r['receita'] ?? 0);

    $stmt = $conn->prepare(
        "SELECT COUNT(*) FROM propostas WHERE caminhoneiro_id = :id AND status = 'pendente'"
    );
    $stmt->execute([':id' => $motoristaId]);
    $kpi['propostas_pendentes'] = (int)$stmt->fetchColumn();

    try {
        $stmt = $conn->prepare(
            'SELECT avaliacao_media, total_entregas FROM perfil_caminhoneiro WHERE usuario_id = :id'
        );
        $stmt->execute([':id' => $motoristaId]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($p) {
            $kpi['avaliacao_media'] = round((float)($p['avaliacao_media'] ?? 0), 1);
            $kpi['total_entregas']  = (int)($p['total_entregas'] ?? 0);
        }
    } catch (Throwable $e) {
        // opcional
    }

    return $kpi;
}

function kpi_admin(PDO $conn): array
{
    $kpi = [
        'utilizadores'       => 0,
        'missoes_total'      => 0,
        'missoes_andamento'  => 0,
        'missoes_concluidas' => 0,
        'emergencias'        => 0,
        'documentos_pendentes'=> 0,
        'usuarios_pendentes' => 0,
    ];

    try {
        $kpi['utilizadores'] = (int)$conn->query(
            "SELECT COUNT(*) FROM usuarios WHERE tipo_usuario != 'admin'"
        )->fetchColumn();
        $kpi['usuarios_pendentes'] = (int)$conn->query(
            "SELECT COUNT(*) FROM usuarios WHERE status = 'pendente'"
        )->fetchColumn();
        $r = $conn->query(
            "SELECT COUNT(*) AS total,
                SUM(CASE WHEN status IN ('aceita','em_andamento','em_transito','em_entrega','aguardando_confirmacao') THEN 1 ELSE 0 END) AS andamento,
                SUM(CASE WHEN status IN ('concluida','entrega_confirmada') THEN 1 ELSE 0 END) AS concluidas,
                SUM(CASE WHEN status IN ('emergencia','emergencia_reportada') THEN 1 ELSE 0 END) AS emergencias
             FROM missoes"
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        $kpi['missoes_total']      = (int)($r['total'] ?? 0);
        $kpi['missoes_andamento']  = (int)($r['andamento'] ?? 0);
        $kpi['missoes_concluidas'] = (int)($r['concluidas'] ?? 0);
        $kpi['emergencias']        = (int)($r['emergencias'] ?? 0);
        $kpi['documentos_pendentes'] = (int)$conn->query(
            "SELECT COUNT(*) FROM documentos WHERE status = 'pendente'"
        )->fetchColumn();
    } catch (Throwable $e) {
        error_log('kpi_admin: ' . $e->getMessage());
    }

    return $kpi;
}
