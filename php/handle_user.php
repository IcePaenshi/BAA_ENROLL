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
$allowedRoles = ['admin', 'registrar'];

if (!in_array($userRole, $allowedRoles)) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Get request data - handle both JSON and POST data
$requestData = [];
if ($_SERVER['CONTENT_TYPE'] === 'application/json' || strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === 0) {
    $requestData = json_decode(file_get_contents('php://input'), true) ?? [];
    // Convert to $_POST format for backward compatibility with existing code
    foreach ($requestData as $key => $value) {
        $_POST[$key] = $value;
    }
} else {
    $requestData = $_POST;
}

// Get action
$action = $_POST['action'] ?? 'create';

switch ($action) {
    case 'create':
        handleCreateUser();
        break;
    case 'update_student':
        handleUpdateStudent();
        break;
    case 'update_teacher_subjects':
        handleUpdateTeacherSubjects();
        break;
    default:
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        exit();
}

function handleCreateUser() {
    global $pdo, $userRole;

    // Get form data
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $firstName = trim($_POST['first_name'] ?? '');
    $middleName = trim($_POST['middle_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $suffix = trim($_POST['suffix'] ?? '');
    $role = $_POST['role'] ?? '';
    $gradeLevel = trim($_POST['gradeLevel'] ?? '');
    $section = trim($_POST['section'] ?? '');
    $lrn = trim($_POST['lrn'] ?? '');

    // Enrollment fields (still needed for validation)
    $age = trim($_POST['age'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $birthdate = trim($_POST['birthdate'] ?? '');
    $strand = trim($_POST['strand'] ?? '');
    $phone = trim($_POST['phone'] ?? '');

    // Enrollment ID (if coming from Accept)
    $enrollmentId = $_POST['enrollment_id'] ?? null;

    // Build full name for display (not stored separately)
    $fullNameParts = [$firstName];
    if (!empty($middleName)) $fullNameParts[] = $middleName;
    if (!empty($lastName)) $fullNameParts[] = $lastName;
    if (!empty($suffix)) $fullNameParts[] = $suffix;
    $fullName = implode(' ', $fullNameParts);

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

    if (empty($firstName) || empty($lastName)) {
        $errors[] = 'First name and last name are required';
    }

    $validRoles = ['student', 'teacher'];
    if ($userRole == 'admin') {
        $validRoles = ['student', 'teacher', 'cashier', 'registrar', 'admin'];
    } elseif ($userRole == 'registrar') {
        $validRoles = ['student'];
    }

    if (empty($role) || !in_array($role, $validRoles)) {
        $errors[] = 'Invalid role selected';
    }

    // Role-specific validations
    $computedAge = null;
    if ($role == 'student') {
        $validGrades = ['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'Grade 12'];
        
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
        
        // LRN is optional for students

        if (empty($gender) || !in_array($gender, ['Male', 'Female'])) {
            $errors[] = 'Gender is required';
        }

        $computedAge = null;
        if (empty($birthdate)) {
            $errors[] = 'Birthdate is required';
        } else {
            $date = DateTime::createFromFormat('Y-m-d', $birthdate);
            if (!$date || $date->format('Y-m-d') !== $birthdate) {
                $errors[] = 'Invalid birthdate format';
            } else {
                $computedAge = baa_age_from_birthdate_years($birthdate);
                if ($computedAge === null || $computedAge < 1 || $computedAge > 120) {
                    $errors[] = 'Invalid birthdate or age out of range';
                }
            }
        }

        // Phone: must be exactly 10 digits (national mobile without +63)
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
        // Not a student – clear student-specific fields
        $gradeLevel = '';
        $section = '';
        $lrn = '';
        $strand = null;
        // Age, gender, birthdate, and phone are now required for all users
        if (empty($gender) || !in_array($gender, ['Male', 'Female'])) {
            $errors[] = 'Gender is required';
        }

        $computedAge = null;
        if (empty($birthdate)) {
            $errors[] = 'Birthdate is required';
        } else {
            $date = DateTime::createFromFormat('Y-m-d', $birthdate);
            if (!$date || $date->format('Y-m-d') !== $birthdate) {
                $errors[] = 'Invalid birthdate format';
            } else {
                $computedAge = baa_age_from_birthdate_years($birthdate);
                if ($computedAge === null || $computedAge < 1 || $computedAge > 120) {
                    $errors[] = 'Invalid birthdate or age out of range';
                }
            }
        }

        // Phone: must be exactly 10 digits (national mobile without +63)
        if (empty($phone) || !preg_match('/^[0-9]{10}$/', $phone)) {
            $errors[] = 'Valid phone number (10 digits) is required';
        }
    }

    if (!empty($errors)) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => implode(', ', $errors)]);
        exit();
    }

    if ($role === 'student') {
        $lrn = ($lrn === '' || $lrn === null) ? '' : $lrn;
        $age = $computedAge;
    }

    // Format phone number for all users
    $phone = '+63' . preg_replace('/\D/', '', $phone);

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

        // Generate a unique student_id for students (optional)
        $studentIdValue = null; // default NULL
        if ($role == 'student') {
            if ($enrollmentId) {
                // Use enrollment ID to create a unique student ID (e.g., ENR-123)
                $studentIdValue = 'ENR-' . $enrollmentId;
            } else {
                // If manually adding a student without an enrollment, you could generate a different unique ID
                // For now, leave NULL (allowed after table alteration)
                $studentIdValue = null;
            }
        }

        // Insert user into users table using separate name fields
        $stmt = $pdo->prepare("
            INSERT INTO users 
            (username, email, password, first_name, middle_name, last_name, suffix, role, grade_level, section, lrn, student_id, age, gender, birthdate, phone, strand)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $username,
            $email,
            $hashedPassword,
            $firstName,
            $middleName,
            $lastName,
            $suffix,
            $role,
            $gradeLevel,
            $section,
            $lrn,
            $studentIdValue,
            (int) $computedAge,
            $gender,
            $birthdate,
            '+63' . preg_replace('/\D/', '', $phone),
            $strand,
        ]);

        $userId = $pdo->lastInsertId();

        // Do NOT insert into enrollments here – the enrollment already exists.
        // It will be updated separately via update_enrollment_after_accept.php

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
}

function handleUpdateStudent() {
    global $pdo;

    $userId = $_POST['user_id'] ?? '';
    $gradeLevel = trim($_POST['grade_level'] ?? '');
    $section = trim($_POST['section'] ?? '');

    if (empty($userId) || empty($gradeLevel) || empty($section)) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit();
    }

    // Validate grade level and section
    $validGrades = ['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'Grade 12'];
    $gradeSections = [
        'Grade 7' => ['Love', 'Joy'],
        'Grade 8' => ['Patience', 'Peace'],
        'Grade 9' => ['Goodness', 'Kindness'],
        'Grade 10' => ['Gentleness', 'Faithfulness'],
        'Grade 11' => ['Self-Control', 'Honesty'],
        'Grade 12' => ['Humility', 'Meekness']
    ];

    if (!in_array($gradeLevel, $validGrades)) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Invalid grade level']);
        exit();
    }

    if (!in_array($section, $gradeSections[$gradeLevel] ?? [])) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Invalid section for grade level']);
        exit();
    }

    try {
        $stmt = $pdo->prepare("UPDATE users SET grade_level = ?, section = ? WHERE id = ? AND role = 'student'");
        $stmt->execute([$gradeLevel, $section, $userId]);

        ob_end_clean();
        echo json_encode(['success' => true, 'message' => 'Student updated successfully']);
    } catch(PDOException $e) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

