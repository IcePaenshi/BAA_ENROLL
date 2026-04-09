<?php
session_start();
require_once 'db.php';

// Check permissions – now include cashier
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'registrar', 'cashier'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

header('Content-Type: application/json');

// Get request data
$requestData = json_decode(file_get_contents('php://input'), true);
$search = $requestData['search'] ?? '';
$role = $requestData['role'] ?? '';
$userId = $requestData['user_id'] ?? '';
$limit = $requestData['limit'] ?? null;

try {
    if (!empty($userId)) {
        // Get specific user by ID
        $stmt = $pdo->prepare("
            SELECT
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
                suffix
            FROM users
            WHERE id = ?
        ");
        $stmt->execute([$userId]);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Build query for user search/list
        $whereConditions = [];
        $params = [];

        if (!empty($search)) {
            $whereConditions[] = "CONCAT_WS(' ', first_name, middle_name, last_name, suffix) LIKE ?";
            $params[] = '%' . $search . '%';
        }

        if (!empty($role)) {
            $whereConditions[] = "role = ?";
            $params[] = $role;
        }

        $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
        $limitClause = $limit ? 'LIMIT ' . (int)$limit : '';

        $stmt = $pdo->prepare("
            SELECT
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
                suffix
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
        'users' => $users
    ]);

} catch(PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>