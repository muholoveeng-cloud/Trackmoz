<?php
/**
 * Helpers de parcerias profissionais — histórico de negociação legível.
 */

function parceria_campo_label(string $campo): string
{
    static $map = [
        'criacao'                   => 'Proposta inicial',
        'aceite'                    => 'Aceite da proposta',
        'aprovacao_empresa'         => 'Aprovação da empresa',
        'aprovacao_transportador'   => 'Aprovação da transportadora',
        'validacao_admin'           => 'Validação do admin',
        'rejeicao_admin'            => 'Rejeição do admin',
        'recusa'                    => 'Recusa',
        'cancelamento'              => 'Cancelamento',
        'valor_missao'              => 'Valor por missão',
        'valor_km'                  => 'Valor por km',
        'valor_mensal'              => 'Valor mensal',
        'comissao_plataforma_pct'   => 'Comissão da plataforma',
        'condicoes_pagamento'       => 'Condições de pagamento',
        'sla_resposta_horas'        => 'SLA de resposta',
        'penalidade_atraso_pct'     => 'Penalidade por atraso',
        'responsabilidade_carga'    => 'Responsabilidade da carga',
        'tipos_carga_permitidos'    => 'Tipos de carga',
        'rotas_cobertas'            => 'Rotas cobertas',
        'data_inicio'               => 'Data de início',
        'data_fim'                  => 'Data de fim',
        'tipo_contrato'             => 'Tipo de contrato',
        'observacoes_negociacao'    => 'Observações',
        'descricao'                 => 'Descrição',
        'exclusiva'                 => 'Exclusividade',
        'requer_validacao_admin'    => 'Requer validação admin',
        // chaves legadas (PDO binds gravados por engano)
        ':eid' => 'Empresa',
        ':tid' => 'Transportadora',
        ':desc' => 'Descrição',
        ':inicio' => 'Data de início',
        ':fim' => 'Data de fim',
        ':excl' => 'Exclusividade',
        ':tipo_contrato' => 'Tipo de contrato',
        ':valor_missao' => 'Valor por missão',
        ':valor_km' => 'Valor por km',
        ':valor_mensal' => 'Valor mensal',
        ':comissao' => 'Comissão da plataforma',
        ':cond_pag' => 'Condições de pagamento',
        ':sla' => 'SLA de resposta',
        ':penalidade' => 'Penalidade por atraso',
        ':resp_carga' => 'Responsabilidade da carga',
        ':tipos_carga' => 'Tipos de carga',
        ':rotas' => 'Rotas cobertas',
        ':obs' => 'Observações',
        ':req_admin' => 'Requer validação admin',
    ];

    return $map[$campo] ?? ucfirst(str_replace('_', ' ', ltrim($campo, ':')));
}

function parceria_papel_label(string $papel): string
{
    return match ($papel) {
        'empresa' => 'Contratante',
        'transportador' => 'Transportadora',
        'admin' => 'Administração',
        default => ucfirst($papel),
    };
}

function parceria_formatar_valor($valor): string
{
    if ($valor === null || $valor === '') {
        return '—';
    }

    if (is_bool($valor)) {
        return $valor ? 'Sim' : 'Não';
    }

    if (is_numeric($valor) && !is_string($valor)) {
        // percentagens / money handled by caller context — keep number
        return (string)$valor;
    }

    $str = trim((string)$valor);

    // JSON object/array
    if (($str[0] ?? '') === '{' || ($str[0] ?? '') === '[') {
        $decoded = json_decode($str, true);
        if (is_array($decoded)) {
            return parceria_formatar_snapshot($decoded);
        }
    }

    // Boolean-ish
    if (in_array(strtolower($str), ['1', 'true', 'sim', 'yes'], true)) {
        return 'Sim';
    }
    if (in_array(strtolower($str), ['0', 'false', 'nao', 'não', 'no'], true)) {
        return 'Não';
    }

    // Enums conhecidos
    $enums = [
        'tabela' => 'Tabela de preços',
        'por_missao' => 'Por missão',
        'por_km' => 'Por quilómetro',
        'mensal' => 'Mensal',
        'misto' => 'Misto',
        '30_dias' => '30 dias',
        '15_dias' => '15 dias',
        '7_dias' => '7 dias',
        'a_vista' => 'À vista',
        'imediato' => 'Imediato',
        'transportador' => 'Transportadora',
        'empresa' => 'Empresa',
        'seguro' => 'Seguro',
        'compartilhada' => 'Partilhada',
        'todas' => 'Todas as rotas',
    ];
    if (isset($enums[$str])) {
        return $enums[$str];
    }

    return $str;
}

/**
 * Converte snapshot (limpo ou com binds PDO) numa lista legível multilinha.
 */
