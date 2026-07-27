<?php
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');

include_once('../../includes/auth.php');
include_once('../../includes/helpers.php');
include_once('../../includes/reputacao-helpers.php');

require_role(['empresa'], '../login.php');

// Verificar se o ID da missão foi fornecido
if (!isset($_GET['id'])) {
    header('Location: ' . BASE_URL . '/pages/contratante/missoes.php');
    exit;
}

$missao_id = intval($_GET['id']);
$user_id = $_SESSION['user_id'];

// Processar a conclusão da missão
if (isset($_POST['concluir_missao'])) {
    try {
        // Verificar se a missão pertence a este contratante e está aguardando confirmação
        $stmt = $conn->prepare("SELECT id, status, caminhoneiro_id, transportador_id FROM missoes WHERE id = ? AND empresa_id = ?");
        $stmt->execute([$missao_id, $user_id]);

        $missao_row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$missao_row) {
            $erro = "Missão não encontrada ou você não tem permissão para concluí-la.";
        } else {
            if (($missao_row['status'] ?? '') !== 'aguardando_confirmacao') {
                $erro = "A missão não está aguardando confirmação.";
            } else {
                $conn->beginTransaction();

                // Atualizar status da missão para concluída
                $sql = "UPDATE missoes SET 
                        status = 'concluida', 
                        status_viagem = 'finalizada',
                        data_chegada = NOW(),
                        data_atualizacao = NOW()
                        WHERE id = :missao_id";

                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    ':missao_id' => $missao_id,
                ]);

                // Registrar no log
                $stmt = $conn->prepare("INSERT INTO registros_viagem (missao_id, tipo, descricao, data_registro) VALUES (:missao_id, 'confirmacao_entrega', 'Entrega confirmada pela empresa', NOW())");
                $stmt->execute([':missao_id' => $missao_id]);

                // Se foi caminhoneiro, processar avaliação e disponibilidade
                if (!empty($missao_row['caminhoneiro_id'])) {
                    $notaPost = isset($_POST['avaliacao']) ? (int)$_POST['avaliacao'] : 0;
                    if ($notaPost >= 1 && $notaPost <= 5) {
                        reputacao_registrar_avaliacao(
                            $conn,
                            $missao_id,
                            (int)$user_id,
                            (int)$missao_row['caminhoneiro_id'],
                            $notaPost,
                            trim((string)($_POST['comentario_avaliacao'] ?? ''))
                        );
                    }

                    $sql = "UPDATE perfil_caminhoneiro 
                            SET disponibilidade = 'disponivel'
                            WHERE usuario_id = :caminhoneiro_id";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([':caminhoneiro_id' => $missao_row['caminhoneiro_id']]);
                }

                // Notificar responsável (transportador tem prioridade)
                $responsavel_id = null;
                $responsavel_tipo = null;
                if (!empty($missao_row['transportador_id'])) {
                    $responsavel_id = (int)$missao_row['transportador_id'];
                    $responsavel_tipo = 'transportador';
                } elseif (!empty($missao_row['caminhoneiro_id'])) {
                    $responsavel_id = (int)$missao_row['caminhoneiro_id'];
                    $responsavel_tipo = 'caminhoneiro';
                }

                if (!empty($responsavel_id)) {
                    $stmt = $conn->prepare("INSERT INTO notificacoes (usuario_id, tipo, titulo, mensagem, link, data_criacao, lida)
                            VALUES (:usuario_id, 'confirmacao_entrega', 'Entrega confirmada', :mensagem, :link, NOW(), 0)");
                    $stmt->execute([
                        ':usuario_id' => $responsavel_id,
                        ':mensagem' => 'A empresa confirmou a entrega da missão #' . $missao_id . '.',
                        ':link' => BASE_URL . '/pages/' . $responsavel_tipo . '/detalhes-missao.php?id=' . $missao_id
                    ]);
                }

                $conn->commit();

                // Redirecionar para a página de missões após concluir
                header('Location: ' . BASE_URL . '/pages/contratante/missoes.php?success=Missão concluída com sucesso!');
                exit;
            }
        }
    } catch (PDOException $e) {
        if (isset($conn) && $conn->inTransaction()) {
            $conn->rollBack();
        }
        $erro = "Erro ao concluir missão: " . $e->getMessage();
        error_log($erro);
    }
}

