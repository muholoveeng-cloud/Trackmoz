<?php
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');
include_once('../../includes/auth.php');

require_role(['admin','empresa','caminhoneiro'], '../login.php');

$uid   = (int)$_SESSION['user_id'];
$utype = $_SESSION['user_type'];

$missao_id = isset($_GET['missao_id']) ? (int)$_GET['missao_id'] : 0;
if ($missao_id <= 0) {
    header('Location: ' . BASE_URL . '/pages/contratante/missoes.php');
    exit;
}

try {
    $stmt = $conn->prepare(
        "SELECT m.*,
                u.nome AS motorista_nome, u.telefone AS motorista_telefone,
                emp.nome AS empresa_nome, emp.telefone AS empresa_telefone,
                v.placa AS viatura_placa, v.marca AS viatura_marca, v.modelo AS viatura_modelo,
                d.nome AS destinatario_nome, d.telefone AS destinatario_telefone, d.email AS destinatario_email, d.nuit_documento AS destinatario_nuit, d.endereco AS destinatario_endereco
         FROM missoes m
         LEFT JOIN usuarios u ON m.caminhoneiro_id = u.id
         LEFT JOIN usuarios emp ON m.empresa_id = emp.id
         LEFT JOIN veiculos v ON m.veiculo_id = v.id
         LEFT JOIN destinatarios d ON m.destinatario_id = d.id
         WHERE m.id = ?"
    );
    $stmt->execute([$missao_id]);
    $missao = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$missao) {
        header('Location: ' . BASE_URL . '/pages/contratante/missoes.php');
        exit;
    }
    // Permissão
    $permitido = ($utype === 'admin')
        || (int)$missao['empresa_id'] === $uid
        || (int)$missao['caminhoneiro_id'] === $uid;
    if (!$permitido) {
        header('Location: ' . BASE_URL . '/pages/login.php');
        exit;
    }

    // Buscar entrega confirmada
    $stmt2 = $conn->prepare("SELECT * FROM entregas_confirmacao WHERE missao_id = ? ORDER BY id DESC LIMIT 1");
    $stmt2->execute([$missao_id]);
    $entrega = $stmt2->fetch(PDO::FETCH_ASSOC);

    // Buscar avaliação
    $stmt3 = $conn->prepare("SELECT * FROM avaliacoes_entrega WHERE missao_id = ? LIMIT 1");
    $stmt3->execute([$missao_id]);
    $avaliacao = $stmt3->fetch(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    error_log('entrega-comprovante: ' . $e->getMessage());
    echo 'Erro interno.';
    exit;
}

