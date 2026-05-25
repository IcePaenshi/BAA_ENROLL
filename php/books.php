<?php
session_start();
header('Content-Type: application/json');

try { require_once 'db.php'; } catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'DB error']); exit;
}

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'registrar'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
}

// Ensure table exists
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS books (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS book_grade_assignments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            book_id INT NOT NULL,
            grade_level VARCHAR(20) NOT NULL,
            UNIQUE KEY uniq_book_grade (book_id, grade_level),
            FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
        )
    ");
} catch (PDOException $e) { /* table may already exist */ }

$action = $_REQUEST['action'] ?? $_POST['action'] ?? '';

// GET list
if ($action === 'list') {
    $stmt = $pdo->query("
        SELECT b.id, b.title, b.price,
            GROUP_CONCAT(bga.grade_level ORDER BY bga.grade_level SEPARATOR ',') AS grade_levels
        FROM books b
        LEFT JOIN book_grade_assignments bga ON bga.book_id = b.id
        GROUP BY b.id
        ORDER BY b.title
    ");
    $books = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($books as &$b) {
        $b['grade_levels'] = $b['grade_levels'] ? explode(',', $b['grade_levels']) : [];
        $b['price'] = (float)$b['price'];
    }
    echo json_encode(['success' => true, 'books' => $books]);
    exit;
}

// GET total for grade
if ($action === 'total_for_grade') {
    $grade = trim($_GET['grade'] ?? '');
    if (!$grade) { echo json_encode(['success' => true, 'total' => 0]); exit; }
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(b.price), 0) AS total
        FROM books b
        JOIN book_grade_assignments bga ON bga.book_id = b.id
        WHERE bga.grade_level = ?
    ");
    $stmt->execute([$grade]);
    $total = (float)$stmt->fetchColumn();
    echo json_encode(['success' => true, 'total' => $total]);
    exit;
}

// POST save (add/edit)
if ($action === 'save') {
    $id = (int)($_POST['id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $price = (float)($_POST['price'] ?? 0);
    $grade_levels = json_decode($_POST['grade_levels'] ?? '[]', true);
    if (!is_array($grade_levels)) $grade_levels = [];

    if (!$title) { echo json_encode(['success' => false, 'message' => 'Title is required']); exit; }
    if ($price < 0) { echo json_encode(['success' => false, 'message' => 'Price must be non-negative']); exit; }

    try {
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE books SET title = ?, price = ? WHERE id = ?");
            $stmt->execute([$title, $price, $id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO books (title, price) VALUES (?, ?)");
            $stmt->execute([$title, $price]);
            $id = (int)$pdo->lastInsertId();
        }
        // Re-assign grades
        $pdo->prepare("DELETE FROM book_grade_assignments WHERE book_id = ?")->execute([$id]);
        $ins = $pdo->prepare("INSERT IGNORE INTO book_grade_assignments (book_id, grade_level) VALUES (?, ?)");
        foreach ($grade_levels as $gl) {
            $gl = trim($gl);
            if ($gl) $ins->execute([$id, $gl]);
        }
        echo json_encode(['success' => true, 'id' => $id]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// POST delete
if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id < 1) { echo json_encode(['success' => false, 'message' => 'Invalid ID']); exit; }
    $pdo->prepare("DELETE FROM books WHERE id = ?")->execute([$id]);
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action']);
?>
