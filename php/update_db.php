<?php
require_once 'db.php';

try {
    // Alter the users table to add name fields
    $pdo->exec("ALTER TABLE users ADD COLUMN first_name VARCHAR(50) AFTER password");
    $pdo->exec("ALTER TABLE users ADD COLUMN middle_name VARCHAR(50) AFTER first_name");
    $pdo->exec("ALTER TABLE users ADD COLUMN last_name VARCHAR(50) AFTER middle_name");
    $pdo->exec("ALTER TABLE users ADD COLUMN suffix VARCHAR(10) AFTER last_name");
    
    // Update the users table role enum
    $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('student', 'teacher', 'cashier', 'registrar', 'admin') DEFAULT 'student'");
    
    echo "✅ Updated users table structure and role enum<br>";
    
    // Update existing super_admin to admin
    $pdo->exec("UPDATE users SET role = 'admin' WHERE role = 'super_admin'");
    echo "✅ Migrated super_admin to admin<br>";
    
    // Update existing users to split full_name into parts (simple split)
    $pdo->exec("UPDATE users SET 
        first_name = SUBSTRING_INDEX(full_name, ' ', 1),
        last_name = SUBSTRING_INDEX(full_name, ' ', -1),
        middle_name = CASE 
            WHEN LENGTH(full_name) - LENGTH(REPLACE(full_name, ' ', '')) > 1 
            THEN SUBSTRING_INDEX(SUBSTRING_INDEX(full_name, ' ', 2), ' ', -1)
            ELSE ''
        END
        WHERE first_name IS NULL OR first_name = ''");
    echo "✅ Split full_name into separate fields<br>";
    
    // Check if admin user exists (renamed from super_admin)
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = 'admin'");
    $stmt->execute();
    if (!$stmt->fetch()) {
        // Create admin user
        $password = password_hash('admin123', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (username, email, password, first_name, last_name, full_name, role) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute(['admin', 'admin@baa.edu', $password, 'Admin', 'User', 'Admin User', 'admin']);
        echo "✅ Created admin user<br>";
    } else {
        echo "✅ Admin user already exists<br>";
    }
    
    // Create cashier user if not exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = 'cashier'");
    $stmt->execute();
    if (!$stmt->fetch()) {
        $password = password_hash('cashier123', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (username, email, password, first_name, last_name, full_name, role) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute(['cashier', 'cashier@baa.edu', $password, 'Cashier', 'User', 'Cashier User', 'cashier']);
        echo "✅ Created cashier user<br>";
    }
    
    // Create registrar user if not exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = 'registrar'");
    $stmt->execute();
    if (!$stmt->fetch()) {
        $password = password_hash('registrar123', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (username, email, password, first_name, last_name, full_name, role) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute(['registrar', 'registrar@baa.edu', $password, 'Registrar', 'User', 'Registrar User', 'registrar']);
        echo "✅ Created registrar user<br>";
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