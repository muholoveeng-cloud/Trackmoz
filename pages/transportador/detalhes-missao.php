<?php
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');
include_once('../../includes/auth.php');
include_once('../../includes/helpers.php');
include_once('../../includes/frota-helpers.php');
include_once('../../includes/timeline-helpers.php');
include_once('../../includes/disputas-helpers.php');
include_once('../../includes/regras-negocio.php');

require_role(['transportador'], '../login.php');

if (!isset($_GET['id'])) {
    header('Location: ' . BASE_URL . '/pages/transportador/missoes.php');
    exit;
}

$missao_id = (int)$_GET['id'];
$transportador_id = (int)$_SESSION['user_id'];

$missao = null;
$error = null;
$motoristas = [];
$veiculos = [];
$independentes = [];
$frotaEstado = ['frota_disponivel' => true, 'motoristas_livres' => 0, 'veiculos_livres' => 0];
$motoristaNome = null;
$veiculoInfo = null;
$timelineEventos = [];

try {
    $sql = "SELECT m.*, pe.nome_empresa, u.telefone AS telefone_empresa
            FROM missoes m
            JOIN usuarios u ON m.empresa_id = u.id
            LEFT JOIN perfil_empresa pe ON u.id = pe.usuario_id
            WHERE m.id = :id AND m.transportador_id = :transportador_id";

    $stmt = $conn->prepare($sql);
    $stmt->execute([':id' => $missao_id, ':transportador_id' => $transportador_id]);
    $missao = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$missao) {
        header('Location: ' . BASE_URL . '/pages/transportador/missoes.php');
        exit;
    }

    $motoristas = transportador_listar_motoristas($conn, $transportador_id);
    $veiculos = array_values(array_filter(
        transportador_listar_veiculos($conn, $transportador_id),
        static fn($v) => ($v['estado_operacional'] ?? 'ativo') === 'ativo'
    ));
    $independentes = transportador_listar_independentes_disponiveis($conn);
    $frotaEstado = transportador_frota_tem_recursos_livres($conn, $transportador_id);
    $motoristaNome = transportador_nome_motorista_missao($conn, $missao);
    $veiculoInfo = transportador_info_veiculo_missao($conn, $missao);
    $timelineEventos = timeline_eventos_missao($conn, $missao_id);
} catch (PDOException $e) {
    error_log('Erro ao carregar detalhes da missão (transportador): ' . $e->getMessage());
    $error = 'Erro ao carregar a missão.';
}

