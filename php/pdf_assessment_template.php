<?php
require_once __DIR__ . '/../fpdf/fpdf.php';

/**
 * Enhanced Assessment PDF Template class.
 * Defined outside the function for stability.
 */
class AssessmentPDFTemplate extends FPDF {
    protected $primaryBlue = [30, 58, 138];
    protected $secondaryGray = [100, 116, 139];
    public $encoderName = 'System';

    function Header() {
        if ($this->PageNo() > 1) return;

        // Display logo
        $logoPath = __DIR__ . '/../images/logo.png';
        if (file_exists($logoPath)) {
            $this->Image($logoPath, 15, 5, 35);
        } else {
            // Fallback if logo not found
            $this->SetXY(15, 5);
            $this->SetDrawColor(200, 200, 200);
            $this->Rect(15, 5, 35, 35);
            $this->SetXY(15, 5);
            $this->SetFont('Arial', 'B', 12);
            $this->Cell(35, 35, 'BAA', 0, 0, 'C');
        }

        $this->SetFont('Arial', 'B', 20);
        $this->SetTextColor(0, 0, 0);
        $this->SetY(12);
        $this->Cell(0, 10, 'BAESA ADVENTIST ACADEMY', 0, 1, 'C');

        $this->SetFont('Arial', 'I', 10);
        $this->SetTextColor($this->secondaryGray[0], $this->secondaryGray[1], $this->secondaryGray[2]);
        $this->SetY(24);
        $this->Cell(0, 5, 'The School That Trains for Service', 0, 1, 'C');

        $this->SetFont('Arial', '', 8);
        $this->SetY(31);
        $this->Cell(0, 4, 'Baesa Road, Baesa, Quezon City, Metro Manila 1106', 0, 1, 'C');
        $this->SetY(36);
        $this->Cell(0, 4, 'Tel: (02) 123-4567 | Email: info@baesaadventist.edu.ph', 0, 1, 'C');

        $this->SetDrawColor($this->primaryBlue[0], $this->primaryBlue[1], $this->primaryBlue[2]);
        $this->SetLineWidth(0.6);
        $this->Line(15, 43, 195, 43);
        $this->SetY(48);
    }

    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 10, 'Page ' . $this->PageNo() . ' | Encoded by ' . $this->encoderName . ' on ' . date('Y-m-d'), 0, 0, 'C');
    }

    function SectionTitle($title) {
        $this->SetFont('Arial', 'B', 12);
        $this->SetTextColor(0, 0, 0);
        $this->Cell(0, 8, $title, 0, 1);
        $this->SetDrawColor($this->primaryBlue[0], $this->primaryBlue[1], $this->primaryBlue[2]);
        $this->SetLineWidth(0.5);
        $this->Line(15, $this->GetY(), 195, $this->GetY());
        $this->Ln(4);
    }

    function TwoColumnRow($leftLabel, $leftValue, $rightLabel = '', $rightValue = '') {
        $this->SetFont('Arial', 'B', 10);
        $this->SetTextColor($this->primaryBlue[0], $this->primaryBlue[1], $this->primaryBlue[2]);
        $this->Cell(45, 6, $leftLabel . ':', 0, 0);
        $this->SetFont('Arial', '', 10);
        $this->SetTextColor(0, 0, 0);
        $this->Cell(55, 6, $leftValue, 0, 0);

        if ($rightLabel !== '') {
            $this->SetFont('Arial', 'B', 10);
            $this->SetTextColor($this->primaryBlue[0], $this->primaryBlue[1], $this->primaryBlue[2]);
            $this->Cell(25, 6, $rightLabel . ':', 0, 0);
            $this->SetFont('Arial', '', 10);
            $this->SetTextColor(0, 0, 0);
            $this->Cell(0, 6, $rightValue, 0, 1);
        } else {
            $this->Cell(0, 6, '', 0, 1);
        }
    }
}

/**
 * Shared Student Assessment PDF generator.
 */
