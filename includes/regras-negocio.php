<?php
/**
 * Regras de Negócio - TrackMoz
 *
 * Este arquivo contém todas as validações de regras de negócio
 * para garantir que o sistema opere conforme os requisitos.
 */
require_once __DIR__ . '/motorista-regras.php';
require_once __DIR__ . '/otp-entrega.php';
require_once __DIR__ . '/kyc-helpers.php';

/**
 * Valida se um motorista pode iniciar uma nova missão
 * 
 * Regras:
 * - Apenas uma missão activa por vez
 * - Não pode iniciar nova missão sem concluir a anterior
 * - Carta (CNH) expirada bloqueia actividade
 * - Motorista suspenso não recebe missões
 * 
 * @param PDO $conn Conexão com o banco de dados
 * @param int $user_id ID do usuário (motorista)
 * @return array ['ok' => bool, 'erros' => string[], 'warnings' => string[]]
 */
function validar_motorista_nova_missao(PDO $conn, int $user_id): array
{
    $erros = [];
    $warnings = [];
    
    try {
        // 1. Verificar se o usuário está suspenso ou inativo
        $stmt = $conn->prepare("SELECT status FROM usuarios WHERE id = :id");
        $stmt->execute([':id' => $user_id]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$usuario) {
            return ['ok' => false, 'erros' => ['Usuário não encontrado'], 'warnings' => []];
        }
        
        if ($usuario['status'] === 'bloqueado' || $usuario['status'] === 'inativo') {
            $erros[] = 'Sua conta está ' . strtoupper($usuario['status']) . '. Entre em contato com o suporte.';
            return ['ok' => false, 'erros' => $erros, 'warnings' => $warnings];
        }

        if ($usuario['status'] === 'pendente') {
            $erros[] = 'A sua conta aguarda aprovação do administrador.';
            return ['ok' => false, 'erros' => $erros, 'warnings' => $warnings];
        }

        if ($usuario['status'] !== 'ativo') {
            $erros[] = 'Conta suspensa.';
            return ['ok' => false, 'erros' => $erros, 'warnings' => $warnings];
        }

        // KYC: visitante não pode negociar missões
        $kyc = kyc_pode_operar($conn, $user_id);
        if (!$kyc['ok']) {
            $erros[] = $kyc['erros'][0] ?? 'Conta ainda não verificada.';
            return ['ok' => false, 'erros' => $erros, 'warnings' => $warnings, 'solucao' => $kyc['solucao'] ?? null];
        }
        
        // 2. Verificar CNH e disponibilidade
        $stmt = $conn->prepare(
            "SELECT validade_cnh, disponibilidade FROM perfil_caminhoneiro WHERE usuario_id = :id"
        );
        $stmt->execute([':id' => $user_id]);
        $perfil = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        
        if ($perfil && !empty($perfil['validade_cnh'])) {
            $validade = new DateTime($perfil['validade_cnh']);
            $hoje = new DateTime();
            
            if ($validade < $hoje) {
                $erros[] = 'Sua CNH está expirada desde ' . $validade->format('d/m/Y') . '. Renove sua CNH para continuar operando.';
            } elseif ($validade->diff($hoje)->days <= 30) {
                $warnings[] = 'Sua CNH expira em ' . $validade->format('d/m/Y') . '. Renove em breve.';
            }
        } elseif ($perfil) {
            $warnings[] = 'Data de validade da CNH não informada. Atualize seu perfil.';
        } else {
            $warnings[] = 'Perfil de motorista incompleto. Complete seu perfil para melhor visibilidade.';
        }
        
        // 3. Verificar se já tem missão em execução (agendadas/aceites são permitidas)
        if (motorista_tem_missao_ativa($conn, $user_id)) {
            $erros[] = 'Você já possui uma missão em andamento. Finalize a missão actual antes de aceitar outra.';
        }
        
        // 4. Verificar disponibilidade
        $disponibilidade = $perfil
            ? (($perfil['disponibilidade'] ?? null) ?: 'disponivel')
            : 'disponivel';
        if ($disponibilidade === 'indisponivel') {
            $erros[] = 'Sua disponibilidade está marcada como INDISPONÍVEL. Altere para DISPONÍVEL para aceitar missões.';
        } elseif ($disponibilidade === 'manutencao') {
            $erros[] = 'Você está em MANUTENÇÃO. Altere sua disponibilidade para aceitar missões.';
        } elseif ($disponibilidade === 'ocupado') {
            $warnings[] = 'Está marcado como OCUPADO — confirme se consegue assumir esta missão.';
        }
        
    } catch (PDOException $e) {
        error_log('Erro validar_motorista_nova_missao: ' . $e->getMessage());
        return ['ok' => false, 'erros' => ['Erro ao validar regras de negócio'], 'warnings' => []];
    }
    
    return [
        'ok' => empty($erros),
        'erros' => $erros,
        'warnings' => $warnings
    ];
}