$disputaMissao = null;
$podeAbrirDisputa = false;
try {
    disputas_bootstrap($conn);
    if (!empty($missao) && disputas_tabela_existe($conn)) {
        $disputaMissao = disputa_da_missao($conn, $missao_id);
        if (($missao['status'] ?? '') === 'concluida') {
            $chk = validar_missao_pode_disputar($conn, $missao_id, $transportador_id, 'transportador');
            $podeAbrirDisputa = !empty($chk['ok']);
        }
    }
} catch (Throwable $e) { /* ignore */ }
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detalhes da Missão - Transportador - TrackMoz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
</head>
<body>
    <?php include_once('../../includes/menu.php'); ?>

    <div class="container mt-4" style="max-width: 960px;">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3 class="mb-0">Detalhes da Missão</h3>
            <a class="btn btn-outline-secondary" href="<?php echo BASE_URL; ?>/pages/transportador/missoes.php">Voltar</a>
        </div>

        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars((string)$_GET['success']); ?></div>
        <?php endif; ?>

        <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars((string)$_GET['error']); ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($missao): ?>
            <div class="card mb-3">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h4 class="mb-1"><?php echo htmlspecialchars($missao['titulo'] ?? ''); ?></h4>
                            <p class="text-muted mb-0"><i class="bi bi-building"></i> <?php echo htmlspecialchars($missao['nome_empresa'] ?? ''); ?></p>
                        </div>
                        <?php
                            $status_raw = (string)($missao['status'] ?? '');
                            $status_label = $status_raw;
                            switch ($status_raw) {
                                case 'aceita': $status_label = 'Aceita'; break;
                                case 'em_andamento': $status_label = 'Em Andamento'; break;
                                case 'em_transito': $status_label = 'Em Trânsito'; break;
                                case 'em_entrega': $status_label = 'Em Entrega'; break;
                                case 'aguardando_confirmacao': $status_label = 'Aguardando Confirmação'; break;
                                case 'aguardando_aceitacao_transportadora': $status_label = 'Recebida - Aguardando Resposta'; break;
                                case 'concluida': $status_label = 'Concluída'; break;
                                case 'cancelada': $status_label = 'Cancelada'; break;
                                default: $status_label = ucfirst(str_replace('_', ' ', $status_raw));
                            }
                        ?>
                        <span class="badge bg-secondary"><?php echo htmlspecialchars($status_label); ?></span>
                    </div>

                    <hr>

                    <div class="d-flex flex-wrap gap-2 mb-3">
                        <?php if (($missao['status'] ?? '') === 'aguardando_aceitacao_transportadora'): ?>
                            <button class="btn btn-success" onclick="responderMissao('aceitar')"><i class="bi bi-check-circle"></i> Aceitar Missão</button>
                            <button class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#modalRecusarMissao"><i class="bi bi-x-circle"></i> Recusar</button>
                        <?php endif; ?>

                        <?php if (in_array($missao['status'] ?? '', ['aceita', 'em_andamento'], true)
                            && empty($missao['caminhoneiro_id']) && empty($missao['motorista_id'])): ?>
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAtribuirEquipa">
                                <i class="bi bi-person-plus"></i> Atribuir Motorista / Independente
                            </button>
                        <?php endif; ?>

                        <?php if (($missao['status'] ?? '') === 'aceita'): ?>
                            <form method="POST" action="<?php echo BASE_URL; ?>/pages/transportador/atualizar-status-missao.php" class="d-inline">
                                <input type="hidden" name="missao_id" value="<?php echo (int)$missao['id']; ?>">
                                <input type="hidden" name="acao" value="iniciar">
                                <button type="submit" class="btn btn-success"><i class="bi bi-play-circle"></i> Iniciar</button>
                            </form>
                        <?php endif; ?>

                        <?php if (($missao['status'] ?? '') === 'em_andamento'): ?>
                            <form method="POST" action="<?php echo BASE_URL; ?>/pages/transportador/atualizar-status-missao.php" class="d-inline">
                                <input type="hidden" name="missao_id" value="<?php echo (int)$missao['id']; ?>">
                                <input type="hidden" name="acao" value="em_transito">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-truck"></i> Marcar Em Trânsito
                                </button>
                            </form>
                        <?php endif; ?>

                        <?php if (($missao['status'] ?? '') === 'em_transito'): ?>
                            <form method="POST" action="<?php echo BASE_URL; ?>/pages/transportador/atualizar-status-missao.php" class="d-inline">
                                <input type="hidden" name="missao_id" value="<?php echo (int)$missao['id']; ?>">
                                <input type="hidden" name="acao" value="em_entrega">
                                <button type="submit" class="btn btn-info">
                                    <i class="bi bi-geo-alt"></i> Marcar Em Entrega
                                </button>
                            </form>
                        <?php endif; ?>

                        <?php if (in_array($missao['status'] ?? '', ['em_entrega', 'em_transito'], true)): ?>
                            <button type="button" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#modalFinalizarOtp">
                                <i class="bi bi-key"></i> Finalizar com OTP
                            </button>
                        <?php endif; ?>

                        <?php if (($missao['status'] ?? '') === 'aguardando_confirmacao'): ?>
                            <form method="POST" action="<?php echo BASE_URL; ?>/pages/transportador/atualizar-status-missao.php" class="d-inline">
                                <input type="hidden" name="missao_id" value="<?php echo (int)$missao['id']; ?>">
                                <input type="hidden" name="acao" value="concluir">
                                <button type="submit" class="btn btn-success">
                                    <i class="bi bi-flag"></i> Concluir
                                </button>
                            </form>
                        <?php endif; ?>

                        <?php if ($disputaMissao): ?>
                            <a href="<?php echo BASE_URL; ?>/pages/shared/disputa.php?id=<?php echo (int)$disputaMissao['id']; ?>"
                               class="btn btn-<?php echo $disputaMissao['status'] === 'encerrada' ? 'outline-secondary' : 'warning'; ?>">
                                <i class="bi bi-shield-exclamation"></i>
                                Disputa #<?php echo (int)$disputaMissao['id']; ?>
                            </a>
                        <?php elseif ($podeAbrirDisputa): ?>
                            <button type="button" class="btn btn-outline-warning" data-bs-toggle="modal" data-bs-target="#modalDisputa">
                                <i class="bi bi-shield-exclamation"></i> Abrir disputa
                            </button>
                        <?php endif; ?>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="p-3 bg-light rounded">
                                <div class="d-flex align-items-center mb-2">
                                    <i class="bi bi-geo-alt text-primary me-2"></i>
                                    <strong>Origem</strong>
                                </div>
                                <div><?php echo htmlspecialchars($missao['origem'] ?? ''); ?></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="p-3 bg-light rounded">
                                <div class="d-flex align-items-center mb-2">
                                    <i class="bi bi-geo-alt-fill text-primary me-2"></i>
                                    <strong>Destino</strong>
                                </div>
                                <div><?php echo htmlspecialchars($missao['destino'] ?? ''); ?></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="p-3 bg-light rounded">
                                <div class="d-flex align-items-center mb-2">
                                    <i class="bi bi-truck text-primary me-2"></i>
                                    <strong>Tipo de Veículo</strong>
                                </div>
                                <div><?php echo htmlspecialchars($missao['tipo_veiculo'] ?? ''); ?></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="p-3 bg-light rounded">
                                <div class="d-flex align-items-center mb-2">
                                    <i class="bi bi-currency-dollar text-primary me-2"></i>
                                    <strong>Valor</strong>
                                </div>
                                <div><?php echo number_format((float)($missao['valor'] ?? 0), 2, ',', '.'); ?> MT</div>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="p-3 bg-light rounded">
                                <div class="d-flex align-items-center mb-2">
                                    <i class="bi bi-card-text text-primary me-2"></i>
                                    <strong>Descrição</strong>
                                </div>
                                <div><?php echo nl2br(htmlspecialchars($missao['descricao'] ?? '')); ?></div>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="p-3 bg-light rounded">
                                <div class="d-flex align-items-center mb-2">
                                    <i class="bi bi-telephone text-primary me-2"></i>
                                    <strong>Contato da Empresa</strong>
                                </div>
                                <div><?php echo htmlspecialchars($missao['telefone_empresa'] ?? ''); ?></div>
                            </div>
                        </div>
                        <?php if ($motoristaNome || $veiculoInfo): ?>
                        <div class="col-md-6">
                            <div class="p-3 bg-light rounded">
                                <div class="d-flex align-items-center mb-2"><i class="bi bi-person text-primary me-2"></i><strong>Motorista</strong></div>
                                <div><?php echo $motoristaNome ? e($motoristaNome) : '<span class="text-muted">Não atribuído</span>'; ?></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="p-3 bg-light rounded">
                                <div class="d-flex align-items-center mb-2"><i class="bi bi-truck text-primary me-2"></i><strong>Viatura</strong></div>
                                <div><?php echo $veiculoInfo ? e($veiculoInfo['matricula'] . ' — ' . $veiculoInfo['marca'] . ' ' . $veiculoInfo['modelo']) : '<span class="text-muted">Não atribuída</span>'; ?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if ($missao['previsao_recolha']): ?>
                        <div class="col-md-6">
                            <div class="p-3 bg-light rounded">
                                <div class="d-flex align-items-center mb-2"><i class="bi bi-calendar text-primary me-2"></i><strong>Previsão Recolha</strong></div>
                                <div><?php echo date('d/m/Y H:i', strtotime($missao['previsao_recolha'])); ?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if ($missao['previsao_entrega']): ?>
                        <div class="col-md-6">
                            <div class="p-3 bg-light rounded">
                                <div class="d-flex align-items-center mb-2"><i class="bi bi-calendar-check text-primary me-2"></i><strong>Previsão Entrega</strong></div>
                                <div><?php echo date('d/m/Y H:i', strtotime($missao['previsao_entrega'])); ?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($timelineEventos)): ?>
                        <div class="col-12">
                            <div class="p-3 bg-light rounded">
                                <div class="d-flex align-items-center mb-2"><i class="bi bi-clock-history text-primary me-2"></i><strong>Timeline operacional</strong></div>
                                <?php echo timeline_render_html($timelineEventos); ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Modal Finalizar com OTP -->
    <div class="modal fade" id="modalFinalizarOtp" tabindex="-1">
        <div class="modal-dialog"><div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-key me-2"></i>Confirmar entrega com OTP</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="<?php echo BASE_URL; ?>/pages/transportador/atualizar-status-missao.php">
                <div class="modal-body">
                    <p class="small text-muted">
                        Peça ao destinatário o código de 6 dígitos enviado pela empresa (WhatsApp/SMS).
                        Sem OTP válido a entrega não passa a «aguardando confirmação».
                    </p>
                    <input type="hidden" name="missao_id" value="<?php echo (int)$missao_id; ?>">
                    <input type="hidden" name="acao" value="finalizar">
                    <label class="form-label fw-semibold">Código OTP *</label>
                    <input type="text" name="otp" class="form-control form-control-lg text-center"
                           maxlength="6" inputmode="numeric" pattern="[0-9]{6}" required
                           placeholder="______" autocomplete="one-time-code">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-warning">Validar OTP e finalizar</button>
                </div>
            </form>
        </div></div>
    </div>

    <!-- Modal Recusar -->
    <div class="modal fade" id="modalRecusarMissao" tabindex="-1">
        <div class="modal-dialog"><div class="modal-content">
            <div class="modal-header"><h5 class="modal-title text-danger">Recusar Missão</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <form onsubmit="return enviarRecusarMissao(event)">
                <div class="modal-body">
                    <p>Tem certeza que deseja recusar esta missão?</p>
                    <textarea name="motivo" class="form-control" rows="3" placeholder="Motivo (opcional)"></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-danger">Recusar Missão</button>
                </div>
            </form>
        </div></div>
    </div>

    <!-- Modal Atribuir Equipa -->
    <div class="modal fade" id="modalAtribuirEquipa" tabindex="-1">
        <div class="modal-dialog modal-lg"><div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Atribuir execução</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form onsubmit="return enviarAtribuirEquipa(event)" id="formAtribuirEquipa">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="missao_id" value="<?php echo $missao_id; ?>">
                <input type="hidden" name="modo" id="atribuirModo" value="frota">
                <div class="modal-body">
                    <?php if (empty($frotaEstado['frota_disponivel'])): ?>
                        <div class="alert alert-warning small">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            Frota sem recursos livres
                            (motoristas livres: <?php echo (int)$frotaEstado['motoristas_livres']; ?>,
                            viaturas livres: <?php echo (int)$frotaEstado['veiculos_livres']; ?>).
                            Pode <strong>convidar um motorista independente</strong> da plataforma.
                        </div>
                    <?php endif; ?>

                    <ul class="nav nav-pills mb-3" role="tablist">
                        <li class="nav-item">
                            <button type="button" class="nav-link active" id="tabFrota"
                                    onclick="setAtribuirModo('frota')">Frota própria</button>
                        </li>
                        <li class="nav-item">
                            <button type="button" class="nav-link" id="tabIndep"
                                    onclick="setAtribuirModo('independente')">Motorista independente</button>
                        </li>
                    </ul>

                    <div id="painelFrota">
                        <?php if (empty($motoristas) || empty($veiculos)): ?>
                            <div class="alert alert-secondary small">Ainda não tem motoristas/viaturas activos na frota. Use a aba «Independente» ou cadastre recursos em Frota.</div>
                        <?php endif; ?>
                        <div class="mb-3">
                            <label class="form-label">Motorista da frota</label>
                            <select class="form-select" name="motorista_id" id="selMotoristaFrota">
                                <option value="">Seleccione...</option>
                                <?php foreach ($motoristas as $mot): ?>
                                    <option value="<?php echo (int)$mot['id']; ?>"><?php echo e($mot['nome']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Viatura</label>
                            <select class="form-select" name="veiculo_id" id="selVeiculoFrota">
                                <option value="">Seleccione...</option>
                                <?php foreach ($veiculos as $v): ?>
                                    <option value="<?php echo (int)$v['id']; ?>"><?php echo e($v['matricula'] . ' — ' . $v['marca'] . ' ' . $v['modelo']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div id="painelIndep" class="d-none">
                        <p class="small text-muted">
                            Contrata um caminhoneiro da plataforma (usa o próprio veículo). A transportadora
                            continua responsável perante a empresa. Respeita o limite de 1 missão activa e CNH válida.
                        </p>
                        <div class="mb-3">
                            <label class="form-label">Motorista independente disponível</label>
                            <select class="form-select" name="caminhoneiro_id" id="selIndependente">
                                <option value="">Seleccione...</option>
                                <?php foreach ($independentes as $ind): ?>
                                    <option value="<?php echo (int)$ind['id']; ?>">
                                        <?php echo e($ind['nome']); ?>
                                        · ★ <?php echo number_format((float)($ind['avaliacao_media'] ?? 0), 1); ?>
                                        · <?php echo (int)($ind['total_entregas'] ?? 0); ?> entregas
                                        <?php if (!empty($ind['tipo_veiculo'])): ?> · <?php echo e($ind['tipo_veiculo']); endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (empty($independentes)): ?>
                                <div class="form-text text-danger">Não há independentes disponíveis de momento.</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label">Previsão Recolha</label>
                            <input type="datetime-local" class="form-control" name="previsao_recolha">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Previsão Entrega</label>
                            <input type="datetime-local" class="form-control" name="previsao_entrega">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Confirmar atribuição</button>
                </div>
            </form>
        </div></div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?php echo BASE_URL; ?>/assets/js/main.js"></script>
    <?php if ($podeAbrirDisputa): ?>
    <div class="modal fade" id="modalDisputa" tabindex="-1">
        <div class="modal-dialog"><div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Abrir disputa</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <form id="formDisputa">
                    <input type="hidden" name="missao_id" value="<?php echo (int)$missao_id; ?>">
                    <?php echo csrf_field(); ?>
                    <div class="mb-2">
                        <label class="form-label">Categoria</label>
                        <select name="categoria" class="form-select">
                            <?php foreach (DISPUTA_CATEGORIAS as $k => $lab): ?>
                                <option value="<?php echo e($k); ?>"><?php echo e($lab); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <label class="form-label">Motivo</label>
                    <textarea name="motivo" class="form-control" rows="4" required minlength="20"></textarea>
                </form>
                <div id="msgDisputa" class="small mt-2"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-warning" id="btnSubmeterDisputa">Submeter</button>
            </div>
        </div></div>
    </div>
    <?php endif; ?>
    <script>
    const BASE_URL = '<?php echo BASE_URL; ?>';
    const CSRF_TOKEN = '<?php echo csrf_token(); ?>';
    const missaoId = <?php echo $missao_id; ?>;

    document.getElementById('btnSubmeterDisputa')?.addEventListener('click', async () => {
        const form = document.getElementById('formDisputa');
        if (!form || !confirm('Confirma abertura de disputa?')) return;
        const r = await fetch(BASE_URL + '/api/disputa-criar.php', { method: 'POST', body: new FormData(form) });
        const d = await r.json();
        if (d.success) location.href = d.redirect || location.href;
        else {
            const el = document.getElementById('msgDisputa');
            if (el) el.innerHTML = '<span class="text-danger">' + (d.message || 'Erro') + '</span>';
        }
    });

    async function responderMissao(acao) {
        if (!confirm(acao === 'aceitar' ? 'Aceitar esta missão?' : 'Recusar esta missão?')) return;
        const form = new FormData();
        form.append('missao_id', missaoId);
        form.append('acao', acao);
        form.append('csrf_token', CSRF_TOKEN);
        try {
            const r = await fetch(BASE_URL + '/api/missao-transportador-responder.php', {method:'POST', body:form});
            const d = await r.json();
            alert(d.message);
            if (d.success) location.reload();
        } catch(e){ alert('Erro de ligação.'); }
    }

    async function enviarRecusarMissao(e) {
        e.preventDefault();
        const form = e.target;
        const data = new FormData(form);
        data.append('missao_id', missaoId);
        data.append('acao', 'recusar');
        data.append('csrf_token', CSRF_TOKEN);
        try {
            const r = await fetch(BASE_URL + '/api/missao-transportador-responder.php', {method:'POST', body:data});
            const d = await r.json();
            alert(d.message);
            if (d.success) location.href = BASE_URL + '/pages/transportador/missoes.php?status=recebidas';
        } catch(err){ alert('Erro de ligação.'); }
        return false;
    }

    function setAtribuirModo(modo) {
        document.getElementById('atribuirModo').value = modo;
        const frota = document.getElementById('painelFrota');
        const indep = document.getElementById('painelIndep');
        const tabF = document.getElementById('tabFrota');
        const tabI = document.getElementById('tabIndep');
        const isFrota = modo === 'frota';
        frota.classList.toggle('d-none', !isFrota);
        indep.classList.toggle('d-none', isFrota);
        tabF.classList.toggle('active', isFrota);
        tabI.classList.toggle('active', !isFrota);
        document.getElementById('selMotoristaFrota').required = isFrota;
        document.getElementById('selVeiculoFrota').required = isFrota;
        document.getElementById('selIndependente').required = !isFrota;
    }

    async function enviarAtribuirEquipa(e) {
        e.preventDefault();
        const form = e.target;
        const data = new FormData(form);
        if (!data.get('csrf_token')) data.append('csrf_token', CSRF_TOKEN);
        try {
            const r = await fetch(BASE_URL + '/api/missao-atribuir-equipa.php', {method:'POST', body:data});
            const d = await r.json();
            alert(d.message || (d.success ? 'OK' : 'Erro'));
            if (d.success) location.reload();
        } catch(err){ alert('Erro de ligação.'); }
        return false;
    }
    setAtribuirModo(<?php echo empty($frotaEstado['frota_disponivel']) ? "'independente'" : "'frota'"; ?>);
    </script>
</body>
</html>
