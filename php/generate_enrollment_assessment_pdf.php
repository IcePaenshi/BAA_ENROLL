<?php
session_start();

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'registrar', 'cashier'], true)) {
    http_response_code(403);
    exit('Unauthorized');
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/pdf_assessment_template.php';

if (ob_get_level()) {
    ob_clean();
}

$enrollmentId = isset($_GET['enrollment_id']) ? (int) $_GET['enrollment_id'] : 0;
if ($enrollmentId < 1) {
    http_response_code(400);
    exit('Invalid enrollment ID');
}

$stmt = $pdo->prepare("
    SELECT e.*,
           " . baa_full_name_sql('e') . " AS full_name
    FROM enrollments e
    WHERE e.id = ?
    LIMIT 1
");
$stmt->execute([$enrollmentId]);
$enrollment = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$enrollment) {
    http_response_code(404);
    exit('Enrollment not found');
}

if (($enrollment['status'] ?? '') !== 'approved' && !in_array($_SESSION['role'], ['admin', 'registrar', 'cashier'], true)) {
    http_response_code(400);
    exit('Assessment PDF is available only for accepted enrollments');
}

$studentKey = 'ENR-' . $enrollmentId;
$studentStmt = $pdo->prepare("
    SELECT id, lrn, grade_level, section
    FROM users
    WHERE role = 'student' AND (student_id = ? OR email = ?)
    ORDER BY id DESC
    LIMIT 1
");
$studentStmt->execute([$studentKey, (string) ($enrollment['email'] ?? '')]);
$student = $studentStmt->fetch(PDO::FETCH_ASSOC);

if ($student) {
    $pdf = baa_build_assessment_pdf($pdo, (int) $student['id'], [
        'tuition' => 0,
        'misc' => 0,
        'aircon' => 0,
        'hsa' => 0,
        'books' => 0,
        'discounts' => 0,
        'downPayment' => 0,
        'monthlyPayments' => 4,
        'monthlyPaymentAmount' => 0,
    ]);
} else {
    $fallbackStudent = [
        'first_name' => $enrollment['first_name'] ?? '',
        'middle_name' => $enrollment['middle_name'] ?? '',
        'last_name' => $enrollment['last_name'] ?? '',
        'suffix' => $enrollment['suffix'] ?? '',
        'grade_level' => $enrollment['grade_level'] ?? '',
        'section' => $enrollment['section'] ?? '',
        'lrn' => $enrollment['lrn'] ?? '',
    ];

    $pdf = baa_build_assessment_pdf($pdo, $fallbackStudent, [
        'tuition' => 0,
        'misc' => 0,
        'aircon' => 0,
        'hsa' => 0,
        'books' => 0,
        'discounts' => 0,
        'downPayment' => 0,
        'monthlyPayments' => 4,
        'monthlyPaymentAmount' => 0,
    ]);
}

$pdf->Output('D', 'Assessment_ENR_' . $enrollmentId . '.pdf');
exit;

