<?php
// Tela pública para destinatário confirmar entrega
// URL: /pages/entrega/confirmar.php?missao_id=123
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');
include_once('../../includes/helpers.php');

$missao_id = isset($_GET['missao_id']) ? (int)$_GET['missao_id'] : 0;
if ($missao_id <= 0) {
    http_response_code(400);
    echo '<div class="alert alert-danger">Link inválido.</div>';
    exit;
}

try {
    $stmt = $conn->prepare(
        "SELECT m.*, u.nome AS motorista_nome, e.nome AS empresa_nome
         FROM missoes m
         LEFT JOIN usuarios u ON m.caminhoneiro_id = u.id
         LEFT JOIN usuarios e ON m.empresa_id = e.id
         WHERE m.id = ?"
    );
    $stmt->execute([$missao_id]);
    $missao = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$missao) {
        echo '<div class="alert alert-danger">Missão não encontrada.</div>';
        exit;
    }
} catch (Throwable $e) {
    error_log('entrega/confirmar: ' . $e->getMessage());
    echo '<div class="alert alert-danger">Erro interno.</div>';
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirmar Recebimento — TrackMoz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <style>
        body { background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); min-height: 100vh; }
        .confirm-card { max-width: 480px; margin: 24px auto; background: #fff; border-radius: 20px; padding: 28px; box-shadow: 0 8px 30px rgba(0,0,0,.08); }
        .signature-pad { background: #fff; border: 2px dashed #dee2e6; border-radius: 10px; width: 100%; height: 140px; touch-action: none; }
        .btn-confirm { background: #198754; border: none; color: #fff; font-weight: 700; padding: 14px; border-radius: 12px; width: 100%; }
    </style>
</head>
<body>
<div class="confirm-card">
    <div class="text-center mb-3">
        <i class="bi bi-box-seam text-success" style="font-size:2.5rem"></i>
        <h5 class="fw-bold mt-2">Confirmar Recebimento</h5>
        <p class="text-muted small mb-0">Missão #<?php echo $missao_id; ?></p>
    </div>
    <div class="card bg-light border-0 mb-3">
        <div class="card-body small">
            <div><strong>Origem:</strong> <?php echo htmlspecialchars($missao['origem']); ?></div>
            <div><strong>Destino:</strong> <?php echo htmlspecialchars($missao['destino']); ?></div>
            <div><strong>Empresa:</strong> <?php echo htmlspecialchars($missao['empresa_nome'] ?? 'N/A'); ?></div>
            <div><strong>Motorista:</strong> <?php echo htmlspecialchars($missao['motorista_nome'] ?? 'N/A'); ?></div>
        </div>
    </div>

    <form id="formConfirmar">
        <input type="hidden" name="missao_id" value="<?php echo $missao_id; ?>">
        <input type="hidden" name="metodo" value="destinatario_cadastrado">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
        <input type="hidden" name="latitude" id="latInput">
        <input type="hidden" name="longitude" id="lngInput">

        <div class="mb-3">
            <label class="form-label small fw-semibold">Seu nome completo *</label>
            <input type="text" name="nome_recebedor" class="form-control" required>
        </div>
        <div class="mb-3">
            <label class="form-label small fw-semibold">Documento / BI / NUIT</label>
            <input type="text" name="documento_recebedor" class="form-control">
        </div>
        <div class="mb-3">
            <label class="form-label small fw-semibold">Telefone *</label>
            <input type="tel" name="telefone_recebedor" class="form-control" required inputmode="tel">
        </div>
        <div class="mb-3">
            <label class="form-label small fw-semibold">Estado da carga ao receber *</label>
            <select name="estado_carga" class="form-select" required>
                <option value="sem_danos">Recebida sem danos</option>
                <option value="com_danos">Recebida com danos</option>
                <option value="parcial">Recebida parcialmente</option>
                <option value="recusada">Recusada</option>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label small fw-semibold">Foto da carga entregue</label>
            <input type="file" name="foto_carga" accept="image/*" class="form-control">
        </div>
        <div class="mb-3">
            <label class="form-label small fw-semibold">Assinatura digital *</label>
            <canvas class="signature-pad" id="canvasAssinatura"></canvas>
            <div class="text-end"><button type="button" class="btn btn-sm btn-link" onclick="limparAssinatura()">Limpar</button></div>
        </div>
        <div class="mb-3">
            <label class="form-label small fw-semibold">Observações</label>
            <textarea name="observacoes" class="form-control" rows="2"></textarea>
        </div>
        <button type="submit" class="btn-confirm" id="btnConfirmar"><i class="bi bi-check-lg me-1"></i>Confirmar Recebimento</button>
    </form>
    <div id="msgResult" class="mt-3"></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const BASE_URL = <?php echo json_encode(BASE_URL); ?>;
const MISSAO_ID = <?php echo json_encode($missao_id); ?>;

navigator.geolocation.getCurrentPosition(p => {
    document.getElementById('latInput').value = p.coords.latitude;
    document.getElementById('lngInput').value = p.coords.longitude;
}, () => {});

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
    const cx = e.touches ? e.touches[0].clientX : e.clientX;
    const cy = e.touches ? e.touches[0].clientY : e.clientY;
    return { x: cx - rect.left, y: cy - rect.top };
}
function start(e) { drawing = true; ctx.beginPath(); const p = getPos(e); ctx.moveTo(p.x, p.y); e.preventDefault(); }
function move(e) { if (!drawing) return; const p = getPos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); e.preventDefault(); }
function end() { drawing = false; }
canvas.addEventListener('mousedown', start);
canvas.addEventListener('mousemove', move);
canvas.addEventListener('mouseup', end);
canvas.addEventListener('mouseleave', end);
canvas.addEventListener('touchstart', start, {passive:false});
canvas.addEventListener('touchmove', move, {passive:false});
canvas.addEventListener('touchend', end);
function limparAssinatura() { ctx.clearRect(0, 0, canvas.width, canvas.height); }

// Submit
const form = document.getElementById('formConfirmar');
form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = document.getElementById('btnConfirmar');
    btn.disabled = true; btn.innerHTML = '<i class="bi bi-hourglass-split"></i> A processar...';

    const formData = new FormData(form);
    const sig = canvas.toDataURL('image/png');
    if (sig && sig.startsWith('data:image')) {
        const blob = await (await fetch(sig)).blob();
        formData.append('assinatura', blob, 'assinatura.png');
    }

    try {
        const r = await fetch(BASE_URL + '/api/entrega-confirmar.php', { method:'POST', body: formData });
        const d = await r.json();
        const msg = document.getElementById('msgResult');
        if (d.ok) {
            msg.innerHTML = '<div class="alert alert-success text-center"><h6>Recebimento confirmado!</h6><p class="small mb-0">Obrigado. Pode agora avaliar o serviço.</p></div>';
            setTimeout(() => {
                window.location.href = BASE_URL + '/pages/entrega/avaliar.php?missao_id=' + MISSAO_ID + '&entrega_id=' + d.entrega_id;
            }, 1200);
        } else {
            msg.innerHTML = '<div class="alert alert-danger">' + (d.error || 'Erro') + '</div>';
            btn.disabled = false; btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Confirmar Recebimento';
        }
    } catch(e) {
        document.getElementById('msgResult').innerHTML = '<div class="alert alert-danger">Erro de ligação.</div>';
        btn.disabled = false; btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Confirmar Recebimento';
    }
});
</script>
</body>
</html>
