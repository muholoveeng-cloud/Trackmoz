<?php
/**
 * Ligação à base de dados.
 *
 * Offline (WAMP): usa defaults localhost / root
 * Online (site.je): cria config/database.local.php NO SERVIDOR (não vai no Git)
 *   → o deploy FTP não apaga esse ficheiro
 */

function envValue($key, $default = null) {
    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
        return $_SERVER[$key];
    }
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
        return $_ENV[$key];
    }
    $value = getenv($key);
    if ($value !== false && $value !== '') {
        return $value;
    }
    return $default;
}

// Defaults = desenvolvimento local (WAMP)
$dbHost = envValue('DB_HOST', 'localhost');
$dbPort = envValue('DB_PORT', '3306');
$dbUser = envValue('DB_USER', 'root');
$dbPass = envValue('DB_PASS', '');
$dbName = envValue('DB_NAME', 'crbhlspv_trackmoz');

// Override por ambiente (servidor ou PC) — ficheiro fora do Git
$localDb = __DIR__ . '/database.local.php';
if (is_file($localDb)) {
    $local = include $localDb;
    if (is_array($local)) {
        if (!empty($local['host'])) $dbHost = (string)$local['host'];
        if (!empty($local['port'])) $dbPort = (string)$local['port'];
        if (!empty($local['user'])) $dbUser = (string)$local['user'];
        if (array_key_exists('pass', $local)) $dbPass = (string)$local['pass'];
        if (!empty($local['name'])) $dbName = (string)$local['name'];
    }
}

if (!defined('DB_HOST')) define('DB_HOST', $dbHost);
if (!defined('DB_PORT')) define('DB_PORT', $dbPort);
if (!defined('DB_USER')) define('DB_USER', $dbUser);
if (!defined('DB_PASS')) define('DB_PASS', $dbPass);
if (!defined('DB_NAME')) define('DB_NAME', $dbName);

$conn = null;

function getConnection() {
    global $conn;

    if ($conn === null) {
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            $conn = new PDO(
                $dsn,
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $e) {
            error_log('Erro na conexão DB: ' . $e->getMessage());
            http_response_code(500);
            echo 'Erro interno. Não foi possível ligar à base de dados.';
            exit;
        }
    }

    return $conn;
}

$conn = getConnection();
