<?php
/**
 * Lembretes e itens que exigem atenção do administrador.
 */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/kyc-helpers.php';
require_once __DIR__ . '/kyc-advertencias-helpers.php';

/**
 * @return array{
 *   total: int,
 *   itens: list<array{chave:string,titulo:string,detalhe:string,count:int,url:string,nivel:string}>,
 *   contagens: array<string,int>
 * }
 */
function admin_atencao_resumo(PDO $conn): array
{
    kyc_bootstrap($conn);
    kyc_advertencias_bootstrap($conn);

    $contagens = [
        'usuarios_pendentes'   => 0,
        'docs_pendentes'       => 0,
        'kyc_em_analise'       => 0,
        'contas_irregulares'   => 0,
        'prazos_expirados'     => 0,
        'emergencias_abertas'  => 0,
        'disputas_abertas'     => 0,
    ];

    try {
        $contagens['usuarios_pendentes'] = (int)$conn->query(
            "SELECT COUNT(*) FROM usuarios WHERE status = 'pendente'"
        )->fetchColumn();
    } catch (Throwable $e) { /* ignore */ }

    try {
        $contagens['docs_pendentes'] = (int)$conn->query(
            "SELECT COUNT(*) FROM documentos WHERE status = 'pendente'"
        )->fetchColumn();
    } catch (Throwable $e) { /* ignore */ }

    try {
        $contagens['kyc_em_analise'] = (int)$conn->query(
            "SELECT COUNT(*) FROM usuarios
             WHERE estado_kyc = 'em_analise'
               AND tipo_usuario IN ('caminhoneiro','empresa','transportador')"
        )->fetchColumn();
    } catch (Throwable $e) { /* ignore */ }

    try {
        $contagens['emergencias_abertas'] = (int)$conn->query(
            "SELECT COUNT(*) FROM emergencias WHERE status IN ('aberta','em_atendimento','pendente')"
        )->fetchColumn();
    } catch (Throwable $e) {
        try {
            $contagens['emergencias_abertas'] = (int)$conn->query(
                "SELECT COUNT(*) FROM emergencias WHERE status = 'aberta'"
            )->fetchColumn();
        } catch (Throwable $e2) { /* ignore */ }
    }

    try {
        $contagens['disputas_abertas'] = (int)$conn->query(
            "SELECT COUNT(*) FROM disputas WHERE status IN ('aberta','em_analise')"
        )->fetchColumn();
    } catch (Throwable $e) { /* ignore */ }

    try {
        $irregulares = kyc_listar_contas_irregulares($conn);
        $contagens['contas_irregulares'] = count($irregulares);
        $exp = 0;
        foreach ($irregulares as $row) {
            if (!empty($row['prazo_expirado'])) {
                $exp++;
            }
        }
        $contagens['prazos_expirados'] = $exp;
    } catch (Throwable $e) { /* ignore */ }

    $itens = [];
    if ($contagens['usuarios_pendentes'] > 0) {
        $n = $contagens['usuarios_pendentes'];
        $itens[] = [
            'chave'   => 'usuarios_pendentes',
            'titulo'  => $n === 1 ? '1 utilizador aguarda aprovação' : "{$n} utilizadores aguardam aprovação",
            'detalhe' => 'Novos cadastros pendentes de activação.',
            'count'   => $n,
            'url'     => BASE_URL . '/pages/admin/usuarios.php?status=pendente',
            'nivel'   => 'warning',
        ];
    }
    if ($contagens['docs_pendentes'] > 0 || $contagens['kyc_em_analise'] > 0) {
        $n = max($contagens['docs_pendentes'], $contagens['kyc_em_analise']);
        $itens[] = [
            'chave'   => 'docs_kyc',
            'titulo'  => $contagens['docs_pendentes'] . ' documento(s) e '
                . $contagens['kyc_em_analise'] . ' verificação(ões) KYC',
            'detalhe' => 'Analise, aprove ou rejeite documentos enviados.',
            'count'   => $n,
            'url'     => BASE_URL . '/pages/admin/verificar-documentos.php?status=pendente',
            'nivel'   => 'danger',
        ];
    }
    if ($contagens['contas_irregulares'] > 0) {
        $n = $contagens['contas_irregulares'];
        $exp = $contagens['prazos_expirados'];
        $detalhe = $exp > 0
            ? "{$exp} com prazo de advertência expirado — pode remover."
            : 'Envie advertências; se demorarem, bloqueie ou remova.';
        $itens[] = [
            'chave'   => 'contas_irregulares',
            'titulo'  => $n === 1 ? '1 conta irregular (sem docs)' : "{$n} contas irregulares (sem docs)",
            'detalhe' => $detalhe,
            'count'   => $n,
            'url'     => BASE_URL . '/pages/admin/contas-irregulares.php'
                . ($exp > 0 ? '?filtro=expirado' : ''),
            'nivel'   => $exp > 0 ? 'danger' : 'warning',
        ];
    }
    if ($contagens['emergencias_abertas'] > 0) {
        $n = $contagens['emergencias_abertas'];
        $itens[] = [
            'chave'   => 'emergencias',
            'titulo'  => $n === 1 ? '1 emergência aberta' : "{$n} emergências abertas",
            'detalhe' => 'Requer intervenção imediata.',
            'count'   => $n,
            'url'     => BASE_URL . '/pages/admin/emergencias.php',
            'nivel'   => 'danger',
        ];
    }
    if ($contagens['disputas_abertas'] > 0) {
        $n = $contagens['disputas_abertas'];
        $itens[] = [
            'chave'   => 'disputas',
            'titulo'  => $n === 1 ? '1 disputa em aberto' : "{$n} disputas em aberto",
            'detalhe' => 'Casos comerciais a mediar.',
            'count'   => $n,
            'url'     => BASE_URL . '/pages/admin/disputas.php',
            'nivel'   => 'warning',
        ];
    }

    $total = 0;
    foreach ($itens as $it) {
        $total += (int)$it['count'];
    }

    return [
        'total'     => $total,
        'itens'     => $itens,
        'contagens' => $contagens,
    ];
}