/**
 * Valida se um veículo pode ser operado
 * 
 * Regras:
 * - Seguro expirado bloqueia operação
 * - Inspecção expirada bloqueia operação
 * - Viatura indisponível não pode ser atribuída
 * 
 * @param PDO $conn Conexão com o banco de dados
 * @param int $veiculo_id ID do veículo
 * @return array ['ok' => bool, 'erros' => string[], 'warnings' => string[]]
 */
function validar_veiculo_operacao(PDO $conn, int $veiculo_id): array
{
    $erros = [];
    $warnings = [];
    
    try {
        // Verificar se veículo existe e está activo
        $stmt = $conn->prepare("SELECT * FROM veiculos WHERE id = :id");
        $stmt->execute([':id' => $veiculo_id]);
        $veiculo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$veiculo) {
            return ['ok' => false, 'erros' => ['Veículo não encontrado'], 'warnings' => []];
        }
        
        if (($veiculo['estado_operacional'] ?? $veiculo['status'] ?? '') === 'inativo') {
            $erros[] = 'Este veículo está INATIVO e não pode ser operado.';
            return ['ok' => false, 'erros' => $erros, 'warnings' => $warnings];
        }
        
        if (($veiculo['estado_operacional'] ?? $veiculo['status'] ?? '') === 'manutencao') {
            $erros[] = 'Este veículo está em MANUTENÇÃO e não pode ser operado.';
            return ['ok' => false, 'erros' => $erros, 'warnings' => $warnings];
        }
        
        // Verificar documentos do veículo (seguro, inspecção)
        // Nota: Esta funcionalidade requer tabela de documentos_veiculo
        // Por enquanto, apenas warnings
        
        $warnings[] = 'Verifique se o seguro e a inspecção do veículo estão válidos.';
        
    } catch (PDOException $e) {
        error_log('Erro validar_veiculo_operacao: ' . $e->getMessage());
        return ['ok' => false, 'erros' => ['Erro ao validar veículo'], 'warnings' => []];
    }
    
    return [
        'ok' => empty($erros),
        'erros' => $erros,
        'warnings' => $warnings
    ];
}

/**
 * Valida se uma missão pode ser executada
 * 
 * Regras:
 * - Missão só pode ser executada por parceiros activos
 * - Contrato expirado bloqueia novas missões
 * - Missão não pode ser concluída sem prova de entrega
 * 
 * @param PDO $conn Conexão com o banco de dados
 * @param int $missao_id ID da missão
 * @param int $user_id ID do usuário (empresa ou transportador)
 * @return array ['ok' => bool, 'erros' => string[], 'warnings' => string[]]
 */
