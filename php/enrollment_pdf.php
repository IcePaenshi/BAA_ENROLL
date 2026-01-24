<?php
ini_set('display_errors', 0);
error_reporting(0);
ob_start();
session_start();
require_once 'db.php';
require('../fpdf/fpdf.php');

class PDF extends FPDF {
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
        $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb} | Generated on ' . date('Y-m-d H:i:s'), 0, 0, 'C');
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
    
    // Detail line
    function DetailLine($label, $value, $indent = 0) {
        $this->SetX($this->GetX() + $indent);
        $this->SetFont('Arial', 'B', 10);
        $this->SetTextColor(0, 0, 0);
        $this->Cell(50, 6, $label . ':', 0, 0);
        $this->SetFont('Arial', '', 10);
        $this->SetTextColor(0, 0, 0);
        $this->Cell(0, 6, $value, 0, 1);
    }
    
    // Generate enrollment number
    function GenerateEnrollmentNumber($id) {
        return 'ENR-' . date('Y') . '-' . str_pad($id, 6, '0', STR_PAD_LEFT);
    }
}

// Check if enrollment ID is provided
if (!isset($_GET['enrollment_id'])) {
    ob_end_clean();
    die('Enrollment ID is required');
}

$enrollmentId = intval($_GET['enrollment_id']);