/**
 * Garante notificações-lembrete no admin (dedupe por título/chave).
 * Chamado no login e no menu do admin.
 */
function admin_sincronizar_lembretes(PDO $conn, int $adminId): void
{
    if ($adminId <= 0) {
        return;
    }

    require_once __DIR__ . '/notificacoes-helpers.php';
    $resumo = admin_atencao_resumo($conn);

    $mapa = [
        'usuarios_pendentes' => [
            'tipo'    => 'alerta',
            'titulo'  => 'Lembrete: utilizadores pendentes',
            'msg'     => 'Existem cadastros à espera da sua aprovação.',
            'link'    => BASE_URL . '/pages/admin/usuarios.php?status=pendente',
            'count'   => $resumo['contagens']['usuarios_pendentes'],
        ],
        'docs_pendentes' => [
            'tipo'    => 'alerta',
            'titulo'  => 'Lembrete: documentos para analisar',
            'msg'     => 'Há documentos enviados que precisam de aprovação ou rejeição.',
            'link'    => BASE_URL . '/pages/admin/verificar-documentos.php?status=pendente',
            'count'   => $resumo['contagens']['docs_pendentes'],
        ],
        'kyc_em_analise' => [
            'tipo'    => 'alerta',
            'titulo'  => 'Lembrete: verificações KYC em análise',
            'msg'     => 'Utilizadores concluíram o envio e aguardam a sua decisão.',
            'link'    => BASE_URL . '/pages/admin/verificar-documentos.php?status=pendente',
            'count'   => $resumo['contagens']['kyc_em_analise'],
        ],
        'emergencias_abertas' => [
            'tipo'    => 'emergencia',
            'titulo'  => 'Lembrete: emergências abertas',
            'msg'     => 'Há emergências reportadas que ainda não foram resolvidas.',
            'link'    => BASE_URL . '/pages/admin/emergencias.php',
            'count'   => $resumo['contagens']['emergencias_abertas'],
        ],
        'disputas_abertas' => [
            'tipo'    => 'alerta',
            'titulo'  => 'Lembrete: disputas em aberto',
            'msg'     => 'Existem disputas comerciais a tratar.',
            'link'    => BASE_URL . '/pages/admin/disputas.php',
            'count'   => $resumo['contagens']['disputas_abertas'],
        ],
        'contas_irregulares' => [
            'tipo'    => 'alerta',
            'titulo'  => 'Lembrete: contas irregulares',
            'msg'     => 'Há utilizadores activos sem documentação regularizada. Envie advertências ou remova se o prazo expirou.',
            'link'    => BASE_URL . '/pages/admin/contas-irregulares.php',
            'count'   => $resumo['contagens']['contas_irregulares'],
        ],
        'prazos_expirados' => [
            'tipo'    => 'alerta',
            'titulo'  => 'Lembrete: prazos de regularização expirados',
            'msg'     => 'Contas advertidas sem regularizar — pode bloquear ou remover.',
            'link'    => BASE_URL . '/pages/admin/contas-irregulares.php?filtro=expirado',
            'count'   => $resumo['contagens']['prazos_expirados'],
        ],
    ];

    foreach ($mapa as $chave => $cfg) {
        $count = (int)$cfg['count'];
        if ($count <= 0) {
            // Marcar lembretes antigos como lidos se já não há pendências
            try {
                $conn->prepare(
                    "UPDATE notificacoes SET lida = 1
                     WHERE usuario_id = ? AND titulo = ? AND lida = 0"
                )->execute([$adminId, $cfg['titulo']]);
            } catch (Throwable $e) { /* ignore */ }
            continue;
        }

        $mensagem = $cfg['msg'] . " ({$count})";

        try {
            // Dedupe por título (6h) — se já existe lembrete igual, actualiza a mensagem/contagem
            $stmt = $conn->prepare(
                "SELECT id FROM notificacoes
                 WHERE usuario_id = ? AND titulo = ? AND lida = 0
                   AND data_criacao > DATE_SUB(NOW(), INTERVAL 6 HOUR)
                 LIMIT 1"
            );
            $stmt->execute([$adminId, $cfg['titulo']]);
            $existente = $stmt->fetchColumn();
            if ($existente) {
                $conn->prepare('UPDATE notificacoes SET mensagem = ?, link = ?, data_criacao = NOW() WHERE id = ?')
                     ->execute([$mensagem, $cfg['link'], $existente]);
            } else {
                if (function_exists('notificar_usuario')) {
                    notificar_usuario($conn, $adminId, $cfg['tipo'], $cfg['titulo'], $mensagem, $cfg['link']);
                } else {
                    $conn->prepare(
                        'INSERT INTO notificacoes (usuario_id, tipo, titulo, mensagem, link, lida, data_criacao)
                         VALUES (?,?,?,?,?,0,NOW())'
                    )->execute([$adminId, $cfg['tipo'], $cfg['titulo'], $mensagem, $cfg['link']]);
                }
            }
        } catch (Throwable $e) {
            error_log('admin_sincronizar_lembretes: ' . $e->getMessage());
        }
    }
}

/**
 * HTML do banner sticky de atenção (admin).
 */
function admin_atencao_banner_html(array $resumo): string
{
    if (($resumo['total'] ?? 0) <= 0 || empty($resumo['itens'])) {
        return '';
    }

    $html = '<div class="tm-admin-atencao" role="alert">';
    $html .= '<div class="tm-admin-atencao-inner">';
    $html .= '<div class="tm-admin-atencao-title"><i class="bi bi-bell-fill"></i> Atenção necessária</div>';
    $html .= '<div class="tm-admin-atencao-items">';
    foreach ($resumo['itens'] as $it) {
        $nivel = $it['nivel'] === 'danger' ? 'danger' : 'warning';
        $html .= '<a class="tm-admin-chip tm-admin-chip-' . e($nivel) . '" href="' . e($it['url']) . '">';
        $html .= '<span class="badge">' . (int)$it['count'] . '</span> ';
        $html .= e($it['titulo']);
        $html .= '</a>';
    }
    $html .= '</div></div></div>';
    return $html;
}
