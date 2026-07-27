<?php
/**
 * Sistema de Alertas Automáticos para Frota
 * TrackMoz
 * 
 * Este helper fornece funções para gerir alertas de:
 * - Manutenção preventiva (baseada em quilometragem)
 * - Seguro expirado
 * - Inspecção expirada
 * - Documentos a vencer
 */

/**
 * Verifica alertas de frota para um transportador
 * 
 * @param PDO $conn Conexão com o banco de dados
 * @param int $transportador_id ID do transportador
 * @return array ['manutencao' => int, 'seguro' => int, 'inspecao' => int, 'documentos' => int]
 */
function verificar_alertas_frota(PDO $conn, int $transportador_id): array
{
    $alertas = [
        'manutencao' => 0,
        'seguro' => 0,
        'inspecao' => 0,
        'documentos' => 0
    ];
    
    try {
        // Alertas de manutenção (quilometragem próxima do limite)
        $stmt = $conn->prepare(
            "SELECT COUNT(*) 
             FROM veiculos v
             WHERE v.proprietario_id = :id 
             AND v.proprietario_tipo = 'transportador'
             AND v.estado_operacional = 'ativo'
             AND v.km_atual > 0
             AND v.km_manutencao > 0
             AND (v.km_manutencao - v.km_atual) <= 1000"
        );
        $stmt->execute([':id' => $transportador_id]);
        $alertas['manutencao'] = (int)$stmt->fetchColumn();
        
        // Alertas de seguro (a vencer em 30 dias)
        $stmt = $conn->prepare(
            "SELECT COUNT(*) 
             FROM veiculo_documentos vd
             JOIN veiculos v ON vd.veiculo_id = v.id
             WHERE v.proprietario_id = :id 
             AND v.proprietario_tipo = 'transportador'
             AND vd.tipo_documento = 'seguro'
             AND vd.data_validade < DATE_ADD(CURDATE(), INTERVAL 30 DAY)"
        );
        $stmt->execute([':id' => $transportador_id]);
        $alertas['seguro'] = (int)$stmt->fetchColumn();
        
        // Alertas de inspecção (a vencer em 30 dias)
        $stmt = $conn->prepare(
            "SELECT COUNT(*) 
             FROM veiculo_documentos vd
             JOIN veiculos v ON vd.veiculo_id = v.id
             WHERE v.proprietario_id = :id 
             AND v.proprietario_tipo = 'transportador'
             AND vd.tipo_documento = 'inspecao'
             AND vd.data_validade < DATE_ADD(CURDATE(), INTERVAL 30 DAY)"
        );
        $stmt->execute([':id' => $transportador_id]);
        $alertas['inspecao'] = (int)$stmt->fetchColumn();
        
        // Alertas de documentos gerais (a vencer em 30 dias)
        $stmt = $conn->prepare(
            "SELECT COUNT(*) 
             FROM veiculo_documentos vd
             JOIN veiculos v ON vd.veiculo_id = v.id
             WHERE v.proprietario_id = :id 
             AND v.proprietario_tipo = 'transportador'
             AND vd.data_validade < DATE_ADD(CURDATE(), INTERVAL 30 DAY)"
        );
        $stmt->execute([':id' => $transportador_id]);
        $alertas['documentos'] = (int)$stmt->fetchColumn();
        
    } catch (PDOException $e) {
        error_log('Erro verificar_alertas_frota: ' . $e->getMessage());
    }
    
    return $alertas;
}

/**
 * Retorna detalhes de alertas de frota
 * 
 * @param PDO $conn Conexão com o banco de dados
 * @param int $transportador_id ID do transportador
 * @return array Array de alertas com detalhes
 */
