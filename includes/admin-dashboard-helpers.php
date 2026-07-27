<?php
/**
 * Dados agregados para o dashboard home do administrador.
 */
require_once __DIR__ . '/admin-atencao-helpers.php';

function admin_tempo_relativo(?string $datetime): string
{
    if (!$datetime) {
        return '';
    }
    $ts = strtotime($datetime);
    if ($ts === false) {
        return '';
    }
    $diff = time() - $ts;
    if ($diff < 60) {
        return 'Agora mesmo';
    }
    if ($diff < 3600) {
        $m = (int)floor($diff / 60);
        return $m === 1 ? 'Há 1 minuto' : "Há {$m} minutos";
    }
    if ($diff < 86400) {
        $h = (int)floor($diff / 3600);
        return $h === 1 ? 'Há 1 hora' : "Há {$h} horas";
    }
    if ($diff < 604800) {
        $d = (int)floor($diff / 86400);
        return $d === 1 ? 'Há 1 dia' : "Há {$d} dias";
    }
    return date('d/m/Y H:i', $ts);
}

/**
 * @return array{
 *   stats: array<string,int|float>,
 *   atencao: array,
 *   actividades: list<array{titulo:string,quando:string,icon:string,cor:string,url:?string}>,
 *   irregulares_preview: list<array>,
 *   missoes_recentes: list<array>
 * }
 */
