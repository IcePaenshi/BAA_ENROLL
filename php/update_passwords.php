<?php
require_once 'db.php';

try {
    // Update cashier password
    $cashierHash = password_hash('cashier123', PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE username = 'cashier'");
    $stmt->execute([$cashierHash]);
    echo "Updated cashier password<br>";
    
    // Update registrar password
    $registrarHash = password_hash('registrar123', PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE username = 'registrar'");
    $stmt->execute([$registrarHash]);
    echo "Updated registrar password<br>";
    
    // Verify
    $stmt = $pdo->prepare("SELECT username, password FROM users WHERE username IN ('cashier', 'registrar')");
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($users as $user) {
        echo "User: {$user['username']}, Hash: {$user['password']}<br>";
    }
    
} catch(PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>