function validar_missao_execucao(PDO $conn, int $missao_id, int $user_id): array
{
    $erros = [];
    $warnings = [];
    
    try {
        // Buscar dados da missão
        $stmt = $conn->prepare("SELECT * FROM missoes WHERE id = :id");
        $stmt->execute([':id' => $missao_id]);
        $missao = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$missao) {
            return ['ok' => false, 'erros' => ['Missão não encontrada'], 'warnings' => []];
        }
        
        // Verificar se há parceria associada
        if ($missao['parceria_id']) {
            $stmt = $conn->prepare("SELECT * FROM parcerias WHERE id = :id");
            $stmt->execute([':id' => $missao['parceria_id']]);
            $parceria = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($parceria) {
                // Verificar se parceria está activa
                if ($parceria['status'] !== 'ativa') {
                    $erros[] = 'A parceria associada está ' . strtoupper($parceria['status']) . '. Apenas parcerias ACTIVAS podem executar missões.';
                }
                
                // Verificar se parceria expirou
                if ($parceria['data_fim']) {
                    $data_fim = new DateTime($parceria['data_fim']);
                    $hoje = new DateTime();
                    
                    if ($data_fim < $hoje) {
                        $erros[] = 'A parceria expirou em ' . $data_fim->format('d/m/Y') . '. Renove o contrato para continuar.';
                    } elseif ($data_fim->diff($hoje)->days <= 30) {
                        $warnings[] = 'A parceria expira em ' . $data_fim->format('d/m/Y') . '. Renove em breve.';
                    }
                }
            }
        }
        
        // Verificar se missão requer documento de carga
        if ($missao['requer_documento_carga'] && !$missao['tipo_documento_carga']) {
            $warnings[] = 'Missão requer documento de carga, mas tipo não especificado.';
        }
        
    } catch (PDOException $e) {
        error_log('Erro validar_missao_execucao: ' . $e->getMessage());
        return ['ok' => false, 'erros' => ['Erro ao validar missão'], 'warnings' => []];
    }
    
    return [
        'ok' => empty($erros),
        'erros' => $erros,
        'warnings' => $warnings
    ];
}

/**
 * Valida se uma entrega pode ser confirmada
 * 
 * Regras:
 * - OTP obrigatório quando configurado
 * - OTP único
 * - OTP de utilização única
 * - GPS obrigatório
 * - Histórico completo
 * 
 * @param PDO $conn Conexão com o banco de dados
 * @param int $missao_id ID da missão
 * @param string $metodo Método de confirmação (otp, destinatario_cadastrado, manual_assistida)
 * @param string|null $otp Código OTP fornecido
 * @param float|null $latitude Latitude GPS
 * @param float|null $longitude Longitude GPS
 * @return array ['ok' => bool, 'erros' => string[], 'warnings' => string[]]
 */
function validar_entrega_confirmacao(PDO $conn, int $missao_id, string $metodo, ?string $otp, ?float $latitude, ?float $longitude): array
{
    $erros = [];
    $warnings = [];
    
    try {
        // Buscar dados da missão
        $stmt = $conn->prepare("SELECT * FROM missoes WHERE id = :id");
        $stmt->execute([':id' => $missao_id]);
        $missao = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$missao) {
            return ['ok' => false, 'erros' => ['Missão não encontrada'], 'warnings' => []];
        }
        
        // Validar GPS
        if ($latitude === null || $longitude === null) {
            $warnings[] = 'Localização GPS não fornecida. Recomendado para rastreamento.';
        }
        
        // Validar OTP se método for OTP (validação completa em entrega-confirmar.php)
        if ($metodo === 'otp' && !$otp) {
            $erros[] = 'Código OTP obrigatório para este método de confirmação.';
        }
        
        // Verificar se já existe confirmação de entrega
        $stmt = $conn->prepare("SELECT COUNT(*) FROM entregas_confirmacao WHERE missao_id = :id");
        $stmt->execute([':id' => $missao_id]);
        $ja_confirmada = (int)$stmt->fetchColumn() > 0;
        
        if ($ja_confirmada) {
            $warnings[] = 'Esta missão já possui confirmação de entrega registrada.';
        }
        
    } catch (PDOException $e) {
        error_log('Erro validar_entrega_confirmacao: ' . $e->getMessage());
        return ['ok' => false, 'erros' => ['Erro ao validar entrega'], 'warnings' => []];
    }
    
    return [
        'ok' => empty($erros),
        'erros' => $erros,
        'warnings' => $warnings
    ];
}

