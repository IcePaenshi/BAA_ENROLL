<?php
require_once 'db.php';
header('Content-Type: application/json');

try {
    $res = [];
    
    // Check trimester_grades
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM trimester_grades");
        $res['trimester_grades_count'] = (int)$stmt->fetchColumn();
    } catch (Exception $e) { $res['trimester_grades_error'] = $e->getMessage(); }
    
    // Check legacy grades
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM grades");
        $res['legacy_grades_count'] = (int)$stmt->fetchColumn();
    } catch (Exception $e) { $res['legacy_grades_error'] = $e->getMessage(); }
    
    // Check users
    try {
        $stmt = $pdo->query("SELECT role, COUNT(*) as count FROM users GROUP BY role");
        $res['users_by_role'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Exception $e) { $res['users_error'] = $e->getMessage(); }

    echo json_encode($res, JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
