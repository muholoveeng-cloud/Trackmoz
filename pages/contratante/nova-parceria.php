<?php
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');
include_once('../../includes/auth.php');
include_once('../../includes/helpers.php');
include_once('../../includes/parceria-helpers.php');

require_role(['empresa'], '../login.php');

$empresa_id = (int)$_SESSION['user_id'];
$msg_ok  = '';
$msg_err = '';

// Buscar transportadoras disponíveis (ativas, sem parceria ativa/pendente com esta empresa)
try {
    $stmt = $conn->prepare(
        "SELECT u.id, u.email, u.telefone, pt.nome_empresa, pt.cidade, pt.provincia,
                pt.avaliacao_media, pt.total_missoes, pt.verificada
         FROM usuarios u
         JOIN perfil_transportador pt ON u.id = pt.usuario_id
         WHERE u.tipo_usuario = 'transportador'
           AND u.status = 'ativo'
           AND u.id NOT IN (
               SELECT transportador_id FROM parcerias
               WHERE empresa_id = :eid AND status IN (
                   'rascunho','pedido_enviado','em_negociacao',
                   'aguardando_aprovacao_empresa','aguardando_aprovacao_transportador',
                   'aguardando_validacao_admin','ativa','pendente'
               )
           )
         ORDER BY pt.verificada DESC, pt.avaliacao_media DESC"
    );
    $stmt->execute([':eid' => $empresa_id]);
    $transportadoras = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Erro ao listar transportadoras: ' . $e->getMessage());
    $transportadoras = [];
    $msg_err = 'Erro ao carregar transportadoras disponíveis.';
}

