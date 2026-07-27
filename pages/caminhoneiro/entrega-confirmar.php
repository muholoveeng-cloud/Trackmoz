<?php
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');
include_once('../../includes/auth.php');
include_once('../../includes/helpers.php');

require_role(['caminhoneiro'], '../login.php');

$missao_id = isset($_GET['missao_id']) ? (int)$_GET['missao_id'] : 0;
$uid = (int)$_SESSION['user_id'];

if ($missao_id <= 0) {
    header('Location: ' . BASE_URL . '/pages/caminhoneiro/missoes.php');
    exit;
}

try {
    $stmt = $conn->prepare(
        "SELECT m.*,
                u.nome AS motorista_nome,
                COALESCE(v.matricula, '') AS viatura_placa,
                d.nome AS destinatario_nome,
                d.telefone AS destinatario_telefone,
                d.email AS destinatario_email
         FROM missoes m
         LEFT JOIN usuarios u ON m.caminhoneiro_id = u.id
         LEFT JOIN veiculos v ON m.veiculo_id = v.id
         LEFT JOIN destinatarios d ON m.destinatario_id = d.id
         WHERE m.id = ? AND m.caminhoneiro_id = ?"
    );
    $stmt->execute([$missao_id, $uid]);
    $missao = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$missao) {
        header('Location: ' . BASE_URL . '/pages/caminhoneiro/missoes.php?error=Missão não encontrada');
        exit;
    }
} catch (Throwable $e) {
    error_log('entrega-confirmar join: ' . $e->getMessage());
    // Fallback sem joins de viatura/destinatário (schemas diferentes)
    try {
        $stmt = $conn->prepare(
            "SELECT m.*, u.nome AS motorista_nome
             FROM missoes m
             LEFT JOIN usuarios u ON m.caminhoneiro_id = u.id
             WHERE m.id = ? AND m.caminhoneiro_id = ?"
        );
        $stmt->execute([$missao_id, $uid]);
        $missao = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e2) {
        error_log('entrega-confirmar fallback: ' . $e2->getMessage());
        $missao = null;
    }
}

if (empty($missao)) {
    try {
        $stmt = $conn->prepare('SELECT * FROM missoes WHERE id = ? AND caminhoneiro_id = ?');
        $stmt->execute([$missao_id, $uid]);
        $missao = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $missao = null;
    }
}

if (!$missao) {
    header('Location: ' . BASE_URL . '/pages/caminhoneiro/missoes.php?error=' . urlencode('Missão não encontrada'));
    exit;
}

// Completar nome do motorista se veio do fallback
if (empty($missao['motorista_nome'])) {
    $missao['motorista_nome'] = (string)($_SESSION['user_name'] ?? 'Motorista');
}
if (!isset($missao['viatura_placa'])) {
    $missao['viatura_placa'] = null;
    if (!empty($missao['veiculo_id'])) {
        try {
            $vs = $conn->prepare('SELECT matricula FROM veiculos WHERE id = ?');
            $vs->execute([(int)$missao['veiculo_id']]);
            $missao['viatura_placa'] = $vs->fetchColumn() ?: null;
        } catch (Throwable $e) {
            // ignore
        }
    }
}