function baa_build_assessment_pdf(PDO $pdo, $studentOrId, array $financial): AssessmentPDFTemplate
{
    if (is_int($studentOrId) || (is_string($studentOrId) && ctype_digit($studentOrId))) {
        $studentUserId = (int) $studentOrId;
        $stmt = $pdo->prepare("SELECT first_name, middle_name, last_name, suffix, grade_level, section, lrn FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$studentUserId]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$student) throw new RuntimeException('Student not found');
    } elseif (is_array($studentOrId)) {
        $student = $studentOrId;
    } else {
        throw new InvalidArgumentException('Invalid student identifier');
    }

    $tuition = (float)($financial['tuition'] ?? 0);
    $misc = (float)($financial['misc'] ?? 0);
    $aircon = (float)($financial['aircon'] ?? 0);
    $hsa = (float)($financial['hsa'] ?? 0);
    $books = (float)($financial['books'] ?? 0);
    $discounts = (float)($financial['discounts'] ?? 0);
    $downPayment = (float)($financial['downPayment'] ?? 0);
    $monthlyPayments = (int)($financial['monthlyPayments'] ?? 4);
    $monthlyAmount = (float)($financial['monthlyPaymentAmount'] ?? 0);

    // Fetch subjects
    $subjectQuery = "SELECT subject_name, day_of_week, DATE_FORMAT(start_time, '%h:%i %p') as start_time, DATE_FORMAT(end_time, '%h:%i %p') as end_time FROM subjects WHERE grade_level = ? AND section = ? AND day_of_week IN ('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday') ORDER BY subject_name, FIELD(day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'), start_time";
    $subjStmt = $pdo->prepare($subjectQuery);
    $subjStmt->execute([$student['grade_level'] ?? '', $student['section'] ?? '']);
    $subjects = $subjStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($subjects) && !empty($student['grade_level'])) {
        $fallbackQuery = "SELECT subject_name, day_of_week, DATE_FORMAT(start_time, '%h:%i %p') as start_time, DATE_FORMAT(end_time, '%h:%i %p') as end_time FROM subjects WHERE grade_level = ? AND day_of_week IN ('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday') ORDER BY subject_name, FIELD(day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'), start_time";
        $fallbackStmt = $pdo->prepare($fallbackQuery);
        $fallbackStmt->execute([$student['grade_level']]);
        $subjects = $fallbackStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $dayMap = ['Monday' => 0, 'Tuesday' => 1, 'Wednesday' => 2, 'Thursday' => 3, 'Friday' => 4];
    $scheduleByDay = array_fill(0, 5, []);
    $allSubjects = [];
    $hasSchedule = false;

    foreach ($subjects as $subj) {
        if (!in_array($subj['subject_name'], $allSubjects, true)) {
            $allSubjects[] = $subj['subject_name'];
        }
        if (!empty($subj['day_of_week']) && isset($dayMap[$subj['day_of_week']])) {
            $hasSchedule = true;
            $dayIndex = $dayMap[$subj['day_of_week']];
            $timeRange = trim($subj['start_time'] . ' - ' . $subj['end_time']);
            if (!isset($scheduleByDay[$dayIndex][$subj['subject_name']])) {
                $scheduleByDay[$dayIndex][$subj['subject_name']] = [];
            }
            $scheduleByDay[$dayIndex][$subj['subject_name']][] = $timeRange;
        }
    }
    sort($allSubjects);

    $fullName = baa_build_full_name([
        $student['first_name'] ?? '',
        $student['middle_name'] ?? '',
        $student['last_name'] ?? '',
        $student['suffix'] ?? ''
    ]);

    $totalFees = $tuition + $misc + $aircon + $hsa + $books;
    $netTotal = $totalFees - $discounts;
    $balanceAfterDP = $netTotal - $downPayment;

    $encoderName = 'System';
    if (session_status() === PHP_SESSION_NONE) @session_start();
    if (isset($_SESSION['user_id'])) {
        $encoderId = (int)$_SESSION['user_id'];
        $encoderStmt = $pdo->prepare("SELECT " . baa_full_name_sql() . " AS full_name FROM users WHERE id = ?");
        $encoderStmt->execute([$encoderId]);
        $res = $encoderStmt->fetch(PDO::FETCH_ASSOC);
        if ($res) $encoderName = $res['full_name'];
    }

    $pdf = new AssessmentPDFTemplate();
    $pdf->encoderName = $encoderName;
    $pdf->AliasNbPages();
    $pdf->AddPage();
    $pdf->SetMargins(15, 15, 15);

    $pdf->SetFont('Arial', 'B', 20);
    $pdf->Cell(0, 15, 'STUDENT ASSESSMENT FORM', 0, 1, 'C');
    $pdf->Ln(5);

    $pdf->SectionTitle('Student Information');
    $pdf->TwoColumnRow('Name', $fullName, 'LRN', (string) ($student['lrn'] ?? ''));
    $pdf->TwoColumnRow('Grade Level', (string) ($student['grade_level'] ?? ''), 'School Year', date('Y') . '-' . (date('Y') + 1));
    $pdf->TwoColumnRow('Section', (string) ($student['section'] ?? ''), '', '');
    $pdf->Ln(3);

    $pdf->SectionTitle('Weekly Class Schedule');
    if (!empty($allSubjects) && $hasSchedule) {
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetFillColor(248, 250, 252);
        $pdf->SetTextColor(30, 58, 138);
        $subjectWidth = 30; $dayWidth = 30;
        $pdf->Cell($subjectWidth, 8, 'Subject', 1, 0, 'C', true);
        foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri'] as $day) {
            $pdf->Cell($dayWidth, 8, $day, 1, 0, 'C', true);
        }
        $pdf->Ln();

        $pdf->SetFont('Arial', '', 8);
        $pdf->SetTextColor(0, 0, 0);
        foreach ($allSubjects as $subject) {
            $pdf->Cell($subjectWidth, 6, $subject, 1, 0, 'L');
            for ($i = 0; $i < 5; $i++) {
                $txt = isset($scheduleByDay[$i][$subject]) ? implode(" ", $scheduleByDay[$i][$subject]) : '';
                $pdf->Cell($dayWidth, 6, $txt, 1, 0, 'C');
            }
            $pdf->Ln();
        }
    } else {
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(0, 6, 'No schedule assigned.', 0, 1);
    }
    $pdf->Ln(5);

    $pdf->SectionTitle('Financial Summary');
    $leftX = 15; $rightX = 105; $currentY = $pdf->GetY();

    // Left Column: Fees
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetXY($leftX, $currentY);
    $pdf->Cell(60, 5, 'Tuition Fee', 0, 0);
    $pdf->Cell(30, 5, 'Php ' . number_format($tuition, 2), 0, 1, 'R');
    $pdf->SetX($leftX);
    $pdf->Cell(60, 5, 'Miscellaneous & Others', 0, 0);
    $pdf->Cell(30, 5, 'Php ' . number_format($misc, 2), 0, 1, 'R');
    $pdf->SetX($leftX);
    $pdf->Cell(60, 5, 'Aircon Fee', 0, 0);
    $pdf->Cell(30, 5, 'Php ' . number_format($aircon, 2), 0, 1, 'R');
    $pdf->SetX($leftX);
    $pdf->Cell(60, 5, 'HSA Fee', 0, 0);
    $pdf->Cell(30, 5, 'Php ' . number_format($hsa, 2), 0, 1, 'R');
    $pdf->SetX($leftX);
    $pdf->Cell(60, 5, 'Books', 0, 0);
    $pdf->Cell(30, 5, 'Php ' . number_format($books, 2), 0, 1, 'R');
    $pdf->Ln(2);
    $pdf->SetX($leftX);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(60, 5, 'TOTAL ASSESSMENT', 0, 0);
    $pdf->Cell(30, 5, 'Php ' . number_format($totalFees, 2), 0, 1, 'R');

    // Right Column: Summary & Schedule
    $pdf->SetXY($rightX, $currentY);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(60, 5, 'Less: Discounts/Scholarship', 0, 0);
    $pdf->Cell(30, 5, 'Php ' . number_format($discounts, 2), 0, 1, 'R');
    $pdf->SetX($rightX);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(60, 5, 'NET TOTAL', 0, 0);
    $pdf->Cell(30, 5, 'Php ' . number_format($netTotal, 2), 0, 1, 'R');
    $pdf->SetX($rightX);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(60, 5, 'Down Payment', 0, 0);
    $pdf->Cell(30, 5, 'Php ' . number_format($downPayment, 2), 0, 1, 'R');
    $pdf->Ln(3);

    $pdf->SetX($rightX);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(90, 6, 'Payment Schedule', 0, 1);
    $pdf->SetX($rightX);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(60, 5, 'Monthly Payments', 0, 0);
    $pdf->Cell(30, 5, $monthlyPayments, 0, 1, 'R');
    $pdf->SetX($rightX);
    $pdf->Cell(60, 5, 'Monthly Amount', 0, 0);
    $pdf->Cell(30, 5, 'Php ' . number_format($monthlyAmount, 2), 0, 1, 'R');
    $pdf->Ln(4);

    // Outstanding Balance Block (Blue Block)
    $pdf->SetXY(105, $pdf->GetY());
    $pdf->SetFillColor(30, 58, 138);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(90, 8, 'OUTSTANDING BALANCE', 1, 2, 'C', true);
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(90, 10, 'Php ' . number_format($balanceAfterDP, 2), 1, 1, 'C', true);
    $pdf->SetTextColor(0, 0, 0);

    return $pdf;
}
