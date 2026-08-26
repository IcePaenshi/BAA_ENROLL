<?php
session_start();
require_once __DIR__ . '/db.php';
header('Content-Type: application/json');

function baa_ensure_payment_mode_columns(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS student_downpayments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            payment_date DATE NOT NULL,
            processed_by INT NOT NULL,
            payment_mode VARCHAR(32) DEFAULT 'cash',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_student_id (student_id)
        )");
    } catch (PDOException $e) {
        // ignore if creation fails due to existing schema differences
    }

    try {
        $pdo->exec("ALTER TABLE student_downpayments ADD COLUMN IF NOT EXISTS payment_mode VARCHAR(32) DEFAULT 'cash'");
    } catch (PDOException $e) {
        // ignore if old MySQL syntax doesn't support IF NOT EXISTS or column already exists
        try {
            $pdo->exec("ALTER TABLE student_downpayments ADD COLUMN payment_mode VARCHAR(32) DEFAULT 'cash'");
        } catch (PDOException $ignored) {
            // ignore duplicate column errors
        }
    }

    try {
        $pdo->exec("ALTER TABLE payments ADD COLUMN IF NOT EXISTS payment_mode VARCHAR(50) DEFAULT 'cash'");
    } catch (PDOException $e) {
        try {
            $pdo->exec("ALTER TABLE payments ADD COLUMN payment_mode VARCHAR(50) DEFAULT 'cash'");
        } catch (PDOException $ignored) {
            // ignore duplicate column errors
        }
    }
}

baa_ensure_payment_mode_columns($pdo);

// Check if user is admin or cashier
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'cashier'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$paymentTarget = $_POST['payment_target'] ?? 'student';
$studentId = $_POST['student_id'] ?? '';
$enrollmentId = $_POST['enrollment_id'] ?? '';
$amount = $_POST['amount'] ?? '';
$paymentType = strtolower(trim((string) ($_POST['payment_type'] ?? '')));
$paymentMode = strtolower(trim((string) ($_POST['payment_mode'] ?? '')));
if ($paymentMode === '') {
    $paymentMode = 'cash';
}
$paymentDate = trim((string) ($_POST['payment_date'] ?? ''));
if ($paymentDate === '') {
    $paymentDate = date('Y-m-d');
}
$paymentDateObj = DateTime::createFromFormat('Y-m-d', $paymentDate);
if (!$paymentDateObj || $paymentDateObj->format('Y-m-d') !== $paymentDate) {
    echo json_encode(['success' => false, 'message' => 'Invalid payment date']);
    exit();
}

