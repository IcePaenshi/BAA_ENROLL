<?php
header('Content-Type: application/json');
session_start();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/get_fee_breakdown.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$grade = trim($_POST['grade_level'] ?? '');
if ($grade === '') {
    echo json_encode(['success' => false, 'message' => 'grade_level is required']);
    exit();
}

$defaults = baa_default_fee_breakdowns();
if (!isset($defaults[$grade])) {
    echo json_encode(['success' => false, 'message' => 'Unknown grade']);
    exit();
}

try {
    $b = $defaults[$grade];
    baa_ensure_tuition_fees_table($pdo);

    $pdo->beginTransaction();

    $upd = $pdo->prepare("
        UPDATE tuition_fees
        SET tuition = ?, misc = ?, aircon = ?, hsa = ?, books = ?, updated_by = ?
        WHERE grade_level = ?
    ");
    $upd->execute([
        (float) $b['tuition'],
        (float) $b['misc'],
        (float) $b['aircon'],
        (float) $b['hsa'],
        (float) $b['books'],
        (int) $_SESSION['user_id'],
        $grade
    ]);

    // Update existing pending payables for this grade
    $students = $pdo->prepare("SELECT id FROM users WHERE role = 'student' AND grade_level = ?");
    $students->execute([$grade]);
    $studentIds = $students->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($studentIds)) {
        $map = [
            'Tuition' => (float) $b['tuition'],
            'Misc' => (float) $b['misc'],
            'Aircon' => (float) $b['aircon'],
            'HSA' => (float) $b['hsa'],
            'Books' => (float) $b['books'],
        ];

        $in = implode(',', array_fill(0, count($studentIds), '?'));
        $payStmt = $pdo->prepare("
            SELECT id, item_name
            FROM payables
            WHERE student_id IN ($in)
              AND (status = 'pending' OR status IS NULL OR status = '')
              AND item_name IN ('Tuition','Misc','Aircon','HSA','Books')
        ");
        $payStmt->execute($studentIds);
        $rows = $payStmt->fetchAll(PDO::FETCH_ASSOC);

        $updPay = $pdo->prepare("UPDATE payables SET amount = ? WHERE id = ?");
        foreach ($rows as $r) {
            $name = trim((string) ($r['item_name'] ?? ''));
            if (!array_key_exists($name, $map)) continue;
            $updPay->execute([(float) $map[$name], (int) $r['id']]);
        }
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => "Reset tuition fees for $grade to defaults.",
        'total' => baa_fee_total($b),
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}

