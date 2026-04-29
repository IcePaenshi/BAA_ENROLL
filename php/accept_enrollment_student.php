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
require_once __DIR__ . '/../fpdf/fpdf.php';
require_once __DIR__ . '/get_fee_breakdown.php';

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

function baa_generate_basic_assessment_pdf(array $student, float $downpayment, array $feeBreakdown = []): string
{
    $tmpPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'assessment_enr_' . ($student['student_id'] ?? uniqid()) . '_' . time() . '.pdf';

    $pdf = new FPDF();
    $pdf->AddPage();
    $pdf->SetFont('Arial', 'B', 16);
    $pdf->Cell(0, 10, 'Baesa Adventist Academy', 0, 1, 'C');
    $pdf->SetFont('Arial', '', 12);
    $pdf->Cell(0, 8, 'Student Assessment Form', 0, 1, 'C');
    $pdf->Ln(5);

    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(60, 8, 'Student Name:', 0, 0);
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(0, 8, (string) ($student['full_name'] ?? ''), 0, 1);

    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(60, 8, 'Student ID:', 0, 0);
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(0, 8, (string) ($student['student_id'] ?? ''), 0, 1);

    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(60, 8, 'Grade Level:', 0, 0);
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(0, 8, (string) ($student['grade_level'] ?? ''), 0, 1);

    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(60, 8, 'Section:', 0, 0);
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(0, 8, (string) ($student['section'] ?? ''), 0, 1);

    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(60, 8, 'Recorded Downpayment:', 0, 0);
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(0, 8, 'PHP ' . number_format($downpayment, 2), 0, 1);

    $tuition = (float) ($feeBreakdown['tuition'] ?? 0);
    $misc = (float) ($feeBreakdown['misc'] ?? 0);
    $aircon = (float) ($feeBreakdown['aircon'] ?? 0);
    $hsa = (float) ($feeBreakdown['hsa'] ?? 0);
    $books = (float) ($feeBreakdown['books'] ?? 0);
    $totalAssessment = $tuition + $misc + $aircon + $hsa + $books;
    $remaining = max(0, $totalAssessment - $downpayment);

    $pdf->Ln(5);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 8, 'Financial Summary', 0, 1);
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(80, 6, 'Tuition Fee', 0, 0);
    $pdf->Cell(0, 6, 'PHP ' . number_format($tuition, 2), 0, 1, 'R');
    $pdf->Cell(80, 6, 'Miscellaneous & Others', 0, 0);
    $pdf->Cell(0, 6, 'PHP ' . number_format($misc, 2), 0, 1, 'R');
    $pdf->Cell(80, 6, 'Aircon Fee', 0, 0);
    $pdf->Cell(0, 6, 'PHP ' . number_format($aircon, 2), 0, 1, 'R');
    $pdf->Cell(80, 6, 'HSA Fee', 0, 0);
    $pdf->Cell(0, 6, 'PHP ' . number_format($hsa, 2), 0, 1, 'R');
    $pdf->Cell(80, 6, 'Books', 0, 0);
    $pdf->Cell(0, 6, 'PHP ' . number_format($books, 2), 0, 1, 'R');
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(80, 8, 'TOTAL ASSESSMENT', 0, 0);
    $pdf->Cell(0, 8, 'PHP ' . number_format($totalAssessment, 2), 0, 1, 'R');
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(80, 6, 'Down Payment', 0, 0);
    $pdf->Cell(0, 6, 'PHP ' . number_format($downpayment, 2), 0, 1, 'R');
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(80, 10, 'OUTSTANDING BALANCE', 0, 0);
    $pdf->Cell(0, 10, 'PHP ' . number_format($remaining, 2), 0, 1, 'R');

    $pdf->Ln(5);
    $pdf->SetFont('Arial', '', 10);
    $pdf->MultiCell(0, 7, 'This assessment form follows the same fee breakdown structure used in Payables Management.');

    $pdf->Output('F', $tmpPath);
    return $tmpPath;
}