/**
 * Valida se o peso da carga cabe na capacidade do veículo do motorista.
 * @return array{ok: bool, erros: string[], warnings: string[]}
 */
function validar_peso_capacidade_missao(PDO $conn, int $missaoId, int $caminhoneiroId): array
{
    $erros = [];
    $warnings = [];

    try {
        $stmt = $conn->prepare('SELECT peso_carga, titulo FROM missoes WHERE id = :id');
        $stmt->execute([':id' => $missaoId]);
        $missao = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$missao) {
            return ['ok' => false, 'erros' => ['Missão não encontrada'], 'warnings' => []];
        }

        $peso = (float)($missao['peso_carga'] ?? 0);
        if ($peso <= 0) {
            return ['ok' => true, 'erros' => [], 'warnings' => []];
        }

        $capacidade = 0.0;
        $stmt = $conn->prepare(
            'SELECT capacidade_carga FROM perfil_caminhoneiro WHERE usuario_id = :id'
        );
        $stmt->execute([':id' => $caminhoneiroId]);
        $perfil = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($perfil) {
            $capacidade = (float)($perfil['capacidade_carga'] ?? 0);
        }

        require_once __DIR__ . '/frota-helpers.php';
        $veiculo = motorista_resolver_veiculo($conn, $caminhoneiroId);
        if ($veiculo && !empty($veiculo['capacidade_kg'])) {
            $capVeiculo = (float)$veiculo['capacidade_kg'];
            if ($capVeiculo > $capacidade) {
                $capacidade = $capVeiculo;
            }
        }

        if ($capacidade <= 0) {
            $warnings[] = 'Capacidade do veículo não registada — confirme se a carga de '
                . number_format($peso, 0, ',', '.') . ' kg é viável.';
            return ['ok' => true, 'erros' => [], 'warnings' => $warnings];
        }

        if ($peso > $capacidade) {
            $erros[] = 'Peso da carga (' . number_format($peso, 0, ',', '.') . ' kg) excede a capacidade do veículo ('
                . number_format($capacidade, 0, ',', '.') . ' kg).';
        } elseif ($peso > ($capacidade * 0.95)) {
            $warnings[] = 'Carga próxima do limite de capacidade (' . number_format($capacidade, 0, ',', '.') . ' kg).';
        }
    } catch (PDOException $e) {
        error_log('validar_peso_capacidade_missao: ' . $e->getMessage());
        return ['ok' => false, 'erros' => ['Erro ao validar peso da carga'], 'warnings' => []];
    }

    return ['ok' => empty($erros), 'erros' => $erros, 'warnings' => $warnings];
}

// =============================================================================
// Conta de utilizador — RN01, RN03, RN06
// =============================================================================

function obter_status_usuario(PDO $conn, int $userId): ?string
{
    $stmt = $conn->prepare('SELECT status FROM usuarios WHERE id = :id');
    $stmt->execute([':id' => $userId]);
    $status = $stmt->fetchColumn();
    return $status !== false ? (string)$status : null;
}

/**
 * RN06 — Apenas contas ativas autenticam-se.
 */
function validar_conta_pode_autenticar(string $status): array
{
    if ($status === 'ativo') {
        return ['ok' => true, 'erros' => [], 'mensagem' => null];
    }
    if ($status === 'pendente') {
        return ['ok' => false, 'erros' => ['A sua conta aguarda aprovação do administrador.'], 'mensagem' => null];
    }
    if ($status === 'bloqueado') {
        return [
            'ok' => false,
            'erros' => ['Conta bloqueada por documentação irregular. Contacte o suporte ou regularize os documentos.'],
            'mensagem' => 'Conta bloqueada.',
        ];
    }
    if ($status === 'inativo') {
        return [
            'ok' => false,
            'erros' => ['Esta conta foi desactivada pela administração.'],
            'mensagem' => 'Conta desactivada.',
        ];
    }
    return ['ok' => false, 'erros' => ['Conta suspensa.'], 'mensagem' => 'Conta suspensa.'];
}

