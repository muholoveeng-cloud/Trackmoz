<?php
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');
include_once('../../includes/auth.php');
include_once('../../includes/helpers.php');
include_once('../../includes/kyc-helpers.php');
include_once('../../includes/notificacoes-helpers.php');

require_role(['admin'], '../login.php');
kyc_bootstrap($conn);

$success = $error = '';

// Processar aprovação ou rejeição de documentos
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (isset($_POST['aprovar_documento']) && isset($_POST['documento_id'])) {
        $documento_id = (int)$_POST['documento_id'];
        
        try {
            kyc_bootstrap($conn);
            $sql = "UPDATE documentos 
                    SET status = 'aprovado', bloqueado = 1 
                    WHERE id = :id";
            $stmt = $conn->prepare($sql);
            $stmt->execute([':id' => $documento_id]);

            $sql = "SELECT usuario_id, tipo_documento FROM documentos WHERE id = :id";
            $stmt = $conn->prepare($sql);
            $stmt->execute([':id' => $documento_id]);
            $documento = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($documento) {
                $tiposDocumentos = array_merge(
                    kyc_documentos_obrigatorios('caminhoneiro'),
                    kyc_documentos_obrigatorios('empresa'),
                    ['outros' => 'Documento']
                );
                $tipoTexto = $tiposDocumentos[$documento['tipo_documento']] ?? 'Documento';
                
                notificar_usuario(
                    $conn,
                    (int)$documento['usuario_id'],
                    'sucesso',
                    'Documento aprovado',
                    "O seu {$tipoTexto} foi verificado e aprovado.",
                    kyc_url_verificacao()
                );

                $reav = kyc_reavaliar_apos_doc($conn, (int)$documento['usuario_id'], (int)$_SESSION['user_id']);
                if (!empty($reav['verificado'])) {
                    $success = 'Documento aprovado. Conta do utilizador ficou VERIFICADA — já pode operar.';
                } else {
                    $success = 'Documento aprovado. Ainda faltam documentos ou dados para verificação completa.';
                }
            } else {
                $success = 'Documento aprovado com sucesso!';
            }
        } catch (PDOException $e) {
            $error = "Erro ao aprovar documento: " . $e->getMessage();
        }
    } elseif (isset($_POST['rejeitar_documento']) && isset($_POST['documento_id']) && isset($_POST['motivo_rejeicao'])) {
        $documento_id = (int)$_POST['documento_id'];
        $motivo = trim((string)$_POST['motivo_rejeicao']);
        
        if ($motivo === '') {
            $error = "Por favor, forneça um motivo para a rejeição.";
        } else {
            try {
                kyc_bootstrap($conn);
                $sql = "UPDATE documentos 
                        SET status = 'rejeitado', bloqueado = 0 
                        WHERE id = :id";
                $stmt = $conn->prepare($sql);
                $stmt->execute([':id' => $documento_id]);
                
                $sql = "SELECT usuario_id, tipo_documento FROM documentos WHERE id = :id";
                $stmt = $conn->prepare($sql);
                $stmt->execute([':id' => $documento_id]);
                $documento = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($documento) {
                    $tiposDocumentos = [
                        'bi' => 'Bilhete de Identidade',
                        'cnh' => 'Carta de Condução',
                        'alvara' => 'Licença / Alvará',
                        'registro_empresa' => 'NUIT / Registo',
                        'outros' => 'Documento'
                    ];
                    $tipoTexto = $tiposDocumentos[$documento['tipo_documento']] ?? 'Documento';
                    
                    notificar_usuario(
                        $conn,
                        (int)$documento['usuario_id'],
                        'alerta',
                        'Documento rejeitado',
                        "O seu {$tipoTexto} foi rejeitado. Motivo: {$motivo}. Envie um novo documento.",
                        kyc_url_verificacao()
                    );

                    $conn->prepare('UPDATE usuarios SET kyc_motivo_rejeicao = ? WHERE id = ?')
                         ->execute([$motivo, (int)$documento['usuario_id']]);
                    kyc_reavaliar_apos_doc($conn, (int)$documento['usuario_id'], (int)$_SESSION['user_id']);
                }
                
                $success = "Documento rejeitado com sucesso!";
            } catch (PDOException $e) {
                $error = "Erro ao rejeitar documento: " . $e->getMessage();
            }
        }
    }
}

