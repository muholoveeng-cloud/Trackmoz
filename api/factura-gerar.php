<?php
/**
 * API: Gerar factura a partir de missão concluída
 * POST: missao_id, csrf_token
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/regras-negocio.php';

session_start();

$uid = (int)($_SESSION['user_id'] ?? 0);
$tipo = $_SESSION['user_type'] ?? '';

if (!$uid || !in_array($tipo, ['empresa','transportador','admin'], true)) {
    echo json_encode(['success' => false, 'message' => 'Não autorizado.']);
    exit;
}

require_csrf_json();

$missao_id = (int)($_POST['missao_id'] ?? 0);
if ($missao_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Missão inválida.']);
    exit;
}

try {
    $conn = getConnection();

    $stmt = $conn->prepare(
        "SELECT m.*, p.tipo_contrato, p.valor_missao, p.valor_km, p.comissao_plataforma_pct, p.condicoes_pagamento,
                p.empresa_id AS parceria_empresa_id, p.transportador_id AS parceria_transportador_id
         FROM missoes m
         LEFT JOIN parcerias p ON m.parceria_id = p.id
         WHERE m.id = :id"
    );
    $stmt->execute([':id' => $missao_id]);
    $missao = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$missao) {
        echo json_encode(['success' => false, 'message' => 'Missão não encontrada.']);
        exit;
    }

    $docCheck = validar_missao_gera_documento_final($conn, $missao_id);
    if (!$docCheck['ok']) {
        echo json_encode(['success' => false, 'message' => regras_erro_mensagem($docCheck)]);
        exit;
    }

    // Verificar permissão
    if ($tipo === 'empresa' && (int)$missao['empresa_id'] !== $uid) {
        echo json_encode(['success' => false, 'message' => 'Sem permissão.']);
        exit;
    }
    if ($tipo === 'transportador' && (int)$missao['transportador_id'] !== $uid) {
        echo json_encode(['success' => false, 'message' => 'Sem permissão.']);
        exit;
    }

    // Verificar se já existe factura
    $chk = $conn->prepare("SELECT id FROM facturas WHERE missao_id = :mid LIMIT 1");
    $chk->execute([':mid' => $missao_id]);
    if ($chk->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Já existe uma factura para esta missão.']);
        exit;
    }

    $empresa_id = (int)$missao['empresa_id'];
    $transportador_id = (int)$missao['transportador_id'];
    $parceria_id = $missao['parceria_id'] ? (int)$missao['parceria_id'] : null;

    // Calcular valores
    $tipoContrato = $missao['tipo_contrato'] ?? 'por_missao';
    $valorBase = (float)($missao['valor_proposto'] ?? 0);
    $valorKm = 0;
    $distanciaKm = (float)($missao['distancia_km'] ?? 0);
    $comissaoPct = (float)($missao['comissao_plataforma_pct'] ?? 0);

    if ($parceria_id && $missao['valor_missao'] !== null) {
        $valorBase = (float)$missao['valor_missao'];
    }
    if ($parceria_id && $missao['valor_km'] !== null && $distanciaKm > 0) {
        $valorKm = (float)$missao['valor_km'] * $distanciaKm;
    }

    $comissao = round(($valorBase + $valorKm) * ($comissaoPct / 100), 2);
    $imposto = round(($valorBase + $valorKm) * 0.16, 2); // 16% IVA exemplo
    $valorTotal = round($valorBase + $valorKm + $imposto - $comissao, 2);

    $numeroFactura = 'FAC-' . date('Y') . '-' . str_pad((string)$missao_id, 6, '0', STR_PAD_LEFT);

    // Vencimento baseado nas condições
    $condPag = $missao['condicoes_pagamento'] ?? '30_dias';
    $dias = match($condPag) {
        '15_dias' => 15, '7_dias' => 7, 'a_entrega' => 0, 'antecipado' => -1, default => 30
    };
    $dataVencimento = $dias >= 0 ? date('Y-m-d', strtotime("+{$dias} days")) : date('Y-m-d');

    $conn->prepare(
        "INSERT INTO facturas
         (missao_id, parceria_id, empresa_id, transportador_id, numero_factura, data_emissao, data_vencimento,
          descricao_servico, origem, destino, distancia_km, valor_base, valor_km, comissao_plataforma, imposto, valor_total, status)
         VALUES
         (:mid, :pid, :eid, :tid, :num, NOW(), :ven, :desc, :orig, :dest, :dkm, :vbase, :vkm, :com, :imp, :tot, 'emitida')"
    )->execute([
        ':mid' => $missao_id, ':pid' => $parceria_id, ':eid' => $empresa_id, ':tid' => $transportador_id,
        ':num' => $numeroFactura, ':ven' => $dataVencimento, ':desc' => $missao['titulo'],
        ':orig' => $missao['origem'], ':dest' => $missao['destino'], ':dkm' => $distanciaKm ?: null,
        ':vbase' => $valorBase, ':vkm' => $valorKm, ':com' => $comissao, ':imp' => $imposto, ':tot' => $valorTotal
    ]);

    $factura_id = (int)$conn->lastInsertId();

    // Criar pagamento pendente
    $conn->prepare(
        "INSERT INTO pagamentos_missao
         (missao_id, factura_id, parceria_id, empresa_id, transportador_id, tipo_pagamento, valor_base, valor_km, distancia_km,
          comissao_plataforma, imposto, valor_total, status, data_vencimento)
         VALUES
         (:mid, :fid, :pid, :eid, :tid, :tpag, :vbase, :vkm, :dkm, :com, :imp, :tot, 'aguardando_pagamento', :ven)"
    )->execute([
        ':mid' => $missao_id, ':fid' => $factura_id, ':pid' => $parceria_id,
        ':eid' => $empresa_id, ':tid' => $transportador_id,
        ':tpag' => $tipoContrato, ':vbase' => $valorBase, ':vkm' => $valorKm,
        ':dkm' => $distanciaKm ?: null, ':com' => $comissao, ':imp' => $imposto, ':tot' => $valorTotal, ':ven' => $dataVencimento
    ]);

    // Notificar transportador
    $conn->prepare(
        "INSERT INTO notificacoes (usuario_id, tipo, titulo, mensagem, link)
         VALUES (:uid, 'factura', 'Factura Emitida', :msg, '')"
    )->execute([
        ':uid' => $transportador_id,
        ':msg' => "Foi emitida a factura {$numeroFactura} no valor de " . number_format($valorTotal, 2, ',', '.') . " MT."
    ]);

    registrar_log($conn, $uid, 'criar', 'factura', $factura_id, "Factura {$numeroFactura} gerada para missao {$missao_id}");
    echo json_encode(['success' => true, 'message' => 'Factura gerada com sucesso.', 'factura_id' => $factura_id]);

} catch (Throwable $e) {
    error_log('factura-gerar: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro interno.']);
}
