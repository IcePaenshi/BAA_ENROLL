<?php
header('Content-Type: application/json');
session_start();

try {
    require_once 'db.php';
    require_once __DIR__ . '/get_fee_breakdown.php';
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database connection error']);
    exit();
}

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'student') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$userId = $_SESSION['user_id'];

try {
    $uStmt = $pdo->prepare("SELECT grade_level, student_id FROM users WHERE id = ? LIMIT 1");
    $uStmt->execute([(int) $userId]);
    $u = $uStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $gradeLevel = (string) ($u['grade_level'] ?? '');

    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(item_name, 'Tuition Fee') as description, 
            due_date, 
            amount,
            COALESCE(status, 'pending') as status
        FROM payables 
        WHERE student_id = ? 
        ORDER BY due_date ASC, status ASC
    ");
    $stmt->execute([$userId]);
    $payables = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $sumPayablesStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payables WHERE student_id = ?");
    $sumPayablesStmt->execute([$userId]);
    $remainingPayables = (float) $sumPayablesStmt->fetchColumn();

    $sumPaymentsStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE student_id = ?");
    $sumPaymentsStmt->execute([$userId]);
    $totalPaid = (float) $sumPaymentsStmt->fetchColumn();

    // Downpayment total if student_id is ENR-{id}
    $downpaymentTotal = 0.0;
    $studentKey = (string) ($u['student_id'] ?? '');
    if (preg_match('/^ENR-(\d+)$/', $studentKey, $m)) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS enrollment_downpayments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                enrollment_id INT NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                payment_date DATE NOT NULL,
                processed_by INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_enrollment_id (enrollment_id)
            )
        ");
        $sumStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM enrollment_downpayments WHERE enrollment_id = ?");
        $sumStmt->execute([(int) $m[1]]);
        $downpaymentTotal = (float) ($sumStmt->fetchColumn() ?: 0);
    }

    $feeTotal = 0.0;
    if ($gradeLevel !== '') {
        $b = baa_get_fee_breakdown($pdo, $gradeLevel);
        if ($b) $feeTotal = baa_fee_total($b);
    }
    
    echo json_encode([
        'success' => true,
        'payables' => $payables,
        'totals' => [
            'grade_level' => $gradeLevel,
            'fee_total' => round($feeTotal, 2),
            'downpayment_total' => round($downpaymentTotal, 2),
            'total_paid' => round($totalPaid, 2),
            'total_reduced' => round($downpaymentTotal + $totalPaid, 2),
            'total_to_be_paid' => round($remainingPayables, 2),
            // Backwards-compatible keys used by existing UI
            'total_tuition_fee' => round($feeTotal, 2),
        ]
    ]);
} catch(PDOException $e) {
    error_log("Error in get_payables.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>