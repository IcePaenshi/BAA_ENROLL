<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    http_response_code(403);
    exit('Unauthorized');
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../fpdf/fpdf.php';

function date_range_from_request(string $rangeType, string $date, int $months): array
{
    $base = DateTime::createFromFormat('Y-m-d', $date ?: date('Y-m-d'));
    if (!$base) {
        $base = new DateTime('today');
    }
    $base->setTime(0, 0, 0);

    switch ($rangeType) {
        case 'week':
            $start = clone $base;
            $start->modify('monday this week');
            $end = clone $start;
            $end->modify('+6 days');
            break;
        case 'month':
            $start = clone $base;
            $start->modify('first day of this month');
            $end = clone $base;
            $end->modify('last day of this month');
            break;
        case 'custom':
            $months = max(1, $months);
            $start = clone $base;
            $start->modify('first day of this month');
            $end = clone $start;
            $end->modify('+' . ($months - 1) . ' month');
            $end->modify('last day of this month');
            break;
        case 'school_year':
            $months = max(4, min(5, $months));
            $start = clone $base;
            $start->modify('first day of this month');
            $end = clone $start;
            $end->modify('+' . ($months - 1) . ' month');
            $end->modify('last day of this month');
            break;
        case 'day':
        default:
            $start = clone $base;
            $end = clone $base;
            break;
    }
    return [$start, $end];
}

$teacherId = (int) $_SESSION['user_id'];
$rangeType = $_GET['range_type'] ?? 'day';
$dateParam = $_GET['date'] ?? date('Y-m-d');
$months = (int) ($_GET['months'] ?? 1);
$gradeFilter = trim($_GET['grade'] ?? 'all');
$sectionFilter = trim($_GET['section'] ?? 'all');
[$startDate, $endDate] = date_range_from_request($rangeType, $dateParam, $months);

$studentsStmt = $pdo->prepare(
    "SELECT DISTINCT u.id, " . baa_full_name_sql('u') . " AS full_name,
            u.grade_level, u.section
        FROM users u
        JOIN subjects s ON u.grade_level = s.grade_level AND u.section = s.section
        JOIN teacher_subjects ts ON s.id = ts.subject_id
        WHERE ts.teacher_id = ?
          AND u.role = 'student'
        ORDER BY u.grade_level, u.section, full_name"
);
$params = [$teacherId];

if ($gradeFilter !== 'all' && $gradeFilter !== '') {
    $studentsStmt = $pdo->prepare(
        "SELECT DISTINCT u.id, " . baa_full_name_sql('u') . " AS full_name,
                u.grade_level, u.section
            FROM users u
            JOIN subjects s ON u.grade_level = s.grade_level AND u.section = s.section
            JOIN teacher_subjects ts ON s.id = ts.subject_id
            WHERE ts.teacher_id = ?
              AND u.role = 'student'
              AND u.grade_level = ?
            ORDER BY u.grade_level, u.section, full_name"
    );
    $params = [$teacherId, $gradeFilter];
}

if ($sectionFilter !== 'all' && $sectionFilter !== '') {
    $sql = "SELECT DISTINCT u.id, " . baa_full_name_sql('u') . " AS full_name,
                u.grade_level, u.section
            FROM users u
            JOIN subjects s ON u.grade_level = s.grade_level AND u.section = s.section
            JOIN teacher_subjects ts ON s.id = ts.subject_id
            WHERE ts.teacher_id = ?
              AND u.role = 'student'
              AND u.section = ?";
    if ($gradeFilter !== 'all' && $gradeFilter !== '') {
        $sql .= " AND u.grade_level = ?";
        $params = [$teacherId, $sectionFilter, $gradeFilter];
    } else {
        $params = [$teacherId, $sectionFilter];
    }
    $sql .= " ORDER BY u.grade_level, u.section, full_name";
    $studentsStmt = $pdo->prepare($sql);
}

$studentsStmt->execute($params);
$students = $studentsStmt->fetchAll(PDO::FETCH_ASSOC);

