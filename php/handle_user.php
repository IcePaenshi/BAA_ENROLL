<?php
ob_clean();
ob_start();
header('Content-Type: application/json');
require_once 'db.php';

// Validate request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

session_start();

// Check permissions
if (!isset($_SESSION['user_id'])) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

$userRole = $_SESSION['role'];
$allowedRoles = ['admin', 'super_admin'];

if (!in_array($userRole, $allowedRoles)) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Get form data
$username = trim($_POST['username'] ?? '');
$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';
$fullName = trim($_POST['fullName'] ?? '');
$role = $_POST['role'] ?? '';
$gradeLevel = trim($_POST['gradeLevel'] ?? '');
$section = trim($_POST['section'] ?? '');
$lrn = trim($_POST['lrn'] ?? '');

// Enrollment fields
$age = trim($_POST['age'] ?? '');
$gender = trim($_POST['gender'] ?? '');
$birthdate = trim($_POST['birthdate'] ?? '');
$strand = trim($_POST['strand'] ?? '');
$phone = trim($_POST['phone'] ?? '');

// Validation
$errors = [];

if (empty($username)) {
    $errors[] = 'Username is required';
}

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Valid email is required';
}

if (empty($password)) {
    $errors[] = 'Password is required';
}

if (empty($fullName)) {
    $errors[] = 'Full name is required';
}

$validRoles = ['student', 'teacher'];
if ($userRole == 'super_admin') {
    $validRoles[] = 'admin';
}

if (empty($role) || !in_array($role, $validRoles)) {
    $errors[] = 'Invalid role selected';
}

// Role-specific validations
if ($role == 'student') {
    $validGrades = ['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'Grade 12'];
    
    // Grade-section mapping
    $gradeSections = [
        'Grade 7' => ['Love', 'Joy'],
        'Grade 8' => ['Patience', 'Peace'],
        'Grade 9' => ['Goodness', 'Kindness'],
        'Grade 10' => ['Gentleness', 'Faithfulness'],
        'Grade 11' => ['Self-Control', 'Honesty'],
        'Grade 12' => ['Humility', 'Meekness']
    ];
    
    if (empty($gradeLevel)) {
        $errors[] = 'Grade level is required for students';
    } elseif (!in_array($gradeLevel, $validGrades)) {
        $errors[] = 'Invalid grade level selected';
    }
    
    if (empty($section)) {
        $errors[] = 'Section is required for students';
    } elseif (!in_array($section, $gradeSections[$gradeLevel] ?? [])) {
        $errors[] = 'Invalid section for selected grade level';
    }
    
    if (empty($lrn)) {
        $errors[] = 'LRN is required for students';
    }

    // Enrollment field validations
    if (empty($age) || !is_numeric($age) || $age < 1 || $age > 120) {
        $errors[] = 'Valid age is required';
    }

    if (empty($gender) || !in_array($gender, ['Male', 'Female'])) {
        $errors[] = 'Gender is required';
    }

    if (empty($birthdate)) {
        $errors[] = 'Birthdate is required';
    } else {
        $date = DateTime::createFromFormat('Y-m-d', $birthdate);
        if (!$date || $date->format('Y-m-d') !== $birthdate) {
            $errors[] = 'Invalid birthdate format';
        }
    }

    // Phone: must be exactly 10 digits
    if (empty($phone) || !preg_match('/^[0-9]{10}$/', $phone)) {
        $errors[] = 'Valid phone number (10 digits) is required';
    }

    // Strand validation for Grade 11 & 12
    if (in_array($gradeLevel, ['Grade 11', 'Grade 12'])) {
        if (empty($strand) || !in_array($strand, ['STEM', 'ABM', 'HUMSS'])) {
            $errors[] = 'Strand is required for Senior High School';
        }
    } else {
        $strand = null;
    }
} else {
    $gradeLevel = null;
    $section = null;
    $lrn = null;
    $age = null;
    $gender = null;
    $birthdate = null;
    $strand = null;
    $phone = null;
}

if (!empty($errors)) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => implode(', ', $errors)]);
    exit();
}

try {
    // Check if username or email already exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
    $stmt->execute([$username, $email]);
    if ($stmt->fetch()) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Username or email already exists']);
        exit();
    }

    // Hash password
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    // Insert user
    $stmt = $pdo->prepare("
        INSERT INTO users (username, email, password, full_name, role, grade_level, section, lrn)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $username,
        $email,
        $hashedPassword,
        $fullName,
        $role,
        $gradeLevel,
        $section,
        $lrn
    ]);

    $userId = $pdo->lastInsertId();

    // If role is student, also create an enrollment record
    if ($role == 'student') {
        // Format phone with +63 prefix
        $fullPhone = '+63' . $phone;

        // Insert into enrollments table
        $enrollStmt = $pdo->prepare("
            INSERT INTO enrollments 
            (student_id, full_name, email, phone, age, gender, birthdate, grade_level, strand, section, lrn, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'approved', NOW())
        ");
        $enrollStmt->execute([
            $userId,
            $fullName,
            $email,
            $fullPhone,
            $age,
            $gender,
            $birthdate,
            $gradeLevel,
            $strand,
            $section,
            $lrn
        ]);
    }

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'message' => 'User created successfully',
        'user_id' => $userId
    ]);

} catch(PDOException $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>