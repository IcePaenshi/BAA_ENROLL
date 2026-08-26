<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'cashier'], true)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS enrollment_downpayments (\n            id INT AUTO_INCREMENT PRIMARY KEY,\n            enrollment_id INT NOT NULL,\n            amount DECIMAL(10,2) NOT NULL,\n            payment_date DATE NOT NULL,\n            processed_by INT NOT NULL,\n            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n            INDEX idx_enrollment_id (enrollment_id)\n        )");

    try {
        $pdo->exec("ALTER TABLE enrollment_downpayments ADD COLUMN IF NOT EXISTS payment_mode VARCHAR(32) DEFAULT NULL");
    } catch (PDOException $e) {
        // ignore if ALTER not supported
    }

    $stmt = $pdo->query("
        SELECT
            e.id,
            " . baa_full_name_sql('e') . " AS full_name,
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
