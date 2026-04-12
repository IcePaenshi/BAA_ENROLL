<?php
/**
 * One-step accept: create student user, approve enrollment, email credentials.
 */
session_start();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'registrar'], true)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$enrollmentId = isset($_POST['enrollment_id']) ? (int) $_POST['enrollment_id'] : 0;
if ($enrollmentId < 1) {
    echo json_encode(['success' => false, 'message' => 'Invalid enrollment id']);
    exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mail_util.php';

$gradeSections = [
    'Grade 7'  => ['Love', 'Joy'],
    'Grade 8'  => ['Patience', 'Peace'],
    'Grade 9'  => ['Goodness', 'Kindness'],
    'Grade 10' => ['Gentleness', 'Faithfulness'],
    'Grade 11' => ['Self-Control', 'Honesty'],
    'Grade 12' => ['Humility', 'Meekness'],
];

function baa_normalize_grade_level(?string $grade): ?string
{
    if ($grade === null || $grade === '') {
        return null;
    }
    $g = trim($grade);
    $map = [
        '7' => 'Grade 7', '8' => 'Grade 8', '9' => 'Grade 9',
        '10' => 'Grade 10', '11' => 'Grade 11', '12' => 'Grade 12',
        'Grade 7' => 'Grade 7', 'Grade 8' => 'Grade 8', 'Grade 9' => 'Grade 9',
        'Grade 10' => 'Grade 10', 'Grade 11' => 'Grade 11', 'Grade 12' => 'Grade 12',
    ];
    return $map[$g] ?? (preg_match('/^Grade\s+[0-9]{1,2}$/i', $g) ? $g : null);
}

function baa_normalize_phone_10(?string $phone): ?string
{
    if ($phone === null || $phone === '') {
        return null;
    }
    $digits = preg_replace('/\D/', '', $phone);
    if (strlen($digits) >= 11 && substr($digits, 0, 2) === '63') {
        $digits = substr($digits, -10);
    }
    if (strlen($digits) === 11 && $digits[0] === '0') {
        $digits = substr($digits, 1);
    }
    if (strlen($digits) === 10) {
        return $digits;
    }
    return null;
}

function baa_slug_last_name(string $lastName): string
{
    $s = strtolower(trim($lastName));
    $s = preg_replace('/[^a-z0-9]+/', '', $s);
    return $s !== '' ? $s : 'student';
}

function baa_unique_username(PDO $pdo, string $base): string
{
    $candidate = $base . '_student';
    $check = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    $n = 2;
    while (true) {
        $check->execute([$candidate]);
        if (!$check->fetch()) {
            return $candidate;
        }
        $candidate = $base . '_student' . $n;
        $n++;
    }
}

/** Student created from this enrollment: student_id ENR-{id} or same email + role student */
function baa_find_linked_student(PDO $pdo, int $enrollmentId, string $email): ?array
{
    $key = 'ENR-' . $enrollmentId;
    $q = $pdo->prepare(
        "SELECT id, username, email, first_name, middle_name, last_name, suffix, role
         FROM users WHERE student_id = ? LIMIT 1"
    );
    $q->execute([$key]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return $row;
    }
    $q2 = $pdo->prepare(
        "SELECT id, username, email, first_name, middle_name, last_name, suffix, role
         FROM users WHERE email = ? AND role = 'student' LIMIT 1"
    );
    $q2->execute([$email]);
    $row = $q2->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

try {
    $stmt = $pdo->prepare('SELECT * FROM enrollments WHERE id = ?');
    $stmt->execute([$enrollmentId]);
    $e = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$e) {
        echo json_encode(['success' => false, 'message' => 'Enrollment not found']);
        exit();
    }

    $status = strtolower(trim((string) ($e['status'] ?? '')));
    $email = trim($e['email'] ?? '');

    // Already approved (e.g. first run created the account but email failed) — resend credentials
    if ($status === 'approved') {
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Enrollment has no valid email']);
            exit();
        }
        $studentIdKey = 'ENR-' . $enrollmentId;
        $u = baa_find_linked_student($pdo, $enrollmentId, $email);
        if (!$u) {
            echo json_encode([
                'success' => false,
                'message' => 'This enrollment is approved but no matching student account was found (expected student_id ' . $studentIdKey . ' or same email).',
            ]);
            exit();
        }
        if (($u['role'] ?? '') !== 'student') {
            echo json_encode(['success' => false, 'message' => 'Linked account is not a student.']);
            exit();
        }

        $plainPassword = 'baa123';
        $hashedPassword = password_hash($plainPassword, PASSWORD_DEFAULT);
        $updPw = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
        $updPw->execute([$hashedPassword, $u['id']]);

        $fn = trim($u['first_name'] ?? '');
        $mn = trim($u['middle_name'] ?? '');
        $ln = trim($u['last_name'] ?? '');
        $sx = trim($u['suffix'] ?? '');
        $displayName = trim($fn . ' ' . ($mn !== '' ? $mn . ' ' : '') . $ln);
        if ($sx !== '') {
            $displayName .= ', ' . $sx;
        }
        $toEmail = trim($u['email'] ?? '');
        if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Student account has no valid email.']);
            exit();
        }

        [$mailOk, $mailErr] = baa_send_student_credentials_mail(
            $toEmail,
            $displayName,
            $u['username'],
            $plainPassword
        );

        $out = [
            'success'   => true,
            'message'   => 'Student was already approved. Password reset to default and login email sent again.',
            'user_id'   => (int) $u['id'],
            'username'  => $u['username'],
            'mail_sent' => $mailOk,
            'resent'    => true,
        ];
        if (!$mailOk) {
            $out['mail_warning'] = $mailErr;
        }
        echo json_encode($out);
        exit();
    }

    if (!in_array($status, ['pending', 'needs_docs', 'rejected'], true)) {
        echo json_encode(['success' => false, 'message' => 'This enrollment is not awaiting approval']);
        exit();
    }

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Enrollment has no valid email']);
        exit();
    }

    // Rejected after a prior accept: student row may still exist — re-approve enrollment and resend mail
    if ($status === 'rejected') {
        $u = baa_find_linked_student($pdo, $enrollmentId, $email);
        if ($u) {
            if (($u['role'] ?? '') !== 'student') {
                echo json_encode(['success' => false, 'message' => 'Linked account is not a student.']);
                exit();
            }
            $plainPassword = 'baa123';
            $hashedPassword = password_hash($plainPassword, PASSWORD_DEFAULT);
            $pdo->beginTransaction();
            try {
                $pdo->prepare("UPDATE enrollments SET status = 'approved' WHERE id = ?")->execute([$enrollmentId]);
                $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([$hashedPassword, $u['id']]);
                $pdo->commit();
            } catch (Throwable $tx) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $tx;
            }

            $fn = trim($u['first_name'] ?? '');
            $mn = trim($u['middle_name'] ?? '');
            $ln = trim($u['last_name'] ?? '');
            $sx = trim($u['suffix'] ?? '');
            $displayName = trim($fn . ' ' . ($mn !== '' ? $mn . ' ' : '') . $ln);
            if ($sx !== '') {
                $displayName .= ', ' . $sx;
            }
            $toEmail = trim($u['email'] ?? '');
            if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
                echo json_encode(['success' => false, 'message' => 'Student account has no valid email.']);
                exit();
            }

            [$mailOk, $mailErr] = baa_send_student_credentials_mail(
                $toEmail,
                $displayName,
                $u['username'],
                $plainPassword
            );

            $out = [
                'success'   => true,
                'message'   => 'Enrollment re-approved. Password reset to default and login email sent.',
                'user_id'   => (int) $u['id'],
                'username'  => $u['username'],
                'mail_sent' => $mailOk,
                'reopened'  => true,
            ];
            if (!$mailOk) {
                $out['mail_warning'] = $mailErr;
            }
            echo json_encode($out);
            exit();
        }
    }

    $dup = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $dup->execute([$email]);
    if ($dup->fetch()) {
        echo json_encode(['success' => false, 'message' => 'A user account already exists for this email']);
        exit();
    }

    $firstName = trim($e['first_name'] ?? '');
    $middleName = trim($e['middle_name'] ?? '');
    $lastName = trim($e['last_name'] ?? '');
    $suffix = trim($e['suffix'] ?? '');

    if ($firstName === '' || $lastName === '') {
        echo json_encode(['success' => false, 'message' => 'Enrollment is missing first or last name']);
        exit();
    }

    $gradeLevel = baa_normalize_grade_level($e['grade_level'] ?? null);
    if ($gradeLevel === null) {
        echo json_encode(['success' => false, 'message' => 'Enrollment is missing a valid grade level']);
        exit();
    }

    $sectionIn = isset($e['section']) ? trim((string) $e['section']) : '';
    $sections = $gradeSections[$gradeLevel] ?? [];
    if (empty($sections)) {
        echo json_encode(['success' => false, 'message' => 'Invalid grade configuration']);
        exit();
    }
    $section = in_array($sectionIn, $sections, true) ? $sectionIn : $sections[0];

    $strand = null;
    if (in_array($gradeLevel, ['Grade 11', 'Grade 12'], true)) {
        $st = trim($e['strand'] ?? '');
        if ($st === '' || !in_array($st, ['STEM', 'ABM', 'HUMSS'], true)) {
            echo json_encode(['success' => false, 'message' => 'Senior high enrollment must have strand STEM, ABM, or HUMSS']);
            exit();
        }
        $strand = $st;
    }

    $age = $e['age'] ?? null;
    if ($age === null || $age === '' || !is_numeric($age) || (int) $age < 1 || (int) $age > 120) {
        echo json_encode(['success' => false, 'message' => 'Enrollment has invalid age']);
        exit();
    }

    $gender = $e['gender'] ?? '';
    if (!in_array($gender, ['Male', 'Female'], true)) {
        echo json_encode(['success' => false, 'message' => 'Enrollment has invalid gender']);
        exit();
    }

    $birthdate = $e['birthdate'] ?? '';
    if ($birthdate === '') {
        echo json_encode(['success' => false, 'message' => 'Enrollment is missing birthdate']);
        exit();
    }
    $dt = DateTime::createFromFormat('Y-m-d', $birthdate);
    if (!$dt || $dt->format('Y-m-d') !== $birthdate) {
        echo json_encode(['success' => false, 'message' => 'Enrollment has invalid birthdate']);
        exit();
    }

    $phone10 = baa_normalize_phone_10($e['phone'] ?? '');
    if ($phone10 === null) {
        echo json_encode(['success' => false, 'message' => 'Enrollment phone must yield 10 digits (e.g. +639XXXXXXXXX)']);
        exit();
    }

    // users.lrn may be NOT NULL — store '' when no LRN
    $lrn = isset($e['lrn']) ? trim((string) $e['lrn']) : '';

    $slug = baa_slug_last_name($lastName);
    $username = baa_unique_username($pdo, $slug);
    $plainPassword = 'baa123';
    $hashedPassword = password_hash($plainPassword, PASSWORD_DEFAULT);
    $studentIdValue = 'ENR-' . $enrollmentId;

    $pdo->beginTransaction();

    $phoneStore = '+63' . $phone10;

    $ins = $pdo->prepare('
        INSERT INTO users
        (username, email, password, first_name, middle_name, last_name, suffix, role, grade_level, section, lrn, student_id, age, gender, birthdate, phone, strand)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $ins->execute([
        $username,
        $email,
        $hashedPassword,
        $firstName,
        $middleName,
        $lastName,
        $suffix,
        'student',
        $gradeLevel,
        $section,
        $lrn,
        $studentIdValue,
        (int) $age,
        $gender,
        $birthdate,
        $phoneStore,
        $strand,
    ]);
    $userId = (int) $pdo->lastInsertId();

    $upd = $pdo->prepare("UPDATE enrollments SET status = 'approved' WHERE id = ?");
    $upd->execute([$enrollmentId]);

    $pdo->commit();

    $displayName = trim($firstName . ' ' . ($middleName !== '' ? $middleName . ' ' : '') . $lastName);
    if ($suffix !== '') {
        $displayName .= ', ' . $suffix;
    }

    [$mailOk, $mailErr] = baa_send_student_credentials_mail($email, $displayName, $username, $plainPassword);

    $out = [
        'success'       => true,
        'message'       => 'Student account created and enrollment approved.',
        'user_id'       => $userId,
        'username'      => $username,
        'mail_sent'     => $mailOk,
    ];
    if (!$mailOk) {
        $out['mail_warning'] = $mailErr;
    }
    echo json_encode($out);
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('accept_enrollment_student: ' . $ex->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $ex->getMessage()]);
}
