<?php

function require_login(?string $redirectTo = null): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!defined('BASE_URL')) {
        $appPath = __DIR__ . '/../config/app.php';
        if (file_exists($appPath)) {
            include_once $appPath;
        }
    }

    if (!isset($_SESSION['user_id'])) {
        if ($redirectTo === null) {
            $redirectTo = defined('BASE_URL') ? (BASE_URL . '/pages/login.php') : '/pages/login.php';
        }
        header('Location: ' . $redirectTo);
        exit;
    }
}

function require_active_account(?string $redirectTo = null): void
{
    require_login($redirectTo);

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $dbPath = __DIR__ . '/../config/database.php';
    if (!file_exists($dbPath)) {
        return;
    }

    include_once $dbPath;
    require_once __DIR__ . '/regras-negocio.php';

    global $conn;
    if (!isset($conn)) {
        return;
    }

    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        return;
    }

    $validacao = validar_conta_ativa($conn, $userId);
    if ($validacao['ok']) {
        return;
    }

    session_unset();
    session_destroy();
    session_start();

    if ($redirectTo === null) {
        $redirectTo = defined('BASE_URL') ? (BASE_URL . '/pages/login.php') : '/pages/login.php';
    }
    $msg = urlencode(regras_erro_mensagem($validacao));
    header('Location: ' . $redirectTo . '?error=' . $msg);
    exit;
}

function require_role(array $allowedRoles, ?string $redirectTo = null): void
{
    require_login($redirectTo);

    $userType = $_SESSION['user_type'] ?? null;
    if ($userType === null || !in_array($userType, $allowedRoles, true)) {
        if ($redirectTo === null) {
            $redirectTo = defined('BASE_URL') ? (BASE_URL . '/index.php') : '/index.php';
        }
        header('Location: ' . $redirectTo);
        exit;
    }

    require_active_account($redirectTo);
}

function is_logged_in(): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    return isset($_SESSION['user_id']);
}

function is_role(array $allowedRoles): bool
{
    if (!is_logged_in()) {
        return false;
    }

    $userType = $_SESSION['user_type'] ?? null;
    return $userType !== null && in_array($userType, $allowedRoles, true);
}

function is_caminhoneiro_logged_in(): bool
{
    return is_role(['caminhoneiro']);
}
