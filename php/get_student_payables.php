<?php
header('Content-Type: application/json');
session_start();
require_once 'db.php';
require_once __DIR__ . '/get_fee_breakdown.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'cashier'], true)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$studentId = $_GET['student_id'] ?? '';

if (!$studentId || !is_numeric($studentId)) {
    echo json_encode(['success' => false, 'message' => 'Invalid student ID']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT id, item_name, amount, due_date, status FROM payables WHERE student_id = ? ORDER BY due_date");
    $stmt->execute([$studentId]);
    $payables = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Totals
    $uStmt = $pdo->prepare("SELECT grade_level, student_id FROM users WHERE id = ? LIMIT 1");
    $uStmt->execute([(int) $studentId]);
    $u = $uStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $gradeLevel = (string) ($u['grade_level'] ?? '');
    $feeTotal = 0.0;
    if ($gradeLevel !== '') {
        $b = baa_get_fee_breakdown($pdo, $gradeLevel);
        if ($b) $feeTotal = baa_fee_total($b);
    }

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

    $remainingStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payables WHERE student_id = ? AND (status = 'pending' OR status IS NULL OR status = '')");
    $remainingStmt->execute([(int) $studentId]);
    $remainingDue = (float) ($remainingStmt->fetchColumn() ?: 0);

    $paidStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE student_id = ?");
    $paidStmt->execute([(int) $studentId]);
    $totalPaid = (float) ($paidStmt->fetchColumn() ?: 0);

    echo json_encode([
        'success' => true,
        'payables' => $payables,
        'totals' => [
            'grade_level' => $gradeLevel,
            'fee_total' => round($feeTotal, 2),
            'downpayment_total' => round($downpaymentTotal, 2),
            'total_paid' => round($totalPaid, 2),
            'remaining_due' => round($remainingDue, 2),
            'total_reduced' => round($downpaymentTotal + $totalPaid, 2),
        ]
    ]);
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>