<?php
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

define('DB_HOST', envValue('DB_HOST', 'localhost'));
define('DB_USER', envValue('DB_USER', 'root'));
define('DB_PASS', envValue('DB_PASS', ''));
define('DB_NAME', envValue('DB_NAME', 'crbhlspv_trackmoz'));

// Create a global connection variable
$conn = null;

// Function to get database connection
function getConnection() {
    global $conn;
    
    // If connection doesn't exist yet, create it
    if ($conn === null) {
        try {
            $conn = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch(PDOException $e) {
            // Não expor detalhes de conexão ao utilizador final
            error_log('Erro na conexão DB: ' . $e->getMessage());
            http_response_code(500);
            echo "Erro interno. Não foi possível ligar à base de dados.";
            exit;
        }
    }
    
    return $conn;
}

// Create initial connection
$conn = getConnection();
?>