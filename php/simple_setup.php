<?php
require_once 'db.php';

echo "<h2>Simple User Setup</h2>";

// Clear and recreate users table
try {
    $pdo->exec("DROP TABLE IF EXISTS users");
    $pdo->exec("CREATE TABLE users (
        id INT PRIMARY KEY AUTO_INCREMENT,
        username VARCHAR(50) UNIQUE,
        email VARCHAR(100) UNIQUE,
        password VARCHAR(255),
        full_name VARCHAR(100),
        role ENUM('student', 'teacher', 'admin') DEFAULT 'student',
        grade_level VARCHAR(20),
        section VARCHAR(50),
        lrn VARCHAR(20),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    echo "✅ Created fresh users table<br>";
    
} catch(PDOException $e) {
    echo "Error: " . $e->getMessage() . "<br>";
    exit;
}

// Create users
$users = [
    [
        'username' => 'student',
        'email' => 'student@baa.edu',
        'password' => password_hash('student123', PASSWORD_DEFAULT),
        'full_name' => 'Student User',
        'role' => 'student',
        'grade_level' => 'Grade 10',
        'section' => 'Self-Control',
        'lrn' => '136628120097'
    ],
    [
        'username' => 'teacher',
        'email' => 'teacher@baa.edu',
        'password' => password_hash('teacher123', PASSWORD_DEFAULT),
        'full_name' => 'Teacher User',
        'role' => 'teacher',
        'grade_level' => null,
        'section' => null,
        'lrn' => null
    ],
    [
        'username' => 'admin',
        'email' => 'admin@baa.edu',
        'password' => password_hash('admin123', PASSWORD_DEFAULT),
        'full_name' => 'Admin User',
        'role' => 'admin',
        'grade_level' => null,
        'section' => null,
        'lrn' => null
    ]
];

$stmt = $pdo->prepare("
    INSERT INTO users (username, email, password, full_name, role, grade_level, section, lrn) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
");

foreach ($users as $user) {
    try {
        $stmt->execute([
            $user['username'],
            $user['email'],
            $user['password'],
            $user['full_name'],
            $user['role'],
            $user['grade_level'],
            $user['section'],
            $user['lrn']
        ]);
        echo "Created user: " . $user['username'] . "<br>";
    } catch(PDOException $e) {
        echo "Error creating " . $user['username'] . ": " . $e->getMessage() . "<br>";
    }
}

echo "<hr><h2>Setup Complete!</h2>";
echo "<h3>Login Credentials:</h3>";
echo "<table border='1' cellpadding='10'>";
echo "<tr><th>Username</th><th>Password</th><th>Role</th><th>Email</th></tr>";
echo "<tr><td>student</td><td>student123</td><td>Student</td><td>student@baa.edu</td></tr>";
echo "<tr><td>teacher</td><td>teacher123</td><td>Teacher</td><td>teacher@baa.edu</td></tr>";
echo "<tr><td>admin</td><td>admin123</td><td>Admin</td><td>admin@baa.edu</td></tr>";
echo "</table>";

echo "<p><strong>Just use username and password to login!</strong></p>";
?>