function handleUpdateTeacherSubjects() {
    global $pdo;

    $teacherId = $_POST['teacher_id'] ?? '';
    $subjectIds = $_POST['subject_ids'] ?? [];
    $teacherGradeLevel = trim((string) ($_POST['teacher_grade_level'] ?? ''));
    $teacherSection = trim((string) ($_POST['teacher_section'] ?? ''));

    if (empty($teacherId)) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Missing teacher ID']);
        exit();
    }

    $gradeSections = [
        'Grade 7' => ['Love', 'Joy'],
        'Grade 8' => ['Patience', 'Peace'],
        'Grade 9' => ['Goodness', 'Kindness'],
        'Grade 10' => ['Gentleness', 'Faithfulness'],
        'Grade 11' => ['Self-Control', 'Honesty'],
        'Grade 12' => ['Humility', 'Meekness'],
    ];

    if (($teacherGradeLevel === '' && $teacherSection !== '') || ($teacherGradeLevel !== '' && $teacherSection === '')) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Please select both teacher grade level and section']);
        exit();
    }

    if ($teacherGradeLevel !== '') {
        if (!array_key_exists($teacherGradeLevel, $gradeSections)) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'Invalid teacher grade level']);
            exit();
        }
        if (!in_array($teacherSection, $gradeSections[$teacherGradeLevel], true)) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'Invalid teacher section for selected grade']);
            exit();
        }
    }

    // Ensure subject_ids is an array
    if (!is_array($subjectIds)) {
        $subjectIds = [];
    }

    try {
        // Expand selected subject IDs to all matching duplicate subject rows
        $expandedSubjectIds = [];
        if (!empty($subjectIds)) {
            $placeholders = implode(',', array_fill(0, count($subjectIds), '?'));
            $stmt = $pdo->prepare("SELECT subject_name, grade_level FROM subjects WHERE id IN ($placeholders)");
            $stmt->execute($subjectIds);
            $selectedSubjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($selectedSubjects as $subject) {
                $stmt = $pdo->prepare("SELECT id FROM subjects WHERE subject_name = ? AND grade_level = ?");
                $stmt->execute([$subject['subject_name'], $subject['grade_level']]);
                $matchingIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
                foreach ($matchingIds as $matchingId) {
                    $expandedSubjectIds[] = $matchingId;
                }
            }

            $expandedSubjectIds = array_unique($expandedSubjectIds);
        }

        // Start transaction
        $pdo->beginTransaction();

        // Delete existing teacher-subject assignments
        $stmt = $pdo->prepare("DELETE FROM teacher_subjects WHERE teacher_id = ?");
        $stmt->execute([$teacherId]);

        // Insert new assignments
        if (!empty($expandedSubjectIds)) {
            $stmt = $pdo->prepare("INSERT INTO teacher_subjects (teacher_id, subject_id) VALUES (?, ?)");
            foreach ($expandedSubjectIds as $subjectId) {
                $stmt->execute([$teacherId, $subjectId]);
            }
        }

        $teacherGradeLevelDb = $teacherGradeLevel !== '' ? $teacherGradeLevel : null;
        $teacherSectionDb = $teacherSection !== '' ? $teacherSection : null;
        $updateTeacherStmt = $pdo->prepare("UPDATE users SET teacher_grade_level = ?, teacher_section = ? WHERE id = ? AND role = 'teacher'");
        $updateTeacherStmt->execute([$teacherGradeLevelDb, $teacherSectionDb, $teacherId]);

        $pdo->commit();

        ob_end_clean();
        echo json_encode(['success' => true, 'message' => 'Teacher subjects updated successfully']);
    } catch(PDOException $e) {
        $pdo->rollBack();
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

/**
 * Calendar age from Y-m-d (same idea as public enrollment form).
 */
function baa_age_from_birthdate_years(string $ymd): ?int
{
    $d = DateTime::createFromFormat('Y-m-d', $ymd);
    if (!$d || $d->format('Y-m-d') !== $ymd) {
        return null;
    }
    $today = new DateTime('today');
    $age = (int) $today->format('Y') - (int) $d->format('Y');
    if ($today->format('md') < $d->format('md')) {
        $age--;
    }

    return $age;
}
?>