/**
 * RN06 — Conta activa obrigatória para usar o sistema.
 */
function validar_conta_ativa(PDO $conn, int $userId): array
{
    $status = obter_status_usuario($conn, $userId);
    if ($status === null) {
        return ['ok' => false, 'erros' => ['Utilizador não encontrado.']];
    }
    $auth = validar_conta_pode_autenticar($status);
    return ['ok' => $auth['ok'], 'erros' => $auth['erros']];
}

/**
 * RN03 — Email único.
 */
function validar_email_unico(PDO $conn, string $email, ?int $excluirUserId = null): array
{
    $email = trim(mb_strtolower($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'erros' => ['Email inválido.']];
    }

    $sql = 'SELECT id FROM usuarios WHERE LOWER(email) = :email';
    $params = [':email' => $email];
    if ($excluirUserId) {
        $sql .= ' AND id != :id';
        $params[':id'] = $excluirUserId;
    }
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    if ($stmt->fetch()) {
        return ['ok' => false, 'erros' => ['Este email já está registado por outro utilizador.']];
    }
    return ['ok' => true, 'erros' => []];
}

// =============================================================================
// Empresa / Transportador — RN07, RN11
// =============================================================================

/**
 * RN07 — Apenas empresas activas E verificadas (KYC) podem publicar missões.
 */
function validar_empresa_pode_publicar(PDO $conn, int $empresaId): array
{
    $conta = validar_conta_ativa($conn, $empresaId);
    if (!$conta['ok']) {
        return $conta;
    }

    $stmt = $conn->prepare(
        "SELECT tipo_usuario FROM usuarios WHERE id = :id"
    );
    $stmt->execute([':id' => $empresaId]);
    if ($stmt->fetchColumn() !== 'empresa') {
        return ['ok' => false, 'erros' => ['Apenas empresas contratantes podem publicar missões.']];
    }

    $kyc = kyc_pode_operar($conn, $empresaId);
    if (!$kyc['ok']) {
        return [
            'ok' => false,
            'erros' => $kyc['erros'],
            'solucao' => $kyc['solucao'] ?? 'Complete a verificação da conta (dados legais + documentos).',
        ];
    }

    return ['ok' => true, 'erros' => []];
}

/**
 * RN11 — Apenas transportadoras activas E verificadas podem candidatar-se.
 */
function validar_transportador_pode_candidatar(PDO $conn, int $transportadorId): array
{
    $conta = validar_conta_ativa($conn, $transportadorId);
    if (!$conta['ok']) {
        return $conta;
    }

    $stmt = $conn->prepare('SELECT tipo_usuario FROM usuarios WHERE id = :id');
    $stmt->execute([':id' => $transportadorId]);
    if ($stmt->fetchColumn() !== 'transportador') {
        return ['ok' => false, 'erros' => ['Perfil inválido para candidatura.']];
    }

    $kyc = kyc_pode_operar($conn, $transportadorId);
    if (!$kyc['ok']) {
        return [
            'ok' => false,
            'erros' => $kyc['erros'],
            'solucao' => $kyc['solucao'] ?? 'Complete a verificação da conta.',
        ];
    }

    return ['ok' => true, 'erros' => []];
}

// =============================================================================
// Missões — RN08, RN09, RN10, RN24, RN25
// =============================================================================

/** Estados em que a missão não pode ser alterada (RN10). */
function missao_estados_imutaveis(): array
{
    return ['concluida', 'cancelada'];
}

/**
 * RN08 — Campos obrigatórios para publicar missão.
 */
