<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/branding-helpers.php';

function tmz_safe_text($value, string $fallback = 'N/D'): string
{
    $txt = trim((string)($value ?? ''));
    return $txt !== '' ? $txt : $fallback;
}

function tmz_doc_money($value): string
{
    if ($value === null || $value === '') {
        return 'N/D';
    }
    return number_format((float)$value, 2, ',', '.') . ' MT';
}

function tmz_doc_date($value, string $format = 'd/m/Y'): string
{
    if (empty($value)) {
        return 'N/D';
    }
    $ts = strtotime((string)$value);
    if ($ts === false) {
        return 'N/D';
    }
    return date($format, $ts);
}

function tmz_generate_document_id(string $prefix, int $missaoId): string
{
    $rand = strtoupper(bin2hex(random_bytes(3)));
    return sprintf('%s-%06d-%s-%s', $prefix, $missaoId, date('YmdHis'), $rand);
}

function tmz_doc_signature_block(string $title): string
{
    return '<div class="sign-box">' . e($title) . '<br><small class="text-muted">Nome / Cargo / Data / Carimbo</small></div>';
}

function tmz_company_logo_url(array $doc): ?string
{
    $logo = trim((string)($doc['logo_empresa'] ?? ''));
    if ($logo !== '') {
        return tmz_logo_url($logo);
    }

    $fallback = trim((string)($doc['foto_perfil'] ?? ''));
    if ($fallback !== '') {
        return BASE_URL . '/uploads/perfil/' . rawurlencode($fallback);
    }

    return null;
}

/**
 * @return array{brand: array<string,mixed>, logoUrl: ?string, accent: string}
 */
function tmz_doc_brand_for_missao(PDO $conn, array $doc): array
{
    $brand = tmz_get_branding($conn, (int)($doc['empresa_id'] ?? 0), 'empresa');
    return [
        'brand'   => $brand,
        'logoUrl' => $brand['logo_url'] ?? tmz_company_logo_url($doc),
        'accent'  => tmz_branding_css_var($brand),
    ];
}

function tmz_html_empresa_emissora(array $brand, string $titulo = 'Empresa Emissora'): string
{
    $html = '<div class="doc-section"><h6>' . e($titulo) . '</h6>';
    $html .= '<div class="kv"><span class="k">Nome comercial:</span> ' . e(tmz_safe_text($brand['nome_comercial'] ?? null)) . '</div>';
    if (trim((string)($brand['razao_social'] ?? '')) !== '') {
        $html .= '<div class="kv"><span class="k">Razão social:</span> ' . e(tmz_safe_text($brand['razao_social'])) . '</div>';
    }
    $html .= '<div class="kv"><span class="k">NUIT:</span> ' . e(tmz_safe_text($brand['nuit'] ?? null)) . '</div>';
    if (trim((string)($brand['licenca'] ?? '')) !== '') {
        $html .= '<div class="kv"><span class="k">Licença:</span> ' . e(tmz_safe_text($brand['licenca'])) . '</div>';
    }
    $html .= '<div class="kv"><span class="k">Endereço:</span> ' . e(tmz_branding_endereco_linha($brand)) . '</div>';
    $contacto = trim((string)($brand['telefone_comercial'] ?? '')) ?: trim((string)($brand['telefone'] ?? ''));
    $html .= '<div class="kv"><span class="k">Contacto:</span> ' . e(tmz_safe_text($contacto)) . '</div>';
    if (trim((string)($brand['email_comercial'] ?? '')) !== '') {
        $html .= '<div class="kv"><span class="k">Email:</span> ' . e(tmz_safe_text($brand['email_comercial'])) . '</div>';
    }
    if (trim((string)($brand['website'] ?? '')) !== '') {
        $html .= '<div class="kv"><span class="k">Website:</span> ' . e(tmz_safe_text($brand['website'])) . '</div>';
    }
    $bancoInfo = trim(trim((string)($brand['banco'] ?? '')) . ' ' . trim((string)($brand['iban'] ?? '')));
    if ($bancoInfo !== '') {
        $html .= '<div class="kv"><span class="k">Dados bancários:</span> ' . e($bancoInfo) . '</div>';
    }
    $html .= '</div>';
    return $html;
}

function tmz_doc_tracking_url(string $trackingId): string
{
    return BASE_URL . '/pages/shared/validar-documento.php?tracking=' . rawurlencode($trackingId);
}

function tmz_doc_qr_html(string $trackingId, string $label = 'Validar documento'): string
{
    $url = tmz_doc_tracking_url($trackingId);
    $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=140x140&data=' . rawurlencode($url);
    $html = '<div class="doc-section"><h6>Validação digital</h6>';
    $html .= '<div class="d-flex align-items-center gap-3 flex-wrap">';
    $html .= '<img src="' . e($qrUrl) . '" alt="QR Code" width="140" height="140" class="border rounded p-1 bg-white">';
    $html .= '<div><div class="kv"><span class="k">' . e($label) . ':</span> ' . e($trackingId) . '</div>';
    $html .= '<div class="doc-note mt-2">Leia o QR ou aceda a:<br><small>' . e($url) . '</small></div></div>';
    $html .= '</div></div>';
    return $html;
}

/**
 * Layout unificado para documentos oficiais.
 */
