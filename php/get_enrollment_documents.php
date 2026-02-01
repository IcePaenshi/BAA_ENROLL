<?php
session_start();
require_once 'db.php';

// Check if user is admin or super_admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$enrollmentId = $_GET['enrollment_id'] ?? '';

if (!$enrollmentId) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Missing enrollment ID']);
    exit();
}

try {
    // First get the enrollment info
    $enrollStmt = $pdo->prepare("
        SELECT id, full_name 
        FROM enrollments 
        WHERE id = ?
    ");
    $enrollStmt->execute([$enrollmentId]);
    $enrollment = $enrollStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$enrollment) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Enrollment not found']);
        exit();
    }
    
    $stmt = $pdo->prepare("
        SELECT 
            id,
            enrollment_id,
            document_filename,
            document_path,
            file_size,
            created_at
        FROM enrollment_documents 
        WHERE enrollment_id = ?
        ORDER BY created_at
    ");
    $stmt->execute([$enrollmentId]);
    $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Verify files exist and build correct paths
    $baseDir = __DIR__ . '/..';
    $enrollmentsDir = $baseDir . '/enrollments';
    $validDocuments = [];
    $studentName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $enrollment['full_name']);
    
    foreach ($documents as $doc) {
        $filename = basename($doc['document_path']);
        $found = false;
        
        // Try the exact stored path first
        $fullPath = $baseDir . '/' . $doc['document_path'];
        if (file_exists($fullPath)) {
            $validDocuments[] = $doc;
            $found = true;
        }
        
        // If not found, search in all subdirectories matching the student name
        if (!$found && is_dir($enrollmentsDir)) {
            $dirs = scandir($enrollmentsDir);
            foreach ($dirs as $dir) {
                if ($dir === '.' || $dir === '..' || !is_dir($enrollmentsDir . '/' . $dir)) {
                    continue;
                }
                
                // Check if directory name starts with the student name
                if (strpos($dir, $studentName) === 0) {
                    $filePath = $enrollmentsDir . '/' . $dir . '/' . $filename;
                    if (file_exists($filePath)) {
                        // Update the path for the response
                        $doc['document_path'] = 'enrollments/' . $dir . '/' . $filename;
                        $validDocuments[] = $doc;
                        $found = true;
                        break;
                    }
                }
            }
        }
    }
    
    // Debug: Log what we're returning
    error_log("Enrollment $enrollmentId: Found " . count($validDocuments) . " documents");
    foreach ($validDocuments as $doc) {
        error_log("  - " . $doc['document_filename'] . " @ " . $doc['document_path']);
    }
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'documents' => $validDocuments,
        'debug' => [
            'enrollment_id' => $enrollmentId,
            'student_name' => $enrollment['full_name'] ?? 'N/A',
            'total_documents' => count($validDocuments)
        ]
    ]);
} catch(PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