function obter_detalhes_alertas_frota(PDO $conn, int $transportador_id): array
{
    $alertas = [];
    
    try {
        // Manutenção preventiva
        $stmt = $conn->prepare(
            "SELECT v.id, v.matricula, v.marca, v.modelo, v.km_atual, v.km_manutencao,
                    (v.km_manutencao - v.km_atual) AS km_restantes
             FROM veiculos v
             WHERE v.proprietario_id = :id 
             AND v.proprietario_tipo = 'transportador'
             AND v.estado_operacional = 'ativo'
             AND v.km_atual > 0
             AND v.km_manutencao > 0
             AND (v.km_manutencao - v.km_atual) <= 1000
             ORDER BY (v.km_manutencao - v.km_atual) ASC"
        );
        $stmt->execute([':id' => $transportador_id]);
        $manutencao = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($manutencao as $m) {
            $alertas[] = [
                'tipo' => 'manutencao',
                'gravidade' => $m['km_restantes'] <= 100 ? 'alta' : 'media',
                'veiculo' => $m['matricula'],
                'descricao' => "Manutenção preventiva em {$m['km_restantes']} km (atual: {$m['km_atual']} km)",
                'acao' => 'Agendar manutenção',
                'link' => BASE_URL . '/pages/transportador/veiculo-detalhes.php?id=' . $m['id']
            ];
        }
        
        // Documentos a vencer
        $stmt = $conn->prepare(
            "SELECT vd.*, v.matricula, v.marca, v.modelo,
                    DATEDIFF(vd.data_validade, CURDATE()) AS dias_restantes
             FROM veiculo_documentos vd
             JOIN veiculos v ON vd.veiculo_id = v.id
             WHERE v.proprietario_id = :id 
             AND v.proprietario_tipo = 'transportador'
             AND vd.data_validade < DATE_ADD(CURDATE(), INTERVAL 30 DAY)
             ORDER BY vd.data_validade ASC"
        );
        $stmt->execute([':id' => $transportador_id]);
        $documentos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($documentos as $d) {
            $gravidade = $d['dias_restantes'] < 0 ? 'critica' : 
                        ($d['dias_restantes'] <= 7 ? 'alta' : 'media');
            
            $alertas[] = [
                'tipo' => 'documento',
                'subtipo' => $d['tipo_documento'],
                'gravidade' => $gravidade,
                'veiculo' => $d['matricula'],
                'descricao' => "{$d['tipo_documento']} " . ($d['dias_restantes'] < 0 ? 'expirado' : "expira em {$d['dias_restantes']} dias"),
                'acao' => 'Actualizar documento',
                'link' => BASE_URL . '/pages/transportador/veiculo-detalhes.php?id=' . $d['veiculo_id']
            ];
        }
        
    } catch (PDOException $e) {
        error_log('Erro obter_detalhes_alertas_frota: ' . $e->getMessage());
    }
    
    return $alertas;
}

/**
 * Marca veículo como em manutenção
 * 
 * @param PDO $conn Conexão com o banco de dados
 * @param int $veiculo_id ID do veículo
 * @return bool Sucesso
 */
function marcar_manutencao(PDO $conn, int $veiculo_id): bool
{
    try {
        $stmt = $conn->prepare(
            "UPDATE veiculos 
             SET estado_operacional = 'manutencao',
                 data_ultima_manutencao = CURDATE()
             WHERE id = :id"
        );
        return $stmt->execute([':id' => $veiculo_id]);
    } catch (PDOException $e) {
        error_log('Erro marcar_manutencao: ' . $e->getMessage());
        return false;
    }
}

/**
 * Conclui manutenção de veículo
 * 
 * @param PDO $conn Conexão com o banco de dados
 * @param int $veiculo_id ID do veículo
 * @param int $novo_km Nova quilometragem
 * @param int $proxima_manutencao_km Próxima manutenção em km
 * @return bool Sucesso
 */
function concluir_manutencao(PDO $conn, int $veiculo_id, int $novo_km, int $proxima_manutencao_km): bool
{
    try {
        $conn->beginTransaction();
        
        $stmt = $conn->prepare(
            "UPDATE veiculos 
             SET estado_operacional = 'ativo',
                 km_atual = :novo_km,
                 km_manutencao = :proxima_manutencao,
                 data_ultima_manutencao = CURDATE()
             WHERE id = :id"
        );
        $stmt->execute([
            ':novo_km' => $novo_km,
            ':proxima_manutencao' => $proxima_manutencao_km,
            ':id' => $veiculo_id
        ]);
        
        $conn->commit();
        return true;
    } catch (PDOException $e) {
        $conn->rollBack();
        error_log('Erro concluir_manutencao: ' . $e->getMessage());
        return false;
    }
}
