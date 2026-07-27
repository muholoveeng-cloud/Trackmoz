<?php
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');
include_once('../../includes/auth.php');
include_once('../../includes/helpers.php');
include_once('../../includes/regras-negocio.php');
include_once('../../includes/documentos-registry.php');

require_role(['empresa'], '../login.php');

$empresa_id = (int)$_SESSION['user_id'];
$erro = '';
$sucesso = '';

// Buscar parcerias activas da empresa
$parceriasAtivas = [];
try {
    $stmt = $conn->prepare(
        "SELECT p.*, pt.nome_empresa AS transportador_nome
         FROM parcerias p
         JOIN perfil_transportador pt ON p.transportador_id = pt.usuario_id
         WHERE p.empresa_id = :eid AND p.status = 'ativa'
         ORDER BY pt.nome_empresa"
    );
    $stmt->execute([':eid' => $empresa_id]);
    $parceriasAtivas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Erro ao buscar parcerias: ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $titulo = htmlspecialchars(trim($_POST['titulo'] ?? ''), ENT_QUOTES, 'UTF-8');
    $descricao = htmlspecialchars(trim($_POST['descricao'] ?? ''), ENT_QUOTES, 'UTF-8');
    $origem = htmlspecialchars(trim($_POST['origem'] ?? ''), ENT_QUOTES, 'UTF-8');
    $destino = htmlspecialchars(trim($_POST['destino'] ?? ''), ENT_QUOTES, 'UTF-8');
    $tipo_carga = htmlspecialchars(trim($_POST['tipo_carga'] ?? ''), ENT_QUOTES, 'UTF-8');
    $peso_carga = $_POST['peso_carga'] !== '' ? (float)$_POST['peso_carga'] : null;
    $valor_proposto = $_POST['valor_proposto'] !== '' ? (float)$_POST['valor_proposto'] : null;
    $prazo_entrega = $_POST['prazo_entrega'] ?? '';
    $parceria_id = !empty($_POST['parceria_id']) ? (int)$_POST['parceria_id'] : null;
    $volume_m3 = $_POST['volume_m3'] !== '' ? (float)$_POST['volume_m3'] : null;
    $instrucoes = htmlspecialchars(trim($_POST['instrucoes_especiais'] ?? ''), ENT_QUOTES, 'UTF-8');

    $pubCheck = validar_empresa_pode_publicar($conn, $empresa_id);
    if (!$pubCheck['ok']) {
        $erro = regras_erro_mensagem($pubCheck);
    } elseif (empty($titulo) || empty($origem) || empty($destino) || empty($tipo_carga) || $peso_carga === null || $valor_proposto === null || empty($prazo_entrega)) {
        $erro = "Preencha todos os campos obrigatórios (título, origem, destino, tipo de carga, peso, valor e prazo).";
    } elseif ($valor_proposto <= 0 || $peso_carga <= 0) {
        $erro = "O valor e o peso devem ser maiores que zero.";
    } elseif ($parceria_id) {
        // Validar parceria
        $stmt = $conn->prepare("SELECT * FROM parcerias WHERE id = :pid AND empresa_id = :eid AND status = 'ativa'");
        $stmt->execute([':pid' => $parceria_id, ':eid' => $empresa_id]);
        $parceria = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$parceria) {
            $erro = "Parceria seleccionada não é válida ou não está activa.";
        } else {
            // Validar tipo de carga
            $tiposPermitidos = array_map('trim', explode(',', $parceria['tipos_carga_permitidos'] ?? ''));
            if (!empty($tiposPermitidos[0]) && !in_array($tipo_carga, $tiposPermitidos, true)) {
                $erro = "Tipo de carga não permitido no contrato. Permitidos: " . $parceria['tipos_carga_permitidos'];
            }
            // Validar rota
            $rotas = array_map('trim', explode(',', $parceria['rotas_cobertas'] ?? ''));
            $rotaValida = true;
            if (!empty($rotas[0])) {
                $rotaValida = false;
                foreach ($rotas as $rota) {
                    if (stripos($origem . '-' . $destino, $rota) !== false || stripos($destino . '-' . $origem, $rota) !== false) {
                        $rotaValida = true; break;
                    }
                }
            }
            if (!$rotaValida) {
                $erro = "Rota não coberta pelo contrato. Rotas: " . $parceria['rotas_cobertas'];
            }
        }
    }

    if (empty($erro)) {
        try {
            // Garantir status de parceria no ENUM (só se ainda não existir)
            if ($parceria_id) {
                try {
                    $stCol = $conn->query("SHOW COLUMNS FROM missoes LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
                    $type = (string)($stCol['Type'] ?? '');
                    if ($type !== '' && stripos($type, 'aguardando_aceitacao_transportadora') === false) {
                        $conn->exec(
                            "ALTER TABLE missoes MODIFY COLUMN status ENUM(
                                'aberta','em_negociacao','aceita','em_andamento','em_transito','em_entrega',
                                'emergencia_reportada','aguardando_confirmacao','entrega_confirmada',
                                'concluida','cancelada','emergencia','aguardando_aceitacao_transportadora'
                            ) NULL"
                        );
                    }
                } catch (Throwable $e) {
                    // ignore — fallback abaixo
                }
            }

            $status = $parceria_id ? 'aguardando_aceitacao_transportadora' : 'aberta';
            $transportador_id = $parceria_id ? (int)$parceria['transportador_id'] : null;

            // A coluna real na BD é `valor` (não valor_proposto)
            $sql = "INSERT INTO missoes (
                        empresa_id, transportador_id, parceria_id, titulo, descricao, origem, destino,
                        tipo_carga, peso_carga, volume_m3, valor, prazo_entrega, instrucoes_especiais,
                        status, data_criacao
                    ) VALUES (
                        :empresa_id, :transportador_id, :parceria_id, :titulo, :descricao, :origem, :destino,
                        :tipo_carga, :peso_carga, :volume_m3, :valor, :prazo_entrega, :instrucoes,
                        :status, NOW()
                    )";
            $stmt = $conn->prepare($sql);
            try {
                $stmt->execute([
                    ':empresa_id' => $empresa_id,
                    ':transportador_id' => $transportador_id,
                    ':parceria_id' => $parceria_id,
                    ':titulo' => $titulo,
                    ':descricao' => $descricao,
                    ':origem' => $origem,
                    ':destino' => $destino,
                    ':tipo_carga' => $tipo_carga,
                    ':peso_carga' => $peso_carga,
                    ':volume_m3' => $volume_m3,
                    ':valor' => $valor_proposto,
                    ':prazo_entrega' => $prazo_entrega,
                    ':instrucoes' => $instrucoes ?: null,
                    ':status' => $status,
                ]);
            } catch (PDOException $eStatus) {
                // Fallback se o ENUM ainda não aceitar o status de parceria
                if ($parceria_id && str_contains($eStatus->getMessage(), 'aguardando_aceitacao_transportadora')) {
                    $status = 'aceita';
                    $stmt->execute([
                        ':empresa_id' => $empresa_id,
                        ':transportador_id' => $transportador_id,
                        ':parceria_id' => $parceria_id,
                        ':titulo' => $titulo,
                        ':descricao' => $descricao,
                        ':origem' => $origem,
                        ':destino' => $destino,
                        ':tipo_carga' => $tipo_carga,
                        ':peso_carga' => $peso_carga,
                        ':volume_m3' => $volume_m3,
                        ':valor' => $valor_proposto,
                        ':prazo_entrega' => $prazo_entrega,
                        ':instrucoes' => $instrucoes ?: null,
                        ':status' => $status,
                    ]);
                } else {
                    throw $eStatus;
                }
            }
            $missao_id = (int)$conn->lastInsertId();

            // Registo oficial da missão no explorador de documentos
            try {
                tmz_docs_criar_registo_missao(
                    $conn,
                    $missao_id,
                    $empresa_id,
                    $empresa_id,
                    [
                        'titulo' => $titulo,
                        'origem' => $origem,
                        'destino' => $destino,
                        'valor' => $valor_proposto,
                        'parceria_id' => $parceria_id,
                    ],
                    $transportador_id
                );
            } catch (Throwable $e) {
                error_log('Automação docs publicar_missao: ' . $e->getMessage());
            }

            if ($transportador_id) {
                try {
                    $conn->prepare(
                        "INSERT INTO notificacoes (usuario_id, tipo, titulo, mensagem, link, data_criacao, lida)
                         VALUES (:uid, 'missao', 'Nova Missão Recebida', :msg, :link, NOW(), 0)"
                    )->execute([
                        ':uid'  => $transportador_id,
                        ':msg'  => "Recebeu uma nova missão via parceria: {$titulo}. Origem: {$origem} → Destino: {$destino}",
                        ':link' => BASE_URL . '/pages/transportador/missoes.php',
                    ]);
                } catch (Throwable $e) {
                    error_log('Notif publicar missao: ' . $e->getMessage());
                }
            }

            try {
                registrar_log($conn, $empresa_id, 'criar', 'missao', $missao_id, 'Missao publicada' . ($parceria_id ? ' via parceria' : ''));
            } catch (Throwable $e) {
                error_log('Log publicar missao: ' . $e->getMessage());
            }

            $sucesso = "Missão publicada com sucesso!" . ($parceria_id ? " Enviada directamente à transportadora parceira." : "");
            $_POST = array();
        } catch (PDOException $e) {
            error_log('Erro publicar missao: ' . $e->getMessage());
            $erro = "Erro ao publicar a missão. Tente novamente.";
            if (defined('APP_DEBUG') && APP_DEBUG) {
                $erro .= ' (' . $e->getMessage() . ')';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Publicar Missão — TrackMoz</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css"></head>
<body>
<?php include_once('../../includes/menu.php'); ?>
<div class="container mt-4">
    <div class="row justify-content-center">
        <div class="col-lg-9">
            <div class="d-flex align-items-center mb-4 gap-3">
                <a href="missoes.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
                <div><h4 class="mb-0">Publicar Nova Missão</h4><p class="text-muted small mb-0">Preencha os dados da missão</p></div>
            </div>
            <?php if ($erro): ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i><?php echo $erro; ?></div><?php endif; ?>
            <?php if ($sucesso): ?><div class="alert alert-success"><i class="bi bi-check-circle me-1"></i><?php echo $sucesso; ?></div><?php endif; ?>

            <form method="POST" class="card border-0 shadow-sm">
                <div class="card-body">
                    <!-- Parceria -->
                    <?php if (!empty($parceriasAtivas)): ?>
                        <div class="mb-4">
                            <label class="form-label fw-semibold"><i class="bi bi-handshake me-2 text-primary"></i>Enviar via Parceria <span class="text-muted small">(opcional)</span></label>
                            <select class="form-select" name="parceria_id" id="parceriaSelect">
                                <option value="">Publicar no feed público</option>
                                <?php foreach ($parceriasAtivas as $pa): ?>
                                    <option value="<?php echo (int)$pa['id']; ?>" data-tipo="<?php echo e($pa['tipo_contrato']); ?>" data-valor-missao="<?php echo e($pa['valor_missao'] ?? ''); ?>" data-valor-km="<?php echo e($pa['valor_km'] ?? ''); ?>">
                                        <?php echo e($pa['transportador_nome']); ?> — <?php echo e($pa['tipo_contrato']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Se seleccionar uma parceria, a missão será enviada directamente à transportadora.</div>
                        </div>
                    <?php endif; ?>

                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Título da Missão *</label>
                            <input type="text" class="form-control" name="titulo" required value="<?php echo isset($_POST['titulo']) ? e($_POST['titulo']) : ''; ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Descrição</label>
                            <textarea class="form-control" name="descricao" rows="2"><?php echo isset($_POST['descricao']) ? e($_POST['descricao']) : ''; ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Origem *</label>
                            <input type="text" class="form-control" name="origem" required value="<?php echo isset($_POST['origem']) ? e($_POST['origem']) : ''; ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Destino *</label>
                            <input type="text" class="form-control" name="destino" required value="<?php echo isset($_POST['destino']) ? e($_POST['destino']) : ''; ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Tipo de Carga *</label>
                            <select class="form-select" name="tipo_carga" required>
                                <option value="">Seleccione...</option>
                                <option value="geral" <?php echo (isset($_POST['tipo_carga']) && $_POST['tipo_carga'] == 'geral') ? 'selected' : ''; ?>>Carga Geral</option>
                                <option value="granel" <?php echo (isset($_POST['tipo_carga']) && $_POST['tipo_carga'] == 'granel') ? 'selected' : ''; ?>>Granel</option>
                                <option value="refrigerada" <?php echo (isset($_POST['tipo_carga']) && $_POST['tipo_carga'] == 'refrigerada') ? 'selected' : ''; ?>>Refrigerada</option>
                                <option value="perigosa" <?php echo (isset($_POST['tipo_carga']) && $_POST['tipo_carga'] == 'perigosa') ? 'selected' : ''; ?>>Carga Perigosa</option>
                                <option value="fragil" <?php echo (isset($_POST['tipo_carga']) && $_POST['tipo_carga'] == 'fragil') ? 'selected' : ''; ?>>Frágil</option>
                                <option value="materiais_construcao" <?php echo (isset($_POST['tipo_carga']) && $_POST['tipo_carga'] == 'materiais_construcao') ? 'selected' : ''; ?>>Materiais de Construção</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Peso (kg) *</label>
                            <input type="number" step="0.01" class="form-control" name="peso_carga" required value="<?php echo isset($_POST['peso_carga']) ? e($_POST['peso_carga']) : ''; ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Volume (m³)</label>
                            <input type="number" step="0.001" class="form-control" name="volume_m3" value="<?php echo isset($_POST['volume_m3']) ? e($_POST['volume_m3']) : ''; ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Valor Proposto (MT) *</label>
                            <input type="number" step="0.01" class="form-control" name="valor_proposto" id="valorProposto" required value="<?php echo isset($_POST['valor_proposto']) ? e($_POST['valor_proposto']) : ''; ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Prazo de Entrega *</label>
                            <input type="date" class="form-control" name="prazo_entrega" required value="<?php echo isset($_POST['prazo_entrega']) ? e($_POST['prazo_entrega']) : ''; ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Instruções Especiais</label>
                            <textarea class="form-control" name="instrucoes_especiais" rows="2"><?php echo isset($_POST['instrucoes_especiais']) ? e($_POST['instrucoes_especiais']) : ''; ?></textarea>
                        </div>
                    </div>
                    <div class="d-flex justify-content-between mt-4">
                        <a href="missoes.php" class="btn btn-outline-secondary">Cancelar</a>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i>Publicar Missão</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('parceriaSelect')?.addEventListener('change', function() {
    const opt = this.options[this.selectedIndex];
    const tipo = opt.dataset.tipo;
    const valorMissao = parseFloat(opt.dataset.valorMissao);
    if (tipo === 'por_missao' && valorMissao > 0) {
        document.getElementById('valorProposto').value = valorMissao.toFixed(2);
    }
});
</script>
</body></html>