// Buscar detalhes da missão
try {
    $sql = "SELECT m.*, 
            m.origem as endereco_origem, NULL as origem_lat, NULL as origem_lng,
            m.destino as endereco_destino, NULL as destino_lat, NULL as destino_lng,
            u.nome as nome_caminhoneiro, u.telefone as telefone_caminhoneiro, u.email as email_caminhoneiro,
            u.id as caminhoneiro_id,
            pc.tipo_veiculo, pc.placa_veiculo, pc.capacidade_carga,
            pc.ultima_localizacao_lat, pc.ultima_localizacao_lng, pc.ultima_atualizacao_local
            FROM missoes m
            LEFT JOIN usuarios u ON m.caminhoneiro_id = u.id
            LEFT JOIN perfil_caminhoneiro pc ON u.id = pc.usuario_id
            WHERE m.id = ? AND m.empresa_id = ?";
            
    $stmt = $conn->prepare($sql);
    $stmt->execute([$missao_id, $user_id]);
    $missao = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$missao) {
        header('Location: ' . BASE_URL . '/pages/contratante/missoes.php?error=Missão não encontrada');
        exit;
    }
    
    // Buscar log de eventos da missão
    $sql = "SELECT * FROM registros_viagem WHERE missao_id = ? ORDER BY data_registro DESC";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$missao_id]);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $erro = "Erro ao buscar detalhes da missão: " . $e->getMessage();
    error_log($erro);
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detalhes da Missão - TrackMoz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    
    <style>
        #map {
            height: 400px;
            width: 100%;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .status-badge {
            font-size: 1rem;
            padding: 8px 12px;
        }
        .timeline {
            position: relative;
            padding-left: 30px;
        }
        .timeline::before {
            content: '';
            position: absolute;
            left: 10px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #dee2e6;
        }
        .timeline-item {
            position: relative;
            margin-bottom: 20px;
        }
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -30px;
            top: 5px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #0d6efd;
        }
        .tracking-detail {
            padding: 15px;
            border-radius: 8px;
            background-color: #f8f9fa;
            margin-bottom: 15px;
        }
        .rating-stars {
            font-size: 24px;
            color: #aaa;
            cursor: pointer;
        }
        .rating-stars .bi-star-fill {
            color: #ffc107;
        }
    </style>
