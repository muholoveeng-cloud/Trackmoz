<?php
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');

include_once('../../includes/auth.php');
include_once('../../includes/geocode.php');
include_once('../../includes/missao-helpers.php');
include_once('../../includes/regras-negocio.php');
include_once('../../includes/documentos-registry.php');
include_once('../../includes/otp-entrega.php');
include_once('../../includes/sms-helpers.php');

require_role(['empresa'], '../login.php');

$success = $error = '';

// Verificar se a empresa tem parceria exclusiva activa
$parceria_activa = null;
try {
    $stmt = $conn->prepare(
        "SELECT p.id, p.transportador_id, p.exclusiva,
                pt.nome_empresa AS transportador_nome
         FROM parcerias p
         JOIN perfil_transportador pt ON p.transportador_id = pt.usuario_id
         WHERE p.empresa_id = :eid
           AND p.status = 'ativa'
           AND p.exclusiva = 1
           AND (p.data_fim IS NULL OR p.data_fim >= CURDATE())
         ORDER BY p.data_criacao ASC
         LIMIT 1"
    );
    $stmt->execute([':eid' => $_SESSION['user_id']]);
    $parceria_activa = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (PDOException $e) {
    error_log('Erro ao verificar parceria: ' . $e->getMessage());
}

try {
    // Processar o envio do formulário
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Ler e normalizar dados (sem FILTER_SANITIZE_STRING – obsoleto em PHP 8.1+)
        $titulo          = isset($_POST['titulo']) ? trim((string)$_POST['titulo']) : '';
        $origem          = isset($_POST['origem']) ? trim((string)$_POST['origem']) : '';
        // Destino: separado em província + distrito
        $destino_prov    = isset($_POST['destino_provincia']) ? trim((string)$_POST['destino_provincia']) : '';
        $destino_dist    = isset($_POST['destino_distrito']) ? trim((string)$_POST['destino_distrito']) : '';
        $destino         = ($destino_prov && $destino_dist) ? ($destino_prov . ' - ' . $destino_dist) : '';
        $tipo_veiculo    = isset($_POST['tipo_veiculo']) ? trim((string)$_POST['tipo_veiculo']) : '';
        $tipo_carga      = isset($_POST['tipo_carga']) ? trim((string)$_POST['tipo_carga']) : '';
        $valor           = isset($_POST['valor']) ? (float)str_replace(',', '.', (string)$_POST['valor']) : 0;
        $descricao       = isset($_POST['descricao']) ? trim((string)$_POST['descricao']) : '';
        $prazo_entrega   = isset($_POST['prazo_entrega']) ? trim((string)$_POST['prazo_entrega']) : '';
        // Coordenadas de origem (opcional, vindas do GPS)
        $origem_lat      = isset($_POST['origem_lat']) && $_POST['origem_lat'] !== '' ? (float)$_POST['origem_lat'] : null;
        $origem_lng      = isset($_POST['origem_lng']) && $_POST['origem_lng'] !== '' ? (float)$_POST['origem_lng'] : null;
        $destino_lat     = isset($_POST['destino_lat']) && $_POST['destino_lat'] !== '' ? (float)$_POST['destino_lat'] : null;
        $destino_lng     = isset($_POST['destino_lng']) && $_POST['destino_lng'] !== '' ? (float)$_POST['destino_lng'] : null;
        $requer_documento_carga = isset($_POST['requer_documento_carga']) ? 1 : 0;
        $tipo_documento_carga   = isset($_POST['tipo_documento_carga']) ? trim((string)$_POST['tipo_documento_carga']) : '';
        $destinatario_telefone  = isset($_POST['destinatario_telefone']) ? trim((string)$_POST['destinatario_telefone']) : '';

        if (empty($origem) && $origem_lat !== null && $origem_lng !== null) {
            $origem = $origem_lat . ', ' . $origem_lng;
        }

        // Validações básicas
        if (empty($titulo) || empty($origem) || empty($destino_prov) || empty($destino_dist) ||
            empty($tipo_veiculo) || empty($tipo_carga) || empty($valor) || empty($descricao) || empty($prazo_entrega)) {
            $error = "Todos os campos são obrigatórios (incluindo província e distrito do destino).";
        } elseif ($valor <= 0) {
            $error = "O valor deve ser maior que zero.";
        } elseif ($requer_documento_carga && empty($tipo_documento_carga)) {
            $error = "Se requer documento da carga, especifique o tipo.";
        } else {
            $pubCheck = validar_empresa_pode_publicar($conn, (int)$_SESSION['user_id']);
            if (!$pubCheck['ok']) {
                $error = regras_erro_mensagem($pubCheck);
            } else {
            $camposCheck = validar_missao_campos_obrigatorios([
                'origem'        => $origem,
                'destino'       => $destino,
                'descricao'     => $descricao,
                'tipo_carga'    => $tipo_carga,
                'peso_carga'    => $_POST['peso_carga'] ?? null,
                'valor'         => $valor,
                'prazo_entrega' => $prazo_entrega,
                'origem_lat'    => $origem_lat,
                'origem_lng'    => $origem_lng,
                'destino_lat'   => $destino_lat,
                'destino_lng'   => $destino_lng,
            ]);
            if (!$camposCheck['ok']) {
                $error = implode(' ', $camposCheck['erros']);
            } else {
            // Verificar se vai via parceria ou feed público
            $via_parceria   = isset($_POST['via_parceria']) && $_POST['via_parceria'] === '1' && $parceria_activa;
            $missao_status  = $via_parceria ? 'aceita' : 'aberta';
            $tid_parceria   = $via_parceria ? (int)$parceria_activa['transportador_id'] : null;
            $pid_parceria   = $via_parceria ? (int)$parceria_activa['id'] : null;

            $temParceriaCol = coluna_existe($conn, 'missoes', 'parceria_id');

            if ($temParceriaCol) {
                $sql = "INSERT INTO missoes (empresa_id, transportador_id, parceria_id, titulo, origem, destino,
                        tipo_veiculo, tipo_carga, valor, descricao, prazo_entrega, status, data_criacao,
                        requer_documento_carga, tipo_documento_carga)
                        VALUES (:empresa_id, :transportador_id, :parceria_id, :titulo, :origem, :destino,
                        :tipo_veiculo, :tipo_carga, :valor, :descricao, :prazo_entrega, :status, NOW(),
                        :requer_documento_carga, :tipo_documento_carga)";
            } else {
                $sql = "INSERT INTO missoes (empresa_id, transportador_id, titulo, origem, destino,
                        tipo_veiculo, tipo_carga, valor, descricao, prazo_entrega, status, data_criacao,
                        requer_documento_carga, tipo_documento_carga)
                        VALUES (:empresa_id, :transportador_id, :titulo, :origem, :destino,
                        :tipo_veiculo, :tipo_carga, :valor, :descricao, :prazo_entrega, :status, NOW(),
                        :requer_documento_carga, :tipo_documento_carga)";
            }

            $stmt = $conn->prepare($sql);
            $params = [
                ':empresa_id'             => $_SESSION['user_id'],
                ':transportador_id'       => $tid_parceria,
                ':titulo'                 => $titulo,
                ':origem'                 => $origem,
                ':destino'                => $destino,
                ':tipo_veiculo'           => $tipo_veiculo,
                ':tipo_carga'             => $tipo_carga,
                ':valor'                  => $valor,
                ':descricao'              => $descricao,
                ':prazo_entrega'          => $prazo_entrega,
                ':status'                 => $missao_status,
                ':requer_documento_carga' => $requer_documento_carga,
                ':tipo_documento_carga'   => $tipo_documento_carga,
            ];
            if ($temParceriaCol) {
                $params[':parceria_id'] = $pid_parceria;
            }
            $stmt->execute($params);

            $missao_id = (int)$conn->lastInsertId();

            pos_criacao_missao(
                $conn,
                $missao_id,
                $origem_lat,
                $origem_lng,
                $destino_lat,
                $destino_lng,
                $origem,
                $destino,
                $missao_status
            );

            // Automação documental inicial: registo da missão
            try {
                tmz_docs_criar_registo_missao(
                    $conn,
                    $missao_id,
                    (int)$_SESSION['user_id'],
                    (int)$_SESSION['user_id'],
                    [
                        'titulo' => $titulo,
                        'origem' => $origem,
                        'destino' => $destino,
                        'valor' => $valor,
                    ]
                );
            } catch (Throwable $e) {
                error_log('Automação docs nova_missao: ' . $e->getMessage());
            }

            // Processar documentos (falha no upload não cancela a missão já criada)
            if (!empty($_FILES['documentos']['name'][0])) {
                try {
                    $upload_dir = '../../uploads/documentos_missao/';
                    if (!file_exists($upload_dir)) {
                        mkdir($upload_dir, 0775, true);
                    }

                    foreach ($_FILES['documentos']['tmp_name'] as $key => $tmp_name) {
                        if (empty($tmp_name) || !is_uploaded_file($tmp_name)) {
                            continue;
                        }
                        $file_name      = $_FILES['documentos']['name'][$key];
                        $file_type      = $_FILES['documentos']['type'][$key];
                        $file_descricao = $_POST['documento_descricao'][$key] ?? '';
                        $file_extension = pathinfo($file_name, PATHINFO_EXTENSION);
                        $new_file_name  = 'missao_' . $missao_id . '_' . uniqid() . '.' . $file_extension;

                        if (move_uploaded_file($tmp_name, $upload_dir . $new_file_name)) {
                            $stmtDoc = $conn->prepare(
                                "INSERT INTO documentos_missao (missao_id, nome, arquivo, tipo, data_upload, is_from_contratante, descricao)
                                 VALUES (:missao_id, :nome, :arquivo, :tipo, NOW(), 1, :descricao)"
                            );
                            $stmtDoc->execute([
                                ':missao_id' => $missao_id,
                                ':nome'      => $file_name,
                                ':arquivo'   => $new_file_name,
                                ':tipo'      => $file_type,
                                ':descricao' => $file_descricao,
                            ]);
                        }
                    }
                } catch (PDOException $e) {
                    error_log('Upload documentos missão ' . $missao_id . ': ' . $e->getMessage());
                }
            }

            // Código OTP de entrega (partilhar com destinatário — motorista não vê)
            $otp_codigo_criacao = null;
            $otp_envio_links = null;
            try {
                $otpResult = otp_gerar_para_missao($conn, $missao_id, (int)$_SESSION['user_id'], false);
                if ($otpResult['ok']) {
                    $otp_codigo_criacao = $otpResult['codigo'];
                    $_SESSION['otp_missao_' . $missao_id] = $otp_codigo_criacao;
                    if ($destinatario_telefone !== '') {
                        $otp_envio_links = otp_notificar_destinatario(
                            $conn,
                            $missao_id,
                            $destinatario_telefone,
                            $otp_codigo_criacao,
                            $otpResult['expira_em'],
                            $titulo
                        );
                    }
                }
            } catch (Throwable $e) {
                error_log('OTP nova_missao: ' . $e->getMessage());
            }

            if ($via_parceria) {
                try {
                    $notif = $conn->prepare(
                        "INSERT INTO notificacoes (usuario_id, tipo, titulo, mensagem, link)
                         VALUES (:uid, 'missao', 'Nova Missão via Parceria', :msg, :link)"
                    );
                    $notif->execute([
                        ':uid'  => $tid_parceria,
                        ':msg'  => 'Nova missão atribuída directamente via contrato de parceria: ' . $titulo,
                        ':link' => BASE_URL . '/pages/transportador/missoes.php',
                    ]);
                } catch (PDOException $e) {
                    error_log('Notif parceria: ' . $e->getMessage());
                }
                $success = 'Missão criada e enviada directamente para ' . htmlspecialchars($parceria_activa['transportador_nome']) . ' (parceria exclusiva).';
            } else {
                try {
                    $msgNotif = 'Uma nova missão foi publicada: ' . $titulo;
                    $stmtCam = $conn->query(
                        "SELECT u.id FROM usuarios u
                         INNER JOIN perfil_caminhoneiro pc ON u.id = pc.usuario_id
                         WHERE u.tipo_usuario = 'caminhoneiro' AND pc.disponibilidade = 'disponivel'"
                    );
                    $insNotif = $conn->prepare(
                        "INSERT INTO notificacoes (usuario_id, tipo, titulo, mensagem)
                         VALUES (:uid, 'missao', 'Nova missão disponível', :msg)"
                    );
                    foreach ($stmtCam->fetchAll(PDO::FETCH_COLUMN) as $uidCam) {
                        $insNotif->execute([':uid' => $uidCam, ':msg' => $msgNotif]);
                    }
                } catch (PDOException $e) {
                    error_log('Notif caminhoneiros: ' . $e->getMessage());
                }
                $success = 'Missão criada com sucesso!';
            }
            if ($otp_codigo_criacao) {
                $success .= ' Código de entrega (OTP): <strong>' . htmlspecialchars($otp_codigo_criacao)
                    . '</strong> — partilhe com o destinatário. Válido até '
                    . date('d/m/Y H:i', strtotime($otpResult['expira_em'] ?? '+48 hours')) . '.';
                if ($otp_envio_links && !empty($otp_envio_links['enviado_automatico'])) {
                    $success .= ' <span class="text-success">SMS enviado automaticamente.</span>';
                } elseif ($otp_envio_links && !empty($otp_envio_links['whatsapp_url'])) {
                    $success .= ' <a href="' . htmlspecialchars($otp_envio_links['whatsapp_url']) . '" target="_blank" class="alert-link">Enviar por WhatsApp</a>';
                }
            }
            }
            }
        }
    }
} catch (PDOException $e) {
    error_log('Erro ao criar missão: ' . $e->getMessage());
    $error = 'Erro ao criar missão. Por favor, tente novamente.';
    if (defined('APP_DEBUG') && APP_DEBUG) {
        $error .= ' (' . $e->getMessage() . ')';
    }
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nova Missão - TrackMoz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/tms-mapa.css"/>
</head>
<body>
    <?php include_once('../../includes/menu.php'); ?>

    <div class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card fade-in">
                    <div class="card-body">
                        <h2 class="card-title mb-4">Nova Missão</h2>

                        <?php if ($success): ?>
                            <div class="alert alert-success" role="alert">
                                <?php echo $success; ?>
                                <div class="mt-3">
                                    <a href="missoes.php" class="btn btn-primary">Ver Minhas Missões</a>
                                </div>
                            </div>
                        <?php else: ?>
                            <?php if ($error): ?>
                                <div class="alert alert-danger" role="alert">
                                    <?php echo $error; ?>
                                </div>
                            <?php endif; ?>

                            <form method="POST" action="" enctype="multipart/form-data" data-guard-submit>

                                <?php if ($parceria_activa): ?>
                                <div class="alert alert-primary d-flex align-items-start gap-3 mb-4">
                                    <i class="bi bi-handshake fs-4 mt-1 flex-shrink-0"></i>
                                    <div class="flex-fill">
                                        <div class="fw-semibold mb-1">Parceria Exclusiva Activa</div>
                                        <div class="small mb-2">
                                            Tem uma parceria exclusiva com <strong><?php echo htmlspecialchars($parceria_activa['transportador_nome']); ?></strong>.
                                            Esta missão pode ser enviada directamente, sem aparecer no feed público.
                                        </div>
                                        <div class="form-check form-switch mb-0">
                                            <input class="form-check-input" type="checkbox"
                                                   name="via_parceria" value="1"
                                                   id="viaParceria" checked>
                                            <label class="form-check-label small" for="viaParceria">
                                                Enviar directamente para <strong><?php echo htmlspecialchars($parceria_activa['transportador_nome']); ?></strong>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <div class="mb-3">
                                    <label for="titulo" class="form-label">Título da Missão</label>
                                    <input type="text" class="form-control" id="titulo" name="titulo" required>
                                </div>

                                <div class="card border-success border-2 mb-3">
                                    <div class="card-header bg-success text-white">
                                        <i class="bi bi-geo-alt me-1"></i> Origem (local de recolha da carga)
                                    </div>
                                    <div class="card-body">
                                        <div class="row g-2 mb-2">
                                            <div class="col-md-6">
                                                <label class="form-label small fw-semibold">Endereço / Localidade</label>
                                                <input type="text" class="form-control" id="origem" name="origem"
                                                       placeholder="Ex: Beira, Sofala" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small fw-semibold">Pesquisar no mapa</label>
                                                <div class="input-group position-relative">
                                                    <input type="text" class="form-control" id="origem_pesquisa"
                                                           placeholder="Ex: Mega Distribuidora Maputo">
                                                    <button type="button" class="btn btn-outline-success" id="btnPesquisarOrigem">
                                                        <i class="bi bi-search"></i>
                                                    </button>
                                                    <div id="sugestoes_origem" class="tm-sugestoes"></div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="d-flex flex-wrap gap-2 mb-2">
                                            <button type="button" class="btn btn-sm btn-primary" id="btnUseLocation">
                                                <i class="bi bi-geo-alt me-1"></i>Usar minha localização actual
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" id="btnEscolherMapaOrigem">
                                                <i class="bi bi-pin-map me-1"></i>Escolher no mapa
                                            </button>
                                            <small id="geoStatus" class="text-muted align-self-center d-none"></small>
                                        </div>
                                        <div class="row g-2">
                                            <div class="col-6">
                                                <label class="form-label small text-muted">Latitude</label>
                                                <input type="text" class="form-control form-control-sm" name="origem_lat" id="origem_lat" readonly placeholder="—">
                                            </div>
                                            <div class="col-6">
                                                <label class="form-label small text-muted">Longitude</label>
                                                <input type="text" class="form-control form-control-sm" name="origem_lng" id="origem_lng" readonly placeholder="—">
                                            </div>
                                        </div>
                                        <div class="form-text mt-1">Preencha o endereço e use o GPS ou clique no mapa. Nunca assumimos Maputo como padrão.</div>
                                    </div>
                                </div>

                                <div class="card border-danger border-2 mb-3">
                                    <div class="card-header bg-danger text-white">
                                        <i class="bi bi-geo me-1"></i> Destino (local de entrega)
                                    </div>
                                    <div class="card-body">
                                        <div class="row g-2 mb-2">
                                            <div class="col-md-6">
                                                <label class="form-label small fw-semibold">Província</label>
                                                <input type="text" class="form-control" id="destino_provincia"
                                                       name="destino_provincia" placeholder="Ex: Gaza" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small fw-semibold">Distrito</label>
                                                <input type="text" class="form-control" id="destino_distrito"
                                                       name="destino_distrito" placeholder="Ex: Xai-Xai" required>
                                            </div>
                                        </div>
                                        <div class="row g-2 mb-2">
                                            <div class="col-md-6">
                                                <label class="form-label small fw-semibold">Pesquisar no mapa</label>
                                                <div class="input-group position-relative">
                                                    <input type="text" class="form-control" id="destino_pesquisa"
                                                           placeholder="Ex: Shoprite Beira, Porto da Beira">
                                                    <button type="button" class="btn btn-outline-danger" id="btnPesquisarDestino">
                                                        <i class="bi bi-search"></i>
                                                    </button>
                                                    <div id="sugestoes_destino" class="tm-sugestoes"></div>
                                                </div>
                                            </div>
                                            <div class="col-md-6 d-flex align-items-end">
                                                <button type="button" class="btn btn-sm btn-outline-secondary" id="btnEscolherMapaDestino">
                                                    <i class="bi bi-pin-map me-1"></i>Escolher destino no mapa
                                                </button>
                                            </div>
                                        </div>
                                        <div class="row g-2">
                                            <div class="col-6">
                                                <label class="form-label small text-muted">Latitude</label>
                                                <input type="text" class="form-control form-control-sm" name="destino_lat" id="destino_lat" readonly placeholder="—">
                                            </div>
                                            <div class="col-6">
                                                <label class="form-label small text-muted">Longitude</label>
                                                <input type="text" class="form-control form-control-sm" name="destino_lng" id="destino_lng" readonly placeholder="—">
                                            </div>
                                        </div>
                                        <div class="mt-2">
                                            <label class="form-label small fw-semibold">Telefone do destinatário (OTP)</label>
                                            <input type="tel" class="form-control form-control-sm" name="destinatario_telefone"
                                                   placeholder="Ex: 84 123 4567 — para enviar código por WhatsApp/SMS" inputmode="tel">
                                            <div class="form-text">Opcional. <?php echo htmlspecialchars(sms_modo_descricao()); ?></div>
                                        </div>
                                    </div>
                                </div>

                                <div class="card mb-3">
                                    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                                        <span><i class="bi bi-map me-1"></i> Mapa interactivo</span>
                                        <div class="btn-group btn-group-sm">
                                            <button type="button" class="btn btn-outline-success active" id="btnModoOrigem">Marcar origem</button>
                                            <button type="button" class="btn btn-outline-danger" id="btnModoDestino">Marcar destino</button>
                                        </div>
                                    </div>
                                    <div class="card-body p-2">
                                        <div id="mapaSeletorMissao"></div>
                                        <div id="rotaInfo" class="alert alert-info mt-2 mb-0 py-2 d-none small">
                                            <i class="bi bi-signpost-split me-1"></i><span id="rotaInfoTexto"></span>
                                        </div>
                                        <p class="small text-muted mt-2 mb-0">
                                            <strong>Verde</strong> = recolha da carga &nbsp;|&nbsp; <strong>Vermelho</strong> = entrega &nbsp;|&nbsp; Clique no mapa para marcar. A rota é calculada pela estrada (OSRM).
                                        </p>
                                    </div>
                                </div>

                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label for="tipo_veiculo" class="form-label">Tipo de Veículo</label>
                                        <select class="form-select" id="tipo_veiculo" name="tipo_veiculo" required>
                                            <option value="">Selecione...</option>
                                            <option value="caminhao">Caminhão</option>
                                            <option value="van">Van</option>
                                            <option value="pickup">Pickup</option>
                                            <option value="moto">Moto</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="tipo_carga" class="form-label">Tipo de Carga</label>
                                        <select class="form-select" id="tipo_carga" name="tipo_carga" required>
                                            <option value="">Selecione...</option>
                                            <option value="geral">Carga Geral</option>
                                            <option value="granel">Granel</option>
                                            <option value="refrigerada">Refrigerada</option>
                                            <option value="perigosa">Carga Perigosa</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label for="valor" class="form-label">Valor Sugerido (MT)</label>
                                        <div class="input-group">
                                            <span class="input-group-text">MT</span>
                                            <input type="number" class="form-control" id="valor" name="valor" 
                                                   step="0.01" min="0" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="prazo_entrega" class="form-label">Prazo de Entrega</label>
                                        <input type="date" class="form-control" id="prazo_entrega" name="prazo_entrega" required>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label for="descricao" class="form-label">Descrição</label>
                                    <textarea class="form-control" id="descricao" name="descricao" rows="4" required
                                              placeholder="Descreva os detalhes da missão, requisitos especiais, etc..."></textarea>
                                </div>

                                <div class="card mb-4">
                                    <div class="card-header">
                                        <h5 class="mb-0">Documentação</h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="mb-3">
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" id="requer_documento_carga" name="requer_documento_carga">
                                                <label class="form-check-label" for="requer_documento_carga">
                                                    Requerer documento da carga do caminhoneiro
                                                </label>
                                            </div>
                                            <div id="documentoCargaOptions" class="mt-3" style="display: none;">
                                                <label for="tipo_documento_carga" class="form-label">Tipo de documento necessário:</label>
                                                <select class="form-select" id="tipo_documento_carga" name="tipo_documento_carga">
                                                    <option value="">Selecione...</option>
                                                    <option value="nota_fiscal">Nota Fiscal</option>
                                                    <option value="certificado_carga">Certificado da Carga</option>
                                                    <option value="guia_transporte">Guia de Transporte</option>
                                                    <option value="carta_porte">Carta de Porte</option>
                                                    <option value="termo_responsabilidade">Termo de Responsabilidade</option>
                                                    <option value="outro">Outro (especificar na descrição)</option>
                                                </select>
                                                <div class="form-text">O caminhoneiro precisará fornecer este documento antes de iniciar a missão.</div>
                                            </div>
                                        </div>

                                        <div class="mb-3">
                                            <label for="documentos" class="form-label">Anexar documentos da carga (opcional)</label>
                                            <div id="documentosContainer">
                                                <div class="documento-item mb-2">
                                                    <div class="row">
                                                        <div class="col-md-8 mb-2">
                                                            <input type="file" class="form-control" name="documentos[]">
                                                        </div>
                                                        <div class="col-md-4 mb-2">
                                                            <input type="text" class="form-control" name="documento_descricao[]" placeholder="Descrição (opcional)">
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <button type="button" id="addDocumentoBtn" class="btn btn-sm btn-outline-secondary mt-2">
                                                <i class="bi bi-plus-circle"></i> Adicionar mais documentos
                                            </button>
                                    <div class="form-text">
                                                Você pode anexar documentos como nota fiscal, certificado da carga, etc.
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="d-flex justify-content-between">
                                    <a href="missoes.php" class="btn btn-outline-secondary">
                                        <i class="bi bi-arrow-left"></i> Voltar
                                    </a>
                                    <button type="submit" class="btn btn-primary" data-loading-text="<span class='tm-loader'></span> A criar...">
                                        <i class="bi bi-plus-circle"></i> Criar Missão
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="<?php echo BASE_URL; ?>/assets/js/mapa-core.js"></script>
    <script src="<?php echo BASE_URL; ?>/assets/js/mapa-seletor.js"></script>
    <script src="<?php echo BASE_URL; ?>/assets/js/form-guard.js"></script>
    <script>
        const BASE_URL_MAP = <?php echo json_encode(BASE_URL); ?>;
        let mapaSeletor = null;

        function mostrarGeoStatus(msg, tipo = 'info') {
            const el = document.getElementById('geoStatus');
            if (!el) return;
            el.textContent = msg;
            el.className = 'small align-self-center ' + (tipo === 'erro' ? 'text-danger' : tipo === 'ok' ? 'text-success' : 'text-muted');
            el.classList.remove('d-none');
        }

        function mostrarRotaInfo(info) {
            const box = document.getElementById('rotaInfo');
            const txt = document.getElementById('rotaInfoTexto');
            if (!box || !txt) return;
            if (info.km > 0) {
                txt.textContent = `Rota por estrada: ~${info.km} km · ~${info.min} min`;
                if (info.aviso) txt.textContent += ` (${info.aviso})`;
                box.classList.remove('d-none');
            } else {
                box.classList.add('d-none');
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            /* --- Mapa seletor --- */
            if (document.getElementById('mapaSeletorMissao')) {
                mapaSeletor = new MapaSeletorMissao('mapaSeletorMissao', {
                    baseUrl: BASE_URL_MAP,
                    onUpdate(modo, info) {
                        const lat = typeof info === 'object' ? info.lat : info;
                        const lng = typeof info === 'object' ? info.lng : arguments[2];
                        const endereco = typeof info === 'object' ? (info.endereco || info.nome) : '';
                        if (modo === 'origem') {
                            document.getElementById('origem_lat').value = lat;
                            document.getElementById('origem_lng').value = lng;
                            if (endereco && document.getElementById('origem').value === '') {
                                document.getElementById('origem').value = endereco;
                            }
                        } else {
                            document.getElementById('destino_lat').value = lat;
                            document.getElementById('destino_lng').value = lng;
                        }
                    },
                    onRotaInfo(info) { mostrarRotaInfo(info); },
                });

                document.getElementById('btnModoOrigem')?.addEventListener('click', () => {
                    mapaSeletor.setModo('origem');
                    document.getElementById('btnModoOrigem').classList.add('active');
                    document.getElementById('btnModoDestino').classList.remove('active');
                });
                document.getElementById('btnModoDestino')?.addEventListener('click', () => {
                    mapaSeletor.setModo('destino');
                    document.getElementById('btnModoDestino').classList.add('active');
                    document.getElementById('btnModoOrigem').classList.remove('active');
                });
            }

            /* --- Pesquisar origem com sugestões Nominatim --- */
            function setupNominatimAutocomplete(inputId, listId, modo) {
                const input = document.getElementById(inputId);
                const list = document.getElementById(listId);
                if (!input || !list) return;
                let timer = null;
                input.addEventListener('input', () => {
                    clearTimeout(timer);
                    const q = input.value.trim();
                    if (q.length < 2) { list.classList.remove('active'); list.innerHTML = ''; return; }
                    timer = setTimeout(async () => {
                        const sugestoes = await mapaSeletor?.pesquisar(q, modo) || [];
                        list.innerHTML = sugestoes.map((s, i) =>
                            `<div class="tm-sugestao-item" data-idx="${i}">
                                <strong>${s.nome}</strong><small>${s.endereco}</small>
                            </div>`
                        ).join('');
                        list.classList.add('active');
                        list.querySelectorAll('.tm-sugestao-item').forEach((el, i) => {
                            el.addEventListener('click', async () => {
                                await mapaSeletor.selecionarSugestao(sugestoes[i], modo);
                                input.value = sugestoes[i].nome;
                                list.classList.remove('active');
                                mostrarGeoStatus('Local seleccionado!', 'ok');
                            });
                        });
                    }, 400);
                });
            }
            setupNominatimAutocomplete('origem_pesquisa', 'sugestoes_origem', 'origem');
            setupNominatimAutocomplete('destino_pesquisa', 'sugestoes_destino', 'destino');

            /* --- Pesquisar origem --- */
            const btnPesquisarOrigem = document.getElementById('btnPesquisarOrigem');
            const inputPesquisaOrigem = document.getElementById('origem_pesquisa');
            if (btnPesquisarOrigem && inputPesquisaOrigem) {
                btnPesquisarOrigem.addEventListener('click', async () => {
                    const q = inputPesquisaOrigem.value.trim();
                    if (!q) return;
                    mostrarGeoStatus('A pesquisar...');
                    const res = await mapaSeletor?.geocodificar(q, 'origem');
                    if (res) {
                        mostrarGeoStatus('Localizacao encontrada!', 'ok');
                        document.getElementById('origem_lat').value = res.lat;
                        document.getElementById('origem_lng').value = res.lng;
                    } else {
                        mostrarGeoStatus('Endereco nao encontrado. Tente outro ou clique no mapa.', 'erro');
                    }
                });
                inputPesquisaOrigem.addEventListener('keypress', (e) => { if (e.key === 'Enter') { e.preventDefault(); btnPesquisarOrigem.click(); } });
            }

            /* --- Pesquisar destino --- */
            const btnPesquisarDestino = document.getElementById('btnPesquisarDestino');
            const inputPesquisaDestino = document.getElementById('destino_pesquisa');
            if (btnPesquisarDestino && inputPesquisaDestino) {
                btnPesquisarDestino.addEventListener('click', async () => {
                    const q = inputPesquisaDestino.value.trim();
                    if (!q) return;
                    mostrarGeoStatus('A pesquisar destino...');
                    const res = await mapaSeletor?.geocodificar(q, 'destino');
                    if (res) {
                        mostrarGeoStatus('Destino encontrado!', 'ok');
                        document.getElementById('destino_lat').value = res.lat;
                        document.getElementById('destino_lng').value = res.lng;
                    } else {
                        mostrarGeoStatus('Destino nao encontrado. Tente outro ou clique no mapa.', 'erro');
                    }
                });
                inputPesquisaDestino.addEventListener('keypress', (e) => { if (e.key === 'Enter') { e.preventDefault(); btnPesquisarDestino.click(); } });
            }

            /* --- Blur provincia/distrito -> geocodificar destino automaticamente --- */
            ['destino_provincia', 'destino_distrito'].forEach((id) => {
                document.getElementById(id)?.addEventListener('blur', () => {
                    const prov = document.getElementById('destino_provincia')?.value || '';
                    const dist = document.getElementById('destino_distrito')?.value || '';
                    if (prov && dist) mapaSeletor?.geocodificarDestino(prov + ' - ' + dist);
                });
            });

            /* --- Usar localizacao actual --- */
            const btnUseLocation = document.getElementById('btnUseLocation');
            const origemInput    = document.getElementById('origem');
            const origemLat      = document.getElementById('origem_lat');
            const origemLng      = document.getElementById('origem_lng');

            if (btnUseLocation) {
                btnUseLocation.addEventListener('click', async () => {
                    if (!navigator.geolocation) {
                        mostrarGeoStatus('Navegador sem suporte a GPS. Use pesquisa ou clique no mapa.', 'erro');
                        origemInput.focus();
                        return;
                    }
                    mostrarGeoStatus('A obter GPS... aguarde.');
                    try {
                        const pos = await mapaSeletor.usarLocalizacaoAtual();
                        origemLat.value = pos.lat;
                        origemLng.value = pos.lng;
                        let msg = 'Localizacao actual obtida!';
                        if (pos.accuracy > 1000) msg += ' (precisao baixa: ~' + Math.round(pos.accuracy) + 'm)';
                        mostrarGeoStatus(msg, 'ok');
                    } catch (err) {
                        mostrarGeoStatus(err.message + ' Use pesquisa ou clique no mapa.', 'erro');
                    }
                });
            }

            /* --- Botoes "Escolher no mapa" --- */
            document.getElementById('btnEscolherMapaOrigem')?.addEventListener('click', () => {
                mapaSeletor?.setModo('origem');
                document.getElementById('btnModoOrigem').classList.add('active');
                document.getElementById('btnModoDestino').classList.remove('active');
                document.getElementById('mapaSeletorMissao').scrollIntoView({ behavior: 'smooth', block: 'center' });
            });
            document.getElementById('btnEscolherMapaDestino')?.addEventListener('click', () => {
                mapaSeletor?.setModo('destino');
                document.getElementById('btnModoDestino').classList.add('active');
                document.getElementById('btnModoOrigem').classList.remove('active');
                document.getElementById('mapaSeletorMissao').scrollIntoView({ behavior: 'smooth', block: 'center' });
            });

            /* --- Documentos da carga --- */
            const requerDocumentoCheckbox = document.getElementById('requer_documento_carga');
            const documentoCargaOptions = document.getElementById('documentoCargaOptions');
            requerDocumentoCheckbox?.addEventListener('change', function() {
                documentoCargaOptions.style.display = this.checked ? 'block' : 'none';
            });

            const addDocumentoBtn = document.getElementById('addDocumentoBtn');
            const documentosContainer = document.getElementById('documentosContainer');
            addDocumentoBtn?.addEventListener('click', function() {
                const newItem = document.createElement('div');
                newItem.className = 'documento-item mb-2';
                newItem.innerHTML = `
                    <div class="row">
                        <div class="col-md-8 mb-2">
                            <input type="file" class="form-control" name="documentos[]">
                        </div>
                        <div class="col-md-4 mb-2">
                            <input type="text" class="form-control" name="documento_descricao[]" placeholder="Descricao (opcional)">
                        </div>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-danger remove-documento">
                        <i class="bi bi-trash"></i> Remover
                    </button>
                `;
                documentosContainer.appendChild(newItem);
                newItem.querySelector('.remove-documento').addEventListener('click', function() {
                    documentosContainer.removeChild(newItem);
                });
            });

            /* --- Data minima prazo --- */
            const prazoEntregaInput = document.getElementById('prazo_entrega');
            if (prazoEntregaInput) {
                const today = new Date();
                prazoEntregaInput.setAttribute('min', today.toISOString().split('T')[0]);
            }
        });
    </script>
</body>
</html> 