<?php
session_start();
require_once 'db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'cashier'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);

if (!is_array($data) || empty($data)) {
    echo json_encode(['success' => false, 'message' => 'No data provided']);
    exit();
}

try {
    $pdo->beginTransaction();
    
    if (isset($data['action'])) {
        if ($data['action'] === 'add' && isset($data['name'])) {
            $stmt = $pdo->prepare("INSERT INTO discounts (name, amount) VALUES (?, 0)");
            $stmt->execute([$data['name']]);
        } elseif ($data['action'] === 'delete' && isset($data['id'])) {
            $stmt = $pdo->prepare("DELETE FROM discounts WHERE id = ?");
            $stmt->execute([(int)$data['id']]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            exit();
        }
    } else {
        $stmt = $pdo->prepare("UPDATE discounts SET amount = ? WHERE id = ?");
        
        foreach ($data as $discount) {
            if (isset($discount['id'], $discount['amount'])) {
                $stmt->execute([(float)$discount['amount'], (int)$discount['id']]);
            }
        }
    }
    
    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Discounts updated successfully']);
} catch(PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
