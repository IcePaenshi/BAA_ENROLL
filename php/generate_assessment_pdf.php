<?php
// Clear any previous output
if (ob_get_level()) ob_clean();

require_once 'db.php';
require_once '../fpdf/fpdf.php';

// Get POST data
$student_id = $_POST['student_id'] ?? 0;
$tuition = floatval($_POST['tuition'] ?? 0);
$misc = floatval($_POST['misc'] ?? 0);
$aircon = floatval($_POST['aircon'] ?? 0);
$hsa = floatval($_POST['hsa'] ?? 0);
$books = floatval($_POST['books'] ?? 0);
$discounts = floatval($_POST['discounts'] ?? 0);
$downPayment = floatval($_POST['downPayment'] ?? 0);
$monthlyPayments = intval($_POST['monthlyPayments'] ?? 4);
$monthlyAmount = floatval($_POST['monthlyPaymentAmount'] ?? 0);
$remainingBalance = floatval($_POST['remainingBalance'] ?? 0);

if (!$student_id) {
    die('Student ID required');
}

// Fetch student details
$stmt = $pdo->prepare("SELECT first_name, middle_name, last_name, suffix, grade_level, section, lrn FROM users WHERE id = ?");
$stmt->execute([$student_id]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$student) {
    die('Student not found');
}

// Fetch subjects schedule for 5-day table layout
$subjStmt = $pdo->prepare("SELECT subject_name, day_of_week, DATE_FORMAT(start_time, '%h:%i %p') as start_time, DATE_FORMAT(end_time, '%h:%i %p') as end_time FROM subjects WHERE grade_level = ? AND section = ? AND day_of_week IN ('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday') ORDER BY subject_name, FIELD(day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'), start_time");
$subjStmt->execute([$student['grade_level'], $student['section']]);
$subjects = $subjStmt->fetchAll(PDO::FETCH_ASSOC);

// Organize subjects into day-indexed structure for 5-day table
$dayMap = array('Monday' => 0, 'Tuesday' => 1, 'Wednesday' => 2, 'Thursday' => 3, 'Friday' => 4);
$scheduleByDay = array_fill(0, 5, array());
$allSubjects = array();

foreach ($subjects as $subj) {
    $dayIndex = $dayMap[$subj['day_of_week']];
    $timeRange = $subj['start_time'] . ' - ' . $subj['end_time'];
    
    // Store schedule for each day
    if (!isset($scheduleByDay[$dayIndex][$subj['subject_name']])) {
        $scheduleByDay[$dayIndex][$subj['subject_name']] = array();
    }
    $scheduleByDay[$dayIndex][$subj['subject_name']][] = $timeRange;
    
    // Track all unique subjects
    if (!in_array($subj['subject_name'], $allSubjects)) {
        $allSubjects[] = $subj['subject_name'];
    }
}

// Sort subjects alphabetically
sort($allSubjects);

// Build full name
$fullName = trim($student['first_name'] . ' ' .
    (!empty($student['middle_name']) ? $student['middle_name'] . ' ' : '') .
    $student['last_name'] .
    (!empty($student['suffix']) ? ' ' . $student['suffix'] : ''));

// Calculate totals
$totalFees = $tuition + $misc + $aircon + $hsa + $books;
$netTotal = $totalFees - $discounts;
$balanceAfterDP = $netTotal - $downPayment;

class AssessmentPDF extends FPDF {
    // Color constants (hex to RGB conversion)
    private $primaryBlue = array(30, 58, 138);      // #1e3a8a
    private $secondaryGray = array(100, 116, 139);  // #64748b
    private $background = array(248, 250, 252);     // #f8fafc
    private $border = array(226, 232, 240);         // #e2e8f0
    private $textMain = array(15, 23, 42);          // #0f172a

