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
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'cashier'])) {
    ob_end_clean();
    die('Unauthorized access');
}

try {
    // Downpayment paid vs didn't
    $stmt = $pdo->query("SELECT 
        SUM(CASE WHEN payment_type = 'downpayment' AND status = 'completed' THEN 1 ELSE 0 END) as paid_downpayment,
        SUM(CASE WHEN payment_type = 'downpayment' AND status != 'completed' THEN 1 ELSE 0 END) as didnt_pay_downpayment
        FROM payments");
    $downpayment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Full payment
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM payments WHERE payment_type = 'full' AND status = 'completed'");
    $fullPaid = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Installment
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM payments WHERE payment_type = 'installment' AND status = 'completed'");
    $installment = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Most common installment months
    $stmt = $pdo->query("SELECT MONTHNAME(next_due_date) as month, COUNT(*) as count 
        FROM payments 
        WHERE payment_type = 'installment' AND next_due_date IS NOT NULL 
        GROUP BY MONTH(next_due_date) 
        ORDER BY count DESC 
        LIMIT 5");
    $installmentMonths = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch cashier/user name
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
    $pdf->Cell(0, 15, 'CASHIER PAYMENT REPORT', 0, 1, 'C');
    $pdf->Ln(10);
    
    // Payment Summary
    $pdf->SectionTitle('Payment Summary');
    $pdf->TableHeader(['Payment Type', 'Count']);
    $pdf->TableRow(['Downpayment Paid', $downpayment['paid_downpayment']]);
    $pdf->TableRow(['Downpayment Not Paid', $downpayment['didnt_pay_downpayment']]);
    $pdf->TableRow(['Full Payment', $fullPaid]);
    $pdf->TableRow(['Installment', $installment]);
    $pdf->Ln(10);
    
    // Installment Months
    $pdf->SectionTitle('Most Common Installment Due Months');
    $pdf->TableHeader(['Month', 'Count']);
    foreach ($installmentMonths as $month) {
        $pdf->TableRow([$month['month'], $month['count']]);
    }
    
    // Output PDF
    ob_end_clean();
    $pdf->Output('cashier_report.pdf', 'I');
    
} catch (Exception $e) {
    ob_end_clean();
    die('Error generating report: ' . $e->getMessage());
}
?>