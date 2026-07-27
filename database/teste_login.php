<?php
require_once 'C:/wamp64/www/trackmoz/config/database.php';

$stmt = $conn->query('SELECT email, senha FROM usuarios LIMIT 1');
$u = $stmt->fetch(PDO::FETCH_ASSOC);
echo $u['email'] . ' - ' . (password_verify('TrackMoz2026!', $u['senha']) ? 'LOGIN OK' : 'FALHA') . "\n";

// Testar mais alguns usuarios
$stmt2 = $conn->query("SELECT email, senha, tipo_usuario FROM usuarios WHERE tipo_usuario IN ('empresa', 'caminhoneiro', 'transportador') ORDER BY tipo_usuario LIMIT 6");
while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
    echo $row['tipo_usuario'] . ': ' . $row['email'] . ' - ' . (password_verify('TrackMoz2026!', $row['senha']) ? 'OK' : 'FALHA') . "\n";
}
