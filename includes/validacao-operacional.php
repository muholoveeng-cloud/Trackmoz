<?php
/**
 * Validação operacional pré-missão
 * Verifica: veículo activo, documentos válidos, motorista activo, CNH válida, seguro, inspecção
 */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/frota-helpers.php';

/**
 * Retorna array com 'ok' => bool e 'erros' => array de strings
 */
function validar_operacional_missao(PDO $conn, int $caminhoneiroId, ?int $veiculoId = null): array
{
    $erros = [];

    // 1. Verificar perfil do caminhoneiro
    $stmt = $conn->prepare(
        "SELECT disponibilidade, numero_cnh, validade_cnh, placa_veiculo, tipo_veiculo
         FROM perfil_caminhoneiro WHERE usuario_id = :uid"
    );
    $stmt->execute([':uid' => $caminhoneiroId]);
    $perfil = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$perfil) {
        $erros[] = 'Perfil de caminhoneiro incompleto. Complete o seu perfil antes de iniciar missões.';
        return ['ok' => false, 'erros' => $erros];
    }

    $disp = $perfil['disponibilidade'] ?? 'disponivel';
    if (in_array($disp, ['bloqueado', 'inativo', 'indisponivel', 'manutencao'], true)) {
        $erros[] = 'O seu perfil não está disponível para operar (' . $disp . '). Actualize a disponibilidade no perfil.';
    }

    // 2. CNH válida
    if (empty($perfil['numero_cnh'])) {
        $erros[] = 'Número da CNH não registado no perfil.';
    }
    if (!empty($perfil['validade_cnh']) && $perfil['validade_cnh'] !== '0000-00-00'
        && strtotime($perfil['validade_cnh']) < strtotime('+7 days')) {
        $erros[] = 'A sua CNH está próxima de expirar ou já expirou. Renove antes de iniciar missões.';
    }

    // 3. Documentos do motorista (certificações, exame médico)
    try {
        $stmt = $conn->prepare(
            "SELECT tipo, data_validade FROM motorista_documentos
             WHERE motorista_id = :mid AND data_validade < DATE_ADD(CURDATE(), INTERVAL 7 DAY)"
        );
        $stmt->execute([':mid' => $caminhoneiroId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $erros[] = 'Documento do motorista "' . ($d['tipo'] ?? 'documento') . '" expirado ou a expirar em breve.';
        }
    } catch (Throwable $e) {
        // Tabela opcional — ignorar se não existir
    }

    // 4. Veículo (frota, proprietário ou matrícula no perfil)
    $veiculo = motorista_resolver_veiculo($conn, $caminhoneiroId, $veiculoId);

    if (!$veiculo) {
        $erros[] = 'Nenhum veículo associado ao seu perfil. Registe a matrícula no perfil ou contacte o transportador.';
    } else {
        if (($veiculo['estado_operacional'] ?? 'ativo') !== 'ativo') {
            $erros[] = 'O veículo "' . e($veiculo['matricula'] ?? '') . '" não está activo (estado: '
                . e($veiculo['estado_operacional'] ?? 'desconhecido') . ').';
        }

        $veiculoIdReal = (int)($veiculo['id'] ?? 0);
        if ($veiculoIdReal > 0) {
            try {
                $stmt = $conn->prepare(
                    "SELECT tipo, data_validade FROM veiculo_documentos
                     WHERE veiculo_id = :vid AND data_validade < DATE_ADD(CURDATE(), INTERVAL 7 DAY)"
                );
                $stmt->execute([':vid' => $veiculoIdReal]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
                    $dias = ceil((strtotime($d['data_validade']) - time()) / 86400);
                    $erros[] = 'Documento do veículo "' . e($d['tipo'] ?? '') . '" expirado'
                        . ($dias >= 0 ? ' (faltam ' . $dias . ' dias)' : '')
                        . '. Matrícula: ' . e($veiculo['matricula'] ?? '');
                }
            } catch (Throwable $e) {
                // Tabela opcional
            }
        }
    }

    return ['ok' => empty($erros), 'erros' => $erros, 'veiculo' => $veiculo];
}
