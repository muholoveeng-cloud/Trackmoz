<?php
session_start();
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/pages/login.php');
    exit;
}

$rotas = [
    'caminhoneiro'  => BASE_URL . '/pages/caminhoneiro/perfil.php',
    'transportador' => BASE_URL . '/pages/transportador/perfil.php',
    'empresa'       => BASE_URL . '/pages/contratante/perfil.php',
    'admin'         => BASE_URL . '/pages/admin/perfil.php',
];

$tipo = $_SESSION['user_type'] ?? null;

if (!$tipo) {
    try {
        $stmt = getConnection()->prepare('SELECT tipo_usuario FROM usuarios WHERE id = ?');
        $stmt->execute([(int)$_SESSION['user_id']]);
        $tipo = $stmt->fetchColumn() ?: null;
        if ($tipo) {
            $_SESSION['user_type'] = $tipo;
        }
    } catch (Throwable $e) {
        error_log('perfil.php: ' . $e->getMessage());
    }
}

if ($tipo && isset($rotas[$tipo])) {
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    $destino = $rotas[$tipo] . ($qs !== '' ? '?' . $qs : '');
    header('Location: ' . $destino);
    exit;
}

http_response_code(400);
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Perfil — TrackMoz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="alert alert-danger">Não foi possível abrir o perfil. Tipo de utilizador inválido.</div>
    <a href="<?php echo BASE_URL; ?>/index.php" class="btn btn-primary">Voltar ao Dashboard</a>
</div>
</body>
</html>