try {
    // Fetch enrollment data
    $stmt = $pdo->prepare("
        SELECT e.*, 
               DATE_FORMAT(e.created_at, '%M %d, %Y %h:%i %p') as formatted_date,
               DATE_FORMAT(e.birthdate, '%M %d, %Y') as formatted_birthdate
        FROM enrollments e
        WHERE e.id = ?
    ");
    $stmt->execute([$enrollmentId]);
    $enrollment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$enrollment) {
        ob_end_clean();
        die('Enrollment record not found');
    }
    
    // Fetch uploaded documents
    $docStmt = $pdo->prepare("
        SELECT * FROM enrollment_documents 
        WHERE enrollment_id = ? 
        ORDER BY created_at
    ");
    $docStmt->execute([$enrollmentId]);
    $documents = $docStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Create PDF
    $pdf = new PDF();
    $pdf->AliasNbPages();
    $pdf->AddPage();
    $pdf->SetMargins(15, 15, 15);
    
    // Title
    $pdf->SetFont('Arial', 'B', 20);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(0, 15, 'STUDENT ENROLLMENT FORM', 0, 1, 'C');
    $pdf->Ln(10);
    
    // Enrollment Information
    $pdf->SectionTitle('Enrollment Information');
    $enrollmentNumber = $pdf->GenerateEnrollmentNumber($enrollment['id']);
    $pdf->DetailLine('Enrollment ID', $enrollmentNumber);
    $pdf->DetailLine('Application Date', $enrollment['formatted_date']);
    $pdf->DetailLine('Status', ucfirst($enrollment['status']));
    $pdf->Ln(8);
    
    // Student Information
    $pdf->SectionTitle('Student Information');
    $pdf->DetailLine('Full Name', $enrollment['full_name']);
    $pdf->DetailLine('Age', $enrollment['age'] . ' years old');
    $pdf->DetailLine('Gender', $enrollment['gender']);
    $pdf->DetailLine('Birthdate', $enrollment['formatted_birthdate']);
    $pdf->DetailLine('Email Address', $enrollment['email']);
    $pdf->DetailLine('Phone Number', $enrollment['phone']);
    $pdf->Ln(8);
    
    // Additional Information
    if (!empty($enrollment['preferred_grade']) || !empty($enrollment['preferred_section'])) {
        $pdf->SectionTitle('Enrollment Preferences');
        
        if (!empty($enrollment['preferred_grade'])) {
            $pdf->DetailLine('Preferred Grade', $enrollment['preferred_grade']);
        }
        
        if (!empty($enrollment['preferred_section'])) {
            $pdf->DetailLine('Preferred Section', $enrollment['preferred_section']);
        }
        
        if (!empty($enrollment['remarks'])) {
            $pdf->Ln(3);
            $pdf->SetFont('Arial', 'B', 10);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->Cell(50, 6, 'Remarks:', 0, 0);
            $pdf->SetFont('Arial', '', 10);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->MultiCell(0, 6, $enrollment['remarks']);
        }
        $pdf->Ln(8);
    }
    
    // Submitted Documents
    $pdf->SectionTitle('Submitted Documents');
    
    if (!empty($documents)) {
        $counter = 1;
        foreach ($documents as $doc) {
            $fileSize = isset($doc['file_size']) ? round($doc['file_size'] / 1024, 2) : 0;
            $uploadDate = isset($doc['created_at']) ? date('M d, Y', strtotime($doc['created_at'])) : 'N/A';
            
            $pdf->SetFont('Arial', '', 10);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->Cell(5, 6, $counter . '.', 0, 0);
            $pdf->SetFont('Arial', 'B', 10);
            $pdf->SetTextColor(0, 0, 0);
            $fileName = 'Document ' . $counter;
            if (isset($doc['file_name']) && !empty($doc['file_name'])) {
                $fileName = $doc['file_name'];
            } elseif (isset($doc['document_filename']) && !empty($doc['document_filename'])) {
                $fileName = $doc['document_filename'];
            }
            
            $pdf->Cell(60, 6, $fileName, 0, 0);
            
            $pdf->SetFont('Arial', '', 9);
            $pdf->SetTextColor(100, 100, 100);
            $pdf->Cell(0, 6, "({$fileSize} KB)", 0, 1);
            
            $pdf->SetX(20);
            $pdf->SetFont('Arial', 'I', 9);
            $pdf->SetTextColor(0, 0, 0);
            $docType = 'Document';
            if (isset($doc['document_type']) && !empty($doc['document_type'])) {
                $docType = ucfirst(str_replace('_', ' ', $doc['document_type']));
            } elseif (isset($doc['type']) && !empty($doc['type'])) {
                $docType = ucfirst(str_replace('_', ' ', $doc['type']));
            }
            
            $pdf->Cell(0, 5, 'Type: ' . $docType, 0, 1);
            
            $pdf->Ln(2);
            $counter++;
        }
    } else {
        $pdf->SetFont('Arial', 'I', 10);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(0, 6, 'No documents submitted', 0, 1);
    }
    $pdf->Ln(10);
    
    // Terms and Conditions
    $pdf->SectionTitle('Important Notes');
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->MultiCell(0, 5, '1. This document serves as official proof of enrollment application.');
    $pdf->MultiCell(0, 5, '2. Enrollment approval is subject to verification of submitted documents.');
    $pdf->MultiCell(0, 5, '3. Please keep this receipt for future reference and inquiries.');
    $pdf->MultiCell(0, 5, '4. Official confirmation will be sent via email upon approval.');
    $pdf->MultiCell(0, 5, '5. For any questions, contact the Registrar\'s Office.');
    $pdf->Ln(10);
    
    // Signature
    $pdf->SetFont('Arial', '', 10);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(0, 5, '_________________________', 0, 1, 'R');
    $pdf->Cell(0, 5, 'Registrar\'s Office Signature', 0, 1, 'R');
    $pdf->SetFont('Arial', 'I', 9);
    $pdf->Cell(0, 5, 'Baesa Adventist Academy', 0, 1, 'R');
    
    // Clean buffer before PDF output
    if (ob_get_length()) {
        ob_clean();
    }

    $filename = "Enrollment_Form_" . $enrollmentNumber . ".pdf";
    $pdf->Output('D', $filename);
    exit;
    
} catch (PDOException $e) {
    // If there's an error, output it cleanly
    while (ob_get_level()) {
        ob_end_clean();
    }
    echo "Error generating PDF: " . htmlspecialchars($e->getMessage());
    exit();
} catch (Exception $e) {
    // If there's an error, output it cleanly
    while (ob_get_level()) {
        ob_end_clean();
    }
    echo "Error: " . htmlspecialchars($e->getMessage());
    exit();
}
?>