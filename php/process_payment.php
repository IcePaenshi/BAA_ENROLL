<?php
session_start();
require_once 'db.php';

// Check if user is admin or super_admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$studentId = $_POST['student_id'] ?? '';
$amount = $_POST['amount'] ?? '';
$paymentDate = $_POST['payment_date'] ?? date('Y-m-d');

if (!$studentId || !is_numeric($studentId)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid student ID']);
    exit();
}

if (!is_numeric($amount) || $amount <= 0) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid payment amount']);
    exit();
}

try {
    // Start transaction
    $pdo->beginTransaction();
    
    // DEBUG: Log what we're trying to do
    error_log("Processing payment: Student $studentId, Amount: $amount");
    
    // Get ALL payables (pending and unpaid) for the student
    $stmt = $pdo->prepare("SELECT id, item_name, amount, due_date, status FROM payables WHERE student_id = ? AND (status = 'pending' OR status IS NULL OR status = '') ORDER BY due_date ASC");
    $stmt->execute([$studentId]);
    $payables = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    error_log("Found " . count($payables) . " payables for student $studentId");
    
    if (empty($payables)) {
        $pdo->rollBack();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'No payables found for this student']);
        exit();
    }
    
    $remainingAmount = floatval($amount);
    $paidPayables = [];
    $totalApplied = 0;
    $updatedPayableIds = [];
    
    // Apply payment to payables (oldest first)
    foreach ($payables as $payable) {
        if ($remainingAmount <= 0) break;
        
        $payableId = $payable['id'];
        $payableAmount = floatval($payable['amount']);
        $payableName = $payable['item_name'] ?: 'Tuition Fee';
        $currentStatus = $payable['status'];
        
        // Skip if already paid
        if ($currentStatus === 'paid') {
            continue;
        }
        
        error_log("Processing payable $payableId: Amount $payableAmount, Status: $currentStatus");
        
        if ($remainingAmount >= $payableAmount) {
            // FULL PAYMENT: Mark as paid
            $updateStmt = $pdo->prepare("UPDATE payables SET status = 'paid' WHERE id = ?");
            $updateResult = $updateStmt->execute([$payableId]);
            
            error_log("Marked payable $payableId as paid. Rows affected: " . $updateStmt->rowCount());
            
            $paidPayables[] = $payableName . ' (₱' . number_format($payableAmount, 2) . ')';
            $remainingAmount -= $payableAmount;
            $totalApplied += $payableAmount;
            $updatedPayableIds[] = $payableId;
            
        } else {
            // PARTIAL PAYMENT: Update amount and keep as pending
            $newAmount = $payableAmount - $remainingAmount;
            $updateStmt = $pdo->prepare("UPDATE payables SET amount = ?, status = 'pending' WHERE id = ?");
            $updateResult = $updateStmt->execute([$newAmount, $payableId]);
            
            error_log("Updated payable $payableId to amount $newAmount. Rows affected: " . $updateStmt->rowCount());
            
            $paidPayables[] = $payableName . ' (₱' . number_format($remainingAmount, 2) . ' partial of ₱' . number_format($payableAmount, 2) . ')';
            $totalApplied += $remainingAmount;
            $remainingAmount = 0;
            $updatedPayableIds[] = $payableId;
        }
    }
    
    // Record the payment transaction
    $stmt = $pdo->prepare("INSERT INTO payments (student_id, amount, payment_date, processed_by) VALUES (?, ?, ?, ?)");
    $stmt->execute([$studentId, $totalApplied, $paymentDate, $_SESSION['user_id']]);
    $paymentId = $pdo->lastInsertId();
    
    error_log("Created payment record $paymentId for amount $totalApplied");
    
    // Commit transaction
    $pdo->commit();
    
    // Verify updates
    error_log("Updated payable IDs: " . implode(', ', $updatedPayableIds));
    
    // Prepare success message
    $message = '<div style="color: #155724; background: #d4edda; padding: 15px; border-radius: 4px; margin-bottom: 15px;">';
    $message .= '<h4 style="margin: 0 0 10px 0; color: #155724;">✓ Payment Processed Successfully</h4>';
    $message .= '<p style="margin: 5px 0;"><strong>Payment ID:</strong> #' . $paymentId . '</p>';
    $message .= '<p style="margin: 5px 0;"><strong>Total Applied:</strong> ₱' . number_format($totalApplied, 2) . '</p>';
    $message .= '<p style="margin: 5px 0;"><strong>Date:</strong> ' . date('F j, Y', strtotime($paymentDate)) . '</p>';
    $message .= '</div>';
    
    if (!empty($paidPayables)) {
        $message .= '<div style="margin-top: 15px;"><strong>Payment Applied To:</strong><ul style="margin: 10px 0 0 20px; padding: 0;">';
        foreach ($paidPayables as $item) {
            $message .= '<li style="margin: 5px 0;">' . $item . '</li>';
        }
        $message .= '</ul></div>';
    }
    
    if ($remainingAmount > 0) {
        $message .= '<div style="margin-top: 15px; color: #856404; background: #fff3cd; padding: 10px; border-radius: 4px;">';
        $message .= '<strong>Note:</strong> ₱' . number_format($remainingAmount, 2) . ' was refunded/excess.';
        $message .= '</div>';
    }
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => $message]);
    
} catch(PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Payment processing error: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>