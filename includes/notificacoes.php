<?php
/**
 * Sistema de Notificações para Prazos Próximos
 * TrackMoz
 * 
 * Este helper fornece funções para gerir notificações de:
 * - Missões com prazos de entrega próximos
 * - Documentos a vencer
 * - Contratos a expirar
 * - Parcerias a vencer
 * - CNH de caminhoneiros a expirar
 */

/**
 * Verifica notificações de prazos próximos para o dashboard admin
 * 
 * @param PDO $conn Conexão com o banco de dados
 * @return array Array de notificações
 */
function verificar_notificacoes_prazos(PDO $conn): array
{
    $notificacoes = [];
    
    try {
        // Missões com prazo de entrega próximo (7 dias)
        $stmt = $conn->prepare(
            "SELECT m.id, m.titulo, m.prazo_entrega, m.status, u.nome as empresa_nome,
                    DATEDIFF(m.prazo_entrega, CURDATE()) AS dias_restantes
             FROM missoes m
             JOIN usuarios u ON m.empresa_id = u.id
             WHERE m.status IN ('aberta', 'em_andamento')
             AND m.prazo_entrega IS NOT NULL
             AND m.prazo_entrega BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
             ORDER BY m.prazo_entrega ASC"
        );
        $stmt->execute();
        $missoes_prazo = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($missoes_prazo as $missao) {
            $gravidade = $missao['dias_restantes'] <= 1 ? 'critica' : 
                        ($missao['dias_restantes'] <= 3 ? 'alta' : 'media');
            
            $notificacoes[] = [
                'tipo' => 'missao',
                'gravidade' => $gravidade,
                'titulo' => 'Missão com prazo próximo',
                'descricao' => "Missão #{$missao['id']} - {$missao['titulo']} ({$missao['empresa_nome']})",
                'detalhe' => "Prazo: " . date('d/m/Y', strtotime($missao['prazo_entrega'])) . 
                            " ({$missao['dias_restantes']} dias restantes)",
                'acao' => 'Ver missão',
                'link' => BASE_URL . '/pages/admin/ver-missao.php?id=' . $missao['id']
            ];
        }
        
        // Documentos de usuários a vencer (30 dias)
        $stmt = $conn->prepare(
            "SELECT d.*, u.nome, u.email, u.tipo_usuario,
                    DATEDIFF(d.data_validade, CURDATE()) AS dias_restantes
             FROM documentos d
             JOIN usuarios u ON d.usuario_id = u.id
             WHERE d.status = 'aprovado'
             AND d.data_validade IS NOT NULL
             AND d.data_validade < DATE_ADD(CURDATE(), INTERVAL 30 DAY)
             ORDER BY d.data_validade ASC"
        );
        $stmt->execute();
        $documentos_vencer = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($documentos_vencer as $doc) {
            $gravidade = $doc['dias_restantes'] < 0 ? 'critica' : 
                        ($doc['dias_restantes'] <= 7 ? 'alta' : 'media');
            
            $notificacoes[] = [
                'tipo' => 'documento',
                'gravidade' => $gravidade,
                'titulo' => 'Documento a vencer',
                'descricao' => "{$doc['tipo_documento']} - {$doc['nome']} ({$doc['tipo_usuario']})",
                'detalhe' => "Validade: " . date('d/m/Y', strtotime($doc['data_validade'])) . 
                            " ({$doc['dias_restantes']} dias restantes)",
                'acao' => 'Ver documento',
                'link' => BASE_URL . '/pages/admin/documentos.php'
            ];
        }
        
        // CNH de caminhoneiros a expirar (30 dias)
        $stmt = $conn->prepare(
            "SELECT pc.*, u.nome, u.email,
                    DATEDIFF(pc.cnh_validade, CURDATE()) AS dias_restantes
             FROM perfil_caminhoneiro pc
             JOIN usuarios u ON pc.usuario_id = u.id
             WHERE pc.cnh_validade IS NOT NULL
             AND pc.cnh_validade < DATE_ADD(CURDATE(), INTERVAL 30 DAY)
             ORDER BY pc.cnh_validade ASC"
        );
        $stmt->execute();
        $cnh_vencer = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($cnh_vencer as $cnh) {
            $gravidade = $cnh['dias_restantes'] < 0 ? 'critica' : 
                        ($cnh['dias_restantes'] <= 7 ? 'alta' : 'media');
            
            $notificacoes[] = [
                'tipo' => 'cnh',
                'gravidade' => $gravidade,
                'titulo' => 'CNH a expirar',
                'descricao' => "CNH de {$cnh['nome']}",
                'detalhe' => "Validade: " . date('d/m/Y', strtotime($cnh['cnh_validade'])) . 
                            " ({$cnh['dias_restantes']} dias restantes)",
                'acao' => 'Ver perfil',
                'link' => BASE_URL . '/pages/admin/ver-usuario.php?id=' . $cnh['usuario_id']
            ];
        }
        
        // Contratos a expirar (30 dias)
        $stmt = $conn->prepare(
            "SELECT c.*, e.nome as empresa_nome, t.nome as transportador_nome,
                    DATEDIFF(c.data_fim, CURDATE()) AS dias_restantes
             FROM contratos c
             JOIN usuarios e ON c.empresa_id = e.id
             JOIN usuarios t ON c.transportador_id = t.id
             WHERE c.status = 'ativo'
             AND c.data_fim IS NOT NULL
             AND c.data_fim < DATE_ADD(CURDATE(), INTERVAL 30 DAY)
             ORDER BY c.data_fim ASC"
        );
        $stmt->execute();
        $contratos_vencer = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($contratos_vencer as $contrato) {
            $gravidade = $contrato['dias_restantes'] < 0 ? 'critica' : 
                        ($contrato['dias_restantes'] <= 7 ? 'alta' : 'media');
            
            $notificacoes[] = [
                'tipo' => 'contrato',
                'gravidade' => $gravidade,
                'titulo' => 'Contrato a expirar',
                'descricao' => "Contrato entre {$contrato['empresa_nome']} e {$contrato['transportador_nome']}",
                'detalhe' => "Fim: " . date('d/m/Y', strtotime($contrato['data_fim'])) . 
                            " ({$contrato['dias_restantes']} dias restantes)",
                'acao' => 'Ver contrato',
                'link' => BASE_URL . '/pages/admin/contratos.php'
            ];
        }
        
        // Parcerias a vencer (30 dias)
        $stmt = $conn->prepare(
            "SELECT p.*, e.nome as empresa_nome, t.nome as transportador_nome,
                    DATEDIFF(p.data_fim, CURDATE()) AS dias_restantes
             FROM parcerias p
             JOIN usuarios e ON p.empresa_id = e.id
             JOIN usuarios t ON p.transportador_id = t.id
             WHERE p.status = 'ativa'
             AND p.data_fim IS NOT NULL
             AND p.data_fim < DATE_ADD(CURDATE(), INTERVAL 30 DAY)
             ORDER BY p.data_fim ASC"
        );
        $stmt->execute();
        $parcerias_vencer = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($parcerias_vencer as $parceria) {
            $gravidade = $parceria['dias_restantes'] < 0 ? 'critica' : 
                        ($parceria['dias_restantes'] <= 7 ? 'alta' : 'media');
            
            $notificacoes[] = [
                'tipo' => 'parceria',
                'gravidade' => $gravidade,
                'titulo' => 'Parceria a vencer',
                'descricao' => "Parceria entre {$parceria['empresa_nome']} e {$parceria['transportador_nome']}",
                'detalhe' => "Fim: " . date('d/m/Y', strtotime($parceria['data_fim'])) . 
                            " ({$parceria['dias_restantes']} dias restantes)",
                'acao' => 'Ver parceria',
                'link' => BASE_URL . '/pages/admin/parcerias.php'
            ];
        }
        
    } catch (PDOException $e) {
        error_log('Erro verificar_notificacoes_prazos: ' . $e->getMessage());
    }
    
    // Ordenar por gravidade (critica > alta > media)
    $ordem_gravidade = ['critica' => 0, 'alta' => 1, 'media' => 2];
    usort($notificacoes, function($a, $b) use ($ordem_gravidade) {
        return $ordem_gravidade[$a['gravidade']] - $ordem_gravidade[$b['gravidade']];
    });
    
    return $notificacoes;
}

/**
 * Retorna resumo de notificações por gravidade
 * 
 * @param PDO $conn Conexão com o banco de dados
 * @return array ['critica' => int, 'alta' => int, 'media' => int, 'total' => int]
 */
function obter_resumo_notificacoes(PDO $conn): array
{
    $resumo = [
        'critica' => 0,
        'alta' => 0,
        'media' => 0,
        'total' => 0
    ];
    
    $notificacoes = verificar_notificacoes_prazos($conn);
    
    foreach ($notificacoes as $notif) {
        $resumo[$notif['gravidade']]++;
        $resumo['total']++;
    }
    
    return $resumo;
}
