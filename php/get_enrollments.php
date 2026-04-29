<?php
session_start();

try {
    require_once 'db.php';
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database connection error']);
    exit();
}

// Check if user is admin or registrar
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'registrar'])) {
    header('Content-Type: application/json');
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

    // Get all enrollments with their documents
    $stmt = $pdo->prepare("
        SELECT 
        e.id,
        CONCAT_WS(' ', 
            e.first_name, 
            e.middle_name, 
            e.last_name, 
            e.suffix
        ) AS full_name,
        e.first_name,
        e.middle_name,
        e.last_name,
        e.suffix,
        e.age,
        e.gender,
        e.birthdate,
        e.email,
        e.phone,
        e.grade_level,
        e.strand,
        e.status,
        e.created_at,
        COUNT(ed.id) as document_count,
        COALESCE(dp.total_downpayment, 0) AS downpayment_total
        FROM enrollments e
        LEFT JOIN enrollment_documents ed ON e.id = ed.enrollment_id
        LEFT JOIN (
            SELECT enrollment_id, SUM(amount) AS total_downpayment
            FROM enrollment_downpayments
            GROUP BY enrollment_id
        ) dp ON dp.enrollment_id = e.id
        GROUP BY e.id
        ORDER BY e.created_at DESC
    ");
    $stmt->execute();
    $enrollments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'enrollments' => $enrollments
    ]);
} catch(PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
