<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    http_response_code(403);
    exit('Unauthorized');
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../fpdf/fpdf.php';

$teacherId = (int) $_SESSION['user_id'];
$studentId = isset($_GET['student_id']) ? (int) $_GET['student_id'] : 0;

if ($studentId <= 0) {
    exit('Invalid student ID.');
}

// Fetch teacher's name for the footer
$teacherStmt = $pdo->prepare("SELECT " . baa_full_name_sql() . " AS full_name FROM users WHERE id = ?");
$teacherStmt->execute([$teacherId]);
$teacherName = $teacherStmt->fetchColumn() ?: 'Teacher';

// Fetch student details & verify teacher has access to this student
$studentStmt = $pdo->prepare(
    "SELECT DISTINCT u.id, " . baa_full_name_sql('u') . " AS full_name,
            u.grade_level, u.section
        FROM users u
        JOIN subjects s ON u.grade_level = s.grade_level AND u.section = s.section
        JOIN teacher_subjects ts ON s.id = ts.subject_id
        WHERE ts.teacher_id = ?
          AND u.role = 'student'
          AND u.id = ?"
);
$studentStmt->execute([$teacherId, $studentId]);
$student = $studentStmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    http_response_code(403);
    exit('Unauthorized or student not found under your sections.');
}

// Handle date range
$rangeType = $_GET['range_type'] ?? 'month';
$startDateParam = $_GET['start_date'] ?? '';
$endDateParam = $_GET['end_date'] ?? '';

if ($rangeType === 'school_year') {
    // School year: 5 months range ending in current month
    $startDate = new DateTime('today');
    $startDate->modify('first day of this month');
    $startDate->modify('-4 months');
    $endDate = new DateTime('today');
    $endDate->modify('last day of this month');
} elseif ($rangeType === 'custom' && !empty($startDateParam) && !empty($endDateParam)) {
    $startDate = DateTime::createFromFormat('Y-m-d', $startDateParam);
    $endDate = DateTime::createFromFormat('Y-m-d', $endDateParam);
    if (!$startDate || !$endDate) {
        $startDate = new DateTime('first day of this month');
        $endDate = new DateTime('last day of this month');
    }
} else {
    // Default to 'month'
    $startDate = new DateTime('first day of this month');
    $endDate = new DateTime('last day of this month');
}

// Fetch student's attendance records
$attendanceStmt = $pdo->prepare(
    "SELECT date, status FROM attendance
     WHERE student_id = ? AND date BETWEEN ? AND ?
     ORDER BY date ASC"
);
$attendanceStmt->execute([$studentId, $startDate->format('Y-m-d'), $endDate->format('Y-m-d')]);
$records = $attendanceStmt->fetchAll(PDO::FETCH_ASSOC);

$statusMap = [];
foreach ($records as $r) {
    $statusMap[$r['date']] = strtoupper(substr((string)$r['status'], 0, 1));
}

// Generate weekly groups
$startCursor = clone $startDate;
$startCursor->modify('monday this week');
$endCursor = clone $endDate;
$endCursor->modify('sunday this week');

$weeks = [];
while ($startCursor <= $endCursor) {
    $weekEnd = clone $startCursor;
    $weekEnd->modify('+6 days');
    
    $days = [];
    $dayCursor = clone $startCursor;
    for ($i = 0; $i < 7; $i++) {
        $days[] = $dayCursor->format('Y-m-d');
        $dayCursor->modify('+1 day');
    }
    
    $weeks[] = [
        'label' => $startCursor->format('M d') . ' - ' . $weekEnd->format('M d, Y'),
        'days' => $days
    ];
    
    $startCursor->modify('+7 days');
}

class LedgerPDF extends FPDF
{
    private $primaryBlue = [30, 58, 138];
    private $secondaryGray = [100, 116, 139];
    public $teacherName;

    function Header()
    {
        $logoPath = __DIR__ . '/../images/logo.png';
        if (file_exists($logoPath) && is_readable($logoPath)) {
            $this->Image($logoPath, 15, 8, 35);
        } else {
            $this->SetFont('Arial', 'B', 14);
            $this->SetTextColor(0,0,0);
            $this->SetXY(15,12);
            $this->Cell(35,35,'BAA',1,0,'C');
        }

        $this->SetFont('Arial','B',20);
        $this->SetTextColor(0, 0, 0);
        $this->SetY(12);
        $this->Cell(0,10,'BAESA ADVENTIST ACADEMY',0,1,'C');

        $this->SetFont('Arial','I',10);
        $this->SetTextColor($this->secondaryGray[0], $this->secondaryGray[1], $this->secondaryGray[2]);
        $this->SetY(24);
        $this->Cell(0,5,'The School That Trains for Service',0,1,'C');

        $this->SetFont('Arial','',8);
        $this->SetY(31);
        $this->Cell(0,4,'Baesa Road, Baesa, Quezon City, Metro Manila 1106',0,1,'C');
        $this->SetY(36);
        $this->Cell(0,4,'Tel: (02) 123-4567 | Email: info@baesaadventist.edu.ph',0,1,'C');

        $this->SetDrawColor($this->primaryBlue[0], $this->primaryBlue[1], $this->primaryBlue[2]);
        $this->SetLineWidth(0.6);
        $this->Line(15, 43, $this->GetPageWidth() - 15, 43);
        $this->SetY(48);
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Arial', 'B', 14);
        $this->Cell(0, 7, 'STUDENT ATTENDANCE LEDGER', 0, 1, 'C');
        $this->Ln(1);
    }