    function Header() {
        // School Logo - enlarged
        $logoPath = '../images/logo.png';
        if (file_exists($logoPath) && is_readable($logoPath)) {
            $this->Image($logoPath, 15, 8, 35);
        } else {
            $this->SetFont('Arial', 'B', 14);
            $this->SetTextColor(0,0,0);
            $this->SetXY(15,12);
            $this->Cell(35,35,'BAA',1,0,'C');
        }
        
        // School Name - centered, black, uppercase
        $this->SetFont('Arial','B',20);
        $this->SetTextColor(0, 0, 0);
        $this->SetY(12);
        $this->Cell(0,10,'BAESA ADVENTIST ACADEMY',0,1,'C');
        
        // Tagline - italic, gray
        $this->SetFont('Arial','I',10);
        $this->SetTextColor($this->secondaryGray[0], $this->secondaryGray[1], $this->secondaryGray[2]);
        $this->SetY(24);
        $this->Cell(0,5,'The School That Trains for Service',0,1,'C');
        
        // Address - gray
        $this->SetFont('Arial','',8);
        $this->SetY(31);
        $this->Cell(0,4,'Baesa Road, Baesa, Quezon City, Metro Manila 1106',0,1,'C');
        $this->SetY(36);
        $this->Cell(0,4,'Tel: (02) 123-4567 | Email: info@baesaadventist.edu.ph',0,1,'C');
        
        // Blue separator line
        $this->SetDrawColor($this->primaryBlue[0], $this->primaryBlue[1], $this->primaryBlue[2]);
        $this->SetLineWidth(0.6);
        $this->Line(15, 43, 195, 43);
        $this->SetY(48);
    }
    
    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial','I',8);
        $this->SetTextColor(100,100,100);
        $this->Cell(0,10,'Page '.$this->PageNo().'/{nb} | Generated on '.date('Y-m-d H:i:s'),0,0,'C');
    }
    
    function SectionTitle($title) {
        $this->SetFont('Arial','B',12);
        $this->SetTextColor(0, 0, 0);
        $this->Cell(0,8,$title,0,1);
        $this->SetDrawColor($this->primaryBlue[0], $this->primaryBlue[1], $this->primaryBlue[2]);
        $this->SetLineWidth(0.5);
        $this->Line($this->GetX(), $this->GetY(), $this->GetX()+180, $this->GetY());
        $this->Ln(4);
    }
    
    function TwoColumnRow($leftLabel, $leftValue, $rightLabel, $rightValue) {
        // Left column: label in blue, value in black
        $this->SetFont('Arial','B',10);
        $this->SetTextColor($this->primaryBlue[0], $this->primaryBlue[1], $this->primaryBlue[2]);
        $this->Cell(45,6,$leftLabel.':',0,0);
        $this->SetFont('Arial','',10);
        $this->SetTextColor(0, 0, 0);
        $this->Cell(55,6,$leftValue,0,0);
        
        // Right column: label in blue, value in black
        $this->SetFont('Arial','B',10);
        $this->SetTextColor($this->primaryBlue[0], $this->primaryBlue[1], $this->primaryBlue[2]);
        $this->Cell(25,6,$rightLabel.':',0,0);
        $this->SetFont('Arial','',10);
        $this->SetTextColor(0, 0, 0);
        $this->Cell(0,6,$rightValue,0,1);
    }

    // Helper to compute number of lines for MultiCell
    function NbLines($w, $txt) {
        $cw = &$this->CurrentFont['cw'];
        if($w == 0)
            $w = $this->w - $this->rMargin - $this->x;
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s = str_replace("\r", '', $txt);
        $nb = strlen($s);
        if($nb > 0 && $s[$nb - 1] == "\n")
            $nb--;
        $sep = -1;
        $i = 0;
        $j = 0;
        $l = 0;
        $nl = 1;
        while($i < $nb) {
            $c = $s[$i];
            if($c == "\n") {
                $i++;
                $sep = -1;
                $j = $i;
                $l = 0;
                $nl++;
                continue;
            }
            if($c == ' ')
                $sep = $i;
            $l += $cw[$c];
            if($l > $wmax) {
                if($sep == -1) {
                    if($i == $j)
                        $i++;
                } else {
                    $i = $sep + 1;
                }
                $sep = -1;
                $j = $i;
                $l = 0;
                $nl++;
            } else {
                $i++;
            }
        }
        return $nl;
    }
}

$pdf = new AssessmentPDF();
$pdf->AliasNbPages();
$pdf->AddPage();
$pdf->SetMargins(15,15,15);

// Title
$pdf->SetFont('Arial','B',20);
$pdf->Cell(0,15,'STUDENT ASSESSMENT FORM',0,1,'C');
$pdf->Ln(5);

// Student Information
$pdf->SectionTitle('Student Information');
$schoolYear = date('Y').'-'.(date('Y')+1);
$pdf->TwoColumnRow('Name', $fullName, 'LRN', $student['lrn']);
$pdf->TwoColumnRow('Grade Level', $student['grade_level'], 'School Year', $schoolYear);
$pdf->TwoColumnRow('Section', $student['section'], '', '');
$pdf->Ln(3);