$metodoLabel = ['otp'=>'OTP/PIN','destinatario_cadastrado'=>'Destinatário cadastrado','manual_assistida'=>'Confirmação manual'];
$estadoLabel = ['sem_danos'=>'Recebida sem danos','com_danos'=>'Recebida com danos','parcial'=>'Recebida parcialmente','recusada'=>'Recusada'];
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <title>Comprovante de Entrega #<?php echo $missao_id; ?> — TrackMoz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <style>
        @media print {
            .no-print { display: none !important; }
            body { background: #fff; }
            .comprovante { box-shadow: none; border: 1px solid #dee2e6; }
        }
        body { background: #e9ecef; }
        .comprovante { max-width: 800px; margin: 24px auto; background: #fff; border-radius: 12px; padding: 40px; box-shadow: 0 4px 20px rgba(0,0,0,.08); }
        .header-logo { font-size: 1.6rem; font-weight: 800; color: #0d6efd; }
        .stamp { font-size: .72rem; color: #6c757d; text-transform: uppercase; letter-spacing: .5px; }
        .photo-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; }
        .photo-grid img { width: 100%; border-radius: 8px; border: 1px solid #dee2e6; }
        .signature-box { max-width: 280px; border: 1px solid #dee2e6; border-radius: 8px; padding: 10px; background: #fff; }
        .qr { width: 80px; height: 80px; background: #f8f9fa; border: 1px dashed #dee2e6; display: flex; align-items: center; justify-content: center; font-size: .65rem; color: #6c757d; }
    </style>
</head>
<body>
<div class="comprovante">
    <div class="d-flex justify-content-between align-items-start mb-4">
        <div>
            <div class="header-logo"><i class="bi bi-box-seam me-2"></i>TrackMoz</div>
            <div class="stamp mt-1">Comprovante de Entrega (ePOD)</div>
        </div>
        <div class="text-end">
            <div class="small text-muted">Nº Missão</div>
            <div class="fw-bold fs-5">#<?php echo $missao_id; ?></div>
            <div class="small text-muted mt-1"><?php echo $entrega ? date('d/m/Y H:i', strtotime($entrega['data_confirmacao'])) : 'Pendente'; ?></div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="small text-muted">Remetente / Empresa</div>
            <div class="fw-semibold"><?php echo htmlspecialchars($missao['empresa_nome'] ?? 'N/A'); ?></div>
            <div class="small"><?php echo htmlspecialchars($missao['empresa_telefone'] ?? ''); ?></div>
        </div>
        <div class="col-md-4">
            <div class="small text-muted">Destino</div>
            <div class="fw-semibold"><?php echo htmlspecialchars($missao['destino']); ?></div>
            <?php if($missao['destinatario_endereco']): ?><div class="small"><?php echo htmlspecialchars($missao['destinatario_endereco']); ?></div><?php endif; ?>
        </div>
        <div class="col-md-4">
            <div class="small text-muted">Destinatário</div>
            <div class="fw-semibold"><?php echo htmlspecialchars($missao['destinatario_nome'] ?? $entrega['nome_recebedor'] ?? 'N/A'); ?></div>
            <div class="small"><?php echo htmlspecialchars($missao['destinatario_telefone'] ?? ''); ?></div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="small text-muted">Motorista</div>
            <div class="fw-semibold"><?php echo htmlspecialchars($missao['motorista_nome'] ?? 'N/A'); ?></div>
            <div class="small"><?php echo htmlspecialchars($missao['motorista_telefone'] ?? ''); ?></div>
        </div>
        <div class="col-md-3">
            <div class="small text-muted">Viatura</div>
            <div class="fw-semibold"><?php echo htmlspecialchars($missao['viatura_placa'] ?? 'N/A'); ?></div>
            <div class="small"><?php echo htmlspecialchars(($missao['viatura_marca'] ?? '') . ' ' . ($missao['viatura_modelo'] ?? '')); ?></div>
        </div>
        <div class="col-md-3">
            <div class="small text-muted">Método de confirmação</div>
            <div class="fw-semibold"><?php echo $metodoLabel[$entrega['metodo'] ?? ''] ?? 'N/A'; ?></div>
        </div>
        <div class="col-md-3">
            <div class="small text-muted">Estado da carga</div>
            <div class="fw-semibold"><?php echo $estadoLabel[$entrega['estado_carga'] ?? ''] ?? 'N/A'; ?></div>
        </div>
    </div>

    <?php if ($entrega): ?>
    <hr>
    <h6 class="fw-bold mb-3"><i class="bi bi-clipboard-check me-1"></i>Dados da Confirmação</h6>
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="small text-muted">Quem recebeu</div>
            <div class="fw-semibold"><?php echo htmlspecialchars($entrega['nome_recebedor'] ?? 'N/A'); ?></div>
            <?php if($entrega['documento_recebedor']): ?><div class="small">Doc: <?php echo htmlspecialchars($entrega['documento_recebedor']); ?></div><?php endif; ?>
            <?php if($entrega['telefone_recebedor']): ?><div class="small">Tel: <?php echo htmlspecialchars($entrega['telefone_recebedor']); ?></div><?php endif; ?>
        </div>
        <div class="col-md-4">
            <div class="small text-muted">Localização GPS</div>
            <div class="fw-semibold small">
                <?php if($entrega['latitude'] && $entrega['longitude']): ?>
                    <?php echo number_format((float)$entrega['latitude'],6); ?>, <?php echo number_format((float)$entrega['longitude'],6); ?>
                    <a href="https://maps.google.com/?q=<?php echo $entrega['latitude']; ?>,<?php echo $entrega['longitude']; ?>" target="_blank"><i class="bi bi-box-arrow-up-right"></i></a>
                <?php else: ?>Não registada<?php endif; ?>
            </div>
            <div class="small text-muted mt-1">Data/hora: <?php echo date('d/m/Y H:i:s', strtotime($entrega['data_confirmacao'])); ?></div>
        </div>
        <div class="col-md-4">
            <?php if ($entrega['assinatura_url']): ?>
                <div class="small text-muted mb-1">Assinatura</div>
                <div class="signature-box"><img src="<?php echo htmlspecialchars($entrega['assinatura_url']); ?>" style="max-width:100%" alt="Assinatura"></div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($entrega['observacoes']): ?>
        <div class="alert alert-light border mb-4">
            <div class="small text-muted">Observações:</div>
            <div><?php echo nl2br(htmlspecialchars($entrega['observacoes'])); ?></div>
        </div>
    <?php endif; ?>

    <?php if ($entrega['foto_carga_url'] || $entrega['foto_doc_url']): ?>
        <h6 class="fw-bold mb-2"><i class="bi bi-images me-1"></i>Anexos</h6>
        <div class="photo-grid mb-4">
            <?php if ($entrega['foto_carga_url']): ?><div><div class="small text-muted">Foto da carga</div><img src="<?php echo htmlspecialchars($entrega['foto_carga_url']); ?>" alt="Foto carga"></div><?php endif; ?>
            <?php if ($entrega['foto_doc_url']): ?><div><div class="small text-muted">Documento assinado</div><img src="<?php echo htmlspecialchars($entrega['foto_doc_url']); ?>" alt="Doc"></div><?php endif; ?>
        </div>
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($avaliacao): ?>
    <hr>
    <h6 class="fw-bold mb-2"><i class="bi bi-star-fill text-warning me-1"></i>Avaliação do Destinatário</h6>
    <div class="row g-2 small mb-3">
        <div class="col-6 col-md-3"><span class="text-muted">Geral:</span> <strong><?php echo $avaliacao['nota_geral']; ?>/5</strong></div>
        <div class="col-6 col-md-3"><span class="text-muted">Pontualidade:</span> <strong><?php echo $avaliacao['nota_pontualidade'] ?? '-'; ?>/5</strong></div>
        <div class="col-6 col-md-3"><span class="text-muted">Estado carga:</span> <strong><?php echo $avaliacao['nota_estado_carga'] ?? '-'; ?>/5</strong></div>
        <div class="col-6 col-md-3"><span class="text-muted">Comunicação:</span> <strong><?php echo $avaliacao['nota_comunicacao'] ?? '-'; ?>/5</strong></div>
    </div>
    <?php if ($avaliacao['comentario']): ?><p class="small fst-italic">"<?php echo htmlspecialchars($avaliacao['comentario']); ?>"</p><?php endif; ?>
    <?php if ($avaliacao['problema_reportado']): ?><div class="alert alert-warning small py-2">Problema reportado: <?php echo htmlspecialchars($avaliacao['problema_reportado']); ?></div><?php endif; ?>
    <?php endif; ?>

    <hr>
    <div class="d-flex justify-content-between align-items-center mt-4">
        <div class="small text-muted">
            Este documento é uma prova electrónica de entrega (ePOD) gerada automaticamente pelo sistema TrackMoz.<br>
            Identificador único: EPOD-<?php echo $missao_id; ?>-<?php echo $entrega['id'] ?? 0; ?>
        </div>
        <div class="qr">
            <i class="bi bi-upc-scan" style="font-size:1.5rem"></i>
        </div>
    </div>
</div>

<div class="text-center mb-4 no-print">
    <button class="btn btn-primary" onclick="window.print()"><i class="bi bi-printer me-1"></i>Imprimir / Guardar PDF</button>
    <a href="<?php echo BASE_URL; ?>/pages/contratante/detalhes-missao.php?id=<?php echo $missao_id; ?>" class="btn btn-outline-secondary ms-2">Voltar</a>
</div>
</body>
</html>
