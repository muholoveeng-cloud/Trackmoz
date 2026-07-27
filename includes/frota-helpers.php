<?php
/**
 * Helpers de frota para transportadoras (motoristas, viaturas).
 */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/regras-negocio.php';

function transportador_listar_motoristas(PDO $conn, int $transportadorId): array
{
    if ($transportadorId <= 0) {
        return [];
    }

    try {
        $stmt = $conn->prepare(
            "SELECT id, nome, telefone, email, cnh, status
             FROM transportador_motoristas
             WHERE transportador_id = :tid AND status = 'ativo'
             ORDER BY nome"
        );
        $stmt->execute([':tid' => $transportadorId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('transportador_listar_motoristas: ' . $e->getMessage());
        return [];
    }
}

function transportador_listar_veiculos(PDO $conn, int $transportadorId): array
{
    if ($transportadorId <= 0) {
        return [];
    }

    try {
        $stmt = $conn->prepare(
            "SELECT id, matricula, marca, modelo, tipo, estado_operacional
             FROM veiculos
             WHERE proprietario_id = :tid AND proprietario_tipo = 'transportador'
             ORDER BY matricula"
        );
        $stmt->execute([':tid' => $transportadorId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('transportador_listar_veiculos: ' . $e->getMessage());
        return [];
    }
}

function transportador_motorista_pertence(PDO $conn, int $transportadorId, int $motoristaId): bool
{
    if ($motoristaId <= 0) {
        return false;
    }

    try {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) FROM transportador_motoristas
             WHERE id = :id AND transportador_id = :tid"
        );
        $stmt->execute([':id' => $motoristaId, ':tid' => $transportadorId]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function transportador_veiculo_pertence(PDO $conn, int $transportadorId, int $veiculoId): bool
{
    if ($veiculoId <= 0) {
        return false;
    }

    try {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) FROM veiculos
             WHERE id = :id AND proprietario_id = :tid AND proprietario_tipo = 'transportador'"
        );
        $stmt->execute([':id' => $veiculoId, ':tid' => $transportadorId]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Motoristas independentes (plataforma) disponíveis para a transportadora convidar.
 */
function transportador_listar_independentes_disponiveis(PDO $conn, int $limit = 40): array
{
    $limit = max(1, min(80, $limit));
    try {
        $ativos = missoes_status_operacionais_ativos();
        $ph = implode(',', array_fill(0, count($ativos), '?'));
        $sql = "SELECT u.id, u.nome, u.telefone, pc.avaliacao_media, pc.total_entregas, pc.tipo_veiculo
                FROM usuarios u
                INNER JOIN perfil_caminhoneiro pc ON pc.usuario_id = u.id
                WHERE u.tipo_usuario = 'caminhoneiro'
                  AND (u.status IS NULL OR u.status NOT IN ('suspenso','rejeitado','inativo','bloqueado','banido'))
                  AND (pc.disponibilidade IS NULL OR pc.disponibilidade IN ('disponivel','', 'livre'))
                  AND u.id NOT IN (
                      SELECT DISTINCT m.caminhoneiro_id FROM missoes m
                      WHERE m.caminhoneiro_id IS NOT NULL AND m.status IN ({$ph})
                  )
                ORDER BY pc.avaliacao_media DESC, pc.total_entregas DESC, u.nome
                LIMIT {$limit}";
        $stmt = $conn->prepare($sql);
        $stmt->execute($ativos);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('transportador_listar_independentes: ' . $e->getMessage());
        // Fallback sem filtro de status de utilizador
        try {
            $stmt = $conn->query(
                "SELECT u.id, u.nome, u.telefone, pc.avaliacao_media, pc.total_entregas, pc.tipo_veiculo
                 FROM usuarios u
                 INNER JOIN perfil_caminhoneiro pc ON pc.usuario_id = u.id
                 WHERE u.tipo_usuario = 'caminhoneiro'
                 ORDER BY u.nome LIMIT 40"
            );
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e2) {
            return [];
        }
    }
}

function transportador_frota_tem_recursos_livres(PDO $conn, int $transportadorId): array
{
    $mots = transportador_listar_motoristas($conn, $transportadorId);
    $veics = array_values(array_filter(
        transportador_listar_veiculos($conn, $transportadorId),
        static fn($v) => ($v['estado_operacional'] ?? 'ativo') === 'ativo'
    ));

    $motsLivres = 0;
    foreach ($mots as $m) {
        $chk = validar_motorista_frota_disponivel($conn, (int)$m['id'], null);
        if ($chk['ok']) {
            $motsLivres++;
        }
    }
    $veicsLivres = 0;
    foreach ($veics as $v) {
        $chk = validar_veiculo_disponivel_missao($conn, (int)$v['id'], null);
        if ($chk['ok']) {
            $veicsLivres++;
        }
    }

    return [
        'motoristas_total' => count($mots),
        'veiculos_total'   => count($veics),
        'motoristas_livres'=> $motsLivres,
        'veiculos_livres'  => $veicsLivres,
        'frota_disponivel' => $motsLivres > 0 && $veicsLivres > 0,
    ];
}

function transportador_nome_motorista_missao(PDO $conn, array $missao): ?string
{
    $caminhoneiroId = (int)($missao['caminhoneiro_id'] ?? 0);
    if ($caminhoneiroId > 0) {
        $stmt = $conn->prepare('SELECT nome FROM usuarios WHERE id = ? LIMIT 1');
        $stmt->execute([$caminhoneiroId]);
        $nome = $stmt->fetchColumn();
        if ($nome) {
            return (string)$nome;
        }
    }

    $motoristaId = (int)($missao['motorista_id'] ?? 0);
    if ($motoristaId <= 0) {
        return null;
    }

    try {
        $stmt = $conn->prepare('SELECT nome FROM transportador_motoristas WHERE id = ? LIMIT 1');
        $stmt->execute([$motoristaId]);
        $nome = $stmt->fetchColumn();
        if ($nome) {
            return (string)$nome;
        }
    } catch (Throwable $e) {
        // ignore
    }

    $stmt = $conn->prepare("SELECT nome FROM usuarios WHERE id = ? AND tipo_usuario = 'caminhoneiro' LIMIT 1");
    $stmt->execute([$motoristaId]);
    $nome = $stmt->fetchColumn();

    return $nome ? (string)$nome : null;
}

/**
 * @return array{matricula: string, marca: string, modelo: string}|null
 */
function transportador_info_veiculo_missao(PDO $conn, array $missao): ?array
{
    $veiculoId = (int)($missao['veiculo_id'] ?? 0);
    if ($veiculoId <= 0) {
        return null;
    }

    try {
        $stmt = $conn->prepare(
            'SELECT matricula, marca, modelo FROM veiculos WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$veiculoId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Resolve o veículo operacional de um caminhoneiro (frota, proprietário ou perfil).
 * @return array<string, mixed>|null
 */
function motorista_resolver_veiculo(PDO $conn, int $caminhoneiroId, ?int $veiculoId = null): ?array
{
    if ($caminhoneiroId <= 0) {
        return null;
    }

    try {
        if ($veiculoId && $veiculoId > 0) {
            $stmt = $conn->prepare('SELECT * FROM veiculos WHERE id = :vid LIMIT 1');
            $stmt->execute([':vid' => $veiculoId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        }

        $stmt = $conn->prepare(
            "SELECT * FROM veiculos
             WHERE motorista_id = :mid AND estado_operacional != 'inativo'
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([':mid' => $caminhoneiroId]);
        $veiculo = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($veiculo) {
            return $veiculo;
        }

        $stmt = $conn->prepare(
            "SELECT * FROM veiculos
             WHERE proprietario_id = :mid AND proprietario_tipo = 'caminhoneiro'
               AND estado_operacional != 'inativo'
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([':mid' => $caminhoneiroId]);
        $veiculo = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($veiculo) {
            return $veiculo;
        }

        $stmt = $conn->prepare(
            'SELECT placa_veiculo, tipo_veiculo FROM perfil_caminhoneiro WHERE usuario_id = :uid'
        );
        $stmt->execute([':uid' => $caminhoneiroId]);
        $perfil = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$perfil) {
            return null;
        }

        $placa = trim((string)($perfil['placa_veiculo'] ?? ''));
        if ($placa === '' || strcasecmp($placa, 'Não informado') === 0) {
            return null;
        }

        $stmt = $conn->prepare(
            "SELECT * FROM veiculos
             WHERE UPPER(REPLACE(matricula, ' ', '')) = UPPER(REPLACE(:placa, ' ', ''))
             LIMIT 1"
        );
        $stmt->execute([':placa' => $placa]);
        $veiculo = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($veiculo) {
            return $veiculo;
        }

        return [
            'id'                  => 0,
            'matricula'           => $placa,
            'marca'               => null,
            'modelo'              => $perfil['tipo_veiculo'] ?? null,
            'estado_operacional'  => 'ativo',
            '_fonte'              => 'perfil',
        ];
    } catch (Throwable $e) {
        error_log('motorista_resolver_veiculo: ' . $e->getMessage());
        return null;
    }
}
