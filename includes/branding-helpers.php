<?php
/**
 * Branding empresarial reutilizável em documentos, contratos e facturas.
 */
require_once __DIR__ . '/helpers.php';

function tmz_branding_defaults(): array
{
    return [
        'usuario_id'          => 0,
        'tipo'                => 'empresa',
        'nome_comercial'      => 'TrackMoz',
        'razao_social'        => '',
        'nuit'                => '',
        'endereco'            => '',
        'cidade'              => '',
        'distrito'            => '',
        'provincia'           => '',
        'pais'                => 'Moçambique',
        'telefone'            => '',
        'telefone_comercial'  => '',
        'email'               => '',
        'email_comercial'     => '',
        'website'             => '',
        'cor_institucional'   => '#2563eb',
        'descricao'           => '',
        'ano_fundacao'        => null,
        'especialidade'       => '',
        'licenca'             => '',
        'provincias_operacao' => '',
        'logo_url'            => null,
        'banco'               => '',
        'iban'                => '',
        'responsavel_legal'   => '',
    ];
}

function tmz_logo_url(?string $filename, string $pasta = 'logos'): ?string
{
    $file = trim((string)$filename);
    if ($file === '') {
        return null;
    }
    return BASE_URL . '/uploads/' . $pasta . '/' . rawurlencode($file);
}

/**
 * @return array<string,mixed>
 */
function tmz_get_branding(PDO $conn, int $usuarioId, string $tipo = 'empresa'): array
{
    $brand = tmz_branding_defaults();
    if ($usuarioId <= 0) {
        return $brand;
    }

    $stmt = $conn->prepare('SELECT id, nome, email, telefone, tipo_usuario FROM usuarios WHERE id = ?');
    $stmt->execute([$usuarioId]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u) {
        return $brand;
    }

    $brand['usuario_id'] = $usuarioId;
    $brand['tipo']       = $u['tipo_usuario'] ?? $tipo;
    $brand['email']      = $u['email'] ?? '';
    $brand['telefone']   = $u['telefone'] ?? '';

    if (($u['tipo_usuario'] ?? '') === 'transportador' || $tipo === 'transportador') {
        $stmt = $conn->prepare(
            'SELECT * FROM perfil_transportador WHERE usuario_id = ? LIMIT 1'
        );
        $stmt->execute([$usuarioId]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $brand['nome_comercial']     = $p['nome_empresa'] ?? $u['nome'] ?? '';
        $brand['razao_social']       = $p['razao_social'] ?? $p['nome_empresa'] ?? '';
        $brand['nuit']               = $p['nuit'] ?? '';
        $brand['endereco']           = $p['endereco'] ?? '';
        $brand['cidade']             = $p['cidade'] ?? '';
        $brand['provincia']          = $p['provincia'] ?? '';
        $brand['pais']               = $p['pais'] ?? 'Moçambique';
        $brand['telefone_comercial'] = $p['telefone_comercial'] ?? '';
        $brand['email_comercial']    = $p['email_comercial'] ?? '';
        $brand['website']            = $p['website'] ?? '';
        $brand['cor_institucional']  = $p['cor_institucional'] ?? '#2563eb';
        $brand['descricao']          = $p['descricao'] ?? '';
        $brand['ano_fundacao']       = $p['ano_fundacao'] ?? null;
        $brand['especialidade']      = $p['especialidade'] ?? '';
        $brand['licenca']            = $p['licenca'] ?? ($p['alvara'] ?? '');
        $brand['provincias_operacao']= $p['provincias_operacao'] ?? '';
        $brand['logo_url']           = tmz_logo_url($p['logo_empresa'] ?? null);
        return $brand;
    }

    $stmt = $conn->prepare('SELECT * FROM perfil_empresa WHERE usuario_id = ? LIMIT 1');
    $stmt->execute([$usuarioId]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $brand['nome_comercial']     = $p['nome_empresa'] ?? $u['nome'] ?? '';
    $brand['razao_social']       = $p['razao_social'] ?? $p['nome_empresa'] ?? '';
    $brand['nuit']               = $p['nuit'] ?? '';
    $brand['endereco']           = $p['endereco'] ?? '';
    $brand['cidade']             = $p['cidade'] ?? '';
    $brand['distrito']           = $p['distrito'] ?? '';
    $brand['provincia']          = $p['provincia'] ?? '';
    $brand['pais']               = $p['pais'] ?? 'Moçambique';
    $brand['telefone_comercial'] = $p['telefone_comercial'] ?? '';
    $brand['email_comercial']    = $p['email_comercial'] ?? ($p['email_comercial'] ?? '');
    $brand['website']            = $p['website'] ?? ($p['site'] ?? '');
    $brand['cor_institucional']  = $p['cor_institucional'] ?? '#2563eb';
    $brand['descricao']          = $p['descricao'] ?? '';
    $brand['ano_fundacao']       = $p['ano_fundacao'] ?? null;
    $brand['especialidade']      = $p['especialidade'] ?? ($p['ramo_atividade'] ?? ($p['tipo_empresa'] ?? ''));
    $brand['licenca']            = $p['licenca'] ?? ($p['cnpj'] ?? '');
    $brand['provincias_operacao']= $p['provincias_operacao'] ?? '';
    $brand['logo_url']           = tmz_logo_url($p['logo_empresa'] ?? null);
    $brand['banco']              = $p['banco'] ?? '';
    $brand['iban']               = $p['iban'] ?? '';
    $brand['responsavel_legal']  = $p['responsavel_legal'] ?? '';

    return $brand;
}

function tmz_branding_endereco_linha(array $brand): string
{
    $parts = array_filter([
        $brand['endereco'] ?? '',
        $brand['cidade'] ?? '',
        $brand['distrito'] ?? '',
        $brand['provincia'] ?? '',
        $brand['pais'] ?? '',
    ], fn($v) => trim((string)$v) !== '');
    return implode(', ', $parts) ?: 'N/D';
}

function tmz_branding_css_var(array $brand): string
{
    $cor = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($brand['cor_institucional'] ?? ''))
        ? $brand['cor_institucional']
        : '#2563eb';
    return $cor;
}
