<?php
require_once 'db.php';

try {
    $stmt = $pdo->query("DESCRIBE users");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h2>Current Users Table Structure</h2>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    foreach ($columns as $col) {
        echo "<tr>";
        echo "<td>" . $col['Field'] . "</td>";
        echo "<td>" . $col['Type'] . "</td>";
        echo "<td>" . $col['Null'] . "</td>";
        echo "<td>" . $col['Key'] . "</td>";
        echo "<td>" . $col['Default'] . "</td>";
        echo "<td>" . $col['Extra'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    echo "<h2>Current Users</h2>";
    $stmt = $pdo->query("SELECT id, username, role FROM users");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>Username</th><th>Role</th></tr>";
    foreach ($users as $user) {
        echo "<tr><td>{$user['id']}</td><td>{$user['username']}</td><td>{$user['role']}</td></tr>";
    }
    echo "</table>";
    
} catch(PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>