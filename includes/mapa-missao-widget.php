<?php
/**
 * Widget de mapa para páginas de detalhe da missão.
 * Definir antes do include:
 *   $mapa_missao_id, $mapa_origem_lat, $mapa_origem_lng, $mapa_destino_lat, $mapa_destino_lng
 *   $mapa_origem_txt, $mapa_destino_txt, $mapa_poll_missao_id (opcional)
 */
$mapa_widget_uid = 'mapaMissao_' . ($mapa_missao_id ?? uniqid());
$mapa_altura     = $mapa_altura ?? '300px';
?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-transparent border-bottom d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-semibold"><i class="bi bi-map me-2 text-primary"></i>Rota no mapa</h6>
        <span id="<?php echo e($mapa_widget_uid); ?>_info" class="small text-muted d-none"></span>
    </div>
    <div class="card-body p-2">
        <div id="<?php echo e($mapa_widget_uid); ?>" style="height:<?php echo e($mapa_altura); ?>;border-radius:.5rem;"></div>
        <div class="d-flex gap-3 mt-2 small text-muted">
            <span><span class="d-inline-block rounded-circle bg-success" style="width:10px;height:10px"></span> Origem</span>
            <span><span class="d-inline-block rounded-circle bg-danger" style="width:10px;height:10px"></span> Destino</span>
            <?php if (!empty($mapa_poll_missao_id)): ?>
                <span>🚛 Posição do motorista (actualização automática)</span>
            <?php endif; ?>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof MapaMissaoDetalhe === 'undefined') return;
    new MapaMissaoDetalhe(<?php echo json_encode($mapa_widget_uid); ?>, {
        baseUrl: <?php echo json_encode(BASE_URL); ?>,
        origemLat: <?php echo json_encode(isset($mapa_origem_lat) && $mapa_origem_lat !== '' ? (float)$mapa_origem_lat : null); ?>,
        origemLng: <?php echo json_encode(isset($mapa_origem_lng) && $mapa_origem_lng !== '' ? (float)$mapa_origem_lng : null); ?>,
        destinoLat: <?php echo json_encode(isset($mapa_destino_lat) && $mapa_destino_lat !== '' ? (float)$mapa_destino_lat : null); ?>,
        destinoLng: <?php echo json_encode(isset($mapa_destino_lng) && $mapa_destino_lng !== '' ? (float)$mapa_destino_lng : null); ?>,
        origemTxt: <?php echo json_encode($mapa_origem_txt ?? ''); ?>,
        destinoTxt: <?php echo json_encode($mapa_destino_txt ?? ''); ?>,
        missaoId: <?php echo json_encode($mapa_poll_missao_id ?? null); ?>,
        infoId: <?php echo json_encode($mapa_widget_uid . '_info'); ?>,
    }).init();
});
</script>
