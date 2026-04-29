<?php
session_start();
require_once 'db.php';
require_once 'mail_util.php';

// Check if user is admin or registrar
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'registrar'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$enrollmentId = $_POST['enrollment_id'] ?? '';
$status = $_POST['status'] ?? '';
$reasonsRaw = $_POST['reasons'] ?? '[]';
$missingDocsRaw = $_POST['missing_documents'] ?? '[]';
$customMessage = trim((string) ($_POST['custom_message'] ?? ''));

// Validate status
$validStatuses = ['pending', 'approved', 'rejected', 'needs_docs'];
if (!in_array($status, $validStatuses)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid status']);
    exit();
}

try {
    if (!is_numeric($enrollmentId) || (int) $enrollmentId < 1) {
        echo json_encode(['success' => false, 'message' => 'Invalid enrollment ID']);
        exit();
    }

    $enrollmentId = (int) $enrollmentId;

    $getEnrollment = $pdo->prepare("SELECT id, first_name, middle_name, last_name, suffix, email, grade_level FROM enrollments WHERE id = ? LIMIT 1");
    $getEnrollment->execute([$enrollmentId]);
    $enrollment = $getEnrollment->fetch(PDO::FETCH_ASSOC);
    if (!$enrollment) {
        echo json_encode(['success' => false, 'message' => 'Enrollment not found']);
        exit();
    }

    $reasons = json_decode((string) $reasonsRaw, true);
    if (!is_array($reasons)) {
        $reasons = [];
    }
    $reasons = array_values(array_filter(array_map('strval', $reasons)));

    $missingDocs = json_decode((string) $missingDocsRaw, true);
    if (!is_array($missingDocs)) {
        $missingDocs = [];
    }
    $missingDocs = array_values(array_filter(array_map('strval', $missingDocs)));

    if ($status === 'rejected') {
        if (count($reasons) === 0) {
            echo json_encode(['success' => false, 'message' => 'At least one rejection reason is required']);
            exit();
        }
        if (in_array('lack_of_documents', $reasons, true) && count($missingDocs) === 0) {
            echo json_encode(['success' => false, 'message' => 'Select at least one missing document']);
            exit();
        }
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS enrollment_rejection_reasons (
            id INT AUTO_INCREMENT PRIMARY KEY,
            enrollment_id INT NOT NULL,
            reasons_json TEXT NOT NULL,
            missing_documents_json TEXT NULL,
            custom_message TEXT NULL,
            rejected_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_enrollment_id (enrollment_id)
        )
    ");

    $stmt = $pdo->prepare("
        UPDATE enrollments 
        SET status = ? 
        WHERE id = ?
    ");
    $stmt->execute([$status, $enrollmentId]);

    if ($status === 'rejected') {
        $insReason = $pdo->prepare("
            INSERT INTO enrollment_rejection_reasons
                (enrollment_id, reasons_json, missing_documents_json, custom_message, rejected_by)
            VALUES (?, ?, ?, ?, ?)
        ");
        $insReason->execute([
            $enrollmentId,
            json_encode($reasons),
            json_encode($missingDocs),
            $customMessage !== '' ? $customMessage : null,
            (int) $_SESSION['user_id']
        ]);

        $reasonLabels = [];
        foreach ($reasons as $reason) {
            if ($reason === 'lack_of_documents') {
                $reasonLabels[] = 'Lack of documents';
            } elseif ($reason === 'insufficient_data') {
                $reasonLabels[] = 'Insufficient data';
            }
        }
        if (empty($reasonLabels)) {
            $reasonLabels[] = 'Application requirements not met';
        }

        $firstName = trim((string) ($enrollment['first_name'] ?? ''));
        $middleName = trim((string) ($enrollment['middle_name'] ?? ''));
        $lastName = trim((string) ($enrollment['last_name'] ?? ''));
        $suffix = trim((string) ($enrollment['suffix'] ?? ''));
        $displayName = trim($firstName . ' ' . ($middleName !== '' ? $middleName . ' ' : '') . $lastName);
        if ($suffix !== '') {
            $displayName .= ', ' . $suffix;
        }
        $toEmail = trim((string) ($enrollment['email'] ?? ''));

        if ($toEmail !== '' && filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            [$mailSent, $mailErr] = baa_send_enrollment_rejection_mail(
                $toEmail,
                $displayName !== '' ? $displayName : 'Applicant',
                $reasonLabels,
                $missingDocs,
                $customMessage
            );
            if (!$mailSent) {
                error_log('update_enrollment_status rejection mail failed: ' . $mailErr);
            }
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => $status === 'rejected'
            ? 'Enrollment rejected and notification email sent (if email is valid).'
            : 'Enrollment status updated successfully'
    ]);
} catch(PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
