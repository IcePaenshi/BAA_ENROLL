<?php
ini_set('display_errors', 0);
error_reporting(0);
ob_start();
session_start();
require_once 'db.php';
require('../fpdf/fpdf.php');

class PDF extends FPDF {
    public $userName = 'User';

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
        $this->Cell(0, 10, 'Page ' . $this->PageNo() . ' | Encoded by ' . $this->userName . ' on ' . date('Y-m-d'), 0, 0, 'C');
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
        foreach ($headers as $header) {
            $this->Cell(45, 8, $header, 1, 0, 'C', true);
        }
        $this->Ln();
    }
    
    // Table row
    function TableRow($data) {
        $this->SetFont('Arial', '', 10);
        $this->SetTextColor(0, 0, 0);
        foreach ($data as $cell) {
            $this->Cell(45, 8, $cell, 1, 0, 'C');
        }
        $this->Ln();
    }
}

// Check user role
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'registrar'])) {
    ob_end_clean();
    die('Unauthorized access');
}

try {
    $currentYear = date('Y');
    $lastYear = $currentYear - 1;
    
    // Enrolled this year vs last year
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM enrollments WHERE YEAR(created_at) = ? AND status = 'accepted'");
    $stmt->execute([$currentYear]);
    $enrolledThisYear = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    $stmt->execute([$lastYear]);
    $enrolledLastYear = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Didn't enroll/submit this year vs last year (pending)
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM enrollments WHERE YEAR(created_at) = ? AND status = 'pending'");
    $stmt->execute([$currentYear]);
    $pendingThisYear = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    $stmt->execute([$lastYear]);
    $pendingLastYear = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Grade 7-12 counts
    $gradeCounts = [];
    for ($grade = 7; $grade <= 12; $grade++) {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM enrollments WHERE grade_level = ? AND status = 'accepted'");
        $stmt->execute([$grade]);
        $gradeCounts[$grade] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    }
    
    // Student type counts
    $stmt = $pdo->query("SELECT student_type, COUNT(*) as count FROM enrollments WHERE status = 'accepted' GROUP BY student_type");
    $studentTypeCounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch registrar/user name
    $userId = $_SESSION['user_id'];
    $userNameStmt = $pdo->prepare("SELECT " . baa_full_name_sql() . " AS full_name FROM users WHERE id = ?");
    $userNameStmt->execute([$userId]);
    $userName = $userNameStmt->fetchColumn() ?: 'User';

    // Create PDF
    $pdf = new PDF();
    $pdf->userName = $userName;
    $pdf->AliasNbPages();
    $pdf->AddPage();
    $pdf->SetMargins(15, 15, 15);
    
    // Title
    $pdf->SetFont('Arial', 'B', 20);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(0, 15, 'REGISTRAR ENROLLMENT REPORT', 0, 1, 'C');
    $pdf->Ln(10);
    
    // Enrollment Comparison
    $pdf->SectionTitle('Enrollment Comparison');
    $pdf->TableHeader(['Metric', 'This Year (' . $currentYear . ')', 'Last Year (' . $lastYear . ')']);
    $pdf->TableRow(['Enrolled Students', $enrolledThisYear, $enrolledLastYear]);
    $pdf->TableRow(['Pending Submissions', $pendingThisYear, $pendingLastYear]);
    $pdf->Ln(10);
    
    // Grade Breakdown
    $pdf->SectionTitle('Grade Level Breakdown (Accepted Enrollments)');
    $pdf->TableHeader(['Grade', 'Count']);
    for ($grade = 7; $grade <= 12; $grade++) {
        $pdf->TableRow([$grade, $gradeCounts[$grade]]);
    }
    $pdf->Ln(10);
    
    // Student Type Breakdown
    $pdf->SectionTitle('Student Type Breakdown (Accepted Enrollments)');
    $pdf->TableHeader(['Type', 'Count']);
    foreach ($studentTypeCounts as $type) {
        $pdf->TableRow([$type['student_type'], $type['count']]);
    }
    
    // Output PDF
    ob_end_clean();
    $pdf->Output('registrar_report.pdf', 'I');
    
} catch (Exception $e) {
    ob_end_clean();
    die('Error generating report: ' . $e->getMessage());
}
?>