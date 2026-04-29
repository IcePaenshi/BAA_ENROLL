<?php
session_start();
header('Content-Type: application/json');

require_once 'db.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'cashier'], true)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

try {
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

    $stmt = $pdo->query("
        SELECT
            e.id,
            CONCAT_WS(' ', e.first_name, e.middle_name, e.last_name, e.suffix) AS full_name,
            e.grade_level,
            e.status,
            COALESCE(dp.total_downpayment, 0) AS downpayment_total
        FROM enrollments e
        LEFT JOIN (
            SELECT enrollment_id, SUM(amount) AS total_downpayment
            FROM enrollment_downpayments
            GROUP BY enrollment_id
        ) dp ON dp.enrollment_id = e.id
        WHERE e.status IN ('pending', 'needs_docs', 'rejected')
        ORDER BY e.created_at DESC
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'enrollments' => $rows
    ]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
