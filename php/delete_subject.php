<?php
/**
 * Admin endpoint to delete a subject.
 */
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Admin access required']);
    exit();
}

require_once 'db.php';

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if ($id < 1) {
    echo json_encode(['success' => false, 'message' => 'Invalid subject ID']);
    exit();
}

try {
    // Check if subject exists
    $check = $pdo->prepare("SELECT id, subject_name FROM subjects WHERE id = ?");
    $check->execute([$id]);
    $subject = $check->fetch(PDO::FETCH_ASSOC);

    if (!$subject) {
        echo json_encode(['success' => false, 'message' => 'Subject not found']);
        exit();
    }

    // Remove teacher assignments first
    $pdo->prepare("DELETE FROM teacher_subjects WHERE subject_id = ?")->execute([$id]);

    // Remove grades for this subject
    $pdo->prepare("DELETE FROM grades WHERE subject_id = ?")->execute([$id]);

    // Delete the subject
    $pdo->prepare("DELETE FROM subjects WHERE id = ?")->execute([$id]);

    echo json_encode(['success' => true, 'message' => 'Subject "' . $subject['subject_name'] . '" deleted successfully']);
} catch (PDOException $e) {
    error_log('delete_subject error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