// Processar formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($msg_err)) {
    $transportador_id = (int)($_POST['transportador_id'] ?? 0);
    $data_inicio      = trim($_POST['data_inicio'] ?? '');
    $data_fim         = !empty($_POST['data_fim']) ? trim($_POST['data_fim']) : null;
    $exclusiva        = isset($_POST['exclusiva']) ? 1 : 0;
    $descricao        = trim($_POST['descricao'] ?? '');

    if ($transportador_id <= 0 || empty($data_inicio)) {
        $msg_err = 'Seleccione uma transportadora e defina a data de início.';
    } elseif ($data_fim && $data_fim <= $data_inicio) {
        $msg_err = 'A data de fim deve ser posterior à data de início.';
    } else {
        try {
            $chk = $conn->prepare(
                "SELECT COUNT(*) FROM parcerias
                 WHERE empresa_id = :eid AND transportador_id = :tid AND status IN ('rascunho','pedido_enviado','em_negociacao','aguardando_aprovacao_empresa','aguardando_aprovacao_transportador','aguardando_validacao_admin','ativa')"
            );
            $chk->execute([':eid' => $empresa_id, ':tid' => $transportador_id]);
            if ((int)$chk->fetchColumn() > 0) {
                $msg_err = 'Já existe uma parceria activa ou em negociação com esta transportadora.';
            } else {
                $dados = [
                    ':eid'   => $empresa_id,
                    ':tid'   => $transportador_id,
                    ':desc'  => $descricao ?: null,
                    ':inicio'=> $data_inicio,
                    ':fim'   => $data_fim,
                    ':excl'  => $exclusiva,
                    ':tipo_contrato' => $_POST['tipo_contrato'] ?? 'por_missao',
                    ':valor_missao' => $_POST['valor_missao'] !== '' ? (float)$_POST['valor_missao'] : null,
                    ':valor_km' => $_POST['valor_km'] !== '' ? (float)$_POST['valor_km'] : null,
                    ':valor_mensal' => $_POST['valor_mensal'] !== '' ? (float)$_POST['valor_mensal'] : null,
                    ':comissao' => $_POST['comissao_plataforma_pct'] !== '' ? (float)$_POST['comissao_plataforma_pct'] : 0,
                    ':cond_pag' => $_POST['condicoes_pagamento'] ?? '30_dias',
                    ':sla' => (int)($_POST['sla_resposta_horas'] ?? 24),
                    ':penalidade' => $_POST['penalidade_atraso_pct'] !== '' ? (float)$_POST['penalidade_atraso_pct'] : 0,
                    ':resp_carga' => $_POST['responsabilidade_carga'] ?? 'seguro',
                    ':tipos_carga' => $_POST['tipos_carga_permitidos'] ?? null,
                    ':rotas' => $_POST['rotas_cobertas'] ?? null,
                    ':obs' => $_POST['observacoes_negociacao'] ?? null,
                    ':req_admin' => isset($_POST['requer_validacao_admin']) ? 1 : 0,
                ];

                $stmt = $conn->prepare(
                    "INSERT INTO parcerias
                        (empresa_id, transportador_id, descricao, data_inicio, data_fim, exclusiva,
                         tipo_contrato, valor_missao, valor_km, valor_mensal, comissao_plataforma_pct,
                         condicoes_pagamento, sla_resposta_horas, penalidade_atraso_pct,
                         responsabilidade_carga, tipos_carga_permitidos, rotas_cobertas,
                         observacoes_negociacao, status, proposto_por, requer_validacao_admin,
                         aprovado_por_empresa, data_criacao)
                     VALUES
                        (:eid, :tid, :desc, :inicio, :fim, :excl,
                         :tipo_contrato, :valor_missao, :valor_km, :valor_mensal, :comissao,
                         :cond_pag, :sla, :penalidade,
                         :resp_carga, :tipos_carga, :rotas,
                         :obs, 'pedido_enviado', 'empresa', :req_admin,
                         1, NOW())"
                );
                $stmt->execute($dados);

                $parceria_id = (int)$conn->lastInsertId();

                // Histórico (snapshot limpo, sem binds PDO)
                $snapshot = parceria_snapshot_de_binds($dados);
                $conn->prepare(
                    "INSERT INTO parceria_negociacoes (parceria_id, proposto_por, proposto_por_usuario_id, versao, campo_alterado, valor_novo, comentario)
                     VALUES (:pid, 'empresa', :uid, 1, 'criacao', :json, 'Proposta inicial enviada')"
                )->execute([
                    ':pid'  => $parceria_id,
                    ':uid'  => $empresa_id,
                    ':json' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
                ]);

                $emp = $conn->prepare("SELECT nome_empresa FROM perfil_empresa WHERE usuario_id = :id");
                $emp->execute([':id' => $empresa_id]);
                $nome_empresa = $emp->fetchColumn() ?: 'Uma empresa';

                $conn->prepare(
                    "INSERT INTO notificacoes (usuario_id, tipo, titulo, mensagem, link)
                     VALUES (:uid, 'parceria', 'Nova Proposta de Parceria',
                     :msg, '/trackmoz/pages/transportador/parcerias.php')"
                )->execute([
                    ':uid' => $transportador_id,
                    ':msg' => $nome_empresa . ' enviou uma proposta de parceria profissional. Revise os termos e responda.',
                ]);

                $msg_ok = 'Proposta de parceria profissional enviada! A transportadora será notificada.';
                $transportadoras = array_filter($transportadoras, fn($t) => (int)$t['id'] !== $transportador_id);
            }
        } catch (PDOException $e) {
            error_log('Erro ao criar parceria: ' . $e->getMessage());
            $msg_err = 'Erro ao enviar proposta de parceria.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nova Parceria - TrackMoz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <style>
        .transportadora-card {
            border: 2px solid transparent;
            cursor: pointer;
            transition: border-color 0.2s, background 0.2s;
        }
        .transportadora-card:hover { border-color: #0d6efd; background: #f0f4ff; }
        .transportadora-card.selected { border-color: #0d6efd; background: #e8f0fe; }
    </style>
</head>
<body>
<?php include_once('../../includes/menu.php'); ?>

<div class="container mt-4">
    <div class="row justify-content-center">
        <div class="col-lg-9">
            <div class="d-flex align-items-center mb-4 gap-3">
                <a href="parcerias.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left"></i>
                </a>
                <div>
                    <h2 class="mb-0"><i class="bi bi-handshake me-2 text-primary"></i>Propor Nova Parceria</h2>
                    <p class="text-muted mb-0 small">Seleccione uma transportadora e defina os termos do contrato</p>
                </div>
            </div>

            <?php if ($msg_ok): ?>
                <div class="alert alert-success">
                    <i class="bi bi-check-circle me-2"></i><?php echo htmlspecialchars($msg_ok); ?>
                    <div class="mt-2">
                        <a href="parcerias.php" class="btn btn-success btn-sm">Ver Parcerias</a>
                    </div>
                </div>
            <?php endif; ?>
            <?php if ($msg_err): ?>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-circle me-2"></i><?php echo htmlspecialchars($msg_err); ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <!-- 1. Selecção da transportadora -->
                <div class="card mb-4">
                    <div class="card-header fw-semibold">
                        <i class="bi bi-truck me-2 text-primary"></i>1. Seleccionar Transportadora
                    </div>
                    <div class="card-body">
                        <?php if (empty($transportadoras)): ?>
                            <div class="text-center text-muted py-3">
                                <i class="bi bi-info-circle fs-4"></i>
                                <p class="mt-2">Não existem transportadoras disponíveis para nova parceria.<br>
                                <small>Todas as transportadoras já têm parceria activa ou pendente com a sua empresa.</small></p>
                            </div>
                        <?php else: ?>
                            <div class="row g-2" id="transportadorasGrid">
                                <?php foreach ($transportadoras as $t): ?>
                                    <div class="col-md-6">
                                        <label class="transportadora-card card p-3 w-100 mb-0"
                                               data-id="<?php echo $t['id']; ?>">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div>
                                                    <div class="fw-semibold">
                                                        <?php echo htmlspecialchars($t['nome_empresa']); ?>
                                                        <?php if ($t['verificada']): ?>
                                                            <i class="bi bi-patch-check-fill text-primary ms-1" title="Verificada"></i>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="small text-muted">
                                                        <i class="bi bi-geo-alt"></i>
                                                        <?php echo htmlspecialchars($t['cidade'] ?? ''); ?>
                                                        <?php if ($t['provincia']): ?>, <?php echo htmlspecialchars($t['provincia']); ?><?php endif; ?>
                                                    </div>
                                                </div>
                                                <div class="text-end">
                                                    <div class="text-warning small">
                                                        <i class="bi bi-star-fill"></i>
                                                        <?php echo number_format((float)$t['avaliacao_media'], 1); ?>
                                                    </div>
                                                    <div class="small text-muted"><?php echo (int)$t['total_missoes']; ?> missões</div>
                                                </div>
                                            </div>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <input type="hidden" name="transportador_id" id="transportador_id" value="" required>
                            <div id="erroTransportadora" class="text-danger small mt-2" style="display:none">
                                Seleccione uma transportadora.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 2. Termos do contrato -->
                <div class="card mb-4">
                    <div class="card-header fw-semibold">
                        <i class="bi bi-file-earmark-text me-2 text-primary"></i>2. Termos do Contrato Profissional
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Data de Início <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="data_inicio"
                                       min="<?php echo date('Y-m-d'); ?>"
                                       value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Data de Fim <span class="text-muted small">(opcional)</span></label>
                                <input type="date" class="form-control" name="data_fim">
                                <div class="form-text">Deixe em branco para indeterminado.</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Tipo de Contrato <span class="text-danger">*</span></label>
                                <select class="form-select" name="tipo_contrato" required>
                                    <option value="por_missao">Por Missão</option>
                                    <option value="por_km">Por Quilómetro</option>
                                    <option value="mensalidade">Mensalidade</option>
                                    <option value="misto">Misto (Base + KM)</option>
                                    <option value="tabela">Tabela de Preços</option>
                                </select>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label">Valor por Missão (MT)</label>
                                <input type="number" step="0.01" class="form-control" name="valor_missao" placeholder="Ex: 15000">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Valor por KM (MT)</label>
                                <input type="number" step="0.0001" class="form-control" name="valor_km" placeholder="Ex: 2.50">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Valor Mensal (MT)</label>
                                <input type="number" step="0.01" class="form-control" name="valor_mensal" placeholder="Ex: 50000">
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Comissão Plataforma (%)</label>
                                <input type="number" step="0.01" class="form-control" name="comissao_plataforma_pct" value="0">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">SLA Resposta (horas)</label>
                                <input type="number" class="form-control" name="sla_resposta_horas" value="24">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Penalidade Atraso (%)</label>
                                <input type="number" step="0.01" class="form-control" name="penalidade_atraso_pct" value="0">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Condições Pagamento</label>
                                <select class="form-select" name="condicoes_pagamento">
                                    <option value="30_dias">30 dias</option>
                                    <option value="15_dias">15 dias</option>
                                    <option value="7_dias">7 dias</option>
                                    <option value="a_entrega">À entrega</option>
                                    <option value="antecipado">Antecipado</option>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Responsabilidade da Carga</label>
                                <select class="form-select" name="responsabilidade_carga">
                                    <option value="seguro">Seguro</option>
                                    <option value="contratante">Contratante</option>
                                    <option value="transportador">Transportador</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check form-switch mt-4">
                                    <input class="form-check-input" type="checkbox" name="exclusiva" id="exclusiva" checked>
                                    <label class="form-check-label" for="exclusiva"><strong>Parceria Exclusiva</strong></label>
                                </div>
                                <div class="form-check form-switch mt-2">
                                    <input class="form-check-input" type="checkbox" name="requer_validacao_admin" id="req_admin">
                                    <label class="form-check-label" for="req_admin"><strong>Requer validação do Admin</strong></label>
                                </div>
                            </div>

                            <div class="col-12">
                                <label class="form-label">Tipos de Carga Permitidos</label>
                                <textarea class="form-control" name="tipos_carga_permitidos" rows="2" placeholder="Ex: carga_geral, refrigerada, perigosa, graneis..."></textarea>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Rotas Cobertas</label>
                                <textarea class="form-control" name="rotas_cobertas" rows="2" placeholder="Ex: Maputo-Nampula, Beira-Tete, Nacional..."></textarea>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Descrição / Condições Especiais</label>
                                <textarea class="form-control" name="descricao" rows="3" placeholder="Descreva os termos da parceria, tipo de cargas, rotas previstas, condições especiais..."></textarea>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Observações de Negociação</label>
                                <textarea class="form-control" name="observacoes_negociacao" rows="2" placeholder="Notas internas sobre a negociação..."></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-between">
                    <a href="parcerias.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-1"></i> Cancelar
                    </a>
                    <button type="submit" class="btn btn-primary" <?php echo empty($transportadoras) ? 'disabled' : ''; ?>>
                        <i class="bi bi-send me-1"></i> Enviar Proposta
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('.transportadora-card').forEach(card => {
    card.addEventListener('click', () => {
        document.querySelectorAll('.transportadora-card').forEach(c => c.classList.remove('selected'));
        card.classList.add('selected');
        document.getElementById('transportador_id').value = card.dataset.id;
        document.getElementById('erroTransportadora').style.display = 'none';
    });
});

document.querySelector('form').addEventListener('submit', e => {
    const val = document.getElementById('transportador_id').value;
    if (!val) {
        e.preventDefault();
        document.getElementById('erroTransportadora').style.display = 'block';
    }
});
</script>
</body>
</html>
