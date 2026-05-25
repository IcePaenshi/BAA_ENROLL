<?php
session_start();
require_once 'db.php';
require_once __DIR__ . '/mail_util.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'registrar'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$studentId = $data['student_id'] ?? 0;

if (!$studentId) {
    echo json_encode(['success' => false, 'message' => 'Invalid student ID']);
    exit;
}

$stmt = $pdo->prepare("SELECT grade_level, section, email, first_name, middle_name, last_name, suffix FROM users WHERE id = ? AND role = 'student'");
$stmt->execute([$studentId]);
$student = $stmt->fetch();

if (!$student) {
    echo json_encode(['success' => false, 'message' => 'Student not found']);
    exit;
}

$gradeLevels = [
    'Grade 7' => 'Grade 8',
    'Grade 8' => 'Grade 9',
    'Grade 9' => 'Grade 10',
    'Grade 10' => 'Grade 11',
    'Grade 11' => 'Grade 12'
];

$currentGrade = $student['grade_level'];
if (!isset($gradeLevels[$currentGrade])) {
    echo json_encode(['success' => false, 'message' => 'Cannot promote this student (already highest grade or invalid)']);
    exit;
}

$newGrade = $gradeLevels[$currentGrade];

$sectionMapping = [
    'Grade 7' => ['Love' => 'Patience', 'Joy' => 'Peace'],
    'Grade 8' => ['Patience' => 'Goodness', 'Peace' => 'Kindness'],
    'Grade 9' => ['Goodness' => 'Gentleness', 'Kindness' => 'Faithfulness'],
    'Grade 10' => ['Gentleness' => 'Self-Control', 'Faithfulness' => 'Honesty'],
    'Grade 11' => ['Self-Control' => 'Humility', 'Honesty' => 'Meekness']
];

$newSection = '';
if (!empty($student['section']) && isset($sectionMapping[$currentGrade][$student['section']])) {
    $newSection = $sectionMapping[$currentGrade][$student['section']];
}

$update = $pdo->prepare("UPDATE users SET grade_level = ?, section = ? WHERE id = ?");
if ($update->execute([$newGrade, $newSection, $studentId])) {
    $mailWarning = '';
    $toEmail = trim((string) ($student['email'] ?? ''));
    if ($toEmail !== '' && filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        $displayName = trim(implode(' ', array_filter([
            trim((string) ($student['first_name'] ?? '')),
            trim((string) ($student['middle_name'] ?? '')),
            trim((string) ($student['last_name'] ?? '')),
            trim((string) ($student['suffix'] ?? '')),
        ])));
        [$mailOk, $mailErr] = baa_send_promotion_notice_mail(
            $toEmail,
            $displayName !== '' ? $displayName : 'Student',
            (string) $currentGrade,
            (string) $newGrade
        );
        if (!$mailOk) {
            $mailWarning = $mailErr;
        }
    } else {
        $mailWarning = 'Student has no valid email, promotion email not sent.';
    }

    $payload = ['success' => true, 'message' => "Student promoted to $newGrade" . ($newSection ? " ($newSection)" : "")];
    if ($mailWarning !== '') {
        $payload['mail_warning'] = $mailWarning;
    }
    echo json_encode($payload);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}