function baa_apply_amount_to_payables(PDO $pdo, int $studentUserId, float $amount): void
{
    if ($amount <= 0) return;
    // Apply to pending payables in a fixed priority order
    $priority = ['Tuition', 'Misc', 'Aircon', 'HSA', 'Books'];
    $in = implode(',', array_fill(0, count($priority), '?'));
    $stmt = $pdo->prepare("
        SELECT id, item_name, amount, status
        FROM payables
        WHERE student_id = ?
          AND (status = 'pending' OR status IS NULL OR status = '')
          AND item_name IN ($in)
        ORDER BY FIELD(item_name, " . implode(',', array_fill(0, count($priority), '?')) . "), id ASC
    ");
    $params = array_merge([$studentUserId], $priority, $priority);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $remaining = $amount;
    foreach ($rows as $r) {
        if ($remaining <= 0) break;
        $pid = (int) $r['id'];
        $amt = (float) $r['amount'];
        if ($amt <= 0) {
            continue;
        }
        if ($remaining >= $amt) {
            $pdo->prepare("UPDATE payables SET amount = 0, status = 'paid' WHERE id = ?")->execute([$pid]);
            $remaining -= $amt;
        } else {
            $newAmt = round($amt - $remaining, 2);
            $pdo->prepare("UPDATE payables SET amount = ?, status = 'pending' WHERE id = ?")->execute([$newAmt, $pid]);
            $remaining = 0;
        }
    }
}

function baa_safe_rollback(PDO $pdo): void
{
    try {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    } catch (Throwable $rollbackEx) {
        error_log('accept_enrollment_student rollback failed: ' . $rollbackEx->getMessage());
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
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS enrollment_downpayments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            enrollment_id INT NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            payment_date DATE NOT NULL,
            processed_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_enrollment_id (enrollment_id)
        )
    ");

    $stmt = $pdo->prepare('SELECT * FROM enrollments WHERE id = ?');
    $stmt->execute([$enrollmentId]);
    $e = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$e) {
        echo json_encode(['success' => false, 'message' => 'Enrollment not found']);
        exit();
    }

    $status = strtolower(trim((string) ($e['status'] ?? '')));
    $email = trim($e['email'] ?? '');
    $downStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) AS total FROM enrollment_downpayments WHERE enrollment_id = ?");
    $downStmt->execute([$enrollmentId]);
    $downpaymentTotal = (float) ($downStmt->fetchColumn() ?: 0);
    if ($downpaymentTotal < 2000) {
        echo json_encode([
            'success' => false,
            'message' => 'Approval blocked: minimum preliminary downpayment of PHP 2,000.00 is required before acceptance.'
        ]);
        exit();
    }

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
            $plainPassword,
            null,
            'ENR-' . $enrollmentId,
            baa_normalize_grade_level($e['grade_level'] ?? null) ?? ''
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
                baa_safe_rollback($pdo);
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

            $feeBreakdown = baa_get_fee_breakdown($pdo, baa_normalize_grade_level($e['grade_level'] ?? null) ?? '') ?? [];
            $assessmentPath = baa_generate_basic_assessment_pdf([
                'student_id' => 'ENR-' . $enrollmentId,
                'full_name' => $displayName,
                'grade_level' => baa_normalize_grade_level($e['grade_level'] ?? null) ?? '',
                'section' => $e['section'] ?? ''
            ], $downpaymentTotal, $feeBreakdown);

            [$mailOk, $mailErr] = baa_send_student_credentials_mail(
                $toEmail,
                $displayName,
                $u['username'],
                $plainPassword,
                $assessmentPath,
                'ENR-' . $enrollmentId,
                baa_normalize_grade_level($e['grade_level'] ?? null) ?? ''
            );

            // keep separate assessment mail for compatibility
            [$assessmentMailOk, $assessmentMailErr] = baa_send_assessment_form_mail($toEmail, $displayName, $assessmentPath);
            if (is_readable($assessmentPath)) {
                @unlink($assessmentPath);
            }

            $out = [
                'success'   => true,
                'message'   => 'Enrollment re-approved. Password reset to default and login email sent.',
                'user_id'   => (int) $u['id'],
                'username'  => $u['username'],
                'mail_sent' => $mailOk,
                'assessment_mail_sent' => $assessmentMailOk,
                'reopened'  => true,
            ];
            if (!$mailOk) {
                $out['mail_warning'] = $mailErr;
            }
            if (!$assessmentMailOk) {
                $out['assessment_mail_warning'] = $assessmentMailErr;
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

    $firstInit = !empty($firstName) ? strtolower($firstName[0]) : '';
    $cleanLastName = strtolower(str_replace(' ', '', $lastName));
    $username = $firstInit . '.' . $cleanLastName . '.' . $enrollmentId;

    // Ensure uniqueness just in case
    $check = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    $n = 2;
    $baseUsername = $username;
    while (true) {
        $check->execute([$username]);
        if (!$check->fetch()) {
            break;
        }
        $username = $baseUsername . $n;
        $n++;
    }
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

    // Auto-create fee payables for this student's grade
    $breakdown = baa_get_fee_breakdown($pdo, $gradeLevel);
    if (!$breakdown) {
        throw new RuntimeException('No fee breakdown configured for ' . $gradeLevel);
    }
    $feeItems = [
        'Tuition' => (float) ($breakdown['tuition'] ?? 0),
        'Misc' => (float) ($breakdown['misc'] ?? 0),
        'Aircon' => (float) ($breakdown['aircon'] ?? 0),
        'HSA' => (float) ($breakdown['hsa'] ?? 0),
        'Books' => (float) ($breakdown['books'] ?? 0),
    ];
    $insPay = $pdo->prepare("
        INSERT INTO payables (student_id, item_name, amount, due_date, status, order_number)
        VALUES (?, ?, ?, NULL, 'pending', ?)
    ");
    foreach ($feeItems as $name => $amt) {
        // Skip zero items to keep table clean
        if ($amt <= 0) continue;
        $orderNumber = 'FEE-' . $studentIdValue . '-' . $name . '-' . uniqid();
        $insPay->execute([$userId, $name, round($amt, 2), $orderNumber]);
    }

    // Apply recorded enrollee downpayment to reduce these payables
    baa_apply_amount_to_payables($pdo, $userId, $downpaymentTotal);

    $upd = $pdo->prepare("UPDATE enrollments SET status = 'approved' WHERE id = ?");
    $upd->execute([$enrollmentId]);

    $pdo->commit();

    $displayName = trim($firstName . ' ' . ($middleName !== '' ? $middleName . ' ' : '') . $lastName);
    if ($suffix !== '') {
        $displayName .= ', ' . $suffix;
    }

    $assessmentPath = baa_generate_basic_assessment_pdf([
        'student_id' => $studentIdValue,
        'full_name' => $displayName,
        'grade_level' => $gradeLevel,
        'section' => $section
    ], $downpaymentTotal, $breakdown);

    [$mailOk, $mailErr] = baa_send_student_credentials_mail($email, $displayName, $username, $plainPassword, $assessmentPath, 'ENR-' . $enrollmentId, $gradeLevel);
    [$assessmentMailOk, $assessmentMailErr] = baa_send_assessment_form_mail($email, $displayName, $assessmentPath);
    if (is_readable($assessmentPath)) {
        @unlink($assessmentPath);
    }

    $out = [
        'success'       => true,
        'message'       => 'Student account created and enrollment approved.',
        'user_id'       => $userId,
        'username'      => $username,
        'mail_sent'     => $mailOk,
        'assessment_mail_sent' => $assessmentMailOk,
    ];
    if (!$mailOk) {
        $out['mail_warning'] = $mailErr;
    }
    if (!$assessmentMailOk) {
        $out['assessment_mail_warning'] = $assessmentMailErr;
    }
    echo json_encode($out);
} catch (Throwable $ex) {
    baa_safe_rollback($pdo);
    error_log('accept_enrollment_student: ' . $ex->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $ex->getMessage()]);
}