function tmz_render_document_page(
    string $title,
    string $subtitle,
    string $documentId,
    string $trackingId,
    string $backUrl,
    ?string $logoUrl,
    string $bodyHtml,
    ?array $signers = null,
    ?string $accentColor = null
): void {
    $emitidoEm = date('d/m/Y H:i:s');
    $primary = preg_match('/^#[0-9a-fA-F]{6}$/', (string)$accentColor) ? $accentColor : '#0d6efd';
    ?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($title . ' - ' . $documentId); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <style>
        :root {
            --tmz-primary: <?php echo e($primary); ?>;
            --tmz-dark: #1f2937;
            --tmz-muted: #6b7280;
            --tmz-border: #d1d5db;
        }
        @media print {
            .no-print { display: none !important; }
            body { -webkit-print-color-adjust: exact; print-color-adjust: exact; background: #fff !important; }
        }
        body { background: #f3f4f6; color: var(--tmz-dark); }
        .doc-card {
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            background: #fff;
            box-shadow: 0 6px 20px rgba(0,0,0,0.06);
        }
        .doc-head {
            border-bottom: 3px solid var(--tmz-primary);
            padding-bottom: 14px;
            margin-bottom: 20px;
        }
        .doc-brand {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .doc-logo {
            width: 72px;
            height: 72px;
            object-fit: contain;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            background: #fff;
            padding: 4px;
        }
        .doc-meta {
            font-size: .9rem;
            color: var(--tmz-muted);
            line-height: 1.4;
        }
        .doc-section {
            border: 1px solid var(--tmz-border);
            border-radius: 10px;
            padding: 14px;
            margin-bottom: 14px;
            background: #fff;
        }
        .doc-section h6 {
            margin-bottom: 12px;
            color: #111827;
            font-weight: 700;
            border-bottom: 1px dashed #e5e7eb;
            padding-bottom: 8px;
        }
        .kv {
            margin-bottom: 7px;
            font-size: .94rem;
        }
        .kv .k {
            color: #4b5563;
            font-weight: 600;
        }
        .doc-note {
            font-size: .86rem;
            color: #374151;
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 10px 12px;
        }
        .sign-box {
            margin-top: 34px;
            border-top: 1px solid #111827;
            padding-top: 8px;
            font-size: .92rem;
            min-height: 54px;
        }
        .doc-footer {
            margin-top: 24px;
            padding-top: 10px;
            border-top: 1px dashed #d1d5db;
            color: var(--tmz-muted);
            font-size: .82rem;
        }
    </style>
</head>
<body>
<div class="container my-4">
    <div class="d-flex justify-content-end gap-2 no-print mb-3">
        <a class="btn btn-outline-secondary" href="<?php echo e($backUrl); ?>">
            <i class="bi bi-arrow-left"></i> Voltar
        </a>
        <button class="btn btn-outline-primary" onclick="window.print()">
            <i class="bi bi-printer"></i> Imprimir
        </button>
        <button class="btn btn-success" id="btnDownloadPdf">
            <i class="bi bi-file-earmark-pdf"></i> Descarregar PDF
        </button>
    </div>

    <div class="doc-card p-4" id="docContent">
        <div class="doc-head d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div class="doc-brand">
                <?php if ($logoUrl !== null): ?>
                    <img src="<?php echo e($logoUrl); ?>" alt="Logo da empresa" class="doc-logo">
                <?php else: ?>
                    <div class="doc-logo d-flex align-items-center justify-content-center">
                        <i class="bi bi-building fs-2 text-secondary"></i>
                    </div>
                <?php endif; ?>
                <div>
                    <h4 class="mb-1"><?php echo e($title); ?></h4>
                    <div class="text-muted"><?php echo e($subtitle); ?></div>
                </div>
            </div>
            <div class="doc-meta text-end">
                <div><strong>ID Documento:</strong> <?php echo e($documentId); ?></div>
                <div><strong>Tracking ID:</strong> <?php echo e($trackingId); ?></div>
                <div><strong>Emitido em:</strong> <?php echo e($emitidoEm); ?></div>
                <div><strong>Plataforma:</strong> TrackMoz</div>
            </div>
        </div>

        <?php echo $bodyHtml; ?>

        <?php
            $defaultSigners = [
                'Assinatura e carimbo da Empresa Transportadora',
                'Assinatura do Cliente/Remetente',
                'Assinatura do Destinatário',
                'Assinatura do Condutor',
            ];
            $signersToRender = $signers ?? $defaultSigners;
        ?>
        <div class="row mt-2">
            <?php foreach ($signersToRender as $label): ?>
                <div class="col-md-6">
                    <?php echo tmz_doc_signature_block((string)$label); ?>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="doc-footer">
            Documento emitido digitalmente no TrackMoz. Validação por Tracking ID.
            Data/hora de emissão: <?php echo e($emitidoEm); ?>.
        </div>
    </div>
</div>

<script>
document.getElementById('btnDownloadPdf').addEventListener('click', function () {
    const btn = this;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> A gerar...';
    html2pdf().set({
        margin: [8, 8, 8, 8],
        filename: <?php echo json_encode($title . '-' . $documentId . '.pdf'); ?>,
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2, useCORS: true },
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
    }).from(document.getElementById('docContent')).save().then(function () {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-file-earmark-pdf"></i> Descarregar PDF';
    });
});
</script>
</body>
</html>
<?php
}

