<?php
session_start();
require_once 'db.php';

// Check permissions – now include cashier
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'registrar', 'cashier'], true)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

header('Content-Type: application/json');

$raw = file_get_contents('php://input');
$requestData = json_decode($raw !== false && $raw !== '' ? $raw : '[]', true);
if (!is_array($requestData)) {
    $requestData = [];
}

$search = $requestData['search'] ?? '';
$role = $requestData['role'] ?? '';
$userId = $requestData['user_id'] ?? '';
$limit = $requestData['limit'] ?? null;

$sessionRole = $_SESSION['role'];

$selectCols = "
    id,
    username,
    email,
    role,
    grade_level,
    section,
    lrn,
    status,
    created_at,
    first_name,
    middle_name,
    last_name,
    suffix,
    CONCAT_WS(' ', first_name, middle_name, last_name, suffix) AS full_name,
    age,
    gender,
    birthdate,
    phone,
    strand
";

try {
    if (!empty($userId)) {
        $stmt = $pdo->prepare("
            SELECT $selectCols
            FROM users
            WHERE id = ?
        ");
        $stmt->execute([$userId]);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($users) && $sessionRole === 'registrar' && ($users[0]['role'] ?? '') === 'admin') {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit();
        }
    } else {
        $whereConditions = [];
        $params = [];

        if ($sessionRole === 'registrar') {
            $whereConditions[] = "role <> 'admin'";
        }

        if (!empty($search)) {
            $whereConditions[] = '(CONCAT_WS(\' \', first_name, middle_name, last_name, suffix) LIKE ? OR username LIKE ? OR email LIKE ?)';
            $term = '%' . $search . '%';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        if (!empty($role)) {
            $whereConditions[] = 'role = ?';
            $params[] = $role;
        }

        $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
        $limitClause = $limit ? 'LIMIT ' . (int) $limit : '';

        $stmt = $pdo->prepare("
            SELECT $selectCols
            FROM users
            $whereClause
            ORDER BY created_at DESC
            $limitClause
        ");
        $stmt->execute($params);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode([
        'success' => true,
        'users' => $users,
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