</head>
<body>
    <?php include_once('../../includes/menu.php'); ?>

    <div class="container mt-4">
        <?php if (isset($erro)): ?>
            <div class="alert alert-danger" role="alert">
                <?php echo $erro; ?>
            </div>
        <?php endif; ?>
        
        <div class="card mb-4">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h2 class="card-title">
                        <i class="bi bi-truck"></i> Missão #<?php echo $missao_id; ?>
                    </h2>
                    <span class="badge status-badge bg-<?php
                        switch($missao['status']) {
                            case 'pendente': echo 'warning'; break;
                            case 'em_transito': echo 'primary'; break;
                            case 'em_entrega': echo 'info'; break;
                            case 'concluida': echo 'success'; break;
                            case 'cancelada': echo 'danger'; break;
                            case 'emergencia': echo 'danger'; break;
                            default: echo 'secondary';
                        }
                    ?>">
                        <?php 
                            switch($missao['status']) {
                                case 'pendente': echo 'Pendente'; break;
                                case 'em_transito': echo 'Em Trânsito'; break;
                                case 'em_entrega': echo 'Em Entrega'; break;
                                case 'concluida': echo 'Concluída'; break;
                                case 'cancelada': echo 'Cancelada'; break;
                                case 'emergencia': echo 'Emergência'; break;
                                default: echo ucfirst($missao['status']);
                            }
                        ?>
                    </span>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <h5 class="mb-3">Detalhes da Carga</h5>
                        <div class="mb-3">
                            <p class="mb-1"><strong>Descrição:</strong></p>
                            <p><?php echo htmlspecialchars($missao['descricao_carga']); ?></p>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <p class="mb-1"><strong>Tipo de Carga:</strong></p>
                                <p><?php echo htmlspecialchars($missao['tipo_carga']); ?></p>
                            </div>
                            <div class="col-md-6">
                                <p class="mb-1"><strong>Peso (kg):</strong></p>
                                <p><?php echo isset($missao['peso']) ? number_format($missao['peso'], 2, ',', '.') : (isset($missao['peso_carga']) ? number_format($missao['peso_carga'], 2, ',', '.') : 'N/D'); ?></p>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <p class="mb-1"><strong>Valor do Frete:</strong></p>
                                <p><?php echo 'MZN ' . number_format($missao['valor'], 2, ',', '.'); ?></p>
                            </div>
                            <div class="col-md-6">
                                <p class="mb-1"><strong>Data Criação:</strong></p>
                                <p><?php echo date('d/m/Y', strtotime($missao['data_criacao'])); ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <h5 class="mb-3">Locais</h5>
                        <div class="mb-3">
                            <p class="mb-1"><strong><i class="bi bi-geo-alt text-success"></i> Origem:</strong></p>
                            <p><?php echo htmlspecialchars($missao['endereco_origem']); ?></p>
                        </div>
                        <div class="mb-3">
                            <p class="mb-1"><strong><i class="bi bi-geo-alt-fill text-danger"></i> Destino:</strong></p>
                            <p><?php echo htmlspecialchars($missao['endereco_destino']); ?></p>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <p class="mb-1"><strong>Distância:</strong></p>
                                <p><?php echo isset($missao['distancia']) ? (number_format(((float)$missao['distancia']) / 1000, 1, ',', '.') . ' km') : 'N/D'; ?></p>
                            </div>
                            <div class="col-md-6">
                                <p class="mb-1"><strong>Prazo de Entrega:</strong></p>
                                <p><?php echo !empty($missao['prazo_entrega']) ? date('d/m/Y', strtotime($missao['prazo_entrega'])) : 'N/D'; ?></p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <hr>
                
                <div class="row">
                    <div class="col-md-6">
                        <h5 class="mb-3">Informações do Caminhoneiro</h5>
                        <?php if ($missao['caminhoneiro_id']): ?>
                            <div class="mb-3">
                                <p class="mb-1"><strong>Nome:</strong></p>
                                <p><?php echo htmlspecialchars($missao['nome_caminhoneiro']); ?></p>
                            </div>
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <p class="mb-1"><strong>Telefone:</strong></p>
                                    <p><?php echo htmlspecialchars($missao['telefone_caminhoneiro']); ?></p>
                                </div>
                                <div class="col-md-6">
                                    <p class="mb-1"><strong>Email:</strong></p>
                                    <p><?php echo htmlspecialchars($missao['email_caminhoneiro']); ?></p>
                                </div>
                            </div>
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <p class="mb-1"><strong>Veículo:</strong></p>
                                    <p><?php echo htmlspecialchars($missao['tipo_veiculo']); ?></p>
                                </div>
                                <div class="col-md-6">
                                    <p class="mb-1"><strong>Placa:</strong></p>
                                    <p><?php echo htmlspecialchars($missao['placa_veiculo']); ?></p>
                                </div>
                            </div>
                        <?php else: ?>
                            <p class="text-muted">Nenhum caminhoneiro atribuído a esta missão.</p>
                        <?php endif; ?>
                    </div>
                    
                    <div class="col-md-6">
                        <h5 class="mb-3">Status da Entrega</h5>
                        <div class="timeline">
                            <?php if (!empty($logs)): ?>
                                <?php foreach ($logs as $log): ?>
                                    <div class="timeline-item">
                                        <small class="text-muted"><?php echo date('d/m/Y H:i', strtotime($log['data_registro'])); ?></small>
                                        <p class="mb-0"><strong><?php echo htmlspecialchars($log['descricao']); ?></strong></p>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="text-muted">Nenhum registro de atividade para esta missão.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <?php if (($missao['status'] === 'em_transito' || $missao['status'] === 'em_entrega') && !empty($missao['ultima_localizacao_lat']) && !empty($missao['ultima_localizacao_lng'])): ?>
                    <hr>
                    <div class="mb-4">
                        <h5 class="mb-3">Localização Atual do Caminhoneiro</h5>
                        <div id="map"></div>
                    </div>
                <?php endif; ?>
                
                <?php if ($missao['caminhoneiro_id'] && in_array($missao['status'], ['em_entrega', 'em_transito', 'em_andamento', 'aguardando_confirmacao'], true)): ?>
                    <div class="card-footer bg-white">
                        <h5 class="mb-3">Confirmar Conclusão da Entrega</h5>
                        <p class="small text-muted">
                            Prefere o fluxo completo com estrelas?
                            <a href="concluir-missao.php?id=<?php echo (int)$missao_id; ?>">Abrir confirmação e avaliação</a>
                        </p>
                        <form method="POST" action="" id="formConcluirVisualizar">
                            <input type="hidden" name="caminhoneiro_id" value="<?php echo $missao['caminhoneiro_id']; ?>">
                            
                            <div class="mb-3">
                                <label for="avaliacao" class="form-label">Avaliação do Caminhoneiro *</label>
                                <div class="rating-stars mb-2" id="rating" style="font-size:1.75rem;cursor:pointer;color:#fbbf24;letter-spacing:.15rem">
                                    <i class="bi bi-star" data-value="1"></i>
                                    <i class="bi bi-star" data-value="2"></i>
                                    <i class="bi bi-star" data-value="3"></i>
                                    <i class="bi bi-star" data-value="4"></i>
                                    <i class="bi bi-star" data-value="5"></i>
                                </div>
                                <input type="hidden" name="avaliacao" id="avaliacao_input" value="0">
                            </div>
                            
                            <div class="mb-3">
                                <label for="comentario_avaliacao" class="form-label">Comentário (opcional)</label>
                                <textarea class="form-control" id="comentario_avaliacao" name="comentario_avaliacao" rows="2" placeholder="Deixe um comentário sobre o serviço prestado"></textarea>
                            </div>
                            
                            <div class="mb-3">
                                <label for="observacoes" class="form-label">Observações da Conclusão</label>
                                <textarea class="form-control" id="observacoes" name="observacoes" rows="2" placeholder="Adicione observações sobre a conclusão da entrega"></textarea>
                            </div>
                            
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" id="confirma_conclusao" required>
                                <label class="form-check-label" for="confirma_conclusao">
                                    Confirmo que a entrega foi concluída com sucesso e todas as mercadorias foram recebidas conforme o esperado.
                                </label>
                            </div>
                            
                            <button type="submit" name="concluir_missao" class="btn btn-success">
                                <i class="bi bi-check-circle"></i> Confirmar Conclusão da Missão
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // Estrelas sempre activas (não dependem do GPS / mapa)
        document.addEventListener('DOMContentLoaded', function () {
            const stars = document.querySelectorAll('.rating-stars i');
            const ratingInput = document.getElementById('avaliacao_input');
            if (!stars.length || !ratingInput) return;

            function paint(value) {
                stars.forEach((s, idx) => {
                    const on = idx < value;
                    s.classList.toggle('bi-star-fill', on);
                    s.classList.toggle('bi-star', !on);
                });
            }
            stars.forEach(star => {
                star.addEventListener('click', function () {
                    const value = parseInt(this.dataset.value, 10) || 0;
                    ratingInput.value = String(value);
                    paint(value);
                });
                star.addEventListener('mouseenter', function () {
                    paint(parseInt(this.dataset.value, 10) || 0);
                });
            });
            document.getElementById('rating')?.addEventListener('mouseleave', function () {
                paint(parseInt(ratingInput.value, 10) || 0);
            });
            document.getElementById('formConcluirVisualizar')?.addEventListener('submit', function (e) {
                if (parseInt(ratingInput.value, 10) < 1) {
                    e.preventDefault();
                    alert('Seleccione uma classificação de 1 a 5 estrelas.');
                }
            });
        });
    </script>
    
    <?php if (($missao['status'] === 'em_transito' || $missao['status'] === 'em_entrega') && !empty($missao['ultima_localizacao_lat']) && !empty($missao['ultima_localizacao_lng'])): ?>
    <script>
        // Mapa para mostrar a localização do caminhoneiro
        function initMap() {
            const caminhoneiroLat = <?php echo (float)($missao['ultima_localizacao_lat'] ?? 0); ?>;
            const caminhoneiroLng = <?php echo (float)($missao['ultima_localizacao_lng'] ?? 0); ?>;
            const origemLat = <?php echo (float)$missao['origem_lat']; ?>;
            const origemLng = <?php echo (float)$missao['origem_lng']; ?>;
            const destinoLat = <?php echo (float)$missao['destino_lat']; ?>;
            const destinoLng = <?php echo (float)$missao['destino_lng']; ?>;
            
            if (!caminhoneiroLat || !caminhoneiroLng || !document.getElementById('map')) {
                return;
            }
            
            // Coordenadas para centralizar o mapa
            const centerLat = caminhoneiroLat ? caminhoneiroLat : (origemLat + destinoLat) / 2;
            const centerLng = caminhoneiroLng ? caminhoneiroLng : (origemLng + destinoLng) / 2;
            
            const map = new google.maps.Map(document.getElementById('map'), {
                zoom: 10,
                center: { lat: centerLat, lng: centerLng }
            });
            
            if (origemLat && origemLng) {
                new google.maps.Marker({
                    position: { lat: origemLat, lng: origemLng },
                    map: map,
                    icon: {
                        url: 'http://maps.google.com/mapfiles/ms/icons/green-dot.png'
                    },
                    title: 'Origem'
                });
            }

            if (destinoLat && destinoLng) {
                new google.maps.Marker({
                    position: { lat: destinoLat, lng: destinoLng },
                    map: map,
                    icon: {
                        url: 'http://maps.google.com/mapfiles/ms/icons/red-dot.png'
                    },
                    title: 'Destino'
                });
            }
            
            // Marcador do caminhoneiro (se houver localização)
            if (caminhoneiroLat && caminhoneiroLng) {
                const lastUpdate = <?php echo isset($missao['ultima_atualizacao_local']) ? 
                    "'" . date('d/m/Y H:i', strtotime($missao['ultima_atualizacao_local'])) . "'" : 'null'; ?>;
                
                const caminhoneiroMarker = new google.maps.Marker({
                    position: { lat: caminhoneiroLat, lng: caminhoneiroLng },
                    map: map,
                    icon: {
                        path: google.maps.SymbolPath.CIRCLE,
                        scale: 10,
                        fillColor: '#4285F4',
                        fillOpacity: 1,
                        strokeColor: '#FFFFFF',
                        strokeWeight: 2
                    },
                    title: 'Localização atual do caminhoneiro\nÚltima atualização: ' + lastUpdate
                });
                
                // Centralizar no caminhoneiro e ajustar zoom
                map.setCenter({ lat: caminhoneiroLat, lng: caminhoneiroLng });
                map.setZoom(14);
            }
            
            // Traçar rota completa
            const directionsService = new google.maps.DirectionsService();
            const directionsRenderer = new google.maps.DirectionsRenderer({
                map: map,
                suppressMarkers: true,
                polylineOptions: {
                    strokeColor: "#90CAF9",
                    strokeWeight: 4,
                    strokeOpacity: 0.7
                }
            });
            
            if (origemLat && origemLng && destinoLat && destinoLng) {
                const request = {
                    origin: { lat: origemLat, lng: origemLng },
                    destination: { lat: destinoLat, lng: destinoLng },
                    travelMode: 'DRIVING'
                };
                
                directionsService.route(request, function(result, status) {
                    if (status == 'OK') {
                        directionsRenderer.setDirections(result);
                    }
                });
            }
            
            // Atualizar o mapa a cada minuto
            setInterval(function() {
                location.reload();
            }, 60000);
        }
    </script>
    
    <script async defer src="https://maps.googleapis.com/maps/api/js?key=AIzaSyAKpY7mdPAvQ9wXq191e_Dj5FJK0bZRxvo&callback=initMap"></script>
    <?php endif; ?>
</body>
</html> 