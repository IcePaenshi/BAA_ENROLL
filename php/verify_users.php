<?php
require_once 'db.php';

echo "<h2>Verify Users</h2>";

try {
    // Check all users
    $stmt = $pdo->query("SELECT id, username, email, password, full_name, role FROM users");
    $users = $stmt->fetchAll();
    
    if ($users) {
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>ID</th><th>Username</th><th>Email</th><th>Password Hash</th><th>Name</th><th>Role</th></tr>";
        foreach ($users as $user) {
            echo "<tr>";
            echo "<td>" . $user['id'] . "</td>";
            echo "<td>" . $user['username'] . "</td>";
            echo "<td>" . $user['email'] . "</td>";
            echo "<td style='font-size:10px;'><code>" . substr($user['password'], 0, 30) . "...</code></td>";
            echo "<td>" . $user['full_name'] . "</td>";
            echo "<td>" . $user['role'] . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        echo "<h3>Password Test:</h3>";
        $test_credentials = [
            ['student', 'student123'],
            ['teacher', 'teacher123'],
            ['admin', 'admin123']
        ];
        
        foreach ($test_credentials as $cred) {
            $stmt = $pdo->prepare("SELECT password FROM users WHERE username = ?");
            $stmt->execute([$cred[0]]);
            $result = $stmt->fetch();
            
            if ($result) {
                $isValid = password_verify($cred[1], $result['password']);
                echo $cred[0] . ": " . ($isValid ? "Password valid" : "Password INVALID") . "<br>";
                
                if (!$isValid) {
                    echo "Current hash doesn't match '{$cred[1]}'<br>";
                    echo "To fix: UPDATE users SET password = '" . password_hash($cred[1], PASSWORD_DEFAULT) . "' WHERE username = '{$cred[0]}';<br>";
                }
            } else {
                echo "User '{$cred[0]}' not found<br>";
            }
        }
    } else {
        echo "No users found.";
    }
} catch(PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>