function admin_dashboard_home(PDO $conn): array
{
    $stats = [
        'total_usuarios'      => 0,
        'total_caminhoneiros' => 0,
        'total_empresas'      => 0,
        'total_transportadores' => 0,
        'usuarios_ativos'     => 0,
        'usuarios_pendentes'  => 0,
        'total_missoes'       => 0,
        'missoes_abertas'     => 0,
        'missoes_andamento'   => 0,
        'missoes_concluidas'  => 0,
        'docs_pendentes'      => 0,
        'contas_irregulares'  => 0,
        'prazos_expirados'    => 0,
        'emergencias_abertas' => 0,
        'disputas_abertas'    => 0,
        'taxa_conclusao'      => 0.0,
    ];

    $queries = [
        'total_usuarios'        => "SELECT COUNT(*) FROM usuarios",
        'total_caminhoneiros'   => "SELECT COUNT(*) FROM usuarios WHERE tipo_usuario='caminhoneiro'",
        'total_empresas'        => "SELECT COUNT(*) FROM usuarios WHERE tipo_usuario='empresa'",
        'total_transportadores' => "SELECT COUNT(*) FROM usuarios WHERE tipo_usuario='transportador'",
        'usuarios_ativos'       => "SELECT COUNT(*) FROM usuarios WHERE status='ativo'",
        'usuarios_pendentes'    => "SELECT COUNT(*) FROM usuarios WHERE status='pendente'",
        'total_missoes'         => "SELECT COUNT(*) FROM missoes",
        'missoes_abertas'       => "SELECT COUNT(*) FROM missoes WHERE status='aberta'",
        'missoes_andamento'     => "SELECT COUNT(*) FROM missoes WHERE status IN ('em_andamento','em_transito','aceita')",
        'missoes_concluidas'    => "SELECT COUNT(*) FROM missoes WHERE status='concluida'",
    ];

    foreach ($queries as $key => $sql) {
        try {
            $stats[$key] = (int)$conn->query($sql)->fetchColumn();
        } catch (Throwable $e) { /* ignore */ }
    }

    if ($stats['total_missoes'] > 0) {
        $stats['taxa_conclusao'] = round(($stats['missoes_concluidas'] / $stats['total_missoes']) * 100, 1);
    }

    $atencao = ['total' => 0, 'itens' => [], 'contagens' => []];
    try {
        $atencao = admin_atencao_resumo($conn);
        $c = $atencao['contagens'] ?? [];
        $stats['docs_pendentes']      = (int)($c['docs_pendentes'] ?? 0);
        $stats['contas_irregulares']  = (int)($c['contas_irregulares'] ?? 0);
        $stats['prazos_expirados']    = (int)($c['prazos_expirados'] ?? 0);
        $stats['emergencias_abertas'] = (int)($c['emergencias_abertas'] ?? 0);
        $stats['disputas_abertas']    = (int)($c['disputas_abertas'] ?? 0);
    } catch (Throwable $e) {
        error_log('admin_dashboard_home atencao: ' . $e->getMessage());
    }

    $irregularesPreview = [];
    try {
        require_once __DIR__ . '/kyc-advertencias-helpers.php';
        $lista = kyc_listar_contas_irregulares($conn);
        $irregularesPreview = array_slice($lista, 0, 5);
    } catch (Throwable $e) { /* ignore */ }

    $missoesRecentes = [];
    try {
        $missoesRecentes = $conn->query(
            "SELECT id, titulo, origem, destino, status, data_criacao
             FROM missoes
             ORDER BY data_criacao DESC
             LIMIT 6"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { /* ignore */ }

    return [
        'stats'               => $stats,
        'atencao'             => $atencao,
        'actividades'         => admin_dashboard_actividades($conn, 10),
        'irregulares_preview' => $irregularesPreview,
        'missoes_recentes'    => $missoesRecentes,
    ];
}

/**
 * Feed real de actividades recentes (missões, cadastros, emergências, logs).
 *
 * @return list<array{titulo:string,quando:string,icon:string,cor:string,url:?string,ts:int}>
 */
function admin_dashboard_actividades(PDO $conn, int $limit = 10): array
{
    $items = [];

    try {
        foreach ($conn->query(
            "SELECT id, titulo, status, data_criacao FROM missoes ORDER BY data_criacao DESC LIMIT 8"
        )->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $st = $m['status'] ?? '';
            $icon = 'bi-truck';
            $cor = 'blue';
            $titulo = 'Nova missão: ' . ($m['titulo'] ?: ('#' . $m['id']));
            if ($st === 'concluida') {
                $icon = 'bi-check-circle';
                $cor = 'green';
                $titulo = 'Missão concluída: ' . ($m['titulo'] ?: ('#' . $m['id']));
            } elseif (in_array($st, ['em_andamento', 'em_transito', 'aceita'], true)) {
                $icon = 'bi-geo-alt';
                $cor = 'cyan';
                $titulo = 'Missão em curso: ' . ($m['titulo'] ?: ('#' . $m['id']));
            }
            $ts = strtotime($m['data_criacao'] ?? '') ?: 0;
            $items[] = [
                'titulo' => $titulo,
                'quando' => admin_tempo_relativo($m['data_criacao'] ?? null),
                'icon'   => $icon,
                'cor'    => $cor,
                'url'    => BASE_URL . '/pages/admin/ver-missao.php?id=' . (int)$m['id'],
                'ts'     => $ts,
            ];
        }
    } catch (Throwable $e) { /* ignore */ }

    try {
        foreach ($conn->query(
            "SELECT id, nome, tipo_usuario, status, data_registro
             FROM usuarios
             WHERE tipo_usuario IN ('caminhoneiro','empresa','transportador')
             ORDER BY data_registro DESC LIMIT 6"
        )->fetchAll(PDO::FETCH_ASSOC) as $u) {
            $tipo = match ($u['tipo_usuario'] ?? '') {
                'caminhoneiro' => 'motorista',
                'empresa' => 'empresa',
                'transportador' => 'transportador',
                default => 'utilizador',
            };
            $st = $u['status'] ?? '';
            $prefix = $st === 'pendente' ? 'Novo cadastro pendente' : 'Novo utilizador';
            $items[] = [
                'titulo' => "{$prefix}: {$u['nome']} ({$tipo})",
                'quando' => admin_tempo_relativo($u['data_registro'] ?? null),
                'icon'   => 'bi-person-plus',
                'cor'    => $st === 'pendente' ? 'amber' : 'purple',
                'url'    => BASE_URL . '/pages/admin/ver-usuario.php?id=' . (int)$u['id'],
                'ts'     => strtotime($u['data_registro'] ?? '') ?: 0,
            ];
        }
    } catch (Throwable $e) { /* ignore */ }

    try {
        foreach ($conn->query(
            "SELECT id, tipo, gravidade, status, data_criacao
             FROM emergencias
             ORDER BY data_criacao DESC LIMIT 4"
        )->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $items[] = [
                'titulo' => 'Emergência ' . ($e['tipo'] ?: 'reportada')
                    . (!empty($e['gravidade']) ? ' · ' . $e['gravidade'] : ''),
                'quando' => admin_tempo_relativo($e['data_criacao'] ?? null),
                'icon'   => 'bi-exclamation-triangle',
                'cor'    => 'rose',
                'url'    => BASE_URL . '/pages/admin/emergencias.php',
                'ts'     => strtotime($e['data_criacao'] ?? '') ?: 0,
            ];
        }
    } catch (Throwable $e) { /* ignore */ }

    try {
        foreach ($conn->query(
            "SELECT id, tipo_acao, entidade, descricao, data_criacao
             FROM logs_sistema
             ORDER BY data_criacao DESC LIMIT 6"
        )->fetchAll(PDO::FETCH_ASSOC) as $log) {
            $items[] = [
                'titulo' => $log['descricao'] ?: (($log['tipo_acao'] ?? 'Acção') . ' em ' . ($log['entidade'] ?? 'sistema')),
                'quando' => admin_tempo_relativo($log['data_criacao'] ?? null),
                'icon'   => 'bi-journal-text',
                'cor'    => 'blue',
                'url'    => null,
                'ts'     => strtotime($log['data_criacao'] ?? '') ?: 0,
            ];
        }
    } catch (Throwable $e) { /* ignore */ }

    usort($items, static fn($a, $b) => ($b['ts'] ?? 0) <=> ($a['ts'] ?? 0));
    return array_slice($items, 0, $limit);
}