function parceria_formatar_snapshot(array $dados): string
{
    $skip = [':eid', ':tid', 'empresa_id', 'transportador_id', 'id'];
    $linhas = [];

    foreach ($dados as $chave => $valor) {
        if (in_array($chave, $skip, true)) {
            continue;
        }
        if ($valor === null || $valor === '') {
            continue;
        }

        $label = parceria_campo_label((string)$chave);
        $fmt = parceria_formatar_valor($valor);

        // Formatação extra por tipo de campo
        $chaveNorm = ltrim((string)$chave, ':');
        if (in_array($chaveNorm, ['valor_missao', 'valor_km', 'valor_mensal', 'comissao', 'penalidade'], true)
            || str_contains($chaveNorm, 'valor') || str_contains($chaveNorm, 'comissao') || str_contains($chaveNorm, 'penalidade')) {
            if (is_numeric($valor)) {
                if (str_contains($chaveNorm, 'comissao') || str_contains($chaveNorm, 'penalidade')) {
                    $fmt = number_format((float)$valor, 2, ',', '.') . '%';
                } else {
                    $fmt = number_format((float)$valor, 2, ',', '.') . ' MT';
                }
            }
        }
        if (in_array($chaveNorm, ['sla', 'sla_resposta_horas'], true) && is_numeric($valor)) {
            $fmt = (int)$valor . ' h';
        }
        if (in_array($chaveNorm, ['excl', 'exclusiva', 'req_admin', 'requer_validacao_admin'], true)) {
            $fmt = ((int)$valor === 1) ? 'Sim' : 'Não';
        }

        $linhas[] = $label . ': ' . $fmt;
    }

    return $linhas ? implode("\n", $linhas) : '—';
}

/**
 * Snapshot limpo a partir dos binds PDO usados no INSERT de parceria.
 */
function parceria_snapshot_de_binds(array $dados): array
{
    $map = [
        ':desc' => 'descricao',
        ':inicio' => 'data_inicio',
        ':fim' => 'data_fim',
        ':excl' => 'exclusiva',
        ':tipo_contrato' => 'tipo_contrato',
        ':valor_missao' => 'valor_missao',
        ':valor_km' => 'valor_km',
        ':valor_mensal' => 'valor_mensal',
        ':comissao' => 'comissao_plataforma_pct',
        ':cond_pag' => 'condicoes_pagamento',
        ':sla' => 'sla_resposta_horas',
        ':penalidade' => 'penalidade_atraso_pct',
        ':resp_carga' => 'responsabilidade_carga',
        ':tipos_carga' => 'tipos_carga_permitidos',
        ':rotas' => 'rotas_cobertas',
        ':obs' => 'observacoes_negociacao',
        ':req_admin' => 'requer_validacao_admin',
    ];

    $out = [];
    foreach ($map as $bind => $campo) {
        if (array_key_exists($bind, $dados)) {
            $out[$campo] = $dados[$bind];
        }
    }
    return $out;
}

/**
 * HTML do bloco de uma entrada do histórico.
 */
function parceria_negociacao_html(array $n): string
{
    $papel = parceria_papel_label((string)($n['proposto_por'] ?? ''));
    $campo = parceria_campo_label((string)($n['campo_alterado'] ?? ''));
    $versao = (int)($n['versao'] ?? 1);
    $comentario = trim((string)($n['comentario'] ?? ''));
    $data = !empty($n['data_criacao']) ? date('d/m/Y H:i', strtotime($n['data_criacao'])) : '';

    $badgeClass = match ($n['proposto_por'] ?? '') {
        'empresa' => 'primary',
        'transportador' => 'info',
        'admin' => 'secondary',
        default => 'secondary',
    };

    $anterior = trim((string)($n['valor_anterior'] ?? ''));
    $novo = trim((string)($n['valor_novo'] ?? ''));

    $html = '<div class="d-flex mb-3 pb-3 border-bottom">';
    $html .= '<div class="me-2"><span class="badge bg-' . e($badgeClass) . '">' . e($papel) . '</span></div>';
    $html .= '<div class="flex-fill">';
    $html .= '<div class="fw-semibold">' . e($campo) . ' <span class="text-muted fw-normal">· v' . $versao . '</span></div>';

    if ($novo !== '' || $anterior !== '') {
        // Snapshot JSON (criação) vs alteração simples
        $novoFmt = parceria_formatar_valor($novo);
        $antFmt = $anterior !== '' ? parceria_formatar_valor($anterior) : '';

        $isMultiline = str_contains($novoFmt, "\n") || strlen($novoFmt) > 80;

        if ($isMultiline || ($n['campo_alterado'] ?? '') === 'criacao') {
            $html .= '<div class="mt-1 p-2 rounded bg-light border small" style="white-space:pre-wrap">' . e($novoFmt) . '</div>';
        } elseif ($anterior !== '' && $novo !== '') {
            $html .= '<div class="text-muted mt-1"><span class="text-decoration-line-through">' . e($antFmt) . '</span>'
                . ' <i class="bi bi-arrow-right mx-1"></i> <strong>' . e($novoFmt) . '</strong></div>';
        } else {
            $html .= '<div class="text-muted mt-1">' . e($novoFmt !== '—' ? $novoFmt : $antFmt) . '</div>';
        }
    }

    if ($comentario !== '') {
        $html .= '<div class="text-muted fst-italic mt-1">“' . e($comentario) . '”</div>';
    }
    if ($data !== '') {
        $html .= '<div class="text-muted small mt-1">' . e($data) . '</div>';
    }

    $html .= '</div></div>';
    return $html;
}
