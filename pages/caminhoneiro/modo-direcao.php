<?php
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');
include_once('../../includes/auth.php');
include_once('../../includes/helpers.php');
include_once('../../includes/missao-helpers.php');
include_once('../../includes/geocode.php');
include_once('../../includes/motorista-regras.php');
include_once('../../includes/checklist-helpers.php');

require_role(['caminhoneiro'], '../login.php');

if (!isset($_GET['missao_id'])) {
    header('Location: ' . BASE_URL . '/pages/caminhoneiro/missoes.php');
    exit;
}

$missao_id = (int)$_GET['missao_id'];
$user_id   = (int)$_SESSION['user_id'];

try {
    missao_garantir_colunas_operacionais($conn);

    $extraEntrega = coluna_existe($conn, 'missoes', 'status_entrega') ? ', m.status_entrega' : '';

    $stmt = $conn->prepare(
        "SELECT m.id, m.titulo, m.origem, m.destino, m.status, m.status_viagem, m.caminhoneiro_id{$extraEntrega},
                m.modo_conducao_ativo, m.data_inicio_conducao, m.data_pausa_conducao,
                m.data_retomada_conducao, m.tempo_conducao_acumulado_seg,
                lo.latitude  AS origem_lat,  lo.longitude  AS origem_lng,
                ld.latitude  AS destino_lat, ld.longitude  AS destino_lng
         FROM missoes m
         LEFT JOIN locais lo ON m.local_origem_id  = lo.id
         LEFT JOIN locais ld ON m.local_destino_id = ld.id
         WHERE m.id = :mid AND m.caminhoneiro_id = :uid"
    );
    $stmt->execute([':mid' => $missao_id, ':uid' => $user_id]);
    $missao = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$missao) {
        header('Location: ' . BASE_URL . '/pages/caminhoneiro/missoes.php');
        exit;
    }

    $modoCheck = motorista_pode_modo_conducao($conn, $user_id, $missao);
    if (!$modoCheck['ok'] && empty($missao['modo_conducao_ativo']) && empty($missao['data_inicio_conducao'])) {
        header('Location: ' . BASE_URL . '/pages/caminhoneiro/detalhes-missao.php?id=' . $missao_id . '&error=' . urlencode($modoCheck['motivo']));
        exit;
    }

    garantir_locais_missao($conn, $missao_id);
    $stmt->execute([':mid' => $missao_id, ':uid' => $user_id]);
    $missao = enriquecer_missao_mapa($stmt->fetch(PDO::FETCH_ASSOC));
    $checklistEstado = checklist_estado_missao($conn, $missao_id);
} catch (PDOException $e) {
    error_log('modo-direcao: ' . $e->getMessage());
    header('Location: ' . BASE_URL . '/pages/caminhoneiro/missoes.php');
    exit;
}
$checklistEstado = $checklistEstado ?? ['pre_viagem' => false, 'recolha' => false, 'entrega' => false];
$checklistDefs = checklist_definicoes();
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Em Viagem — TrackMoz</title>
    <?php include_once __DIR__ . '/../../includes/pwa-head.php'; ?>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; overflow: hidden; background: #f8fafc; font-family: system-ui, sans-serif; }

        /* Mapa: ocupa tudo */
        #map { position: fixed; inset: 0; z-index: 1; }

        /* Barra superior */
        .top-bar {
            position: fixed; top: 0; left: 0; right: 0; z-index: 20;
            background: rgba(255,255,255,.95); backdrop-filter: blur(8px);
            padding: 10px 16px;
            display: flex; align-items: center; gap: 12px;
            border-bottom: 1px solid #e2e8f0;
            box-shadow: 0 1px 8px rgba(15,23,42,.06);
        }
        .top-bar .dest-info { flex: 1; min-width: 0; }
        .top-bar .dest-name {
            font-size: .85rem; color: #64748b;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .top-bar .dist-time {
            font-size: 1.05rem; font-weight: 700; color: #0f172a;
            white-space: nowrap;
        }
        .top-bar .btn-fechar {
            background: #f1f5f9; border: none; border-radius: 50%;
            width: 36px; height: 36px; color: #64748b; font-size: 1.1rem;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; flex-shrink: 0; transition: background .15s;
        }
        .top-bar .btn-fechar:hover { background: #e2e8f0; color: #0f172a; }

        /* Card de próxima instrução */
        .direction-card {
            position: fixed; top: 62px; left: 12px; right: 12px; z-index: 20;
            background: #fff; border-radius: 14px;
            box-shadow: 0 4px 20px rgba(15,23,42,.12);
            display: flex; align-items: center; gap: 14px;
            padding: 12px 16px;
            border: 1px solid #e2e8f0;
        }
        .direction-card .dir-icon {
            font-size: 2.2rem; color: #0d6efd; flex-shrink: 0; width: 48px; text-align: center;
        }
        .direction-card .dir-text {
            font-size: 1rem; font-weight: 700; color: #111;
        }
        .direction-card .dir-dist {
            font-size: .78rem; color: #888; margin-top: 2px;
        }

        /* GPS status pill */
        .gps-pill {
            position: fixed; bottom: 100px; left: 50%; transform: translateX(-50%);
            z-index: 20;
            background: rgba(255,255,255,.95); color: #334155; backdrop-filter: blur(6px);
            border-radius: 30px; padding: 6px 16px; font-size: .78rem;
            display: flex; align-items: center; gap: 6px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 12px rgba(15,23,42,.08);
        }
        .gps-dot { width: 8px; height: 8px; border-radius: 50%; background: #aaa; flex-shrink: 0; }
        .gps-dot.ok  { background: #22c55e; box-shadow: 0 0 0 3px rgba(34,197,94,.25); animation: blink 1.4s infinite; }
        .gps-dot.err { background: #ef4444; }
        @keyframes blink {
            0%, 100% { opacity: 1; }
            50%       { opacity: .4; }
        }

        /* Painel inferior */
        .bottom-panel {
            position: fixed; bottom: 0; left: 0; right: 0; z-index: 20;
            background: rgba(255,255,255,.97); backdrop-filter: blur(8px);
            padding: 12px 16px 20px;
            border-top: 1px solid #e2e8f0;
            display: flex; align-items: center; gap: 10px;
            box-shadow: 0 -2px 12px rgba(15,23,42,.06);
        }
        .route-info { flex: 1; min-width: 0; }
        .route-info .orig, .route-info .dest {
            font-size: .75rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .route-info .orig { color: #16a34a; }
        .route-info .dest { color: #dc2626; }

        .btn-emergency {
            background: #dc2626; border: none; border-radius: 50%;
            width: 54px; height: 54px; color: #fff; font-size: 1.4rem;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; flex-shrink: 0; transition: transform .12s, box-shadow .12s;
            box-shadow: 0 0 0 4px rgba(220,38,38,.3);
            animation: pulsered 2s infinite;
        }
        @keyframes pulsered {
            0%, 100% { box-shadow: 0 0 0 4px rgba(220,38,38,.3); }
            50%       { box-shadow: 0 0 0 10px rgba(220,38,38,.0); }
        }
        .btn-emergency:active { transform: scale(.92); }

        .btn-centrar {
            background: #f1f5f9; border: none; border-radius: 12px;
            padding: 10px 14px; color: #334155; font-size: .8rem;
            display: flex; flex-direction: column; align-items: center; gap: 3px;
            cursor: pointer; flex-shrink: 0; transition: background .15s;
        }
        .btn-centrar:hover { background: #e2e8f0; }
        .btn-centrar i { font-size: 1.2rem; }

        /* Accuracy indicator */
        .acc-badge {
            position: fixed; top: 150px; right: 12px; z-index: 20;
            background: rgba(255,255,255,.9); border-radius: 8px; padding: 4px 8px;
            font-size: .65rem; color: #64748b;
            border: 1px solid #e2e8f0;
            display: none;
        }
        .status-panel {
            position: fixed; bottom: 88px; left: 12px; right: 12px; z-index: 25;
            display: flex; flex-wrap: wrap; gap: 6px; justify-content: center;
        }
        .status-panel .btn-status {
            background: rgba(255,255,255,.95); border: 1px solid #e2e8f0;
            color: #334155; border-radius: 20px; padding: 6px 12px; font-size: .72rem;
            cursor: pointer; backdrop-filter: blur(6px);
            box-shadow: 0 1px 6px rgba(15,23,42,.06);
        }
        .status-panel .btn-status:active { transform: scale(.96); background: #eff6ff; }
        .status-panel .btn-status.active-drive {
            background: #ecfdf5; border-color: #22c55e; color: #15803d;
        }
        .status-panel .btn-status.btn-leg1 { background: #ecfdf5; border-color: #22c55e; }
        .status-panel .btn-status.btn-leg2 { background: #eff6ff; border-color: #3b82f6; }
        .status-panel .btn-status.paused-drive {
            background: #fefce8; border-color: #eab308; color: #a16207;
        }
        .route-msg {
            position: fixed; top: 118px; left: 12px; right: 12px; z-index: 19;
            background: rgba(234,179,8,.92); color: #422006; border-radius: 10px;
            padding: 8px 12px; font-size: .75rem; display: none; text-align: center;
        }
        .timer-conducao {
            position: fixed; top: 62px; right: 12px; z-index: 20;
            background: rgba(255,255,255,.95); color: #16a34a; backdrop-filter: blur(6px);
            border-radius: 10px; padding: 6px 12px; font-size: .85rem; font-variant-numeric: tabular-nums;
            border: 1px solid #bbf7d0; display: none;
            box-shadow: 0 2px 8px rgba(15,23,42,.06);
        }
        .timer-conducao.pausado { color: #a16207; border-color: #fde047; }

        /* Modal de emergência */
        .modal-overlay {
            position: fixed; inset: 0; z-index: 100;
            background: rgba(15,23,42,.4); backdrop-filter: blur(4px);
            display: none; align-items: flex-end; justify-content: center;
        }
        .modal-overlay.active { display: flex; }
        .modal-content {
            background: #fff; color: #0f172a; width: 100%; max-width: 520px;
            border-radius: 20px 20px 0 0; padding: 20px;
            max-height: 92vh; overflow-y: auto;
            animation: slideUp .25s ease-out;
            box-shadow: 0 -8px 32px rgba(15,23,42,.12);
        }
        @keyframes slideUp { from { transform: translateY(100%); } to { transform: translateY(0); } }
        .modal-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
        .modal-head h5 { margin: 0; font-size: 1.1rem; }
        .modal-close {
            background: #f1f5f9; border: none; border-radius: 50%;
            width: 32px; height: 32px; color: #64748b; font-size: 1rem; cursor: pointer;
        }
        .form-group { margin-bottom: 14px; }
        .form-group label { display: block; font-size: .78rem; color: #64748b; margin-bottom: 5px; }
        .form-group select, .form-group textarea,
        .form-group input[type="text"], .form-group input[type="tel"],
        .form-group input[type="number"], .form-group input:not([type]) {
            width: 100%; background: #f8fafc; border: 1px solid #e2e8f0;
            border-radius: 10px; padding: 10px 12px; color: #0f172a; font-size: .9rem;
            outline: none;
        }
        .form-group select:focus, .form-group textarea:focus,
        .form-group input:focus { border-color: #2563eb; }
        .otp-input {
            letter-spacing: .45em; font-size: 1.6rem !important; font-weight: 800;
            text-align: center; padding: 14px 12px !important;
        }
        .btn-otp-ok {
            width: 100%; background: #16a34a; border: none; border-radius: 12px;
            padding: 14px; color: #fff; font-weight: 700; font-size: 1rem; cursor: pointer;
            display: flex; align-items: center; justify-content: center; gap: 8px;
        }
        .btn-otp-ok:disabled { opacity: .5; cursor: not-allowed; }
        #otpMsg .alert { border-radius: 10px; padding: 10px 12px; font-size: .85rem; margin-top: 10px; }
        #otpMsg .alert-danger { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
        #otpMsg .alert-success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
        #otpMsg .alert-info { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }
        .gravidade-opt { display: flex; gap: 6px; flex-wrap: wrap; }
        .gravidade-opt label {
            flex: 1; min-width: 70px; text-align: center; padding: 8px;
            border-radius: 10px; border: 1px solid #e2e8f0; cursor: pointer; font-size: .78rem;
            background: #f8fafc; color: #64748b;
        }
        .gravidade-opt input { display: none; }
        .gravidade-opt input:checked + label { border-color: #dc2626; background: #fef2f2; color: #dc2626; }
        .btn-enviar-alert {
            width: 100%; background: #dc2626; border: none; border-radius: 12px;
            padding: 14px; color: #fff; font-weight: 700; font-size: 1rem; cursor: pointer;
            display: flex; align-items: center; justify-content: center; gap: 8px;
        }
        .btn-enviar-alert:disabled { opacity: .5; cursor: not-allowed; }
        .file-preview { font-size: .78rem; color: #64748b; margin-top: 4px; }
        .checklist-item {
            display: flex; align-items: flex-start; gap: 10px;
            padding: 10px 0; border-bottom: 1px solid #e2e8f0;
            font-size: .88rem; color: #334155;
        }
        .checklist-item input { margin-top: 3px; accent-color: #2563eb; }
    </style>
</head>
<body>

<!-- Barra superior -->
<div class="top-bar">
    <button class="btn-fechar" onclick="confirmarSair()">
        <i class="bi bi-x-lg"></i>
    </button>
    <div class="dest-info">
        <div class="dest-name" id="topDest">→ <?php echo htmlspecialchars($missao['destino']); ?></div>
        <div class="dist-time" id="topDistTime">A calcular rota...</div>
    </div>
</div>

<!-- Card de próxima instrução -->
<div class="route-msg" id="routeMsg"></div>
<div class="direction-card" id="dirCard">
    <div class="dir-icon"><i class="bi bi-arrow-up-circle-fill" id="dirIcon"></i></div>
    <div>
        <div class="dir-text" id="dirText">A aguardar GPS...</div>
        <div class="dir-dist" id="dirDist"></div>
    </div>
</div>

<!-- Mapa -->
<div id="map"></div>

<!-- Precisão -->
<div class="acc-badge" id="accBadge"></div>

<!-- Indicador GPS -->
<div class="gps-pill">
    <div class="gps-dot" id="gpsDot"></div>
    <span id="gpsLabel">A obter GPS...</span>
</div>

<!-- Timer de condução -->
<div class="timer-conducao" id="timerConducao">⏱ 00:00:00</div>

<!-- Acções de estado da viagem -->
<div class="status-panel" id="statusPanel">
    <button type="button" class="btn-status" id="btnIniciar" onclick="accaoConducao('iniciar')"><i class="bi bi-play-fill"></i> Iniciar Condução</button>
    <button type="button" class="btn-status" id="btnPausar" onclick="accaoConducao('pausar')" style="display:none"><i class="bi bi-pause-fill"></i> Pausar Condução</button>
    <button type="button" class="btn-status" id="btnRetomar" onclick="accaoConducao('retomar')" style="display:none"><i class="bi bi-play-fill"></i> Retomar Condução</button>
    <button type="button" class="btn-status btn-leg1" id="btnChegarRecolha" onclick="accaoViagem('chegou_origem')" style="display:none"><i class="bi bi-geo"></i> Cheguei ao ponto de recolha</button>
    <button type="button" class="btn-status btn-leg1" id="btnConfirmarRecolha" onclick="accaoViagem('recolheu')" style="display:none"><i class="bi bi-box-seam"></i> Confirmar recolha da carga</button>
    <button type="button" class="btn-status btn-leg2" id="btnIniciarDestino" onclick="accaoViagem('atualizar','entrega')" style="display:none"><i class="bi bi-signpost-split"></i> Iniciar viagem para destino</button>
    <button type="button" class="btn-status btn-leg2" id="btnChegarDestino" onclick="accaoViagem('chegada_destino')" style="display:none"><i class="bi bi-flag"></i> Cheguei ao Destino</button>
    <button type="button" class="btn-status" id="btnConfirmarEntrega" onclick="iniciarConfirmacaoOtp()" style="display:none"><i class="bi bi-key"></i> Confirmar entrega (OTP)</button>
    <button type="button" class="btn-status" id="btnInserirOtp" onclick="abrirModalOtp()" style="display:none"><i class="bi bi-shield-lock"></i> Inserir código OTP</button>
    <button type="button" class="btn-status" id="btnConcluir" onclick="accaoConducao('concluir')" style="display:none"><i class="bi bi-check-lg"></i> Concluir Condução</button>
    <button type="button" class="btn-status" id="btnOrigemManual" onclick="definirOrigemManual()" style="display:none"><i class="bi bi-pin-map"></i> Definir origem manual</button>
</div>

<!-- Painel inferior -->
<div class="bottom-panel">
    <div class="route-info">
        <div class="orig"><i class="bi bi-circle me-1"></i><?php echo htmlspecialchars($missao['origem']); ?></div>
        <div class="dest"><i class="bi bi-geo-alt-fill me-1"></i><?php echo htmlspecialchars($missao['destino']); ?></div>
    </div>
    <button class="btn-centrar" id="btnCentrar" onclick="centrarNoMotorista()">
        <i class="bi bi-crosshair2"></i>
        <span>Centrar</span>
    </button>
    <button class="btn-emergency" onclick="reportarEmergencia()" title="Emergência">
        <i class="bi bi-exclamation-triangle-fill"></i>
    </button>
</div>

<!-- Modal Checklist operacional -->
<div class="modal-overlay" id="modalChecklist">
    <div class="modal-content">
        <div class="modal-head">
            <h5 id="checklistTitulo"><i class="bi bi-list-check"></i> Checklist</h5>
            <button class="modal-close" onclick="fecharChecklist()">&times;</button>
        </div>
        <form id="formChecklist">
            <div id="checklistItems"></div>
            <button type="submit" class="btn-enviar-alert" style="background:#2563eb;margin-top:12px">
                <i class="bi bi-check2-circle"></i> Confirmar checklist
            </button>
        </form>
    </div>
</div>

<!-- Modal OTP de confirmação de entrega -->
<div class="modal-overlay" id="modalOtp">
    <div class="modal-content">
        <div class="modal-head">
            <h5><i class="bi bi-key" style="color:#16a34a"></i> Código OTP de entrega</h5>
            <button class="modal-close" type="button" onclick="fecharModalOtp()">&times;</button>
        </div>
        <p style="font-size:.82rem;color:#64748b;margin:0 0 12px">
            Peça ao <strong>destinatário</strong> o código de 6 dígitos enviado pela empresa (SMS/WhatsApp) e digite-o abaixo.
        </p>
        <form id="formOtpEntrega">
            <div class="form-group">
                <label>Código OTP (6 dígitos) *</label>
                <input type="text" name="otp" id="otpCodigoInput" class="otp-input" maxlength="6"
                       inputmode="numeric" pattern="[0-9]*" placeholder="______" required autocomplete="one-time-code">
            </div>
            <div class="form-group">
                <label>Nome de quem recebeu *</label>
                <input type="text" name="nome_recebedor" id="otpNome" placeholder="Nome completo" required>
            </div>
            <div class="form-group">
                <label>Telefone de quem recebeu *</label>
                <input type="tel" name="telefone_recebedor" id="otpTelefone" placeholder="Ex: 84xxxxxxx" required>
            </div>
            <div class="form-group">
                <label>Documento (opcional)</label>
                <input type="text" name="documento_recebedor" id="otpDoc" placeholder="BI / NUIT">
            </div>
            <div class="form-group">
                <label>Estado da carga</label>
                <select name="estado_carga" id="otpEstado">
                    <option value="sem_danos">Recebida sem danos</option>
                    <option value="com_danos">Recebida com danos</option>
                    <option value="parcial">Recebida parcialmente</option>
                    <option value="recusada">Recusada</option>
                </select>
            </div>
            <div class="form-group">
                <label>Foto da carga entregue *</label>
                <input type="file" name="foto_carga" id="otpFoto" accept="image/*" capture="environment" required>
            </div>
            <div class="form-group">
                <label>Observações</label>
                <textarea name="observacoes" id="otpObs" rows="2" placeholder="Opcional"></textarea>
            </div>
            <button type="submit" class="btn-otp-ok" id="btnOtpSubmit">
                <i class="bi bi-check-lg"></i> Confirmar entrega
            </button>
        </form>
        <div id="otpMsg"></div>
        <p style="font-size:.75rem;color:#94a3b8;margin:12px 0 0;text-align:center">
            <a href="#" id="otpLinkCompleto" style="color:#2563eb">Abrir formulário completo</a>
        </p>
    </div>
</div>

<!-- Modal de Emergência -->
<div class="modal-overlay" id="modalEmergencia">
    <div class="modal-content">
        <div class="modal-head">
            <h5><i class="bi bi-exclamation-triangle-fill" style="color:#dc2626"></i> Solicitar Emergência</h5>
            <button class="modal-close" onclick="fecharModalEmergencia()">&times;</button>
        </div>
        <form id="formEmergencia" enctype="multipart/form-data">
            <div class="form-group">
                <label>Tipo de emergência</label>
                <select name="tipo" id="emgTipo" required>
                    <option value="">Selecione...</option>
                    <option value="acidente">Acidente</option>
                    <option value="avaria">Avaria mecânica</option>
                    <option value="furo">Furo / pneu</option>
                    <option value="problema_carga">Problema com a carga</option>
                    <option value="roubo">Roubo / assalto</option>
                    <option value="saude">Problema de saúde</option>
                    <option value="fiscalizacao">Fiscalização / autoridade</option>
                    <option value="atraso_grave">Atraso grave</option>
                    <option value="outro">Outro</option>
                </select>
            </div>
            <div class="form-group">
                <label>Descrição da emergência *</label>
                <textarea name="descricao" id="emgDescricao" rows="3" placeholder="Descreva o que aconteceu..." required></textarea>
            </div>
            <div class="form-group">
                <label>Nível de gravidade</label>
                <div class="gravidade-opt">
                    <input type="radio" name="gravidade" id="grav_baixa" value="baixa" checked><label for="grav_baixa">Baixa</label>
                    <input type="radio" name="gravidade" id="grav_media" value="media"><label for="grav_media">Média</label>
                    <input type="radio" name="gravidade" id="grav_alta" value="alta"><label for="grav_alta">Alta</label>
                    <input type="radio" name="gravidade" id="grav_critica" value="critica"><label for="grav_critica">Crítica</label>
                </div>
            </div>
            <div class="form-group">
                <label>Anexar foto / vídeo / documento</label>
                <input type="file" name="anexo" id="emgAnexo" accept="image/*,video/*,.pdf,.doc,.docx" style="color:#fff;font-size:.8rem">
                <div class="file-preview" id="emgFilePreview"></div>
            </div>
            <input type="hidden" name="missao_id" value="<?php echo $missao_id; ?>">
            <input type="hidden" name="latitude" id="emgLat">
            <input type="hidden" name="longitude" id="emgLng">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <button type="submit" class="btn-enviar-alert" id="btnEnviarAlerta">
                <i class="bi bi-send-fill"></i> Enviar alerta de emergência
            </button>
        </form>
    </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="<?php echo BASE_URL; ?>/assets/js/offline-sync.js?v=1"></script>
<script src="<?php echo BASE_URL; ?>/assets/js/mapa-core.js"></script>
<script src="<?php echo BASE_URL; ?>/assets/js/gps-tracker.js?v=2"></script>
<script>
const BASE_URL   = <?php echo json_encode(BASE_URL); ?>;
const MISSAO_ID  = <?php echo json_encode($missao_id); ?>;
let ORIGEM_LAT = <?php echo json_encode($missao['origem_lat']  ? (float)$missao['origem_lat']  : null); ?>;
let ORIGEM_LNG = <?php echo json_encode($missao['origem_lng']  ? (float)$missao['origem_lng']  : null); ?>;
let DEST_LAT   = <?php echo json_encode($missao['destino_lat'] ? (float)$missao['destino_lat'] : null); ?>;
let DEST_LNG   = <?php echo json_encode($missao['destino_lng'] ? (float)$missao['destino_lng'] : null); ?>;

// Estado de condução persistente
const MODO_ATIVO        = <?php echo json_encode((bool)($missao['modo_conducao_ativo'] ?? false)); ?>;
const TEMPO_ACUMULADO   = <?php echo json_encode((int)($missao['tempo_conducao_acumulado_seg'] ?? 0)); ?>;
let STATUS_MISSAO     = <?php echo json_encode($missao['status']); ?>;
let STATUS_VIAGEM       = <?php echo json_encode($missao['status_viagem'] ?? 'nao_iniciada'); ?>;
const CSRF_TOKEN        = <?php echo json_encode(csrf_token()); ?>;
const ORIGEM_NOME       = <?php echo json_encode($missao['origem']); ?>;
const DESTINO_NOME      = <?php echo json_encode($missao['destino']); ?>;
const CHECKLIST_ESTADO  = <?php echo json_encode($checklistEstado); ?>;
const CHECKLIST_DEFS    = <?php echo json_encode($checklistDefs); ?>;
let checklistPendente   = null;
let checklistCallback   = null;

const MISSAO_SNAPSHOT = {
    missaoId: MISSAO_ID,
    origem: ORIGEM_NOME,
    destino: DESTINO_NOME,
    origem_lat: ORIGEM_LAT,
    origem_lng: ORIGEM_LNG,
    destino_lat: DEST_LAT,
    destino_lng: DEST_LNG,
    status: STATUS_MISSAO,
    status_viagem: STATUS_VIAGEM,
    modo_conducao_ativo: MODO_ATIVO,
    tempo_conducao_acumulado_seg: TEMPO_ACUMULADO,
    checklist: CHECKLIST_ESTADO,
};

function optimisticStatus(acao, etapa) {
    const map = {
        iniciar: { status: 'em_andamento', status_viagem: 'a_caminho_recolha', message: 'Viagem iniciada (offline)' },
        chegou_origem: { status: 'em_andamento', status_viagem: 'aguardando_recolha', message: 'Chegada à recolha (offline)' },
        recolheu: { status: 'em_transito', status_viagem: 'carga_recolhida', message: 'Recolha confirmada (offline)' },
        chegada_destino: { status: 'em_entrega', status_viagem: 'entrega', message: 'Chegada ao destino (offline)' },
        concluir: { status: 'concluida', status_viagem: 'finalizada', message: 'Missão concluída (offline)' },
    };
    if (acao === 'atualizar' && etapa === 'entrega') {
        return { status: 'em_transito', status_viagem: 'em_transito', message: 'A caminho do destino (offline)' };
    }
    return map[acao] || null;
}

function aplicarEstadoViagem(status, statusViagem, mensagem) {
    if (status) STATUS_MISSAO = status;
    if (statusViagem) STATUS_VIAGEM = statusViagem;
    const gpsLabelEl = document.getElementById('gpsLabel');
    if (gpsLabelEl && mensagem) {
        const prev = gpsLabelEl.textContent;
        gpsLabelEl.textContent = '✓ ' + mensagem;
        setTimeout(() => { gpsLabelEl.textContent = prev; }, 3000);
    }
    if (typeof lastRouteKey !== 'undefined') lastRouteKey = '';
    if (typeof desenharRotaAtiva === 'function') desenharRotaAtiva(true);
    if (typeof actualizarBotoesConducao === 'function') actualizarBotoesConducao();
    guardarCacheMissao();
}

function guardarCacheMissao() {
    if (!window.TrackMozOffline) return;
    TrackMozOffline.cacheMission(MISSAO_ID, Object.assign({}, MISSAO_SNAPSHOT, {
        status: STATUS_MISSAO,
        status_viagem: STATUS_VIAGEM,
        origem_lat: ORIGEM_LAT,
        origem_lng: ORIGEM_LNG,
        destino_lat: DEST_LAT,
        destino_lng: DEST_LNG,
        checklist: CHECKLIST_ESTADO,
    }));
}

async function restaurarCacheMissao() {
    if (!window.TrackMozOffline) return;
    try {
        const cached = await TrackMozOffline.getCachedMission(MISSAO_ID);
        if (!cached) return;
        if (cached.status) STATUS_MISSAO = cached.status;
        if (cached.status_viagem) STATUS_VIAGEM = cached.status_viagem;
        if (cached.origem_lat != null) ORIGEM_LAT = cached.origem_lat;
        if (cached.origem_lng != null) ORIGEM_LNG = cached.origem_lng;
        if (cached.destino_lat != null) DEST_LAT = cached.destino_lat;
        if (cached.destino_lng != null) DEST_LNG = cached.destino_lng;
        if (cached.checklist && typeof cached.checklist === 'object') {
            Object.assign(CHECKLIST_ESTADO, cached.checklist);
        }
        const topDest = document.getElementById('topDest');
        if (topDest && cached.destino) topDest.textContent = '→ ' + cached.destino;
    } catch (_) { /* ignore */ }
}

if (window.TrackMozOffline) {
    guardarCacheMissao();
    TrackMozOffline.onChange(function () { /* banner gerido pelo offline-sync */ });
}

// ── Mapa (Moçambique) ─────────────────────────────────────────
const MZ_BOUNDS = [[-27.5, 30.0], [-10.0, 41.0]];
function dentroMocambique(lat, lng) {
    return lat >= -27.5 && lat <= -10.0 && lng >= 30.0 && lng <= 41.0;
}

const centroInicial = ORIGEM_LAT ? [ORIGEM_LAT, ORIGEM_LNG] : [-18.0, 35.0];
const map = L.map('map', { zoomControl: false, attributionControl: false, maxBounds: MZ_BOUNDS, maxBoundsViscosity: 0.85 })
              .setView(centroInicial, ORIGEM_LAT ? 13 : 6);

L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap',
}).addTo(map);

const dotIcon = (cor) => L.divIcon({
    html: `<div style="width:14px;height:14px;border-radius:50%;background:${cor};
                border:3px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.5)"></div>`,
    className: '', iconAnchor: [7, 7],
});
const truckIcon = L.divIcon({
    html: '<div style="font-size:28px;line-height:1;filter:drop-shadow(0 2px 4px rgba(0,0,0,.8))">🚛</div>',
    className: '', iconAnchor: [14, 22],
});

if (ORIGEM_LAT) {
    L.marker([ORIGEM_LAT, ORIGEM_LNG], { icon: dotIcon('#22c55e') })
     .addTo(map).bindPopup('<b>Recolha</b><br>' + ORIGEM_NOME);
}
if (DEST_LAT) {
    L.marker([DEST_LAT, DEST_LNG], { icon: dotIcon('#ef4444') })
     .addTo(map).bindPopup('<b>Destino</b><br>' + DESTINO_NOME);
}

let routeLayer      = null;
let lastRouteKey    = '';
let lastRouteCalc   = 0;
let manualPos       = null;

function etapaRecolha() {
    return ['', null, 'nao_iniciada', 'a_caminho_recolha'].includes(STATUS_VIAGEM);
}
function etapaAguardandoRecolha() {
    return STATUS_VIAGEM === 'aguardando_recolha';
}
function etapaDestino() {
    return ['carga_recolhida', 'em_transito', 'coleta'].includes(STATUS_VIAGEM);
}
function etapaEntrega() {
    return ['entrega', 'finalizada'].includes(STATUS_VIAGEM)
        || ['em_entrega', 'aguardando_confirmacao'].includes(STATUS_MISSAO);
}

function posicaoMotorista() {
    if (currentPos) return currentPos;
    if (manualPos) return manualPos;
    return null;
}

function alvoAtual() {
    if (etapaRecolha()) {
        return { lat: ORIGEM_LAT, lng: ORIGEM_LNG, nome: ORIGEM_NOME, leg: 1, label: 'recolha' };
    }
    return { lat: DEST_LAT, lng: DEST_LNG, nome: DESTINO_NOME, leg: 2, label: 'destino' };
}

function formatarDistTempo(distM, durS) {
    const km  = (distM / 1000).toFixed(1);
    const min = Math.round(durS / 60);
    const h   = Math.floor(min / 60), m = min % 60;
    return km + ' km · ' + (h > 0 ? h + 'h ' : '') + m + 'min';
}

function mostrarAvisoRota(msg) {
    const el = document.getElementById('routeMsg');
    if (!el) return;
    if (msg) { el.textContent = msg; el.style.display = 'block'; }
    else { el.style.display = 'none'; }
}

async function calcularRota(fromLat, fromLng, toLat, toLng, force) {
    if (!fromLat || !fromLng || !toLat || !toLng) return null;
    const key = [fromLat.toFixed(4), fromLng.toFixed(4), toLat.toFixed(4), toLng.toFixed(4)].join('|');
    const now = Date.now();
    if (!force && key === lastRouteKey && (now - lastRouteCalc) < 30000) return null;
    lastRouteKey  = key;
    lastRouteCalc = now;

    try {
        const url = BASE_URL + '/api/route.php?from_lat=' + fromLat + '&from_lng=' + fromLng
                  + '&to_lat=' + toLat + '&to_lng=' + toLng;
        const r = await fetch(url, { credentials: 'same-origin' });
        const d = await r.json();
        if (!d.ok) throw new Error(d.error || 'Rota indisponível');
        return d;
    } catch (e) {
        console.error('route:', e);
        const distM = haversine(fromLat, fromLng, toLat, toLng);
        mostrarAvisoRota('Rota aproximada — serviço de estradas indisponível.');
        return {
            ok: true,
            distancia_m: distM,
            duracao_s: Math.max(60, Math.round((distM / 1000) / 50 * 3600)),
            coordinates: [[fromLat, fromLng], [toLat, toLng]],
            fallback: true,
        };
    }
}

async function desenharRotaAtiva(force) {
    const alvo = alvoAtual();
    if (!alvo.lat || !alvo.lng) return;

    let fromLat, fromLng;
    if (etapaRecolha()) {
        const pos = posicaoMotorista();
        if (!pos) {
            document.getElementById('topDistTime').textContent = 'Aguardando GPS para rota até recolha...';
            document.getElementById('topDest').textContent = '→ ' + alvo.nome + ' (recolha)';
            return;
        }
        fromLat = pos.lat; fromLng = pos.lng;
    } else if (etapaAguardandoRecolha()) {
        document.getElementById('topDistTime').textContent = 'No ponto de recolha — confirme a carga';
        document.getElementById('topDest').textContent = '→ ' + alvo.nome + ' (recolha)';
        if (routeLayer) { map.removeLayer(routeLayer); routeLayer = null; }
        return;
    } else {
        const pos = posicaoMotorista();
        fromLat = pos ? pos.lat : (ORIGEM_LAT || alvo.lat);
        fromLng = pos ? pos.lng : (ORIGEM_LNG || alvo.lng);
    }

    document.getElementById('topDest').textContent =
        (alvo.leg === 1 ? '→ Recolha: ' : '→ Destino: ') + alvo.nome;

    const rota = await calcularRota(fromLat, fromLng, alvo.lat, alvo.lng, force);
    if (!rota) return;

    if (routeLayer) map.removeLayer(routeLayer);
    const dash = rota.fallback ? '8,8' : null;
    routeLayer = L.polyline(rota.coordinates, {
        color: alvo.leg === 1 ? '#22c55e' : '#3b82f6',
        weight: 5, opacity: .75, dashArray: dash
    }).addTo(map);

    document.getElementById('topDistTime').textContent = formatarDistTempo(rota.distancia_m, rota.duracao_s);
    if ($rota.fallback) {
        mostrarAvisoRota('Rota aproximada (serviço de estradas indisponível).');
    } else if (rota.via_corredor || rota.nacional) {
        mostrarAvisoRota('Rota nacional via corredores de Moçambique (EN1/EN6).');
    } else if (rota.aviso) {
        mostrarAvisoRota(rota.aviso);
    } else {
        mostrarAvisoRota('');
    }

    try {
        map.fitBounds(routeLayer.getBounds(), { padding: [80, 80], maxZoom: 15 });
    } catch (_) {}
}

function definirOrigemManual() {
    const latStr = prompt('Latitude da sua posição (ex: -25.9655):');
    const lngStr = prompt('Longitude da sua posição (ex: 32.5832):');
    if (!latStr || !lngStr) return;
    const lat = parseFloat(latStr), lng = parseFloat(lngStr);
    if (isNaN(lat) || isNaN(lng)) { alert('Coordenadas inválidas'); return; }
    manualPos = { lat, lng };
    gpsLabel.textContent = 'Origem manual definida';
    gpsDot.className = 'gps-dot ok';
    desenharRotaAtiva(true);
    actualizarBotoesConducao();
}

// ── GPS tracking ─────────────────────────────────────────────
let marcadorTruck    = null;
let polyPercorrida   = null;
let pontos           = [];
let followMode       = true;
let lastServerSend   = 0;
let watchId          = null;
let currentPos       = null;

const gpsDot   = document.getElementById('gpsDot');
const gpsLabel = document.getElementById('gpsLabel');
const accBadge = document.getElementById('accBadge');

function onPosicao(pos) {
    const lat = pos.coords.latitude;
    const lng = pos.coords.longitude;
    const acc = Math.round(pos.coords.accuracy);

    currentPos = { lat, lng };
    if (!dentroMocambique(lat, lng)) {
        gpsDot.className = 'gps-dot err';
        gpsLabel.textContent = 'GPS fora de Moçambique — use origem manual ou active localização real';
        document.getElementById('btnOrigemManual').style.display = '';
        mostrarAvisoRota('A sua posição GPS parece estar fora de Moçambique. Defina a origem manualmente.');
        return;
    }
    gpsDot.className   = 'gps-dot ok';
    gpsLabel.textContent = 'GPS activo · ±' + acc + 'm';
    accBadge.textContent = '±' + acc + 'm';
    accBadge.style.display = 'block';

    // Marcador do caminhão
    if (!marcadorTruck) {
        marcadorTruck = L.marker([lat, lng], { icon: truckIcon, zIndexOffset: 1000 })
                         .addTo(map).bindPopup('A minha posição');
    } else {
        marcadorTruck.setLatLng([lat, lng]);
    }

    // Rasto percorrido (linha verde)
    pontos.push([lat, lng]);
    if (polyPercorrida) map.removeLayer(polyPercorrida);
    if (pontos.length > 1) {
        polyPercorrida = L.polyline(pontos, { color:'#4ade80', weight:4, opacity:.75 }).addTo(map);
    }

    // Centrar no caminhão se modo seguir activo
    if (followMode) map.setView([lat, lng], Math.max(map.getZoom(), 15));

    // Instrução direccional até alvo da etapa actual
    const alvo = alvoAtual();
    if (alvo.lat) {
        const bearing = calcBearing(lat, lng, alvo.lat, alvo.lng);
        const dist    = haversine(lat, lng, alvo.lat, alvo.lng);
        setDirCard(bearing, dist, alvo.label);
    }

    desenharRotaAtiva(false);
}

function onErroGPS(err) {
    gpsDot.className   = 'gps-dot err';
    const msgs = {
        1: 'GPS bloqueado — permite o acesso à localização',
        2: 'Sinal GPS indisponível',
        3: 'GPS: tempo limite esgotado',
    };
    gpsLabel.textContent = msgs[err.code] || 'Erro GPS';
    document.getElementById('btnOrigemManual').style.display = '';
}

function iniciarGPS() {
    if (!navigator.geolocation) {
        gpsLabel.textContent = 'Este browser não suporta GPS';
        return;
    }
    watchId = navigator.geolocation.watchPosition(onPosicao, onErroGPS, {
        enableHighAccuracy: true,
        timeout: 15000,
        maximumAge: 5000,
    });
}

// ── Envio da posição ao servidor (TMS) ───────────────────────
function enviarPosicao(lat, lng, speed, heading) {
    const form = new FormData();
    form.append('latitude',  lat);
    form.append('longitude', lng);
    form.append('missao_id', MISSAO_ID);
    if (speed != null) form.append('speed', +(speed * 3.6).toFixed(1));
    if (heading != null) form.append('heading', +heading.toFixed(1));
    fetch(BASE_URL + '/api/update-localizacao.php', { method:'POST', body: form })
        .then(r => r.json())
        .then(d => {
            if (d.checkpoint) {
                const labels = {
                    chegou_recolha: 'Chegou ao local de recolha',
                    carga_recolhida: 'Carga recolhida',
                    chegou_destino: 'Chegou ao destino',
                };
                if (labels[d.checkpoint.tipo]) {
                    document.getElementById('dirText').textContent = '✓ ' + labels[d.checkpoint.tipo];
                }
            }
        })
        .catch(() => {});
}

let gpsTracker = null;
function iniciarGpsTracker() {
    if (typeof TrackMozGpsTracker === 'undefined') return;
    gpsTracker = new TrackMozGpsTracker({
        baseUrl: BASE_URL,
        missaoId: MISSAO_ID,
        intervalMs: 5000,
        onPosition(pos) {
            onPosicao({ coords: { latitude: pos.lat, longitude: pos.lng, speed: pos.speed, heading: pos.heading } });
        },
        onError: onErroGPS,
        onCheckpoint(cp) {
            const labels = { chegou_recolha: 'Chegou à recolha', carga_recolhida: 'Carga recolhida', chegou_destino: 'Chegou ao destino' };
            if (labels[cp.tipo]) document.getElementById('dirText').textContent = '✓ ' + labels[cp.tipo];
        },
        onOffline(info) {
            gpsDot.className = 'gps-dot err';
            gpsLabel.textContent = 'GPS offline há ' + info.segundos + 's';
        },
    });
    gpsTracker.start();
}

// ── Navegação direccional simples ─────────────────────────────
function calcBearing(lat1, lon1, lat2, lon2) {
    const toR = d => d * Math.PI / 180;
    const dLon = toR(lon2 - lon1);
    const y = Math.sin(dLon) * Math.cos(toR(lat2));
    const x = Math.cos(toR(lat1)) * Math.sin(toR(lat2))
            - Math.sin(toR(lat1)) * Math.cos(toR(lat2)) * Math.cos(dLon);
    return ((Math.atan2(y, x) * 180 / Math.PI) + 360) % 360;
}

function haversine(lat1, lon1, lat2, lon2) {
    const R = 6371000;
    const toR = d => d * Math.PI / 180;
    const dLat = toR(lat2 - lat1), dLon = toR(lon2 - lon1);
    const a = Math.sin(dLat/2)**2
            + Math.cos(toR(lat1))*Math.cos(toR(lat2))*Math.sin(dLon/2)**2;
    return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
}

function setDirCard(bearing, distMetros, alvoLabel) {
    const icon = document.getElementById('dirIcon');
    const text = document.getElementById('dirText');
    const dist = document.getElementById('dirDist');
    const destinoTxt = alvoLabel === 'recolha' ? 'ponto de recolha' : 'destino';

    const seg  = Math.round(bearing / 45) % 8;
    const dirs = [
        ['bi-arrow-up-circle-fill',     'Siga em frente'],
        ['bi-arrow-up-right-circle-fill','Nordeste'],
        ['bi-arrow-right-circle-fill',  'Vire à direita'],
        ['bi-arrow-down-right-circle-fill','Sudeste'],
        ['bi-arrow-down-circle-fill',   'Siga em frente'],
        ['bi-arrow-down-left-circle-fill','Sudoeste'],
        ['bi-arrow-left-circle-fill',   'Vire à esquerda'],
        ['bi-arrow-up-left-circle-fill', 'Noroeste'],
    ];
    icon.className = 'bi ' + dirs[seg][0];
    text.textContent = dirs[seg][1];

    if (distMetros < 80) {
        text.textContent = '🎯 Chegou ao ' + destinoTxt + '!';
        icon.className   = 'bi bi-geo-alt-fill';
    }

    const distTxt = distMetros >= 1000
        ? (distMetros/1000).toFixed(1) + ' km até ao ' + destinoTxt
        : Math.round(distMetros) + ' m até ao ' + destinoTxt;
    dist.textContent = distTxt;
}

// ── Botão centrar ─────────────────────────────────────────────
function centrarNoMotorista() {
    followMode = true;
    if (currentPos) map.setView([currentPos.lat, currentPos.lng], 16);
}

map.on('dragstart', () => { followMode = false; });

// ── Emergência ────────────────────────────────────────────────
const modalEmergencia = document.getElementById('modalEmergencia');
const formEmergencia  = document.getElementById('formEmergencia');
const emgAnexo      = document.getElementById('emgAnexo');
const emgPreview    = document.getElementById('emgFilePreview');

function reportarEmergencia() {
    // Preencher localização actual
    if (currentPos) {
        document.getElementById('emgLat').value = currentPos.lat;
        document.getElementById('emgLng').value = currentPos.lng;
    }
    modalEmergencia.classList.add('active');
}
function fecharModalEmergencia() {
    modalEmergencia.classList.remove('active');
    formEmergencia.reset();
    emgPreview.textContent = '';
}

emgAnexo.addEventListener('change', () => {
    emgPreview.textContent = emgAnexo.files[0] ? emgAnexo.files[0].name : '';
});

formEmergencia.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = document.getElementById('btnEnviarAlerta');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> A enviar...';

    const form = new FormData(formEmergencia);
    // Garantir latitude/longitude actual
    if (currentPos) {
        form.set('latitude', currentPos.lat);
        form.set('longitude', currentPos.lng);
    }

    try {
        const r = await fetch(BASE_URL + '/api/emergencia-create.php', { method:'POST', body: form });
        const d = await r.json();
        if (d.ok) {
            alert('✅ ' + d.message);
            fecharModalEmergencia();
        } else {
            alert('❌ ' + (d.error || 'Erro ao reportar'));
        }
    } catch(e) {
        alert('❌ Sem ligação. Verifique a rede e tente novamente.\nEm emergência real, ligue: 840 000 000');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-send-fill"></i> Enviar alerta de emergência';
    }
});

// ── Sair ──────────────────────────────────────────────────────
function confirmarSair() {
    if (confirm('Sair do modo de condução?\nA missão continua activa.')) {
        if (watchId !== null) navigator.geolocation.clearWatch(watchId);
        if (gpsTracker) gpsTracker.stop();
        window.location.href = BASE_URL + '/pages/caminhoneiro/detalhes-missao.php?id=' + MISSAO_ID;
    }
}

// ── Timer de condução persistente ─────────────────────────────
let tempoAcumuladoSeg = TEMPO_ACUMULADO;
let modoConducaoAtivo = MODO_ATIVO;
let timerInterval     = null;
let timerStart          = null;

function formatarTempo(segundos) {
    const h = Math.floor(segundos / 3600);
    const m = Math.floor((segundos % 3600) / 60);
    const s = segundos % 60;
    return String(h).padStart(2,'0') + ':' + String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
}

function atualizarTimerDisplay() {
    const el = document.getElementById('timerConducao');
    let total = tempoAcumuladoSeg;
    if (modoConducaoAtivo && timerStart) {
        total += Math.floor((Date.now() - timerStart) / 1000);
    }
    el.textContent = '⏱ ' + formatarTempo(total);
    el.style.display = (total > 0 || modoConducaoAtivo) ? 'block' : 'none';
    el.classList.toggle('pausado', !modoConducaoAtivo);
}

function iniciarTimer() {
    if (timerInterval) clearInterval(timerInterval);
    timerStart = Date.now();
    timerInterval = setInterval(atualizarTimerDisplay, 1000);
}
function pararTimer() {
    if (timerInterval) { clearInterval(timerInterval); timerInterval = null; }
    if (timerStart) {
        tempoAcumuladoSeg += Math.floor((Date.now() - timerStart) / 1000);
        timerStart = null;
    }
}

function actualizarBotoesConducao() {
    const btnIniciar         = document.getElementById('btnIniciar');
    const btnPausar          = document.getElementById('btnPausar');
    const btnRetomar         = document.getElementById('btnRetomar');
    const btnConcluir        = document.getElementById('btnConcluir');
    const btnChegarRecolha   = document.getElementById('btnChegarRecolha');
    const btnConfirmarRecolha= document.getElementById('btnConfirmarRecolha');
    const btnIniciarDestino  = document.getElementById('btnIniciarDestino');
    const btnDestino         = document.getElementById('btnChegarDestino');
    const btnConfirmarEntrega= document.getElementById('btnConfirmarEntrega');
    const btnInserirOtp      = document.getElementById('btnInserirOtp');
    const btnOrigemManual    = document.getElementById('btnOrigemManual');

    if (!btnIniciar) return;

    [btnIniciar, btnPausar, btnRetomar, btnConcluir, btnChegarRecolha,
     btnConfirmarRecolha, btnIniciarDestino, btnDestino, btnConfirmarEntrega,
     btnInserirOtp, btnOrigemManual].forEach(b => { if (b) b.style.display = 'none'; });

    const statusIniciavel = ['aceita','em_andamento','em_transito','em_entrega','aguardando_confirmacao','emergencia_reportada','emergencia'];
    const emViagem        = modoConducaoAtivo || tempoAcumuladoSeg > 0 || jaIniciouAntes();

    if (modoConducaoAtivo) {
        btnPausar.style.display = '';
    } else if (tempoAcumuladoSeg > 0 || jaIniciouAntes()) {
        btnRetomar.style.display = '';
    } else if (statusIniciavel.includes(STATUS_MISSAO)) {
        btnIniciar.style.display = '';
    }

    if (!posicaoMotorista() && etapaRecolha() && emViagem) {
        btnOrigemManual.style.display = '';
    }

    if (emViagem) {
        if (etapaRecolha()) {
            btnChegarRecolha.style.display = '';
        } else if (etapaAguardandoRecolha()) {
            btnConfirmarRecolha.style.display = '';
        } else if (STATUS_VIAGEM === 'carga_recolhida') {
            btnIniciarDestino.style.display = '';
        } else if (etapaDestino()) {
            btnDestino.style.display = '';
        } else if (STATUS_MISSAO === 'aguardando_confirmacao') {
            if (btnInserirOtp) btnInserirOtp.style.display = '';
        } else if (etapaEntrega()) {
            btnConfirmarEntrega.style.display = '';
        }
    }
}

function jaIniciouAntes() {
    return TEMPO_ACUMULADO > 0 || ['em_andamento','em_transito','em_entrega','aguardando_confirmacao'].includes(STATUS_MISSAO);
}

// ── Checklists operacionais ───────────────────────────────────
function exigirChecklist(fase, callback) {
    if (CHECKLIST_ESTADO[fase]) {
        callback();
        return;
    }
    checklistPendente = fase;
    checklistCallback = callback;
    const def = CHECKLIST_DEFS[fase];
    if (!def) { callback(); return; }
    document.getElementById('checklistTitulo').innerHTML = '<i class="bi bi-list-check"></i> ' + def.titulo;
    const box = document.getElementById('checklistItems');
    box.innerHTML = '';
    Object.entries(def.items).forEach(([key, label]) => {
        box.innerHTML += '<label class="checklist-item"><input type="checkbox" name="items[' + key + ']" value="1"> <span>' + label + '</span></label>';
    });
    document.getElementById('modalChecklist').classList.add('active');
}
function fecharChecklist() {
    document.getElementById('modalChecklist').classList.remove('active');
    checklistPendente = null;
    checklistCallback = null;
}
document.getElementById('formChecklist').addEventListener('submit', async function(e) {
    e.preventDefault();
    if (!checklistPendente || !checklistCallback) return;
    const form = new FormData(this);
    form.append('missao_id', MISSAO_ID);
    form.append('fase', checklistPendente);
    form.append('csrf_token', CSRF_TOKEN);
    const btn = this.querySelector('button[type="submit"]');
    if (btn) { btn.disabled = true; btn.textContent = 'A guardar...'; }
    // Guardar callback ANTES de fecharChecklist() (que o anula)
    const faseAtual = checklistPendente;
    const cb = checklistCallback;
    try {
        const r = await fetch(BASE_URL + '/api/checklist-viagem.php', { method: 'POST', body: form, credentials: 'same-origin' });
        let d;
        try { d = await r.json(); } catch (_) {
            alert('Resposta inválida do servidor. Recarregue a página e tente novamente.');
            return;
        }
        if (!d.success) {
            alert(d.message || d.error || 'Checklist incompleto.');
            return;
        }
        CHECKLIST_ESTADO[faseAtual] = true;
        checklistCallback = null;
        checklistPendente = null;
        document.getElementById('modalChecklist').classList.remove('active');
        if (typeof cb === 'function') {
            await cb();
        }
    } catch(err) {
        console.error('checklist submit:', err);
        alert('Erro ao guardar checklist. ' + (err && err.message ? err.message : 'Verifique a ligação à internet.'));
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check2-circle"></i> Confirmar checklist';
        }
    }
});

// ── API de condução ───────────────────────────────────────────
async function accaoConducao(acao) {
    // Validar operacional antes de iniciar
    if (acao === 'iniciar') {
        exigirChecklist('pre_viagem', () => accaoConducaoExecutar(acao));
        return;
    }
    await accaoConducaoExecutar(acao);
}

async function accaoConducaoExecutar(acao) {
    // Validar operacional antes de iniciar
    if (acao === 'iniciar') {
        try {
            const vForm = new FormData();
            vForm.append('missao_id', MISSAO_ID);
            vForm.append('csrf_token', CSRF_TOKEN);
            const vr = await fetch(BASE_URL + '/api/validar-operacional.php', { method: 'POST', body: vForm });
            const vd = await vr.json();
            if (!vd.success) {
                const erros = vd.erros ? vd.erros.join('\n• ') : vd.message;
                alert('❌ Validação operacional reprovada:\n• ' + erros);
                return;
            }
        } catch(e) {
            alert('❌ Erro ao validar operacional. Tente novamente.');
            return;
        }
    }

    const form = new FormData();
    form.append('missao_id', MISSAO_ID);
    form.append('acao', acao);
    form.append('csrf_token', CSRF_TOKEN);
    if (currentPos) {
        form.append('latitude', currentPos.lat);
        form.append('longitude', currentPos.lng);
    }
    try {
        const r = await fetch(BASE_URL + '/api/conducao-control.php', { method: 'POST', body: form });
        const d = await r.json();
        if (d.success) {
            if (acao === 'iniciar' || acao === 'retomar') {
                modoConducaoAtivo = true;
                iniciarTimer();
                if (acao === 'iniciar' && ['', null, 'nao_iniciada'].includes(STATUS_VIAGEM)) {
                    STATUS_VIAGEM = 'a_caminho_recolha';
                }
                desenharRotaAtiva(true);
            } else if (acao === 'pausar') {
                modoConducaoAtivo = false;
                pararTimer();
            } else if (acao === 'concluir') {
                modoConducaoAtivo = false;
                pararTimer();
                alert('Condução concluída. A redireccionar...');
                setTimeout(() => {
                    window.location.href = BASE_URL + '/pages/caminhoneiro/detalhes-missao.php?id=' + MISSAO_ID;
                }, 800);
                return;
            }
            actualizarBotoesConducao();
            atualizarTimerDisplay();
            // Mostrar toast leve em vez de alert bloqueante
            const gpsLabel = document.getElementById('gpsLabel');
            const originalText = gpsLabel.textContent;
            gpsLabel.textContent = '✓ ' + d.message;
            setTimeout(() => gpsLabel.textContent = originalText, 3000);
        } else {
            const msg = d.message || 'Erro';
            const sol = d.solucao && !msg.includes('💡') ? ('\n\n💡 ' + d.solucao) : '';
            alert('❌ ' + msg + sol);
        }
    } catch(e) {
        alert('❌ Sem ligação. Tente novamente.');
    }
}

// ── Estado da viagem (etapas recolha → destino) ───────────────
async function iniciarConfirmacaoOtp() {
    exigirChecklist('entrega', async () => {
        await accaoViagemExecutar('aguardar_codigo');
    });
}

function abrirModalOtp() {
    const link = document.getElementById('otpLinkCompleto');
    if (link) {
        link.href = BASE_URL + '/pages/caminhoneiro/entrega-confirmar.php?missao_id=' + MISSAO_ID;
    }
    document.getElementById('otpMsg').innerHTML = '';
    document.getElementById('modalOtp').classList.add('active');
    setTimeout(() => {
        const el = document.getElementById('otpCodigoInput');
        if (el) el.focus();
    }, 200);
}

function fecharModalOtp() {
    document.getElementById('modalOtp').classList.remove('active');
}

async function accaoViagem(acao, etapa) {
    if (acao === 'recolheu') {
        exigirChecklist('recolha', () => accaoViagemExecutar(acao, etapa));
        return;
    }
    if (acao === 'aguardar_codigo') {
        await iniciarConfirmacaoOtp();
        return;
    }
    await accaoViagemExecutar(acao, etapa);
}

async function enfileirarStatusViagem(acao, etapa) {
    if (!window.TrackMozOffline) return false;
    if (acao === 'aguardar_codigo') {
        alert('Confirmação por código OTP precisa de rede. Conecte-se e tente novamente.');
        return false;
    }
    const opt = optimisticStatus(acao, etapa);
    if (!opt) {
        alert('Esta acção não pode ser feita offline.');
        return false;
    }
    const body = {
        missao_id: MISSAO_ID,
        acao: acao,
        client_op_id: TrackMozOffline.uuid(),
    };
    if (etapa) body.etapa = etapa;
    const pos = posicaoMotorista();
    if (pos) {
        body.latitude = pos.lat;
        body.longitude = pos.lng;
    }
    await TrackMozOffline.enqueue({
        type: 'status_viagem',
        url: BASE_URL + '/pages/caminhoneiro/atualizar-status-viagem.php',
        body: body,
        meta: { missaoId: MISSAO_ID, acao: acao },
    });
    aplicarEstadoViagem(opt.status, opt.status_viagem, 'Guardado — sync quando houver rede');
    return true;
}

async function accaoViagemExecutar(acao, etapa) {
    if (acao === 'chegada_destino' && !etapaDestino() && STATUS_VIAGEM !== 'carga_recolhida') {
        alert('Confirme a recolha da carga antes de seguir para o destino.');
        return false;
    }
    if (acao === 'aguardar_codigo' && !etapaEntrega() && STATUS_MISSAO !== 'aguardando_confirmacao') {
        alert('Registe a chegada ao destino antes de confirmar a entrega.');
        return false;
    }

    // Já está à espera do OTP — abrir modal directamente
    if (acao === 'aguardar_codigo' && STATUS_MISSAO === 'aguardando_confirmacao') {
        abrirModalOtp();
        return true;
    }

    if (!navigator.onLine) {
        return enfileirarStatusViagem(acao, etapa);
    }

    const form = new FormData();
    form.append('missao_id', MISSAO_ID);
    form.append('acao', acao);
    if (etapa) form.append('etapa', etapa);
    const clientOpId = window.TrackMozOffline ? TrackMozOffline.uuid() : null;
    if (clientOpId) form.append('client_op_id', clientOpId);
    const pos = posicaoMotorista();
    if (pos) {
        form.append('latitude', pos.lat);
        form.append('longitude', pos.lng);
    }

    try {
        const r = await fetch(BASE_URL + '/pages/caminhoneiro/atualizar-status-viagem.php', { method: 'POST', body: form });
        let d;
        try { d = await r.json(); } catch (_) {
            alert('❌ Resposta inválida do servidor. Recarregue a página.');
            return false;
        }
        if (!d.success) {
            const msg = d.message || 'Erro';
            const sol = d.solucao ? ('\n\n💡 ' + d.solucao) : '';
            alert('❌ ' + msg + (msg.includes('💡') ? '' : sol));
            return false;
        }

        aplicarEstadoViagem(d.status, d.status_viagem, d.message || 'Actualizado');

        if (acao === 'aguardar_codigo') {
            abrirModalOtp();
        }
        return true;
    } catch (_) {
        return enfileirarStatusViagem(acao, etapa);
    }
}

document.getElementById('formOtpEntrega').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = document.getElementById('btnOtpSubmit');
    const msg = document.getElementById('otpMsg');
    const otp = (document.getElementById('otpCodigoInput').value || '').trim();
    const nome = (document.getElementById('otpNome').value || '').trim();
    const tel = (document.getElementById('otpTelefone').value || '').trim();
    const foto = document.getElementById('otpFoto').files[0];

    if (!/^\d{6}$/.test(otp)) {
        msg.innerHTML = '<div class="alert alert-danger">Introduza o código OTP de 6 dígitos.</div>';
        return;
    }
    if (!nome || !tel) {
        msg.innerHTML = '<div class="alert alert-danger">Nome e telefone de quem recebeu são obrigatórios.</div>';
        return;
    }
    if (!foto) {
        msg.innerHTML = '<div class="alert alert-danger">Tire uma foto da carga entregue.</div>';
        return;
    }

    const pos = posicaoMotorista();
    if (!pos) {
        msg.innerHTML = '<div class="alert alert-danger">Active o GPS e aguarde o sinal antes de confirmar.</div>';
        return;
    }

    const form = new FormData();
    form.append('missao_id', MISSAO_ID);
    form.append('metodo', 'otp');
    form.append('otp', otp);
    form.append('nome_recebedor', nome);
    form.append('telefone_recebedor', tel);
    form.append('documento_recebedor', document.getElementById('otpDoc').value || '');
    form.append('estado_carga', document.getElementById('otpEstado').value || 'sem_danos');
    form.append('observacoes', document.getElementById('otpObs').value || '');
    form.append('latitude', pos.lat);
    form.append('longitude', pos.lng);
    form.append('csrf_token', CSRF_TOKEN);
    form.append('foto_carga', foto);

    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="bi bi-hourglass-split"></i> A confirmar...'; }
    msg.innerHTML = '<div class="alert alert-info">A validar código e confirmar entrega…</div>';

    try {
        const r = await fetch(BASE_URL + '/api/entrega-confirmar.php', { method: 'POST', body: form, credentials: 'same-origin' });
        let d;
        try { d = await r.json(); } catch (_) {
            msg.innerHTML = '<div class="alert alert-danger">Resposta inválida do servidor.</div>';
            return;
        }
        if (!d.ok) {
            msg.innerHTML = '<div class="alert alert-danger">' + (d.error || d.message || 'Não foi possível confirmar.') + '</div>';
            return;
        }
        msg.innerHTML = '<div class="alert alert-success">' + (d.message || 'Entrega confirmada!') + '</div>';
        STATUS_MISSAO = 'entrega_confirmada';
        STATUS_VIAGEM = 'finalizada';
        actualizarBotoesConducao();
        setTimeout(() => {
            window.location.href = BASE_URL + '/pages/caminhoneiro/detalhes-missao.php?id=' + MISSAO_ID;
        }, 1200);
    } catch (err) {
        msg.innerHTML = '<div class="alert alert-danger">Erro de ligação. Tente novamente.</div>';
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check-lg"></i> Confirmar entrega';
        }
    }
});

document.getElementById('otpLinkCompleto')?.addEventListener('click', function(e) {
    e.preventDefault();
    window.location.href = BASE_URL + '/pages/caminhoneiro/entrega-confirmar.php?missao_id=' + MISSAO_ID;
});

// Geocodificar se faltar coordenadas
(async function geocodeFallback() {
    if (ORIGEM_LAT && DEST_LAT) {
        desenharRotaAtiva(true);
        return;
    }
    const tasks = [];
    if (!ORIGEM_LAT) tasks.push(fetch(BASE_URL + '/api/geocode.php?q=' + encodeURIComponent(ORIGEM_NOME)).then(r=>r.json()).then(d=>{ if(d.ok){ ORIGEM_LAT=d.lat; ORIGEM_LNG=d.lng; }}));
    if (!DEST_LAT) tasks.push(fetch(BASE_URL + '/api/geocode.php?q=' + encodeURIComponent(DESTINO_NOME)).then(r=>r.json()).then(d=>{ if(d.ok){ DEST_LAT=d.lat; DEST_LNG=d.lng; }}));
    await Promise.all(tasks);
    desenharRotaAtiva(true);
})();

// ── Inicialização ─────────────────────────────────────────────
(async function initModoDirecaoOffline() {
    await restaurarCacheMissao();
    guardarCacheMissao();
    if (modoConducaoAtivo) {
        iniciarTimer();
    }
    atualizarTimerDisplay();
    actualizarBotoesConducao();
    iniciarGpsTracker();
    if (window.TrackMozOffline && navigator.onLine) {
        TrackMozOffline.flush();
    }
})();
</script>
</body>
</html>