$studentIds = array_column($students, 'id');
$statusMap = [];
if (!empty($studentIds)) {
    $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
    $recordsStmt = $pdo->prepare(
        "SELECT student_id, date, status
        FROM attendance
        WHERE teacher_id = ? AND date BETWEEN ? AND ? AND student_id IN ($placeholders)"
    );
    $recordsStmt->execute(array_merge([$teacherId, $startDate->format('Y-m-d'), $endDate->format('Y-m-d')], $studentIds));
    foreach ($recordsStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $statusMap[(int) $r['student_id']][$r['date']] = strtoupper(substr((string) $r['status'], 0, 1));
    }
}

$teacherStmt = $pdo->prepare("SELECT " . baa_full_name_sql() . " AS full_name FROM users WHERE id = ?");
$teacherStmt->execute([$teacherId]);
$teacherName = $teacherStmt->fetchColumn() ?: 'Teacher';

$days = [];
$cursor = clone $startDate;
while ($cursor <= $endDate) {
    $days[] = $cursor->format('Y-m-d');
    $cursor->modify('+1 day');
}

class AttendancePDF extends FPDF
{
    private $primaryBlue = [30, 58, 138];
    private $secondaryGray = [100, 116, 139];
    public $teacherName;

    function Header()
    {
        if ($this->PageNo() > 1) {
            $this->SetY(15);
            return;
        }

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
        $this->Cell(0, 7, 'ATTENDANCE REPORT', 0, 1, 'C');
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

$pdf = new AttendancePDF('P', 'mm', 'A4');
$pdf->teacherName = $teacherName;
$pdf->SetMargins(15, 15, 15);
$pdf->AddPage();
$pdf->SetFont('Arial', '', 9);

// Legend with colored boxes
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

// Format selection: if the report spans more than 7 days, format as week-by-week tables
if (count($days) > 7) {
    // Generate weekly groups
    $startCursor = clone $startDate;
    $startCursor->modify('monday this week');
    $endCursor = clone $endDate;
    $endCursor->modify('sunday this week');

    $weeks = [];
    while ($startCursor <= $endCursor) {
        $weekEnd = clone $startCursor;
        $weekEnd->modify('+6 days');
        
        $weekDays = [];
        $dayCursor = clone $startCursor;
        for ($i = 0; $i < 7; $i++) {
            $weekDays[] = $dayCursor->format('Y-m-d');
            $dayCursor->modify('+1 day');
        }
        
        $weeks[] = [
            'label' => $startCursor->format('M d') . ' - ' . $weekEnd->format('M d, Y'),
            'days' => $weekDays
        ];
        
        $startCursor->modify('+7 days');
    }

    foreach ($weeks as $week) {
        if ($pdf->GetY() > 240) {
            $pdf->AddPage();
        }

        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(0, 6, 'Week: ' . $week['label'], 0, 1, 'L');
        
        $pdf->SetFillColor(30, 58, 138);
        $pdf->SetTextColor(255, 255, 255);
        
        $nameWidth = 70;
        $dayWidth = 15;
        
        $pdf->Cell($nameWidth, 8, 'Student Name', 1, 0, 'L', true);
        foreach ($week['days'] as $day) {
            $pdf->Cell($dayWidth, 8, date('m/d/y', strtotime($day)), 1, 0, 'C', true);
        }
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln();
        
        $pdf->SetFont('Arial', '', 8);
        if (empty($students)) {
            $pdf->Cell($nameWidth + ($dayWidth * 7), 8, 'No student records found.', 1, 1, 'C');
        } else {
            foreach ($students as $student) {
                if ($pdf->GetY() > 270) {
                    $pdf->AddPage();
                    $pdf->SetFont('Arial', 'B', 9);
                    $pdf->Cell(0, 6, 'Week: ' . $week['label'] . ' (Continued)', 0, 1, 'L');
                    $pdf->SetFillColor(30, 58, 138);
                    $pdf->SetTextColor(255, 255, 255);
                    $pdf->Cell($nameWidth, 8, 'Student Name', 1, 0, 'L', true);
                    foreach ($week['days'] as $day) {
                        $pdf->Cell($dayWidth, 8, date('D m/d', strtotime($day)), 1, 0, 'C', true);
                    }
                    $pdf->SetTextColor(0, 0, 0);
                    $pdf->Ln();
                    $pdf->SetFont('Arial', '', 8);
                }
                
                $pdf->Cell($nameWidth, 7, substr((string) $student['full_name'], 0, 45), 1, 0, 'L');
                foreach ($week['days'] as $day) {
                    $status = $statusMap[(int) $student['id']][$day] ?? '-';
                    if ($status === 'P') {
                        $pdf->SetFillColor(34, 197, 94);
                        $pdf->SetTextColor(255, 255, 255);
                        $pdf->Cell($dayWidth, 7, 'P', 1, 0, 'C', true);
                    } elseif ($status === 'L') {
                        $pdf->SetFillColor(250, 204, 21);
                        $pdf->SetTextColor(0, 0, 0);
                        $pdf->Cell($dayWidth, 7, 'L', 1, 0, 'C', true);
                    } elseif ($status === 'A') {
                        $pdf->SetFillColor(239, 68, 68);
                        $pdf->SetTextColor(255, 255, 255);
                        $pdf->Cell($dayWidth, 7, 'A', 1, 0, 'C', true);
                    } else {
                        $pdf->SetTextColor(100, 116, 139);
                        $pdf->Cell($dayWidth, 7, '-', 1, 0, 'C');
                    }
                    $pdf->SetTextColor(0, 0, 0);
                }
                $pdf->Ln();
            }
        }
        $pdf->Ln(4);
    }
} else {
    // Normal single table layout (for daily or single week reports)
    $totalWidth = $pdf->GetPageWidth() - 30;
    $nameWidth = 70;
    $dayWidth = max(8, floor(($totalWidth - $nameWidth) / max(1, count($days))));
    $pdf->SetX(15);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell($nameWidth, 8, 'Student Name', 1, 0, 'L');
    foreach ($days as $day) {
        $pdf->Cell($dayWidth, 8, date('m/d/y', strtotime($day)), 1, 0, 'C');
    }
    $pdf->Ln();

    $pdf->SetFont('Arial', '', 8);
    if (empty($students)) {
        $pdf->Cell($nameWidth + ($dayWidth * count($days)), 8, 'No attendance records found in selected range.', 1, 1, 'C');
    } else {
        foreach ($students as $student) {
            $pdf->SetX(15);
            $pdf->Cell($nameWidth, 7, substr((string) $student['full_name'], 0, 45), 1, 0, 'L');
            foreach ($days as $day) {
                $status = $statusMap[(int) $student['id']][$day] ?? '-';
                $letter = strtoupper(substr((string) $status, 0, 1));
                if ($letter === 'P') {
                    $pdf->SetFillColor(34, 197, 94);
                    $pdf->SetTextColor(255, 255, 255);
                    $pdf->Cell($dayWidth, 7, 'P', 1, 0, 'C', true);
                    $pdf->SetTextColor(0, 0, 0);
                } elseif ($letter === 'L') {
                    $pdf->SetFillColor(250, 204, 21);
                    $pdf->SetTextColor(0, 0, 0);
                    $pdf->Cell($dayWidth, 7, 'L', 1, 0, 'C', true);
                } elseif ($letter === 'A') {
                    $pdf->SetFillColor(239, 68, 68);
                    $pdf->SetTextColor(255, 255, 255);
                    $pdf->Cell($dayWidth, 7, 'A', 1, 0, 'C', true);
                    $pdf->SetTextColor(0, 0, 0);
                } else {
                    $pdf->SetTextColor(100, 116, 139);
                    $pdf->Cell($dayWidth, 7, '-', 1, 0, 'C');
                    $pdf->SetTextColor(0, 0, 0);
                }
            }
            $pdf->Ln();
        }
    }
}

$pdf->Output('I', 'attendance_report_' . date('Ymd_His') . '.pdf');
exit;
?>