function validar_missao_campos_obrigatorios(array $dados): array
{
    $erros = [];
    $campos = [
        'origem'        => 'origem',
        'destino'       => 'destino',
        'descricao'     => 'descrição',
        'tipo_carga'    => 'categoria da carga',
        'valor'         => 'valor',
        'prazo_entrega' => 'prazo de entrega',
    ];

    foreach ($campos as $key => $label) {
        $val = $dados[$key] ?? null;
        if ($val === null || $val === '') {
            $erros[] = "O campo «{$label}» é obrigatório.";
            continue;
        }
        if ($key === 'valor' && (float)$val <= 0) {
            $erros[] = 'O valor deve ser maior que zero.';
        }
    }

    if (isset($dados['peso_carga']) && $dados['peso_carga'] !== '' && (float)$dados['peso_carga'] <= 0) {
        $erros[] = 'O peso da carga deve ser maior que zero.';
    }

    $oLat = $dados['origem_lat'] ?? null;
    $oLng = $dados['origem_lng'] ?? null;
    $dLat = $dados['destino_lat'] ?? null;
    $dLng = $dados['destino_lng'] ?? null;
    if (!$oLat || !$oLng || !$dLat || !$dLng) {
        $erros[] = 'Defina origem e destino no mapa (coordenadas obrigatórias).';
    }

    return ['ok' => empty($erros), 'erros' => $erros];
}

/**
 * RN09 — Retirar/apagar missão do ar: só se ainda não foi aceite por motorista nem recolhida.
 * (Transportadora parceira sem motorista atribuído ainda permite retirada.)
 */
function validar_missao_pode_cancelar(array $missao): array
{
    return validar_missao_pode_apagar($missao);
}

/**
 * Pode apagar/retirar do ar?
 * Bloqueado se: concluída/cancelada, motorista atribuído, ou já em recolha/trânsito/entrega.
 */
function validar_missao_pode_apagar(array $missao): array
{
    $status = (string)($missao['status'] ?? '');

    if (in_array($status, ['concluida', 'cancelada', 'entrega_confirmada'], true)) {
        return ['ok' => false, 'erros' => ['Missão concluída ou já cancelada não pode ser apagada.']];
    }

    $bloqueados = [
        'em_transito', 'em_entrega', 'aguardando_confirmacao',
        'emergencia', 'emergencia_reportada',
    ];
    if (in_array($status, $bloqueados, true)) {
        return [
            'ok' => false,
            'erros' => ['Não é possível apagar: a missão já está em curso, recolhida ou em entrega.'],
        ];
    }

    if (!empty($missao['caminhoneiro_id']) || !empty($missao['motorista_id'])) {
        return [
            'ok' => false,
            'erros' => ['Não é possível apagar: já existe motorista atribuído/aceite nesta missão.'],
        ];
    }

    $sv = (string)($missao['status_viagem'] ?? 'nao_iniciada');
    if (in_array($sv, ['carga_recolhida', 'em_transito', 'coleta', 'entrega', 'finalizada'], true)) {
        return [
            'ok' => false,
            'erros' => ['Não é possível apagar: a carga já foi recolhida ou a viagem avançou.'],
        ];
    }

    $permitidos = [
        'aberta', 'em_negociacao', 'aguardando_aceitacao_transportadora',
        'aceita', 'em_andamento',
    ];
    if (!in_array($status, $permitidos, true)) {
        return ['ok' => false, 'erros' => ['Estado actual não permite apagar esta missão.']];
    }

    return ['ok' => true, 'erros' => []];
}

/**
 * RN10 — Missão concluída/cancelada é imutável.
 */
function validar_missao_pode_editar(array $missao): array
{
    if (in_array($missao['status'] ?? '', missao_estados_imutaveis(), true)) {
        return ['ok' => false, 'erros' => ['Missão concluída ou cancelada não pode ser alterada.']];
    }
    if (($missao['status'] ?? '') !== 'aberta') {
        return ['ok' => false, 'erros' => ['Só é possível editar missões com estado «aberta».']];
    }
    return ['ok' => true, 'erros' => []];
}

