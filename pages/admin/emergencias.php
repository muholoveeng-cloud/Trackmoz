<?php
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');
include_once('../../includes/auth.php');

require_role(['admin','empresa'], '../login.php');

$uid   = (int)$_SESSION['user_id'];
$utype = $_SESSION['user_type'];

$statusFiltro = $_GET['status'] ?? '';
$emgId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

try {
    // Detalhe de uma emergência específica
    $emergencia = null;
    if ($emgId > 0) {
        $stmt = $conn->prepare(
            "SELECT e.*, m.titulo AS missao_titulo, m.empresa_id, m.caminhoneiro_id, m.status AS missao_status,
                    u.nome AS motorista_nome, u.telefone AS motorista_telefone,
                    adm.nome AS resolvido_por_nome
             FROM emergencias e
             JOIN missoes m ON e.missao_id = m.id
             JOIN usuarios u ON e.caminhoneiro_id = u.id
             LEFT JOIN usuarios adm ON e.resolvido_por = adm.id
             WHERE e.id = ?"
        );
        $stmt->execute([$emgId]);
        $emergencia = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($emergencia && $utype === 'empresa' && (int)$emergencia['empresa_id'] !== $uid) {
            $emergencia = null;
        }
    }

    // Lista de emergências
    $where = [];
    $params = [];
    if ($utype === 'empresa') {
        $where[] = 'm.empresa_id = :uid';
        $params[':uid'] = $uid;
    }
    if ($statusFiltro && in_array($statusFiltro, ['aberta','em_atendimento','resolvida','cancelada'], true)) {
        $where[] = 'e.status = :st';
        $params[':st'] = $statusFiltro;
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $stmt = $conn->prepare(
        "SELECT e.id, e.missao_id, e.tipo, e.gravidade, e.status, e.data_criacao,
                m.titulo AS missao_titulo, u.nome AS motorista_nome
         FROM emergencias e
         JOIN missoes m ON e.missao_id = m.id
         JOIN usuarios u ON e.caminhoneiro_id = u.id
         $whereSql
         ORDER BY e.data_criacao DESC
         LIMIT 100"
    );
    $stmt->execute($params);
    $lista = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    error_log('admin/emergencias: ' . $e->getMessage());
    $lista = [];
    $emergencia = null;
}

$labelsTipo = [
    'acidente'=>'Acidente','avaria'=>'Avaria mecânica','furo'=>'Furo / pneu',
    'problema_carga'=>'Problema com a carga','roubo'=>'Roubo / assalto','saude'=>'Problema de saúde',
    'fiscalizacao'=>'Fiscalização / autoridade','atraso_grave'=>'Atraso grave','outro'=>'Outro'
];
$labelsGrav = ['baixa'=>'Baixa','media'=>'Média','alta'=>'Alta','critica'=>'Crítica'];
$badgeGrav = ['baixa'=>'success','media'=>'warning','alta'=>'danger','critica'=>'dark'];
$badgeStatus = ['aberta'=>'danger','em_atendimento'=>'warning','resolvida'=>'success','cancelada'=>'secondary'];
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Emergências — TrackMoz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
</head>
<body>
<?php include_once('../../includes/menu.php'); ?>
<div class="container py-4">
    <h4 class="fw-bold mb-3"><i class="bi bi-exclamation-triangle-fill text-danger me-2"></i>Emergências</h4>

    <?php if ($emergencia): ?>
    <!-- Detalhe -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>Emergência #<?php echo $emergencia['id']; ?></span>
            <span class="badge bg-<?php echo $badgeStatus[$emergencia['status']] ?? 'secondary'; ?>">
                <?php echo ucfirst($emergencia['status']); ?>
            </span>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <p><strong>Missão:</strong> <?php echo htmlspecialchars($emergencia['missao_titulo']); ?> (#<?php echo $emergencia['missao_id']; ?>)</p>
                    <p><strong>Motorista:</strong> <?php echo htmlspecialchars($emergencia['motorista_nome']); ?> <?php if($emergencia['motorista_telefone']): ?><a href="tel:<?php echo htmlspecialchars($emergencia['motorista_telefone']); ?>"><i class="bi bi-telephone"></i></a><?php endif; ?></p>
                    <p><strong>Tipo:</strong> <?php echo $labelsTipo[$emergencia['tipo']] ?? $emergencia['tipo']; ?></p>
                    <p><strong>Gravidade:</strong> <span class="badge bg-<?php echo $badgeGrav[$emergencia['gravidade']] ?? 'secondary'; ?>"><?php echo $labelsGrav[$emergencia['gravidade']] ?? $emergencia['gravidade']; ?></span></p>
                    <p><strong>Data:</strong> <?php echo date('d/m/Y H:i', strtotime($emergencia['data_criacao'])); ?></p>
                    <?php if ($emergencia['latitude'] && $emergencia['longitude']): ?>
                        <p><strong>Localização:</strong>
                            <a href="https://maps.google.com/?q=<?php echo $emergencia['latitude']; ?>,<?php echo $emergencia['longitude']; ?>" target="_blank">
                                <?php echo number_format((float)$emergencia['latitude'],6); ?>, <?php echo number_format((float)$emergencia['longitude'],6); ?>
                                <i class="bi bi-box-arrow-up-right"></i>
                            </a>
                        </p>
                    <?php endif; ?>
                </div>
                <div class="col-md-6">
                    <p><strong>Descrição:</strong></p>
                    <div class="p-3 bg-light rounded"><?php echo nl2br(htmlspecialchars($emergencia['descricao'])); ?></div>
                    <?php if ($emergencia['anexo_url']): ?>
                        <p class="mt-2"><strong>Anexo:</strong>
                            <a href="<?php echo htmlspecialchars($emergencia['anexo_url']); ?>" target="_blank">
                                <i class="bi bi-paperclip"></i> Ver anexo
                            </a>
                        </p>
                    <?php endif; ?>
                </div>
            </div>
            <hr>
            <form id="formAtualizar" class="row g-2">
                <input type="hidden" name="emergencia_id" value="<?php echo $emergencia['id']; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <div class="col-md-4">
                    <label class="form-label small">Novo status</label>
                    <select name="status" class="form-select" required>
                        <option value="aberta" <?php echo $emergencia['status']==='aberta'?'selected':''; ?>>Aberta</option>
                        <option value="em_atendimento" <?php echo $emergencia['status']==='em_atendimento'?'selected':''; ?>>Em atendimento</option>
                        <option value="resolvida" <?php echo $emergencia['status']==='resolvida'?'selected':''; ?>>Resolvida</option>
                        <option value="cancelada" <?php echo $emergencia['status']==='cancelada'?'selected':''; ?>>Cancelada</option>
                    </select>
                </div>
                <div class="col-md-8">
                    <label class="form-label small">Resposta / notas internas</label>
                    <textarea name="resposta" class="form-control" rows="2" placeholder="Resposta ao motorista..."><?php echo htmlspecialchars($emergencia['resposta_admin'] ?? ''); ?></textarea>
                </div>
                <div class="col-12 text-end">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Actualizar emergência</button>
                </div>
            </form>
            <div id="msgUpdate" class="mt-2"></div>
        </div>
    </div>
    <a href="?" class="btn btn-outline-secondary btn-sm mb-3"><i class="bi bi-arrow-left"></i> Voltar à lista</a>
    <?php endif; ?>

    <!-- Filtros -->
    <div class="d-flex gap-2 mb-3 flex-wrap">
        <a href="?" class="btn btn-sm <?php echo !$statusFiltro ? 'btn-dark' : 'btn-outline-secondary'; ?>">Todas</a>
        <a href="?status=aberta" class="btn btn-sm <?php echo $statusFiltro==='aberta' ? 'btn-danger' : 'btn-outline-danger'; ?>">Abertas</a>
        <a href="?status=em_atendimento" class="btn btn-sm <?php echo $statusFiltro==='em_atendimento' ? 'btn-warning' : 'btn-outline-warning'; ?>">Em atendimento</a>
        <a href="?status=resolvida" class="btn btn-sm <?php echo $statusFiltro==='resolvida' ? 'btn-success' : 'btn-outline-success'; ?>">Resolvidas</a>
    </div>

    <!-- Lista -->
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>Missão</th>
                    <th>Motorista</th>
                    <th>Tipo</th>
                    <th>Gravidade</th>
                    <th>Status</th>
                    <th>Data</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($lista)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">Nenhuma emergência registada.</td></tr>
                <?php else: ?>
                    <?php foreach ($lista as $e): ?>
                    <tr>
                        <td><?php echo $e['id']; ?></td>
                        <td><a href="<?php echo BASE_URL; ?>/pages/contratante/detalhes-missao.php?id=<?php echo $e['missao_id']; ?>">#<?php echo $e['missao_id']; ?></a></td>
                        <td><?php echo htmlspecialchars($e['motorista_nome']); ?></td>
                        <td><?php echo $labelsTipo[$e['tipo']] ?? $e['tipo']; ?></td>
                        <td><span class="badge bg-<?php echo $badgeGrav[$e['gravidade']] ?? 'secondary'; ?>"><?php echo $labelsGrav[$e['gravidade']] ?? $e['gravidade']; ?></span></td>
                        <td><span class="badge bg-<?php echo $badgeStatus[$e['status']] ?? 'secondary'; ?>"><?php echo ucfirst($e['status']); ?></span></td>
                        <td class="small"><?php echo date('d/m H:i', strtotime($e['data_criacao'])); ?></td>
                        <td><a href="?id=<?php echo $e['id']; ?>" class="btn btn-sm btn-outline-primary">Ver</a></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const BASE_URL = <?php echo json_encode(BASE_URL); ?>;
const formAtualizar = document.getElementById('formAtualizar');
const msgUpdate = document.getElementById('msgUpdate');
if (formAtualizar) {
    formAtualizar.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = formAtualizar.querySelector('button[type="submit"]');
        btn.disabled = true;
        const form = new FormData(formAtualizar);
        try {
            const r = await fetch(BASE_URL + '/api/emergencia-update.php', { method:'POST', body: form });
            const d = await r.json();
            msgUpdate.innerHTML = d.ok
                ? '<div class="alert alert-success py-2">' + d.message + '</div>'
                : '<div class="alert alert-danger py-2">' + (d.error || 'Erro') + '</div>';
            if (d.ok) setTimeout(() => location.reload(), 900);
        } catch(e) {
            msgUpdate.innerHTML = '<div class="alert alert-danger py-2">Erro de ligação</div>';
        } finally {
            btn.disabled = false;
        }
    });
}
</script>
</body>
</html>