    function Footer()
    {
        $this->SetY(-15);
        $this->SetFont('Arial','I',8);
        $this->SetTextColor(100,100,100);
        $this->Cell(0,10,'Page '.$this->PageNo().' | Encoded by '.$this->teacherName.' on '.date('Y-m-d'),0,0,'C');
    }
}

$pdf = new LedgerPDF('P', 'mm', 'A4');
$pdf->teacherName = $teacherName;
$pdf->SetMargins(15, 15, 15);
$pdf->AddPage();

// Student info section
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(28, 6, 'Student Name:', 0, 0);
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(80, 6, $student['full_name'], 0, 0);

$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(28, 6, 'Grade/Section:', 0, 0);
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(0, 6, $student['grade_level'] . ' - ' . $student['section'], 0, 1);

$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(28, 6, 'Ledger Period:', 0, 0);
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(80, 6, $startDate->format('F d, Y') . ' to ' . $endDate->format('F d, Y'), 0, 1);
$pdf->Ln(4);

// Legend
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(20, 6, 'Legend:', 0, 0);
$pdf->SetFillColor(34, 197, 94); // green
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(10, 6, 'P', 1, 0, 'C', true);
$pdf->SetTextColor(0, 0, 0);
$pdf->Cell(18, 6, 'Present', 0, 0);

$pdf->SetFillColor(250, 204, 21); // yellow
$pdf->SetTextColor(0, 0, 0);
$pdf->Cell(10, 6, 'L', 1, 0, 'C', true);
$pdf->Cell(14, 6, 'Late', 0, 0);

$pdf->SetFillColor(239, 68, 68); // red
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(10, 6, 'A', 1, 0, 'C', true);
$pdf->SetTextColor(0, 0, 0);
$pdf->Cell(16, 6, 'Absent', 0, 1);
$pdf->Ln(4);

// Table Header
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetFillColor(30, 58, 138); // Primary Blue
$pdf->SetTextColor(255, 255, 255);

$weekWidth = 60;
$dayWidth = 17;

$pdf->Cell($weekWidth, 8, 'Week Range', 1, 0, 'C', true);
$pdf->Cell($dayWidth, 8, 'Mon', 1, 0, 'C', true);
$pdf->Cell($dayWidth, 8, 'Tue', 1, 0, 'C', true);
$pdf->Cell($dayWidth, 8, 'Wed', 1, 0, 'C', true);
$pdf->Cell($dayWidth, 8, 'Thu', 1, 0, 'C', true);
$pdf->Cell($dayWidth, 8, 'Fri', 1, 0, 'C', true);
$pdf->Cell($dayWidth, 8, 'Sat', 1, 0, 'C', true);
$pdf->Cell($dayWidth, 8, 'Sun', 1, 1, 'C', true);

$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('Arial', '', 9);

foreach ($weeks as $week) {
    // Add page if near bottom
    if ($pdf->GetY() > 250) {
        $pdf->AddPage();
        // Reprint header
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetFillColor(30, 58, 138);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->Cell($weekWidth, 8, 'Week Range', 1, 0, 'C', true);
        $pdf->Cell($dayWidth, 8, 'Mon', 1, 0, 'C', true);
        $pdf->Cell($dayWidth, 8, 'Tue', 1, 0, 'C', true);
        $pdf->Cell($dayWidth, 8, 'Wed', 1, 0, 'C', true);
        $pdf->Cell($dayWidth, 8, 'Thu', 1, 0, 'C', true);
        $pdf->Cell($dayWidth, 8, 'Fri', 1, 0, 'C', true);
        $pdf->Cell($dayWidth, 8, 'Sat', 1, 0, 'C', true);
        $pdf->Cell($dayWidth, 8, 'Sun', 1, 1, 'C', true);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Arial', '', 9);
    }

    $pdf->Cell($weekWidth, 8, $week['label'], 1, 0, 'C');
    
    foreach ($week['days'] as $day) {
        $status = $statusMap[$day] ?? '-';
        if ($status === 'P') {
            $pdf->SetFillColor(34, 197, 94);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->Cell($dayWidth, 8, 'P', 1, 0, 'C', true);
        } elseif ($status === 'L') {
            $pdf->SetFillColor(250, 204, 21);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->Cell($dayWidth, 8, 'L', 1, 0, 'C', true);
        } elseif ($status === 'A') {
            $pdf->SetFillColor(239, 68, 68);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->Cell($dayWidth, 8, 'A', 1, 0, 'C', true);
        } else {
            $pdf->SetTextColor(100, 116, 139);
            $pdf->Cell($dayWidth, 8, '-', 1, 0, 'C');
        }
        $pdf->SetTextColor(0, 0, 0);
    }
    $pdf->Ln();
}

$pdf->Output('I', 'attendance_ledger_' . $studentId . '_' . date('Ymd_His') . '.pdf');
exit;
