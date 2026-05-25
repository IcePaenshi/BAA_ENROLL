<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/file_security.php';
header('Content-Type: application/json');

function requirements_for_grade(string $gradeLevel): array
{
    $common = [
        'PSA Birth Certificate',
        'Report Card (SF9)',
        'Certificate of Good Moral Character',
        '2x2 ID Picture',
    ];
    $grade7 = [
        'Certificate of Completion (Elementary)',
    ];
    $grade11 = [
        'Junior High School Certificate',
    ];

    $items = $common;
    if (trim($gradeLevel) === 'Grade 7') {
        $items = array_merge($items, $grade7);
    }
    if (trim($gradeLevel) === 'Grade 11') {
        $items = array_merge($items, $grade11);
    }
    return array_values(array_unique($items));
}

function get_enrollment_id_for_student(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare("SELECT student_id, email FROM users WHERE id = ? AND role = 'student' LIMIT 1");
    $stmt->execute([$userId]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u) {
        return 0;
    }
    $studentKey = (string) ($u['student_id'] ?? '');
    if (preg_match('/^ENR-(\d+)$/', $studentKey, $m)) {
        return (int) $m[1];
    }
    $find = $pdo->prepare("SELECT id FROM enrollments WHERE email = ? ORDER BY id DESC LIMIT 1");
    $find->execute([(string) ($u['email'] ?? '')]);
    return (int) ($find->fetchColumn() ?: 0);
}

function ensure_verification_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS enrollment_document_checks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            enrollment_id INT NOT NULL,
            requirement_name VARCHAR(255) NOT NULL,
            is_verified TINYINT(1) NOT NULL DEFAULT 0,
            verified_by INT NULL,
            verified_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_enrollment_requirement (enrollment_id, requirement_name)
        )
    ");
}

