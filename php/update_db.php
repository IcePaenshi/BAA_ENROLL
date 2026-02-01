<?php
require_once 'db.php';

try {
    // Alter the users table to add super_admin to the role enum
    $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('student', 'teacher', 'admin', 'super_admin') DEFAULT 'student'");
    
    echo "✅ Updated users table role enum<br>";
    
    // Check if super_admin user exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = 'super_admin'");
    $stmt->execute();
    if (!$stmt->fetch()) {
        // Create super_admin user
        $password = password_hash('super123', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (username, email, password, full_name, role) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute(['super_admin', 'superadmin@baa.edu', $password, 'Super Admin User', 'super_admin']);
        echo "✅ Created super_admin user<br>";
    } else {
        echo "✅ Super admin user already exists<br>";
    }
    
    // Create payables table if not exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS payables (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        description VARCHAR(255) NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        due_date DATE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
    )");
    
    echo "✅ Created payables table<br>";
    
} catch(PDOException $e) {
    echo "Error: " . $e->getMessage() . "<br>";
}
?>