$metodo = $missao['modo_confirmacao_entrega'] ?? 'otp';
if ($metodo !== 'otp') {
    $metodo = 'otp';
}
$erroPagina = null;
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Confirmar Entrega — TrackMoz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <style>
        body { background: #f8f9fa; font-family: system-ui, sans-serif; }
        .epod-card { max-width: 520px; margin: 0 auto; padding: 16px; }
        .step-badge { width: 28px; height: 28px; border-radius: 50%; background: #0d6efd; color: #fff; display: inline-flex; align-items: center; justify-content: center; font-size: .75rem; font-weight: 700; }
        .camera-input { position: relative; overflow: hidden; }
        .camera-input input[type="file"] { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
        .preview-img { max-width: 100%; max-height: 200px; border-radius: 10px; margin-top: 8px; }
        .signature-pad { background: #fff; border: 2px dashed #dee2e6; border-radius: 10px; width: 100%; height: 160px; touch-action: none; }
        .btn-confirm { background: #198754; border: none; color: #fff; font-weight: 700; padding: 14px; border-radius: 12px; width: 100%; }
    </style>
</head>
<body>
<div class="epod-card">
    <h5 class="fw-bold mb-3"><i class="bi bi-clipboard-check-fill text-success me-2"></i>Confirmar Entrega</h5>
    <div class="card mb-3">
        <div class="card-body small">
            <div class="d-flex justify-content-between"><span class="text-muted">Missão</span><strong>#<?php echo $missao_id; ?></strong></div>
            <div class="d-flex justify-content-between"><span class="text-muted">Destino</span><strong><?php echo e($missao['destino'] ?? ''); ?></strong></div>
            <div class="d-flex justify-content-between"><span class="text-muted">Motorista</span><span><?php echo e($missao['motorista_nome'] ?? ''); ?></span></div>
            <div class="d-flex justify-content-between"><span class="text-muted">Viatura</span><span><?php echo e($missao['viatura_placa'] ?? 'N/A'); ?></span></div>
        </div>
    </div>

    <form id="formEntrega" enctype="multipart/form-data">
        <input type="hidden" name="missao_id" value="<?php echo $missao_id; ?>">
        <input type="hidden" name="metodo" value="<?php echo $metodo; ?>">
        <input type="hidden" name="latitude" id="latInput">
        <input type="hidden" name="longitude" id="lngInput">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
        <input type="hidden" name="assinatura" id="assinaturaData">

        <!-- OTP Mode -->
        <?php if ($metodo === 'otp'): ?>
        <div class="mb-3">
            <label class="form-label small fw-semibold">1. Código OTP do destinatário</label>
            <input type="text" name="otp" id="otpInput" class="form-control form-control-lg text-center" maxlength="6" placeholder="______" inputmode="numeric" pattern="[0-9]*" required>
            <div class="alert alert-info small py-2 mt-2 mb-0">
                <i class="bi bi-info-circle me-1"></i>Pedir ao destinatário o código de 6 dígitos enviado pela empresa (WhatsApp/SMS). O motorista <strong>não</strong> gera o OTP — só a empresa pode criá-lo ou regenerá-lo.
            </div>
        </div>
        <?php endif; ?>

        <!-- Destinatário cadastrado -->
        <?php if ($metodo === 'destinatario_cadastrado' && $missao['destinatario_nome']): ?>
        <div class="alert alert-info small py-2">
            <i class="bi bi-info-circle me-1"></i>Destinatário cadastrado: <strong><?php echo e($missao['destinatario_nome'] ?? ''); ?></strong>
        </div>
        <?php endif; ?>

        <!-- Dados do recebedor -->
        <div class="mb-3">
            <label class="form-label small fw-semibold"><?php echo $metodo==='otp'?'2':'1'; ?>. Dados de quem recebeu</label>
            <input type="text" name="nome_recebedor" class="form-control mb-2" placeholder="Nome completo" required>
            <input type="text" name="documento_recebedor" class="form-control mb-2" placeholder="Documento / BI / NUIT (opcional)">
            <input type="tel" name="telefone_recebedor" class="form-control" placeholder="Telefone de contacto" inputmode="tel" required>
        </div>

        <!-- Estado da carga -->
        <div class="mb-3">
            <label class="form-label small fw-semibold">Estado da carga ao receber</label>
            <select name="estado_carga" class="form-select" required>
                <option value="sem_danos">Recebida sem danos</option>
                <option value="com_danos">Recebida com danos</option>
                <option value="parcial">Recebida parcialmente</option>
                <option value="recusada">Recusada</option>
            </select>
        </div>

        <!-- Foto da carga -->
        <div class="mb-3">
            <label class="form-label small fw-semibold">Foto da carga entregue</label>
            <div class="camera-input btn btn-outline-secondary w-100 py-3">
                <i class="bi bi-camera-fill fs-4"></i><br><span class="small">Tirar foto</span>
                <input type="file" name="foto_carga" accept="image/*" capture="environment" id="fotoCarga">
            </div>
            <img id="previewFotoCarga" class="preview-img d-none">
        </div>

        <!-- Assinatura -->
        <div class="mb-3">
            <label class="form-label small fw-semibold">Assinatura do recebedor</label>
            <canvas class="signature-pad" id="canvasAssinatura"></canvas>
            <div class="text-end"><button type="button" class="btn btn-sm btn-link" onclick="limparAssinatura()">Limpar</button></div>
        </div>

        <!-- Observações -->
        <div class="mb-3">
            <label class="form-label small fw-semibold">Observações</label>
            <textarea name="observacoes" class="form-control" rows="2" placeholder="Algo a registar?"></textarea>
        </div>

        <button type="submit" class="btn-confirm" id="btnConfirmar">
            <i class="bi bi-check-lg me-1"></i>Confirmar Entrega
        </button>
    </form>
    <div id="msgEntrega" class="mt-3"></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const BASE_URL = <?php echo json_encode(BASE_URL); ?>;
const MISSAO_ID = <?php echo json_encode($missao_id); ?>;

// GPS
navigator.geolocation.getCurrentPosition(p => {
    document.getElementById('latInput').value = p.coords.latitude;
    document.getElementById('lngInput').value = p.coords.longitude;
}, () => {});

// Preview foto
const fotoCarga = document.getElementById('fotoCarga');
const previewFoto = document.getElementById('previewFotoCarga');
fotoCarga.addEventListener('change', () => {
    if (fotoCarga.files[0]) {
        previewFoto.src = URL.createObjectURL(fotoCarga.files[0]);
        previewFoto.classList.remove('d-none');
    }
});

// Assinatura
const canvas = document.getElementById('canvasAssinatura');
const ctx = canvas.getContext('2d');
let drawing = false;
function resizeCanvas() {
    const rect = canvas.getBoundingClientRect();
    canvas.width = rect.width * window.devicePixelRatio;
    canvas.height = rect.height * window.devicePixelRatio;
    ctx.scale(window.devicePixelRatio, window.devicePixelRatio);
    ctx.strokeStyle = '#000'; ctx.lineWidth = 2; ctx.lineCap = 'round';
}
window.addEventListener('load', resizeCanvas);
window.addEventListener('resize', resizeCanvas);
function getPos(e) {
    const rect = canvas.getBoundingClientRect();
    const clientX = e.touches ? e.touches[0].clientX : e.clientX;
    const clientY = e.touches ? e.touches[0].clientY : e.clientY;
    return { x: clientX - rect.left, y: clientY - rect.top };
}
function startDraw(e) { drawing = true; ctx.beginPath(); const p = getPos(e); ctx.moveTo(p.x, p.y); e.preventDefault(); }
function moveDraw(e) { if (!drawing) return; const p = getPos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); e.preventDefault(); }
function endDraw() { drawing = false; }
canvas.addEventListener('mousedown', startDraw);
canvas.addEventListener('mousemove', moveDraw);
canvas.addEventListener('mouseup', endDraw);
canvas.addEventListener('mouseleave', endDraw);
canvas.addEventListener('touchstart', startDraw, {passive:false});
canvas.addEventListener('touchmove', moveDraw, {passive:false});
canvas.addEventListener('touchend', endDraw);
function limparAssinatura() { ctx.clearRect(0, 0, canvas.width, canvas.height); }

// Submit
const formEntrega = document.getElementById('formEntrega');
formEntrega.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = document.getElementById('btnConfirmar');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> A processar...';

    // Capturar assinatura
    document.getElementById('assinaturaData').value = canvas.toDataURL('image/png');

    const form = new FormData(formEntrega);
    // Converter dataURL para blob
    const sigData = document.getElementById('assinaturaData').value;
    if (sigData && sigData.startsWith('data:image')) {
        const blob = await (await fetch(sigData)).blob();
        form.append('assinatura', blob, 'assinatura.png');
    }

    try {
        const r = await fetch(BASE_URL + '/api/entrega-confirmar.php', { method:'POST', body: form });
        const d = await r.json();
        const msg = document.getElementById('msgEntrega');
        if (d.ok) {
            msg.innerHTML = '<div class="alert alert-success">' + d.message + '</div>';
            setTimeout(() => {
                window.location.href = BASE_URL + '/pages/caminhoneiro/detalhes-missao.php?id=' + MISSAO_ID;
            }, 1500);
        } else {
            msg.innerHTML = '<div class="alert alert-danger">' + (d.error || 'Erro') + '</div>';
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Confirmar Entrega';
        }
    } catch(e) {
        document.getElementById('msgEntrega').innerHTML = '<div class="alert alert-danger">Erro de ligação. Tente novamente.</div>';
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Confirmar Entrega';
    }
});
</script>
</body>
</html>
