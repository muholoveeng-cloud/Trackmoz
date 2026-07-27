<?php
/**
 * Reset de passwords — uso local/dev apenas.
 * Admin → 123456 | restantes → 654321
 */
require_once __DIR__ . '/../config/database.php';

$conn = getConnection();

$hashAdmin = password_hash('123456', PASSWORD_DEFAULT);
$hashUser  = password_hash('654321', PASSWORD_DEFAULT);

$conn->beginTransaction();
try {
    $st = $conn->prepare("UPDATE usuarios SET senha = :s WHERE tipo_usuario = 'admin'");
    $st->execute([':s' => $hashAdmin]);
    $admins = $st->rowCount();

    $st = $conn->prepare("UPDATE usuarios SET senha = :s WHERE tipo_usuario <> 'admin'");
    $st->execute([':s' => $hashUser]);
    $others = $st->rowCount();

    $conn->commit();

    echo "Passwords actualizadas.\n";
    echo "Admins (123456): $admins\n";
    echo "Outros (654321): $others\n\n";

    echo "Contas admin:\n";
    foreach ($conn->query("SELECT id, email, nome FROM usuarios WHERE tipo_usuario='admin'")->fetchAll(PDO::FETCH_ASSOC) as $u) {
        echo "  - {$u['email']} ({$u['nome']})\n";
    }
    echo "\nEmpresas:\n";
    foreach ($conn->query("SELECT id, email, nome FROM usuarios WHERE tipo_usuario='empresa'")->fetchAll(PDO::FETCH_ASSOC) as $u) {
        echo "  - {$u['email']} ({$u['nome']}) → senha: 654321\n";
    }
} catch (Throwable $e) {
    $conn->rollBack();
    echo "Erro: " . $e->getMessage() . "\n";
    exit(1);
}
