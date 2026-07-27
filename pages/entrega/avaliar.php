<?php
include_once('../../config/app.php');
include_once('../../config/database.php');

$missao_id  = isset($_GET['missao_id']) ? (int)$_GET['missao_id'] : 0;
$entrega_id = isset($_GET['entrega_id']) ? (int)$_GET['entrega_id'] : 0;

if ($missao_id <= 0 || $entrega_id <= 0) {
    echo '<div class="alert alert-danger">Link inválido.</div>';
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Avaliar Entrega — TrackMoz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <style>
        body { background: #f8f9fa; min-height: 100vh; }
        .rate-card { max-width: 480px; margin: 24px auto; background: #fff; border-radius: 20px; padding: 28px; box-shadow: 0 8px 30px rgba(0,0,0,.08); }
        .star-rating { display: flex; gap: 8px; justify-content: center; font-size: 1.8rem; }
        .star-rating i { color: #dee2e6; cursor: pointer; transition: color .15s; }
        .star-rating i.active { color: #ffc107; }
        .star-rating i:hover { color: #ffc107; }
    </style>
</head>
<body>
<div class="rate-card">
    <div class="text-center mb-3">
        <i class="bi bi-star-fill text-warning" style="font-size:2rem"></i>
        <h5 class="fw-bold mt-2">Avaliar Entrega</h5>
        <p class="text-muted small">A sua opinião ajuda-nos a melhorar.</p>
    </div>

    <form id="formAvaliar">
        <input type="hidden" name="missao_id" value="<?php echo $missao_id; ?>">
        <input type="hidden" name="entrega_id" value="<?php echo $entrega_id; ?>">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">

        <div class="mb-3 text-center">
            <label class="form-label small fw-semibold">Nota geral</label>
            <div class="star-rating" data-field="nota_geral">
                <?php for($i=1;$i<=5;$i++): ?><i class="bi bi-star-fill" data-val="<?php echo $i; ?>"></i><?php endfor; ?>
            </div>
            <input type="hidden" name="nota_geral" id="nota_geral" required>
        </div>
        <div class="mb-3 text-center">
            <label class="form-label small fw-semibold">Pontualidade</label>
            <div class="star-rating" data-field="nota_pontualidade">
                <?php for($i=1;$i<=5;$i++): ?><i class="bi bi-star-fill" data-val="<?php echo $i; ?>"></i><?php endfor; ?>
            </div>
            <input type="hidden" name="nota_pontualidade" id="nota_pontualidade">
        </div>
        <div class="mb-3 text-center">
            <label class="form-label small fw-semibold">Estado da carga</label>
            <div class="star-rating" data-field="nota_estado_carga">
                <?php for($i=1;$i<=5;$i++): ?><i class="bi bi-star-fill" data-val="<?php echo $i; ?>"></i><?php endfor; ?>
            </div>
            <input type="hidden" name="nota_estado_carga" id="nota_estado_carga">
        </div>
        <div class="mb-3 text-center">
            <label class="form-label small fw-semibold">Comunicação do motorista</label>
            <div class="star-rating" data-field="nota_comunicacao">
                <?php for($i=1;$i<=5;$i++): ?><i class="bi bi-star-fill" data-val="<?php echo $i; ?>"></i><?php endfor; ?>
            </div>
            <input type="hidden" name="nota_comunicacao" id="nota_comunicacao">
        </div>

        <div class="mb-3">
            <label class="form-label small fw-semibold">Comentário</label>
            <textarea name="comentario" class="form-control" rows="2" placeholder="Opcional..."></textarea>
        </div>
        <div class="mb-3">
            <label class="form-label small fw-semibold">Problema a reportar?</label>
            <textarea name="problema" class="form-control" rows="2" placeholder="Descreva se houve algum problema..."></textarea>
        </div>
        <button type="submit" class="btn btn-success w-100 fw-bold py-3" id="btnEnviar">
            <i class="bi bi-send-fill me-1"></i>Enviar Avaliação
        </button>
    </form>
    <div id="msgResult" class="mt-3"></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const BASE_URL = <?php echo json_encode(BASE_URL); ?>;

// Star rating
function initStarRating(container) {
    const field = container.dataset.field;
    const input = document.getElementById(field);
    const stars = container.querySelectorAll('i');
    stars.forEach(s => {
        s.addEventListener('click', () => {
            const val = parseInt(s.dataset.val);
            input.value = val;
            stars.forEach(st => st.classList.toggle('active', parseInt(st.dataset.val) <= val));
        });
    });
}
document.querySelectorAll('.star-rating').forEach(initStarRating);

// Submit
const form = document.getElementById('formAvaliar');
form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = document.getElementById('btnEnviar');
    btn.disabled = true; btn.innerHTML = '<i class="bi bi-hourglass-split"></i> A enviar...';

    try {
        const r = await fetch(BASE_URL + '/api/entrega-avaliar.php', { method:'POST', body: new FormData(form) });
        const d = await r.json();
        const msg = document.getElementById('msgResult');
        if (d.ok) {
            msg.innerHTML = '<div class="alert alert-success text-center"><h6>Obrigado!</h6><p class="small mb-0">A sua avaliação foi registada.</p></div>';
            setTimeout(() => { window.close(); }, 2000);
        } else {
            msg.innerHTML = '<div class="alert alert-danger">' + (d.error || 'Erro') + '</div>';
            btn.disabled = false; btn.innerHTML = '<i class="bi bi-send-fill me-1"></i>Enviar Avaliação';
        }
    } catch(e) {
        document.getElementById('msgResult').innerHTML = '<div class="alert alert-danger">Erro de ligação.</div>';
        btn.disabled = false; btn.innerHTML = '<i class="bi bi-send-fill me-1"></i>Enviar Avaliação';
    }
});
</script>
</body>
</html>
