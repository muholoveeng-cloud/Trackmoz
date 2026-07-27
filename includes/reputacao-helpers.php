<?php
/**
 * Reputação e avaliações (Módulo 7).
 */
require_once __DIR__ . '/helpers.php';

function reputacao_utilizador(PDO $conn, int $userId): array
{
    $out = [
        'media'           => 0.0,
        'total'           => 0,
        'entregas'        => 0,
        'nivel'           => 'novo',
        'nivel_label'     => 'Novo',
    ];
    if ($userId <= 0) {
        return $out;
    }

    try {
        $stmt = $conn->prepare(
            'SELECT avaliacao_media, total_entregas FROM perfil_caminhoneiro WHERE usuario_id = :id'
        );
        $stmt->execute([':id' => $userId]);
        $perfil = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($perfil) {
            $out['media']    = round((float)($perfil['avaliacao_media'] ?? 0), 1);
            $out['entregas'] = (int)($perfil['total_entregas'] ?? 0);
        }
    } catch (Throwable $e) {
        // perfil opcional
    }

    try {
        $stmt = $conn->prepare(
            'SELECT avaliacao_media, total_missoes FROM perfil_transportador WHERE usuario_id = :id'
        );
        $stmt->execute([':id' => $userId]);
        $perfilT = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($perfilT && (float)($perfilT['avaliacao_media'] ?? 0) > 0) {
            $out['media'] = round((float)$perfilT['avaliacao_media'], 1);
            if ((int)($perfilT['total_missoes'] ?? 0) > 0) {
                $out['entregas'] = (int)$perfilT['total_missoes'];
            }
        }
    } catch (Throwable $e) {
        // perfil opcional
    }

    try {
        $stmt = $conn->prepare(
            'SELECT AVG(nota) AS media, COUNT(*) AS total FROM avaliacoes WHERE avaliado_id = :id'
        );
        $stmt->execute([':id' => $userId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($r && (int)($r['total'] ?? 0) > 0) {
            $out['media'] = round((float)$r['media'], 1);
            $out['total'] = (int)$r['total'];
        }
    } catch (Throwable $e) {
        // tabela opcional
    }

    if ($out['total'] === 0 && $out['entregas'] > 0) {
        $out['total'] = $out['entregas'];
    }

    $m = $out['media'];
    if ($m >= 4.5 && $out['total'] >= 10) {
        $out['nivel'] = 'excelente';
        $out['nivel_label'] = 'Excelente';
    } elseif ($m >= 4.0 && $out['total'] >= 5) {
        $out['nivel'] = 'bom';
        $out['nivel_label'] = 'Bom';
    } elseif ($m >= 3.0) {
        $out['nivel'] = 'regular';
        $out['nivel_label'] = 'Regular';
    } elseif ($out['total'] > 0) {
        $out['nivel'] = 'atencao';
        $out['nivel_label'] = 'Atenção';
    }

    return $out;
}

function reputacao_badge_html(array $rep): string
{
    $media = (float)($rep['media'] ?? 0);
    $total = (int)($rep['total'] ?? $rep['entregas'] ?? 0);
    $nivel = $rep['nivel'] ?? 'novo';
    $cls   = match ($nivel) {
        'excelente' => 'success',
        'bom'       => 'primary',
        'regular'   => 'warning',
        'atencao'   => 'danger',
        default     => 'secondary',
    };
    $stars = $total > 0 ? number_format($media, 1, ',', '.') . ' ★' : 'Sem avaliações';
    return '<span class="badge bg-' . $cls . '">' . e($stars)
        . ($total > 0 ? ' <small>(' . $total . ')</small>' : '') . '</span>';
}

function reputacao_estrelas_html(float $nota, int $max = 5): string
{
    $html = '';
    for ($i = 1; $i <= $max; $i++) {
        $html .= $i <= round($nota)
            ? '<i class="bi bi-star-fill text-warning"></i>'
            : '<i class="bi bi-star text-muted"></i>';
    }
    return $html;
}

/**
 * Comentários/avaliações recebidas por um utilizador (mais recentes primeiro).
 *
 * @return list<array{nota:int,comentario:string,data:string,avaliador:string,missao_id:int}>
 */
function reputacao_comentarios(PDO $conn, int $userId, int $limit = 20): array
{
    if ($userId <= 0) {
        return [];
    }
    $limit = max(1, min(50, $limit));
    try {
        $stmt = $conn->prepare(
            "SELECT a.nota, a.comentario, a.data_avaliacao, a.missao_id,
                    COALESCE(u.nome, 'Cliente') AS avaliador_nome
             FROM avaliacoes a
             LEFT JOIN usuarios u ON u.id = a.avaliador_id
             WHERE a.avaliado_id = :id
             ORDER BY a.data_avaliacao DESC
             LIMIT {$limit}"
        );
        $stmt->execute([':id' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'nota'       => (int)$r['nota'],
                'comentario' => trim((string)($r['comentario'] ?? '')),
                'data'       => (string)($r['data_avaliacao'] ?? ''),
                'avaliador'  => (string)($r['avaliador_nome'] ?? 'Cliente'),
                'missao_id'  => (int)($r['missao_id'] ?? 0),
            ];
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

function reputacao_ja_avaliou(PDO $conn, int $missaoId, int $avaliadorId, ?int $avaliadoId = null): bool
{
    if ($missaoId <= 0 || $avaliadorId <= 0) {
        return false;
    }
    try {
        if ($avaliadoId) {
            $stmt = $conn->prepare(
                'SELECT 1 FROM avaliacoes WHERE missao_id = :m AND avaliador_id = :a AND avaliado_id = :d LIMIT 1'
            );
            $stmt->execute([':m' => $missaoId, ':a' => $avaliadorId, ':d' => $avaliadoId]);
        } else {
            $stmt = $conn->prepare(
                'SELECT 1 FROM avaliacoes WHERE missao_id = :m AND avaliador_id = :a LIMIT 1'
            );
            $stmt->execute([':m' => $missaoId, ':a' => $avaliadorId]);
        }
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/** Permite avaliar motorista E transportadora na mesma missão. */
function reputacao_garantir_indice_avaliacoes(PDO $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $idx = $conn->query("SHOW INDEX FROM avaliacoes WHERE Key_name = 'uq_missao_avaliador'")->fetch(PDO::FETCH_ASSOC);
        if ($idx) {
            $conn->exec('ALTER TABLE avaliacoes DROP INDEX uq_missao_avaliador');
        }
        $idx2 = $conn->query("SHOW INDEX FROM avaliacoes WHERE Key_name = 'uq_missao_avaliador_avaliado'")->fetch(PDO::FETCH_ASSOC);
        if (!$idx2) {
            $conn->exec('ALTER TABLE avaliacoes ADD UNIQUE KEY uq_missao_avaliador_avaliado (missao_id, avaliador_id, avaliado_id)');
        }
    } catch (Throwable $e) {
        error_log('reputacao_garantir_indice_avaliacoes: ' . $e->getMessage());
    }
}

/**
 * Regista avaliação empresa → motorista e actualiza média no perfil.
 *
 * @return array{ok:bool,error?:string}
 */
function reputacao_registrar_avaliacao(
    PDO $conn,
    int $missaoId,
    int $avaliadorId,
    int $avaliadoId,
    int $nota,
    string $comentario = ''
): array {
    $nota = max(1, min(5, $nota));
    if ($missaoId <= 0 || $avaliadorId <= 0 || $avaliadoId <= 0) {
        return ['ok' => false, 'error' => 'Dados inválidos para avaliação.'];
    }
    reputacao_garantir_indice_avaliacoes($conn);
    if (reputacao_ja_avaliou($conn, $missaoId, $avaliadorId, $avaliadoId)) {
        return ['ok' => false, 'error' => 'Já avaliou esta parte nesta missão.'];
    }

    try {
        $stmt = $conn->prepare(
            'INSERT INTO avaliacoes (missao_id, avaliador_id, avaliado_id, nota, comentario, data_avaliacao)
             VALUES (:m, :av, :ad, :n, :c, NOW())'
        );
        $stmt->execute([
            ':m'  => $missaoId,
            ':av' => $avaliadorId,
            ':ad' => $avaliadoId,
            ':n'  => $nota,
            ':c'  => $comentario !== '' ? $comentario : null,
        ]);

        $avg = $conn->prepare(
            'SELECT AVG(nota) FROM avaliacoes WHERE avaliado_id = :id'
        );
        $avg->execute([':id' => $avaliadoId]);
        $media = round((float)$avg->fetchColumn(), 2);

        try {
            $upd = $conn->prepare(
                'UPDATE perfil_caminhoneiro SET avaliacao_media = :m WHERE usuario_id = :id'
            );
            $upd->execute([':m' => $media, ':id' => $avaliadoId]);
        } catch (Throwable $e) {
            // perfil opcional
        }

        try {
            $updT = $conn->prepare(
                'UPDATE perfil_transportador SET avaliacao_media = :m WHERE usuario_id = :id'
            );
            $updT->execute([':m' => $media, ':id' => $avaliadoId]);
        } catch (Throwable $e) {
            // perfil opcional
        }

        try {
            $conn->prepare(
                "INSERT INTO notificacoes (usuario_id, tipo, titulo, mensagem, link, data_criacao, lida)
                 VALUES (:uid, 'avaliacao', 'Nova avaliação', :msg, :link, NOW(), 0)"
            )->execute([
                ':uid'  => $avaliadoId,
                ':msg'  => 'Recebeu uma avaliação de ' . $nota . ' estrela(s) na missão #' . $missaoId . '.',
                ':link' => (defined('BASE_URL') ? BASE_URL : '') . '/pages/caminhoneiro/perfil.php',
            ]);
        } catch (Throwable $e) {
            // notificações opcionais
        }

        return ['ok' => true];
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) === 1062) {
            return ['ok' => false, 'error' => 'Já avaliou esta missão.'];
        }
        error_log('reputacao_registrar_avaliacao: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Erro ao guardar avaliação.'];
    }
}

/** Markup + JS mínimo para input de estrelas (name do hidden). */
function reputacao_estrelas_input_html(string $inputId = 'avaliacao_input', string $inputName = 'avaliacao'): string
{
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $inputId) ?: 'avaliacao_input';
    $name = preg_replace('/[^a-zA-Z0-9_-]/', '', $inputName) ?: 'avaliacao';
    $stars = '';
    for ($i = 1; $i <= 5; $i++) {
        $stars .= '<i class="bi bi-star tm-star" data-value="' . $i . '" role="button" tabindex="0" aria-label="' . $i . ' estrela"></i>';
    }
    return <<<HTML
<div class="tm-rating-stars mb-2" data-target="{$id}" style="font-size:1.75rem;cursor:pointer;user-select:none;color:#fbbf24;letter-spacing:.15rem">
    {$stars}
</div>
<input type="hidden" name="{$name}" id="{$id}" value="0">
HTML;
}

function reputacao_estrelas_input_script(): string
{
    return <<<'JS'
<script>
(function () {
    function paint(container, value) {
        container.querySelectorAll('.tm-star').forEach(function (s, idx) {
            var on = idx < value;
            s.classList.toggle('bi-star-fill', on);
            s.classList.toggle('bi-star', !on);
        });
    }
    document.querySelectorAll('.tm-rating-stars').forEach(function (box) {
        var input = document.getElementById(box.getAttribute('data-target'));
        if (!input) return;
        box.querySelectorAll('.tm-star').forEach(function (star) {
            star.addEventListener('click', function () {
                var v = parseInt(star.getAttribute('data-value'), 10) || 0;
                input.value = String(v);
                paint(box, v);
            });
            star.addEventListener('mouseenter', function () {
                paint(box, parseInt(star.getAttribute('data-value'), 10) || 0);
            });
        });
        box.addEventListener('mouseleave', function () {
            paint(box, parseInt(input.value, 10) || 0);
        });
    });
})();
</script>
JS;
}