/**
 * RN24 / RN37 — Apenas missões concluídas podem ser avaliadas.
 */
function validar_missao_pode_avaliar(PDO $conn, int $missaoId): array
{
    $stmt = $conn->prepare('SELECT status, empresa_id FROM missoes WHERE id = :id');
    $stmt->execute([':id' => $missaoId]);
    $missao = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$missao) {
        return ['ok' => false, 'erros' => ['Missão não encontrada.']];
    }
    if ($missao['status'] !== 'concluida') {
        return ['ok' => false, 'erros' => ['Apenas missões concluídas podem ser avaliadas.']];
    }

    $stmt = $conn->prepare(
        'SELECT COUNT(*) FROM avaliacoes_entrega WHERE missao_id = :id'
    );
    $stmt->execute([':id' => $missaoId]);
    if ((int)$stmt->fetchColumn() > 0) {
        return ['ok' => false, 'erros' => ['Esta missão já possui avaliação registada.']];
    }

    try {
        $stmt = $conn->prepare(
            'SELECT COUNT(*) FROM avaliacoes WHERE missao_id = :id AND avaliador_id = :aid'
        );
        $stmt->execute([':id' => $missaoId, ':aid' => (int)($_SESSION['user_id'] ?? 0)]);
        if ((int)$stmt->fetchColumn() > 0) {
            return ['ok' => false, 'erros' => ['Já avaliou esta missão.']];
        }
    } catch (Throwable $e) {
        // ignore
    }

    return ['ok' => true, 'erros' => [], 'missao' => $missao];
}

/**
 * RN25 — Missões canceladas não geram documentos finais.
 */
function validar_missao_gera_documento_final(PDO $conn, int $missaoId): array
{
    $stmt = $conn->prepare('SELECT status FROM missoes WHERE id = :id');
    $stmt->execute([':id' => $missaoId]);
    $status = $stmt->fetchColumn();

    if (!$status) {
        return ['ok' => false, 'erros' => ['Missão não encontrada.']];
    }
    if ($status === 'cancelada') {
        return ['ok' => false, 'erros' => ['Missões canceladas não podem gerar documentos finais.']];
    }
    if ($status !== 'concluida') {
        return ['ok' => false, 'erros' => ['Documento final apenas para missões concluídas.']];
    }
    return ['ok' => true, 'erros' => []];
}

/**
 * Formata erros de regra de negócio para exibição.
 */
function regras_erro_mensagem(array $resultado): string
{
    return implode(' ', $resultado['erros'] ?? ['Operação não permitida.']);
}

// =============================================================================
// NUIT — RN04
// =============================================================================

function normalizar_nuit(string $nuit): string
{
    return preg_replace('/\D+/', '', trim($nuit)) ?? '';
}

/**
 * RN04 — NUIT único entre empresas contratantes e transportadoras.
 */
function validar_nuit_unico(PDO $conn, string $nuit, ?int $excluirUsuarioId = null): array
{
    $nuitNorm = normalizar_nuit($nuit);
    if ($nuitNorm === '') {
        return ['ok' => true, 'erros' => []];
    }

    foreach (['perfil_empresa', 'perfil_transportador'] as $tabela) {
        try {
            $sql = "SELECT usuario_id FROM {$tabela} WHERE REPLACE(REPLACE(REPLACE(nuit, ' ', ''), '-', ''), '.', '') = :nuit";
            $params = [':nuit' => $nuitNorm];
            if ($excluirUsuarioId) {
                $sql .= ' AND usuario_id != :uid';
                $params[':uid'] = $excluirUsuarioId;
            }
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            if ($stmt->fetch()) {
                return ['ok' => false, 'erros' => ['Este NUIT já está registado por outra empresa.']];
            }
        } catch (Throwable $e) {
            // tabela pode não existir
        }
    }
    return ['ok' => true, 'erros' => []];
}

