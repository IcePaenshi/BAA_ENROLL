<?php
session_start();
require_once 'db.php';

// Clear any previous output
ob_clean();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    try {
        // Check if user exists
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['email'] = $user['email'] ?? '';
            $_SESSION['full_name'] = $user['full_name'] ?? '';
            $_SESSION['role'] = $user['role'] ?? 'student';
            $_SESSION['grade_level'] = $user['grade_level'] ?? '';
            $_SESSION['section'] = $user['section'] ?? '';
            $_SESSION['lrn'] = $user['lrn'] ?? '';
            
            session_write_close();
            
            header('Location: ../dashboard.php');
            exit();
            
        } else {
            // Login failed
            header('Location: ../index.php?error=1');
            exit();
        }
    } catch(PDOException $e) {
        // Database error
        error_log("Login error: " . $e->getMessage());
        header('Location: ../index.php?error=2');
        exit();
    }
}

// redirect to login page
header('Location: ../index.php');
exit();
?>