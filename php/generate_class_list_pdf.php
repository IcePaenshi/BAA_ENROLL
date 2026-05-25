<?php
ini_set('display_errors', 0);
error_reporting(0);
ob_start();
session_start();
require_once 'db.php';
require('../fpdf/fpdf.php');

class PDF extends FPDF {
    public $teacherName = 'Teacher';
    public $filterInfo = '';

    // Page header
    function Header() {
        if ($this->PageNo() > 1) {
            $this->SetY(15);
            return;
        }

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
        
        // Line separator - blue line
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
}

// Check user role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    ob_end_clean();
    die('Unauthorized access');
}

try {
    $teacherId = $_SESSION['user_id'];
    
    // Fetch teacher name
    $teacherNameStmt = $pdo->prepare("SELECT " . baa_full_name_sql() . " AS full_name FROM users WHERE id = ?");
    $teacherNameStmt->execute([$teacherId]);
    $teacherName = $teacherNameStmt->fetchColumn() ?: 'Teacher';
    
    // Get teacher sections
    $subjStmt = $pdo->prepare("
            SELECT MIN(s.id) AS id, s.grade_level, s.section
            FROM subjects s
            JOIN teacher_subjects ts ON s.id = ts.subject_id
            WHERE ts.teacher_id = ?
            GROUP BY s.grade_level, s.section
        ");
    $subjStmt->execute([$teacherId]);
    $teacherSections = $subjStmt->fetchAll(PDO::FETCH_ASSOC);

    $students = [];
    $filterDesc = [];

    if (!empty($teacherSections)) {
        $conditions = [];
        $params = [];
        foreach ($teacherSections as $sec) {
            $conditions[] = "(section = ? AND (grade_level = ? OR REPLACE(grade_level, 'Grade ', '') = REPLACE(?, 'Grade ', '')))";
            $params[] = $sec['section'];
            $params[] = $sec['grade_level'];
            $params[] = $sec['grade_level'];
        }
        
        $baseSql = "SELECT id, first_name, middle_name, last_name, suffix, username, email, grade_level, section, lrn, phone, gender, birthdate, status, created_at
                    FROM users 
                    WHERE role = 'student' AND (" . implode(" OR ", $conditions) . ")";

        $filterConditions = [];
        
        // Grades filter
        if (isset($_GET['grades']) && $_GET['grades'] !== '') {
            $gradeList = explode(',', $_GET['grades']);
            $gradePlaceholders = implode(',', array_fill(0, count($gradeList), '?'));
            $filterConditions[] = "grade_level IN ($gradePlaceholders)";
            $params = array_merge($params, $gradeList);
            $filterDesc[] = "Grades: " . implode(', ', $gradeList);
        }
        
        // Sections filter
        if (isset($_GET['sections']) && $_GET['sections'] !== '') {
            $sectionList = explode(',', $_GET['sections']);
            $sectionPlaceholders = implode(',', array_fill(0, count($sectionList), '?'));
            $filterConditions[] = "section IN ($sectionPlaceholders)";
            $params = array_merge($params, $sectionList);
            $filterDesc[] = "Sections: " . implode(', ', $sectionList);
        }
        
        // Search filter
        if (isset($_GET['search']) && $_GET['search'] !== '') {
            $searchTerm = '%' . $_GET['search'] . '%';
            $filterConditions[] = "(first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR username LIKE ? OR lrn LIKE ?)";
            $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
            $filterDesc[] = "Search: '" . $_GET['search'] . "'";
        }

        if (!empty($filterConditions)) {
            $baseSql .= " AND " . implode(" AND ", $filterConditions);
        }

        // Sorting
        $sort = isset($_GET['sort']) ? $_GET['sort'] : 'name';
        if ($sort === 'grade') {
            $baseSql .= " ORDER BY grade_level, section, last_name, first_name";
            $filterDesc[] = "Sorted by Grade";
        } else if ($sort === 'lrn') {
            $baseSql .= " ORDER BY lrn, last_name, first_name";
            $filterDesc[] = "Sorted by LRN";
        } else {
            $baseSql .= " ORDER BY last_name, first_name";
            $filterDesc[] = "Sorted by Name";
        }

        $studentStmt = $pdo->prepare($baseSql);
        $studentStmt->execute($params);
        $students = $studentStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Create PDF
    $pdf = new PDF();
    $pdf->teacherName = $teacherName;
    $pdf->AliasNbPages();
    $pdf->AddPage();
    $pdf->SetMargins(15, 15, 15);
    
    // Header title
    $pdf->SetFont('Arial', 'B', 16);
    $pdf->SetTextColor(10, 45, 99);
    $pdf->Cell(0, 10, 'CLASS LIST REPORT', 0, 1, 'C');
    
    // Filter info summary
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetTextColor(80, 80, 80);
    $filterText = 'Filters: ' . (empty($filterDesc) ? 'None' : implode(' | ', $filterDesc));
    $pdf->Cell(0, 5, $filterText, 0, 1, 'C');
    $pdf->Ln(5);
    
    // Table Headers
    // Printable width = 180mm. 
    // Cols: # (10mm), Name (60mm), LRN (30mm), Grade & Section (40mm), Email (40mm)
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetFillColor(10, 45, 99);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(10, 8, '#', 1, 0, 'C', true);
    $pdf->Cell(60, 8, 'Name', 1, 0, 'C', true);
    $pdf->Cell(30, 8, 'LRN', 1, 0, 'C', true);
    $pdf->Cell(40, 8, 'Grade & Section', 1, 0, 'C', true);
    $pdf->Cell(40, 8, 'Email', 1, 1, 'C', true);
    
    // Table Data
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetTextColor(0, 0, 0);
    
    $index = 1;
    if (empty($students)) {
        $pdf->Cell(180, 10, 'No students found matching current filters.', 1, 1, 'C');
    } else {
        foreach ($students as $row) {
            $nameParts = [$row['first_name'], $row['middle_name'], $row['last_name'], $row['suffix']];
            $nameParts = array_map(function($p) {
                return ($p && strtoupper($p) === 'N/A') ? '' : $p;
            }, $nameParts);
            $nameParts = array_filter($nameParts);
            $fullName = implode(' ', $nameParts);
            if (empty($fullName)) {
                $fullName = 'N/A';
            }
            
            $gs = $row['grade_level'] ? $row['grade_level'] . ($row['section'] ? ' - ' . $row['section'] : '') : '—';
            $lrnVal = $row['lrn'] ? $row['lrn'] : '—';
            $emailVal = $row['email'] ? $row['email'] : '—';
            
            // Highlight background on alternating rows
            $fill = ($index % 2 === 0);
            if ($fill) {
                $pdf->SetFillColor(245, 247, 250);
            } else {
                $pdf->SetFillColor(255, 255, 255);
            }
            
            $pdf->Cell(10, 8, $index, 1, 0, 'C', true);
            
            // Truncate name if it's too long
            $displayName = $fullName;
            if ($pdf->GetStringWidth($displayName) > 58) {
                while ($pdf->GetStringWidth($displayName . '...') > 58 && strlen($displayName) > 0) {
                    $displayName = substr($displayName, 0, -1);
                }
                $displayName .= '...';
            }
            $pdf->Cell(60, 8, $displayName, 1, 0, 'L', true);
            $pdf->Cell(30, 8, $lrnVal, 1, 0, 'C', true);
            $pdf->Cell(40, 8, $gs, 1, 0, 'C', true);
            
            // Truncate email if too long
            $displayEmail = $emailVal;
            if ($pdf->GetStringWidth($displayEmail) > 38) {
                while ($pdf->GetStringWidth($displayEmail . '...') > 38 && strlen($displayEmail) > 0) {
                    $displayEmail = substr($displayEmail, 0, -1);
                }
                $displayEmail .= '...';
            }
            $pdf->Cell(40, 8, $displayEmail, 1, 1, 'L', true);
            
            $index++;
        }
    }
    
    // Output PDF
    ob_end_clean();
    $pdf->Output('class_list.pdf', 'I');
    
} catch (Exception $e) {
    ob_end_clean();
    die('Error generating class list report: ' . $e->getMessage());
}
?>
