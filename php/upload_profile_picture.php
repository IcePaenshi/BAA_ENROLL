<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/file_security.php';

header('Content-Type: application/json');

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId < 1) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

if (!isset($_FILES['profile_pic'])) {
    echo json_encode(['success' => false, 'message' => 'No file uploaded']);
    exit;
}

$file = $_FILES['profile_pic'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'Upload error (code: ' . $file['error'] . ')']);
    exit;
}

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$allowed = ['jpg', 'jpeg', 'png'];

if (!in_array($ext, $allowed, true)) {
    echo json_encode(['success' => false, 'message' => 'Only JPG, JPEG, and PNG images are allowed']);
    exit;
}

// 1. Double extension check
if (baa_check_double_extension($file['name'])) {
    echo json_encode(['success' => false, 'message' => 'Filename cannot contain multiple extensions']);
    exit;
}

// 2. Scan content/size (max 5MB)
if ($file['size'] > 5 * 1024 * 1024) {
    echo json_encode(['success' => false, 'message' => 'File size cannot exceed 5MB']);
    exit;
}

// 3. Image re-encoding via GD to sanitize metadata & payloads
if (!baa_sanitize_image($file['tmp_name'], $ext)) {
    echo json_encode(['success' => false, 'message' => 'Invalid or corrupted image file']);
    exit;
}

// Create destination directory
$destDir = __DIR__ . '/../uploads/profile_pictures';
if (!is_dir($destDir)) {
    mkdir($destDir, 0777, true);
}

$filename = 'profile_' . $userId . '_' . uniqid() . '.' . $ext;
$destPath = $destDir . '/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    echo json_encode(['success' => false, 'message' => 'Failed to save profile picture']);
    exit;
}

// Relative path to store in DB
$relPath = 'uploads/profile_pictures/' . $filename;

try {
    // Save to users table and set status to pending
    $stmt = $pdo->prepare("UPDATE users SET profile_picture = ?, profile_picture_status = 'pending' WHERE id = ?");
    $stmt->execute([$relPath, $userId]);
    
    // Add notification for Admin and Registrar
    try {
        $uNameStmt = $pdo->prepare("SELECT " . baa_full_name_sql() . " FROM users WHERE id = ?");
        $uNameStmt->execute([$userId]);
        $userName = $uNameStmt->fetchColumn() ?: 'A user';

        $staffStmt = $pdo->prepare("SELECT id FROM users WHERE role IN ('admin', 'registrar')");
        $staffStmt->execute();
        $staffIds = $staffStmt->fetchAll(PDO::FETCH_COLUMN);

        $notifStmt = $pdo->prepare("INSERT INTO notifications (user_id, type, message, link) VALUES (?, 'photo', ?, 'photo-approvals')");
        foreach ($staffIds as $staffId) {
            $notifStmt->execute([$staffId, "$userName uploaded a new profile picture for approval."]);
        }
    } catch (Exception $e) {
        error_log("Notification error: " . $e->getMessage());
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Profile picture uploaded successfully! It is now pending admin/registrar approval.',
        'path' => $relPath
    ]);
} catch (PDOException $e) {
    @unlink($destPath);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