$action = $_REQUEST['action'] ?? '';
$role = $_SESSION['role'] ?? '';
$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($userId < 1) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    ensure_verification_table($pdo);

    if ($action === 'student_requirements') {
        if ($role !== 'student') {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        $enrollmentId = get_enrollment_id_for_student($pdo, $userId);
        if ($enrollmentId < 1) {
            echo json_encode(['success' => true, 'requirements' => [], 'documents' => [], 'verified' => []]);
            exit;
        }

        $eStmt = $pdo->prepare("SELECT grade_level FROM enrollments WHERE id = ? LIMIT 1");
        $eStmt->execute([$enrollmentId]);
        $grade = (string) ($eStmt->fetchColumn() ?: '');
        $requirements = requirements_for_grade($grade);

        $dStmt = $pdo->prepare("SELECT id, document_filename, document_path, created_at FROM enrollment_documents WHERE enrollment_id = ? ORDER BY created_at DESC");
        $dStmt->execute([$enrollmentId]);
        $documents = $dStmt->fetchAll(PDO::FETCH_ASSOC);

        $vStmt = $pdo->prepare("SELECT requirement_name, is_verified FROM enrollment_document_checks WHERE enrollment_id = ?");
        $vStmt->execute([$enrollmentId]);
        $verifiedRaw = $vStmt->fetchAll(PDO::FETCH_ASSOC);
        $verified = [];
        foreach ($verifiedRaw as $row) {
            $verified[$row['requirement_name']] = ((int) $row['is_verified']) === 1;
        }

        echo json_encode([
            'success' => true,
            'enrollment_id' => $enrollmentId,
            'requirements' => $requirements,
            'documents' => $documents,
            'verified' => $verified,
        ]);
        exit;
    }

    if ($action === 'student_upload') {
        if ($role !== 'student') {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        $enrollmentId = get_enrollment_id_for_student($pdo, $userId);
        if ($enrollmentId < 1) {
            echo json_encode(['success' => false, 'message' => 'No linked enrollment found']);
            exit;
        }
        $requirementName = trim((string) ($_POST['requirement_name'] ?? ''));
        if ($requirementName === '' || !isset($_FILES['document'])) {
            echo json_encode(['success' => false, 'message' => 'Requirement and file are required']);
            exit;
        }
        $file = $_FILES['document'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'Upload failed']);
            exit;
        }
        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf', 'jpg', 'jpeg', 'png'];
        if (!in_array($ext, $allowed, true)) {
            echo json_encode(['success' => false, 'message' => 'Only PDF/JPG/JPEG/PNG files are allowed']);
            exit;
        }
        if ((int) $file['size'] > 8 * 1024 * 1024) {
            echo json_encode(['success' => false, 'message' => 'File too large (max 8MB)']);
            exit;
        }

        // Strict validation: Reject double extensions (e.g. .php.jpg)
        if (baa_check_double_extension($file['name'])) {
            echo json_encode(['success' => false, 'message' => 'File name cannot contain multiple extensions']);
            exit;
        }

        // Perform security checks based on extension
        if ($ext === 'pdf') {
            if (!baa_validate_pdf_security($file['tmp_name'])) {
                echo json_encode(['success' => false, 'message' => 'Security threat detected in PDF file']);
                exit;
            }
        } else {
            // It's image/jpg/png/jpeg
            if (!baa_sanitize_image($file['tmp_name'], $ext)) {
                echo json_encode(['success' => false, 'message' => 'Invalid or corrupted image file']);
                exit;
            }
            // Update the file size since re-encoding changes it
            $file['size'] = filesize($file['tmp_name']);
        }

        $safeReq = preg_replace('/[^a-zA-Z0-9_-]/', '_', $requirementName);
        $destDir = __DIR__ . '/../enrollments/documents';
        if (!is_dir($destDir)) {
            mkdir($destDir, 0777, true);
        }
        $filename = uniqid('doc_', true) . '_' . $safeReq . '.' . $ext;
        $destPath = $destDir . '/' . $filename;
        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            echo json_encode(['success' => false, 'message' => 'Unable to save file']);
            exit;
        }
        $relPath = 'enrollments/documents/' . $filename;
        $ins = $pdo->prepare("INSERT INTO enrollment_documents (enrollment_id, document_filename, document_path, file_size) VALUES (?, ?, ?, ?)");
        $ins->execute([$enrollmentId, $filename, $relPath, (int) $file['size']]);

        $reset->execute([$enrollmentId, $requirementName]);

        // Add notification for Admin and Registrar
        try {
            $sNameStmt = $pdo->prepare("SELECT " . baa_full_name_sql() . " FROM users WHERE id = ?");
            $sNameStmt->execute([$userId]);
            $studentName = $sNameStmt->fetchColumn() ?: 'A student';

            $staffStmt = $pdo->prepare("SELECT id FROM users WHERE role IN ('admin', 'registrar')");
            $staffStmt->execute();
            $staffIds = $staffStmt->fetchAll(PDO::FETCH_COLUMN);

            $notifStmt = $pdo->prepare("INSERT INTO notifications (user_id, type, message, link) VALUES (?, 'document', ?, 'documents')");
            foreach ($staffIds as $staffId) {
                $notifStmt->execute([$staffId, "$studentName submitted a new document: $requirementName"]);
            }
        } catch (Exception $e) {
            error_log("Notification error: " . $e->getMessage());
        }

        echo json_encode(['success' => true, 'message' => 'Document uploaded successfully']);
        exit;
    }

    if ($action === 'admin_list' || $action === 'admin_details' || $action === 'admin_verify') {
        if (!in_array($role, ['admin', 'registrar'], true)) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
    }

    if ($action === 'admin_list') {
        // Get filters
        $grade = trim((string) ($_GET['grade'] ?? ''));
        $section = trim((string) ($_GET['section'] ?? ''));
        $search = trim((string) ($_GET['search'] ?? ''));
        $docStatus = trim((string) ($_GET['document_status'] ?? ''));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $per_page = max(5, min(100, (int) ($_GET['per_page'] ?? 10)));
        
        // Build WHERE clause
        $where_conditions = ["u.role = 'student'"];
        $where_conditions[] = "u.grade_level IN ('Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'Grade 12')";
        $params = [];
        
        if ($grade && $grade !== '') {
            $where_conditions[] = "u.grade_level = ?";
            $params[] = $grade;
        }
        
        if ($section && $section !== '') {
            $where_conditions[] = "u.section = ?";
            $params[] = $section;
        }
        
        if ($search && $search !== '') {
            $search_term = '%' . $search . '%';
            $where_conditions[] = "(" . baa_full_name_sql('u') . " LIKE ? OR u.student_id LIKE ?)";
            $params[] = $search_term;
            $params[] = $search_term;
        }

        // Document status filter using HAVING on subquery counts
        $docJoin = "LEFT JOIN enrollments enr ON (u.student_id = CONCAT('ENR-', enr.id) OR enr.email = u.email)
            LEFT JOIN enrollment_documents ed2 ON ed2.enrollment_id = enr.id
            LEFT JOIN enrollment_document_checks edc2 ON edc2.enrollment_id = enr.id";
        $docGroupHaving = '';
        if ($docStatus === 'has_documents') {
            $docGroupHaving = "HAVING COUNT(DISTINCT ed2.id) > 0";
        } elseif ($docStatus === 'no_documents') {
            $docGroupHaving = "HAVING COUNT(DISTINCT ed2.id) = 0";
        } elseif ($docStatus === 'pending') {
            $docGroupHaving = "HAVING SUM(CASE WHEN edc2.is_verified = 0 AND edc2.id IS NOT NULL THEN 1 ELSE 0 END) > 0";
        } elseif ($docStatus === 'verified') {
            $docGroupHaving = "HAVING COUNT(DISTINCT ed2.id) > 0 AND SUM(CASE WHEN edc2.is_verified = 0 AND edc2.id IS NOT NULL THEN 1 ELSE 0 END) = 0";
        }
        
        $where_clause = implode(' AND ', $where_conditions);
        
        // Count query
        if ($docGroupHaving) {
            $count_query = "SELECT COUNT(*) FROM (
                SELECT u.id FROM users u $docJoin
                WHERE $where_clause
                GROUP BY u.id
                $docGroupHaving
            ) AS sub";
        } else {
            $count_query = "SELECT COUNT(*) FROM users u WHERE $where_clause";
        }
        $count_stmt = $pdo->prepare($count_query);
        $count_stmt->execute($params);
        $total = (int) $count_stmt->fetchColumn();
        
        $offset = ($page - 1) * $per_page;
        if ($docGroupHaving) {
            $query = "
                SELECT u.id, " . baa_full_name_sql('u') . " AS full_name, u.grade_level, u.section, u.student_id
                FROM users u $docJoin
                WHERE $where_clause
                GROUP BY u.id
                $docGroupHaving
                ORDER BY
                  FIELD(u.grade_level, 'Grade 7','Grade 8','Grade 9','Grade 10','Grade 11','Grade 12') ASC,
                  u.section ASC,
                  u.last_name ASC,
                  u.first_name ASC
                LIMIT $per_page OFFSET $offset
            ";
        } else {
            $query = "
                SELECT u.id, " . baa_full_name_sql('u') . " AS full_name, u.grade_level, u.section, u.student_id
                FROM users u
                WHERE $where_clause
                ORDER BY
                  FIELD(u.grade_level, 'Grade 7','Grade 8','Grade 9','Grade 10','Grade 11','Grade 12') ASC,
                  u.section ASC,
                  u.last_name ASC,
                  u.first_name ASC
                LIMIT $per_page OFFSET $offset
            ";
        }
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'students' => $students,
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page
        ]);
        exit;
    }

    // Action to return badge counts for notification badges
    if ($action === 'badge_counts') {
        if (!in_array($role, ['admin', 'registrar'], true)) {
            echo json_encode(['success' => false, 'pending_docs' => 0]);
            exit;
        }
        // Count students with at least one unverified doc check
        $pendingDocStmt = $pdo->query("
            SELECT COUNT(DISTINCT enrollment_id) FROM enrollment_document_checks WHERE is_verified = 0
        ");
        $pendingDocs = (int)($pendingDocStmt->fetchColumn() ?: 0);
        echo json_encode(['success' => true, 'pending_docs' => $pendingDocs]);
        exit;
    }

    if ($action === 'admin_details') {
        $studentId = (int) ($_GET['student_user_id'] ?? 0);
        if ($studentId < 1) {
            echo json_encode(['success' => false, 'message' => 'Invalid student']);
            exit;
        }
        $enrollmentId = get_enrollment_id_for_student($pdo, $studentId);
        if ($enrollmentId < 1) {
            echo json_encode(['success' => true, 'requirements' => [], 'documents' => [], 'verified' => []]);
            exit;
        }
        $eStmt = $pdo->prepare("SELECT grade_level FROM enrollments WHERE id = ? LIMIT 1");
        $eStmt->execute([$enrollmentId]);
        $grade = (string) ($eStmt->fetchColumn() ?: '');
        $requirements = requirements_for_grade($grade);

        $dStmt = $pdo->prepare("SELECT id, document_filename, document_path, created_at FROM enrollment_documents WHERE enrollment_id = ? ORDER BY created_at DESC");
        $dStmt->execute([$enrollmentId]);
        $documents = $dStmt->fetchAll(PDO::FETCH_ASSOC);

        $vStmt = $pdo->prepare("SELECT requirement_name, is_verified FROM enrollment_document_checks WHERE enrollment_id = ?");
        $vStmt->execute([$enrollmentId]);
        $verifiedRaw = $vStmt->fetchAll(PDO::FETCH_ASSOC);
        $verified = [];
        foreach ($verifiedRaw as $row) {
            $verified[$row['requirement_name']] = ((int) $row['is_verified']) === 1;
        }

        echo json_encode([
            'success' => true,
            'enrollment_id' => $enrollmentId,
            'requirements' => $requirements,
            'documents' => $documents,
            'verified' => $verified,
        ]);
        exit;
    }

    if ($action === 'admin_verify') {
        $enrollmentId = (int) ($_POST['enrollment_id'] ?? 0);
        $requirementName = trim((string) ($_POST['requirement_name'] ?? ''));
        $isVerified = (int) ($_POST['is_verified'] ?? 0) === 1 ? 1 : 0;
        if ($enrollmentId < 1 || $requirementName === '') {
            echo json_encode(['success' => false, 'message' => 'Invalid verification payload']);
            exit;
        }
        $stmt = $pdo->prepare("
            INSERT INTO enrollment_document_checks (enrollment_id, requirement_name, is_verified, verified_by, verified_at)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE is_verified = VALUES(is_verified), verified_by = VALUES(verified_by), verified_at = VALUES(verified_at)
        ");
        $verifiedAt = $isVerified ? date('Y-m-d H:i:s') : null;
        $verifiedBy = $isVerified ? $userId : null;
        $stmt->execute([$enrollmentId, $requirementName, $isVerified, $verifiedBy, $verifiedAt]);

        // Notify student
        try {
            $getStudent = $pdo->prepare("SELECT u.id FROM users u JOIN enrollments e ON (u.student_id = CONCAT('ENR-', e.id) OR u.email = e.email) WHERE e.id = ? AND u.role = 'student' LIMIT 1");
            $getStudent->execute([$enrollmentId]);
            $studentUserId = $getStudent->fetchColumn();

            if ($studentUserId) {
                $statusText = $isVerified ? "verified" : "rejected";
                $notifStmt = $pdo->prepare("INSERT INTO notifications (user_id, type, message, link) VALUES (?, 'document', ?, 'profile')");
                $notifStmt->execute([$studentUserId, "Your document '$requirementName' has been $statusText."]);
            }
        } catch (Exception $e) {
            error_log("Notification error: " . $e->getMessage());
        }

        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action']);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}