// =============================================================================
// Frota — RN14, RN15
// =============================================================================

/** Estados em que viatura/motorista de frota está ocupado. */
function missoes_status_recursos_ocupados(): array
{
    return array_merge(
        ['aceita'],
        missoes_status_operacionais_ativos()
    );
}

/**
 * RN14 — Não atribuir duas missões simultâneas à mesma viatura.
 */
function validar_veiculo_disponivel_missao(PDO $conn, int $veiculoId, ?int $excluirMissaoId = null): array
{
    if ($veiculoId <= 0) {
        return ['ok' => true, 'erros' => []];
    }

    $statuses = missoes_status_recursos_ocupados();
    $placeholders = implode(',', array_fill(0, count($statuses), '?'));

    $sql = "SELECT id, titulo, status FROM missoes
            WHERE veiculo_id = ? AND status IN ({$placeholders})";
    $params = array_merge([$veiculoId], $statuses);

    if ($excluirMissaoId) {
        $sql .= ' AND id != ?';
        $params[] = $excluirMissaoId;
    }
    $sql .= ' LIMIT 1';

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $ocupada = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($ocupada) {
        return [
            'ok' => false,
            'erros' => ['Esta viatura já está atribuída à missão «' . ($ocupada['titulo'] ?? '#' . $ocupada['id']) . '».'],
        ];
    }

    $veiculoCheck = validar_veiculo_operacao($conn, $veiculoId);
    if (!$veiculoCheck['ok']) {
        return $veiculoCheck;
    }

    return ['ok' => true, 'erros' => []];
}

/**
 * RN15 — Não atribuir duas missões simultâneas ao mesmo motorista de frota.
 */
function validar_motorista_frota_disponivel(PDO $conn, int $motoristaFrotaId, ?int $excluirMissaoId = null): array
{
    if ($motoristaFrotaId <= 0) {
        return ['ok' => true, 'erros' => []];
    }

    $statuses = missoes_status_recursos_ocupados();
    $placeholders = implode(',', array_fill(0, count($statuses), '?'));

    $sql = "SELECT id, titulo, status FROM missoes
            WHERE motorista_id = ? AND status IN ({$placeholders})";
    $params = array_merge([$motoristaFrotaId], $statuses);

    if ($excluirMissaoId) {
        $sql .= ' AND id != ?';
        $params[] = $excluirMissaoId;
    }
    $sql .= ' LIMIT 1';

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $ocupada = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($ocupada) {
        return [
            'ok' => false,
            'erros' => ['Este motorista já está atribuído à missão «' . ($ocupada['titulo'] ?? '#' . $ocupada['id']) . '».'],
        ];
    }

    try {
        $stmt = $conn->prepare(
            "SELECT status FROM transportador_motoristas WHERE id = :id"
        );
        $stmt->execute([':id' => $motoristaFrotaId]);
        $st = $stmt->fetchColumn();
        if ($st && $st !== 'ativo') {
            return ['ok' => false, 'erros' => ['Motorista de frota não está activo.']];
        }
    } catch (Throwable $e) {
        // tabela opcional
    }

    return ['ok' => true, 'erros' => []];
}

/**
 * Valida atribuição completa de equipa (viatura + motorista frota).
 */
function validar_atribuicao_equipa(
    PDO $conn,
    ?int $veiculoId,
    ?int $motoristaFrotaId,
    int $missaoId
): array {
    $erros = [];
    if ($veiculoId) {
        $v = validar_veiculo_disponivel_missao($conn, $veiculoId, $missaoId);
        if (!$v['ok']) {
            $erros = array_merge($erros, $v['erros']);
        }
    }
    if ($motoristaFrotaId) {
        $m = validar_motorista_frota_disponivel($conn, $motoristaFrotaId, $missaoId);
        if (!$m['ok']) {
            $erros = array_merge($erros, $m['erros']);
        }
    }
    return ['ok' => empty($erros), 'erros' => $erros];
}
