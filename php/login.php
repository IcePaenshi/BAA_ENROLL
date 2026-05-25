<?php
session_start();
header('Content-Type: application/json');
require_once 'db.php';

// Clear any previous output
ob_clean();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    
    try {
        // Check if user exists
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? OR username = ?");
        $stmt->execute([$email, $email]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            // Check if account is active
            if (isset($user['status']) && $user['status'] == 0) {
                // Account is inactive
                echo json_encode(['success' => false, 'message' => 'Your account is inactive. Please contact the administrator.']);
                exit();
            }

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['email'] = $user['email'] ?? '';
            $_SESSION['full_name'] = baa_build_full_name([
                $user['first_name'] ?? '',
                $user['middle_name'] ?? '',
                $user['last_name'] ?? '',
                $user['suffix'] ?? ''
            ]);
            if ($_SESSION['full_name'] === '' && !empty($user['full_name'])) {
                $rawParts = preg_split('/[\s,]+/', $user['full_name'], -1, PREG_SPLIT_NO_EMPTY);
                $_SESSION['full_name'] = baa_build_full_name($rawParts);
            }
            $_SESSION['role'] = $user['role'] ?? 'student';
            $_SESSION['grade_level'] = $user['grade_level'] ?? '';
            $_SESSION['section'] = $user['section'] ?? '';
            $_SESSION['lrn'] = $user['lrn'] ?? '';
            $_SESSION['first_name'] = $user['first_name'] ?? '';
            
            // Check if user must change password on first login
            // Trigger if DB flag is set
            if (!empty($user['force_password_change'])) {
                $_SESSION['require_password_change'] = true;
            } else {
                $_SESSION['require_password_change'] = false;
            }
            
            session_write_close();
            
            echo json_encode(['success' => true, 'redirect' => 'dashboard.php']);
            exit();
            
        } else {
            // Login failed
            echo json_encode(['success' => false, 'message' => 'Invalid username or password']);
            exit();
        }
    } catch(PDOException $e) {
        // Database error
        error_log("Login error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again later.']);
        exit();
    }
}

// redirect to login page
header('Location: ../index.php');
exit();
?>