// Class Schedule - 5-day table layout
$pdf->SectionTitle('Weekly Class Schedule');
if (!empty($allSubjects)) {
    // Table header with days of week
    $pdf->SetFont('Arial','B',10);
    $pdf->SetFillColor(248, 250, 252); // Background color
    $pdf->SetTextColor(30, 58, 138); // Blue text
    
    $pdf->Cell(35, 8, 'Subject', 1, 0, 'C', true);
    $days = array('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday');
    foreach ($days as $day) {
        $dayShort = substr($day, 0, 3);
        $pdf->Cell(32, 8, $dayShort, 1, 0, 'C', true);
    }
    $pdf->Ln();
    
    // Table rows
    $pdf->SetFont('Arial','',8);
    $pdf->SetTextColor(0, 0, 0);
    
    foreach ($allSubjects as $subject) {
        $pdf->Cell(35, 6, $subject, 1, 0, 'L');
        
        for ($dayIndex = 0; $dayIndex < 5; $dayIndex++) {
            if (isset($scheduleByDay[$dayIndex][$subject])) {
                $times = implode(" ", $scheduleByDay[$dayIndex][$subject]);
                $pdf->Cell(32, 6, $times, 1, 0, 'C');
            } else {
                $pdf->Cell(32, 6, '', 1, 0, 'C');
            }
        }
        $pdf->Ln();
    }
} else {
    $pdf->SetFont('Arial','',10);
    $pdf->Cell(0, 6, 'No schedule assigned.', 0, 1);
}
$pdf->Ln(5);

// Financial Summary Section
$pdf->SectionTitle('Financial Summary');

// LEFT COLUMN: Fee breakdown
$pdf->SetFont('Arial','',9);
$pdf->SetTextColor(0, 0, 0);

$leftX = 15;
$rightX = 105;
$currentY = $pdf->GetY();

$pdf->SetXY($leftX, $currentY);
$pdf->Cell(60, 5, 'Tuition Fee', 0, 0);
$pdf->Cell(30, 5, number_format($tuition, 2), 0, 1, 'R');
$pdf->SetX($leftX);
$pdf->Cell(60, 5, 'Miscellaneous & Others', 0, 0);
$pdf->Cell(30, 5, number_format($misc, 2), 0, 1, 'R');
$pdf->SetX($leftX);
$pdf->Cell(60, 5, 'Aircon Fee', 0, 0);
$pdf->Cell(30, 5, number_format($aircon, 2), 0, 1, 'R');
$pdf->SetX($leftX);
$pdf->Cell(60, 5, 'HSA Fee', 0, 0);
$pdf->Cell(30, 5, number_format($hsa, 2), 0, 1, 'R');
$pdf->SetX($leftX);
$pdf->Cell(60, 5, 'Books', 0, 0);
$pdf->Cell(30, 5, number_format($books, 2), 0, 1, 'R');
$pdf->Ln(2);

// Total Assessment
$pdf->SetX($leftX);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(60, 5, 'TOTAL ASSESSMENT', 0, 0);
$pdf->Cell(30, 5, number_format($totalFees, 2), 0, 1, 'R');

// RIGHT COLUMN: Discount, Net Total, Down Payment
$pdf->SetXY($rightX, $currentY);
$pdf->SetFont('Arial', '', 9);
$pdf->SetTextColor(0, 0, 0);

$pdf->Cell(60, 5, 'Less: Discounts/Scholarship', 0, 0);
$pdf->Cell(30, 5, number_format($discounts, 2), 0, 1, 'R');
$pdf->SetX($rightX);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(60, 5, 'NET TOTAL', 0, 0);
$pdf->Cell(30, 5, number_format($netTotal, 2), 0, 1, 'R');
$pdf->SetX($rightX);
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(60, 5, 'Down Payment', 0, 0);
$pdf->Cell(30, 5, number_format($downPayment, 2), 0, 1, 'R');
$pdf->Ln(3);

// Payment Schedule section
$pdf->SetX($rightX);
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(90, 6, 'Payment Schedule', 0, 1);
$pdf->SetX($rightX);
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(60, 5, 'Number of Monthly Payments', 0, 0);
$pdf->Cell(30, 5, $monthlyPayments, 0, 1, 'R');
$pdf->SetX($rightX);
$pdf->Cell(60, 5, 'Monthly Payment Amount', 0, 0);
$pdf->Cell(30, 5, number_format($monthlyAmount, 2), 0, 1, 'R');
$pdf->Ln(4);

// Outstanding Balance Blue Block - Bottom Right
$balanceBlockX = 105;
$balanceBlockY = $pdf->GetY();
$pdf->SetXY($balanceBlockX, $balanceBlockY);

// Blue background box
$pdf->SetFillColor(30, 58, 138); // Primary blue
$pdf->SetTextColor(255, 255, 255); // White text
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(90, 8, 'OUTSTANDING BALANCE', 1, 1, 'C', true);

// Balance amount
$pdf->SetXY($balanceBlockX, $pdf->GetY());
$pdf->SetFont('Arial', 'B', 14);
$pdf->Cell(90, 10, number_format($balanceAfterDP, 2), 1, 1, 'C', true);

// Reset text color for footer
$pdf->SetTextColor(0, 0, 0);

// Output PDF
$pdf->Output('D', 'Assessment_'.$student['lrn'].'.pdf');
?>