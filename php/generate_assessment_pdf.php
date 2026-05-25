<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Auth check: only allow authorized roles to generate administrative assessments
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'registrar', 'cashier'], true)) {
    http_response_code(403);
    die('Unauthorized access. Please log in as an administrator.');
}

// Clear any previous output
if (ob_get_level()) ob_clean();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/pdf_assessment_template.php';

// Get data (support both POST and GET via $_REQUEST)
$student_id = $_REQUEST['student_id'] ?? 0;
$tuition = floatval($_REQUEST['tuition'] ?? 0);
$misc = floatval($_REQUEST['misc'] ?? 0);
$aircon = floatval($_REQUEST['aircon'] ?? 0);
$hsa = floatval($_REQUEST['hsa'] ?? 0);
$books = floatval($_REQUEST['books'] ?? 0);
$discounts = floatval($_REQUEST['discounts'] ?? 0);
$downPayment = floatval($_REQUEST['downPayment'] ?? 0);
$monthlyPayments = intval($_REQUEST['monthlyPayments'] ?? 4);
$monthlyAmount = floatval($_REQUEST['monthlyPaymentAmount'] ?? 0);

if (!$student_id) {
    http_response_code(400);
    die('Student ID required');
}

try {
    // Generate the PDF using the shared template function
    $pdf = baa_build_assessment_pdf($pdo, (int)$student_id, [
        'tuition' => $tuition,
        'misc' => $misc,
        'aircon' => $aircon,
        'hsa' => $hsa,
        'books' => $books,
        'discounts' => $discounts,
        'downPayment' => $downPayment,
        'monthlyPayments' => $monthlyPayments,
        'monthlyPaymentAmount' => $monthlyAmount,
    ]);

    // Fetch LRN for filename
    $stmt = $pdo->prepare("SELECT lrn FROM users WHERE id = ?");
    $stmt->execute([(int)$student_id]);
    $lrn = $stmt->fetchColumn() ?: 'student';

    // Output PDF
    $pdf->Output('D', 'Assessment_' . $lrn . '.pdf');
} catch (Exception $e) {
    http_response_code(500);
    echo "Error generating PDF: " . htmlspecialchars($e->getMessage());
}
?>