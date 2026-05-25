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
$tuition = $_POST['tuition'] ?? null;
$misc = $_POST['misc'] ?? null;
$aircon = $_POST['aircon'] ?? null;
$hsa = $_POST['hsa'] ?? null;
$books = $_POST['books'] ?? null;

if ($grade === '') {
    echo json_encode(['success' => false, 'message' => 'grade_level is required']);
    exit();
}

foreach (['tuition' => $tuition, 'misc' => $misc, 'aircon' => $aircon, 'hsa' => $hsa, 'books' => $books] as $k => $v) {
    if (!is_numeric($v) || (float) $v < 0) {
        echo json_encode(['success' => false, 'message' => "Invalid $k"]);
        exit();
    }
}

try {
    baa_ensure_tuition_fees_table($pdo);

    $pdo->beginTransaction();

    $upd = $pdo->prepare("
        UPDATE tuition_fees
        SET tuition = ?, misc = ?, aircon = ?, hsa = ?, books = ?, updated_by = ?
        WHERE grade_level = ?
    ");
    $upd->execute([(float) $tuition, (float) $misc, (float) $aircon, (float) $hsa, (float) $books, (int) $_SESSION['user_id'], $grade]);

    // Update existing students' pending payables for this grade (paid history untouched)
    $students = $pdo->prepare("SELECT id FROM users WHERE role = 'student' AND grade_level = ?");
    $students->execute([$grade]);
    $studentIds = $students->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($studentIds)) {
        $map = [
            'Tuition' => (float) $tuition,
            'Misc' => (float) $misc,
            'Aircon' => (float) $aircon,
            'HSA' => (float) $hsa,
            'Books' => (float) $books,
        ];

        $in = implode(',', array_fill(0, count($studentIds), '?'));
        // Pending rows with these item names
        $payStmt = $pdo->prepare("
            SELECT id, student_id, item_name, status
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
        'message' => "Updated tuition fees for $grade.",
        'total' => baa_fee_total(['tuition' => $tuition, 'misc' => $misc, 'aircon' => $aircon, 'hsa' => $hsa, 'books' => $books]),
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}

