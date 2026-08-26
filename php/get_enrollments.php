<?php
session_start();

try {
    require_once 'db.php';
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database connection error']);
    exit();
}

// Check if user is admin, registrar, or cashier
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'registrar', 'cashier'], true)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = isset($_GET['per_page']) ? max(1, intval($_GET['per_page'])) : 10;
$offset = ($page - 1) * $per_page;

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS enrollment_downpayments (\n            id INT AUTO_INCREMENT PRIMARY KEY,\n            enrollment_id INT NOT NULL,\n            amount DECIMAL(10,2) NOT NULL,\n            payment_date DATE NOT NULL,\n            processed_by INT NOT NULL,\n            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n            INDEX idx_enrollment_id (enrollment_id)\n        )");

    try {
        $pdo->exec("ALTER TABLE enrollment_downpayments ADD COLUMN IF NOT EXISTS payment_mode VARCHAR(32) DEFAULT NULL");
    } catch (PDOException $e) {
        // ignore if ALTER not supported on older MySQL versions
    }

    // Get total count
    $countStmt = $pdo->query("SELECT COUNT(*) FROM enrollments");
    $total = $countStmt->fetchColumn();

    // Get paginated enrollments
    $stmt = $pdo->prepare("
        SELECT 
            e.id,
            " . baa_full_name_sql('e') . " AS full_name,
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
            e.student_type,
            e.status,
            e.created_at,
            e.lrn,
            u.section,
            COUNT(ed.id) AS document_count,
            COALESCE(dp.total_downpayment, 0) AS downpayment_total
        FROM enrollments e
        LEFT JOIN enrollment_documents ed ON e.id = ed.enrollment_id
        LEFT JOIN users u ON u.student_id = CONCAT('ENR-', e.id) AND u.role = 'student'
        LEFT JOIN (
            SELECT enrollment_id, SUM(amount) AS total_downpayment
            FROM enrollment_downpayments
            GROUP BY enrollment_id
        ) dp ON dp.enrollment_id = e.id
        GROUP BY e.id, u.section
        ORDER BY e.created_at DESC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $enrollments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'enrollments' => $enrollments,
        'total' => (int)$total,
        'page' => $page,
        'per_page' => $per_page
    ]);
} catch(PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