if (!in_array($paymentTarget, ['student', 'enrollee'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid payment target']);
    exit();
}

// Enforce minimum 2000 for first payment on student
if ($paymentTarget === 'student' && $studentId) {
    $pdo->exec("\n        CREATE TABLE IF NOT EXISTS student_downpayments (\n            id INT AUTO_INCREMENT PRIMARY KEY,\n            student_id INT NOT NULL,\n            amount DECIMAL(10,2) NOT NULL,\n            payment_date DATE NOT NULL,\n            processed_by INT NOT NULL,\n            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n            INDEX idx_student_id (student_id)\n        )\n    ");

    $checkPayments = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE student_id = ?");
    $checkPayments->execute([$studentId]);
    $hasPayments = $checkPayments->fetchColumn() > 0;

    $checkDownpayments = $pdo->prepare("SELECT COUNT(*) FROM student_downpayments WHERE student_id = ?");
    $checkDownpayments->execute([$studentId]);
    $hasDownpayments = $checkDownpayments->fetchColumn() > 0;

    if (!$hasPayments && !$hasDownpayments && (float)$amount < 2000) {
        echo json_encode(['success' => false, 'message' => 'First payment must be at least ₱2,000.00']);
        exit();
    }
}

// Normalize payment type:
// - enrollee payments are always downpayments
// - student payments can be downpayment or payment
if ($paymentTarget === 'enrollee') {
    $paymentType = 'downpayment';
} else {
    if ($paymentType === '') {
        $paymentType = 'payment';
    }
    if (!in_array($paymentType, ['downpayment', 'payment'], true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid payment type']);
        exit();
    }
}

if (!is_numeric($amount) || $amount <= 0) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid payment amount']);
    exit();
}

try {
    if ($paymentTarget === 'enrollee') {
        if (!$enrollmentId || !is_numeric($enrollmentId)) {
            echo json_encode(['success' => false, 'message' => 'Invalid enrollment ID']);
            exit();
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS enrollment_downpayments (\n                id INT AUTO_INCREMENT PRIMARY KEY,\n                enrollment_id INT NOT NULL,\n                amount DECIMAL(10,2) NOT NULL,\n                payment_date DATE NOT NULL,\n                processed_by INT NOT NULL,\n                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n                INDEX idx_enrollment_id (enrollment_id)\n            )");

        try {
            $pdo->exec("ALTER TABLE enrollment_downpayments ADD COLUMN IF NOT EXISTS payment_mode VARCHAR(32) DEFAULT NULL");
        } catch (PDOException $e) {
            // ignore if ALTER not supported
        }

        $check = $pdo->prepare("SELECT id, status FROM enrollments WHERE id = ? LIMIT 1");
        $check->execute([(int) $enrollmentId]);
        $enrollment = $check->fetch(PDO::FETCH_ASSOC);
        if (!$enrollment) {
            echo json_encode(['success' => false, 'message' => 'Enrollment not found']);
            exit();
        }
        if (($enrollment['status'] ?? '') === 'approved') {
            echo json_encode(['success' => false, 'message' => 'Enrollment is already approved']);
            exit();
        }

        $insert = $pdo->prepare("
            INSERT INTO enrollment_downpayments (enrollment_id, amount, payment_date, processed_by, payment_mode)
            VALUES (?, ?, ?, ?, ?)
        ");
        $insert->execute([(int) $enrollmentId, (float) $amount, $paymentDate, (int) $_SESSION['user_id'], $paymentMode]);

        $sumStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) AS total FROM enrollment_downpayments WHERE enrollment_id = ?");
        $sumStmt->execute([(int) $enrollmentId]);
        $total = (float) ($sumStmt->fetchColumn() ?: 0);

        echo json_encode([
            'success' => true,
            'message' => '<div style="color: #155724; background: #d4edda; padding: 15px; border-radius: 4px;"><h4 style="margin: 0 0 8px 0;">✓ Downpayment Recorded</h4><p style="margin:0;">Total enrollee downpayment: ₱' . number_format($total, 2) . '</p></div>'
        ]);
        exit();
    }

    if (!$studentId || !is_numeric($studentId)) {
        echo json_encode(['success' => false, 'message' => 'Invalid student ID']);
        exit();
    }

    // Create student downpayments table if needed
    if ($paymentType === 'downpayment') {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS student_downpayments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                student_id INT NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                payment_date DATE NOT NULL,
                processed_by INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_student_id (student_id)
            )
        ");
    }

    // Start transaction
    $pdo->beginTransaction();
    
    // DEBUG: Log what we're trying to do
    error_log("Processing payment: Student $studentId, Amount: $amount");
    
    // Get ALL payables (pending and unpaid) for the student
    $stmt = $pdo->prepare("SELECT id, item_name, amount, due_date, status FROM payables WHERE student_id = ? AND (status = 'pending' OR status IS NULL OR status = '') ORDER BY due_date ASC");
    $stmt->execute([$studentId]);
    $payables = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    error_log("Found " . count($payables) . " manual payables for student $studentId");
    
    // Process new fields: Monthly Plans and Discounts
    $monthlyPlans = isset($_POST['monthly_plans']) ? (int)$_POST['monthly_plans'] : 0;
    if ($monthlyPlans > 0 && $monthlyPlans <= 8) {
        $pdo->prepare("UPDATE users SET payment_plan_months = ? WHERE id = ?")->execute([$monthlyPlans, $studentId]);
    }
    
    $discountId = isset($_POST['discounts']) ? (int)$_POST['discounts'] : 0;
    if ($discountId > 0) {
        // Get discount amount
        $discStmt = $pdo->prepare("SELECT amount FROM discounts WHERE id = ?");
        $discStmt->execute([$discountId]);
        $discAmount = $discStmt->fetchColumn();
        if ($discAmount) {
            // Check if already assigned
            $checkDisc = $pdo->prepare("SELECT id FROM student_discounts WHERE student_id = ? AND discount_id = ?");
            $checkDisc->execute([$studentId, $discountId]);
            if (!$checkDisc->fetchColumn()) {
                $pdo->prepare("INSERT INTO student_discounts (student_id, discount_id, amount, applied_date) VALUES (?, ?, ?, ?)")
                    ->execute([$studentId, $discountId, $discAmount, date('Y-m-d')]);
            }
        }
    }
    
    $paymentId = null;
    $totalApplied = floatval($amount);

    if ($paymentType === 'downpayment') {
        $stmt = $pdo->prepare("INSERT INTO student_downpayments (student_id, amount, payment_date, processed_by, payment_mode) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$studentId, $totalApplied, $paymentDate, $_SESSION['user_id'], $paymentMode]);
        $paymentId = $pdo->lastInsertId();
        error_log("Created student_downpayment record $paymentId for amount $totalApplied");
    } else {
        $stmt = $pdo->prepare("INSERT INTO payments (student_id, amount, payment_type, payment_date, set_by_user_id, payment_mode) VALUES (?, ?, 'payment', ?, ?, ?)");
        $stmt->execute([$studentId, $totalApplied, $paymentDate, $_SESSION['user_id'], $paymentMode]);
        $paymentId = $pdo->lastInsertId();
        error_log("Created payment record $paymentId for amount $totalApplied");
    }
    
    // Commit transaction
    $pdo->commit();
    
    // Prepare success message
    $message = '<div style="color: #155724; background: #d4edda; padding: 15px; border-radius: 4px; margin-bottom: 15px;">';
    $message .= '<h4 style="margin: 0 0 10px 0; color: #155724;">✓ Payment Processed Successfully</h4>';
    $message .= '<p style="margin: 5px 0;"><strong>' . ($paymentType === 'downpayment' ? 'Downpayment ID' : 'Payment ID') . ':</strong> #' . $paymentId . '</p>';
    $message .= '<p style="margin: 5px 0;"><strong>Total Applied:</strong> ₱' . number_format($totalApplied, 2) . '</p>';
    $message .= '<p style="margin: 5px 0;"><strong>Date:</strong> ' . date('F j, Y', strtotime($paymentDate)) . '</p>';
    $message .= '</div>';
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => $message]);
    
} catch(PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Payment processing error: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>