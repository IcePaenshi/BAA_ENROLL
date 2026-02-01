<?php
session_start();
require_once 'db.php';

// Check if user is admin or super_admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header('HTTP/1.0 403 Forbidden');
    echo 'Access Denied';
    exit();
}

$docId = $_GET['doc_id'] ?? '';
$action = $_GET['action'] ?? 'view';

if (!$docId) {
    header('HTTP/1.0 400 Bad Request');
    echo 'Missing document ID';
    exit();
}

try {
    $stmt = $pdo->prepare("
        SELECT document_filename, document_path 
        FROM enrollment_documents 
        WHERE id = ?
    ");
    $stmt->execute([$docId]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$doc) {
        header('HTTP/1.0 404 Not Found');
        echo 'Document not found';
        exit();
    }
    
    $filePath = $doc['document_path'];
    
    if (!file_exists($filePath)) {
        header('HTTP/1.0 404 Not Found');
        echo 'File not found on server';
        exit();
    }
    
    $fileSize = filesize($filePath);
    $mimeType = mime_content_type($filePath);
    
    if ($action === 'download') {
        header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
    } else {
        header('Content-Disposition: inline; filename="' . basename($filePath) . '"');
    }
    
    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . $fileSize);
    header('Cache-Control: no-cache, no-store, must-revalidate');
    
    readfile($filePath);
    exit();
    
} catch(PDOException $e) {
    header('HTTP/1.0 500 Internal Server Error');
    echo 'Database error';
    exit();
}
?>