// Buscar documentos pendentes
try {
    // Configurar filtros
    $status = isset($_GET['status']) ? $_GET['status'] : 'pendente';
    $tipo = isset($_GET['tipo']) ? $_GET['tipo'] : 'todos';
    $filtroUsuario = isset($_GET['usuario_id']) ? (int)$_GET['usuario_id'] : 0;
    
    $where = "WHERE 1=1";
    
    if ($status !== 'todos') {
        $where .= " AND d.status = :status";
    }
    
    if ($tipo !== 'todos') {
        $where .= " AND d.tipo_documento = :tipo";
    }

    if ($filtroUsuario > 0) {
        $where .= " AND d.usuario_id = :uid";
    }
    
    $sql = "SELECT d.*, u.nome as nome_usuario, u.email as email_usuario, u.estado_kyc,
                   DATE_FORMAT(d.data_upload, '%d/%m/%Y %H:%i') as data_formatada
            FROM documentos d
            JOIN usuarios u ON d.usuario_id = u.id
            $where
            ORDER BY d.data_upload DESC";
    
    $stmt = $conn->prepare($sql);
    
    if ($status !== 'todos') {
        $stmt->bindValue(':status', $status);
    }
    if ($tipo !== 'todos') {
        $stmt->bindValue(':tipo', $tipo);
    }
    if ($filtroUsuario > 0) {
        $stmt->bindValue(':uid', $filtroUsuario, PDO::PARAM_INT);
    }
    
    $stmt->execute();
    $documentos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Contar documentos por status
    $sql = "SELECT status, COUNT(*) as total FROM documentos GROUP BY status";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $contagem = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $total_pendentes = $contagem['pendente'] ?? 0;
    $total_aprovados = $contagem['aprovado'] ?? 0;
    $total_rejeitados = $contagem['rejeitado'] ?? 0;
    
} catch (PDOException $e) {
    $error = "Erro ao carregar documentos: " . $e->getMessage();
    $documentos = [];
}

// Array com os tipos de documentos
$tiposDocumentos = [
    'bi' => 'Bilhete de Identidade',
    'cnh' => 'Carta de Condução',
    'alvara' => 'Alvará / Licença',
    'registro_empresa' => 'NUIT / Registo comercial',
    'outros' => 'Outros Documentos'
];

