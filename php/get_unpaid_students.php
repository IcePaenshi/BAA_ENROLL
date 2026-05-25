<?php
require_once 'db.php';
session_start();

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'cashier'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    // We will do a simplified calculation for all students
    $stmt = $pdo->query("SELECT id, full_name, grade_level, section FROM users WHERE role = 'student' ORDER BY grade_level ASC, section ASC, full_name ASC");
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get all tuition fees
    $tfStmt = $pdo->query("SELECT grade_level, SUM(amount) as total FROM tuition_fees GROUP BY grade_level");
    $tuitionFees = $tfStmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // Get all payments
    $pStmt = $pdo->query("SELECT student_id, SUM(amount) as total FROM payments GROUP BY student_id");
    $payments = $pStmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // Get all student downpayments
    $sdStmt = $pdo->query("SELECT student_id, SUM(amount) as total FROM student_downpayments GROUP BY student_id");
    $studentDownpayments = $sdStmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // Get all enrollment downpayments (need to map enrollment to user)
    // Actually, enrollment downpayments are linked to enrollment_id. If a student is accepted, their total paid includes it, but `student_downpayments` might also have it.
    $edStmt = $pdo->query("SELECT e.user_id, SUM(ed.amount) as total FROM enrollment_downpayments ed JOIN enrollments e ON ed.enrollment_id = e.id WHERE e.user_id IS NOT NULL GROUP BY e.user_id");
    $enrollmentDownpayments = $edStmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // Get all discounts
    $discStmt = $pdo->query("SELECT student_id, SUM(amount) as total FROM student_discounts GROUP BY student_id");
    $discounts = $discStmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // Get all manual payables
    $mpStmt = $pdo->query("SELECT student_id, SUM(amount) as total FROM payables WHERE status != 'paid' GROUP BY student_id");
    $manualPayables = $mpStmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $unpaidStudents = [];

    foreach ($students as $st) {
        $sid = $st['id'];
        $grade = $st['grade_level'];
        
        $tf = $tuitionFees[$grade] ?? 0;
        $disc = $discounts[$sid] ?? 0;
        $mp = $manualPayables[$sid] ?? 0;
        
        $totalDue = max(0, $tf - $disc) + $mp;
        
        $paid1 = $payments[$sid] ?? 0;
        $paid2 = $studentDownpayments[$sid] ?? 0;
        $paid3 = $enrollmentDownpayments[$sid] ?? 0;
        
        $totalPaid = $paid1 + $paid2 + $paid3;
        
        $balance = $totalDue - $totalPaid;
        
        if ($balance > 0 || $totalPaid == 0) {
            $unpaidStudents[] = [
                'id' => $sid,
                'full_name' => $st['full_name'],
                'grade_level' => $grade,
                'section' => $st['section'],
                'total_due' => $totalDue,
                'total_paid' => $totalPaid,
                'balance' => $balance
            ];
        }
    }

    echo json_encode(['success' => true, 'students' => $unpaidStudents]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
