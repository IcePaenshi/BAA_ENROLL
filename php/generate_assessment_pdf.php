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

// Fetch subjects schedule
$subjStmt = $pdo->prepare("SELECT subject_name, day_of_week, DATE_FORMAT(start_time, '%h:%i %p') as start_time, DATE_FORMAT(end_time, '%h:%i %p') as end_time FROM subjects WHERE grade_level = ? AND section = ? ORDER BY subject_name, FIELD(day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'), start_time");
$subjStmt->execute([$student['grade_level'], $student['section']]);
$subjects = $subjStmt->fetchAll(PDO::FETCH_ASSOC);

// Group subjects by name and combine schedules with day abbreviations
$groupedSubjects = [];
foreach ($subjects as $subj) {
    $name = $subj['subject_name'];
    if (!isset($groupedSubjects[$name])) {
        $groupedSubjects[$name] = [];
    }
    $day = $subj['day_of_week'];
    // Abbreviate day
    switch ($day) {
        case 'Monday': $dayAbbr = 'M'; break;
        case 'Tuesday': $dayAbbr = 'T'; break;
        case 'Wednesday': $dayAbbr = 'W'; break;
        case 'Thursday': $dayAbbr = 'Th'; break;
        case 'Friday': $dayAbbr = 'F'; break;
        case 'Saturday': $dayAbbr = 'Sa'; break;
        case 'Sunday': $dayAbbr = 'Su'; break;
        default: $dayAbbr = $day;
    }
    $timeRange = $subj['start_time'] . ' - ' . $subj['end_time'];
    $groupedSubjects[$name][] = $dayAbbr . ' ' . $timeRange;
}

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
    function Header() {
        // School Logo
        $logoPath = '../images/logo.png';
        if (file_exists($logoPath) && is_readable($logoPath)) {
            $this->Image($logoPath, 15, 10, 25);
        } else {
            $this->SetFont('Arial', 'B', 14);
            $this->SetTextColor(0,0,0);
            $this->SetXY(15,15);
            $this->Cell(25,25,'BAA',1,0,'C');
        }
        
        // School Name
        $this->SetFont('Arial','B',18);
        $this->SetTextColor(0,0,0);
        $this->SetY(12);
        $this->Cell(0,8,'BAESA ADVENTIST ACADEMY',0,1,'C');
        
        // Tagline
        $this->SetFont('Arial','I',10);
        $this->SetY(22);
        $this->Cell(0,6,'The School That Trains for Service',0,1,'C');
        
        // Address
        $this->SetFont('Arial','',9);
        $this->SetY(30);
        $this->Cell(0,5,'Baesa Road, Baesa, Quezon City, Metro Manila 1106',0,1,'C');
        $this->SetY(35);
        $this->Cell(0,5,'Tel: (02) 123-4567 | Email: info@baesaadventist.edu.ph',0,1,'C');
        
        // Line
        $this->SetDrawColor(10,45,99);
        $this->SetLineWidth(0.5);
        $this->Line(15,45,195,45);
        $this->SetY(50);
    }
    
    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial','I',8);
        $this->SetTextColor(100,100,100);
        $this->Cell(0,10,'Page '.$this->PageNo().'/{nb} | Generated on '.date('Y-m-d H:i:s'),0,0,'C');
    }
    
    function SectionTitle($title) {
        $this->SetFont('Arial','B',12);
        $this->SetTextColor(0,0,0);
        $this->Cell(0,8,$title,0,1);
        $this->SetDrawColor(10,45,99);
        $this->SetLineWidth(0.3);
        $this->Line($this->GetX(), $this->GetY(), $this->GetX()+180, $this->GetY());
        $this->Ln(4);
    }
    
    function TwoColumnRow($leftLabel, $leftValue, $rightLabel, $rightValue) {
        $this->SetFont('Arial','B',10);
        $this->Cell(45,6,$leftLabel.':',0,0);
        $this->SetFont('Arial','',10);
        $this->Cell(55,6,$leftValue,0,0);
        $this->SetFont('Arial','B',10);
        $this->Cell(25,6,$rightLabel.':',0,0);
        $this->SetFont('Arial','',10);
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

// Class Schedule
$pdf->SectionTitle('Class Schedule');
if (!empty($groupedSubjects)) {
    // Table header
    $pdf->SetFont('Arial','B',10);
    $pdf->Cell(45,7,'Subject',1,0,'L');
    $pdf->Cell(135,7,'Schedule',1,1,'L');
    
    $lineHeight = 5; // line height for schedule
    foreach ($groupedSubjects as $subjectName => $schedules) {
        $scheduleStr = implode(', ', $schedules);
        
        // Calculate number of lines needed for schedule string
        $pdf->SetFont('Arial','',9);
        $nbLines = $pdf->NbLines(135, $scheduleStr);
        $rowHeight = $nbLines * $lineHeight;
        
        // Save current position
        $startX = $pdf->GetX();
        $startY = $pdf->GetY();
        
        // Draw subject cell (with calculated height)
        $pdf->SetFont('Arial','B',10);
        $pdf->Cell(45, $rowHeight, $subjectName, 1, 0, 'L');
        
        // Draw schedule cell (with same height)
        $pdf->SetFont('Arial','',9);
        $pdf->SetXY($startX + 45, $startY);
        $pdf->MultiCell(135, $lineHeight, $scheduleStr, 1, 'L');
        
        // Move Y to the bottom of the row (should already be there after MultiCell)
        $pdf->SetY($startY + $rowHeight);
    }
} else {
    $pdf->SetFont('Arial','',10);
    $pdf->Cell(0,6,'No schedule assigned.',0,1);
}
$pdf->Ln(5);

// Financial Assessment and Payment Schedule side by side
$startY = $pdf->GetY();

// Headers
$pdf->SetFont('Arial','B',11);
$pdf->Cell(90,7,'Financial Assessment',0,0);
$pdf->SetX(105);
$pdf->Cell(90,7,'Payment Schedule',0,1);
$pdf->Ln(1);

$leftX = 15;
$rightX = 105;
$currentY = $pdf->GetY();

// LEFT COLUMN: Fee breakdown up to TOTAL ASSESSMENT
$pdf->SetXY($leftX, $currentY);
$pdf->SetFont('Arial','',9);

$pdf->Cell(60,5,'Tuition Fee',0,0);
$pdf->Cell(30,5,number_format($tuition,2),0,1,'R');
$pdf->SetX($leftX);
$pdf->Cell(60,5,'Miscellaneous & Others',0,0);
$pdf->Cell(30,5,number_format($misc,2),0,1,'R');
$pdf->SetX($leftX);
$pdf->Cell(60,5,'Aircon Fee',0,0);
$pdf->Cell(30,5,number_format($aircon,2),0,1,'R');
$pdf->SetX($leftX);
$pdf->Cell(60,5,'HSA Fee',0,0);
$pdf->Cell(30,5,number_format($hsa,2),0,1,'R');
$pdf->SetX($leftX);
$pdf->Cell(60,5,'Books',0,0);
$pdf->Cell(30,5,number_format($books,2),0,1,'R');
$pdf->Ln(2);

$pdf->SetX($leftX);
$pdf->SetFont('Arial','B',9);
$pdf->Cell(60,5,'TOTAL ASSESSMENT',0,0);
$pdf->Cell(30,5,number_format($totalFees,2),0,1,'R');

// RIGHT COLUMN: Discount, NET TOTAL, Down Payment, then Payment Schedule
$pdf->SetXY($rightX, $currentY);
$pdf->SetFont('Arial','',9);

$pdf->Cell(60,5,'Less: Discounts/Scholarship',0,0);
$pdf->Cell(30,5,number_format($discounts,2),0,1,'R');
$pdf->SetX($rightX);
$pdf->SetFont('Arial','B',9);
$pdf->Cell(60,5,'NET TOTAL',0,0);
$pdf->Cell(30,5,number_format($netTotal,2),0,1,'R');
$pdf->SetX($rightX);
$pdf->SetFont('Arial','',9);
$pdf->Cell(60,5,'Down Payment',0,0);
$pdf->Cell(30,5,number_format($downPayment,2),0,1,'R');
$pdf->Ln(3); // space before Payment Schedule

// Payment Schedule header
$pdf->SetX($rightX);
$pdf->SetFont('Arial','B',10);
$pdf->Cell(90,6,'Payment Schedule',0,1);
$pdf->SetX($rightX);
$pdf->SetFont('Arial','',9);
$pdf->Cell(60,5,'Number of Monthly Payments',0,0);
$pdf->Cell(30,5,$monthlyPayments,0,1,'R');
$pdf->SetX($rightX);
$pdf->Cell(60,5,'Monthly Payment Amount',0,0);
$pdf->Cell(30,5,number_format($monthlyAmount,2),0,1,'R');
$pdf->Ln(2);
$pdf->SetX($rightX);
$pdf->SetFont('Arial','B',9);
$pdf->Cell(60,5,'TOTAL OUTSTANDING BALANCE',0,0);
$pdf->Cell(30,5,number_format($balanceAfterDP,2),0,1,'R');

// Output PDF
$pdf->Output('D', 'Assessment_'.$student['lrn'].'.pdf');
?>