// Contagens KYC para destaque
$kycEmAnalise = 0;
try {
    $kycEmAnalise = (int)$conn->query(
        "SELECT COUNT(*) FROM usuarios WHERE estado_kyc = 'em_analise'
         AND tipo_usuario IN ('caminhoneiro','empresa','transportador')"
    )->fetchColumn();
} catch (Throwable $e) { /* ignore */ }
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificação de Documentos - TrackMoz Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin.css">
    <style>
        .document-card {
            transition: transform 0.2s;
        }
        .document-card:hover {
            transform: translateY(-5px);
        }
        .status-pill {
            white-space: nowrap;
        }
        .preview-container {
            text-align: center;
            margin-bottom: 15px;
        }
        .preview-container img {
            max-width: 100%;
            max-height: 300px;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body>
    <?php include_once('../../includes/menu.php'); ?>

    <div class="container-fluid mt-4 px-4">
        <div class="row">
            <div class="col-lg-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h2 class="mb-1">
                            <i class="bi bi-file-earmark-check"></i> Análise de Documentos
                        </h2>
                        <p class="text-muted mb-0 small">Aprove ou rejeite documentos KYC. Quando todos os obrigatórios forem aprovados, a conta passa a verificada.</p>
                    </div>
                    <div>
                        <a href="<?php echo BASE_URL; ?>/pages/admin/usuarios.php?status=pendente" class="btn btn-outline-warning me-2">
                            <i class="bi bi-people"></i> Utilizadores pendentes
                        </a>
                        <a href="<?php echo BASE_URL; ?>/pages/admin/dashboard.php" class="btn btn-outline-primary">
                            <i class="bi bi-speedometer2"></i> Dashboard
                        </a>
                    </div>
                </div>

                <?php if ($kycEmAnalise > 0 || $total_pendentes > 0): ?>
                <div class="alert alert-danger d-flex align-items-center gap-2">
                    <i class="bi bi-exclamation-triangle-fill fs-4"></i>
                    <div>
                        <strong>Atenção:</strong>
                        <?php echo (int)$total_pendentes; ?> documento(s) pendente(s)
                        · <?php echo (int)$kycEmAnalise; ?> utilizador(es) em análise KYC.
                        Analise e decida (aprovar / rejeitar) para desbloquear a operação.
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($success)): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo $success; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo $error; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="card bg-light">
                                    <div class="card-body text-center">
                                        <h5 class="card-title">Pendentes</h5>
                                        <h2 class="text-warning mb-0"><?php echo $total_pendentes; ?></h2>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card bg-light">
                                    <div class="card-body text-center">
                                        <h5 class="card-title">Aprovados</h5>
                                        <h2 class="text-success mb-0"><?php echo $total_aprovados; ?></h2>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card bg-light">
                                    <div class="card-body text-center">
                                        <h5 class="card-title">Rejeitados</h5>
                                        <h2 class="text-danger mb-0"><?php echo $total_rejeitados; ?></h2>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <div class="row">
                            <div class="col-md-6">
                                <h5 class="mb-0">Lista de Documentos</h5>
                            </div>
                            <div class="col-md-6">
                                <div class="d-flex justify-content-end">
                                    <div class="btn-group me-2">
                                        <a href="?status=todos&tipo=<?php echo $tipo; ?>" class="btn btn-sm btn-outline-secondary <?php echo $status === 'todos' ? 'active' : ''; ?>">
                                            Todos
                                        </a>
                                        <a href="?status=pendente&tipo=<?php echo $tipo; ?>" class="btn btn-sm btn-outline-secondary <?php echo $status === 'pendente' ? 'active' : ''; ?>">
                                            Pendentes
                                        </a>
                                        <a href="?status=aprovado&tipo=<?php echo $tipo; ?>" class="btn btn-sm btn-outline-secondary <?php echo $status === 'aprovado' ? 'active' : ''; ?>">
                                            Aprovados
                                        </a>
                                        <a href="?status=rejeitado&tipo=<?php echo $tipo; ?>" class="btn btn-sm btn-outline-secondary <?php echo $status === 'rejeitado' ? 'active' : ''; ?>">
                                            Rejeitados
                                        </a>
                                    </div>
                                    
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" id="tipoDocumentoDropdown" data-bs-toggle="dropdown">
                                            Tipo: <?php echo $tipo === 'todos' ? 'Todos' : ($tiposDocumentos[$tipo] ?? $tipo); ?>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li>
                                                <a class="dropdown-item" href="?status=<?php echo $status; ?>&tipo=todos">
                                                    Todos os tipos
                                                </a>
                                            </li>
                                            <?php foreach ($tiposDocumentos as $key => $value): ?>
                                                <li>
                                                    <a class="dropdown-item" href="?status=<?php echo $status; ?>&tipo=<?php echo $key; ?>">
                                                        <?php echo $value; ?>
                                                    </a>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card-body">
                        <?php if (empty($documentos)): ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i>
                                Nenhum documento encontrado com os filtros selecionados.
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Usuário</th>
                                            <th>Tipo</th>
                                            <th>Documento</th>
                                            <th>Data de Upload</th>
                                            <th>Status</th>
                                            <th>Ações</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($documentos as $documento): ?>
                                            <tr>
                                                <td><?php echo $documento['id']; ?></td>
                                                <td>
                                                    <div>
                                                        <strong><?php echo htmlspecialchars($documento['nome_usuario']); ?></strong>
                                                        <?php if (!empty($documento['estado_kyc'])): ?>
                                                            <span class="badge bg-<?php echo $documento['estado_kyc'] === 'em_analise' ? 'info' : ($documento['estado_kyc'] === 'verificado' ? 'success' : 'secondary'); ?>">
                                                                <?php echo e(kyc_estado_label($documento['estado_kyc'])); ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <small class="text-muted"><?php echo e($documento['email_usuario']); ?></small>
                                                </td>
                                                <td>
                                                    <?php echo $tiposDocumentos[$documento['tipo_documento']] ?? $documento['tipo_documento']; ?>
                                                </td>
                                                <td>
                                                    <a href="<?php echo documento_view_url((int)$documento['id']); ?>" class="view-document me-1" target="_blank">
                                                        <i class="bi bi-eye"></i>
                                                    </a>
                                                    <a href="#" class="view-document" data-bs-toggle="modal" 
                                                       data-bs-target="#documentModal" 
                                                       data-document-url="<?php echo e(documento_view_url((int)$documento['id']) . '&raw=1'); ?>"
                                                       data-document-page="<?php echo e(documento_view_url((int)$documento['id'])); ?>"
                                                       data-document-name="<?php echo e($documento['nome_arquivo']); ?>"
                                                       data-document-extension="<?php echo strtolower(pathinfo($documento['nome_arquivo'], PATHINFO_EXTENSION)); ?>">
                                                        <?php echo e($documento['nome_arquivo']); ?>
                                                    </a>
                                                </td>
                                                <td><?php echo $documento['data_formatada']; ?></td>
                                                <td>
                                                    <?php if ($documento['status'] === 'pendente'): ?>
                                                        <span class="badge bg-warning status-pill">Pendente</span>
                                                    <?php elseif ($documento['status'] === 'aprovado'): ?>
                                                        <span class="badge bg-success status-pill">Aprovado</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger status-pill">Rejeitado</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($documento['status'] === 'pendente'): ?>
                                                        <div class="btn-group">
                                                            <form method="POST" class="d-inline">
                                                                <?php echo csrf_field(); ?>
                                                                <input type="hidden" name="documento_id" value="<?php echo $documento['id']; ?>">
                                                                <button type="submit" name="aprovar_documento" class="btn btn-sm btn-success me-1" title="Aprovar">
                                                                    <i class="bi bi-check-circle"></i>
                                                                </button>
                                                            </form>
                                                            <button type="button" class="btn btn-sm btn-danger" title="Rejeitar" 
                                                                    data-bs-toggle="modal" data-bs-target="#rejectModal" 
                                                                    data-documento-id="<?php echo $documento['id']; ?>">
                                                                <i class="bi bi-x-circle"></i>
                                                            </button>
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="text-muted">Processado</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal de visualização de documento -->
    <div class="modal fade doc-preview-modal" id="documentModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="documentModalLabel">Visualizar Documento</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="preview-container">
                        <!-- Preview will be loaded here -->
                    </div>
                </div>
                <div class="modal-footer">
                    <a href="#" id="downloadDocumentBtn" class="btn btn-primary" download>
                        <i class="bi bi-download"></i> Baixar
                    </a>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal de rejeição -->
    <div class="modal fade" id="rejectModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Rejeitar Documento</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <div class="modal-body">
                        <input type="hidden" name="documento_id" id="rejectDocumentoId">
                        <div class="mb-3">
                            <label for="motivo_rejeicao" class="form-label">Motivo da Rejeição</label>
                            <textarea class="form-control" id="motivo_rejeicao" name="motivo_rejeicao" rows="3" required></textarea>
                            <div class="form-text">
                                Explique por que o documento está sendo rejeitado para que o usuário possa corrigir o problema.
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" name="rejeitar_documento" class="btn btn-danger">Rejeitar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Modal de visualização de documento
            const documentModal = document.getElementById('documentModal');
            const previewContainer = documentModal.querySelector('.preview-container');
            const downloadBtn = document.getElementById('downloadDocumentBtn');
            
            documentModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const documentUrl = button.getAttribute('data-document-url');
                const documentPage = button.getAttribute('data-document-page');
                const documentName = button.getAttribute('data-document-name');
                const extension = button.getAttribute('data-document-extension');
                
                downloadBtn.href = documentPage + '&download=1';
                downloadBtn.removeAttribute('download');
                
                previewContainer.innerHTML = '';
                
                if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(extension)) {
                    const img = document.createElement('img');
                    img.src = documentUrl;
                    img.alt = documentName;
                    previewContainer.appendChild(img);
                } else if (extension === 'pdf') {
                    const iframe = document.createElement('iframe');
                    iframe.src = documentUrl;
                    iframe.title = documentName;
                    previewContainer.appendChild(iframe);
                } else {
                    previewContainer.innerHTML = '<div class="alert alert-info m-3 text-white">Pré-visualização indisponível. <a href="' + documentPage + '" class="alert-link">Abrir página do documento</a></div>';
                }
            });
            
            // Modal de rejeição
            const rejectModal = document.getElementById('rejectModal');
            const rejectDocumentoIdInput = document.getElementById('rejectDocumentoId');
            
            rejectModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const documentoId = button.getAttribute('data-documento-id');
                rejectDocumentoIdInput.value = documentoId;
            });
        });
    </script>
</body>
</html> 