<?php
session_start();
require_once 'db.php';

header('Content-Type: application/json');

// Check permissions
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'cashier'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

// Get required fields
$studentId = $_POST['student_id'] ?? '';
$remainingBalance = $_POST['remaining_balance'] ?? '';
$monthlyPayments = $_POST['monthly_payments'] ?? '';
$monthlyPaymentAmount = $_POST['monthly_payment_amount'] ?? '';

// Validate
if (!is_numeric($studentId) || $studentId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid student ID']);
    exit();
}

if (!is_numeric($remainingBalance) || $remainingBalance <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid remaining balance']);
    exit();
}

if (!is_numeric($monthlyPayments) || $monthlyPayments <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid number of payments']);
    exit();
}

if (!is_numeric($monthlyPaymentAmount) || $monthlyPaymentAmount <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid monthly amount']);
    exit();
}

try {
    $pdo->beginTransaction();

    // Set first due date to the 10th of next month (adjust as needed)
    $dueDate = new DateTime();
    $dueDate->modify('first day of next month')->setDate($dueDate->format('Y'), $dueDate->format('m'), 10);

    for ($i = 1; $i <= $monthlyPayments; $i++) {
        $itemName = "Monthly Tuition Payment {$i}/{$monthlyPayments}";
        $amount = $monthlyPaymentAmount;

        // Adjust last payment to cover rounding differences
        if ($i == $monthlyPayments) {
            $sumPrevious = $monthlyPaymentAmount * ($monthlyPayments - 1);
            $amount = $remainingBalance - $sumPrevious;
        }

        // Generate a unique order number for each payable
        $orderNumber = 'ORD-' . time() . '-' . $i . '-' . uniqid();

        $stmt = $pdo->prepare("
            INSERT INTO payables (student_id, item_name, amount, due_date, status, order_number)
            VALUES (?, ?, ?, ?, 'pending', ?)
        ");
        $stmt->execute([$studentId, $itemName, $amount, $dueDate->format('Y-m-d'), $orderNumber]);

        // Next month
        $dueDate->modify('+1 month');
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Payables added successfully']);
} catch (PDOException $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>