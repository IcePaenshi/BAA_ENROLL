<?php
ob_clean();
ob_start();
header('Content-Type: application/json');
session_start();
require_once 'db.php';

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Check request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$enrollmentId = $_POST['enrollment_id'] ?? '';

if (!$enrollmentId || !is_numeric($enrollmentId)) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Invalid enrollment ID']);
    exit();
}

try {
    // Get enrollment info to find the directory
    $stmt = $pdo->prepare("
        SELECT id, full_name 
        FROM enrollments 
        WHERE id = ?
    ");
    $stmt->execute([$enrollmentId]);
    $enrollment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$enrollment) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Enrollment not found']);
        exit();
    }
    
    // Delete documents from database
    $deleteDocsStmt = $pdo->prepare("
        DELETE FROM enrollment_documents 
        WHERE enrollment_id = ?
    ");
    $deleteDocsStmt->execute([$enrollmentId]);
    
    // Delete enrollment from database
    $deleteEnrollStmt = $pdo->prepare("
        DELETE FROM enrollments 
        WHERE id = ?
    ");
    $deleteEnrollStmt->execute([$enrollmentId]);
    
    // Delete the enrollment directory and files
    $enrollmentDir = __DIR__ . '/../enrollments';
    $studentName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $enrollment['full_name']);
    
    // Search for directories matching the student name
    if (is_dir($enrollmentDir)) {
        $dirs = scandir($enrollmentDir);
        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..') continue;
            
            $fullDirPath = $enrollmentDir . '/' . $dir;
            if (!is_dir($fullDirPath)) continue;
            
            // Check if directory name starts with student name
            if (strpos($dir, $studentName) === 0) {
                // Delete all files in the directory
                $files = scandir($fullDirPath);
                foreach ($files as $file) {
                    if ($file === '.' || $file === '..') continue;
                    $filePath = $fullDirPath . '/' . $file;
                    if (is_file($filePath)) {
                        @unlink($filePath);
                    }
                }
                // Delete the directory
                @rmdir($fullDirPath);
                break;
            }
        }
    }
    
    ob_end_clean();
    echo json_encode(['success' => true, 'message' => 'Enrollment deleted successfully']);
    exit();
    
} catch(Exception $e) {
    error_log("Error deleting enrollment: " . $e->getMessage());
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Error deleting enrollment']);
    exit();
}
?>
