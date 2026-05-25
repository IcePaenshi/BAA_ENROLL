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
$grade = $data['grade'] ?? '';

if (!$grade) {
    echo json_encode(['success' => false, 'message' => 'Grade level required']);
    exit;
}

$gradeLevels = [
    'Grade 7' => 'Grade 8',
    'Grade 8' => 'Grade 9',
    'Grade 9' => 'Grade 10',
    'Grade 10' => 'Grade 11',
    'Grade 11' => 'Grade 12'
];

if (!isset($gradeLevels[$grade])) {
    echo json_encode(['success' => false, 'message' => 'Invalid grade or cannot promote']);
    exit;
}

$newGrade = $gradeLevels[$grade];

// Section mapping for this grade
$sectionMapping = [
    'Grade 7' => ['Love' => 'Patience', 'Joy' => 'Peace'],
    'Grade 8' => ['Patience' => 'Goodness', 'Peace' => 'Kindness'],
    'Grade 9' => ['Goodness' => 'Gentleness', 'Kindness' => 'Faithfulness'],
    'Grade 10' => ['Gentleness' => 'Self-Control', 'Faithfulness' => 'Honesty'],
    'Grade 11' => ['Self-Control' => 'Humility', 'Honesty' => 'Meekness']
];

$mapping = $sectionMapping[$grade] ?? [];

$stmt = $pdo->prepare("SELECT id, section, email, first_name, middle_name, last_name, suffix FROM users WHERE role = 'student' AND grade_level = ?");
$stmt->execute([$grade]);
$students = $stmt->fetchAll();

$updated = 0;
$mailSent = 0;
$mailFailed = 0;
foreach ($students as $student) {
    $newSection = $mapping[$student['section']] ?? '';
    $update = $pdo->prepare("UPDATE users SET grade_level = ?, section = ? WHERE id = ?");
    if ($update->execute([$newGrade, $newSection, $student['id']])) {
        $updated++;
        $toEmail = trim((string) ($student['email'] ?? ''));
        if ($toEmail !== '' && filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            $displayName = trim(implode(' ', array_filter([
                trim((string) ($student['first_name'] ?? '')),
                trim((string) ($student['middle_name'] ?? '')),
                trim((string) ($student['last_name'] ?? '')),
                trim((string) ($student['suffix'] ?? '')),
            ])));
            [$ok, ] = baa_send_promotion_notice_mail(
                $toEmail,
                $displayName !== '' ? $displayName : 'Student',
                (string) $grade,
                (string) $newGrade
            );
            if ($ok) {
                $mailSent++;
            } else {
                $mailFailed++;
            }
        } else {
            $mailFailed++;
        }
    }
}

echo json_encode([
    'success' => true,
    'message' => "$updated students promoted to $newGrade",
    'mail_sent' => $mailSent,
    'mail_failed' => $mailFailed
]);