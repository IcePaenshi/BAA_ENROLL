<?php
ini_set('display_errors', 0);
error_reporting(0);
ob_start();
session_start();
require_once 'db.php';
require('../fpdf/fpdf.php');

class PDF extends FPDF {
    public $teacherName = 'Teacher';

    // Page header
    function Header() {
        // School Logo 
        $logoPath = '../images/logo.png';
        if (file_exists($logoPath) && is_readable($logoPath)) {
            try {
                $this->Image($logoPath, 15, 10, 25);
            } catch (Exception $e) {
                $this->SetFont('Arial', 'B', 14);
                $this->SetTextColor(0, 0, 0);
                $this->SetXY(15, 15);
                $this->Cell(25, 25, 'BAA', 1, 0, 'C');
            }
        } else {
            $this->SetFont('Arial', 'B', 14);
            $this->SetTextColor(0, 0, 0);
            $this->SetXY(15, 15);
            $this->Cell(25, 25, 'BAA', 1, 0, 'C');
        }
        
        // School Name
        $this->SetFont('Arial', 'B', 18);
        $this->SetTextColor(0, 0, 0);
        $this->SetY(12);
        $this->Cell(0, 8, 'BAESA ADVENTIST ACADEMY', 0, 1, 'C');
        
        // Tagline 
        $this->SetFont('Arial', 'I', 10);
        $this->SetTextColor(0, 0, 0);
        $this->SetY(22);
        $this->Cell(0, 6, 'The School That Trains for Service', 0, 1, 'C');
        
        // Address and Contact
        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(0, 0, 0);
        $this->SetY(30);
        $this->Cell(0, 5, 'Baesa Road, Baesa, Quezon City, Metro Manila 1106', 0, 1, 'C');
        
        $this->SetY(35);
        $this->Cell(0, 5, 'Tel: (02) 123-4567 | Email: info@baesaadventist.edu.ph', 0, 1, 'C');
        
        // Line separator - blue line only
        $this->SetDrawColor(10, 45, 99);
        $this->SetLineWidth(0.5);
        $this->Line(15, 45, 195, 45);
        
        $this->SetY(50);
    }
    
    // Page footer
    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 10, 'Page ' . $this->PageNo() . ' | Encoded by ' . $this->teacherName . ' on ' . date('Y-m-d'), 0, 0, 'C');
    }
    // Section title
    function SectionTitle($title) {
        $this->SetFont('Arial', 'B', 12);
        $this->SetTextColor(0, 0, 0);
        $this->Cell(0, 8, $title, 0, 1);
        $this->SetDrawColor(10, 45, 99);
        $this->SetLineWidth(0.3);
        $this->Line($this->GetX(), $this->GetY(), $this->GetX() + 180, $this->GetY());
        $this->Ln(5);
    }
    
    // Table header
    function TableHeader($headers) {
        $this->SetFont('Arial', 'B', 10);
        $this->SetFillColor(10, 45, 99);
        $this->SetTextColor(255, 255, 255);
        $width = 180 / count($headers);
        foreach ($headers as $header) {
            $this->Cell($width, 8, $header, 1, 0, 'C', true);
        }
        $this->Ln();
    }
    
    // Table row
    function TableRow($data) {
        $this->SetFont('Arial', '', 10);
        $this->SetTextColor(0, 0, 0);
        $width = 180 / count($data);
        foreach ($data as $cell) {
            $this->Cell($width, 8, $cell, 1, 0, 'C');
        }
        $this->Ln();
    }
}

// Check user role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    ob_end_clean();
    die('Unauthorized access');
}

try {
    $teacherId = $_SESSION['user_id'];
    
    // Attendance summary for current month
    $currentMonth = date('m');
    $currentYear = date('Y');
    
    $stmt = $pdo->prepare("SELECT 
        status, COUNT(*) as count 
        FROM attendance 
        WHERE teacher_id = ? AND MONTH(date) = ? AND YEAR(date) = ? 
        GROUP BY status");
    $stmt->execute([$teacherId, $currentMonth, $currentYear]);
    $attendanceSummary = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Total students
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT student_id) as total_students 
        FROM attendance 
        WHERE teacher_id = ? AND MONTH(date) = ? AND YEAR(date) = ?");
    $stmt->execute([$teacherId, $currentMonth, $currentYear]);
    $totalStudents = $stmt->fetch(PDO::FETCH_ASSOC)['total_students'];
    
    // Fetch teacher name
    $teacherNameStmt = $pdo->prepare("SELECT " . baa_full_name_sql() . " AS full_name FROM users WHERE id = ?");
    $teacherNameStmt->execute([$teacherId]);
    $teacherName = $teacherNameStmt->fetchColumn() ?: 'Teacher';

    // Create PDF
    $pdf = new PDF();
    $pdf->teacherName = $teacherName;
    $pdf->AliasNbPages();
    $pdf->AddPage();
    $pdf->SetMargins(15, 15, 15);
    
    // Title
    $pdf->SetFont('Arial', 'B', 20);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(0, 15, 'TEACHER ATTENDANCE REPORT', 0, 1, 'C');
    $pdf->Ln(10);
    
    // Attendance Summary
    $pdf->SectionTitle('Attendance Summary for ' . date('F Y'));
    $pdf->TableHeader(['Status', 'Count']);
    foreach ($attendanceSummary as $summary) {
        $pdf->TableRow([ucfirst($summary['status']), $summary['count']]);
    }
    $pdf->Ln(10);
    
    $pdf->SetFont('Arial', '', 12);
    $pdf->Cell(0, 8, 'Total Students Tracked: ' . $totalStudents, 0, 1);
    
    // Output PDF
    ob_end_clean();
    $pdf->Output('teacher_report.pdf', 'I');
    
} catch (Exception $e) {
    ob_end_clean();
    die('Error generating report: ' . $e->getMessage());
}
?>