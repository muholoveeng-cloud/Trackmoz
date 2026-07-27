<?php
// Inicia a sessão
session_start();

include_once('../config/app.php');

// Limpar todas as variáveis de sessão
$_SESSION = array();

// Destruir o cookie da sessão
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time()-3600, '/');
}

// Destruir a sessão
session_destroy();

// Redirecionar para a página de login
header('Location: ' . BASE_URL . '/pages/login.php');
exit;
?>