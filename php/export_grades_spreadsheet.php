<?php
session_start();
header('Content-Type: application/json');

require '../vendor/autoload.php';
require 'db.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'registrar'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$studentId = $_GET['student_id'] ?? null;
if (!$studentId) {
    echo json_encode(['success' => false, 'message' => 'Student ID is required']);
    exit;
}

try {
    // 1. Fetch Student Info
    $stmt = $pdo->prepare("SELECT id, " . baa_full_name_sql() . " AS full_name, lrn, age, gender, grade_level, section, strand FROM users WHERE id = ?");
    $stmt->execute([$studentId]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        throw new Exception("Student not found");
    }

    // 2. Fetch Attendance (Entire Year)
    $attStmt = $pdo->prepare("SELECT status, COUNT(*) as count FROM attendance WHERE student_id = ? GROUP BY status");
    $attStmt->execute([$studentId]);
    $attendanceData = $attStmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $present = ($attendanceData['present'] ?? 0) + ($attendanceData['late'] ?? 0);
    $absent = $attendanceData['absent'] ?? 0;

    // 3. Fetch Teacher Name (Class Advisor)
    // We'll look for a teacher associated with a subject in the student's section/grade
    $teacherStmt = $pdo->prepare("
        SELECT DISTINCT " . baa_full_name_sql('u') . " AS teacher_name
        FROM users u
        JOIN teacher_subjects ts ON u.id = ts.teacher_id
        JOIN subjects s ON ts.subject_id = s.id
        WHERE u.role = 'teacher' AND s.grade_level = ? AND s.section = ?
        LIMIT 1
    ");
    $teacherStmt->execute([$student['grade_level'], $student['section']]);
    $teacherName = $teacherStmt->fetchColumn() ?: 'N/A';

    // 4. Fetch Grades
    $gradeStmt = $pdo->prepare("
        SELECT s.subject_name, g.semester, g.calculated_grade 
        FROM trimester_grades g 
        JOIN subjects s ON g.subject_id = s.id 
        WHERE g.student_id = ? 
        ORDER BY s.subject_name, g.semester
    ");
    $gradeStmt->execute([$studentId]);
    $gradesRaw = $gradeStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $subjectGrades = [];
    foreach ($gradesRaw as $g) {
        $subjectGrades[$g['subject_name']][$g['semester']] = $g['calculated_grade'];
    }

    // 5. Load Template
    $isSHS = in_array($student['grade_level'], ['Grade 11', 'Grade 12']);
    $templateFile = $isSHS ? '../images/SHS Template.xlsx' : '../images/JHS Template.xlsx';
    
    if (!file_exists($templateFile)) {
        throw new Exception("Template file not found: " . $templateFile);
    }

    $spreadsheet = IOFactory::load($templateFile);
    
    // --- FACE TAB ---
    // Specifically targeting names found in debug: 'SF9 FACE'
    $faceSheet = $spreadsheet->getSheetByName('SF9 FACE') ?: $spreadsheet->getSheet(0);
    
    // Name: U-AF 15
    $faceSheet->setCellValue('U15', $student['full_name']);
    // LRN: Z-AF 16
    $lrnValue = $student['lrn'] ?: '';
    $faceSheet->setCellValueExplicit('Z16', (string)$lrnValue, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);

    // Age: U-V 17
    $faceSheet->setCellValue('U17', $student['age'] ?: '');

    // Sex: AB-AF 17 (Using M/F for better fit)
    $sex = $student['gender'] ? strtoupper(substr($student['gender'], 0, 1)) : '';
    $faceSheet->setCellValue('AB17', $sex);

    // Grade: U-W 18 (Spanning U, V, W as requested)
    $gradeVal = $student['grade_level'] ?: '';
    $faceSheet->setCellValue('U18', $gradeVal);
    try {
        // Attempt to merge or ensure it spans to W18
        $faceSheet->mergeCells('U18:W18');
        $faceSheet->getStyle('U18')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT);
    } catch (Exception $e) {}

    // Section: AB-AF 18
    $faceSheet->setCellValue('AB18', $student['section'] ?: '');
    // Class Advisor: Y-AF 27
    $faceSheet->setCellValue('Y27', $teacherName);
    
    // Attendance
    // Days Present: D-N 16-17
    $faceSheet->setCellValue('D16', $present);
    // Days Absent: D-N 18-19
    $faceSheet->setCellValue('D18', $absent);

    // Progression Logic
    $currentGrade = $student['grade_level'];
    $nextGrade = 'N/A';
    if (preg_match('/Grade (\d+)/i', $currentGrade, $matches)) {
        $num = (int)$matches[1];
        if ($num < 12) $nextGrade = 'Grade ' . ($num + 1);
        else $nextGrade = 'College / Graduate';
    }
    
    // Admitted to Grade: W-AA 31
    $faceSheet->setCellValue('W31', $currentGrade);
    // Section (transfer): AD-AF 31 (Random suffix)
    $faceSheet->setCellValue('AD31', 'Next ' . chr(65 + rand(0, 5)));
    // Eligibility for Admission: Y-AF 32
    $faceSheet->setCellValue('Y32', $nextGrade);

    // --- INSIDE TAB ---
    // Specifically targeting names found in debug: 'SF9 IN'
    $insideSheet = $spreadsheet->getSheetByName('SF9 IN') ?: $spreadsheet->getSheet(1);
    
    if (!$insideSheet || $insideSheet === $faceSheet) {
        // Fallback for single-sheet templates or different naming
        $insideSheet = $spreadsheet->getSheetByName('Inside') ?: $spreadsheet->getSheet(0);
    }
    
    if ($insideSheet) {
        $currentRow = 10; // Grades start at row 10 (F10, G10, H10, J10)
        $totalGradesSum = 0;
        $totalValidGradesCount = 0;

        foreach ($subjectGrades as $subjName => $sems) {
            if ($currentRow > 23) break;
            
            // Subjects: B10-23 (aligned with grades)
            $insideSheet->setCellValue('B' . $currentRow, $subjName);

            $sem1 = isset($sems[1]) ? (float)$sems[1] : null;
            $sem2 = isset($sems[2]) ? (float)$sems[2] : null;
            $sem3 = isset($sems[3]) ? (float)$sems[3] : null;

            $insideSheet->setCellValue('F' . $currentRow, $sem1);
            $insideSheet->setCellValue('G' . $currentRow, $sem2);
            $insideSheet->setCellValue('H' . $currentRow, $sem3);

            // Final Grade: J 10-23
            $rowSum = 0;
            $rowCount = 0;
            if ($sem1 !== null) { $rowSum += $sem1; $rowCount++; }
            if ($sem2 !== null) { $rowSum += $sem2; $rowCount++; }
            if ($sem3 !== null) { $rowSum += $sem3; $rowCount++; }
            
            if ($rowCount > 0) {
                $finalGrade = $rowSum / $rowCount;
                $insideSheet->setCellValue('J' . $currentRow, round($finalGrade, 2));
                $totalGradesSum += $finalGrade;
                $totalValidGradesCount++;
            }

            $currentRow++;
        }

        // General Average: J 24
        if ($totalValidGradesCount > 0) {
            $genAvg = $totalGradesSum / $totalValidGradesCount;
            $insideSheet->setCellValue('J24', round($genAvg, 2));
        }
    }

    // 6. Download
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="Grades_' . preg_replace('/[^a-zA-Z0-9]/', '_', $student['full_name']) . '.xlsx"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;

} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
