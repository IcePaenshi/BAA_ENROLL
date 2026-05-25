<?php
header('Content-Type: application/json');
session_start();

try {
    require_once 'db.php';
    require_once __DIR__ . '/get_fee_breakdown.php';
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database connection error']);
    exit();
}

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'student') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$userId = $_SESSION['user_id'];

try {
    $uStmt = $pdo->prepare("SELECT grade_level, student_id, payment_plan_months, payment_start_date FROM users WHERE id = ? LIMIT 1");
    $uStmt->execute([(int) $userId]);
    $u = $uStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $gradeLevel = (string) ($u['grade_level'] ?? '');
    $paymentPlanMonths = (int)($u['payment_plan_months'] ?? 4);
    if ($paymentPlanMonths < 1) $paymentPlanMonths = 4;
    $paymentStartDate = $u['payment_start_date'] ?: date('Y-m-d');

    // 2. Calculate Core Tuition & Fees
    $feeTotal = 0.0;
    if ($gradeLevel !== '') {
        $b = baa_get_fee_breakdown($pdo, $gradeLevel);
        if ($b) $feeTotal = baa_fee_total($b);
    }

    // 3. Fetch manual payables
    $stmt = $pdo->prepare("SELECT id, item_name, amount, due_date, status FROM payables WHERE student_id = ? AND item_name NOT LIKE 'Tuition%' AND item_name NOT LIKE 'Misc%' AND item_name NOT LIKE 'Aircon%' AND item_name NOT LIKE 'HSA%' AND item_name NOT LIKE 'Books%' ORDER BY due_date");
    $stmt->execute([$userId]);
    $manualPayablesRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $manualPayablesTotal = 0.0;
    foreach ($manualPayablesRows as $row) {
        $manualPayablesTotal += (float)$row['amount'];
    }

    $overallTotalFees = $feeTotal + $manualPayablesTotal;

    // 4. Fetch Downpayments
    $downpaymentTotal = 0.0;
    $studentKey = (string) ($u['student_id'] ?? '');
    if (preg_match('/^ENR-(\d+)$/', $studentKey, $m)) {
        $sumStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM enrollment_downpayments WHERE enrollment_id = ?");
        $sumStmt->execute([(int) $m[1]]);
        $downpaymentTotal += (float) ($sumStmt->fetchColumn() ?: 0);
    }

    $sdStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM student_downpayments WHERE student_id = ?");
    $sdStmt->execute([(int) $userId]);
    $downpaymentTotal += (float) ($sdStmt->fetchColumn() ?: 0);

    // 5. Fetch Payments
    $paidStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE student_id = ?");
    $paidStmt->execute([(int) $userId]);
    $totalPaid = (float) ($paidStmt->fetchColumn() ?: 0);

    // 6. Fetch Discounts Applied
    $discStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM student_discounts WHERE student_id = ?");
    $discStmt->execute([(int) $userId]);
    $discountsTotal = (float) ($discStmt->fetchColumn() ?: 0);

    // 7. Calculate Remaining Balance
    $totalDeductions = $downpaymentTotal + $totalPaid + $discountsTotal;
    $remainingBalance = max(0, $overallTotalFees - $totalDeductions);

    // 8. Generate Dynamic Payables List
    $dynamicPayables = [];
    $paymentAvailable = $totalDeductions;
    
    foreach ($manualPayablesRows as $row) {
        $itemAmount = (float)$row['amount'];
        $itemStatus = 'pending';
        $itemDue = $itemAmount;
        
        if ($paymentAvailable >= $itemAmount) {
            $itemStatus = 'paid';
            $paymentAvailable -= $itemAmount;
            $itemDue = 0;
        } else if ($paymentAvailable > 0) {
            $itemStatus = 'partially_paid';
            $itemDue = $itemAmount - $paymentAvailable;
            $paymentAvailable = 0;
        }
        
        $dynamicPayables[] = [
            'id' => $row['id'],
            'description' => $row['item_name'],
            'amount' => $itemAmount,
            'due_date' => $row['due_date'],
            'status' => $itemStatus,
            'remaining_due' => $itemDue
        ];
    }
    
    // Distribute remaining payments over tuition installments
    if ($paymentAvailable >= $feeTotal) {
        $paymentAvailable -= $feeTotal;
        $dynamicPayables[] = [
            'id' => 'tuition-paid',
            'description' => 'Total Tuition & Fees (Fully Paid)',
            'amount' => $feeTotal,
            'due_date' => $paymentStartDate,
            'status' => 'paid',
            'remaining_due' => 0
        ];
    } else {
        $monthlyDue = $paymentPlanMonths > 0 ? ($feeTotal / $paymentPlanMonths) : $feeTotal;
        $monthlyDue = round($monthlyDue, 2);
        $startDate = new DateTime($paymentStartDate);
        
        for ($i = 0; $i < $paymentPlanMonths; $i++) {
            $instStatus = 'pending';
            $instAmount = ($i === $paymentPlanMonths - 1) ? 
                round($feeTotal - ($monthlyDue * ($paymentPlanMonths - 1)), 2) : 
                $monthlyDue;
            $instDue = $instAmount;
            
            if ($paymentAvailable >= $instAmount) {
                $instStatus = 'paid';
                $paymentAvailable -= $instAmount;
                $instDue = 0;
            } else if ($paymentAvailable > 0) {
                $instStatus = 'partially_paid';
                $instDue = round($instAmount - $paymentAvailable, 2);
                $paymentAvailable = 0;
            }
            
            $dynamicPayables[] = [
                'id' => 'tuition-inst-' . $i,
                'description' => 'Tuition Installment ' . ($i + 1) . ' of ' . $paymentPlanMonths,
                'amount' => $instAmount,
                'due_date' => $startDate->format('Y-m-d'),
                'status' => $instStatus,
                'remaining_due' => $instDue
            ];
            $startDate->modify('+1 month');
        }
    }
    
    echo json_encode([
        'success' => true,
        'payables' => $dynamicPayables,
        'totals' => [
            'grade_level' => $gradeLevel,
            'fee_total' => $overallTotalFees,
            'downpayment_total' => $downpaymentTotal,
            'total_paid' => $totalPaid,
            'discounts_total' => $discountsTotal,
            'remaining_due' => $remainingBalance,
            'payment_plan_months' => $paymentPlanMonths
        ]
    ]);
} catch(PDOException $e) {
    error_log("Error in get_payables.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>