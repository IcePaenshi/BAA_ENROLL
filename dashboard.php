<?php
session_start();
ini_set('display_errors', 0);

try {
    require_once 'php/db.php';
} catch (Exception $e) {
    die("Database connection error. Please try again later.");
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// Validate user status (check if they've been deactivated)
try {
    $statusStmt = $pdo->prepare("SELECT status FROM users WHERE id = ? LIMIT 1");
    $statusStmt->execute([$_SESSION['user_id']]);
    $userStatus = $statusStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$userStatus || $userStatus['status'] == 0) {
        // User is inactive or deleted
        session_destroy();
        header('Location: index.php?logout=inactive');
        exit();
    }
} catch (Exception $e) {
    // Database error, allow user to proceed but they'll be checked via AJAX
}

$userRole = $_SESSION['role'];
$userId = $_SESSION['user_id'];
$fullName = $_SESSION['full_name'];
$gradeLevel = $_SESSION['grade_level'] ?? '';
$section = $_SESSION['section'] ?? '';
$lrn = $_SESSION['lrn'] ?? '';
$userName = isset($_SESSION['username']) ? $_SESSION['username'] : $fullName;
$firstName = $_SESSION['first_name'] ?? '';
if (empty($firstName) && !empty($fullName)) {
    $parts = preg_split('/\s+/', trim($fullName));
    $firstName = $parts[0] ?? '';
}

// Get user-specific data based on role
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// ==================== TEACHER DATA ====================
$teacherSubjects = [];
$teacherSections = [];
$studentsList = [];
$allTeacherStudents = [];
$studentGrades = [];
$disciplinary = [];
$extracurricular = [];
$attendanceToday = [];
$presentToday = 0;
$lateToday = 0;
$absentToday = 0;
$subjectList = [];

if ($userRole == 'teacher') {
    $subjStmt = $pdo->prepare("
            SELECT MIN(s.id) AS id, s.subject_name, s.subject_code, s.grade_level, s.section
            FROM subjects s
            JOIN teacher_subjects ts ON s.id = ts.subject_id
            WHERE ts.teacher_id = ?
            GROUP BY s.subject_name, s.subject_code, s.grade_level, s.section
            ORDER BY s.grade_level, s.section, s.subject_name
        ");
    $subjStmt->execute([$userId]);
    $teacherSubjects = $subjStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $sections = [];
    foreach ($teacherSubjects as $subj) {
        $key = $subj['grade_level'] . ' - ' . $subj['section'];
        $sections[$key] = ['grade_level' => $subj['grade_level'], 'section' => $subj['section']];
    }
    $teacherSections = array_values($sections);

    if (!empty($teacherSections)) {
        $conditions = [];
        $params = [];
        foreach ($teacherSections as $sec) {
            $conditions[] = "(section = ? AND (grade_level = ? OR REPLACE(grade_level, 'Grade ', '') = REPLACE(?, 'Grade ', '')))";
            $params[] = $sec['section'];
            $params[] = $sec['grade_level'];
            $params[] = $sec['grade_level'];
        }
        $sql = "SELECT id, " . baa_full_name_sql() . " AS full_name, username, email, grade_level, section, lrn, gender, birthdate, phone, status, created_at FROM users WHERE role = 'student' AND (" . implode(" OR ", $conditions) . ") ORDER BY grade_level, section, full_name";
        $studentStmt = $pdo->prepare($sql);
        $studentStmt->execute($params);
        $allTeacherStudents = $studentStmt->fetchAll(PDO::FETCH_ASSOC);
        $studentsList = $allTeacherStudents;
        $studentIds = array_column($allTeacherStudents, 'id');

        if (!empty($studentIds)) {
            $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
            $teacherSubjectIds = array_column($teacherSubjects, 'id');
            if (!empty($teacherSubjectIds)) {
                $subjectPlaceholders = implode(',', array_fill(0, count($teacherSubjectIds), '?'));
                $gradeStmt = $pdo->prepare("
                    SELECT tg.student_id, s.subject_name, tg.calculated_grade as grade, tg.semester as quarter 
                    FROM trimester_grades tg
                    JOIN subjects s ON tg.subject_id = s.id
                    WHERE tg.student_id IN ($placeholders)
                    AND tg.subject_id IN ($subjectPlaceholders)
                    ORDER BY tg.student_id, s.subject_name, tg.semester
                ");
                $gradeStmt->execute(array_merge($studentIds, $teacherSubjectIds));
                $gradesRaw = $gradeStmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($gradesRaw as $g) {
                    $studentGrades[$g['student_id']][$g['subject_name']]['grades'][$g['quarter']] = $g['grade'];
                }
            }
            foreach ($studentGrades as $sid => $subjects) {
                foreach ($subjects as $subjName => $data) {
                    $total = 0;
                    $cnt = 0;
                    foreach ($data['grades'] as $grade) {
                        $total += $grade;
                        $cnt++;
                    }
                    $studentGrades[$sid][$subjName]['average'] = $cnt > 0 ? round($total / $cnt) : 0;
                }
            }
            $discStmt = $pdo->prepare("SELECT student_id, suspension_count, suspension_dates, reason FROM disciplinary WHERE student_id IN ($placeholders)");
            $discStmt->execute($studentIds);
            foreach ($discStmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
                $disciplinary[$d['student_id']] = $d;
            }
            $extraStmt = $pdo->prepare("SELECT student_id, activity_name FROM extracurricular WHERE student_id IN ($placeholders)");
            $extraStmt->execute($studentIds);
            foreach ($extraStmt->fetchAll(PDO::FETCH_ASSOC) as $e) {
                $extracurricular[$e['student_id']][] = $e['activity_name'];
            }
            $today = date('Y-m-d');
            $attStmt = $pdo->prepare("SELECT student_id, status FROM attendance WHERE date = ? AND student_id IN ($placeholders)");
            $attStmt->execute(array_merge([$today], $studentIds));
            foreach ($attStmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
                $attendanceToday[$a['student_id']] = $a['status'];
            }
            foreach ($studentsList as $stu) {
                $status = $attendanceToday[$stu['id']] ?? null;
                if ($status === 'present') $presentToday++;
                elseif ($status === 'late') $lateToday++;
                elseif ($status === 'absent') $absentToday++;
            }
        }
    }
}

// Get official submitted grades for student
if ($userRole == 'student') {
    try {
        // Use trimester_grades for official submitted results
        $gradesStmt = $pdo->prepare("
            SELECT s.subject_name, tg.calculated_grade as grade, tg.semester as quarter
            FROM trimester_grades tg 
            JOIN subjects s ON tg.subject_id = s.id 
            WHERE tg.student_id = ? 
            AND tg.is_submitted = 1
            ORDER BY s.subject_name, tg.semester
        ");
        $gradesStmt->execute([$userId]);
        $grades = $gradesStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching official grades: " . $e->getMessage());
        $grades = [];
    }

    // Check payment status for grade visibility
    $isFullyPaid = true;
    $remainingBalance = 0;
    try {
        require_once 'php/get_fee_breakdown.php';
        
        $gradeLevelVal = (string)($user['grade_level'] ?? '');
        $paymentPlanMonths = (int)($user['payment_plan_months'] ?? 4);
        if ($paymentPlanMonths < 1) $paymentPlanMonths = 4;
        
        $feeTotal = 0.0;
        if ($gradeLevelVal !== '') {
            $b = baa_get_fee_breakdown($pdo, $gradeLevelVal);
            if ($b) $feeTotal = baa_fee_total($b);
        }

        // Manual payables
        $stmtM = $pdo->prepare("SELECT SUM(amount) FROM payables WHERE student_id = ? AND item_name NOT LIKE 'Tuition%' AND item_name NOT LIKE 'Misc%' AND item_name NOT LIKE 'Aircon%' AND item_name NOT LIKE 'HSA%' AND item_name NOT LIKE 'Books%'");
        $stmtM->execute([$userId]);
        $manualPayablesTotal = (float)($stmtM->fetchColumn() ?: 0);

        $overallTotalFees = $feeTotal + $manualPayablesTotal;

        // Downpayments
        $downpaymentTotal = 0.0;
        $studentKey = (string)($user['student_id'] ?? '');
        if (preg_match('/^ENR-(\d+)$/', $studentKey, $m)) {
            $sumStmt = $pdo->prepare("SELECT SUM(amount) FROM enrollment_downpayments WHERE enrollment_id = ?");
            $sumStmt->execute([(int)$m[1]]);
            $downpaymentTotal += (float)($sumStmt->fetchColumn() ?: 0);
        }
        $sdStmt = $pdo->prepare("SELECT SUM(amount) FROM student_downpayments WHERE student_id = ?");
        $sdStmt->execute([$userId]);
        $downpaymentTotal += (float)($sdStmt->fetchColumn() ?: 0);

        // Payments
        $paidStmt = $pdo->prepare("SELECT SUM(amount) FROM payments WHERE student_id = ?");
        $paidStmt->execute([$userId]);
        $totalPaid = (float)($paidStmt->fetchColumn() ?: 0);

        // Discounts
        $discStmt = $pdo->prepare("SELECT SUM(amount) FROM student_discounts WHERE student_id = ?");
        $discStmt->execute([$userId]);
        $discountsTotal = (float)($discStmt->fetchColumn() ?: 0);

        $totalDeductions = $downpaymentTotal + $totalPaid + $discountsTotal;
        $remainingBalance = max(0, $overallTotalFees - $totalDeductions);
        
        // Threshold for "Fully Paid": less than 1 unit of currency (allowing for tiny rounding errors)
        $isFullyPaid = ($remainingBalance < 1.0);
    } catch (Exception $e) {
        error_log("Balance check error: " . $e->getMessage());
    }
}

// Set timezone to GMT+8
date_default_timezone_set('Asia/Manila');
$currentTime = date('h:i:s A');
$currentDate = date('F j, Y');
$currentDay = date('l');
$currentDayShort = date('D');

// Get all subjects for student's grade and section
$allSubjects = [];
$todaySubjects = [];

if ($userRole == 'student' && !empty($gradeLevel) && !empty($section)) {
    try {
        $subjectsStmt = $pdo->prepare("
            SELECT 
                subject_code,
                subject_name,
                grade_level,
                section,
                day_of_week,
                start_time,
                end_time,
                semester,
                CONCAT(day_of_week, ', ', DATE_FORMAT(start_time, '%h:%i %p'), ' - ', DATE_FORMAT(end_time, '%h:%i %p')) as schedule,
                DATE_FORMAT(start_time, '%h:%i %p') as start_time_formatted,
                DATE_FORMAT(end_time, '%h:%i %p') as end_time_formatted
            FROM subjects 
            WHERE grade_level = ? AND section = ?
            ORDER BY subject_name, 
                FIELD(day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'),
                start_time
        ");
        $subjectsStmt->execute([$gradeLevel, $section]);
        $subjects = $subjectsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Group subjects by name and separate today's subjects
        foreach ($subjects as $subject) {
            $subjectName = $subject['subject_name'];
            
            // Group all subjects by name
            if (!isset($allSubjects[$subjectName])) {
                $allSubjects[$subjectName] = [
                    'subject_code' => $subject['subject_code'],
                    'subject_name' => $subject['subject_name'],
                    'grade_level' => $subject['grade_level'],
                    'section' => $subject['section'],
                    'semester' => $subject['semester'],
                    'schedules' => []
                ];
            }
            
            // Add schedule to the subject
            $allSubjects[$subjectName]['schedules'][] = [
                'day_of_week' => $subject['day_of_week'],
                'start_time' => $subject['start_time'],
                'end_time' => $subject['end_time'],
                'schedule' => $subject['schedule'],
                'start_time_formatted' => $subject['start_time_formatted'],
                'end_time_formatted' => $subject['end_time_formatted']
            ];

            // Check if this subject is scheduled for today
            if (strtolower($subject['day_of_week']) == strtolower($currentDay) || 
                strtolower($subject['day_of_week']) == strtolower($currentDayShort)) {
                $todaySubjects[$subjectName] = $subject;
            }
        }
        
    } catch (PDOException $e) {
        error_log("Error fetching subjects: " . $e->getMessage());
        $subjects = [];
        $allSubjects = [];
        $todaySubjects = [];
    }
}

// Pre-process subjects for grouped display with day abbreviations
$groupedSubjectsForDisplay = [];

if (!empty($allSubjects)) {
    $dayMap = [
        'Monday' => 'M',
        'Tuesday' => 'T',
        'Wednesday' => 'W',
        'Thursday' => 'Th',
        'Friday' => 'F',
        'Saturday' => 'Sa',
        'Sunday' => 'Su'
    ];

    foreach ($allSubjects as $subjectName => $subjectData) {
        $scheduleStrings = [];
        foreach ($subjectData['schedules'] as $schedule) {
            $dayAbbr = $dayMap[$schedule['day_of_week']] ?? $schedule['day_of_week'];
            $timeRange = $schedule['start_time_formatted'] . ' - ' . $schedule['end_time_formatted'];
            $scheduleStrings[] = $dayAbbr . ' ' . $timeRange;
        }
        $groupedSubjectsForDisplay[] = [
            'subject_name' => $subjectData['subject_name'],
            'schedules_display' => implode('<br>', $scheduleStrings)
        ];
    }
}

// Get events from database - only show 15 days ahead
try {
    $eventsStmt = $pdo->prepare("
        SELECT * FROM events 
        WHERE event_date >= CURDATE() 
        AND event_date <= DATE_ADD(CURDATE(), INTERVAL 15 DAY)
        AND event_date IS NOT NULL
        ORDER BY event_date ASC 
        LIMIT 20
    ");
    $eventsStmt->execute();
    $events = $eventsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // If no events in next 15 days, show the next 5 upcoming events
    if (empty($events)) {
        $eventsStmt = $pdo->prepare("
            SELECT * FROM events 
            WHERE event_date >= CURDATE()
            AND event_date IS NOT NULL
            ORDER BY event_date ASC 
            LIMIT 5
        ");
        $eventsStmt->execute();
        $events = $eventsStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log("Error fetching events: " . $e->getMessage());
    $events = [];
}

// STATS for admin, registrar, and cashier
if ($userRole === 'admin' || $userRole === 'registrar' || $userRole === 'cashier') {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM enrollments WHERE status = 'pending'");
        $newRequests = $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'student' AND grade_level IN ('Grade 7','Grade 8','Grade 9','Grade 10')");
        $stmt->execute();
        $grades7to10 = $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'student' AND grade_level IN ('Grade 11','Grade 12')");
        $stmt->execute();
        $grades11to12 = $stmt->fetchColumn();

        $stmt = $pdo->query("SELECT COUNT(*) FROM enrollments");
        $totalEnrollments = $stmt->fetchColumn();
        
        if ($userRole === 'cashier') {
            $currentYear = date('Y');
            
            // New students (HS/SHS)
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM enrollments WHERE status='accepted' AND student_type='New Student' AND YEAR(created_at) = ? AND grade_level IN ('Grade 7','Grade 8','Grade 9','Grade 10')");
            $stmt->execute([$currentYear]);
            $cashierNewHS = $stmt->fetchColumn() ?: 0;

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM enrollments WHERE status='accepted' AND student_type='New Student' AND YEAR(created_at) = ? AND grade_level IN ('Grade 11','Grade 12')");
            $stmt->execute([$currentYear]);
            $cashierNewSHS = $stmt->fetchColumn() ?: 0;

            // Transferees
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM enrollments WHERE status='accepted' AND student_type='Transferee' AND YEAR(created_at) = ?");
            $stmt->execute([$currentYear]);
            $cashierTransferees = $stmt->fetchColumn() ?: 0;

            // Old Students (Total Students minus new students and transferees of this year)
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM enrollments WHERE status='accepted' AND student_type='Transferee' AND YEAR(created_at) = ? AND grade_level IN ('Grade 7','Grade 8','Grade 9','Grade 10')");
            $stmt->execute([$currentYear]);
            $transfereesHS = $stmt->fetchColumn() ?: 0;

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM enrollments WHERE status='accepted' AND student_type='Transferee' AND YEAR(created_at) = ? AND grade_level IN ('Grade 11','Grade 12')");
            $stmt->execute([$currentYear]);
            $transfereesSHS = $stmt->fetchColumn() ?: 0;

            $cashierOldHS = max(0, $grades7to10 - $cashierNewHS - $transfereesHS);
            $cashierOldSHS = max(0, $grades11to12 - $cashierNewSHS - $transfereesSHS);
            $cashierTotalEnrollees = $cashierOldHS + $cashierOldSHS + $cashierNewHS + $cashierNewSHS;

            // Installment Month Mode
            $stmt = $pdo->query("SELECT payment_plan_months, COUNT(*) as c FROM users WHERE payment_plan_months > 0 GROUP BY payment_plan_months ORDER BY c DESC LIMIT 1");
            $mostUsedMonth = $stmt->fetch(PDO::FETCH_ASSOC);
            $cashierMostUsedMonth = $mostUsedMonth ? $mostUsedMonth['payment_plan_months'] : 'None';

            // Payment stats
            $cashierPaidCash = 0;
            $cashierPaidDownpayment = 0;
            $cashierPaidOnline = 0;
            $cashierPaidBank = 0;

            $stmt = $pdo->query("SELECT payment_mode, COUNT(*) as c FROM student_downpayments GROUP BY payment_mode");
            $pmStats2 = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            $stmt = $pdo->query("SELECT payment_mode, COUNT(*) as c FROM enrollment_downpayments GROUP BY payment_mode");
            $pmStats3 = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            $cashierPaidCash = ($pmStats2['cash'] ?? 0) + ($pmStats3['cash'] ?? 0);
            $cashierPaidDownpayment = ($pmStats2['downpayment'] ?? 0) + ($pmStats3['downpayment'] ?? 0);
            $cashierPaidOnline = ($pmStats2['online'] ?? 0) + ($pmStats3['online'] ?? 0);
            $cashierPaidBank = ($pmStats2['bank'] ?? 0) + ($pmStats3['bank'] ?? 0);

            // Enrollment Velocity (Daily) - Last 7 days
            $dailyEnrollees = [];
            $dailyStudents = [];
            $dailyLabels = [];
            for ($i = 6; $i >= 0; $i--) {
                $dateStr = date('Y-m-d', strtotime("-$i days"));
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM enrollments WHERE DATE(created_at) = ?");
                $stmt->execute([$dateStr]);
                $dailyEnrollees[] = (int) $stmt->fetchColumn();

                $stmt = $pdo->prepare("SELECT COUNT(*) FROM enrollments WHERE DATE(created_at) = ? AND status='accepted'");
                $stmt->execute([$dateStr]);
                $dailyStudents[] = (int) $stmt->fetchColumn();

                $dailyLabels[] = date('M d', strtotime("-$i days"));
            }
        }
    } catch (PDOException $e) {
        error_log("Error fetching stats: " . $e->getMessage());
        $newRequests = $grades7to10 = $grades11to12 = $totalEnrollments = 0;
        $cashierNewHS = $cashierNewSHS = $cashierOldHS = $cashierOldSHS = $cashierTotalEnrollees = $cashierTransferees = 0;
        $cashierMostUsedMonth = 'None';
        $cashierPaidCash = $cashierPaidDownpayment = $cashierPaidOnline = $cashierPaidBank = 0;
    }
}

// ==================== AJAX HANDLER FOR SEARCH ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];
    
    if ($action === 'search_students') {
        try {
            if (!isset($pdo)) {
                throw new RuntimeException('Database connection not available');
            }

            $search = trim((string) ($_POST['search'] ?? ''));
            $grade_filter = trim((string) ($_POST['grade_filter'] ?? ''));
            $section_filter = trim((string) ($_POST['section_filter'] ?? ''));

            $per_page = (int) ($_POST['per_page'] ?? 10);
            if ($per_page < 1) {
                $per_page = 10;
            } elseif ($per_page > 100) {
                $per_page = 100;
            }

            $page = max(1, (int) ($_POST['page'] ?? 1));
            $offset = ($page - 1) * $per_page;

            $conditions = ["role = 'student'"];
            $params = [];

            if ($search !== '') {
                $conditions[] = "(first_name LIKE ? OR middle_name LIKE ? OR last_name LIKE ? OR suffix LIKE ? OR email LIKE ? OR lrn LIKE ?)";
                $searchTerm = "%" . $search . "%";
                $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
            }

            if ($grade_filter !== '') {
                $conditions[] = "grade_level = ?";
                $params[] = $grade_filter;
            }

            if ($section_filter !== '') {
                $conditions[] = "section = ?";
                $params[] = $section_filter;
            }

            $whereClause = 'WHERE ' . implode(' AND ', $conditions);

            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM users $whereClause");
            $countStmt->execute($params);
            $total = (int) ($countStmt->fetchColumn() ?: 0);

            $stmt = $pdo->prepare(
                "SELECT id,
                        " . baa_full_name_sql() . " AS full_name,
                        email, grade_level, section, lrn
                 FROM users
                 $whereClause
                 ORDER BY full_name
                 LIMIT $per_page OFFSET $offset"
            );
            $stmt->execute($params);
            $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $total_pages = max(1, (int) ceil($total / $per_page));

            echo json_encode([
                'success' => true,
                'students' => $students,
                'total_pages' => $total_pages,
                'page' => $page,
            ]);
            exit;
        } catch (Throwable $e) {
            error_log('search_students failed: ' . $e->getMessage());
            error_log('search_students params: ' . json_encode([
                'search' => $_POST['search'] ?? null,
                'grade_filter' => $_POST['grade_filter'] ?? null,
                'section_filter' => $_POST['section_filter'] ?? null,
                'per_page' => $_POST['per_page'] ?? null,
                'page' => $_POST['page'] ?? null,
            ]));
            echo json_encode([
                'success' => false,
                'message' => 'Database error: ' . $e->getMessage(),
            ]);
            exit;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Baesa Adventist Academy - Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Notifications */
        .notification-bell-container {
            position: relative;
            cursor: pointer;
        }
        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #ef4444;
            color: white;
            font-size: 10px;
            font-weight: bold;
            padding: 2px 5px;
            border-radius: 9999px;
            border: 2px solid #0a2d63;
        }
        .notification-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            width: 320px;
            background: white;
            border-radius: 0.5rem;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            border: 1px solid #e5e7eb;
            z-index: 2000;
            margin-top: 10px;
            display: none;
            color: #1f2937;
            overflow: hidden;
        }
        .notification-dropdown.active {
            display: block;
            animation: slideIn 0.2s ease;
        }
        .notification-item {
            padding: 12px 16px;
            border-bottom: 1px solid #f3f4f6;
            transition: background 0.2s;
            cursor: pointer;
        }
        .notification-item:hover {
            background: #f9fafb;
        }
        .notification-item.unread {
            background: #f0f9ff;
        }
        .notification-item.unread:hover {
            background: #e0f2fe;
        }
        .notification-header {
            padding: 12px 16px;
            border-bottom: 2px solid #f3f4f6;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f9fafb;
        }
        .notification-footer {
            padding: 8px 16px;
            text-align: center;
            background: #f9fafb;
            font-size: 12px;
            color: #0a2d63;
            font-weight: 500;
            border-top: 1px solid #f3f4f6;
        }
        .notification-empty {
            padding: 30px 16px;
            text-align: center;
            color: #6b7280;
        }
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .animate-slideIn {
            animation: slideIn 0.3s ease;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .loading::after {
            content: "";
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid #0a2d63;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-left: 10px;
            vertical-align: middle;
        }
        .dashboard-card.active {
            display: flex !important;
        }
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 300px;
            height: 100vh;
            background: #0a2d63;
            color: white;
            z-index: 1000;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
            transition: transform 0.3s ease-in-out;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }
        .dashboard-main {
            transition: margin-left 0.3s ease-in-out;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        /* Responsive specific styles */
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .dashboard-main { margin-left: 0 !important; }
        }
        @media (min-width: 769px) {
            .sidebar { transform: translateX(0); }
            .dashboard-main { margin-left: 300px; }
        }

        .stat-card {
            background: white;
            border-radius: 0.75rem;
            padding: 1.5rem;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06);
            border: 1px solid #e5e7eb;
            cursor: pointer;
        }
        .stat-card h3 {
            font-size: 0.875rem;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .stat-card .value {
            font-size: 2rem;
            font-weight: 700;
            color: #0a2d63;
            margin-top: 0.25rem;
        }
        .highlight {
            background-color: rgba(173, 216, 230, 0.6);
        }
        .chart-container {
            background: white;
            border-radius: 0.75rem;
            padding: 1.5rem;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
            border: 1px solid #e5e7eb;
            margin-bottom: 2rem;
        }
        .filter-select {
            padding: 0.5rem;
            border: 1px solid #d1d5db;
            border-radius: 0.375rem;
            background: white;
            font-size: 0.875rem;
        }
        .search-icon-outline {
            border: 2px solid #0a2d63;
            border-radius: 0.375rem;
            padding: 0.25rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: white;
        }
        .search-icon-outline img { width: 24px; height: 24px; }
        /* Table styles */
        .enrollment-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 0.5rem;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .enrollment-table th {
            background: #f3f4f6;
            color: #374151;
            font-weight: 600;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 0.75rem 1rem;
            text-align: left;
            border-bottom: 2px solid #e5e7eb;
        }
        .enrollment-table td {
            padding: 1rem;
            border-bottom: 1px solid #e5e7eb;
            vertical-align: middle;
        }
        .enrollment-table tr:hover td { background-color: #f9fafb; }
        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: capitalize;
        }
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-approved { background: #d1fae5; color: #065f46; }
        .status-needs_docs { background: #fed7aa; color: #9a3412; }
        .status-rejected { background: #fee2e2; color: #991b1b; }
        .three-dots, .delete-btn {
            background: none;
            border: none;
            font-size: 1.5rem;
            line-height: 1;
            cursor: pointer;
            color: #6b7280;
            padding: 0 0.5rem;
        }
        .three-dots:hover, .delete-btn:hover { color: #0a2d63; }
        .delete-btn:hover { color: #dc2626; }
        .details-row { background-color: #f9fafb; }
        .details-row td { padding: 1.5rem; }
        .hidden { display: none; }
        .action-btn-group {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        .action-btn {
            padding: 0.25rem 0.75rem;
            border-radius: 0.375rem;
            font-size: 0.75rem;
            font-weight: 500;
            border: none;
            cursor: pointer;
        }
        .btn-accept { background: #10b981; color: white; }
        .btn-docs { background: #f59e0b; color: white; }
        .btn-reject { background: #ef4444; color: white; }
        .btn-accept:hover { background: #059669; }
        .btn-docs:hover { background: #d97706; }
        .btn-reject:hover { background: #dc2626; }
        /* Toggle switch for status */
        .switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
        }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 34px;
        }
        .slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        input:checked + .slider { background-color: #0a2d63; }
        input:checked + .slider:before { transform: translateX(26px); }
        .grade-input { width: 70px; padding: 6px; border: 1px solid #d1d5db; border-radius: 6px; text-align: center; }
        .attendance-toggle {
            width: 36px;
            height: 36px;
            border-radius: 6px;
            border: 2px solid #d1d5db;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.2s ease;
            background: #f3f4f6;
            color: #9ca3af;
            user-select: none;
        }
        .attendance-toggle:hover { transform: scale(1.1); box-shadow: 0 2px 8px rgba(0,0,0,0.12); }
        .attendance-toggle.present { background: #dcfce7; border-color: #16a34a; color: #166534; box-shadow: 0 0 0 3px rgba(34,197,94,0.25); }
        .attendance-toggle.late { background: #fef9c3; border-color: #ca8a04; color: #854d0e; box-shadow: 0 0 0 3px rgba(250,204,21,0.28); }
        .attendance-toggle.absent { background: #fee2e2; border-color: #dc2626; color: #991b1b; box-shadow: 0 0 0 3px rgba(239,68,68,0.25); }
        .pass { color: #10b981; font-weight: 600; }
        .fail { color: #ef4444; font-weight: 600; }
        .tag { display: inline-block; background: #e5e7eb; padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; margin: 2px; }
        .dir-sort-option.active, .teacher-class-sort-option.active { background-color: #0a2d63; color: #fff; border-color: #0a2d63; }
    </style>
    <script>
        const gradeFees = {
            'Grade 7': 36102.50,
            'Grade 8': 36723.23,
            'Grade 9': 38226.05,
            'Grade 10': 41587.03,
            'Grade 11': 41827.50,
            'Grade 12': 43677.50
        };
    </script>
    <script>
        // ---------- Global variables ----------
        let chartInstance = null;
        let studentsData = [];
        let allEnrollments = [];
        let currentPage = 1;
        let perPage = 10;
        let totalEnrollments = <?php echo json_encode($totalEnrollments ?? 0, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        let currentUserId = <?php echo json_encode($userId, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        let userRole = <?php echo json_encode($userRole, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const canDeleteEnrollment = <?php echo json_encode(in_array($userRole, ['admin', 'super_admin'], true), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        let teacherSelectedStudentId = null;
        let teacherHomeStudents = <?php echo json_encode($allTeacherStudents, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        let teacherHomeGrades = <?php echo json_encode($studentGrades, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        let teacherHomeDisciplinary = <?php echo json_encode($disciplinary, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        let teacherHomeExtracurricular = <?php echo json_encode($extracurricular, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        let teacherHomeSubjectList = <?php echo json_encode($subjectList, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        // ---------- Chart Functions ----------
        function updateChart() {
            const dataTypeEl = document.getElementById('dataTypeFilter');
            const gradeEl = document.getElementById('chartGradeFilter');
            const sectionEl = document.getElementById('chartSectionFilter');

            if (!dataTypeEl || !gradeEl || !sectionEl) {
                console.error('Filter elements missing');
                return;
            }

            const dataType = dataTypeEl.value;
            const grade = gradeEl.value;
            const section = sectionEl.value;

            if (dataType === 'both') {
                Promise.all([
                    fetch(`php/get_enrollment_chart_data.php?data_type=enrollees${grade ? '&grade='+grade : ''}${section ? '&section='+section : ''}`).then(r => r.json()),
                    fetch(`php/get_enrollment_chart_data.php?data_type=students${grade ? '&grade='+grade : ''}${section ? '&section='+section : ''}`).then(r => r.json())
                ]).then(([enrolleesData, studentsData]) => {
                    if (enrolleesData.success && studentsData.success) {
                        renderChartBoth(enrolleesData.labels, enrolleesData.values, studentsData.values);
                        if (grade && enrolleesData.sections) {
                            populateSectionDropdown(enrolleesData.sections);
                        }
                    } else {
                        console.error('Error fetching both datasets');
                    }
                }).catch(err => console.error(err));
            } else {
                let url = `php/get_enrollment_chart_data.php?data_type=${encodeURIComponent(dataType)}`;
                if (grade) url += `&grade=${encodeURIComponent(grade)}`;
                if (section) url += `&section=${encodeURIComponent(section)}`;
                fetch(url)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            renderChart(data.labels, data.values);
                            if (grade && data.sections) {
                                populateSectionDropdown(data.sections);
                            }
                        } else {
                            console.error('Chart data error:', data.message);
                        }
                    })
                    .catch(err => console.error('Error fetching chart data:', err));
            }
        }

        function renderChart(labels, values) {
            const ctx = document.getElementById('enrollmentChart').getContext('2d');
            if (chartInstance) chartInstance.destroy();

            const dataTypeEl = document.getElementById('dataTypeFilter');
            const label = dataTypeEl ? (dataTypeEl.value === 'students' ? 'Registered Students' : 'Enrollment Requests') : 'Count';
            const color = dataTypeEl.value === 'students' ? '#0a2d63' : '#f59e0b';

            chartInstance = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: label,
                        data: values,
                        borderColor: color,
                        backgroundColor: color + '20',
                        tension: 0.4,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: true, position: 'top' } },
                    scales: { y: { beginAtZero: true } }
                }
            });
        }

        function renderChartBoth(labels, enrolleesValues, studentsValues) {
            const ctx = document.getElementById('enrollmentChart').getContext('2d');
            if (chartInstance) chartInstance.destroy();

            chartInstance = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'Enrollment Requests',
                            data: enrolleesValues,
                            borderColor: '#f59e0b',
                            backgroundColor: '#f59e0b20',
                            tension: 0.4,
                            fill: true
                        },
                        {
                            label: 'Registered Students',
                            data: studentsValues,
                            borderColor: '#0a2d63',
                            backgroundColor: '#0a2d6320',
                            tension: 0.4,
                            fill: true
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: true, position: 'top' } },
                    scales: { y: { beginAtZero: true } }
                }
            });
        }

        function populateSectionDropdown(sections) {
            const select = document.getElementById('chartSectionFilter');
            if (!select) return;
            select.innerHTML = '<option value="">All Sections</option>';
            sections.forEach(s => {
                const option = document.createElement('option');
                option.value = s;
                option.textContent = s;
                select.appendChild(option);
            });
        }

        // ---------- Enrollment Table Functions ----------
        function loadEnrollments(page = currentPage) {
            currentPage = page;
            fetch(`php/get_enrollments.php?page=${currentPage}&per_page=${perPage}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.enrollments) {
                        allEnrollments = data.enrollments;
                        displayEnrollmentTable(allEnrollments);
                        updatePagination(data.total, data.page, data.per_page);
                    }
                })
                .catch(error => console.error('Error loading enrollments:', error));
        }

        function displayEnrollmentTable(enrollments) {
            const tbody = document.getElementById('enrollmentTableBody');
            if (!tbody) return;
            
            if (enrollments.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center text-gray-400 py-10">No enrollment requests yet.</td></tr>';
                return;
            }
            
            let html = '';
            enrollments.forEach(enrollment => {
                const created = new Date(enrollment.created_at).toLocaleDateString();
                const statusClass = `status-${enrollment.status}`;
                const statusText = enrollment.status ? enrollment.status.replace('_', ' ') : 'pending';
                const phone = enrollment.phone.startsWith('+63') ? enrollment.phone : '+63' + enrollment.phone;
                
                html += `
                    <tr class="enrollment-row cursor-pointer hover:bg-gray-100 transition" data-id="${enrollment.id}" onclick="toggleDetails(${enrollment.id})">
                        <td class="font-medium">${enrollment.full_name}</td>
                        <td>${enrollment.email}</td>
                        <td><span class="status-badge ${statusClass}">${statusText}</span></td>
                        <td onclick="event.stopPropagation()">
                            <div class="action-btn-group">
                                <button class="action-btn btn-accept" onclick="acceptEnrollment(${enrollment.id})">Accept</button>
                                <button class="action-btn btn-reject" onclick="openRejectEnrollmentModalById(${enrollment.id})">Reject</button>
                            </div>
                        </td>
                        <td onclick="event.stopPropagation()">
                            ${canDeleteEnrollment ? `<button class="delete-btn" onclick="deleteEnrollment(${enrollment.id}, '${enrollment.full_name}')" title="Delete enrollment">✕</button>` : ''}
                        </td>
                    </tr>
                    <tr id="details-${enrollment.id}" class="details-row hidden">
                        <td colspan="5">
                            <div class="p-4 bg-gray-50 rounded">
                                <div class="grid grid-cols-2 gap-4">
                                    <div><span class="font-semibold">Age:</span> ${enrollment.age}</div>
                                    <div><span class="font-semibold">Gender:</span> ${enrollment.gender}</div>
                                    <div><span class="font-semibold">Birthdate:</span> ${enrollment.birthdate}</div>
                                    <div><span class="font-semibold">Phone:</span> ${phone}</div>
                                    <div><span class="font-semibold">Grade:</span> ${enrollment.grade_level}</div>
                                    <div><span class="font-semibold">Student Type:</span> ${enrollment.student_type || 'New Student'}</div>
                                    <div><span class="font-semibold">Downpayment:</span> ₱${parseFloat(enrollment.downpayment_total || 0).toFixed(2)}</div>
                                    <div><span class="font-semibold">Section:</span> ${enrollment.section}</div>
                                    <div><span class="font-semibold">LRN:</span> ${enrollment.lrn}</div>
                                    <div><span class="font-semibold">Submitted:</span> ${created}</div>
                                </div>
                                <div class="mt-4">
                                    <a href="#" onclick="viewDocuments(${enrollment.id}); return false;" class="text-blue-600 hover:underline">View Documents (${enrollment.document_count})</a>
                                    ${String(enrollment.status || '').toLowerCase() === 'approved'
                                        ? `&nbsp;|&nbsp;<a href="#" onclick="generatePDF(${enrollment.id}, '${String(enrollment.status || '').replace(/'/g, "\\'")}'); return false;" class="text-green-600 hover:underline">Generate Student Assessment PDF</a>`
                                        : ''}
                                </div>
                            </div>
                        </td>
                    </tr>
                `;
            });
            
            tbody.innerHTML = html;
        }

        function toggleDetails(enrollmentId) {
            const detailsRow = document.getElementById(`details-${enrollmentId}`);
            if (detailsRow) {
                detailsRow.classList.toggle('hidden');
            }
        }

        function updatePagination(total, page, perPage) {
            const paginationDiv = document.getElementById('enrollmentPagination');
            const paginationInfo = document.getElementById('paginationInfo');
            const paginationButtons = document.getElementById('paginationButtons');
            if (!paginationDiv || !paginationInfo || !paginationButtons) return;
            
            if (total <= perPage) {
                paginationDiv.style.display = 'none';
                return;
            }
            
            paginationDiv.style.display = 'flex';
            const totalPages = Math.ceil(total / perPage);
            const start = ((page - 1) * perPage) + 1;
            const end = Math.min(page * perPage, total);
            
            paginationInfo.textContent = `Showing ${start} to ${end} of ${total} enrollments`;
            
            let buttonsHtml = '';
            buttonsHtml += `<button class="pagination-btn border border-gray-300 bg-white px-3 py-1 rounded text-sm hover:bg-gray-100 disabled:opacity-50 disabled:cursor-not-allowed" onclick="loadEnrollments(${page - 1})" ${page === 1 ? 'disabled' : ''}>Previous</button>`;
            
            for (let i = 1; i <= totalPages; i++) {
                if (i === 1 || i === totalPages || (i >= page - 2 && i <= page + 2)) {
                    buttonsHtml += `<button class="pagination-btn border border-gray-300 px-3 py-1 rounded text-sm ${i === page ? 'bg-[#0a2d63] text-white border-[#0a2d63]' : 'bg-white hover:bg-gray-100'}" onclick="loadEnrollments(${i})">${i}</button>`;
                } else if (i === page - 3 || i === page + 3) {
                    buttonsHtml += `<button class="pagination-btn border border-gray-300 px-3 py-1 rounded text-sm bg-white" disabled>...</button>`;
                }
            }
            
            buttonsHtml += `<button class="pagination-btn border border-gray-300 bg-white px-3 py-1 rounded text-sm hover:bg-gray-100 disabled:opacity-50 disabled:cursor-not-allowed" onclick="loadEnrollments(${page + 1})" ${page === totalPages ? 'disabled' : ''}>Next</button>`;
            
            paginationButtons.innerHTML = buttonsHtml;
        }

        function changePerPage() {
            const select = document.getElementById('perPageSelect');
            const customInput = document.getElementById('customPerPageInput');
            if (!select || !customInput) return;
            
            if (select.value === 'custom') {
                customInput.style.display = 'flex';
            } else {
                customInput.style.display = 'none';
                perPage = parseInt(select.value);
                currentPage = 1;
                loadEnrollments(1);
            }
        }

        function applyCustomPerPage() {
            const customValue = document.getElementById('customPerPage')?.value;
            if (customValue && customValue > 0) {
                perPage = parseInt(customValue);
                currentPage = 1;
                loadEnrollments(1);
                document.getElementById('customPerPageInput').style.display = 'none';
                document.getElementById('perPageSelect').value = 'custom';
            }
        }

        function renderTeacherHomeStudentFilters() {
            const tableBody = document.getElementById('teacherPerformanceTableBody');
            const cards = document.querySelectorAll('.teacher-student-card');
            if (!tableBody) return;

            if (!teacherSelectedStudentId) {
                const columnCount = document.querySelectorAll('#teacherPerformanceTable thead tr:nth-child(2) th').length;
                tableBody.innerHTML = `<tr><td colspan="${columnCount}" class="p-6 text-center text-gray-500">Search for a student to display performance.</td></tr>`;
                cards.forEach(card => card.style.display = 'none');
                return;
            }

            const student = teacherHomeStudents.find(s => String(s.id) === String(teacherSelectedStudentId));
            if (!student) {
                tableBody.innerHTML = `<tr><td colspan="${document.querySelectorAll('#teacherPerformanceTable thead tr:nth-child(2) th').length}" class="p-6 text-center text-gray-500">Search for a student to display performance.</td></tr>`;
                cards.forEach(card => card.style.display = 'none');
                return;
            }

            const headerCells = Array.from(document.querySelectorAll('#teacherPerformanceTable thead tr:nth-child(2) th')).slice(1, -1);
            const subjectNames = headerCells.map(th => th.textContent.trim());
            const gradesForStudent = teacherHomeGrades[student.id] || {};
            let total = 0;
            let count = 0;
            let rowHtml = '';

            subjectNames.forEach(subject => {
                let avg = gradesForStudent[subject]?.average;
                if (avg !== undefined && avg !== null && avg !== '') {
                    avg = parseFloat(avg);
                }
                const displayValue = typeof avg === 'number' && !isNaN(avg) && avg > 0 ? avg : '-';
                const classes = typeof avg === 'number' && !isNaN(avg)
                    ? (avg >= 75 ? 'text-green-600 font-semibold' : 'text-red-600')
                    : '';
                if (typeof avg === 'number' && !isNaN(avg) && avg > 0) {
                    total += avg;
                    count++;
                }
                rowHtml += `<td class="p-3 text-center ${classes}">${displayValue}</td>`;
            });
            const overall = count > 0 ? Math.round(total / count) : '-';
            tableBody.innerHTML = `<tr class="border-b hover:bg-gray-50" data-student-id="${student.id}"><td class="p-3 font-semibold">${escapeHtml(student.full_name)}</td>${rowHtml}<td class="p-3 text-center font-bold">${overall}</td></tr>`;
            
            cards.forEach(card => {
                card.style.display = card.getAttribute('data-student-id') === String(teacherSelectedStudentId) ? '' : 'none';
            });
        }

        window.scrollTeacherSubjects = function(direction) {
            const container = document.getElementById('teacherPerformanceScroll');
            if (!container) return;
            container.scrollBy({ left: direction * 300, behavior: 'smooth' });
        }

        // ---------- Payables Calculation ----------
        async function calculatePayables() {
            const studentId = document.getElementById('studentSelect')?.value;
            const tuitionInput = document.getElementById('tuitionFee');
            const downInput = document.getElementById('downPayment');
            let tuitionFee = parseFloat(tuitionInput?.value);
            let downPayment = parseFloat(downInput?.value);
            const discounts = parseFloat(document.getElementById('discounts')?.value) || 0;
            const books = parseFloat(document.getElementById('booksFee')?.value) || 0;
            const monthlyPayments = parseInt(document.getElementById('monthlyPayments')?.value) || 4;
            
            if (!studentId) {
                alert('Please select a student');
                return;
            }
            
            // If not manually provided, auto-load from current student totals
            if (!(tuitionFee > 0) || Number.isNaN(downPayment)) {
                try {
                    const resp = await fetch('php/get_student_payables.php?student_id=' + studentId);
                    const data = await parseJsonResponse(resp);
                    if (data?.success && data?.totals) {
                        if (!(tuitionFee > 0)) {
                            tuitionFee = parseFloat(data.totals.fee_total || 0);
                            if (tuitionInput && tuitionFee > 0) tuitionInput.value = tuitionFee.toFixed(2);
                        }
                        if (Number.isNaN(downPayment)) {
                            downPayment = parseFloat(data.totals.downpayment_total || 0);
                            if (downInput) downInput.value = (downPayment || 0).toFixed(2);
                        }
                    }
                } catch (e) {
                    console.error('Failed to auto-load payables totals:', e);
                }
            }

            // Fetch fee breakdown
            let gradeLevel = '';
            let feeBreakdown = { tuition: tuitionFee, misc: 0, aircon: 0, hsa: 0, books: books };
            try {
                const resp = await fetch('php/get_student_payables.php?student_id=' + studentId);
                const data = await parseJsonResponse(resp);
                if (data?.success && data?.totals) {
                    gradeLevel = data.totals.grade_level || '';
                }
            } catch (e) {
                console.error('Failed to get grade level:', e);
            }
            if (gradeLevel) {
                try {
                    const resp2 = await fetch('php/get_fee_breakdown_endpoint.php?grade=' + encodeURIComponent(gradeLevel));
                    const data2 = await parseJsonResponse(resp2);
                    if (data2?.success && data2?.breakdown) {
                        feeBreakdown = data2.breakdown;
                        feeBreakdown.books = books;
                        // Update tuitionFee if not manually set
                        if (!(parseFloat(tuitionInput?.value) > 0)) {
                            tuitionFee = feeBreakdown.tuition + feeBreakdown.misc + feeBreakdown.aircon + feeBreakdown.hsa;
                            if (tuitionInput) tuitionInput.value = tuitionFee.toFixed(2);
                        }
                    }
                } catch (e) {
                    console.error('Failed to fetch fee breakdown:', e);
                }
            }

            if (!tuitionFee || tuitionFee <= 0) {
                alert('Unable to calculate: no total fees found for this student yet.');
                return;
            }

            if (Number.isNaN(downPayment) || downPayment < 0) downPayment = 0;
            const totalPayable = (feeBreakdown.tuition + feeBreakdown.misc + feeBreakdown.aircon + feeBreakdown.hsa + feeBreakdown.books) - discounts;
            
            if (downPayment > totalPayable) {
                alert('Down payment cannot be greater than total payable amount');
                return;
            }
            
            const remainingBalance = totalPayable - downPayment;
            const monthlyPaymentAmount = remainingBalance / monthlyPayments;
            const breakdown = feeBreakdown;
            
            const resultContent = document.getElementById('resultContent');
            const calculationResult = document.getElementById('calculationResult');
            const addPayableBtn = document.getElementById('addPayableBtn');
            const generatePdfBtn = document.getElementById('generatePdfBtnCalc');
            
            if (resultContent) {
                resultContent.innerHTML = `
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-4">
                        <div><strong class="text-gray-700">Tuition Fee:</strong><div class="text-lg text-[#0a2d63]">₱${breakdown.tuition.toFixed(2)}</div></div>
                        <div><strong class="text-gray-700">Miscellaneous:</strong><div class="text-lg text-[#0a2d63]">₱${breakdown.misc.toFixed(2)}</div></div>
                        <div><strong class="text-gray-700">Aircon Fee:</strong><div class="text-lg text-[#0a2d63]">₱${breakdown.aircon.toFixed(2)}</div></div>
                        <div><strong class="text-gray-700">HSA Fee:</strong><div class="text-lg text-[#0a2d63]">₱${breakdown.hsa.toFixed(2)}</div></div>
                        <div><strong class="text-gray-700">Books:</strong><div class="text-lg text-[#0a2d63]">₱${breakdown.books.toFixed(2)}</div></div>
                        <div><strong class="text-gray-700">Discounts/Grants:</strong><div class="text-lg text-green-600">₱${discounts.toFixed(2)}</div></div>
                    </div>
                    <div class="text-center p-4 bg-blue-50 rounded-lg my-4">
                        <strong class="block mb-1 text-[#0a2d63]">Remaining Balance:</strong>
                        <div class="text-2xl font-bold text-[#0a2d63]">₱${remainingBalance.toFixed(2)}</div>
                    </div>
                    <div class="text-center p-4 bg-green-50 rounded-lg">
                        <strong class="block mb-1 text-green-600">Monthly Payment (${monthlyPayments} months):</strong>
                        <div class="text-xl font-bold text-green-600">₱${monthlyPaymentAmount.toFixed(2)}</div>
                    </div>
                `;
            }
            
            if (calculationResult) calculationResult.style.display = 'block';
            if (addPayableBtn) addPayableBtn.style.display = 'inline-block';
            if (generatePdfBtn) generatePdfBtn.style.display = 'inline-block';
            
            window.calculatedPayables = {
                studentId: studentId,
                tuitionFee: breakdown.tuition,
                misc: breakdown.misc,
                aircon: breakdown.aircon,
                hsa: breakdown.hsa,
                books: breakdown.books,
                discounts: discounts,
                downPayment: downPayment,
                remainingBalance: remainingBalance,
                monthlyPayments: monthlyPayments,
                monthlyPaymentAmount: monthlyPaymentAmount
            };
        }

        /**
         * Generates the Assessment PDF using a GET request (window.open)
         * to avoid security/origin issues with POST-to-new-tab on some browsers.
         */
        function generateAssessmentPDF() {
            if (!window.calculatedPayables) {
                alert('No calculation data available. Please calculate first.');
                return;
            }

            const data = window.calculatedPayables;
            const params = new URLSearchParams({
                student_id: data.studentId,
                tuition: data.tuitionFee,
                misc: data.misc || 0,
                aircon: data.aircon || 0,
                hsa: data.hsa || 0,
                books: data.books || 0,
                discounts: data.discounts,
                downPayment: data.downPayment,
                monthlyPayments: data.monthlyPayments,
                monthlyPaymentAmount: data.monthlyPaymentAmount,
                remainingBalance: data.remainingBalance
            });

            // Using window.open with GET parameters is more reliable than hidden form submission for PDFs
            window.open('php/generate_assessment_pdf.php?' + params.toString(), '_blank');
        }

        function addPayable() {
            if (!window.calculatedPayables) {
                alert('Please calculate payables first');
                return;
            }
            
            const { studentId, tuitionFee, discounts, downPayment, remainingBalance, monthlyPayments, monthlyPaymentAmount } = window.calculatedPayables;
            const studentName = document.getElementById('selectedStudentName')?.value || '';
            
            if (!confirm(`Add payables for ${studentName}?\n\nTotal: ₱${tuitionFee.toFixed(2)}\nDiscounts: ₱${discounts.toFixed(2)}\nDown Payment: ₱${downPayment.toFixed(2)}\nRemaining Balance: ₱${remainingBalance.toFixed(2)}\nMonthly Payment: ₱${monthlyPaymentAmount.toFixed(2)} x ${monthlyPayments} months`)) {
                return;
            }
            
            const formData = new FormData();
            formData.append('student_id', studentId);
            formData.append('tuition_fee', tuitionFee);
            formData.append('discounts', discounts);
            formData.append('down_payment', downPayment);
            formData.append('remaining_balance', remainingBalance);
            formData.append('monthly_payments', monthlyPayments);
            formData.append('monthly_payment_amount', monthlyPaymentAmount);
            
            fetch('php/add_payables.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Payables added successfully!');
                    const form = document.getElementById('payablesForm');
                    if (form) form.reset();
                    const calcResult = document.getElementById('calculationResult');
                    if (calcResult) calcResult.style.display = 'none';
                    const addBtn = document.getElementById('addPayableBtn');
                    if (addBtn) addBtn.style.display = 'none';
                    const pdfBtn = document.getElementById('generatePdfBtnCalc');
                    if (pdfBtn) pdfBtn.style.display = 'none';
                    window.calculatedPayables = null;
                } else {
                    alert('Error adding payables: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error adding payables:', error);
                alert('Error adding payables');
            });
        }

        // ---------- Payment Processing Functions ----------
        function parseJsonResponse(response) {
            return response.text().then(text => {
                if (!response.ok) {
                    const error = new Error('Server error ' + response.status + ': ' + response.statusText);
                    error.responseText = text;
                    throw error;
                }

                try {
                    return JSON.parse(text);
                } catch (err) {
                    const error = new Error('Invalid JSON response: ' + text);
                    error.responseText = text;
                    throw error;
                }
            });
        }

        function getTodayLocalDateString() {
            const now = new Date();
            const year = now.getFullYear();
            const month = String(now.getMonth() + 1).padStart(2, '0');
            const day = String(now.getDate()).padStart(2, '0');
            return `${year}-${month}-${day}`;
        }

        function parseDateAsLocal(dateString) {
            if (!dateString) return new Date();
            const parts = String(dateString).split('T')[0].split('-');
            if (parts.length === 3) {
                return new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
            }
            const asDate = new Date(dateString);
            return isNaN(asDate.getTime()) ? new Date() : asDate;
        }

        function loadStudentPayables() {
            const studentId = document.getElementById('paymentStudentId')?.value;
            if (!studentId || studentId === "") {
                alert('Please select a student first');
                return;
            }
            
            const payablesList = document.getElementById('payablesList');
            if (!payablesList) return;
            payablesList.innerHTML = '<div class="text-center text-gray-500 py-10 min-h-[80px]">Loading payables...</div>';
            const studentPayablesDiv = document.getElementById('studentPayables');
            if (studentPayablesDiv) studentPayablesDiv.style.display = 'block';

            const totalsDiv = document.getElementById('payablesTotals');
            if (totalsDiv) totalsDiv.innerHTML = '';
            
            const paymentDiscounts = document.getElementById('paymentDiscounts');
            let simulatedDiscount = 0;
            if (paymentDiscounts) {
                if (paymentDiscounts.tagName === 'SELECT') {
                    const selectedOption = paymentDiscounts.options[paymentDiscounts.selectedIndex];
                    if (selectedOption && selectedOption.dataset.amount) {
                        simulatedDiscount = parseFloat(selectedOption.dataset.amount);
                    }
                } else if (paymentDiscounts.value) {
                    simulatedDiscount = parseFloat(paymentDiscounts.value);
                }
            }
            
            let url = 'php/get_student_payables.php?student_id=' + studentId;
            if (simulatedDiscount > 0) {
                url += '&simulated_discount=' + simulatedDiscount;
            }

            fetch(url)
                .then(parseJsonResponse)
                .then(data => {
                    if (data.success && data.payables) {
                        displayStudentPayables(data.payables, data.totals || null);
                        
                        // Set calculatedPayables for PDF generation
                        if (data.totals) {
                            const t = data.totals;
                            // Calculate a single monthly payment amount for the PDF if there are remaining dues
                            let monthlyPaymentAmount = 0;
                            if (t.remaining_due > 0 && t.payment_plan_months > 0) {
                                monthlyPaymentAmount = t.remaining_due / t.payment_plan_months;
                            }
                            
                            window.calculatedPayables = {
                                studentId: studentId,
                                tuitionFee: (t.breakdown && t.breakdown.tuition) ? t.breakdown.tuition : t.fee_total,
                                misc: (t.breakdown && t.breakdown.misc) ? t.breakdown.misc : 0,
                                aircon: (t.breakdown && t.breakdown.aircon) ? t.breakdown.aircon : 0,
                                hsa: (t.breakdown && t.breakdown.hsa) ? t.breakdown.hsa : 0,
                                books: (t.breakdown && t.breakdown.books) ? t.breakdown.books : 0,
                                discounts: t.discounts_total || 0,
                                downPayment: t.downpayment_total || 0,
                                remainingBalance: t.remaining_due || 0,
                                monthlyPayments: t.payment_plan_months || 4,
                                monthlyPaymentAmount: monthlyPaymentAmount
                            };
                            
                            // Show PDF button
                            const generatePdfBtn = document.getElementById('generatePdfBtnProc');
                            if (generatePdfBtn) generatePdfBtn.style.display = 'inline-block';
                        }
                    } else {
                        payablesList.innerHTML = '<div class="text-center text-gray-500 py-10">No payables found for this student.</div>';
                    }
                })
                .catch(error => {
                    console.error('Error loading student payables:', error);
                    payablesList.innerHTML = '<div class="text-center text-red-600 py-10">Error loading payables</div>';
                });
        }

        function displayStudentPayables(payables, totals = null) {
            const payablesList = document.getElementById('payablesList');
            if (!payablesList) return;
            const totalsDiv = document.getElementById('payablesTotals');
            if (totalsDiv && totals) {
                const fmt = (n) => '₱' + (parseFloat(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
                totalsDiv.innerHTML = `
                    <div class="grid grid-cols-2 lg:grid-cols-6 gap-3">
                        <div class="p-4 bg-white border border-gray-200 rounded">
                            <div class="text-xs font-semibold text-gray-500">Grade</div>
                            <div class="text-sm font-bold text-[#0a2d63]">${escapeHtml(String(totals.grade_level || '-'))}</div>
                        </div>
                        <div class="p-4 bg-white border border-gray-200 rounded">
                            <div class="text-xs font-semibold text-gray-500">Total Fees</div>
                            <div class="text-sm font-bold text-[#0a2d63]">${fmt(totals.fee_total)}</div>
                        </div>
                        <div class="p-4 bg-white border border-gray-200 rounded">
                            <div class="text-xs font-semibold text-gray-500">Downpayment</div>
                            <div class="text-sm font-bold text-green-700">${fmt(totals.downpayment_total)}</div>
                        </div>
                        <div class="p-4 bg-white border border-gray-200 rounded">
                            <div class="text-xs font-semibold text-gray-500">Other Payments</div>
                            <div class="text-sm font-bold text-green-700">${fmt(totals.total_paid)}</div>
                        </div>
                        <div class="p-4 bg-white border border-gray-200 rounded">
                            <div class="text-xs font-semibold text-gray-500">Discounts/Grants</div>
                            <div class="text-sm font-bold text-green-700">${fmt(totals.discounts_total)}</div>
                        </div>
                        <div class="p-4 bg-white border border-gray-200 rounded">
                            <div class="text-xs font-semibold text-gray-500">Remaining Due</div>
                            <div class="text-sm font-bold text-red-700">${fmt(totals.remaining_due)}</div>
                        </div>
                    </div>
                `;
            }
            payablesList.innerHTML = renderAdminPayablesTable(payables);
        }

        function getPayableStatusMeta(payable) {
            const dueDate = parseDateAsLocal(payable.due_date);
            dueDate.setHours(0, 0, 0, 0);
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            let status = String(payable.status || 'pending').toLowerCase();
            let statusClass = 'bg-yellow-100 text-yellow-800';
            let statusText = 'Pending';

            if (status === 'paid') {
                statusClass = 'bg-green-100 text-green-800';
                statusText = 'Paid';
            } else if (status === 'partially_paid') {
                statusClass = 'bg-blue-100 text-blue-800';
                statusText = 'Partially Paid';
            } else if (dueDate < today) {
                statusClass = 'bg-red-100 text-red-800';
                statusText = 'Overdue';
            }

            return { dueDate, statusClass, statusText };
        }

        function renderAdminPayablesTable(payables) {
            if (!Array.isArray(payables) || payables.length === 0) {
                return '<div class="text-center text-gray-500 py-10">No payables found.</div>';
            }

            let html = `
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse bg-white shadow-sm rounded min-w-[600px] md:min-w-full">
                        <thead class="bg-[#0a2d63] text-white">
                            <tr>
                                <th class="p-4 text-left font-semibold">Description</th>
                                <th class="p-4 text-right font-semibold">Amount</th>
                                <th class="p-4 text-left font-semibold">Due Date</th>
                                <th class="p-4 text-center font-semibold">Status</th>
                            </tr>
                        </thead>
                        <tbody>
            `;

            payables.forEach(payable => {
                const { dueDate, statusClass, statusText } = getPayableStatusMeta(payable);
                const description = payable.item_name || payable.description || 'Tuition Fee';
                html += `
                    <tr class="border-b border-gray-200 hover:bg-gray-50">
                        <td class="p-4 text-gray-800">${description}</td>
                        <td class="p-4 text-right font-semibold text-[#0a2d63]">₱${parseFloat(payable.amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</td>
                        <td class="p-4 text-gray-700">${dueDate.toLocaleDateString()}</td>
                        <td class="p-4 text-center"><span class="px-3 py-1 rounded text-xs font-semibold ${statusClass}">${statusText}</span></td>
                    </tr>
                `;
            });

            html += `
                        </tbody>
                    </table>
                </div>
            `;

            return html;
        }

        // ---------- Tuition Fee Manager ----------
        let tuitionFeeManagerData = null;
        let tuitionFeeManagerDefaults = null;

        function loadTuitionFeeManager() {
            const statusEl = document.getElementById('tuitionFeeManagerStatus');
            const tableEl = document.getElementById('tuitionFeeManagerTable');
            if (statusEl) statusEl.innerHTML = '<div class="text-gray-600">Loading fee table...</div>';
            if (tableEl) tableEl.innerHTML = '';

            fetch('php/get_tuition_fees.php')
                .then(parseJsonResponse)
                .then(data => {
                    if (!data.success) throw new Error(data.message || 'Failed to load fees');
                    tuitionFeeManagerData = data.fees || {};
                    tuitionFeeManagerDefaults = data.defaults || {};
                    renderTuitionFeeManagerTable();
                    if (statusEl) statusEl.innerHTML = '<div class="text-green-700 font-medium">Loaded.</div>';
                })
                .catch(err => {
                    console.error(err);
                    if (statusEl) statusEl.innerHTML = '<div class="text-red-700 font-medium">Error loading fees.</div>';
                });
        }

        function feeTotal(b) {
            const v = (k) => parseFloat((b && b[k] != null) ? b[k] : 0) || 0;
            return v('tuition') + v('misc') + v('aircon') + v('hsa') + v('books');
        }

        function gradeToFeeId(grade) {
            return String(grade).trim().replace(/\s+/g, '_').replace(/[^A-Za-z0-9_-]/g, '');
        }

        function renderTuitionFeeManagerTable() {
            const tableEl = document.getElementById('tuitionFeeManagerTable');
            if (!tableEl) return;

            const grades = ['Grade 7','Grade 8','Grade 9','Grade 10','Grade 11','Grade 12'];
            let html = `
                <table class="w-full border-collapse bg-white shadow-sm rounded min-w-[900px]">
                    <thead class="bg-[#0a2d63] text-white">
                        <tr>
                            <th class="p-3 text-left font-semibold">Grade</th>
                            <th class="p-3 text-right font-semibold">Tuition</th>
                            <th class="p-3 text-right font-semibold">Misc</th>
                            <th class="p-3 text-right font-semibold">Aircon</th>
                            <th class="p-3 text-right font-semibold">HSA</th>
                            <th class="p-3 text-right font-semibold">Books</th>
                            <th class="p-3 text-right font-semibold">Total</th>
                            <th class="p-3 text-center font-semibold">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
            `;

            const fmt = (n) => (parseFloat(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
            grades.forEach(g => {
                const b = tuitionFeeManagerData?.[g] || { tuition: 0, misc: 0, aircon: 0, hsa: 0, books: 0 };
                const total = feeTotal(b);
                html += `
                    <tr class="border-b border-gray-200 hover:bg-gray-50">
                        <td class="p-3 font-semibold text-gray-800 whitespace-nowrap">${escapeHtml(g)}</td>
                        <td class="p-3 text-right"><input class="w-32 p-2 border border-gray-300 rounded text-right" type="number" step="0.01" min="0" id="fee_${gradeToFeeId(g)}_tuition" value="${b.tuition}"></td>
                        <td class="p-3 text-right"><input class="w-32 p-2 border border-gray-300 rounded text-right" type="number" step="0.01" min="0" id="fee_${gradeToFeeId(g)}_misc" value="${b.misc}"></td>
                        <td class="p-3 text-right"><input class="w-32 p-2 border border-gray-300 rounded text-right" type="number" step="0.01" min="0" id="fee_${gradeToFeeId(g)}_aircon" value="${b.aircon}"></td>
                        <td class="p-3 text-right"><input class="w-32 p-2 border border-gray-300 rounded text-right" type="number" step="0.01" min="0" id="fee_${gradeToFeeId(g)}_hsa" value="${b.hsa}"></td>
                        <td class="p-3 text-right"><input class="w-32 p-2 border border-gray-300 rounded text-right" type="number" step="0.01" min="0" id="fee_${gradeToFeeId(g)}_books" value="${b.books}"></td>
                        <td class="p-3 text-right font-bold text-[#0a2d63]">₱${fmt(total)}</td>
                        <td class="p-3 text-center">
                            <div class="flex flex-col sm:flex-row gap-2 justify-center">
                                <button type="button" class="px-3 py-2 rounded bg-green-600 text-white font-medium hover:bg-green-700 transition" onclick="saveTuitionFeeRow('${g}')">Save</button>
                                <button type="button" class="px-3 py-2 rounded border border-gray-300 bg-white text-gray-700 font-medium hover:bg-gray-100 transition" onclick="resetTuitionFeeRow('${g}')">Reset</button>
                            </div>
                        </td>
                    </tr>
                `;
            });

            html += `
                    </tbody>
                </table>
            `;

            tableEl.innerHTML = html;
        }

        function readFeeRow(grade) {
            const rowId = gradeToFeeId(grade);
            const get = (k) => parseFloat(document.getElementById(`fee_${rowId}_${k}`)?.value || '0') || 0;
            return { tuition: get('tuition'), misc: get('misc'), aircon: get('aircon'), hsa: get('hsa'), books: get('books') };
        }

        function saveTuitionFeeRow(grade) {
            const b = readFeeRow(grade);
            const confirmMsg = `This will change tuition fees for ALL existing and upcoming ${grade} students (unpaid fees will be updated). Continue?`;
            if (!confirm(confirmMsg)) return;

            const statusEl = document.getElementById('tuitionFeeManagerStatus');
            if (statusEl) statusEl.innerHTML = '<div class="text-gray-600">Saving...</div>';

            const fd = new FormData();
            fd.append('grade_level', grade);
            fd.append('tuition', b.tuition.toFixed(2));
            fd.append('misc', b.misc.toFixed(2));
            fd.append('aircon', b.aircon.toFixed(2));
            fd.append('hsa', b.hsa.toFixed(2));
            fd.append('books', b.books.toFixed(2));

            fetch('php/set_tuition_fee.php', { method: 'POST', body: fd })
                .then(parseJsonResponse)
                .then(data => {
                    if (!data.success) throw new Error(data.message || 'Save failed');
                    if (tuitionFeeManagerData) tuitionFeeManagerData[grade] = b;
                    renderTuitionFeeManagerTable();
                    if (statusEl) statusEl.innerHTML = '<div class="text-green-700 font-medium">Saved.</div>';
                })
                .catch(err => {
                    console.error(err);
                    if (statusEl) statusEl.innerHTML = '<div class="text-red-700 font-medium">Error: ' + escapeHtml(String(err.message || err)) + '</div>';
                });
        }

        function resetTuitionFeeRow(grade) {
            const confirmMsg = `Reset ${grade} fees back to the original/default values? This will also update unpaid fees for existing ${grade} students.`;
            if (!confirm(confirmMsg)) return;
            const statusEl = document.getElementById('tuitionFeeManagerStatus');
            if (statusEl) statusEl.innerHTML = '<div class="text-gray-600">Resetting...</div>';

            const fd = new FormData();
            fd.append('grade_level', grade);
            fetch('php/reset_tuition_fee.php', { method: 'POST', body: fd })
                .then(parseJsonResponse)
                .then(data => {
                    if (!data.success) throw new Error(data.message || 'Reset failed');
                    // Reload to ensure latest values
                    loadTuitionFeeManager();
                })
                .catch(err => {
                    console.error(err);
                    if (statusEl) statusEl.innerHTML = '<div class="text-red-700 font-medium">Error: ' + escapeHtml(String(err.message || err)) + '</div>';
                });
        }

        function renderStudentPayablesTable(payables, totals = null) {
            if (!Array.isArray(payables) || payables.length === 0) {
                return '<div class="text-center text-gray-500 py-10">No payables found.</div>';
            }

            let html = `
                <div class="bg-gray-100 border border-gray-200 rounded">
                    <div class="px-4 py-3 border-b border-gray-300">
                        <h4 class="text-2xl font-semibold text-[#0a2d63] mb-1">Payments and Adjustments</h4>
                        <p class="text-gray-600 text-sm">Detailed payable breakdown and running balance.</p>
                    </div>
                    <div class="p-4 space-y-3">
            `;

            payables.forEach(payable => {
                const { dueDate, statusClass, statusText } = getPayableStatusMeta(payable);
                const description = payable.item_name || payable.description || 'Tuition Fee';
                html += `
                    <div class="border border-gray-200 bg-white rounded overflow-hidden">
                        <div class="bg-gray-200 px-4 py-2 text-gray-700 font-semibold text-sm">
                            ${dueDate.toLocaleDateString()} | ${description}
                        </div>
                        <div class="px-4 py-3 flex justify-between items-center border-b border-gray-100">
                            <span class="text-gray-700">Amount</span>
                            <span class="font-semibold text-gray-800">₱${parseFloat(payable.amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
                        </div>
                        <div class="px-4 py-3 flex justify-between items-center">
                            <span class="text-gray-700">Status</span>
                            <span class="px-3 py-1 rounded text-xs font-semibold ${statusClass}">${statusText}</span>
                        </div>
                    </div>
                `;
            });

            html += '</div></div>';

            if (totals) {
                const totalTuition = parseFloat((totals.fee_total ?? totals.total_tuition_fee) || 0);
                const downpayment = parseFloat(totals.downpayment_total || 0);
                const otherPayments = parseFloat(totals.total_paid || 0);
                const discountsTotal = parseFloat(totals.discounts_total || 0);
                const totalReduced = downpayment + otherPayments + discountsTotal;
                const totalToBePaid = parseFloat((totals.remaining_due ?? totals.total_to_be_paid) || 0);
                html += `
                    <div class="mt-4 border border-gray-200 rounded overflow-hidden">
                        <div class="bg-[#0a2d63] text-white px-4 py-3 flex justify-between items-center font-semibold">
                            <span>Total Deductions</span>
                            <span>₱${totalReduced.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
                        </div>
                        <div class="bg-white px-4 py-3 flex justify-between items-center text-sm border-b border-gray-200">
                            <span class="text-gray-700">Downpayment</span>
                            <span class="font-semibold text-green-700">₱${downpayment.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
                        </div>
                        <div class="bg-white px-4 py-3 flex justify-between items-center text-sm border-b border-gray-200">
                            <span class="text-gray-700">Other payments</span>
                            <span class="font-semibold text-green-700">₱${otherPayments.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
                        </div>
                        <div class="bg-white px-4 py-3 flex justify-between items-center text-sm border-b border-gray-200">
                            <span class="text-gray-700">Discounts/Grants</span>
                            <span class="font-semibold text-green-700">₱${discountsTotal.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
                        </div>
                        <div class="bg-yellow-300 px-4 py-3 flex justify-between items-center text-black font-semibold">
                            <span>Assessment Total Fees</span>
                            <span>₱${totalTuition.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
                        </div>
                        <div class="bg-[#0a2d63] text-white px-4 py-4 flex justify-between items-center text-lg font-bold">
                            <span>To Be Paid</span>
                            <span>₱${totalToBePaid.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
                        </div>
                    </div>
                `;
            }

            return html;
        }

        function processPayment() {
            const targetType = document.getElementById('paymentTargetType')?.value || 'student';
            const studentId = document.getElementById('paymentStudentId')?.value;
            const enrollmentId = document.getElementById('paymentEnrollmentId')?.value;
            const paymentMode = document.getElementById('paymentMode')?.value || 'downpayment';
            const amount = document.getElementById('paymentAmount')?.value;
            let paymentDate = document.getElementById('paymentDate')?.value;
            if (!paymentDate) {
                paymentDate = getTodayLocalDateString();
            }
            
            if (targetType === 'student') {
                if (!studentId || studentId === "") {
                    alert('Please select a student');
                    return;
                }
            } else {
                if (!enrollmentId || enrollmentId === "") {
                    alert('Please select an enrollee');
                    return;
                }
            }
            
            if (!amount || parseFloat(amount) <= 0) {
                alert('Please enter a valid payment amount');
                return;
            }
            
            // Map mode to type
            let paymentType = 'payment';
            if (paymentMode === 'downpayment') {
                paymentType = 'downpayment';
            }
            
            const formData = new FormData();
            formData.append('payment_target', targetType);
            formData.append('student_id', studentId || '');
            formData.append('enrollment_id', enrollmentId || '');
            formData.append('payment_type', paymentType);
            formData.append('payment_mode', paymentMode);
            formData.append('amount', amount);
            formData.append('payment_date', paymentDate);
            if (paymentMode !== 'cash') {
                formData.append('monthly_plans', document.getElementById('paymentMonthsPlan')?.value || '');
            }
            formData.append('discounts', document.getElementById('paymentDiscounts')?.value || '0');
            
            fetch('php/process_payment.php', {
                method: 'POST',
                body: formData
            })
            .then(parseJsonResponse)
            .then(data => {
                const paymentResult = document.getElementById('paymentResult');
                if (data.success) {
                    if (paymentResult) {
                        paymentResult.style.display = 'block';
                        paymentResult.innerHTML = data.message;
                    }
                    const amountInput = document.getElementById('paymentAmount');
                    if (amountInput) amountInput.value = '';
                    if (targetType === 'student') {
                        loadStudentPayables();
                    } else {
                        loadPaymentEnrollees();
                        if (typeof loadEnrollments === 'function') {
                            loadEnrollments();
                        }
                    }
                } else {
                    alert('Error processing payment: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error processing payment:', error);
                alert('Error processing payment');
            });
        }

        // ---------- User Management Functions ----------
        function openAddUserModal() {
            const modal = document.getElementById('addUserModal');
            if (modal) modal.style.display = 'flex';
            toggleModalStudentFields();
        }

        function closeAddUserModal() {
            const modal = document.getElementById('addUserModal');
            if (modal) modal.style.display = 'none';
            const form = document.getElementById('createUserForm');
            if (form) form.reset();
            const studentFields = document.getElementById('modalStudentFields');
            if (studentFields) studentFields.classList.add('hidden');
            const roleSelect = document.getElementById('modalRoleSelect');
            if (roleSelect) roleSelect.disabled = false;
            const enrollmentIdField = document.getElementById('modalEnrollmentId');
            if (enrollmentIdField) enrollmentIdField.value = '';
            syncModalStudentFieldConstraints();
        }

        function syncModalAgeFromBirthdate() {
            const bdEl = document.getElementById('modalBirthdate');
            const ageEl = document.getElementById('modalAge');
            if (!bdEl || !ageEl) return;
            const bd = bdEl.value;
            if (!bd) {
                ageEl.value = '';
                return;
            }
            const parts = bd.split('-');
            if (parts.length !== 3) {
                ageEl.value = '';
                return;
            }
            const birth = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
            if (isNaN(birth.getTime())) {
                ageEl.value = '';
                return;
            }
            const today = new Date();
            let age = today.getFullYear() - birth.getFullYear();
            const m = today.getMonth() - birth.getMonth();
            if (m < 0 || (m === 0 && today.getDate() < birth.getDate())) {
                age--;
            }
            ageEl.value = age >= 0 ? String(age) : '';
        }

        function syncModalStudentFieldConstraints() {
            const isStudent = document.getElementById('modalRoleSelect')?.value === 'student';
            const strandVisible = document.getElementById('modalStrandContainer') && !document.getElementById('modalStrandContainer').classList.contains('hidden');
            const pairs = [
                ['modalGradeLevel', isStudent],
                ['modalSectionSelect', isStudent],
                ['modalStrand', isStudent && strandVisible]
            ];
            pairs.forEach(([id, req]) => {
                const el = document.getElementById(id);
                if (!el) return;
                el.disabled = !isStudent;
                if (req) el.setAttribute('required', 'required');
                else el.removeAttribute('required');
            });
            const lrn = document.getElementById('modalLrnField');
            if (lrn) {
                lrn.disabled = !isStudent;
                lrn.removeAttribute('required');
                lrn.addEventListener('input', (e) => {
                    e.target.value = String(e.target.value || '').replace(/[^0-9]/g, '').slice(0, 12);
                });
            }
            // Age, gender, birthdate, and phone are now always enabled for all users
            const alwaysEnabled = ['modalGender', 'modalBirthdate', 'modalPhone'];
            alwaysEnabled.forEach(id => {
                const el = document.getElementById(id);
                if (el) {
                    el.disabled = false;
                    el.setAttribute('required', 'required');
                }
            });
            const ageEl = document.getElementById('modalAge');
            if (ageEl) {
                ageEl.disabled = false;
                ageEl.readOnly = true;
                ageEl.removeAttribute('required');
            }
        }

        function submitAddUser() {
            const form = document.getElementById('createUserForm');
            if (!form) return;
            const formData = new FormData(form);

            const firstName = formData.get('first_name') || '';
            const middleName = formData.get('middle_name') || '';
            const lastName = formData.get('last_name') || '';
            const suffix = formData.get('suffix') || '';
            const fullName = [firstName, middleName, lastName, suffix].filter(part => part.trim() !== '').join(' ');
            formData.append('full_name', fullName);

            const roleSelect = document.getElementById('modalRoleSelect');
            if (roleSelect) {
                const role = roleSelect.value;
                if (role && !formData.has('role')) {
                    formData.append('role', role);
                }
            }

            const username = formData.get('username')?.trim();
            if (!username) {
                alert('Username is required');
                return;
            }

            const submitBtn = document.querySelector('#addUserModal .bg-\\[\\#0a2d63\\]');
            if (!submitBtn) return;
            const originalText = submitBtn.textContent;
            submitBtn.textContent = 'Creating...';
            submitBtn.disabled = true;
            
            fetch('php/handle_user.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('User created successfully!');

                    const enrollmentId = document.getElementById('modalEnrollmentId')?.value;
                    if (enrollmentId) {
                        fetch('php/update_enrollment_after_accept.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: `enrollment_id=${enrollmentId}&user_id=${data.user_id}`
                        })
                        .then(res => res.json())
                        .then(updateRes => {
                            if (updateRes.success) {
                                if (typeof loadEnrollments === 'function') {
                                    loadEnrollments();
                                }
                            }
                        })
                        .catch(err => console.error(err));
                    }

                    closeAddUserModal();
                    if (typeof loadAllUsersDirectory === 'function') {
                        loadAllUsersDirectory();
                    }
                } else {
                    alert('Error creating user: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('Error creating user:', error);
                alert('Network error. Please try again.');
            })
            .finally(() => {
                submitBtn.textContent = originalText;
                submitBtn.disabled = false;
            });
        }

        // ---------- Utility Functions ----------
        function getSectionsForGrade(gradeLevel) {
            const gradeSections = {
                'Grade 7': ['Love', 'Joy'],
                'Grade 8': ['Patience', 'Peace'],
                'Grade 9': ['Goodness', 'Kindness'],
                'Grade 10': ['Gentleness', 'Faithfulness'],
                'Grade 11': ['Self-Control', 'Honesty'],
                'Grade 12': ['Humility', 'Meekness']
            };
            return gradeSections[gradeLevel] || [];
        }

        // ---------- Edit User Functions ----------
        function closeEditUserModal() {
            const modal = document.getElementById('editUserModal');
            if (modal) modal.style.display = 'none';
            // Reset global subject tracking variables
            allTeacherSubjects = [];
            teacherSelectedSubjectIds = [];
            document.getElementById('subjectGradeFilter').value = '';
        }



        function displayEditUserDetails(user) {
            const detailsDiv = document.getElementById('editUserDetails');
            const infoDiv = document.getElementById('editUserInfo');
            const formDiv = document.getElementById('editUserForm');
            const teacherSection = document.getElementById('teacherSubjectSection');
            
            // Show details section
            detailsDiv.style.display = 'block';
            
            // User info
            const fullName = [user.first_name, user.middle_name, user.last_name, user.suffix].map(p => (p && p.toUpperCase() === 'N/A') ? '' : p).filter(Boolean).join(' ');
            infoDiv.innerHTML = `
                <div><strong>Name:</strong> ${fullName}</div>
                <div><strong>Username:</strong> ${user.username}</div>
                <div><strong>Email:</strong> ${user.email}</div>
                <div><strong>Role:</strong> ${user.role.charAt(0).toUpperCase() + user.role.slice(1)}</div>
                ${user.role === 'student' ?
                `
                    <div><strong>Grade Level:</strong> ${user.grade_level || 'Not set'}</div>
                    <div><strong>Section:</strong> ${user.section || 'Not set'}</div>
                    <div><strong>LRN:</strong> ${user.lrn || 'Not set'}</div>
                ` : ''}
            `;
            
            // Edit form based on role
            if (user.role === 'student') {
                formDiv.innerHTML = `
                    <div class="space-y-4">
                        <div>
                            <label class="block mb-1 font-medium text-gray-700">Grade Level</label>
                            <select id="editStudentGrade" class="w-full p-2 border border-gray-300 rounded" onchange="updateEditSections()">
                                <option value="">Select Grade Level</option>
                                <option value="Grade 7" ${user.grade_level === 'Grade 7' ? 'selected' : ''}>Grade 7</option>
                                <option value="Grade 8" ${user.grade_level === 'Grade 8' ? 'selected' : ''}>Grade 8</option>
                                <option value="Grade 9" ${user.grade_level === 'Grade 9' ? 'selected' : ''}>Grade 9</option>
                                <option value="Grade 10" ${user.grade_level === 'Grade 10' ? 'selected' : ''}>Grade 10</option>
                                <option value="Grade 11" ${user.grade_level === 'Grade 11' ? 'selected' : ''}>Grade 11</option>
                                <option value="Grade 12" ${user.grade_level === 'Grade 12' ? 'selected' : ''}>Grade 12</option>
                            </select>
                        </div>
                        <div>
                            <label class="block mb-1 font-medium text-gray-700">Section</label>
                            <select id="editStudentSection" class="w-full p-2 border border-gray-300 rounded">
                                <option value="">Select Section</option>
                            </select>
                        </div>
                        <div>
                            <button type="button" onclick="resetSelectedStudentPassword(${user.id})" class="bg-[#0a2d63] text-white px-4 py-2 rounded font-medium hover:bg-[#08306b] transition">
                                Reset Student Password
                            </button>
                        </div>
                    </div>
                `;
                teacherSection.style.display = 'none';
                updateEditSections(user.section);
            } else if (user.role === 'teacher') {
                formDiv.innerHTML = '<p class="text-gray-600">No additional fields to edit for teachers.</p>';
                teacherSection.style.display = 'block';
                loadTeacherSubjects(user.id);
            } else {
                formDiv.innerHTML = '<p class="text-gray-600">No editable fields for this role.</p>';
                teacherSection.style.display = 'none';
            }

            // Store user ID for saving
            detailsDiv.dataset.userId = user.id;
            detailsDiv.dataset.userRole = user.role;
            document.getElementById('saveEditUserBtn').disabled = false;
        }

        function updateEditSections(selectedSection = '') {
            const gradeLevel = document.getElementById('editStudentGrade').value;
            const sectionSelect = document.getElementById('editStudentSection');
            
            sectionSelect.innerHTML = '<option value="">Select Section</option>';
            
            if (gradeLevel) {
                const sections = getSectionsForGrade(gradeLevel);
                sections.forEach(section => {
                    const option = document.createElement('option');
                    option.value = section;
                    option.textContent = section;
                    if (section === selectedSection) option.selected = true;
                    sectionSelect.appendChild(option);
                });
            }
        }

        let allTeacherSubjects = [];
        let teacherSelectedSubjectIds = [];

        function loadTeacherSubjects(teacherId) {
            console.log('Loading subjects for teacher:', teacherId);
            fetch('php/get_subject.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'get_all_subjects' })
            })
            .then(response => {
                console.log('Get all subjects response status:', response.status);
                return response.json();
            })
            .then(data => {
                console.log('All subjects data:', data);
                if (data.success && (!data.subjects || data.subjects.length === 0)) {
                    console.log('No subjects found, setting up automatically...');
                    setupSubjectsAutomatically();
                    return;
                }
                
                if (data.success && data.subjects && data.subjects.length > 0) {
                    allTeacherSubjects = data.subjects;
                    fetch('php/get_subject.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'get_teacher_subjects', teacher_id: teacherId })
                    })
                    .then(response => {
                        console.log('Get teacher subjects response status:', response.status);
                        return response.json();
                    })
                    .then(teacherData => {
                        console.log('Teacher subjects data:', teacherData);
                        teacherSelectedSubjectIds = teacherData.success && teacherData.subjects ? teacherData.subjects.map(s => s.id) : [];
                        displaySubjectCheckboxes(allTeacherSubjects, teacherSelectedSubjectIds);
                    })
                    .catch(error => {
                        console.error('Error fetching teacher subjects:', error);
                        displaySubjectCheckboxes(allTeacherSubjects, []);
                    });
                } else {
                    console.error('Get all subjects failed:', data.message);
                    const checkboxesDiv = document.getElementById('subjectCheckboxes');
                    checkboxesDiv.innerHTML = '<p class="text-red-600">Error loading subjects: ' + (data.message || 'Unknown error') + '</p>';
                }
            })
            .catch(error => {
                console.error('Error loading subjects:', error);
                const checkboxesDiv = document.getElementById('subjectCheckboxes');
                checkboxesDiv.innerHTML = '<p class="text-red-600">Network error while loading subjects</p>';
            });
        }

        function setupSubjectsAutomatically() {
            console.log('Auto-setting up subjects...');
            fetch('php/setup_subjects.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' }
            })
            .then(response => response.json())
            .then(data => {
                console.log('Setup subjects response:', data);
                if (data.success) {
                    const teacherId = document.getElementById('editUserDetails').dataset.userId;
                    setTimeout(() => loadTeacherSubjects(teacherId), 500);
                } else {
                    console.error('Failed to setup subjects:', data.message);
                    const checkboxesDiv = document.getElementById('subjectCheckboxes');
                    checkboxesDiv.innerHTML = '<p class="text-red-600">Error setting up subjects: ' + (data.message || 'Unknown error') + '</p>';
                }
            })
            .catch(error => {
                console.error('Error auto-setting up subjects:', error);
                const checkboxesDiv = document.getElementById('subjectCheckboxes');
                checkboxesDiv.innerHTML = '<p class="text-red-600">Network error while setting up subjects</p>';
            });
        }

        function filterSubjectsByGrade() {
            const selectedGrade = document.getElementById('subjectGradeFilter').value;
            displaySubjectCheckboxes(allTeacherSubjects, teacherSelectedSubjectIds, selectedGrade);
            updateSubjectSelectAllButton();
        }

        function displaySubjectCheckboxes(subjects, assignedSubjects, filterGrade = '') {
            console.log('Displaying checkboxes - Total subjects:', subjects.length, 'Assigned:', assignedSubjects.length, 'Filter grade:', filterGrade);
            const checkboxesDiv = document.getElementById('subjectCheckboxes');
            checkboxesDiv.innerHTML = '';

            if (!subjects || subjects.length === 0) {
                checkboxesDiv.innerHTML = '<p class="text-yellow-700 bg-yellow-100 p-3 rounded col-span-full">No subjects found in the system.</p>';
                return;
            }

            const filteredSubjects = filterGrade ? subjects.filter(s => s.grade_level === filterGrade) : subjects;

            if (filteredSubjects.length === 0 && filterGrade) {
                checkboxesDiv.innerHTML = '<p class="text-gray-600 p-3 col-span-full">No subjects found for ' + filterGrade + '</p>';
                return;
            }

            filteredSubjects.forEach(subject => {
                const isAssigned = assignedSubjects.includes(subject.id);
                const checkbox = document.createElement('div');
                checkbox.className = 'flex items-center space-x-2';
                checkbox.innerHTML = `
                    <input type="checkbox" id="subject_${subject.id}" value="${subject.id}" ${isAssigned ? 'checked' : ''} class="w-4 h-4 text-[#0a2d63] border-gray-300 rounded focus:ring-[#0a2d63]" onchange="updateTeacherSelectedSubjects()">
                    <label for="subject_${subject.id}" class="text-sm font-medium text-gray-700 cursor-pointer">
                        ${subject.subject_name}
                    </label>
                `;
                checkboxesDiv.appendChild(checkbox);
            });
            updateSelectedSubjectsSummary();
            updateSubjectSelectAllButton();
            console.log('Rendered', filteredSubjects.length, 'subject checkboxes');
        }

        function updateTeacherSelectedSubjects() {
            const checkboxes = document.querySelectorAll('#subjectCheckboxes input[type="checkbox"]:checked');
            teacherSelectedSubjectIds = Array.from(checkboxes).map(cb => parseInt(cb.value));
            updateSelectedSubjectsSummary();
            updateSubjectSelectAllButton();
            console.log('Updated selected subjects:', teacherSelectedSubjectIds);
        }

        function updateSubjectSelectAllButton() {
            const btn = document.getElementById('subjectSelectAllBtn');
            if (!btn) return;
            const visible = document.querySelectorAll('#subjectCheckboxes input[type="checkbox"]');
            if (!visible || visible.length === 0) {
                btn.textContent = 'Select All';
                btn.disabled = true;
                btn.classList.add('opacity-60', 'cursor-not-allowed');
                return;
            }
            btn.disabled = false;
            btn.classList.remove('opacity-60', 'cursor-not-allowed');
            const allChecked = Array.from(visible).every(cb => cb.checked);
            btn.textContent = allChecked ? 'Unselect All' : 'Select All';
        }

        function toggleSelectAllSubjects() {
            const visible = document.querySelectorAll('#subjectCheckboxes input[type="checkbox"]');
            if (!visible || visible.length === 0) return;
            const allChecked = Array.from(visible).every(cb => cb.checked);
            Array.from(visible).forEach(cb => { cb.checked = !allChecked; });
            updateTeacherSelectedSubjects();
        }

        function clearAllSubjects() {
            const checkboxes = document.querySelectorAll('#subjectCheckboxes input[type="checkbox"]');
            if (!checkboxes || checkboxes.length === 0) return;
            Array.from(checkboxes).forEach(cb => { cb.checked = false; });
            updateTeacherSelectedSubjects();
        }

        function updateSelectedSubjectsSummary() {
            const summaryDiv = document.getElementById('selectedSubjectsSummary');
            const displayDiv = document.getElementById('selectedSubjectsDisplay');

            if (teacherSelectedSubjectIds.length === 0) {
                summaryDiv.style.display = 'none';
                return;
            }

            summaryDiv.style.display = 'block';
            displayDiv.innerHTML = '';
            teacherSelectedSubjectIds.forEach(subjectId => {
                const subject = allTeacherSubjects.find(s => s.id === subjectId);
                if (subject) {
                    const tag = document.createElement('span');
                    tag.className = 'bg-yellow-200 text-yellow-900 px-3 py-1 rounded-full text-xs font-medium';
                    tag.textContent = subject.subject_name + ' (' + subject.grade_level + ')';
                    displayDiv.appendChild(tag);
                }
            });
        }

        function saveEditUser() {
            const detailsDiv = document.getElementById('editUserDetails');
            const userId = detailsDiv.dataset.userId;
            const userRole = detailsDiv.dataset.userRole;

            if (!userId) return;

            const saveBtn = document.getElementById('saveEditUserBtn');
            const originalText = saveBtn.textContent;
            saveBtn.textContent = 'Saving...';
            saveBtn.disabled = true;

            if (userRole === 'student') {
                const gradeLevel = document.getElementById('editStudentGrade').value;
                const section = document.getElementById('editStudentSection').value;

                if (!gradeLevel || !section) {
                    alert('Please select both grade level and section');
                    saveBtn.textContent = originalText;
                    saveBtn.disabled = false;
                    return;
                }

                fetch('php/handle_user.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'update_student',
                        user_id: userId,
                        grade_level: gradeLevel,
                        section: section
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Student updated successfully');
                        closeEditUserModal();
                    } else {
                        alert('Error updating student: ' + (data.message || 'Unknown error'));
                    }
                })
                .catch(error => {
                    console.error('Error updating student:', error);
                    alert('Network error');
                })
                .finally(() => {
                    saveBtn.textContent = originalText;
                    saveBtn.disabled = false;
                });
            } else if (userRole === 'teacher') {
                const teacherAssignedGrade = document.getElementById('teacherAssignedGrade')?.value || '';
                const teacherAssignedSection = document.getElementById('teacherAssignedSection')?.value || '';
                fetch('php/handle_user.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'update_teacher_subjects',
                        teacher_id: userId,
                        subject_ids: teacherSelectedSubjectIds,
                        teacher_grade_level: teacherAssignedGrade,
                        teacher_section: teacherAssignedSection
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Teacher subjects updated successfully');
                        closeEditUserModal();
                    } else {
                        alert('Error updating teacher subjects: ' + (data.message || 'Unknown error'));
                    }
                })
                .catch(error => {
                    console.error('Error updating teacher subjects:', error);
                    alert('Network error');
                })
                .finally(() => {
                    saveBtn.textContent = originalText;
                    saveBtn.disabled = false;
                });
            }
        }

        function resetSelectedStudentPassword(userId) {
            if (!confirm('Reset this student password to default (baa123)?')) return;
            const formData = new FormData();
            formData.append('user_id', String(userId));
            fetch('php/reset_user_password.php', { method: 'POST', body: formData })
                .then(parseJsonResponse)
                .then(data => {
                    if (data.success) {
                        alert(data.message || 'Password reset successful.');
                    } else {
                        alert(data.message || 'Password reset failed.');
                    }
                })
                .catch(() => alert('Network error while resetting password.'));
        }

        // ---------- Users directory (sidebar "Users") ----------
        let allUsers = [];
        let userDirectorySort = 'name';
        let userDirectoryPageSize = 10;

        function escapeUserDirHtml(str) {
            if (str === null || str === undefined) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        function loadAllUsersDirectory() {
            const listEl = document.getElementById('userDirectoryList');
            if (listEl) listEl.innerHTML = '<div class="text-center p-10 text-gray-500">Loading users...</div>';
            fetch('php/get_users.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({})
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.users) {
                        allUsers = data.users;
                        applyUserDirectoryFilters();
                    } else if (listEl) {
                        listEl.innerHTML = '<div class="text-center p-10 text-red-600">Could not load users.</div>';
                    }
                })
                .catch(error => {
                    console.error('Error loading users directory:', error);
                    if (listEl) listEl.innerHTML = '<div class="text-center p-10 text-red-600">Network error.</div>';
                });
        }

        function setUserDirectorySort(sortBy) {
            userDirectorySort = sortBy;
            document.querySelectorAll('.dir-sort-option').forEach(opt => opt.classList.remove('active'));
            const sortEl = document.getElementById('dir-sort-' + sortBy);
            if (sortEl) sortEl.classList.add('active');
            applyUserDirectoryFilters();
        }

        function getSelectedUserDirectoryGrades() {
            return Array.from(document.querySelectorAll('.dirFilterGradeCheckbox:checked')).map(el => el.value);
        }

        function getSelectedUserDirectorySections() {
            return Array.from(document.querySelectorAll('.dirFilterSectionCheckbox:checked')).map(el => el.value);
        }

        function updateUserDirectoryFilterSections() {
            const selectedGrades = getSelectedUserDirectoryGrades();
            const filterSectionContainer = document.getElementById('dirFilterSectionContainer');
            const sectionCheckboxes = document.getElementById('dirFilterSectionCheckboxes');
            const gradeSections = {
                'Grade 7': ['Love', 'Joy'],
                'Grade 8': ['Patience', 'Peace'],
                'Grade 9': ['Goodness', 'Kindness'],
                'Grade 10': ['Gentleness', 'Faithfulness'],
                'Grade 11': ['Self-Control', 'Honesty'],
                'Grade 12': ['Humility', 'Meekness']
            };

            if (!filterSectionContainer || !sectionCheckboxes) return;

            const sectionSet = new Set();
            selectedGrades.forEach(grade => {
                (gradeSections[grade] || []).forEach(section => sectionSet.add(section));
            });

            if (sectionSet.size > 0) {
                filterSectionContainer.classList.remove('hidden');
                sectionCheckboxes.innerHTML = '';
                Array.from(sectionSet).sort().forEach(section => {
                    const label = document.createElement('label');
                    label.className = 'flex items-center gap-2 text-sm text-gray-700 cursor-pointer';
                    label.innerHTML = `<input type="checkbox" class="dirFilterSectionCheckbox w-4 h-4" value="${section}"> ${section}`;
                    const checkbox = label.querySelector('input');
                    checkbox.addEventListener('change', applyUserDirectoryFilters);
                    sectionCheckboxes.appendChild(label);
                });
            } else {
                filterSectionContainer.classList.add('hidden');
                sectionCheckboxes.innerHTML = '';
            }
        }

        function setUserDirectoryPageSize(value) {
            const size = parseInt(value, 10);
            if (!isNaN(size) && size > 0) {
                userDirectoryPageSize = size;
                applyUserDirectoryFilters();
            }
        }

        function applyUserDirectoryFilters() {
            const listEl = document.getElementById('userDirectoryList');
            if (!listEl) return;

            const searchInput = document.getElementById('dirSearchInput');
            const searchTerm = (searchInput && searchInput.value ? searchInput.value : '').toLowerCase();

            const filterStudent = document.getElementById('dirFilterStudent')?.checked || false;
            const filterTeacher = document.getElementById('dirFilterTeacher')?.checked || false;
            const filterCashier = document.getElementById('dirFilterCashier')?.checked || false;
            const filterRegistrar = document.getElementById('dirFilterRegistrar')?.checked || false;
            <?php if ($userRole == 'admin'): ?>
            const filterAdmin = document.getElementById('dirFilterAdmin')?.checked || false;
            <?php else: ?>
            const filterAdmin = false;
            <?php endif; ?>

            const filterGrades = getSelectedUserDirectoryGrades();
            const filterSections = getSelectedUserDirectorySections();

            let filteredUsers = allUsers.filter(user => {
                const matchesSearch = searchTerm === '' ||
                    (user.full_name && user.full_name.toLowerCase().includes(searchTerm)) ||
                    (user.username && user.username.toLowerCase().includes(searchTerm)) ||
                    (user.email && user.email.toLowerCase().includes(searchTerm));
                if (!matchesSearch) return false;

                const roleFilters = [];
                if (filterStudent) roleFilters.push('student');
                if (filterTeacher) roleFilters.push('teacher');
                if (filterCashier) roleFilters.push('cashier');
                if (filterRegistrar) roleFilters.push('registrar');
                if (filterAdmin) roleFilters.push('admin');

                if (roleFilters.length > 0 && !roleFilters.includes(user.role)) return false;
                if (filterGrades.length > 0 && user.role === 'student' && !filterGrades.includes(user.grade_level)) return false;
                if (filterSections.length > 0 && user.role === 'student' && !filterSections.includes(user.section)) return false;
                return true;
            });

            filteredUsers.sort((a, b) => {
                switch (userDirectorySort) {
                    case 'name': return (a.full_name || '').localeCompare(b.full_name || '');
                    case 'role': return (a.role || '').localeCompare(b.role || '');
                    case 'grade': return (a.grade_level || '').localeCompare(b.grade_level || '');
                    case 'date': return new Date(b.created_at) - new Date(a.created_at);
                    default: return 0;
                }
            });

            renderUserDirectoryList(filteredUsers);
        }

        function toggleRoleFilter() {
            const filterSection = document.getElementById('dirFilterRoleSection');
            if (filterSection) {
                filterSection.classList.toggle('hidden');
            }
        }

        function toggleGradeFilter() {
            const filterSection = document.getElementById('dirFilterGradeSection');
            if (filterSection) {
                filterSection.classList.toggle('hidden');
            }
        }

        function editUserFromTable(userId, username) {
            const modal = document.getElementById('editUserModal');
            if (!modal) return;

            // Find the user from allUsers array
            const user = allUsers.find(u => u.id === userId);
            if (!user) {
                alert('User not found');
                return;
            }

            // Display the user details immediately
            displayEditUserDetails(user);
            
            // Open the modal
            modal.style.display = 'flex';
        }

        function deleteUserConfirm(userId, username) {
            if (!confirm(`Are you sure you want to delete user "${username}"? This action cannot be undone.`)) return;
            
            const deleteBtn = event.target;
            const originalText = deleteBtn.textContent;
            deleteBtn.textContent = 'Deleting...';
            deleteBtn.disabled = true;

            fetch('php/handle_user.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'delete',
                    user_id: userId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('User deleted successfully');
                    if (typeof loadAllUsersDirectory === 'function') loadAllUsersDirectory();
                } else {
                    alert('Error deleting user: ' + (data.message || 'Unknown error'));
                    deleteBtn.textContent = originalText;
                    deleteBtn.disabled = false;
                }
            })
            .catch(error => {
                console.error('Error deleting user:', error);
                alert('Network error while deleting user');
                deleteBtn.textContent = originalText;
                deleteBtn.disabled = false;
            });
        }

        function toggleUserDirectoryDetails(userId) {
            const row = document.getElementById('user-dir-details-' + userId);
            if (row) row.classList.toggle('hidden');
        }

        function renderUserDirectoryList(users) {
            const resultsDiv = document.getElementById('userDirectoryList');
            if (!resultsDiv) return;

            if (users.length === 0) {
                resultsDiv.innerHTML = '<div class="text-center text-gray-500 py-10">No users match your filters.</div>';
                return;
            }

            const displayedUsers = users.slice(0, userDirectoryPageSize);
            let html = '';

            displayedUsers.forEach(user => {
                const nameParts = [user.first_name, user.middle_name, user.last_name, user.suffix].map(p => (p && p.toUpperCase() === 'N/A') ? '' : p).filter(Boolean);
                const fullName = (user.full_name ? user.full_name.replace(/\bN\/A\b/gi, '').replace(/\s+/g, ' ').trim() : nameParts.join(' ')) || 'N/A';
                const roleDisplay = user.role ? user.role.charAt(0).toUpperCase() + user.role.slice(1) : 'N/A';
                const roleColor = user.role === 'admin' ? '#0a2d63' : (user.role === 'cashier' ? '#f59e0b' : (user.role === 'registrar' ? '#ef4444' : (user.role === 'teacher' ? '#10b981' : '#6c757d')));
                const isActive = user.status == 1;
                const gs = user.role === 'student' && user.grade_level
                    ? escapeUserDirHtml(user.grade_level + (user.section ? ' - ' + user.section : ''))
                    : '';
                const phoneDisp = user.phone
                    ? (String(user.phone).startsWith('+63') ? escapeUserDirHtml(user.phone) : '+63' + escapeUserDirHtml(user.phone))
                    : '—';
                const canPromote = (userRole === 'admin' || userRole === 'registrar') && user.role === 'student';
                const showToggle = (userRole === 'admin' || userRole === 'registrar') && user.id != currentUserId && !(userRole === 'registrar' && user.role === 'admin');

                html += `
                    <div class="border-b border-gray-200 last:border-b-0">
                        <div class="p-3 md:p-4 hover:bg-gray-50">
                            <div class="flex items-start gap-2 min-w-0">
                                <button type="button" class="text-[#0a2d63] font-bold px-1 shrink-0" title="Show details" onclick="event.stopPropagation(); toggleUserDirectoryDetails(${user.id})">▾</button>
                                <div class="cursor-pointer flex-1 min-w-0" onclick="toggleUserDirectoryDetails(${user.id})">
                                    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-2">
                                        <div class="min-w-0">
                                            <div class="font-semibold text-[#0a2d63] truncate">${escapeUserDirHtml(fullName)}</div>
                                            ${gs ? `<div class="text-sm text-gray-600 mt-0.5 truncate">${gs}</div>` : ''}
                                        </div>
                                        ${showToggle ? `
                                            <div class="status-toggle inline-flex items-center gap-2 shrink-0 self-start sm:self-center">
                                                <label class="switch">
                                                    <input type="checkbox" ${isActive ? 'checked' : ''} onchange="toggleUserStatus(${user.id}, this.checked)">
                                                    <span class="slider"></span>
                                                </label>
                                                <span class="text-xs font-semibold ${isActive ? 'text-green-600' : 'text-red-600'} whitespace-nowrap">${isActive ? 'Active' : 'Inactive'}</span>
                                            </div>` : ''}
                                    </div>
                                    <div class="mt-2 flex flex-wrap items-center gap-2">
                                        <span class="px-2 py-0.5 rounded text-xs font-semibold text-white" style="background:${roleColor}">${escapeUserDirHtml(roleDisplay)}</span>
                                        ${canPromote ? `<button type="button" onclick="promoteStudent(${user.id})" class="bg-green-600 text-white px-3 py-1 rounded text-xs font-medium hover:bg-green-700 transition">Promote</button>` : ''}
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div id="user-dir-details-${user.id}" class="hidden border-t border-gray-100 bg-gray-50 px-4 py-3 pl-10 text-sm text-gray-700">
                            <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 flex-1">
                                    <div><span class="font-semibold">Username:</span> ${escapeUserDirHtml(user.username || '—')}</div>
                                    <div><span class="font-semibold">Email:</span> ${escapeUserDirHtml(user.email || '—')}</div>
                                    <div><span class="font-semibold">Grade / Section:</span> ${user.grade_level ? escapeUserDirHtml((user.grade_level || '') + (user.section ? ' - ' + user.section : '')) : '—'}</div>
                                    <div><span class="font-semibold">Gender:</span> ${escapeUserDirHtml(user.gender || '—')}</div>
                                    <div><span class="font-semibold">Birthdate:</span> ${escapeUserDirHtml(user.birthdate || '—')}</div>
                                    <div><span class="font-semibold">Phone:</span> ${escapeUserDirHtml(phoneDisp)}</div>
                                    <div><span class="font-semibold">Status:</span> ${isActive ? 'Active' : 'Inactive'}</div>
                                    <div><span class="font-semibold">Joined:</span> ${user.created_at ? escapeUserDirHtml(user.created_at) : '—'}</div>
                                </div>
                                <div class="flex md:flex-col gap-2 shrink-0">
                                    <button type="button" onclick="editUserFromTable(${user.id}, '${escapeUserDirHtml(user.username)}')" class="bg-[#0a2d63] text-white px-4 py-2 rounded text-sm font-medium hover:bg-[#08306b] transition">Edit User</button>
                                    ${!isActive ? `<button type="button" onclick="deleteUserConfirm(${user.id}, '${escapeUserDirHtml(user.username)}')" class="bg-red-600 text-white px-4 py-2 rounded text-sm font-medium hover:bg-red-700 transition">Delete User</button>` : ''}
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            });

            const summary = `<div class="text-sm text-gray-600 p-3">Showing ${displayedUsers.length} of ${users.length} user${users.length === 1 ? '' : 's'}.</div>`;
            resultsDiv.innerHTML = html + summary;
        }

        // ---------- Promote Functions ----------
        function promoteStudent(studentId) {
            if (!confirm('Are you sure you want to promote this student to the next grade level?')) return;
            fetch('php/promote_student.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ student_id: studentId })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    if (typeof loadAllUsersDirectory === 'function') loadAllUsersDirectory();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error promoting student:', error);
                alert('Network error. Please try again.');
            });
        }

        function openBatchPromoteModal() {
            const modal = document.getElementById('batchPromoteModal');
            if (modal) modal.style.display = 'flex';
            const gradeSel = document.getElementById('batchPromoteGrade');
            if (!gradeSel) return;
            gradeSel.innerHTML = '<option value="">Select Grade</option>';
            const sectionSel = document.getElementById('batchPromoteSection');
            sectionSel.innerHTML = '<option value="">All Sections</option>';
            
            fetch('php/get_users.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({})
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.users) {
                        const students = data.users.filter(u => u.role === 'student');
                        const grades = [...new Set(students.map(s => s.grade_level).filter(g => g && g !== 'Grade 12'))];
                        grades.sort().forEach(grade => {
                            const option = document.createElement('option');
                            option.value = grade;
                            option.textContent = grade;
                            gradeSel.appendChild(option);
                        });
                    }
                })
                .catch(err => console.error(err));
        }

        function closeBatchPromoteModal() {
            const modal = document.getElementById('batchPromoteModal');
            if (modal) modal.style.display = 'none';
        }

        function updateBatchSections() {
            const grade = document.getElementById('batchPromoteGrade')?.value;
            const sectionSel = document.getElementById('batchPromoteSection');
            if (!grade) {
                sectionSel.innerHTML = '<option value="">All Sections</option>';
                return;
            }
            const sections = {
                'Grade 7': ['Love', 'Joy'],
                'Grade 8': ['Patience', 'Peace'],
                'Grade 9': ['Goodness', 'Kindness'],
                'Grade 10': ['Gentleness', 'Faithfulness'],
                'Grade 11': ['Self-Control', 'Honesty'],
                'Grade 12': ['Humility', 'Meekness']
            }[grade] || [];
            sectionSel.innerHTML = '<option value="">All Sections</option>';
            sections.forEach(s => {
                const option = document.createElement('option');
                option.value = s;
                option.textContent = s;
                sectionSel.appendChild(option);
            });
        }

        function batchPromote() {
            const grade = document.getElementById('batchPromoteGrade')?.value;
            const section = document.getElementById('batchPromoteSection')?.value || '';
            if (!grade) {
                alert('Please select a grade to promote');
                return;
            }
            if (!confirm(`Are you sure you want to promote ALL students from ${grade}${section ? ' (section ' + section + ')' : ''}?`)) return;
            fetch('php/batch_promote.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ grade: grade, section: section })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    closeBatchPromoteModal();
                    if (typeof loadAllUsersDirectory === 'function') loadAllUsersDirectory();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error in batch promote:', error);
                alert('Network error');
            });
        }

        function toggleUserStatus(userId, newStatus) {
            fetch('php/update_user_status.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ user_id: userId, status: newStatus ? 1 : 0 })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (typeof loadAllUsersDirectory === 'function') loadAllUsersDirectory();
                } else {
                    alert('Error updating status: ' + data.message);
                    if (typeof loadAllUsersDirectory === 'function') loadAllUsersDirectory();
                }
            })
            .catch(error => {
                console.error('Error toggling status:', error);
                alert('Network error');
                if (typeof loadAllUsersDirectory === 'function') loadAllUsersDirectory();
            });
        }

        // ---------- Enrollment Modal Functions ----------
        function openEnrollmentSearchModal() {
            const modal = document.getElementById('enrollmentSearchModal');
            if (modal) modal.style.display = 'flex';
            filterEnrollments();
        }

        function closeEnrollmentSearchModal() {
            const modal = document.getElementById('enrollmentSearchModal');
            if (modal) modal.style.display = 'none';
        }

        let esPage = 1, esPerPage = 10;
        function filterEnrollments(page = 1) {
            esPage = page;
            const searchTerm = document.getElementById('enrollmentSearchInput')?.value.toLowerCase() || '';
            
            const filterPending = document.getElementById('filterPending')?.checked || false;
            const filterApproved = document.getElementById('filterApproved')?.checked || false;
            const filterNeedsDocs = document.getElementById('filterNeedsDocs')?.checked || false;
            const filterRejected = document.getElementById('filterRejected')?.checked || false;
            
            const statuses = [];
            if (filterPending) statuses.push('pending');
            if (filterApproved) statuses.push('approved');
            if (filterNeedsDocs) statuses.push('needs_docs');
            if (filterRejected) statuses.push('rejected');
            
            let filtered = allEnrollments.filter(enrollment => {
                const matchesSearch = searchTerm === '' || 
                    enrollment.full_name?.toLowerCase().includes(searchTerm) ||
                    enrollment.email?.toLowerCase().includes(searchTerm) ||
                    enrollment.phone?.toLowerCase().includes(searchTerm);
                
                if (!matchesSearch) return false;
                if (statuses.length > 0 && !statuses.includes(enrollment.status)) return false;
                return true;
            });
            const totalFiltered = filtered.length;
            const startIndex = (esPage - 1) * esPerPage;
            const endIndex = Math.min(startIndex + esPerPage, totalFiltered);
            const paginatedResults = filtered.slice(startIndex, endIndex);
            
            displayEnrollmentSearchResults(paginatedResults);
            updateEnrollmentSearchPagination(totalFiltered, esPage, esPerPage);
        }

        function displayEnrollmentSearchResults(enrollments) {
            const resultsDiv = document.getElementById('enrollmentSearchResults');
            if (!resultsDiv) return;
            
            if (enrollments.length === 0) {
                resultsDiv.innerHTML = '<div class="text-center text-gray-500 py-10">No enrollments found matching your criteria.</div>';
                return;
            }
            
            let html = '';
            enrollments.forEach(enrollment => {
                const statusText = enrollment.status ? enrollment.status.charAt(0).toUpperCase() + enrollment.status.slice(1) : 'Pending';
                let statusColor = '#6c757d';
                if (enrollment.status === 'approved') statusColor = '#10b981';
                if (enrollment.status === 'needs_docs') statusColor = '#f59e0b';
                if (enrollment.status === 'rejected') statusColor = '#ef4444';
                
                html += `
                    <div class="search-result-item p-4 border-b border-gray-200 cursor-pointer hover:bg-gray-50" onclick="viewEnrollmentDetails(${enrollment.id})">
                        <div class="user-name font-semibold text-[#0a2d63] mb-1">${enrollment.full_name}</div>
                        <div class="user-details text-xs text-gray-600 flex gap-2 flex-wrap">
                            <span>${enrollment.email}</span>
                            <span>•</span>
                            <span>${enrollment.phone}</span>
                            <span>•</span>
                            <span style="color: ${statusColor}; font-weight: 600;">${statusText}</span>
                        </div>
                        <div class="text-xs text-gray-500 mt-1">
                            Submitted: ${new Date(enrollment.created_at).toLocaleDateString()}
                        </div>
                    </div>
                `;
            });
            
            resultsDiv.innerHTML = html;
        }

        function updateEnrollmentSearchPagination(total, page, perPage) {
            const paginationDiv = document.getElementById('enrollmentSearchPagination');
            const paginationInfo = document.getElementById('enrollmentSearchInfo');
            const paginationButtons = document.getElementById('enrollmentSearchButtons');
            if (!paginationDiv || !paginationInfo || !paginationButtons) return;
            
            if (total <= perPage) {
                paginationDiv.style.display = 'none';
                return;
            }
            
            paginationDiv.style.display = 'flex';
            const totalPages = Math.ceil(total / perPage);
            const start = ((page - 1) * perPage) + 1;
            const end = Math.min(page * perPage, total);
            
            paginationInfo.textContent = `Showing ${start} to ${end} of ${total} results`;
            
            let buttonsHtml = '';
            buttonsHtml += `<button class="pagination-btn border border-gray-300 bg-white px-3 py-1 rounded text-sm hover:bg-gray-100 disabled:opacity-50 disabled:cursor-not-allowed" onclick="filterEnrollments(${page - 1})" ${page === 1 ? 'disabled' : ''}>Previous</button>`;
            
            for (let i = 1; i <= totalPages; i++) {
                if (i === 1 || i === totalPages || (i >= page - 2 && i <= page + 2)) {
                    buttonsHtml += `<button class="pagination-btn border border-gray-300 px-3 py-1 rounded text-sm ${i === page ? 'bg-[#0a2d63] text-white border-[#0a2d63]' : 'bg-white hover:bg-gray-100'}" onclick="filterEnrollments(${i})">${i}</button>`;
                } else if (i === page - 3 || i === page + 3) {
                    buttonsHtml += `<button class="pagination-btn border border-gray-300 px-3 py-1 rounded text-sm bg-white" disabled>...</button>`;
                }
            }
            
            buttonsHtml += `<button class="pagination-btn border border-gray-300 bg-white px-3 py-1 rounded text-sm hover:bg-gray-100 disabled:opacity-50 disabled:cursor-not-allowed" onclick="filterEnrollments(${page + 1})" ${page === totalPages ? 'disabled' : ''}>Next</button>`;
            
            paginationButtons.innerHTML = buttonsHtml;
        }

        function changeEnrollmentPerPage() {
            const select = document.getElementById('enrollmentPerPage');
            const customInput = document.getElementById('enrollmentCustomPerPage');
            if (!select || !customInput) return;
            
            if (select.value === 'custom') {
                customInput.style.display = 'flex';
            } else {
                customInput.style.display = 'none';
                esPerPage = parseInt(select.value);
                esPage = 1;
                filterEnrollments(1);
            }
        }

        function applyEnrollmentCustomPerPage() {
            const customValue = document.getElementById('enrollmentCustomNumber')?.value;
            if (customValue && customValue > 0) {
                esPerPage = parseInt(customValue);
                esPage = 1;
                filterEnrollments(1);
                document.getElementById('enrollmentCustomPerPage').style.display = 'none';
                document.getElementById('enrollmentPerPage').value = 'custom';
            }
        }

        function viewEnrollmentDetails(enrollmentId) {
            closeEnrollmentSearchModal();
        }

        // ---------- Document & Status Functions ----------
        function viewDocuments(enrollmentId) {
            if (!enrollmentId) {
                alert('Invalid enrollment ID');
                return;
            }
    
            const modal = document.getElementById('documentModal');
            if (modal) modal.style.display = 'flex';
            const documentList = document.getElementById('documentList');
            if (!documentList) return;
            documentList.innerHTML = '<div class="loading text-center text-gray-500 py-10">Loading documents...</div>';
            fetch(`php/get_enrollment_documents.php?enrollment_id=${enrollmentId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.documents && data.documents.length > 0) {
                        let html = '<div class="document-grid grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-5">';
                        data.documents.forEach(doc => {
                            let fileName = doc.document_filename || doc.file_name || 'Document';
                            let fileExt = fileName.includes('.') ? fileName.split('.').pop().toLowerCase() : '';
                            let icon = '[FILE]';
                            if (['jpg','jpeg','png','gif','bmp','webp'].includes(fileExt)) icon = '[IMAGE]';
                            else if (['pdf'].includes(fileExt)) icon = '[PDF]';
                            else if (['doc','docx'].includes(fileExt)) icon = '[DOC]';
                            else if (['xls','xlsx','csv'].includes(fileExt)) icon = '[SPREADSHEET]';
                            else if (['ppt','pptx'].includes(fileExt)) icon = '[PRESENTATION]';
                            else if (['txt','rtf'].includes(fileExt)) icon = '[TEXT]';
                            else if (['zip','rar','7z'].includes(fileExt)) icon = '[ARCHIVE]';
                            let docType = doc.document_type || 'Document';
                            let filePath = doc.document_path || doc.file_path || doc.path || '';
                            let fileSize = doc.file_size ? (parseInt(doc.file_size) < 1024 ? parseInt(doc.file_size) + ' B' : (parseInt(doc.file_size) < 1048576 ? (parseInt(doc.file_size)/1024).toFixed(1) + ' KB' : (parseInt(doc.file_size)/1048576).toFixed(1) + ' MB')) : '';
                            let uploadDate = doc.created_at ? new Date(doc.created_at).toLocaleDateString() : '';

                            html += `
                                <div class="document-item bg-gray-50 border border-gray-200 rounded p-5 text-center hover:-translate-y-1 hover:shadow-md transition flex flex-col justify-between">
                                    <div class="document-icon text-sm font-bold text-[#0a2d63] bg-gray-200 px-3 py-1 rounded inline-block mx-auto mb-4 font-mono">${icon}</div>
                                    <div class="document-name font-semibold text-gray-800 mb-2 break-words">${docType}</div>
                                    <div class="document-type text-xs text-gray-600 mb-2 bg-gray-100 px-2 py-1 rounded inline-block break-words w-full overflow-hidden text-ellipsis">${fileName}</div>
                                    ${fileSize ? `<div class="text-xs text-gray-500 mb-2">${fileSize}</div>` : ''}
                                    ${uploadDate ? `<div class="text-xs text-gray-500 mb-2">Uploaded: ${uploadDate}</div>` : ''}
                                    <div class="document-actions flex flex-wrap gap-2 justify-center mt-2">
                                        <a href="${filePath}" target="_blank" class="document-btn bg-[#0a2d63] text-white px-4 py-2 rounded text-sm font-medium hover:bg-[#08306b] transition no-underline inline-block flex-1 min-w-[80px]">View</a>
                                        <a href="${filePath}" download="${fileName}" class="document-btn bg-green-600 text-white px-4 py-2 rounded text-sm font-medium hover:bg-green-700 transition no-underline inline-block flex-1 min-w-[80px]">Download</a>
                                    </div>
                                </div>
                            `;
                        });
                        html += '</div>';
                        documentList.innerHTML = html;
                    } else {
                        documentList.innerHTML = '<div class="text-center text-gray-500 py-10">No documents uploaded for this enrollment.</div>';
                    }
                })
                .catch(error => {
                    console.error('Error loading documents:', error);
                    documentList.innerHTML = '<div class="text-center text-red-600 py-10">Error loading documents. Please try again.</div>';
                });
        }

        function closeDocumentModal() {
            const modal = document.getElementById('documentModal');
            if (modal) modal.style.display = 'none';
        }

        const REJECTION_REQUIREMENTS = {
            common: [
                'Accomplished Application Form',
                'Accomplished ESC Application Form',
                'PSA Birth Certificate (Original Copy)',
                'Report Card SF9 (Original Copy)',
                'Certificate of Good Moral Standing (Original Copy)',
                'Certificate of Transfer Credentials or Honorable Dismissal (Original copy)',
                '4 pieces of 2x2 picture with white background'
            ],
            grade7: [
                'Diploma (for JHS Grade 7 Applicants) (Photocopy)',
                'Parents Proof of Income (for Grade 7 ESC Grant Applicants)'
            ],
            grade11: [
                'Certificate of Completion (for SHS Grade 11 Applicants) (Photocopy)'
            ]
        };

        async function loadStudentDocumentRequirements() {
            if (userRole !== 'student') return;
            try {
                const resp = await fetch('php/document_management.php?action=student_requirements');
                const data = await parseJsonResponse(resp);
                if (!data.success) return;

                const select = document.getElementById('studentRequirementSelect');
                const checklist = document.getElementById('studentRequirementsChecklist');
                const uploads = document.getElementById('studentUploadedDocuments');
                if (!select || !checklist || !uploads) return;

                select.innerHTML = (data.requirements || []).map(req => `<option value="${req}">${req}</option>`).join('');
                checklist.innerHTML = (data.requirements || []).map(req => {
                    const verified = !!(data.verified || {})[req];
                    return `<label class="flex items-center justify-between border border-gray-200 rounded p-2">
                        <span class="text-sm text-gray-700">${req}</span>
                        <span class="w-4 h-4 rounded-full ${verified ? 'bg-green-500' : 'bg-gray-300'}"></span>
                    </label>`;
                }).join('') || '<div class="text-sm text-gray-500">No requirements available.</div>';

                uploads.innerHTML = (data.documents || []).map(doc => `
                    <div class="text-sm p-2 border border-gray-200 rounded flex justify-between items-center gap-2">
                        <span class="text-gray-700 break-all">${doc.document_filename}</span>
                        <a href="${doc.document_path}" target="_blank" class="text-blue-600 hover:underline">View</a>
                    </div>
                `).join('') || '<div class="text-sm text-gray-500">No uploaded documents yet.</div>';
            } catch (e) {
                console.error(e);
            }
        }

        async function uploadStudentRequirementDocument() {
            const requirement = document.getElementById('studentRequirementSelect')?.value || '';
            const fileInput = document.getElementById('studentDocumentFile');
            const file = fileInput?.files?.[0];
            if (!requirement || !file) {
                alert('Please select a requirement and file.');
                return;
            }
            const formData = new FormData();
            formData.append('action', 'student_upload');
            formData.append('requirement_name', requirement);
            formData.append('document', file);
            const resp = await fetch('php/document_management.php', { method: 'POST', body: formData });
            const data = await parseJsonResponse(resp);
            if (!data.success) {
                alert(data.message || 'Upload failed');
                return;
            }
            alert('Document uploaded successfully.');
            if (fileInput) fileInput.value = '';
            loadStudentDocumentRequirements();
        }

        let documentManagementPageSize = 10;
        let documentManagementCurrentPage = 1;

        async function loadDocumentManagementStudents(page = 1) {
            const list = document.getElementById('documentManagementList');
            if (!list) return;
            list.innerHTML = '<div class="text-center text-gray-500 py-8">Loading students...</div>';
            const grade = document.getElementById('documentGradeFilter')?.value || '';
            const section = document.getElementById('documentSectionFilter')?.value || '';
            const search = document.getElementById('documentSearch')?.value || '';
            const docStatus = document.getElementById('documentStatusFilter')?.value || '';
            const params = new URLSearchParams();
            params.append('action', 'admin_list');
            params.append('page', page);
            params.append('per_page', documentManagementPageSize);
            if (grade) params.append('grade', grade);
            if (section) params.append('section', section);
            if (search) params.append('search', search);
            if (docStatus) params.append('document_status', docStatus);
            const resp = await fetch('php/document_management.php?' + params.toString());
            const data = await parseJsonResponse(resp);
            if (!data.success) {
                list.innerHTML = '<div class="text-center text-red-600 py-8">Unable to load students.</div>';
                return;
            }
            const students = data.students || [];
            const total = data.total || 0;
            
            list.innerHTML = students.map(s => `
                <div class="border border-gray-200 rounded">
                    <button class="w-full text-left p-3 font-medium text-[#0a2d63] bg-gray-50 hover:bg-gray-100" onclick="toggleDocumentStudent(${s.id}, this)">
                        ${s.full_name} <span class="text-gray-500 text-sm">(${s.grade_level || 'N/A'} - ${s.section || 'N/A'})</span>
                    </button>
                    <div class="hidden p-3 space-y-2" id="document-student-${s.id}"></div>
                </div>
            `).join('') || '<div class="text-center text-gray-500 py-8">No students found.</div>';
            
            // Update pagination
            updateDocumentManagementPagination(total, page, documentManagementPageSize);
        }

        function updateDocumentManagementPagination(total, page, perPage) {
            const paginationDiv = document.getElementById('documentManagementPagination');
            if (!paginationDiv) return;
            
            if (total <= perPage) {
                paginationDiv.style.display = 'none';
                return;
            }
            
            paginationDiv.style.display = 'flex';
            const totalPages = Math.ceil(total / perPage);
            const start = ((page - 1) * perPage) + 1;
            const end = Math.min(page * perPage, total);
            
            const paginationInfo = document.getElementById('documentManagementPaginationInfo');
            const paginationButtons = document.getElementById('documentManagementPaginationButtons');
            
            if (paginationInfo) {
                paginationInfo.textContent = `Showing ${start} to ${end} of ${total} students`;
            }
            
            if (paginationButtons) {
                let buttonsHtml = '';
                buttonsHtml += `<button class="pagination-btn border border-gray-300 bg-white px-3 py-1 rounded text-sm hover:bg-gray-100 disabled:opacity-50 disabled:cursor-not-allowed" onclick="loadDocumentManagementStudents(${page - 1})" ${page === 1 ? 'disabled' : ''}>Previous</button>`;
                
                for (let i = 1; i <= totalPages; i++) {
                    if (i === 1 || i === totalPages || (i >= page - 1 && i <= page + 1)) {
                        buttonsHtml += `<button class="pagination-btn border border-gray-300 px-3 py-1 rounded text-sm ${i === page ? 'bg-[#0a2d63] text-white border-[#0a2d63]' : 'bg-white hover:bg-gray-100'}" onclick="loadDocumentManagementStudents(${i})">${i}</button>`;
                    } else if (i === page - 2 || i === page + 2) {
                        buttonsHtml += `<button class="pagination-btn border border-gray-300 px-3 py-1 rounded text-sm bg-white" disabled>...</button>`;
                    }
                }
                
                buttonsHtml += `<button class="pagination-btn border border-gray-300 bg-white px-3 py-1 rounded text-sm hover:bg-gray-100 disabled:opacity-50 disabled:cursor-not-allowed" onclick="loadDocumentManagementStudents(${page + 1})" ${page === totalPages ? 'disabled' : ''}>Next</button>`;
                paginationButtons.innerHTML = buttonsHtml;
            }
        }

        function setDocumentManagementPageSize(size) {
            documentManagementPageSize = parseInt(size);
            documentManagementCurrentPage = 1;
            loadDocumentManagementStudents(1);
        }

        async function loadDocumentSections() {
            const grade = document.getElementById('documentGradeFilter')?.value || '';
            const sectionSelect = document.getElementById('documentSectionFilter');
            if (!sectionSelect) return;
            sectionSelect.innerHTML = '<option value="">All Sections</option>';
            if (grade) {
                try {
                    const resp = await fetch('php/get_sections.php?grade=' + encodeURIComponent(grade));
                    const data = await parseJsonResponse(resp);
                    if (data.success && data.sections) {
                        // Filter sections for the selected grade
                        const filteredSections = (data.sections || []).filter(sec => sec && sec.trim());
                        if (filteredSections.length > 0) {
                            filteredSections.sort().forEach(sec => {
                                const option = document.createElement('option');
                                option.value = sec;
                                option.textContent = sec;
                                sectionSelect.appendChild(option);
                            });
                        }
                    }
                } catch (e) {
                    console.error('Failed to load sections:', e);
                }
            }
            documentManagementCurrentPage = 1;
            loadDocumentManagementStudents(1);
        }

        async function toggleDocumentStudent(studentUserId, btnEl) {
            const body = document.getElementById(`document-student-${studentUserId}`);
            if (!body) return;
            const willShow = body.classList.contains('hidden');
            body.classList.toggle('hidden');
            if (!willShow) return;

            body.innerHTML = '<div class="text-sm text-gray-500">Loading documents...</div>';
            const resp = await fetch('php/document_management.php?action=admin_details&student_user_id=' + encodeURIComponent(studentUserId));
            const data = await parseJsonResponse(resp);
            if (!data.success) {
                body.innerHTML = '<div class="text-sm text-red-600">Failed to load details.</div>';
                return;
            }
            const verified = data.verified || {};
            const checklist = (data.requirements || []).map(req => `
                <label class="flex items-center justify-between border border-gray-200 rounded p-2">
                    <span class="text-sm text-gray-700">${req}</span>
                    <input type="checkbox" ${verified[req] ? 'checked' : ''} onchange="setDocumentVerification(${data.enrollment_id || 0}, '${String(req).replace(/'/g, "\\'")}', this.checked)" class="w-4 h-4 accent-green-600">
                </label>
            `).join('');
            const docs = (data.documents || []).map(doc => `
                <div class="text-sm p-2 border border-gray-200 rounded flex justify-between items-center gap-2">
                    <span class="text-gray-700 break-all">${doc.document_filename}</span>
                    <a href="${doc.document_path}" target="_blank" class="text-blue-600 hover:underline">View</a>
                </div>
            `).join('') || '<div class="text-sm text-gray-500">No uploaded files.</div>';

            body.innerHTML = `
                <div>
                    <h5 class="font-semibold text-gray-800 mb-2">Uploaded Documents</h5>
                    <div class="space-y-2">${docs}</div>
                </div>
                <div class="pt-2">
                    <h5 class="font-semibold text-gray-800 mb-2">Verification Checklist</h5>
                    <div class="space-y-2">${checklist || '<div class="text-sm text-gray-500">No requirements found.</div>'}</div>
                </div>
            `;
        }

        async function setDocumentVerification(enrollmentId, requirementName, isVerified) {
            const formData = new FormData();
            formData.append('action', 'admin_verify');
            formData.append('enrollment_id', String(enrollmentId));
            formData.append('requirement_name', requirementName);
            formData.append('is_verified', isVerified ? '1' : '0');
            const resp = await fetch('php/document_management.php', { method: 'POST', body: formData });
            const data = await parseJsonResponse(resp);
            if (!data.success) {
                alert(data.message || 'Failed to update verification');
            }
        }

        function openChangePasswordModal() {
            const modal = document.getElementById('changePasswordModal');
            if (modal) modal.style.display = 'flex';
        }

        function closeChangePasswordModal() {
            const modal = document.getElementById('changePasswordModal');
            if (modal) modal.style.display = 'none';
            const form = document.getElementById('changePasswordForm');
            if (form) form.reset();
            const msg = document.getElementById('changePasswordError');
            if (msg) msg.textContent = '';
        }

        async function submitChangePassword() {
            const oldPassword = document.getElementById('changeOldPassword')?.value || '';
            const newPassword = document.getElementById('changeNewPassword')?.value || '';
            const confirmPassword = document.getElementById('changeConfirmPassword')?.value || '';
            const errorEl = document.getElementById('changePasswordError');
            const formData = new FormData();
            formData.append('old_password', oldPassword);
            formData.append('new_password', newPassword);
            formData.append('confirm_password', confirmPassword);
            const resp = await fetch('php/change_password.php', { method: 'POST', body: formData });
            const data = await parseJsonResponse(resp);
            if (!data.success) {
                if (errorEl) errorEl.textContent = data.message || 'Failed to update password';
                return;
            }
            alert('Password updated successfully.');
            closeChangePasswordModal();
        }

        function getRequiredDocsByGrade(gradeLevel) {
            const g = String(gradeLevel || '').replace(/[^0-9]/g, '');
            let docs = [...REJECTION_REQUIREMENTS.common];
            if (g === '7') docs = docs.concat(REJECTION_REQUIREMENTS.grade7);
            if (g === '11') docs = docs.concat(REJECTION_REQUIREMENTS.grade11);
            return docs;
        }

        function openRejectEnrollmentModalById(enrollmentId) {
            const enrollment = allEnrollments.find(e => String(e.id) === String(enrollmentId));
            if (!enrollment) {
                alert('Enrollment details not found. Please reload the list.');
                return;
            }
            const modal = document.getElementById('rejectEnrollmentModal');
            if (!modal) return;

            document.getElementById('rejectEnrollmentId').value = enrollment.id;
            document.getElementById('rejectEnrollmentName').textContent = enrollment.full_name || 'Enrollee';
            document.getElementById('rejectEnrollmentGrade').textContent = enrollment.grade_level || '-';
            document.getElementById('rejectReasonDocs').checked = false;
            document.getElementById('rejectReasonData').checked = false;
            document.getElementById('rejectCustomMessage').value = '';
            document.getElementById('rejectReasonError').style.display = 'none';
            document.getElementById('rejectDocsError').style.display = 'none';
            document.getElementById('rejectMissingDocsContainer').style.display = 'none';

            const docsWrap = document.getElementById('rejectMissingDocsList');
            docsWrap.innerHTML = '';
            const docs = getRequiredDocsByGrade(enrollment.grade_level);
            docs.forEach((doc, idx) => {
                const escapedDoc = doc.replace(/"/g, '&quot;');
                docsWrap.innerHTML += `
                    <label class="flex items-start gap-2 text-sm text-gray-700">
                        <input type="checkbox" class="reject-missing-doc w-4 h-4 mt-1" value="${escapedDoc}">
                        <span>${doc}</span>
                    </label>
                `;
            });
            modal.style.display = 'flex';
        }

        function closeRejectEnrollmentModal() {
            const modal = document.getElementById('rejectEnrollmentModal');
            if (modal) modal.style.display = 'none';
        }

        function toggleMissingDocsBox() {
            const selected = document.getElementById('rejectReasonDocs')?.checked;
            const box = document.getElementById('rejectMissingDocsContainer');
            if (box) box.style.display = selected ? 'block' : 'none';
        }

        function submitRejectEnrollment() {
            const enrollmentId = document.getElementById('rejectEnrollmentId')?.value;
            const reasonDocs = document.getElementById('rejectReasonDocs')?.checked;
            const reasonData = document.getElementById('rejectReasonData')?.checked;
            const customMessage = (document.getElementById('rejectCustomMessage')?.value || '').trim();
            const reasonError = document.getElementById('rejectReasonError');
            const docsError = document.getElementById('rejectDocsError');

            if (reasonError) reasonError.style.display = 'none';
            if (docsError) docsError.style.display = 'none';

            if (!reasonDocs && !reasonData) {
                if (reasonError) reasonError.style.display = 'block';
                return;
            }

            const missingDocs = Array.from(document.querySelectorAll('.reject-missing-doc:checked'))
                .map(el => el.value);
            if (reasonDocs && missingDocs.length === 0) {
                if (docsError) docsError.style.display = 'block';
                return;
            }

            const reasons = [];
            if (reasonDocs) reasons.push('lack_of_documents');
            if (reasonData) reasons.push('insufficient_data');

            const body = new URLSearchParams();
            body.set('enrollment_id', String(enrollmentId));
            body.set('status', 'rejected');
            body.set('reasons', JSON.stringify(reasons));
            body.set('missing_documents', JSON.stringify(missingDocs));
            body.set('custom_message', customMessage);

            fetch('php/update_enrollment_status.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(data.message || 'Enrollment rejected successfully.');
                        closeRejectEnrollmentModal();
                        loadEnrollments();
                    } else {
                        alert('Error rejecting enrollment: ' + (data.message || 'Unknown error'));
                    }
                })
                .catch(error => {
                    console.error('Error rejecting enrollment:', error);
                    alert('Error rejecting enrollment');
                });
        }

        function acceptEnrollment(enrollmentId) {
            if (!confirm('Accept this enrollment? A student account will be created and login details will be emailed.')) {
                return;
            }
            const body = new URLSearchParams();
            body.set('enrollment_id', String(enrollmentId));
            fetch('php/accept_enrollment_student.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        let msg = data.message || 'Enrollment accepted.';
                        if (data.mail_sent === false && data.mail_warning) {
                            msg += '\n\nEmail could not be sent: ' + data.mail_warning;
                        }
                        if (data.assessment_mail_sent === false && data.assessment_mail_warning) {
                            msg += '\n\nAssessment email could not be sent: ' + data.assessment_mail_warning;
                        }
                        alert(msg);
                        if (typeof loadEnrollments === 'function') {
                            loadEnrollments();
                        }
                    } else {
                        alert(data.message || 'Accept failed');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Failed to accept enrollment');
                });
        }

        function openAddUserModalWithEnrollment(enrollee) {
            closeAddUserModal();
            const modal = document.getElementById('addUserModal');
            if (modal) modal.style.display = 'flex';

            function setElementValue(id, value) {
                const el = document.getElementById(id);
                if (el) el.value = value || '';
            }

            function setFieldByName(name, value) {
                const field = document.querySelector(`[name="${name}"]`);
                if (field) field.value = value || '';
            }

            const roleSelect = document.getElementById('modalRoleSelect');
            if (roleSelect) {
                roleSelect.value = 'student';
                roleSelect.disabled = true;
            }

            // Show the student fields so admin/registrar can review/add grade, section, and LRN
            const studentFields = document.getElementById('modalStudentFields');
            if (studentFields) {
                studentFields.classList.remove('hidden');
            }
            syncModalStudentFieldConstraints();
            syncModalAgeFromBirthdate();

            function normalizeGradeLevel(grade) {
                const gradeMap = {
                    '7': 'Grade 7',
                    '8': 'Grade 8',
                    '9': 'Grade 9',
                    '10': 'Grade 10',
                    '11': 'Grade 11',
                    '12': 'Grade 12',
                    'Grade 7': 'Grade 7',
                    'Grade 8': 'Grade 8',
                    'Grade 9': 'Grade 9',
                    'Grade 10': 'Grade 10',
                    'Grade 11': 'Grade 11',
                    'Grade 12': 'Grade 12'
                };
                return gradeMap[grade] || grade;
            }

            const normalizedGrade = normalizeGradeLevel(enrollee.grade_level);
            setElementValue('modalGradeLevel', normalizedGrade);
            updateModalSections();
            if (enrollee.section) {
                setElementValue('modalSectionSelect', enrollee.section);
            }
            setElementValue('modalLrnField', enrollee.lrn || '');
            setElementValue('modalGender', enrollee.gender);
            setElementValue('modalBirthdate', enrollee.birthdate);
            let phoneValue = enrollee.phone || '';
            if (phoneValue.startsWith('+63')) phoneValue = phoneValue.substring(3);
            setElementValue('modalPhone', phoneValue);
            if (enrollee.strand) {
                setElementValue('modalStrand', enrollee.strand);
            }

            setFieldByName('first_name', enrollee.first_name || '');
            setFieldByName('middle_name', enrollee.middle_name || '');
            setFieldByName('last_name', enrollee.last_name || '');
            setFieldByName('suffix', enrollee.suffix || '');
            setFieldByName('email', enrollee.email);

            const passwordField = document.querySelector('input[name="password"]');
            if (passwordField) passwordField.value = 'baa123';
            const usernameField = document.querySelector('input[name="username"]');
            if (usernameField) usernameField.value = '';

            const enrollmentIdField = document.getElementById('modalEnrollmentId');
            if (enrollmentIdField) enrollmentIdField.value = enrollee.id;
        }

        function toggleModalStudentFields() {
            const roleSelect = document.getElementById('modalRoleSelect');
            const studentFields = document.getElementById('modalStudentFields');
            if (!roleSelect || !studentFields) return;

            if (roleSelect.value === 'student') {
                studentFields.classList.remove('hidden');
            } else {
                studentFields.classList.add('hidden');
            }
            syncModalStudentFieldConstraints();
            // Always sync age from birthdate for all users
            syncModalAgeFromBirthdate();
        }

        function updateModalSections() {
            const gradeLevel = document.getElementById('modalGradeLevel')?.value;
            const sectionSelect = document.getElementById('modalSectionSelect');
            const strandContainer = document.getElementById('modalStrandContainer');
            
            const gradeSections = {
                'Grade 7': ['Love', 'Joy'],
                'Grade 8': ['Patience', 'Peace'],
                'Grade 9': ['Goodness', 'Kindness'],
                'Grade 10': ['Gentleness', 'Faithfulness'],
                'Grade 11': ['Self-Control', 'Honesty'],
                'Grade 12': ['Humility', 'Meekness']
            };
            if (sectionSelect) {
                sectionSelect.innerHTML = '<option value="">Select Section</option>';
                if (gradeLevel && gradeSections[gradeLevel]) {
                    gradeSections[gradeLevel].forEach(section => {
                        const option = document.createElement('option');
                        option.value = section;
                        option.textContent = section;
                        sectionSelect.appendChild(option);
                    });
                }
            }

            if (strandContainer) {
                if (gradeLevel === 'Grade 11' || gradeLevel === 'Grade 12') {
                    strandContainer.classList.remove('hidden');
                } else {
                    strandContainer.classList.add('hidden');
                }
            }
            syncModalStudentFieldConstraints();
        }

        function updateStatus(enrollmentId, status) {
            if (status === 'rejected') {
                const enrollee = allEnrollments.find(e => String(e.id) === String(enrollmentId));
                if (!enrollee) {
                    alert('Enrollment details not found. Please reload.');
                    return;
                }
                openRejectEnrollmentModalById(enrollmentId);
                return;
            }
            const statusText = status === 'approved' ? 'accept' : status === 'rejected' ? 'reject' : 'request documents';
            if (confirm(`Are you sure you want to ${statusText} this enrollment?`)) {
                fetch('php/update_enrollment_status.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `enrollment_id=${enrollmentId}&status=${status}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(`Enrollment ${statusText}ed successfully!`);
                        loadEnrollments();
                    } else {
                        alert('Error updating enrollment: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error updating enrollment:', error);
                    alert('Error updating enrollment');
                });
            }
        }

        function deleteEnrollment(enrollmentId, studentName) {
            if (confirm(`Are you sure you want to delete the enrollment for ${studentName}? This action cannot be undone.`)) {
                fetch('php/delete_enrollment.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `enrollment_id=${enrollmentId}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Enrollment deleted successfully!');
                        loadEnrollments();
                    } else {
                        alert('Error deleting enrollment: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error deleting enrollment:', error);
                    alert('Error deleting enrollment');
                });
            }
        }

        function generatePDF(enrollmentId, enrollmentStatus = '') {
            if (String(enrollmentStatus).toLowerCase() !== 'approved') {
                alert('PDF generation is available only for accepted enrollments.');
                return;
            }
            window.open('php/generate_enrollment_assessment_pdf.php?enrollment_id=' + enrollmentId, '_blank');
        }

        // ---------- Profile Picture Upload Functions ----------
        function triggerProfilePicUpload() {
            const input = document.getElementById('profilePicFileInput');
            if (input) input.click();
        }

        function uploadProfilePicture(input) {
            if (!input.files || !input.files[0]) return;
            const file = input.files[0];
            
            if (file.size > 5 * 1024 * 1024) {
                alert('File size cannot exceed 5MB.');
                return;
            }
            
            const formData = new FormData();
            formData.append('profile_pic', file);
            
            fetch('php/upload_profile_picture.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    window.location.reload();
                } else {
                    alert(data.message || 'Upload failed.');
                }
            })
            .catch(err => {
                console.error(err);
                alert('Network error during upload.');
            });
        }

        // ---------- Photo Approvals JS Functions ----------
        function loadPhotoApprovals() {
            const tbody = document.getElementById('photoApprovalsTableBody');
            if (!tbody) return;
            tbody.innerHTML = '<tr><td colspan="4" class="text-center py-6 text-gray-500">Loading pending photos...</td></tr>';
            
            fetch('php/get_pending_photos.php')
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.photos && data.photos.length > 0) {
                        let html = '';
                        data.photos.forEach(photo => {
                            html += `
                                <tr class="border-b border-gray-100 hover:bg-gray-50 transition">
                                    <td class="px-6 py-4">
                                        <div class="w-16 h-16 rounded-full overflow-hidden border border-gray-200 cursor-pointer" onclick="previewImage('${photo.profile_picture}')">
                                            <img src="${photo.profile_picture}" class="w-full h-full object-cover" alt="Pending photo">
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 font-semibold text-gray-800">${photo.full_name}</td>
                                    <td class="px-6 py-4 text-gray-600 uppercase tracking-wider text-xs">${photo.role}</td>
                                    <td class="px-6 py-4 space-x-2">
                                        <button onclick="handlePhotoApprovalAction(${photo.id}, 'approve')" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded text-sm font-medium transition">Approve</button>
                                        <button onclick="handlePhotoApprovalAction(${photo.id}, 'reject')" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded text-sm font-medium transition">Reject</button>
                                    </td>
                                </tr>
                            `;
                        });
                        tbody.innerHTML = html;
                    } else {
                        tbody.innerHTML = '<tr><td colspan="4" class="text-center py-6 text-gray-500">No pending profile picture requests.</td></tr>';
                    }
                })
                .catch(err => {
                    console.error(err);
                    tbody.innerHTML = '<tr><td colspan="4" class="text-center text-red-500 py-6">Error loading pending photos.</td></tr>';
                });
        }

        function handlePhotoApprovalAction(targetUserId, action) {
            if (!confirm(`Are you sure you want to ${action} this profile picture?`)) return;
            const fd = new FormData();
            fd.append('target_user_id', targetUserId);
            fd.append('action', action);
            
            fetch('php/approve_profile_picture.php', {
                method: 'POST',
                body: fd
            })
            .then(res => res.json())
            .then(data => {
                alert(data.message || 'Operation completed.');
                if (data.success) {
                    loadPhotoApprovals();
                }
            })
            .catch(err => {
                console.error(err);
                alert('Network error.');
            });
        }
        
        function previewImage(src) {
            window.open(src, '_blank');
        }

        // ---------- Announcement Manager JS Functions ----------
        function getAnnouncementApiUrl(endpoint) {
            const basePath = window.location.pathname.replace(/\/[^\/\?#]+$/, '').replace(/\/$/, '');
            return `${window.location.origin}${basePath}/php/${endpoint}`;
        }

        function loadAnnouncementsAdmin() {
            const tbody = document.getElementById('adminAnnouncementsTableBody');
            if (!tbody) return;
            tbody.innerHTML = '<tr><td colspan="6" class="text-center py-6 text-gray-500">Loading announcements...</td></tr>';
            
            fetch(getAnnouncementApiUrl('get_announcements.php'), { cache: 'no-store', headers: { 'Cache-Control': 'no-cache' } })
                .then(response => response.json())
                .then(data => {
                    if (!data || !data.success) {
                        console.warn('Failed to load admin announcements', data);
                        tbody.innerHTML = '<tr><td colspan="6" class="text-center py-6 text-red-500">Failed to load announcements.</td></tr>';
                        return;
                    }
                    if (data.announcements && data.announcements.length > 0) {
                        let html = '';
                        data.announcements.forEach(ann => {
                            const dateStr = ann.event_date ? ann.event_date : 'N/A';
                            const created = new Date(ann.created_at).toLocaleDateString();
                            
                            // Safe serialization for click handlers
                            const serialized = JSON.stringify(ann).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
                            
                            html += `
                                <tr class="border-b border-gray-100 hover:bg-gray-50 transition">
                                    <td class="px-6 py-4 font-semibold text-gray-800 max-w-[200px] truncate" title="${ann.title}">${ann.title}</td>
                                    <td class="px-6 py-4 text-gray-600 max-w-[300px] truncate" title="${ann.content}">${ann.content}</td>
                                    <td class="px-6 py-4 text-gray-600">${dateStr}</td>
                                    <td class="px-6 py-4 text-gray-600">${ann.location || 'N/A'}</td>
                                    <td class="px-6 py-4 text-gray-600">${ann.responsible_dept || 'N/A'}</td>
                                    <td class="px-6 py-4 space-x-2 whitespace-nowrap">
                                        <button onclick="editAnnouncement(JSON.parse('${serialized}'))" class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded text-sm font-medium transition">Edit</button>
                                        <button onclick="deleteAnnouncement(${ann.id})" class="bg-red-600 hover:bg-red-700 text-white px-3 py-1.5 rounded text-sm font-medium transition">Delete</button>
                                    </td>
                                </tr>
                            `;
                        });
                        tbody.innerHTML = html;
                    } else {
                        tbody.innerHTML = '<tr><td colspan="6" class="text-center py-6 text-gray-500">No announcements found.</td></tr>';
                    }
                })
                .catch(err => {
                    console.error(err);
                    tbody.innerHTML = '<tr><td colspan="6" class="text-center text-red-500 py-6">Error loading announcements.</td></tr>';
                });
        }

        function openAddAnnouncementModal() {
            document.getElementById('announcementModalTitle').textContent = 'Create Announcement';
            document.getElementById('announcementFormId').value = '';
            document.getElementById('announcementFormTitle').value = '';
            document.getElementById('announcementFormContent').value = '';
            document.getElementById('announcementFormEventDate').value = '';
            document.getElementById('announcementFormLocation').value = '';
            document.getElementById('announcementFormDept').value = '';
            document.getElementById('announcementFormError').textContent = '';
            document.getElementById('announcementModal').style.display = 'flex';
        }

        function closeAnnouncementModal() {
            document.getElementById('announcementModal').style.display = 'none';
        }

        function editAnnouncement(ann) {
            document.getElementById('announcementModalTitle').textContent = 'Edit Announcement';
            document.getElementById('announcementFormId').value = ann.id;
            document.getElementById('announcementFormTitle').value = ann.title;
            document.getElementById('announcementFormContent').value = ann.content;
            document.getElementById('announcementFormEventDate').value = ann.event_date || '';
            document.getElementById('announcementFormLocation').value = ann.location || '';
            document.getElementById('announcementFormDept').value = ann.responsible_dept || '';
            document.getElementById('announcementFormError').textContent = '';
            document.getElementById('announcementModal').style.display = 'flex';
        }

        function submitAnnouncementForm() {
            const id = document.getElementById('announcementFormId').value;
            const title = document.getElementById('announcementFormTitle').value.trim();
            const content = document.getElementById('announcementFormContent').value.trim();
            const event_date = document.getElementById('announcementFormEventDate').value;
            const location = document.getElementById('announcementFormLocation').value.trim();
            const responsible_dept = document.getElementById('announcementFormDept').value.trim();
            const errorEl = document.getElementById('announcementFormError');

            if (!title || !content) {
                errorEl.textContent = 'Title and Content are required.';
                return;
            }

            const action = id ? 'update' : 'create';
            const data = { action, id, title, content, event_date, location, responsible_dept };

            fetch(getAnnouncementApiUrl('manage_announcements.php'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Cache-Control': 'no-cache' },
                body: JSON.stringify(data)
            })
            .then(res => res.json())
            .then(resData => {
                if (resData.success) {
                    alert(resData.message);
                    closeAnnouncementModal();
                    if (typeof loadAnnouncementsAdmin === 'function') {
                        loadAnnouncementsAdmin();
                    }
                    if (typeof loadAnnouncements === 'function') {
                        loadAnnouncements();
                    }
                } else {
                    errorEl.textContent = resData.message || 'Failed to save announcement.';
                }
            })
            .catch(err => {
                console.error(err);
                errorEl.textContent = 'Network error.';
            });
        }

        function deleteAnnouncement(id) {
            if (!confirm('Are you sure you want to delete this announcement?')) return;
            fetch(getAnnouncementApiUrl('manage_announcements.php'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Cache-Control': 'no-cache' },
                body: JSON.stringify({ action: 'delete', id })
            })
            .then(res => res.json())
            .then(resData => {
                alert(resData.message);
                if (resData.success) {
                    loadAnnouncementsAdmin();
                }
            })
            .catch(err => {
                console.error(err);
                alert('Network error.');
            });
        }

        // ---------- Delete User Functions ----------
        // ---------- Navigation ----------
        window.navigateTo = function(page) {
            const menuItems = document.querySelectorAll('.sidebar ul li a');
            menuItems.forEach(item => item.classList.remove('active'));
            
            const clickedItem = document.getElementById(`menu-${page}`);
            if (clickedItem) clickedItem.classList.add('active');
            
            const allCards = document.querySelectorAll('.dashboard-card');
            allCards.forEach(card => {
                card.classList.remove('active');
                card.classList.add('hidden');
            });
            
            function activate(id){
                const el = document.getElementById(id);
                if(el){
                    el.classList.remove('hidden');
                    el.classList.add('active');
                }
            }

            switch(page) {
                case 'home':
                    if (userRole === 'student') {
                        activate('homeCard');
                        if (typeof renderStudentChart === 'function') renderStudentChart();
                    } 
                    else if (['admin','registrar'].includes(userRole)) {
                        activate('adminEnrollmentCard');
                        if (typeof loadEnrollments === 'function') loadEnrollments();
                    }
                    else if (userRole === 'cashier') {
                        activate('cashierDashboardCard');
                    }
                    else if (userRole === 'teacher') {
                        activate('teacherHomeCard');
                    }
                    break;
                case 'users':
                    if (['admin','registrar'].includes(userRole)) {
                        activate('userManagementCard');
                        activate('usersCard');
                        if (typeof loadAllUsersDirectory === 'function') {
                            loadAllUsersDirectory();
                        }
                    }
                    break;
                case 'user-directory':
                    if (['admin','registrar'].includes(userRole)) {
                        activate('userDirectoryCard');
                        if (typeof loadAllUsersDirectory === 'function') {
                            loadAllUsersDirectory();
                        }
                    }
                    break;
                case 'payables':
                    if (userRole === 'student') {
                        activate('payablesCard');
                        if (typeof loadPayables === 'function') loadPayables();
                    } 
                    else if (['admin','cashier'].includes(userRole)) {
                        activate('paymentsCard');
                        if (typeof togglePaymentTarget === 'function') togglePaymentTarget();
                    }
                    break;
                case 'tuition-fees':
                    if (['admin', 'cashier', 'registrar'].includes(userRole)) {
                        activate('tuitionFeeManagerCard');
                        if (typeof loadTuitionFeeManager === 'function') loadTuitionFeeManager();
                    }
                    break;
                case 'grants-discounts':
                    if (['admin', 'cashier', 'registrar'].includes(userRole)) {
                        activate('grantsDiscountsCard');
                        if (typeof loadDiscountsManager === 'function') loadDiscountsManager();
                    }
                    break;
                case 'sections-subjects':
                    if (['admin', 'registrar'].includes(userRole)) {
                        activate('sectionsSubjectsCard');
                        if (typeof loadAdminSubjects === 'function') loadAdminSubjects();
                    }
                    break;
                case 'payments':
                    if (['admin','cashier'].includes(userRole)) {
                        activate('paymentsCard');
                        if (typeof togglePaymentTarget === 'function') togglePaymentTarget();
                    }
                    break;
                case 'documents':
                    if (['admin','registrar'].includes(userRole)) {
                        activate('documentManagementCard');
                        if (typeof loadDocumentManagementStudents === 'function') loadDocumentManagementStudents();
                    }
                    break;
                case 'grade-submissions':
                    if (['admin','registrar'].includes(userRole)) {
                        activate('gradeSubmissionsCard');
                        if (typeof loadGradeSubmissions === 'function') loadGradeSubmissions();
                    }
                    break;
                case 'book-manager':
                    if (['admin','registrar'].includes(userRole)) {
                        activate('bookManagerCard');
                        if (typeof loadBooks === 'function') loadBooks();
                    }
                    break;
                case 'grades':
                    if (userRole === 'student') activate('gradesCard');
                    break;
                case 'subjects':
                    if (userRole === 'student') activate('subjectsCard');
                    break;
                case 'events':
                    if (userRole === 'student') activate('eventsCard');
                    break;
                case 'profile':
                    if (userRole === 'student') {
                        activate('profileCard');
                        if (typeof loadStudentDocumentRequirements === 'function') loadStudentDocumentRequirements();
                    } 
                    else if (['admin','cashier','registrar'].includes(userRole)) {
                        activate('adminProfileCard');
                    }
                    break;
                case 'announcements':
                    if (userRole === 'student') {
                        activate('announcementsCard');
                        if (typeof loadAnnouncements === 'function') loadAnnouncements();
                    }
                    break;
                case 'grade-encoding':
                    if (userRole === 'teacher') {
                        activate('teacherGradeEncodingCard');
                    }
                    break;
                case 'attendance':
                    if (userRole === 'teacher') {
                        activate('teacherAttendanceCard');
                        if (typeof loadSavedAttendanceDates === 'function') loadSavedAttendanceDates();
                    }
                    break;
                case 'class-list':
                    if (userRole === 'teacher') {
                        activate('teacherClassListCard');
                        if (typeof loadTeacherClassList === 'function') loadTeacherClassList();
                    }
                    break;
                case 'photo-approvals':
                    if (['admin','registrar'].includes(userRole)) {
                        activate('photoApprovalsCard');
                        if (typeof loadPhotoApprovals === 'function') loadPhotoApprovals();
                    }
                    break;
                case 'announcements-manager':
                    if (['admin','registrar'].includes(userRole)) {
                        activate('adminAnnouncementsCard');
                        if (typeof loadAnnouncementsAdmin === 'function') loadAnnouncementsAdmin();
                    }
                    break;
            }

            // Close sidebar on mobile after clicking a link
            if (window.innerWidth <= 768) {
                const sidebar = document.getElementById('sidebar');
                const overlay = document.getElementById('sidebarOverlay');
                if(sidebar) sidebar.classList.remove('open');
                if(overlay) {
                    overlay.classList.remove('opacity-100');
                    overlay.classList.add('pointer-events-none', 'opacity-0');
                }
            }
        }

        // ---- Notification Badge Polling ----
        <?php if (in_array($userRole, ['admin','registrar'])): ?>
        async function pollNotificationBadges() {
            try {
                // Document badge
                const docResp = await fetch('php/document_management.php?action=badge_counts');
                const docData = await docResp.json();
                const docBadge = document.getElementById('badge-documents');
                if (docBadge) {
                    if (docData.success && docData.pending_docs > 0) {
                        docBadge.classList.remove('hidden');
                    } else {
                        docBadge.classList.add('hidden');
                    }
                }
                // Grade Submissions badge
                const gsResp = await fetch('php/get_grade_submissions.php?badge_only=1');
                const gsData = await gsResp.json();
                const gsBadge = document.getElementById('badge-grade-submissions');
                if (gsBadge) {
                    if (gsData.success && gsData.pending_count > 0) {
                        gsBadge.classList.remove('hidden');
                    } else {
                        gsBadge.classList.add('hidden');
                    }
                }
            } catch(e) { console.error('Badge polling error', e); }
        }
        // Poll immediately then every 60s
        document.addEventListener('DOMContentLoaded', function() {
            pollNotificationBadges();
            setInterval(pollNotificationBadges, 60000);
        });
        <?php endif; ?>

        // Toggle Sidebar for Mobile Navigation
        window.toggleSidebar = function() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            sidebar.classList.toggle('open');
            
            if (sidebar.classList.contains('open')) {
                overlay.classList.remove('pointer-events-none', 'opacity-0');
                overlay.classList.add('opacity-100');
            } else {
                overlay.classList.remove('opacity-100');
                overlay.classList.add('pointer-events-none', 'opacity-0');
            }
        }

        // ---------- Student Functions ----------
        function renderStudentChart() {
            const homeCard = document.getElementById('homeCard');
            if (!homeCard || !homeCard.classList.contains('active')) return;

            const canvas = document.getElementById('studentGradeChart');
            if (!canvas) return;

            if (window.studentChart) window.studentChart.destroy();
            const gradeData = <?php 
                $chartData = [];
                if (!empty($grades)) {
                    $groupedGrades = [];
                    foreach ($grades as $grade) {
                        $subjectName = $grade['subject_name'];
                        $quarter = $grade['quarter'];
                        if (!isset($groupedGrades[$subjectName])) {
                            $groupedGrades[$subjectName] = [
                                'quarters' => [],
                                'average' => 0,
                                'count' => 0,
                                'total' => 0
                            ];
                        }
                        $groupedGrades[$subjectName]['quarters'][$quarter] = $grade['grade'];
                        $groupedGrades[$subjectName]['count']++;
                        $groupedGrades[$subjectName]['total'] += $grade['grade'];
                    }
                    foreach ($groupedGrades as $subject => $data) {
                        if ($data['count'] > 0) {
                            $avg = round($data['total'] / $data['count']);
                            if ($avg > 0) {
                                $chartData[] = [
                                    'subject' => $subject,
                                    'grade' => $avg
                                ];
                            }
                        }
                    }
                }
                echo json_encode($chartData);
            ?>;

            const container = document.querySelector('#homeCard .chart-container');
            if (gradeData.length === 0) {
                if (container) container.style.display = 'none';
                return;
            } else {
                if (container) container.style.display = 'block';
            }

            const ctx = canvas.getContext('2d');
            window.studentChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: gradeData.map(item => item.subject),
                    datasets: [{
                        label: 'Average Grade',
                        data: gradeData.map(item => item.grade),
                        backgroundColor: '#4e73df',
                        borderRadius: 5
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: true, position: 'top' } },
                    scales: {
                        y: { beginAtZero: true, max: 100 }
                    }
                }
            });
        }

        function toggleHomeSubjects() {
            const todayList = document.getElementById('todaySubjectList');
            const allList = document.getElementById('allSubjectList');
            const viewAllBtn = document.querySelector('#homeCard .view-all-btn');
            
            if (todayList && allList && viewAllBtn) {
                if (todayList.style.display === 'none' || todayList.style.display === '') {
                    todayList.style.display = 'block';
                    allList.style.display = 'none';
                    viewAllBtn.textContent = 'View All Subjects';
                    viewAllBtn.style.background = '#0a2d63';
                } else {
                    todayList.style.display = 'none';
                    allList.style.display = 'block';
                    viewAllBtn.textContent = 'View Today\'s Subjects';
                    viewAllBtn.style.background = '#10b981';
                }
            }
        }

        function toggleSubjectCard() {
            const todayList = document.getElementById('todaySubjectsCardList');
            const allList = document.getElementById('allSubjectsCardList');
            const viewAllBtn = document.getElementById('subjectsCardBtn');
            
            if (todayList && allList && viewAllBtn) {
                if (allList.style.display === 'none' || allList.style.display === '') {
                    todayList.style.display = 'none';
                    allList.style.display = 'block';
                    viewAllBtn.textContent = 'View Today\'s Subjects';
                    viewAllBtn.style.background = '#0a2d63';
                } else {
                    todayList.style.display = 'block';
                    allList.style.display = 'none';
                    viewAllBtn.textContent = 'View All Subjects';
                    viewAllBtn.style.background = '#10b981';
                }
            }
        }

        function loadPayables() {
            const payableList = document.getElementById('payableList');
            if (!payableList) return;
            payableList.innerHTML = '<div class="loading text-center text-gray-500 py-10">Loading payables...</div>';
            const timestamp = new Date().getTime();
            fetch('php/get_payables.php?_=' + timestamp)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.payables) {
                        payableList.innerHTML = renderStudentPayablesTable(data.payables, data.totals || null);
                    } else {
                        payableList.innerHTML = '<div class="text-center text-gray-400 py-10">No payables found.</div>';
                    }
                })
                .catch(error => {
                    console.error('Error loading payables:', error);
                    payableList.innerHTML = '<div class="text-center text-gray-400 py-10">Error loading payables</div>';
                });
        }

        function loadAnnouncements() {
            fetch(getAnnouncementApiUrl('get_announcements.php'), { cache: 'no-store', headers: { 'Cache-Control': 'no-cache' } })
                .then(response => response.json())
                .then(data => {
                    const announcementList = document.getElementById('announcementList');
                    if (!announcementList) return;
                    if (!data || !data.success) {
                        console.warn('Failed to load student announcements', data);
                        announcementList.innerHTML = '<div class="text-center text-gray-400 py-10">Failed to load announcements.</div>';
                        return;
                    }
                    if (data.announcements && data.announcements.length > 0) {
                        let html = '';
                        data.announcements.forEach(announcement => {
                            const created = new Date(announcement.created_at);
                            let eventDetails = '';
                            if (announcement.event_date) {
                                eventDetails = `
                                    <div class="mt-3 p-3 bg-blue-50 rounded text-sm text-[#0a2d63] space-y-1 border border-blue-100">
                                        <div><strong>Event Date:</strong> ${announcement.event_date}</div>
                                        ${announcement.location ? `<div><strong>Location:</strong> ${announcement.location}</div>` : ''}
                                        ${announcement.responsible_dept ? `<div><strong>Department:</strong> ${announcement.responsible_dept}</div>` : ''}
                                    </div>
                                `;
                            }
                            html += `
                                <div class="announcement-item bg-gray-50 p-6 hover:bg-gray-100 transition border border-gray-100 rounded-lg">
                                    <div class="announcement-header flex flex-col md:flex-row justify-between items-start md:items-center mb-4">
                                        <h4 class="text-lg font-semibold text-[#0a2d63] flex-1 break-words">${announcement.title}</h4>
                                        <span class="announcement-date bg-gray-200 text-gray-600 px-3 py-1 rounded text-sm whitespace-nowrap mt-2 md:mt-0">${created.toLocaleDateString()}</span>
                                    </div>
                                    <p class="text-gray-700 text-base leading-relaxed break-words">${announcement.content}</p>
                                    ${eventDetails}
                                </div>
                            `;
                        });
                        announcementList.innerHTML = html;
                    } else {
                        announcementList.innerHTML = '<div class="text-center text-gray-400 py-10">No announcements available.</div>';
                    }
                })
                .catch(error => {
                    console.error('Error loading announcements:', error);
                    document.getElementById('announcementList').innerHTML = '<div class="text-center text-gray-400 py-10">Error loading announcements</div>';
                });
        }

        function loadPaymentStudents() {
            const datalist = document.getElementById('paymentStudentDatalist');
            if (!datalist) return;
            datalist.innerHTML = '';
            
            fetch('php/get_users.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({})
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.users) {
                        window.paymentStudentsCache = data.users
                            .filter(u => u.role === 'student')
                            .map(u => ({
                                id: String(u.id),
                                name: String(u.full_name || '').trim(),
                                label: `${String(u.full_name || '').trim()} (Grade ${u.grade_level || ''} - ${u.section || ''})`
                            }))
                            .filter(u => u.name !== '');

                        let optionsHtml = '';
                        window.paymentStudentsCache.forEach(s => {
                            optionsHtml += `<option value="${s.name}"></option>`;
                        });
                        datalist.innerHTML = optionsHtml;
                    } else {
                        window.paymentStudentsCache = [];
                    }
                })
                .catch(error => {
                    console.error('Error loading students:', error);
                    window.paymentStudentsCache = [];
                });
        }

        function setPaymentStudentSelection(student) {
            const searchInput = document.getElementById('paymentStudentSearch');
            const idInput = document.getElementById('paymentStudentId');
            if (searchInput) searchInput.value = student?.name || '';
            if (idInput) idInput.value = student?.id || '';
        }

        function handlePaymentStudentTyping() {
            const input = document.getElementById('paymentStudentSearch');
            const idInput = document.getElementById('paymentStudentId');
            if (!input || !idInput) return;
            const typed = input.value.trim().toLowerCase();
            if (!typed) {
                idInput.value = '';
                return;
            }
            const exact = (window.paymentStudentsCache || []).find(s => s.name.toLowerCase() === typed);
            if (exact) {
                idInput.value = exact.id;
            } else {
                idInput.value = '';
            }
        }

        function autoCorrectPaymentStudent() {
            const input = document.getElementById('paymentStudentSearch');
            if (!input) return;
            const typed = input.value.trim();
            if (!typed) {
                setPaymentStudentSelection(null);
                return;
            }
            const needle = typed.toLowerCase();
            const list = window.paymentStudentsCache || [];
            const exact = list.find(s => s.name.toLowerCase() === needle);
            if (exact) {
                setPaymentStudentSelection(exact);
                return;
            }
            const starts = list.find(s => s.name.toLowerCase().startsWith(needle));
            if (starts) {
                setPaymentStudentSelection(starts);
                return;
            }
            const contains = list.find(s => s.name.toLowerCase().includes(needle));
            if (contains) {
                setPaymentStudentSelection(contains);
                return;
            }
            // No match: clear id but keep text
            const idInput = document.getElementById('paymentStudentId');
            if (idInput) idInput.value = '';
        }

        function loadPaymentEnrollees() {
            const input = document.getElementById('paymentEnrolleeSearch');
            if (input) {
                input.placeholder = 'Type enrollee name...';
            }

            fetch('php/get_pending_enrollees.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.enrollments) {
                        window.paymentEnrolleesCache = data.enrollments.map(e => ({
                            id: String(e.id),
                            name: String(e.full_name || '').trim(),
                            grade: String(e.grade_level || ''),
                            downpayment_total: parseFloat(e.downpayment_total || 0)
                        })).filter(e => e.name !== '');
                    } else {
                        window.paymentEnrolleesCache = [];
                    }
                })
                .catch(error => {
                    console.error('Error loading enrollees:', error);
                    window.paymentEnrolleesCache = [];
                });
        }

        function togglePaymentTarget() {
            const target = document.getElementById('paymentTargetType')?.value || 'student';
            const studentGroup = document.getElementById('paymentStudentGroup');
            const enrolleeGroup = document.getElementById('paymentEnrollmentGroup');
            const paymentTypeEl = document.getElementById('paymentType');
            const studentSearch = document.getElementById('paymentStudentSearch');
            const studentId = document.getElementById('paymentStudentId');
            const enrolleeSearch = document.getElementById('paymentEnrolleeSearch');
            const enrolleeId = document.getElementById('paymentEnrollmentId');
            const loadBtn = document.getElementById('loadPayablesBtn');

            if (target === 'enrollee') {
                if (studentGroup) studentGroup.style.display = 'none';
                if (enrolleeGroup) enrolleeGroup.style.display = 'block';
                if (studentSearch) studentSearch.required = false;
                if (enrolleeSearch) enrolleeSearch.required = true;
                if (loadBtn) loadBtn.style.display = 'none';
                if (paymentTypeEl) {
                    paymentTypeEl.value = 'downpayment';
                    paymentTypeEl.disabled = true;
                }
                if (studentSearch) studentSearch.value = '';
                if (studentId) studentId.value = '';
                loadPaymentEnrollees();
            } else {
                if (studentGroup) studentGroup.style.display = 'block';
                if (enrolleeGroup) enrolleeGroup.style.display = 'none';
                if (studentSearch) studentSearch.required = true;
                if (enrolleeSearch) enrolleeSearch.required = false;
                if (loadBtn) loadBtn.style.display = 'inline-block';
                if (paymentTypeEl) {
                    paymentTypeEl.disabled = false;
                    if (!paymentTypeEl.value) paymentTypeEl.value = 'payment';
                }
                if (enrolleeSearch) enrolleeSearch.value = '';
                if (enrolleeId) enrolleeId.value = '';
                loadPaymentStudents();
            }
        }

        function togglePaymentMode() {
            const mode = document.getElementById('paymentMode')?.value || 'downpayment';
            const monthlyGroup = document.getElementById('monthlyPlansGroup');
            if (mode === 'cash') {
                if (monthlyGroup) monthlyGroup.style.display = 'none';
            } else {
                if (monthlyGroup) monthlyGroup.style.display = 'block';
            }
        }

        function setPaymentEnrolleeSelection(enrollee) {
            const searchInput = document.getElementById('paymentEnrolleeSearch');
            const idInput = document.getElementById('paymentEnrollmentId');
            if (searchInput) searchInput.value = enrollee?.name || '';
            if (idInput) idInput.value = enrollee?.id || '';
        }

        function handlePaymentEnrolleeTyping() {
            const input = document.getElementById('paymentEnrolleeSearch');
            const idInput = document.getElementById('paymentEnrollmentId');
            if (!input || !idInput) return;
            const typed = input.value.trim().toLowerCase();
            if (!typed) {
                idInput.value = '';
                return;
            }
            const exact = (window.paymentEnrolleesCache || []).find(e => e.name.toLowerCase() === typed);
            if (exact) {
                idInput.value = exact.id;
            } else {
                idInput.value = '';
            }
        }

        function autoCorrectPaymentEnrollee() {
            const input = document.getElementById('paymentEnrolleeSearch');
            if (!input) return;
            const typed = input.value.trim();
            if (!typed) {
                setPaymentEnrolleeSelection(null);
                return;
            }
            const needle = typed.toLowerCase();
            const list = window.paymentEnrolleesCache || [];
            const exact = list.find(e => e.name.toLowerCase() === needle);
            if (exact) {
                setPaymentEnrolleeSelection(exact);
                return;
            }
            const starts = list.find(e => e.name.toLowerCase().startsWith(needle));
            if (starts) {
                setPaymentEnrolleeSelection(starts);
                return;
            }
            const contains = list.find(e => e.name.toLowerCase().includes(needle));
            if (contains) {
                setPaymentEnrolleeSelection(contains);
                return;
            }
            const idInput = document.getElementById('paymentEnrollmentId');
            if (idInput) idInput.value = '';
        }

        // ---------- DOM Ready ----------
        document.addEventListener('DOMContentLoaded', function() {
            <?php if (in_array($userRole, ['admin', 'registrar'])): ?>
            const modalBirth = document.getElementById('modalBirthdate');
            if (modalBirth) {
                const today = new Date();
                const maxYear = today.getFullYear() - 8;
                const minYear = today.getFullYear() - 50;
                const month = String(today.getMonth() + 1).padStart(2, '0');
                const day = String(today.getDate()).padStart(2, '0');
                modalBirth.setAttribute('min', `${minYear}-${month}-${day}`);
                modalBirth.setAttribute('max', `${maxYear}-${month}-${day}`);

                modalBirth.addEventListener('change', syncModalAgeFromBirthdate);
                modalBirth.addEventListener('input', syncModalAgeFromBirthdate);
            }
            updateChart();
            loadEnrollments();
            setInterval(loadEnrollments, 30000);
            <?php endif; ?>
            
            <?php if ($userRole === 'cashier'): ?>
            togglePaymentTarget();
            <?php endif; ?>
            <?php if ($userRole === 'admin'): ?>
            togglePaymentTarget();
            <?php endif; ?>
            
            <?php if ($userRole == 'student'): ?>
            if (document.getElementById('homeCard').classList.contains('active')) {
                renderStudentChart();
            }
            <?php endif; ?>
            <?php if ($userRole == 'teacher'): ?>
            window.teacherSections = <?php echo json_encode($teacherSections); ?>;
            renderTeacherHomeStudentFilters();
            initializeAttendanceFilters();
            <?php endif; ?>
            
            window.onclick = function(event) {
                const addModal = document.getElementById('addUserModal');
                const documentModal = document.getElementById('documentModal');
                const enrollmentSearchModal = document.getElementById('enrollmentSearchModal');
                const studentSelectModal = document.getElementById('studentSelectModal');
                const paymentEnrolleeSelectModal = document.getElementById('paymentEnrolleeSelectModal');
                const paymentStudentSelectModal = document.getElementById('paymentStudentSelectModal');
                const batchPromoteModal = document.getElementById('batchPromoteModal');
                const rejectEnrollmentModal = document.getElementById('rejectEnrollmentModal');
                const attendanceRangeModal = document.getElementById('attendanceRangeModal');
                const changePasswordModal = document.getElementById('changePasswordModal');
                if (event.target === addModal) closeAddUserModal();
                if (event.target === documentModal) closeDocumentModal();
                if (event.target === enrollmentSearchModal) closeEnrollmentSearchModal();
                if (event.target === studentSelectModal) closeStudentSelectModal();
                if (event.target === paymentEnrolleeSelectModal) closePaymentEnrolleeBrowseModal();
                if (event.target === paymentStudentSelectModal) closePaymentStudentBrowseModal();
                if (event.target === batchPromoteModal) closeBatchPromoteModal();
                if (event.target === rejectEnrollmentModal) closeRejectEnrollmentModal();
                if (event.target === attendanceRangeModal) closeAttendanceRangeModal();
                if (event.target === changePasswordModal) closeChangePasswordModal();
            }
        });

        // ========== TEACHER GRADE ENCODING ==========
        let currentSubjectId = null, currentSection = null, currentGradeLevel = null;
        async function loadGradeStudents() {
            const subjectSelect = document.getElementById('gradeSubjectSelect');
            const sectionSelect = document.getElementById('gradeSectionSelect');
            currentSubjectId = subjectSelect.value;
            const section = sectionSelect.value;
            if (!currentSubjectId || !section) { alert('Please select both subject and section'); return; }
            
            const selectedOption = sectionSelect.options[sectionSelect.selectedIndex];
            currentGradeLevel = selectedOption.getAttribute('data-grade');
            currentSection = section;
            
            const formData = new FormData();
            formData.append('action', 'get_grade_students');
            formData.append('subject_id', currentSubjectId);
            formData.append('grade_level', currentGradeLevel);
            formData.append('section', currentSection);
            try {
                const response = await fetch('php/teacher_actions.php', { method: 'POST', body: formData });
                const data = await parseJsonResponse(response);
                if (data.success) {
                    renderGradeTable(data.students, data.subject_name);
                    calculateGradeStats();
                } else { alert(data.message || 'Failed to load students'); }
            } catch (error) { alert('Error loading grade data'); }
        }

        window.filterGradeSubjectsBySection = function() {
            const sectionSelect = document.getElementById('gradeSectionSelect');
            const subjectSelect = document.getElementById('gradeSubjectSelect');
            const selectedSection = sectionSelect.value;
            if (!subjectSelect) return;
            
            let currentlySelectedValue = subjectSelect.value;
            
            for (let option of subjectSelect.options) {
                if (!option.value) continue;
                if (!selectedSection) {
                    option.hidden = false;
                    continue;
                }
                const optionSection = option.getAttribute('data-section') || '';
                option.hidden = optionSection !== selectedSection;
            }
        }

        function renderGradeTable(students, subjectName) {
            let html = `<table class="min-w-full border-collapse min-w-[800px]"><thead><tr class="bg-gray-100"><th class="p-3 text-left" rowspan="2">Student Name</th><th class="p-3 text-center" colspan="2">1st Semester</th><th class="p-3 text-center" colspan="2">2nd Semester</th><th class="p-3 text-center" rowspan="2">Average</th></tr><tr class="bg-gray-100"><th class="p-3 text-center">1st Quarter</th><th class="p-3 text-center">2nd Quarter</th><th class="p-3 text-center">3rd Quarter</th><th class="p-3 text-center">4th Quarter</th></tr></thead><tbody>`;
            students.forEach(s => {
                html += `<tr data-student-id="${s.id}">
                    <td class="p-3 font-semibold break-words">${escapeHtml(s.full_name)}</td>
                    <td class="p-2 text-center"><input type="number" class="grade-input q1" value="${s.q1 || ''}" step="any" onchange="calculateGradeStats()"></td>
                    <td class="p-2 text-center"><input type="number" class="grade-input q2" value="${s.q2 || ''}" step="any" onchange="calculateGradeStats()"></td>
                    <td class="p-2 text-center"><input type="number" class="grade-input q3" value="${s.q3 || ''}" step="any" onchange="calculateGradeStats()"></td>
                    <td class="p-2 text-center"><input type="number" class="grade-input q4" value="${s.q4 || ''}" step="any" onchange="calculateGradeStats()"></td>
                    <td class="p-3 text-center avg-cell">-</td>
                </tr>`;
            });
            html += `</tbody></table><input type="hidden" id="currentSubjectName" value="${escapeHtml(subjectName)}">`;
            document.getElementById('gradeEncodingTableContainer').innerHTML = html;
            calculateGradeStats();
        }

        function calculateGradeStats() {
            const rows = document.querySelectorAll('#gradeEncodingTableContainer tbody tr');
            let totalAvg = 0, passCount = 0, highest = 0, validCount = 0;
            rows.forEach(row => {
                const q1Raw = row.querySelector('.q1')?.value ?? '';
                const q2Raw = row.querySelector('.q2')?.value ?? '';
                const q3Raw = row.querySelector('.q3')?.value ?? '';
                const q4Raw = row.querySelector('.q4')?.value ?? '';
                const q1 = q1Raw.trim() !== '' ? parseFloat(q1Raw) : null;
                const q2 = q2Raw.trim() !== '' ? parseFloat(q2Raw) : null;
                const q3 = q3Raw.trim() !== '' ? parseFloat(q3Raw) : null;
                const q4 = q4Raw.trim() !== '' ? parseFloat(q4Raw) : null;
                const avgCell = row.querySelector('.avg-cell');

                let total = 0, count = 0;
                if (q1 !== null && !isNaN(q1)) { total += q1; count++; }
                if (q2 !== null && !isNaN(q2)) { total += q2; count++; }
                if (q3 !== null && !isNaN(q3)) { total += q3; count++; }
                if (q4 !== null && !isNaN(q4)) { total += q4; count++; }

                if (count === 0) {
                    if (avgCell) avgCell.innerText = '-';
                    return;
                }

                const avg = total / count;
                if (avgCell) avgCell.innerText = avg.toFixed(1);
                totalAvg += avg;
                validCount++;
                if (avg > highest) highest = avg;
                if (avg >= 75) passCount++;
            });
            document.getElementById('classAvg').innerText = validCount > 0 ? (totalAvg / validCount).toFixed(1) + '%' : '-';
            document.getElementById('passRate').innerText = validCount > 0 ? Math.round((passCount / validCount) * 100) + '%' : '-';
            document.getElementById('highGrade').innerText = highest > 0 ? highest.toFixed(1) : '-';
        }

        async function saveAllGrades() {
            if (!currentSubjectId || !currentSection) { alert('No subject/section selected'); return; }
            const rows = document.querySelectorAll('#gradeEncodingTableContainer tbody tr');
            const gradesData = [];
            for (let row of rows) {
                const studentId = row.getAttribute('data-student-id');
                const q1 = row.querySelector('.q1')?.value ?? '';
                const q2 = row.querySelector('.q2')?.value ?? '';
                const q3 = row.querySelector('.q3')?.value ?? '';
                const q4 = row.querySelector('.q4')?.value ?? '';
                if (q1.trim() === '' && q2.trim() === '' && q3.trim() === '' && q4.trim() === '') {
                    continue;
                }
                gradesData.push({ student_id: studentId, q1: q1.trim(), q2: q2.trim(), q3: q3.trim(), q4: q4.trim() });
            }
            if (gradesData.length === 0) { alert('No grades to save'); return; }
            const formData = new FormData();
            formData.append('action', 'save_grades');
            formData.append('data', JSON.stringify({ subject_id: currentSubjectId, section: currentSection, grade_level: currentGradeLevel, grades: gradesData }));
            try {
                const response = await fetch('php/teacher_actions.php', { method: 'POST', body: formData });
                const result = await parseJsonResponse(response);
                if (result.success) alert('Grades saved successfully!');
                else alert('Error: ' + result.message);
            } catch (error) { alert('Network error'); }
        }

        // ========== TEACHER ATTENDANCE ==========
        let attendanceDates = [];
        async function loadSavedAttendanceDates() {
            try {
                const response = await fetch('php/teacher_actions.php?action=get_attendance_dates');
                const dates = await parseJsonResponse(response);
                attendanceDates = dates;
                renderAttendanceHeaders();
                await loadAttendanceData();
                updateLiveAnalysis();
            } catch (error) { console.error(error); }
        }

        function createAttendanceChoiceCell(date, studentId = '', selectedStatus = '') {
            const td = document.createElement('td');
            td.className = 'p-2 text-center';
            td.dataset.date = date;
            td.dataset.studentId = studentId;
            td.dataset.status = selectedStatus || '';

            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'attendance-toggle';
            btn.title = 'Click to cycle: Present → Late → Absent';
            btn.onclick = () => cycleAttendanceStatus(td);
            td.appendChild(btn);
            refreshAttendanceCellUI(td);
            return td;
        }

        const attendanceCycle = ['', 'present', 'late', 'absent'];
        const attendanceIcons = { '': '○', present: '✓', late: '-', absent: '✕' };

        function cycleAttendanceStatus(cell) {
            if (!cell) return;
            const current = cell.dataset.status || '';
            const idx = attendanceCycle.indexOf(current);
            const next = attendanceCycle[(idx + 1) % attendanceCycle.length];
            cell.dataset.status = next;
            refreshAttendanceCellUI(cell);
            updateLiveAnalysis();
        }

        function setAttendanceStatus(cell, status) {
            if (!cell) return;
            cell.dataset.status = status;
            refreshAttendanceCellUI(cell);
            updateLiveAnalysis();
        }

        function refreshAttendanceCellUI(cell) {
            const status = cell.dataset.status || '';
            const btn = cell.querySelector('.attendance-toggle');
            if (!btn) return;
            btn.className = 'attendance-toggle' + (status ? ' ' + status : '');
            btn.textContent = attendanceIcons[status] || '○';
            btn.title = status ? (status.charAt(0).toUpperCase() + status.slice(1) + ' — click to change') : 'Click to set attendance';
        }

        function renderAttendanceHeaders() {
            const headerRow = document.querySelector('#attendanceHeader tr');
            while (headerRow.children.length > 1) headerRow.removeChild(headerRow.lastChild);
            attendanceDates.forEach(date => {
                const th = document.createElement('th');
                th.className = 'p-3 text-center relative';
                const dateDiv = document.createElement('div');
                dateDiv.innerText = date;
                dateDiv.className = 'mb-1';
                const deleteBtn = document.createElement('button');
                deleteBtn.innerHTML = '×';
                deleteBtn.className = 'absolute top-0 right-0 text-gray-400 hover:text-red-500 text-sm w-4 h-4 flex items-center justify-center';
                deleteBtn.onclick = function() { removeAttendanceDate(date); };
                th.appendChild(dateDiv);
                th.appendChild(deleteBtn);
                headerRow.appendChild(th);
            });
            const rows = document.querySelectorAll('#attendanceBody tr');
            rows.forEach(row => {
                while (row.children.length - 1 < attendanceDates.length) {
                    const dateIndex = row.children.length - 1;
                    const date = attendanceDates[dateIndex];
                    const studentId = row.dataset.studentId || '';
                    row.appendChild(createAttendanceChoiceCell(date, studentId));
                }
            });
        }

        function addAttendanceColumn(date) {
            if (!date || !/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/.test(date)) {
                alert('Please provide a valid date.');
                return false;
            }
            if (attendanceDates.includes(date)) {
                alert('Date already added');
                return false;
            }
            attendanceDates.push(date);
            renderAttendanceHeaders();
            const rows = document.querySelectorAll('#attendanceBody tr');
            rows.forEach(row => {
                const studentId = row.dataset.studentId || '';
                row.appendChild(createAttendanceChoiceCell(date, studentId));
            });
            return true;
        }

        async function addAttendanceDate() {
            const today = new Date().toISOString().split('T')[0];
            if (!addAttendanceColumn(today)) return;
            await loadAttendanceDataForDate(today);
            updateLiveAnalysis();
        }

        async function addAttendanceSpecificDate() {
            const input = document.getElementById('attendanceSpecificDateInput');
            if (!input || !input.value) return; 
            const selectedDate = input.value;
            if (!addAttendanceColumn(selectedDate)) return;
            await loadAttendanceDataForDate(selectedDate);
            input.value = '';
            updateLiveAnalysis();
        }

        function removeAttendanceDate(date) {
            if (confirm(`Are you sure you want to remove the attendance date ${date}? This will delete all attendance records for this date.`)) {
                const index = attendanceDates.indexOf(date);
                if (index > -1) {
                    attendanceDates.splice(index, 1);
                    renderAttendanceHeaders();
                    // Remove the corresponding cells from all rows
                    const rows = document.querySelectorAll('#attendanceBody tr');
                    rows.forEach(row => {
                        if (row.children.length > index + 1) {
                            row.removeChild(row.children[index + 1]);
                        }
                    });
                    updateLiveAnalysis();
                }
            }
        }

        async function loadAttendanceData() {
            if (attendanceDates.length === 0) return;
            for (let date of attendanceDates) await loadAttendanceDataForDate(date);
        }

        async function loadAttendanceDataForDate(date) {
            try {
                const formData = new FormData();
                formData.append('action', 'get_attendance_by_date');
                formData.append('date', date);
                const response = await fetch('php/teacher_actions.php', { method: 'POST', body: formData });
                let records = await parseJsonResponse(response);
                if (records && typeof records === 'object' && records.success === false) {
                    throw new Error(records.message || 'Server error');
                }
                if (records && typeof records === 'object' && records.success === true) {
                    records = records.records || [];
                }
                if (!Array.isArray(records)) {
                    console.error('Invalid records format for date', date, records);
                    records = [];
                }
                const dateIndex = attendanceDates.indexOf(date);
                if (dateIndex === -1) return;
                const rows = document.querySelectorAll('#attendanceBody tr');
                for (let i = 0; i < rows.length; i++) {
                    const studentName = rows[i].cells[0].innerText.trim();
                    const record = records.find(r => r.full_name === studentName);
                    if (record && record.status) {
                        const targetCell = rows[i].children[dateIndex + 1];
                        if (targetCell) {
                            targetCell.dataset.status = record.status;
                            refreshAttendanceCellUI(targetCell);
                        }
                    }
                }
            } catch (error) { console.error(error); }
        }

        async function saveAttendanceLog() {
            if (attendanceDates.length === 0) { alert('No date columns added'); return; }
            const rows = document.querySelectorAll('#attendanceBody tr');
            const attendanceData = [];
            for (let row of rows) {
                const studentName = row.cells[0].innerText.trim();
                const studentId = row.dataset.studentId || '';
                for (let i = 0; i < attendanceDates.length; i++) {
                    const cell = row.children[i+1];
                    const status = cell?.dataset?.status || '';
                    if (status) {
                        attendanceData.push({
                            date: attendanceDates[i],
                            student_name: studentName,
                            student_id: studentId ? parseInt(studentId, 10) : null,
                            status: status
                        });
                    }
                }
            }
            const formData = new FormData();
            formData.append('action', 'save_attendance');
            formData.append('attendance_data', JSON.stringify(attendanceData));
            formData.append('date', new Date().toISOString().split('T')[0]);
            try {
                const response = await fetch('php/teacher_actions.php', { method: 'POST', body: formData });
                const result = await parseJsonResponse(response);
                if (result.success) {
                    alert('Attendance saved!');
                    // Update analysis stats without reloading
                    updateLiveAnalysis();
                }
                else alert('Error saving attendance');
            } catch (error) { alert('Network error'); }
        }

        function initializeAttendanceFilters() {
            const gradeSelect = document.getElementById('attendanceGradeFilter');
            const sectionSelect = document.getElementById('attendanceSectionFilter');
            if (!gradeSelect || !sectionSelect || !Array.isArray(teacherSections)) {
                return;
            }

            const grades = [...new Set(teacherSections.map(sec => sec.grade_level).filter(Boolean))].sort();
            gradeSelect.innerHTML = '<option value="all">All Grades</option>' + grades.map(grade => `<option value="${escapeHtml(grade)}">${escapeHtml(grade)}</option>`).join('');
            updateAttendanceSectionOptions();

            gradeSelect.addEventListener('change', () => {
                updateAttendanceSectionOptions();
                updateAttendanceAnalysis();
            });
            sectionSelect.addEventListener('change', updateAttendanceAnalysis);
        }

        function updateAttendanceSectionOptions() {
            const gradeSelect = document.getElementById('attendanceGradeFilter');
            const sectionSelect = document.getElementById('attendanceSectionFilter');
            if (!gradeSelect || !sectionSelect || !Array.isArray(teacherSections)) {
                return;
            }
            const selectedGrade = gradeSelect.value;
            const sections = [...new Set(
                teacherSections
                    .filter(sec => selectedGrade === 'all' || sec.grade_level === selectedGrade)
                    .map(sec => sec.section)
                    .filter(Boolean)
            )].sort();

            sectionSelect.innerHTML = '<option value="all">All Sections</option>' + sections.map(section => `<option value="${escapeHtml(section)}">${escapeHtml(section)}</option>`).join('');
        }

        function updateAttendanceAnalysis() {
            const selectedGrade = document.getElementById('attendanceGradeFilter')?.value || 'all';
            const selectedSection = document.getElementById('attendanceSectionFilter')?.value || 'all';
            const rows = document.querySelectorAll('#attendanceBody tr');

            rows.forEach(row => {
                const studentId = row.dataset.studentId;
                const student = teacherHomeStudents.find(s => String(s.id) === String(studentId));
                const matchesGrade = selectedGrade === 'all' || (student && student.grade_level === selectedGrade);
                const matchesSection = selectedSection === 'all' || (student && student.section === selectedSection);
                row.style.display = student && matchesGrade && matchesSection ? '' : 'none';
            });

            updateLiveAnalysis();
        }

        function escapeHtml(str) { return str.replace(/[&<>]/g, function(m){ if(m==='&') return '&amp;'; if(m==='<') return '&lt;'; if(m==='>') return '&gt;'; return m;}); }

        function updateLiveAnalysis() {
            const rows = document.querySelectorAll('#attendanceBody tr');
            let present = 0, absent = 0, late = 0;

            rows.forEach(row => {
                if (row.style.display === 'none' || row.hidden) return;
                attendanceDates.forEach((_, idx) => {
                    const status = row.children[idx + 1]?.dataset?.status || '';
                    if (status === 'present') present++;
                    if (status === 'absent') absent++;
                    if (status === 'late') late++;
                });
            });

            const presentEl = document.getElementById('presentCountDisplay');
            const absentEl = document.getElementById('absentCountDisplay');
            const lateEl = document.getElementById('lateCountDisplay');
            if (presentEl) presentEl.textContent = present;
            if (absentEl) absentEl.textContent = absent;
            if (lateEl) lateEl.textContent = late;
        }

        function promptAttendanceReportRange() {
            const modal = document.getElementById('attendanceRangeModal');
            if (modal) modal.style.display = 'flex';
        }

        function closeAttendanceRangeModal() {
            const modal = document.getElementById('attendanceRangeModal');
            if (modal) modal.style.display = 'none';
        }

        function generateAttendanceReport(rangeType) {
            const date = new Date().toISOString().split('T')[0];
            const months = rangeType === 'school_year' ? 5 : 1;
            const grade = document.getElementById('attendanceGradeFilter')?.value || 'all';
            const section = document.getElementById('attendanceSectionFilter')?.value || 'all';
            const url = `php/generate_attendance_pdf.php?range_type=${encodeURIComponent(rangeType)}&date=${encodeURIComponent(date)}&months=${encodeURIComponent(months)}&grade=${encodeURIComponent(grade)}&section=${encodeURIComponent(section)}`;
            window.open(url, '_blank');
            closeAttendanceRangeModal();
        }

        function promptAttendanceLedger() {
            const modal = document.getElementById('attendanceLedgerModal');
            if (modal) modal.style.display = 'flex';
        }

        function closeAttendanceLedgerModal() {
            const modal = document.getElementById('attendanceLedgerModal');
            if (modal) modal.style.display = 'none';
        }

        function toggleLedgerCustomDates() {
            const rangeSelect = document.getElementById('ledgerRangeType');
            const customDiv = document.getElementById('ledgerCustomDates');
            if (rangeSelect && rangeSelect.value === 'custom') {
                if (customDiv) customDiv.style.display = 'grid';
                const startInput = document.getElementById('ledgerStartDate');
                const endInput = document.getElementById('ledgerEndDate');
                if (startInput && !startInput.value) {
                    const now = new Date();
                    const firstDay = new Date(now.getFullYear(), now.getMonth(), 1).toISOString().split('T')[0];
                    const lastDay = new Date(now.getFullYear(), now.getMonth() + 1, 0).toISOString().split('T')[0];
                    startInput.value = firstDay;
                    endInput.value = lastDay;
                }
            } else {
                if (customDiv) customDiv.style.display = 'none';
            }
        }
    </script>
</head>
<body class="bg-gray-100 font-sans <?php echo in_array($userRole, ['admin', 'cashier', 'registrar']) ? 'admin-mode' : ''; ?>">
    <div class="dashboard-page relative min-h-screen" id="dashboardPage">
        <div id="sidebarOverlay" class="fixed inset-0 bg-black bg-opacity-50 z-[999] transition-opacity duration-300 opacity-0 pointer-events-none md:hidden" onclick="toggleSidebar()"></div>

        <div class="sidebar" id="sidebar">
            <div class="sidebar-header p-8 text-center bg-white bg-opacity-10 border-b border-white border-opacity-10">
                <div class="relative group mx-auto mb-4 w-[110px] h-[110px] cursor-pointer" onclick="triggerProfilePicUpload();" title="Click to upload profile picture">
                    <?php 
                    $showPicture = !empty($user['profile_picture']) && ($user['profile_picture_status'] === 'approved');
                    $picSrc = $showPicture ? htmlspecialchars($user['profile_picture']) : 'images/logo.png';
                    ?>
                    <div class="w-[110px] h-[110px] rounded-full overflow-hidden border-2 border-white border-opacity-25 group-hover:border-opacity-70 transition flex items-center justify-center bg-white bg-opacity-5">
                        <img src="<?php echo $picSrc; ?>" alt="Profile Picture" class="w-full h-full object-cover <?php echo !$showPicture ? 'p-2 object-contain' : ''; ?>" id="sidebar-avatar-img">
                    </div>
                    <div class="absolute inset-0 rounded-full bg-black bg-opacity-50 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity">
                        <span class="text-white text-[10px] font-semibold uppercase tracking-wider">Upload</span>
                    </div>
                    <input type="file" id="profilePicFileInput" accept="image/jpeg,image/png" class="hidden" onchange="uploadProfilePicture(this);">
                </div>
                <?php
                $userFullName = trim(($user['first_name'] ?? '') . ' ' . ($user['middle_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
                if (!empty($user['suffix'])) {
                    $userFullName .= ', ' . $user['suffix'];
                }
                if (empty($userFullName)) {
                    $userFullName = $fullName;
                }
                ?>
                <h3 class="text-lg font-semibold text-white truncate" title="<?php echo htmlspecialchars($userFullName); ?>"><?php echo htmlspecialchars($userFullName); ?></h3>
                <p class="text-xs text-white text-opacity-50 mt-1 uppercase tracking-wider"><?php echo htmlspecialchars($userRole); ?></p>
            </div>
            <ul class="py-5">
                <?php if ($userRole === 'admin'): ?>
                    <li><a href="#" onclick="navigateTo('home'); return false;" class="block px-6 py-4 text-white text-opacity-90 hover:bg-white hover:bg-opacity-10 hover:text-white active:bg-white active:bg-opacity-20 active:border-l-4 active:border-green-500 font-medium transition" id="menu-home">Enrollment Requests</a></li>
                    <li><a href="#" onclick="navigateTo('users'); return false;" class="block px-6 py-4 text-white text-opacity-90 hover:bg-white hover:bg-opacity-10 hover:text-white active:bg-white active:bg-opacity-20 active:border-l-4 active:border-green-500 font-medium transition" id="menu-users">User Management</a></li>
                    <li><a href="#" onclick="navigateTo('sections-subjects'); return false;" class="block px-6 py-4 text-white text-opacity-90 hover:bg-white hover:bg-opacity-10 hover:text-white active:bg-white active:bg-opacity-20 active:border-l-4 active:border-green-500 font-medium transition" id="menu-sections-subjects">Sections & Subjects</a></li>
                    <li><a href="#" onclick="navigateTo('payments'); return false;" class="block px-6 py-4 text-white text-opacity-90 hover:bg-white hover:bg-opacity-10 hover:text-white active:bg-white active:bg-opacity-20 active:border-l-4 active:border-green-500 font-medium transition" id="menu-payments">Payment Processing</a></li>
                    <li><a href="#" onclick="navigateTo('documents'); return false;" class="block px-6 py-4 text-white text-opacity-90 hover:bg-white hover:bg-opacity-10 hover:text-white active:bg-white active:bg-opacity-20 active:border-l-4 active:border-green-500 font-medium transition" id="menu-documents">Document Management</a></li>
                    <li><a href="#" onclick="navigateTo('tuition-fees'); return false;" class="block px-6 py-4 text-white text-opacity-90 hover:bg-white hover:bg-opacity-10 hover:text-white active:bg-white active:bg-opacity-20 active:border-l-4 active:border-green-500 font-medium transition" id="menu-tuition-fees">Tuition Fee Manager</a></li>
                    <li><a href="#" onclick="navigateTo('grants-discounts'); return false;" class="block px-6 py-4 text-white text-opacity-90 hover:bg-white hover:bg-opacity-10 hover:text-white active:bg-white active:bg-opacity-20 active:border-l-4 active:border-green-500 font-medium transition" id="menu-grants-discounts">Grants/Discount Manager</a></li>
                    <li><a href="#" onclick="navigateTo('grade-submissions'); return false;" class="block px-6 py-4 text-white text-opacity-90 hover:bg-white hover:bg-opacity-10 hover:text-white active:bg-white active:bg-opacity-20 active:border-l-4 active:border-green-500 font-medium transition" id="menu-grade-submissions">Grade Submissions</a></li>
                    <li><a href="#" onclick="navigateTo('book-manager'); return false;" class="block px-6 py-4 text-white text-opacity-90 hover:bg-white hover:bg-opacity-10 hover:text-white active:bg-white active:bg-opacity-20 active:border-l-4 active:border-green-500 font-medium transition" id="menu-book-manager">Book Manager</a></li>
                    <li><a href="#" onclick="navigateTo('photo-approvals'); return false;" class="block px-6 py-4 text-white text-opacity-90 hover:bg-white hover:bg-opacity-10 hover:text-white active:bg-white active:bg-opacity-20 active:border-l-4 active:border-green-500 font-medium transition" id="menu-photo-approvals">Photo Approvals</a></li>
                    <li><a href="#" onclick="navigateTo('announcements-manager'); return false;" class="block px-6 py-4 text-white text-opacity-90 hover:bg-white hover:bg-opacity-10 hover:text-white active:bg-white active:bg-opacity-20 active:border-l-4 active:border-green-500 font-medium transition" id="menu-announcements-manager">Announcement Manager</a></li>
                    <li><a href="#" onclick="navigateTo('profile'); return false;" class="block px-6 py-4 text-white text-opacity-90 hover:bg-white hover:bg-opacity-10 hover:text-white active:bg-white active:bg-opacity-20 active:border-l-4 active:border-green-500 font-medium transition" id="menu-profile">Profile</a></li>
                <?php elseif ($userRole === 'cashier'): ?>
                    <li><a href="#" onclick="navigateTo('home'); return false;" class="block px-6 py-4 text-white text-opacity-90 hover:bg-white hover:bg-opacity-10 hover:text-white active:bg-white active:bg-opacity-20 active:border-l-4 active:border-green-500 font-medium transition" id="menu-home">Dashboard</a></li>
                    <li><a href="#" onclick="navigateTo('payments'); return false;" class="block px-6 py-4 text-white text-opacity-90 hover:bg-white hover:bg-opacity-10 hover:text-white active:bg-white active:bg-opacity-20 active:border-l-4 active:border-green-500 font-medium transition" id="menu-payments">Payment Processing</a></li>
                    <li><a href="#" onclick="navigateTo('tuition-fees'); return false;" class="block px-6 py-4 text-white text-opacity-90 hover:bg-white hover:bg-opacity-10 hover:text-white active:bg-white active:bg-opacity-20 active:border-l-4 active:border-green-500 font-medium transition" id="menu-tuition-fees">Tuition Fee Manager</a></li>
                    <li><a href="#" onclick="navigateTo('grants-discounts'); return false;" class="block px-6 py-4 text-white text-opacity-90 hover:bg-white hover:bg-opacity-10 hover:text-white active:bg-white active:bg-opacity-20 active:border-l-4 active:border-green-500 font-medium transition" id="menu-grants-discounts">Grants/Discount Manager</a></li>
                    <li><a href="#" onclick="navigateTo('profile'); return false;" class="block px-6 py-4 text-white text-opacity-90 hover:bg-white hover:bg-opacity-10 hover:text-white active:bg-white active:bg-opacity-20 active:border-l-4 active:border-green-500 font-medium transition" id="menu-profile">Profile</a></li>
                <?php elseif ($userRole === 'registrar'): ?>
                    <li><a href="#" onclick="navigateTo('home'); return false;" class="block px-6 py-4 text-white text-opacity-90 hover:bg-white hover:bg-opacity-10 hover:text-white active:bg-white active:bg-opacity-20 active:border-l-4 active:border-green-500 font-medium transition" id="menu-home">Enrollment Requests</a></li>
                    <li><a href="#" onclick="navigateTo('users'); return false;" class="block px-6 py-4 text-white text-opacity-90 hover:bg-white hover:bg-opacity-10 hover:text-white active:bg-white active:bg-opacity-20 active:border-l-4 active:border-green-500 font-medium transition" id="menu-users">User Management</a></li>
                    <li><a href="#" onclick="navigateTo('sections-subjects'); return false;" class="block px-6 py-4 text-white text-opacity-90 hover:bg-white hover:bg-opacity-10 hover:text-white active:bg-white active:bg-opacity-20 active:border-l-4 active:border-green-500 font-medium transition" id="menu-sections-subjects">Sections & Subjects</a></li>
                    <li><a href="#" onclick="navigateTo('documents'); return false;" class="block px-6 py-4 text-white text-opacity-90 hover:bg-white hover:bg-opacity-10 hover:text-white active:bg-white active:bg-opacity-20 active:border-l-4 active:border-green-500 font-medium transition" id="menu-documents">Document Management</a></li>
                    <li><a href="#" onclick="navigateTo('grade-submissions'); return false;" class="block px-6 py-4 text-white text-opacity-90 hover:bg-white hover:bg-opacity-10 hover:text-white active:bg-white active:bg-opacity-20 active:border-l-4 active:border-green-500 font-medium transition" id="menu-grade-submissions">Grade Submissions</a></li>
                    <li><a href="#" onclick="navigateTo('book-manager'); return false;" class="block px-6 py-4 text-white text-opacity-90 hover:bg-white hover:bg-opacity-10 hover:text-white active:bg-white active:bg-opacity-20 active:border-l-4 active:border-green-500 font-medium transition" id="menu-book-manager">Book Manager</a></li>
                    <li><a href="#" onclick="navigateTo('photo-approvals'); return false;" class="block px-6 py-4 text-white text-opacity-90 hover:bg-white hover:bg-opacity-10 hover:text-white active:bg-white active:bg-opacity-20 active:border-l-4 active:border-green-500 font-medium transition" id="menu-photo-approvals">Photo Approvals</a></li>
                    <li><a href="#" onclick="navigateTo('announcements-manager'); return false;" class="block px-6 py-4 text-white text-opacity-90 hover:bg-white hover:bg-opacity-10 hover:text-white active:bg-white active:bg-opacity-20 active:border-l-4 active:border-green-500 font-medium transition" id="menu-announcements-manager">Announcement Manager</a></li>
                    <li><a href="#" onclick="navigateTo('profile'); return false;" class="block px-6 py-4 text-white text-opacity-90 hover:bg-white hover:bg-opacity-10 hover:text-white active:bg-white active:bg-opacity-20 active:border-l-4 active:border-green-500 font-medium transition" id="menu-profile">Profile</a></li>
                <?php elseif ($userRole === 'teacher'): ?>
                    <li><a href="#" onclick="navigateTo('home'); return false;" class="block px-6 py-4 text-white text-opacity-90 hover:bg-white hover:bg-opacity-10 hover:text-white active:bg-white active:bg-opacity-20 active:border-l-4 active:border-green-500 font-medium transition" id="menu-home">Home</a></li>
                    <li><a href="#" onclick="navigateTo('grade-encoding'); return false;" class="block px-6 py-4 text-white text-opacity-90 hover:bg-white hover:bg-opacity-10 hover:text-white active:bg-white active:bg-opacity-20 active:border-l-4 active:border-green-500 font-medium transition" id="menu-grade-encoding">Grade Encoding</a></li>
                    <li><a href="#" onclick="navigateTo('attendance'); return false;" class="block px-6 py-4 text-white text-opacity-90 hover:bg-white hover:bg-opacity-10 hover:text-white active:bg-white active:bg-opacity-20 active:border-l-4 active:border-green-500 font-medium transition" id="menu-attendance">Attendance Module</a></li>
                    <li><a href="#" onclick="navigateTo('class-list'); return false;" class="block px-6 py-4 text-white text-opacity-90 hover:bg-white hover:bg-opacity-10 hover:text-white active:bg-white active:bg-opacity-20 active:border-l-4 active:border-green-500 font-medium transition" id="menu-class-list">Class List</a></li>
                <?php else: ?>
                    <li><a href="#" onclick="navigateTo('home'); return false;" class="block px-6 py-4 text-white text-opacity-90 hover:bg-white hover:bg-opacity-10 hover:text-white active:bg-white active:bg-opacity-20 active:border-l-4 active:border-green-500 font-medium transition" id="menu-home">Home</a></li>
                    <li><a href="#" onclick="navigateTo('grades'); return false;" class="block px-6 py-4 text-white text-opacity-90 hover:bg-white hover:bg-opacity-10 hover:text-white active:bg-white active:bg-opacity-20 active:border-l-4 active:border-green-500 font-medium transition" id="menu-grades">Grades</a></li>
                    <li><a href="#" onclick="navigateTo('subjects'); return false;" class="block px-6 py-4 text-white text-opacity-90 hover:bg-white hover:bg-opacity-10 hover:text-white active:bg-white active:bg-opacity-20 active:border-l-4 active:border-green-500 font-medium transition" id="menu-subjects">Subjects</a></li>
                    <li><a href="#" onclick="navigateTo('payables'); return false;" class="block px-6 py-4 text-white text-opacity-90 hover:bg-white hover:bg-opacity-10 hover:text-white active:bg-white active:bg-opacity-20 active:border-l-4 active:border-green-500 font-medium transition" id="menu-payables">Payables</a></li>
                    <li><a href="#" onclick="navigateTo('profile'); return false;" class="block px-6 py-4 text-white text-opacity-90 hover:bg-white hover:bg-opacity-10 hover:text-white active:bg-white active:bg-opacity-20 active:border-l-4 active:border-green-500 font-medium transition" id="menu-profile">Profile</a></li>
                    <li><a href="#" onclick="navigateTo('announcements'); return false;" class="block px-6 py-4 text-white text-opacity-90 hover:bg-white hover:bg-opacity-10 hover:text-white active:bg-white active:bg-opacity-20 active:border-l-4 active:border-green-500 font-medium transition" id="menu-announcements">Announcements</a></li>
                <?php endif; ?>
            </ul>
        </div>
        
        <div class="dashboard-main flex flex-col min-h-screen">
            <div class="dashboard-header bg-[#0a2d63] text-white px-4 md:px-10 py-4 shadow-md w-full">
                <div class="header-content grid grid-cols-[auto_1fr_auto] items-center max-w-7xl mx-auto gap-2">
                    <div class="header-left flex items-center justify-start">
                        <button id="hamburgerBtn" class="md:hidden text-white hover:text-gray-300 focus:outline-none mr-4" onclick="toggleSidebar()">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16m-7 6h7"></path>
                            </svg>
                        </button>
                    </div>
                    <div class="header-center text-center justify-self-center max-w-[550px] w-full px-2">
                        <h2 class="text-lg md:text-2xl lg:text-3xl font-semibold mb-1 break-words">
                            <span class="block md:inline">Welcome <?php echo htmlspecialchars(ucfirst($userRole)); ?> <span class="hidden md:inline"><?php echo htmlspecialchars($firstName); ?></span></span>
                            <span class="block md:inline md:ml-1">to your Dashboard</span>
                        </h2>
                        <p class="text-xs md:text-base opacity-90 hidden sm:block break-words">Stay updated with your academic progress and school activities</p>
                    </div>
                    <div class="header-right flex items-center justify-end">
                        <div class="user-info-container flex items-center justify-end gap-2 md:gap-4">
                            <div class="notification-bell-container mr-2" id="notifBell">
                                <i class="fas fa-bell text-white text-xl"></i>
                                <span class="notification-badge hidden" id="notifCount">0</span>
                                <div class="notification-dropdown" id="notifDropdown">
                                    <div class="notification-header">
                                        <span class="font-bold">Notifications</span>
                                        <button class="text-xs text-[#0a2d63] hover:underline" onclick="markAllRead(event)">Mark all as read</button>
                                    </div>
                                    <div class="notification-list max-h-[400px] overflow-y-auto" id="notifList">
                                        <!-- Notifications will be loaded here -->
                                        <div class="notification-empty">No notifications yet.</div>
                                    </div>
                                    <div class="notification-footer">
                                        <a href="#" onclick="toggleNotifDropdown(event)" class="hover:underline">Close</a>
                                    </div>
                                </div>
                            </div>
                            <button class="logout-btn bg-white text-[#0a2d63] px-3 py-1.5 md:px-5 md:py-2 rounded-full font-semibold hover:bg-gray-100 hover:-translate-y-0.5 transition text-sm md:text-base whitespace-nowrap flex-shrink-0" onclick="window.location.href='php/logout.php'">Logout</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="dashboard-content flex justify-center items-start w-full p-5 min-h-[calc(100vh-120px)]">
                <div class="centered-container w-full max-w-[1200px] mx-auto">
                    <?php if (in_array($userRole, ['admin', 'registrar', 'cashier'])): ?>
                        <div class="dashboard-card bg-white shadow-lg border border-gray-200 hidden grades-card <?php echo (in_array($userRole, ['admin', 'registrar'])) ? 'active' : ''; ?>" id="adminEnrollmentCard">
                            <div class="card-content p-8 space-y-6 w-full">
                                <div class="stats-grid grid grid-cols-1 md:grid-cols-3 gap-6">
                                    <div class="stat-card" onclick="navigateTo('home'); setTimeout(() => { document.getElementById('enrollmentList').scrollIntoView({behavior:'smooth'}); const rows = document.querySelectorAll('#enrollmentTableBody tr'); if(rows.length>0){ rows[0].classList.add('highlight'); rows[0].scrollIntoView({block:'center'}); setTimeout(() => { rows[0].classList.remove('highlight'); }, 1000); } }, 1000);">
                                        <h3>New Enrollees</h3>
                                        <div class="value"><?php echo $newRequests; ?></div>
                                    </div>
                                    <div class="stat-card" onclick="navigateTo('users'); document.getElementById('dirFilterStudent').checked=true; document.querySelectorAll('.dirFilterGradeCheckbox').forEach(cb => cb.checked=false); document.querySelectorAll('.dirFilterGradeCheckbox').forEach(cb => { if(['Grade 7','Grade 8','Grade 9','Grade 10'].includes(cb.value)) cb.checked=true; }); applyUserDirectoryFilters();">
                                        <h3>Total Students Enrolled (Grades 7-10)</h3>
                                        <div class="value"><?php echo $grades7to10; ?></div>
                                    </div>
                                    <div class="stat-card" onclick="navigateTo('users'); document.getElementById('dirFilterStudent').checked=true; document.querySelectorAll('.dirFilterGradeCheckbox').forEach(cb => cb.checked=false); document.querySelectorAll('.dirFilterGradeCheckbox').forEach(cb => { if(['Grade 11','Grade 12'].includes(cb.value)) cb.checked=true; }); applyUserDirectoryFilters();">
                                        <h3>Total Students Enrolled (Grades 11-12)</h3>
                                        <div class="value"><?php echo $grades11to12; ?></div>
                                    </div>
                                </div>

                                <div class="chart-container overflow-hidden">
                                    <div class="flex flex-wrap gap-4 mb-4">
                                        <select id="dataTypeFilter" class="filter-select w-full md:w-auto" onchange="updateChart()">
                                            <option value="enrollees">Enrollment Requests</option>
                                            <option value="students">Registered Students</option>
                                            <option value="both" selected>Both</option>
                                        </select>
                                        <select id="chartGradeFilter" class="filter-select w-full md:w-auto" onchange="updateChart()">
                                            <option value="">All Grades</option>
                                            <option value="Grade 7">Grade 7</option>
                                            <option value="Grade 8">Grade 8</option>
                                            <option value="Grade 9">Grade 9</option>
                                            <option value="Grade 10">Grade 10</option>
                                            <option value="Grade 11">Grade 11</option>
                                            <option value="Grade 12">Grade 12</option>
                                        </select>
                                        <select id="chartSectionFilter" class="filter-select w-full md:w-auto" onchange="updateChart()">
                                            <option value="">All Sections</option>
                                        </select>
                                    </div>
                                    <canvas id="enrollmentChart" style="width:100%; max-height:300px;"></canvas>
                                </div>

                                <div class="enrollment-controls flex flex-col md:flex-row justify-between items-center gap-4 p-4 bg-gray-50 rounded">
                                    <div class="enrollment-stats flex flex-col md:flex-row items-center gap-4">
                                        <h3 class="text-2xl font-semibold text-[#0a2d63] text-center md:text-left">Student Access Requests</h3>
                                    </div>
                                    <button class="search-enrollment-btn bg-[#0a2d63] text-white px-4 py-2 rounded font-medium hover:bg-[#08306b] transition flex items-center gap-2 w-full md:w-auto justify-center" onclick="openEnrollmentSearchModal()">
                                        Search Enrollees
                                    </button>
                                </div>
                                <p class="text-gray-600 text-center md:text-left">Review and manage pending student enrollments</p>
                                
                                <div id="enrollmentList" class="space-y-4 overflow-x-auto w-full">
                                    <table class="enrollment-table min-w-[800px] md:min-w-full">
                                        <thead>
                                            <tr>
                                                <th>Full Name</th>
                                                <th>Email</th>
                                                <th>Status</th>
                                                <th>Actions</th>
                                                <th></th>
                                                <th></th>
                                            </tr>
                                        </thead>
                                        <tbody id="enrollmentTableBody">
                                            <tr><td colspan="6" class="text-center text-gray-400 py-10">Loading enrollments...</td></tr>
                                        </tbody>
                                    </table>
                                </div>

                                <div id="enrollmentPagination" class="pagination-controls hidden mt-5 p-4 bg-gray-50 rounded flex flex-col md:flex-row items-center gap-4">
                                    <div class="custom-per-page flex flex-wrap items-center gap-2">
                                        <span class="text-sm text-gray-600">Show:</span>
                                        <select id="perPageSelect" class="border border-gray-300 rounded px-2 py-1 text-sm" onchange="changePerPage()">
                                            <option value="10">10</option>
                                            <option value="25">25</option>
                                            <option value="50">50</option>
                                            <option value="75">75</option>
                                            <option value="100">100</option>
                                            <option value="custom">Custom</option>
                                        </select>
                                        <div id="customPerPageInput" class="hidden flex items-center gap-2">
                                            <input type="number" id="customPerPage" min="1" max="500" placeholder="Number" class="border border-gray-300 rounded px-2 py-1 w-20 text-sm">
                                            <button onclick="applyCustomPerPage()" class="bg-[#0a2d63] text-white px-3 py-1 rounded text-sm">Apply</button>
                                        </div>
                                    </div>
                                    <div class="pagination-info text-sm text-gray-600 text-center" id="paginationInfo"></div>
                                    <div class="pagination-buttons flex flex-wrap justify-center gap-1 w-full md:w-auto md:ml-auto" id="paginationButtons"></div>
                                </div>
                            </div>
                        </div>
                                    <?php if ($userRole === 'cashier'): ?>
                        <div class="dashboard-card bg-white shadow-lg border border-gray-200 hidden active w-full max-w-full" id="cashierDashboardCard">
                            <div class="card-content p-8 space-y-8 w-full max-w-full">
                                <h2 class="text-2xl font-bold text-[#0a2d63] mb-4">Cashier Summary Dashboard</h2>
                                
                                <!-- Top Stats Grid -->
                                <div class="stats-grid grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                                    <div class="stat-card">
                                        <h3>Total Enrollees</h3>
                                        <div class="value"><?php echo $cashierTotalEnrollees; ?></div>
                                    </div>
                                    <div class="stat-card">
                                        <h3>Total Old Students (G7-12)</h3>
                                        <div class="value"><?php echo $cashierOldHS + $cashierOldSHS; ?></div>
                                    </div>
                                    <div class="stat-card">
                                        <h3>Total New Students (G7-12)</h3>
                                        <div class="value"><?php echo $cashierNewHS + $cashierNewSHS; ?></div>
                                    </div>
                                    <div class="stat-card">
                                        <h3>Most Used Plan</h3>
                                        <div class="value"><?php echo $cashierMostUsedMonth === 'None' || $cashierMostUsedMonth === 0 ? 'None' : $cashierMostUsedMonth . ' Months'; ?></div>
                                    </div>
                                </div>

                                <!-- Analytics Section -->
                                <h3 class="text-xl font-bold text-[#1e3a8a] mt-8 mb-4">Payment Methods & Enrollment Trends</h3>
                                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                                    <!-- Line Graph -->
                                    <div class="lg:col-span-2 bg-white p-6 rounded-xl border border-gray-200 shadow-sm h-[350px] flex flex-col">
                                        <div class="text-xs font-bold uppercase tracking-wider text-gray-500 mb-4">Enrollment Velocity (Daily)</div>
                                        <div class="flex-grow relative w-full h-full">
                                            <canvas id="cashierLineChart"></canvas>
                                        </div>
                                    </div>
                                    <!-- Donut Graph -->
                                    <div class="bg-white p-6 rounded-xl border border-gray-200 shadow-sm h-[350px] flex flex-col">
                                        <div class="text-xs font-bold uppercase tracking-wider text-gray-500 mb-4">Payment Methods Breakdown</div>
                                        <div class="flex-grow relative w-full h-full">
                                            <canvas id="cashierDonutChart"></canvas>
                                        </div>
                                    </div>
                                </div>

                                <!-- Detailed Breakdown (White Cards) -->
                                <h3 class="text-xl font-bold text-[#1e3a8a] mt-8 mb-4">High School & Senior High Breakdown</h3>
                                <div class="stats-grid grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                                    <div class="stat-card">
                                        <h3>New Students (JHS)</h3>
                                        <div class="value"><?php echo $cashierNewHS; ?></div>
                                    </div>
                                    <div class="stat-card">
                                        <h3>Old Students (JHS)</h3>
                                        <div class="value"><?php echo $cashierOldHS; ?></div>
                                    </div>
                                    <div class="stat-card">
                                        <h3>New Students (SHS)</h3>
                                        <div class="value"><?php echo $cashierNewSHS; ?></div>
                                    </div>
                                    <div class="stat-card">
                                        <h3>Old Students (SHS)</h3>
                                        <div class="value"><?php echo $cashierOldSHS; ?></div>
                                    </div>
                                </div>

                                <!-- Unpaid Students Section -->
                                <h3 class="text-xl font-bold text-[#1e3a8a] mt-8 mb-4">Unpaid Students Directory</h3>
                                <div class="bg-white rounded border border-gray-200 overflow-hidden">
                                    <div class="overflow-x-auto">
                                        <table class="w-full text-left border-collapse">
                                            <thead>
                                                <tr class="bg-gray-100 text-gray-700 text-sm border-b border-gray-200">
                                                    <th class="p-3 font-semibold">Student Name</th>
                                                    <th class="p-3 font-semibold">Grade & Section</th>
                                                    <th class="p-3 font-semibold">Total Assessed</th>
                                                    <th class="p-3 font-semibold">Total Paid</th>
                                                    <th class="p-3 font-semibold text-right">Balance</th>
                                                </tr>
                                            </thead>
                                            <tbody id="unpaidStudentsTableBody">
                                                <tr><td colspan="5" class="p-8 text-center text-gray-500">Loading unpaid students...</td></tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <script>
                            document.addEventListener('DOMContentLoaded', function() {
                                <?php if ($userRole === 'cashier'): ?>
                                // Line Chart (Enrollment Velocity)
                                const lineCtx = document.getElementById('cashierLineChart');
                                if (lineCtx) {
                                    new Chart(lineCtx.getContext('2d'), {
                                        type: 'line',
                                        data: {
                                            labels: <?php echo json_encode($dailyLabels); ?>,
                                            datasets: [{
                                                label: 'Enrollees',
                                                data: <?php echo json_encode($dailyEnrollees); ?>,
                                                borderColor: '#f59e0b',
                                                backgroundColor: '#f59e0b20',
                                                borderWidth: 3,
                                                fill: true,
                                                tension: 0.4,
                                                pointRadius: 3,
                                                pointBackgroundColor: '#f59e0b'
                                            }, {
                                                label: 'Accepted Students',
                                                data: <?php echo json_encode($dailyStudents); ?>,
                                                borderColor: '#0a2d63',
                                                backgroundColor: '#0a2d6320',
                                                borderWidth: 3,
                                                fill: true,
                                                tension: 0.4,
                                                pointRadius: 3,
                                                pointBackgroundColor: '#0a2d63'
                                            }]
                                        },
                                        options: {
                                            responsive: true,
                                            maintainAspectRatio: false,
                                            plugins: { legend: { display: true, position: 'top', labels: { boxWidth: 12 } } },
                                            scales: {
                                                y: { beginAtZero: true, grid: { color: '#f1f5f9' } },
                                                x: { grid: { display: false } }
                                            }
                                        }
                                    });
                                }

                                // Donut Chart (Payment Methods Breakdown)
                                const donutCtx = document.getElementById('cashierDonutChart');
                                if (donutCtx) {
                                    new Chart(donutCtx.getContext('2d'), {
                                        type: 'doughnut',
                                        data: {
                                            labels: ['Cash', 'Downpayment', 'Online', 'Bank'],
                                            datasets: [{
                                                data: [
                                                    <?php echo $cashierPaidCash; ?>,
                                                    <?php echo $cashierPaidDownpayment; ?>,
                                                    <?php echo $cashierPaidOnline; ?>,
                                                    <?php echo $cashierPaidBank; ?>
                                                ],
                                                backgroundColor: ['#22c55e', '#3b82f6', '#eab308', '#ef4444'],
                                                hoverOffset: 10,
                                                borderWidth: 0
                                            }]
                                        },
                                        options: {
                                            responsive: true,
                                            maintainAspectRatio: false,
                                            cutout: '70%',
                                            plugins: {
                                                legend: {
                                                    position: 'bottom',
                                                    labels: { boxWidth: 12, padding: 20, font: { size: 11, weight: 'bold' } }
                                                }
                                            }
                                        }
                                    });
                                }

                                // Load Unpaid Students
                                const tbody = document.getElementById('unpaidStudentsTableBody');
                                if (tbody) {
                                    fetch('php/get_unpaid_students.php')
                                        .then(res => res.json())
                                        .then(data => {
                                            if (data.success && data.students && data.students.length > 0) {
                                                let html = '';
                                                data.students.sort((a,b) => b.balance - a.balance); // Sort by highest balance
                                                data.students.forEach(st => {
                                                    html += `
                                                        <tr class="border-b border-gray-100 hover:bg-gray-50">
                                                            <td class="p-3 font-medium">${st.full_name}</td>
                                                            <td class="p-3 text-gray-600">${st.grade_level} ${st.section ? '- ' + st.section : ''}</td>
                                                            <td class="p-3 text-gray-600 font-medium">₱${parseFloat(st.total_due).toLocaleString(undefined, {minimumFractionDigits: 2})}</td>
                                                            <td class="p-3 text-green-600 font-medium">₱${parseFloat(st.total_paid).toLocaleString(undefined, {minimumFractionDigits: 2})}</td>
                                                            <td class="p-3 text-right text-red-600 font-bold">₱${parseFloat(st.balance).toLocaleString(undefined, {minimumFractionDigits: 2})}</td>
                                                        </tr>
                                                    `;
                                                });
                                                tbody.innerHTML = html;
                                            } else {
                                                tbody.innerHTML = '<tr><td colspan="5" class="p-8 text-center text-gray-500">No unpaid students found.</td></tr>';
                                            }
                                        })
                                        .catch(err => {
                                            console.error('Error fetching unpaid students:', err);
                                            tbody.innerHTML = '<tr><td colspan="5" class="p-8 text-center text-red-500">Failed to load data.</td></tr>';
                                        });
                                }
                                <?php endif; ?>
                            });
                        </script>
                        <?php endif; ?>

                                <div class="dashboard-card bg-white shadow-lg border border-gray-200 hidden users-card" id="usersCard">
                            <div class="card-content p-8 space-y-6 w-full">
                                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                                    <div>
                                        <h3 class="text-2xl font-semibold text-[#0a2d63] mb-2">User Management</h3>
                                        <p class="text-gray-600">View and manage all system users</p>
                                    </div>
                                </div>
                                <div class="flex flex-col lg:flex-row gap-4">
                                    <div id="dirFilterRoleSection" class="filter-section bg-white p-4 rounded-lg border border-gray-200 flex-1 hidden">
                                        <h4 class="text-sm font-semibold text-[#0a2d63] mb-2">Filter by role</h4>
                                        <div class="checkbox-group flex flex-wrap gap-3">
                                            <label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer"><input type="checkbox" id="dirFilterStudent" class="w-4 h-4" value="student" onchange="applyUserDirectoryFilters()"> Student</label>
                                            <label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer"><input type="checkbox" id="dirFilterTeacher" class="w-4 h-4" value="teacher" onchange="applyUserDirectoryFilters()"> Teacher</label>
                                            <label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer"><input type="checkbox" id="dirFilterCashier" class="w-4 h-4" value="cashier" onchange="applyUserDirectoryFilters()"> Cashier</label>
                                            <label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer"><input type="checkbox" id="dirFilterRegistrar" class="w-4 h-4" value="registrar" onchange="applyUserDirectoryFilters()"> Registrar</label>
                                            <?php if ($userRole === 'admin'): ?>
                                            <label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer"><input type="checkbox" id="dirFilterAdmin" class="w-4 h-4" value="admin" onchange="applyUserDirectoryFilters()"> Admin</label>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div id="dirFilterGradeSection" class="filter-section bg-white p-4 rounded-lg border border-gray-200 flex-1 hidden">
                                        <h4 class="text-sm font-semibold text-[#0a2d63] mb-2">Filter students</h4>
                                        <div class="checkbox-group flex flex-wrap gap-3">
                                            <label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer"><input type="checkbox" class="dirFilterGradeCheckbox w-4 h-4" value="Grade 7" onchange="updateUserDirectoryFilterSections(); applyUserDirectoryFilters()"> Grade 7</label>
                                            <label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer"><input type="checkbox" class="dirFilterGradeCheckbox w-4 h-4" value="Grade 8" onchange="updateUserDirectoryFilterSections(); applyUserDirectoryFilters()"> Grade 8</label>
                                            <label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer"><input type="checkbox" class="dirFilterGradeCheckbox w-4 h-4" value="Grade 9" onchange="updateUserDirectoryFilterSections(); applyUserDirectoryFilters()"> Grade 9</label>
                                            <label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer"><input type="checkbox" class="dirFilterGradeCheckbox w-4 h-4" value="Grade 10" onchange="updateUserDirectoryFilterSections(); applyUserDirectoryFilters()"> Grade 10</label>
                                            <label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer"><input type="checkbox" class="dirFilterGradeCheckbox w-4 h-4" value="Grade 11" onchange="updateUserDirectoryFilterSections(); applyUserDirectoryFilters()"> Grade 11</label>
                                            <label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer"><input type="checkbox" class="dirFilterGradeCheckbox w-4 h-4" value="Grade 12" onchange="updateUserDirectoryFilterSections(); applyUserDirectoryFilters()"> Grade 12</label>
                                        </div>
                                        <div id="dirFilterSectionContainer" class="hidden mt-4">
                                            <h5 class="text-xs font-semibold text-gray-700 mb-2">Section</h5>
                                            <div id="dirFilterSectionCheckboxes" class="checkbox-group flex flex-wrap gap-3"></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="mt-4">
                                    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                                        <div class="flex flex-col sm:flex-row gap-2 sm:items-center">
                                            <span class="text-sm font-semibold text-[#0a2d63] mb-1 sm:mb-0">Sort:</span>
                                            <div class="grid grid-cols-2 sm:flex sm:flex-wrap gap-2 items-center">
                                                <span class="dir-sort-option px-3 py-1 border border-gray-300 rounded-full cursor-pointer text-sm hover:bg-gray-200 transition active text-center flex items-center justify-center whitespace-nowrap" onclick="setUserDirectorySort('name')" id="dir-sort-name">Name</span>
                                                <span class="dir-sort-option px-3 py-1 border border-gray-300 rounded-full cursor-pointer text-sm hover:bg-gray-200 transition text-center flex items-center justify-center whitespace-nowrap" onclick="toggleRoleFilter(); setUserDirectorySort('role')" id="dir-sort-role">Role</span>
                                                <span class="dir-sort-option px-3 py-1 border border-gray-300 rounded-full cursor-pointer text-sm hover:bg-gray-200 transition text-center flex items-center justify-center whitespace-nowrap" onclick="toggleGradeFilter(); setUserDirectorySort('grade')" id="dir-sort-grade">Grade</span>
                                                <span class="dir-sort-option px-3 py-1 border border-gray-300 rounded-full cursor-pointer text-sm hover:bg-gray-200 transition text-center flex items-center justify-center whitespace-nowrap" onclick="setUserDirectorySort('date')" id="dir-sort-date">Date joined</span>
                                            </div>
                                        </div>
                                        <div class="flex-1 w-full md:max-w-md px-0 md:px-4">
                                            <input type="text" id="dirSearchInput" placeholder="Search name, username, or email..." class="w-full p-2 border border-gray-300 rounded focus:ring-2 focus:ring-[#0a2d63] outline-none" onkeyup="applyUserDirectoryFilters()">
                                        </div>
                                        <div class="flex flex-wrap items-center gap-2">
                                            <button onclick="openAddUserModal()" class="bg-green-600 text-white px-4 py-2 rounded font-medium hover:bg-green-700 transition text-sm whitespace-nowrap">Add User</button>
                                            <span class="text-sm font-semibold text-[#0a2d63]">Show:</span>
                                            <select id="dirPageSizeSelect" class="w-full sm:w-auto p-2 border border-gray-300 rounded focus:ring-2 focus:ring-[#0a2d63] outline-none" onchange="setUserDirectoryPageSize(this.value)">
                                                <option value="5">5</option>
                                                <option value="10" selected>10</option>
                                                <option value="15">15</option>
                                                <option value="20">20</option>
                                                <option value="30">30</option>
                                                <option value="50">50</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div id="userDirectoryList" class="border border-gray-200 rounded min-h-[200px]">
                                    <div class="text-center p-10 text-gray-500">Open this tab to load users.</div>
                                </div>
                                <div class="mt-4 flex justify-end">
                                    <button class="bg-[#0a2d63] text-white px-5 py-2 rounded font-medium hover:bg-[#08306b] transition w-full sm:w-auto" onclick="openBatchPromoteModal()">Promote by grade and section</button>
                                </div>
                            </div>
                        </div>

                        <!-- ========== SECTIONS & SUBJECTS MANAGEMENT ========== -->
                        <div class="dashboard-card bg-white shadow-lg border border-gray-200 hidden" id="sectionsSubjectsCard">
                            <div class="card-content p-8 space-y-6 w-full">
                                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                                    <div>
                                        <h3 class="text-2xl font-semibold text-[#0a2d63] mb-2">Sections & Subjects</h3>
                                        <p class="text-gray-600">Add, edit, and remove sections and subjects</p>
                                    </div>
                                    <div class="flex gap-2">
                                        <button onclick="openAddSectionModal()" class="bg-green-600 text-white px-4 py-2 rounded font-medium hover:bg-green-700 transition text-sm">Add Section</button>
                                        <button onclick="openAddSubjectModal()" class="bg-green-600 text-white px-4 py-2 rounded font-medium hover:bg-green-700 transition text-sm">Add Subject</button>
                                    </div>
                                </div>

                                <!-- Filters -->
                                <div class="flex flex-col sm:flex-row gap-4">
                                    <div class="flex-1">
                                        <label class="block mb-1 font-medium text-sm text-[#0a2d63]">Filter by Grade</label>
                                        <select id="adminSubjectGradeFilter" class="w-full p-2 border border-gray-300 rounded focus:ring-2 focus:ring-[#0a2d63] outline-none" onchange="loadAdminSubjects()">
                                            <option value="">All Grades</option>
                                            <option value="Grade 7">Grade 7</option>
                                            <option value="Grade 8">Grade 8</option>
                                            <option value="Grade 9">Grade 9</option>
                                            <option value="Grade 10">Grade 10</option>
                                            <option value="Grade 11">Grade 11</option>
                                            <option value="Grade 12">Grade 12</option>
                                        </select>
                                    </div>
                                    <div class="flex-1">
                                        <label class="block mb-1 font-medium text-sm text-[#0a2d63]">Filter by Section</label>
                                        <select id="adminSubjectSectionFilter" class="w-full p-2 border border-gray-300 rounded focus:ring-2 focus:ring-[#0a2d63] outline-none" onchange="loadAdminSubjects()">
                                            <option value="">All Sections</option>
                                        </select>
                                    </div>
                                </div>

                                <!-- Subjects Table -->
                                <div id="adminSubjectsList" class="border border-gray-200 rounded min-h-[200px]">
                                    <div class="text-center p-10 text-gray-500">Click "Sections & Subjects" to load data.</div>
                                </div>
                            </div>
                        </div>

                        <!-- ========== GRADE SUBMISSIONS CARD ========== -->
                        <div class="dashboard-card bg-white shadow-lg border border-gray-200 hidden" id="gradeSubmissionsCard">
                            <div class="card-content p-8 space-y-6 w-full">
                                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                                    <div>
                                        <h3 class="text-2xl font-semibold text-[#0a2d63] mb-1">Grade Submissions</h3>
                                        <p class="text-gray-600 text-sm">Review grades submitted by teachers to the registrar</p>
                                    </div>
                                    <button class="bg-green-600 text-white px-4 py-2 rounded-lg font-semibold hover:bg-green-700 transition flex items-center gap-2" onclick="openExportStudentModal()">
                                        <i class="fas fa-file-excel"></i> Export to Excel
                                    </button>
                                </div>

                                <!-- Export Student Grades Modal -->
                                <div id="exportStudentModal" class="fixed inset-0 bg-black bg-opacity-50 z-[3000] hidden items-center justify-center p-4">
                                    <div class="bg-white rounded-xl shadow-2xl w-full max-w-2xl overflow-hidden animate-slideIn">
                                        <div class="p-6 border-b border-gray-100 flex justify-between items-center bg-[#0a2d63] text-white">
                                            <h3 class="text-xl font-bold">Export Student Grades to Excel</h3>
                                            <button onclick="closeExportStudentModal()" class="text-white hover:text-gray-200 text-2xl">&times;</button>
                                        </div>
                                        <div class="p-6 space-y-4">
                                            <div class="relative">
                                                <input type="text" id="exportStudentSearch" placeholder="Search student name or LRN..." class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#0a2d63] outline-none" onkeyup="searchExportStudents(this.value)">
                                                <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
                                            </div>
                                            <div id="exportStudentResults" class="max-h-[300px] overflow-y-auto border border-gray-100 rounded-lg">
                                                <div class="p-4 text-center text-gray-500">Type above to search students...</div>
                                            </div>
                                        </div>
                                        <div class="p-6 bg-gray-50 flex justify-end">
                                            <button onclick="closeExportStudentModal()" class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition mr-2">Cancel</button>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex flex-col sm:flex-row gap-4">
                                    <div class="flex-1">
                                        <label class="block mb-1 font-medium text-sm text-[#0a2d63]">Filter by Semester</label>
                                        <select id="gsFilterSemester" class="w-full p-2 border border-gray-300 rounded focus:ring-2 focus:ring-[#0a2d63] outline-none" onchange="loadGradeSubmissions()">
                                            <option value="">All Semesters</option>
                                            <option value="1">1st Semester</option>
                                            <option value="2">2nd Semester</option>
                                            <option value="3">3rd Semester</option>
                                        </select>
                                    </div>
                                    <div class="flex-1">
                                        <label class="block mb-1 font-medium text-sm text-[#0a2d63]">Filter by Grade Level</label>
                                        <select id="gsFilterGrade" class="w-full p-2 border border-gray-300 rounded focus:ring-2 focus:ring-[#0a2d63] outline-none" onchange="loadGradeSubmissions()">
                                            <option value="">All Grades</option>
                                            <option value="Grade 7">Grade 7</option>
                                            <option value="Grade 8">Grade 8</option>
                                            <option value="Grade 9">Grade 9</option>
                                            <option value="Grade 10">Grade 10</option>
                                            <option value="Grade 11">Grade 11</option>
                                            <option value="Grade 12">Grade 12</option>
                                        </select>
                                    </div>
                                </div>
                                <div id="gradeSubmissionsTableContainer" class="border border-gray-200 rounded min-h-[200px]">
                                    <div class="text-center p-10 text-gray-500">Click "Grade Submissions" in the sidebar to load data.</div>
                                </div>
                            </div>
                        </div>

                        <!-- ========== BOOK MANAGER CARD ========== -->
                        <div class="dashboard-card bg-white shadow-lg border border-gray-200 hidden" id="bookManagerCard">
                            <div class="card-content p-8 space-y-6 w-full">
                                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                                    <div>
                                        <h3 class="text-2xl font-semibold text-[#0a2d63] mb-1">Book Manager</h3>
                                        <p class="text-gray-600 text-sm">Manage books and their grade assignments.</p>
                                    </div>
                                    <button onclick="openAddBookModal()" class="bg-green-600 text-white px-4 py-2 rounded font-medium hover:bg-green-700 transition text-sm">Add Book</button>
                                </div>
                                <div id="bookManagerTableContainer" class="border border-gray-200 rounded min-h-[200px]">
                                    <div class="text-center p-10 text-gray-500">Loading books...</div>
                                </div>
                            </div>
                        </div>

                        <!-- Add/Edit Book Modal -->
                        <div id="addBookModal" class="modal-overlay fixed inset-0 bg-black bg-opacity-50 items-center justify-center z-[1000] p-4" style="display: none;">
                            <div class="modal-container bg-white rounded-lg w-full max-w-lg shadow-xl flex flex-col max-h-[90vh]">
                                <div class="modal-header p-4 border-b border-gray-200 bg-gray-50 rounded-t-lg flex justify-between items-center">
                                    <h3 class="text-lg font-semibold text-[#0a2d63]" id="bookModalTitle">Add Book</h3>
                                    <button class="modal-close text-2xl text-gray-600 hover:text-gray-800 w-8 h-8 flex items-center justify-center rounded hover:bg-gray-200 transition" onclick="closeAddBookModal()">×</button>
                                </div>
                                <div class="modal-body p-5 overflow-y-auto">
                                    <form id="bookForm" onsubmit="event.preventDefault(); submitBookForm();" class="space-y-4">
                                        <input type="hidden" id="bookEditId" value="0">
                                        <div>
                                            <label class="block mb-1 font-medium text-gray-700">Book Title <span class="text-red-500">*</span></label>
                                            <input type="text" id="bookTitleInput" class="w-full p-2 border border-gray-300 rounded focus:ring-2 focus:ring-[#0a2d63] outline-none" required placeholder="e.g. Science 7">
                                        </div>
                                        <div>
                                            <label class="block mb-1 font-medium text-gray-700">Price <span class="text-red-500">*</span></label>
                                            <input type="number" id="bookPriceInput" step="0.01" min="0" class="w-full p-2 border border-gray-300 rounded focus:ring-2 focus:ring-[#0a2d63] outline-none" required placeholder="0.00">
                                        </div>
                                        <div>
                                            <label class="block mb-1 font-medium text-gray-700">Assigned Grades</label>
                                            <p class="text-xs text-gray-500 mb-2">Select the grades that use this book.</p>
                                            <div class="grid grid-cols-2 gap-2" id="bookGradeCheckboxes">
                                                <label class="flex items-center space-x-2"><input type="checkbox" value="Grade 7" class="book-grade-cb"><span>Grade 7</span></label>
                                                <label class="flex items-center space-x-2"><input type="checkbox" value="Grade 8" class="book-grade-cb"><span>Grade 8</span></label>
                                                <label class="flex items-center space-x-2"><input type="checkbox" value="Grade 9" class="book-grade-cb"><span>Grade 9</span></label>
                                                <label class="flex items-center space-x-2"><input type="checkbox" value="Grade 10" class="book-grade-cb"><span>Grade 10</span></label>
                                                <label class="flex items-center space-x-2"><input type="checkbox" value="Grade 11" class="book-grade-cb"><span>Grade 11</span></label>
                                                <label class="flex items-center space-x-2"><input type="checkbox" value="Grade 12" class="book-grade-cb"><span>Grade 12</span></label>
                                            </div>
                                        </div>
                                        <div id="bookFormError" class="text-red-600 text-sm"></div>
                                        <button type="submit" class="w-full bg-[#0a2d63] text-white px-4 py-2 rounded font-medium hover:bg-[#08306b] transition">Save Book</button>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- Add Section Modal -->
                        <div id="addSectionModal" class="modal-overlay fixed inset-0 bg-black bg-opacity-50 items-center justify-center z-[1000] p-4" style="display: none;">
                            <div class="modal-container bg-white rounded-lg w-full max-w-sm shadow-xl flex flex-col max-h-[90vh]">
                                <div class="modal-header p-4 border-b border-gray-200 bg-gray-50 rounded-t-lg flex justify-between items-center">
                                    <h3 class="text-lg font-semibold text-[#0a2d63]">Add Section</h3>
                                    <button class="modal-close text-2xl text-gray-600 hover:text-gray-800 w-8 h-8 flex items-center justify-center rounded hover:bg-gray-200 transition" onclick="closeAddSectionModal()">×</button>
                                </div>
                                <div class="modal-body p-5 overflow-y-auto">
                                    <form id="sectionForm" onsubmit="event.preventDefault(); submitSectionForm();" class="space-y-4">
                                        <div>
                                            <label class="block mb-1 font-medium text-gray-700">Section Name <span class="text-red-500">*</span></label>
                                            <input type="text" id="sectionNameInput" class="w-full p-2 border border-gray-300 rounded focus:ring-2 focus:ring-[#0a2d63] outline-none" required placeholder="e.g. Love">
                                        </div>
                                        <div>
                                            <label class="block mb-1 font-medium text-gray-700">Grade Level <span class="text-red-500">*</span></label>
                                            <select id="sectionGradeInput" class="w-full p-2 border border-gray-300 rounded focus:ring-2 focus:ring-[#0a2d63] outline-none" required>
                                                <option value="">Select Grade</option>
                                                <option value="Grade 7">Grade 7</option>
                                                <option value="Grade 8">Grade 8</option>
                                                <option value="Grade 9">Grade 9</option>
                                                <option value="Grade 10">Grade 10</option>
                                                <option value="Grade 11">Grade 11</option>
                                                <option value="Grade 12">Grade 12</option>
                                            </select>
                                        </div>
                                        <div id="sectionFormError" class="text-red-600 text-sm"></div>
                                        <button type="submit" class="w-full bg-[#0a2d63] text-white px-4 py-2 rounded font-medium hover:bg-[#08306b] transition">Save Section</button>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- Add/Edit Subject Modal -->
                        <div id="addSubjectModal" class="modal-overlay fixed inset-0 bg-black bg-opacity-50 items-center justify-center z-[1000] p-4" style="display: none;">
                            <div class="modal-container bg-white rounded-lg w-full max-w-lg shadow-xl flex flex-col max-h-[90vh]">
                                <div class="modal-header p-4 border-b border-gray-200 bg-gray-50 rounded-t-lg flex justify-between items-center">
                                    <h3 class="text-lg font-semibold text-[#0a2d63]" id="subjectModalTitle">Add Subject</h3>
                                    <button class="modal-close text-2xl text-gray-600 hover:text-gray-800 w-8 h-8 flex items-center justify-center rounded hover:bg-gray-200 transition" onclick="closeAddSubjectModal()">×</button>
                                </div>
                                <div class="modal-body p-5 overflow-y-auto">
                                    <form id="subjectForm" onsubmit="event.preventDefault(); submitSubjectForm();" class="space-y-4">
                                        <input type="hidden" id="subjectEditId" value="0">
                                        <div>
                                            <label class="block mb-1 font-medium text-gray-700">Subject Name <span class="text-red-500">*</span></label>
                                            <input type="text" id="subjectNameInput" class="w-full p-2 border border-gray-300 rounded focus:ring-2 focus:ring-[#0a2d63] outline-none" required placeholder="e.g. Mathematics">
                                        </div>
                                        <div>
                                            <label class="block mb-1 font-medium text-gray-700">Subject Code</label>
                                            <input type="text" id="subjectCodeInput" class="w-full p-2 border border-gray-300 rounded focus:ring-2 focus:ring-[#0a2d63] outline-none" placeholder="e.g. MATH101">
                                        </div>
                                        <div class="grid grid-cols-2 gap-4">
                                            <div>
                                                <label class="block mb-1 font-medium text-gray-700">Grade Level <span class="text-red-500">*</span></label>
                                                <select id="subjectGradeInput" class="w-full p-2 border border-gray-300 rounded focus:ring-2 focus:ring-[#0a2d63] outline-none" required>
                                                    <option value="">Select Grade</option>
                                                    <option value="Grade 7">Grade 7</option>
                                                    <option value="Grade 8">Grade 8</option>
                                                    <option value="Grade 9">Grade 9</option>
                                                    <option value="Grade 10">Grade 10</option>
                                                    <option value="Grade 11">Grade 11</option>
                                                    <option value="Grade 12">Grade 12</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="block mb-1 font-medium text-gray-700">Section</label>
                                                <input type="text" id="subjectSectionInput" class="w-full p-2 border border-gray-300 rounded focus:ring-2 focus:ring-[#0a2d63] outline-none" placeholder="e.g. Love">
                                            </div>
                                        </div>
                                        <div class="grid grid-cols-3 gap-4">
                                            <div>
                                                <label class="block mb-1 font-medium text-gray-700">Day</label>
                                                <select id="subjectDayInput" class="w-full p-2 border border-gray-300 rounded focus:ring-2 focus:ring-[#0a2d63] outline-none">
                                                    <option value="">Select</option>
                                                    <option value="Monday">Monday</option>
                                                    <option value="Tuesday">Tuesday</option>
                                                    <option value="Wednesday">Wednesday</option>
                                                    <option value="Thursday">Thursday</option>
                                                    <option value="Friday">Friday</option>
                                                    <option value="Saturday">Saturday</option>
                                                    <option value="Sunday">Sunday</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="block mb-1 font-medium text-gray-700">Start Time</label>
                                                <input type="time" id="subjectStartTimeInput" class="w-full p-2 border border-gray-300 rounded focus:ring-2 focus:ring-[#0a2d63] outline-none">
                                            </div>
                                            <div>
                                                <label class="block mb-1 font-medium text-gray-700">End Time</label>
                                                <input type="time" id="subjectEndTimeInput" class="w-full p-2 border border-gray-300 rounded focus:ring-2 focus:ring-[#0a2d63] outline-none">
                                            </div>
                                        </div>
                                        <div>
                                            <label class="block mb-1 font-medium text-gray-700">Semester</label>
                                            <input type="text" id="subjectSemesterInput" class="w-full p-2 border border-gray-300 rounded focus:ring-2 focus:ring-[#0a2d63] outline-none" placeholder="e.g. 1st Semester">
                                        </div>
                                        <div id="subjectFormError" class="text-red-600 text-sm"></div>
                                        <button type="submit" class="w-full bg-[#0a2d63] text-white px-4 py-2 rounded font-medium hover:bg-[#08306b] transition">Save Subject</button>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <div class="dashboard-card bg-white shadow-lg border border-gray-200 hidden payables-management-card" id="payablesManagementCard">
                            <div class="card-content p-8 space-y-6 w-full">
                                <div>
                                    <h3 class="text-2xl font-semibold text-[#0a2d63] mb-2">Payment Processing</h3>
                                    <p class="text-gray-600">Calculate and manage student payables</p>
                                </div>

                                <div class="p-5 bg-gray-50 rounded space-y-4">
                                    <h4 class="text-lg font-semibold text-[#0a2d63]">Payables Calculator</h4>
                                    <form id="payablesForm" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div>
                                            <label class="block mb-1 font-medium text-gray-700">Select Student *</label>
                                            <div class="flex gap-2">
                                                <input type="text" id="selectedStudentName" readonly placeholder="No student selected" class="w-full p-2 border border-gray-300 rounded bg-gray-100" onclick="openStudentSelectModal()">
                                                <button type="button" onclick="openStudentSelectModal()" class="bg-[#0a2d63] text-white px-4 py-2 rounded font-medium hover:bg-[#08306b] transition">Browse</button>
                                            </div>
                                            <input type="hidden" id="studentSelect" value="">
                                        </div>
                                        <div>
                                            <label class="block mb-1 font-medium text-gray-700">Total Tuition Fee *</label>
                                            <input type="number" id="tuitionFee" placeholder="0.00" step="0.01" min="0" class="w-full p-2 border border-gray-300 rounded" required>
                                        </div>
                                        <div>
                                            <label class="block mb-1 font-medium text-gray-700">Down Payment *</label>
                                            <input type="number" id="downPayment" placeholder="0.00" step="0.01" min="0" class="w-full p-2 border border-gray-300 rounded" required>
                                        </div>
                                        <div>
                                            <label class="block mb-1 font-medium text-gray-700">Discounts/Grants</label>
                                            <input type="number" id="discounts" placeholder="0.00" step="0.01" min="0" class="w-full p-2 border border-gray-300 rounded">
                                        </div>
                                        <div>
                                            <label class="block mb-1 font-medium text-gray-700">Books</label>
                                            <input type="number" id="booksFee" placeholder="0.00" step="0.01" min="0" class="w-full p-2 border border-gray-300 rounded">
                                        </div>
                                        <div>
                                            <label class="block mb-1 font-medium text-gray-700">Number of Monthly Payments</label>
                                            <input type="number" id="monthlyPayments" placeholder="4" min="1" max="12" value="4" class="w-full p-2 border border-gray-300 rounded">
                                        </div>
                                        <div class="md:col-span-2 text-center">
                                            <button type="button" onclick="calculatePayables()" class="bg-[#0a2d63] text-white px-5 py-2 rounded font-medium hover:bg-[#08306b] transition w-full sm:w-auto">Calculate Remaining Balance</button>
                                        </div>
                                    </form>
                                </div>

                                <div id="calculationResult" class="hidden p-5 bg-gray-50 border border-gray-200 rounded space-y-4">
                                    <h4 class="text-lg font-semibold text-[#0a2d63]">Calculation Result</h4>
                                    <div id="resultContent"></div>
                                    <div class="flex flex-col sm:flex-row gap-4 justify-center">
                                        <button onclick="generateAssessmentPDF()" id="generatePdfBtnCalc" class="hidden bg-[#0a2d63] text-white px-5 py-2 rounded font-medium hover:bg-[#08306b] transition w-full sm:w-auto">
                                            Generate Assessment PDF
                                        </button>
                                        <button onclick="addPayable()" id="addPayableBtn" class="hidden bg-green-600 text-white px-5 py-2 rounded font-medium hover:bg-green-700 transition w-full sm:w-auto">
                                            Add to Student Payables
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="dashboard-card bg-white shadow-lg border border-gray-200 hidden payments-card" id="paymentsCard">
                            <div class="card-content p-8 space-y-6 w-full">
                                <div>
                                    <h3 class="text-2xl font-semibold text-[#0a2d63] mb-2">Payment Processing</h3>
                                    <p class="text-gray-600">Process student payments and update payable status</p>
                                </div>

                                <div class="p-6 bg-gray-50 rounded space-y-4">
                                    <h4 class="text-lg font-semibold text-[#0a2d63]">Process Payment</h4>
                                    <form id="paymentForm" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div class="form-group md:col-span-2">
                                            <label for="paymentTargetType" class="block mb-2 font-medium text-gray-700">Payment For *</label>
                                            <select id="paymentTargetType" class="w-full p-2 border border-gray-300 rounded" onchange="togglePaymentTarget()">
                                                <option value="student">Student</option>
                                                <option value="enrollee">Pending Enrollee Downpayment</option>
                                            </select>
                                        </div>
                                        <div class="form-group" id="paymentStudentGroup">
                                            <label for="paymentStudentSearch" class="block mb-2 font-medium text-gray-700">Select Student *</label>
                                            <div class="grid grid-cols-1 sm:grid-cols-[1fr_auto] gap-2">
                                                <input type="text" id="paymentStudentSearch" list="paymentStudentDatalist" placeholder="Type student name..." class="w-full p-2 border border-gray-300 rounded" autocomplete="off" oninput="handlePaymentStudentTyping()" onblur="autoCorrectPaymentStudent()">
                                                <button type="button" onclick="openPaymentStudentBrowseModal()" class="bg-[#0a2d63] text-white px-4 py-2 rounded font-medium hover:bg-[#08306b] transition">
                                                    Browse
                                                </button>
                                            </div>
                                            <datalist id="paymentStudentDatalist"></datalist>
                                            <input type="hidden" id="paymentStudentId" value="">
                                        </div>
                                        <div class="form-group" id="paymentEnrollmentGroup" style="display:none;">
                                            <label for="paymentEnrolleeSearch" class="block mb-2 font-medium text-gray-700">Select Enrollee *</label>
                                            <div class="grid grid-cols-1 sm:grid-cols-[1fr_auto] gap-2">
                                                <input type="text" id="paymentEnrolleeSearch" placeholder="Type enrollee name..." class="w-full p-2 border border-gray-300 rounded" autocomplete="off" oninput="handlePaymentEnrolleeTyping()" onblur="autoCorrectPaymentEnrollee()">
                                                <button type="button" onclick="openPaymentEnrolleeBrowseModal()" class="bg-[#0a2d63] text-white px-4 py-2 rounded font-medium hover:bg-[#08306b] transition">
                                                    Browse
                                                </button>
                                            </div>
                                            <input type="hidden" id="paymentEnrollmentId" value="">
                                        </div>
                                        <div class="form-group">
                                            <label for="paymentMode" class="block mb-2 font-medium text-gray-700">Mode of Payment *</label>
                                            <select id="paymentMode" class="w-full p-2 border border-gray-300 rounded" onchange="togglePaymentMode()">
                                                <option value="cash">Cash</option>
                                                <option value="downpayment" selected>Downpayment</option>
                                                <option value="online">Online Payment</option>
                                                <option value="bank">Bank Payment</option>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label for="paymentAmount" class="block mb-2 font-medium text-gray-700">Payment Amount *</label>
                                            <input type="number" id="paymentAmount" placeholder="0.00" step="0.01" min="0" class="w-full p-2 border border-gray-300 rounded" required>
                                        </div>
                                        <div class="form-group" id="monthlyPlansGroup">
                                            <label for="paymentMonthsPlan" class="block mb-2 font-medium text-gray-700">Number of Monthly Payments</label>
                                            <input type="number" id="paymentMonthsPlan" placeholder="e.g. 4" min="1" max="8" value="4" class="w-full p-2 border border-gray-300 rounded">
                                        </div>
                                        <div class="form-group">
                                            <label for="paymentDiscounts" class="block mb-2 font-medium text-gray-700">Apply Grants/Discounts</label>
                                            <select id="paymentDiscounts" class="w-full p-2 border border-gray-300 rounded">
                                                <option value="">-- No Discount --</option>
                                            </select>
                                        </div>
                                        <div class="form-group md:col-span-2">
                                            <label for="paymentDate" class="block mb-2 font-medium text-gray-700">Payment Date</label>
                                            <input type="date" id="paymentDate" value="<?php echo date('Y-m-d'); ?>" class="w-full p-2 border border-gray-300 rounded">
                                        </div>
                                        <div class="form-actions md:col-span-2 text-center flex flex-col sm:flex-row gap-4 justify-center">
                                            <button type="button" id="loadPayablesBtn" onclick="loadStudentPayables()" class="bg-[#0a2d63] text-white px-5 py-2 rounded font-medium hover:bg-[#08306b] transition w-full sm:w-auto">
                                                Calculate Remaining Balance
                                            </button>
                                            <button type="button" onclick="processPayment()" class="bg-green-600 text-white px-5 py-2 rounded font-medium hover:bg-green-700 transition w-full sm:w-auto">
                                                Process Payment
                                            </button>
                                            <button type="button" id="generatePdfBtnProc" onclick="generateAssessmentPDF()" class="bg-[#0a2d63] text-white px-5 py-2 rounded font-medium hover:bg-[#08306b] transition w-full sm:w-auto">
                                                Generate PDF
                                            </button>
                                        </div>
                                    </form>
                                </div>

                                <div id="studentPayables" class="hidden p-6 bg-gray-50 border border-gray-200 rounded space-y-4">
                                    <h4 class="text-lg font-semibold text-[#0a2d63]">Student Payables</h4>
                                    <div id="payablesTotals"></div>
                                    <div id="payablesList" class="overflow-x-auto">Loading payables...</div>
                                </div>

                                <div id="paymentResult" class="hidden p-6 bg-green-100 border border-green-300 rounded text-green-700"></div>
                            </div>
                        </div>

                        <div class="dashboard-card bg-white shadow-lg border border-gray-200 hidden" id="tuitionFeeManagerCard">
                            <div class="card-content p-8 space-y-6 w-full">
                                <div>
                                    <h3 class="text-2xl font-semibold text-[#0a2d63] mb-2">Tuition Fee Manager</h3>
                                    <p class="text-gray-600">Edit tuition fee components for Grades 7–12. Changes update unpaid payables for existing students in that grade.</p>
                                </div>

                                <div class="p-5 bg-gray-50 border border-gray-200 rounded space-y-4">
                                    <div class="text-sm text-gray-600">Click a grade row to edit and save.</div>
                                    <div id="tuitionFeeManagerStatus" class="text-sm"></div>
                                    <div id="tuitionFeeManagerTable" class="overflow-x-auto"></div>
                                </div>
                            </div>
                        </div>

                        <div class="dashboard-card bg-white shadow-lg border border-gray-200 hidden" id="grantsDiscountsCard">
                            <div class="card-content p-8 space-y-6 w-full">
                                <div>
                                    <h3 class="text-2xl font-semibold text-[#0a2d63] mb-2">Grants/Discount Manager</h3>
                                    <p class="text-gray-600">Edit the amounts for standard grants and discounts.</p>
                                </div>

                                <div class="p-5 bg-gray-50 border border-gray-200 rounded space-y-4">
                                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                                        <div class="text-sm text-gray-600">Update amounts and click Save.</div>
                                        <button type="button" onclick="addNewDiscount()" class="bg-green-600 text-white px-4 py-2 rounded font-medium hover:bg-green-700 transition w-full sm:w-auto">Add Grant/Discount</button>
                                    </div>
                                    <div id="discountsManagerStatus" class="text-sm"></div>
                                    <div id="discountsManagerContainer" class="overflow-x-auto"></div>
                                    <div class="text-right mt-4">
                                        <button type="button" onclick="saveDiscountsManager()" class="bg-green-600 text-white px-5 py-2 rounded font-medium hover:bg-green-700 transition w-full sm:w-auto">Save Discounts</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="dashboard-card bg-white shadow-lg border border-gray-200 hidden" id="documentManagementCard">
                            <div class="card-content p-8 space-y-6 w-full">
                                <div>
                                    <h3 class="text-2xl font-semibold text-[#0a2d63] mb-2">Document Management</h3>
                                    <p class="text-gray-600">Review student-submitted documents and verify each required item.</p>
                                </div>
                                <div class="flex flex-wrap gap-4 items-end">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Grade</label>
                                        <select id="documentGradeFilter" class="p-2 border border-gray-300 rounded" onchange="loadDocumentSections()">
                                            <option value="">All Grades</option>
                                            <option value="Grade 7">Grade 7</option>
                                            <option value="Grade 8">Grade 8</option>
                                            <option value="Grade 9">Grade 9</option>
                                            <option value="Grade 10">Grade 10</option>
                                            <option value="Grade 11">Grade 11</option>
                                            <option value="Grade 12">Grade 12</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Section</label>
                                        <select id="documentSectionFilter" class="p-2 border border-gray-300 rounded" onchange="loadDocumentManagementStudents(1)">
                                            <option value="">All Sections</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Document Status</label>
                                        <select id="documentStatusFilter" class="p-2 border border-gray-300 rounded" onchange="loadDocumentManagementStudents(1)">
                                            <option value="">All Students</option>
                                            <option value="has_documents">Has Documents Uploaded</option>
                                            <option value="no_documents">No Documents Uploaded</option>
                                            <option value="pending">Has Pending (Unverified)</option>
                                            <option value="verified">Fully Verified</option>
                                        </select>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                                        <input type="text" id="documentSearch" class="w-full p-2 border border-gray-300 rounded" placeholder="Search by name or student ID" oninput="loadDocumentManagementStudents(1)">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Show</label>
                                        <select id="documentPageSizeSelect" class="w-full sm:w-auto p-2 border border-gray-300 rounded" onchange="setDocumentManagementPageSize(this.value)">
                                            <option value="10">10</option>
                                            <option value="20">20</option>
                                            <option value="30">30</option>
                                            <option value="50">50</option>
                                        </select>
                                    </div>
                                </div>
                                <div id="documentManagementList" class="space-y-3">
                                    <div class="text-center text-gray-500 py-8">Loading students...</div>
                                </div>
                                <div id="documentManagementPagination" class="pagination-controls hidden mt-4 p-4 bg-gray-50 rounded flex flex-col sm:flex-row justify-between items-center gap-4">
                                    <div class="pagination-info text-sm text-gray-600 text-center" id="documentManagementPaginationInfo"></div>
                                    <div class="pagination-buttons flex flex-wrap justify-center gap-1" id="documentManagementPaginationButtons"></div>
                                </div>
                            </div>
                        </div>

                        <div class="dashboard-card bg-white shadow-lg border border-gray-200 hidden profile-card" id="adminProfileCard">
                            <div class="card-content p-8 space-y-6 w-full">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <h3 class="text-2xl font-semibold text-[#0a2d63] mb-2">Profile</h3>
                                        <p class="text-gray-600">View and update your personal information.</p>
                                    </div>
                                    <button type="button" onclick="openChangePasswordModal()" class="bg-[#0a2d63] text-white px-4 py-2 rounded font-medium hover:bg-[#08306b] transition">Change Password</button>
                                </div>
                                <div class="profile-info bg-gray-50 p-8 space-y-4" id="adminProfileInfo">
                                    <div class="info-item flex flex-col sm:flex-row justify-between items-start sm:items-center py-4 border-b border-gray-200 last:border-0 gap-2">
                                        <span class="label font-semibold text-gray-800">Full Name:</span>
                                        <span class="value text-gray-600"><?php echo htmlspecialchars(trim(($user['first_name'] ?? '') . ' ' . ($user['middle_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))); ?></span>
                                    </div>
                                    <div class="info-item flex flex-col sm:flex-row justify-between items-start sm:items-center py-4 border-b border-gray-200 last:border-0 gap-2">
                                        <span class="label font-semibold text-gray-800">Username:</span>
                                        <span class="value text-gray-600"><?php echo htmlspecialchars($userName); ?></span>
                                    </div>
                                    <div class="info-item flex flex-col sm:flex-row justify-between items-start sm:items-center py-4 border-b border-gray-200 last:border-0 gap-2">
                                        <span class="label font-semibold text-gray-800">User Role:</span>
                                        <span class="value text-gray-600"><?php echo htmlspecialchars(ucfirst($userRole)); ?></span>
                                    </div>
                                    <div class="info-item flex flex-col sm:flex-row justify-between items-start sm:items-center py-4 border-b border-gray-200 last:border-0 gap-2">
                                        <span class="label font-semibold text-gray-800">Email:</span>
                                        <span class="value text-gray-600"><?php echo htmlspecialchars($user['email'] ?? 'N/A'); ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Photo Approvals Card -->
                        <div class="dashboard-card bg-white shadow-lg border border-gray-200 hidden" id="photoApprovalsCard">
                            <div class="card-content p-8 space-y-6 w-full">
                                <div>
                                    <h3 class="text-2xl font-semibold text-[#0a2d63] mb-2">Photo Approvals</h3>
                                    <p class="text-gray-600">Review pending profile picture uploads by students, teachers, and staff.</p>
                                </div>
                                <div class="overflow-x-auto">
                                    <table class="min-w-full border-collapse">
                                        <thead>
                                            <tr class="bg-gray-100 border-b border-gray-200">
                                                <th class="px-6 py-4 text-left text-sm font-semibold text-gray-700 uppercase tracking-wider">Photo</th>
                                                <th class="px-6 py-4 text-left text-sm font-semibold text-gray-700 uppercase tracking-wider">Full Name</th>
                                                <th class="px-6 py-4 text-left text-sm font-semibold text-gray-700 uppercase tracking-wider">Role</th>
                                                <th class="px-6 py-4 text-left text-sm font-semibold text-gray-700 uppercase tracking-wider">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody id="photoApprovalsTableBody">
                                            <tr>
                                                <td colspan="4" class="text-center py-6 text-gray-500">Loading pending photos...</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- Announcement Manager Card -->
                        <div class="dashboard-card bg-white shadow-lg border border-gray-200 hidden" id="adminAnnouncementsCard">
                            <div class="card-content p-8 space-y-6 w-full">
                                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                                    <div>
                                        <h3 class="text-2xl font-semibold text-[#0a2d63] mb-2">Announcement Manager</h3>
                                        <p class="text-gray-600">Manage unified announcements and event schedules for the school portal.</p>
                                    </div>
                                    <button onclick="openAddAnnouncementModal()" class="bg-[#0a2d63] hover:bg-[#08306b] text-white px-4 py-2 rounded-lg font-medium transition flex items-center gap-2">
                                        Create Announcement
                                    </button>
                                </div>
                                <div class="overflow-x-auto">
                                    <table class="min-w-full border-collapse">
                                        <thead>
                                            <tr class="bg-gray-100 border-b border-gray-200">
                                                <th class="px-6 py-4 text-left text-sm font-semibold text-gray-700 uppercase tracking-wider">Title</th>
                                                <th class="px-6 py-4 text-left text-sm font-semibold text-gray-700 uppercase tracking-wider">Content</th>
                                                <th class="px-6 py-4 text-left text-sm font-semibold text-gray-700 uppercase tracking-wider">Event Date</th>
                                                <th class="px-6 py-4 text-left text-sm font-semibold text-gray-700 uppercase tracking-wider">Location</th>
                                                <th class="px-6 py-4 text-left text-sm font-semibold text-gray-700 uppercase tracking-wider">Department</th>
                                                <th class="px-6 py-4 text-left text-sm font-semibold text-gray-700 uppercase tracking-wider">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody id="adminAnnouncementsTableBody">
                                            <tr>
                                                <td colspan="6" class="text-center py-6 text-gray-500">Loading announcements...</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                    <?php elseif ($userRole == 'student'): ?>
                        <div class="dashboard-card bg-white shadow-lg border border-gray-200 hidden active" id="homeCard">
                            <div class="card-content p-8 w-full">
                                <div class="space-y-6">
                                    <div>
                                        <h3 class="text-2xl font-semibold text-[#0a2d63]">Student Performance Overview</h3>
                                        <p class="text-gray-600">Check your current grades, academic performance, and progress reports.</p>
                                    </div>

                                    <div class="chart-container w-full" style="height: 300px; <?php echo empty($grades) ? 'display: none;' : ''; ?>">
                                        <canvas id="studentGradeChart" class="w-full h-full"></canvas>
                                    </div>

                                    <div class="grade-summary overflow-x-auto w-full">
                                        <?php if (!$isFullyPaid): ?>
                                            <div class="p-12 text-center bg-red-50 border-2 border-red-200 rounded-xl animate-pulse">
                                                <div class="text-7xl text-red-600 mb-4">
                                                    <i class="fas fa-times-circle"></i>
                                                </div>
                                                <h4 class="text-2xl font-bold text-red-800 mb-2">Grades Locked</h4>
                                                <p class="text-red-600 max-w-md mx-auto">Your academic records are currently restricted due to an outstanding balance of <strong>₱<?php echo number_format($remainingBalance, 2); ?></strong>. Please contact the registrar's office to settle your accounts and unlock your grades.</p>
                                                <button onclick="navigateTo('payables')" class="mt-6 bg-red-600 text-white px-6 py-2 rounded-lg font-semibold hover:bg-red-700 transition">View Payables</button>
                                            </div>
                                        <?php elseif (!empty($grades)): ?>
                                            <?php
                                            $groupedGrades = [];
                                            foreach ($grades as $grade) {
                                                $subjectName = $grade['subject_name'];
                                                $quarter = $grade['quarter'];
                                                if (!isset($groupedGrades[$subjectName])) {
                                                    $groupedGrades[$subjectName] = [
                                                        'quarters' => [],
                                                        'average' => 0,
                                                        'count' => 0,
                                                        'total' => 0
                                                    ];
                                                }
                                                $groupedGrades[$subjectName]['quarters'][$quarter] = $grade['grade'];
                                                $groupedGrades[$subjectName]['count']++;
                                                $groupedGrades[$subjectName]['total'] += $grade['grade'];
                                            }
                                            
                                            foreach ($groupedGrades as $subjectName => &$data) {
                                                if ($data['count'] > 0) {
                                                    $data['average'] = round($data['total'] / $data['count']);
                                                }
                                            }
                                            ?>
                                            <table class="grades-table w-full border-collapse bg-white shadow-sm rounded min-w-[600px] md:min-w-full">
                                                <thead class="bg-[#0a2d63] text-white">
                                                    <tr>
                                                        <th class="p-4 text-left font-semibold">Subject</th>
                                                        <th class="p-4 text-center font-semibold">Q1</th>
                                                        <th class="p-4 text-center font-semibold">Q2</th>
                                                        <th class="p-4 text-center font-semibold">Q3</th>
                                                        <th class="p-4 text-center font-semibold">Q4</th>
                                                        <th class="p-4 text-center font-semibold">Average</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($groupedGrades as $subjectName => $data): ?>
                                                    <tr class="border-b border-gray-200 hover:bg-gray-50">
                                                        <td class="p-4 font-semibold text-gray-800 whitespace-nowrap"><?php echo htmlspecialchars($subjectName); ?></td>
                                                        <td class="p-4 text-center text-gray-700"><?php echo isset($data['quarters'][1]) ? $data['quarters'][1] : '-'; ?></td>
                                                        <td class="p-4 text-center text-gray-700"><?php echo isset($data['quarters'][2]) ? $data['quarters'][2] : '-'; ?></td>
                                                        <td class="p-4 text-center text-gray-700"><?php echo isset($data['quarters'][3]) ? $data['quarters'][3] : '-'; ?></td>
                                                        <td class="p-4 text-center text-gray-700"><?php echo isset($data['quarters'][4]) ? $data['quarters'][4] : '-'; ?></td>
                                                        <td class="p-4 text-center">
                                                            <?php if ($data['average'] > 0): ?>
                                                                <span class="grade-score inline-block bg-green-600 text-white font-semibold py-2 px-4 rounded min-w-[50px]"><?php echo $data['average']; ?></span>
                                                            <?php else: ?>
                                                                -
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        <?php else: ?>
                                            <table class="grades-table w-full border-collapse bg-white shadow-sm rounded overflow-hidden">
                                                <thead class="bg-[#0a2d63] text-white">
                                                    <tr>
                                                        <th class="p-4 text-left font-semibold">Subject</th>
                                                        <th class="p-4 text-center font-semibold">Q1</th>
                                                        <th class="p-4 text-center font-semibold">Q2</th>
                                                        <th class="p-4 text-center font-semibold">Q3</th>
                                                        <th class="p-4 text-center font-semibold">Q4</th>
                                                        <th class="p-4 text-center font-semibold">Average</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <tr>
                                                        <td colspan="6" class="p-10 text-center text-gray-500">No grades available yet. Check back later.</td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="space-y-4 border-t-2 border-gray-200 pt-8 mt-8">
                                    <div class="subjects-header flex flex-col md:flex-row justify-between items-start md:items-center gap-4 pb-4 border-b border-gray-200">
                                        <div>
                                            <h3 class="text-2xl font-semibold text-[#0a2d63]">Subjects for Today</h3>
                                        </div>
                                        <button class="view-all-btn bg-[#0a2d63] text-white px-4 py-2 rounded font-medium hover:bg-[#08306b] transition w-full md:w-auto" onclick="toggleHomeSubjects()">View All Subjects</button>
                                    </div>
                                    <p class="text-gray-600">Your scheduled subjects for today.</p>
                                    
                                    <div class="subject-list space-y-4" id="todaySubjectList">
                                        <?php if (!empty($todaySubjects)): ?>
                                            <?php foreach ($todaySubjects as $subject): ?>
                                                <div class="subject-item bg-gray-50 p-5 hover:bg-gray-100 transition">
                                                    <h4 class="text-lg font-semibold text-gray-800 mb-2"><?php echo htmlspecialchars($subject['subject_name'] ?? ''); ?> <span class="today-badge bg-green-500 text-white text-xs font-semibold px-2 py-1 rounded ml-2 align-middle">TODAY</span></h4>
                                                    <p class="text-gray-600"><strong>Schedule:</strong> <?php echo htmlspecialchars($subject['schedule'] ?? 'Schedule not set'); ?></p>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <div class="no-subjects-today text-center p-8 bg-gray-50 rounded">
                                                <h4 class="text-lg font-semibold text-[#0a2d63] mb-2">No subjects scheduled for today</h4>
                                                <p class="text-gray-600">You have no classes scheduled for <?php echo $currentDay; ?>.</p>
                                                <p class="text-gray-600">Click "View All Subjects" to see your complete weekly schedule.</p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="subject-list hidden space-y-4" id="allSubjectList">
                                        <?php if (!empty($allSubjects)): ?>
                                            <?php foreach ($allSubjects as $subjectName => $subjectData): ?>
                                                <div class="subject-item bg-gray-50 p-5 hover:bg-gray-100 transition">
                                                    <h4 class="text-lg font-semibold text-gray-800 mb-2"><?php echo htmlspecialchars($subjectData['subject_name']); ?></h4>
                                                    <p class="text-gray-600"><strong>Code:</strong> <?php echo htmlspecialchars($subjectData['subject_code'] ?? ''); ?></p>
                                                    <p class="text-gray-600"><strong>Semester:</strong> <?php echo htmlspecialchars($subjectData['semester'] ?? ''); ?></p>
                                                    <div class="schedule-list mt-2 pl-4">
                                                        <p class="font-medium text-gray-700">All Schedules:</p>
                                                        <?php foreach ($subjectData['schedules'] as $schedule): ?>
                                                            <div class="schedule-item text-sm text-gray-600">
                                                                <span class="day font-semibold text-[#0a2d63]"><?php echo htmlspecialchars($schedule['day_of_week']); ?>:</span>
                                                                <span class="time text-gray-500"><?php echo htmlspecialchars($schedule['start_time_formatted']); ?> - <?php echo htmlspecialchars($schedule['end_time_formatted']); ?></span>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <div class="subject-item bg-gray-50 p-5">
                                                <h4 class="text-lg font-semibold text-[#0a2d63] mb-2">No subjects enrolled for your section</h4>
                                                <p class="text-gray-600">Grade Level: <?php echo htmlspecialchars($gradeLevel); ?> | Section: <?php echo htmlspecialchars($section); ?></p>
                                                <p class="description text-gray-500 italic mt-2">Contact your advisor if you believe this is incorrect.</p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="dashboard-card bg-white shadow-lg border border-gray-200 hidden" id="gradesCard">
                            <div class="card-content p-8 space-y-6 w-full">
                                <div>
                                    <h3 class="text-2xl font-semibold text-[#0a2d63] mb-2">Grades</h3>
                                    <p class="text-gray-600">Check your current grades, academic performance, and progress reports.</p>
                                </div>
                                
                                <div class="grade-summary overflow-x-auto w-full">
                                    <?php if (!$isFullyPaid): ?>
                                        <div class="p-12 text-center bg-red-50 border-2 border-red-200 rounded-xl">
                                            <div class="text-7xl text-red-600 mb-4">
                                                <i class="fas fa-times-circle"></i>
                                            </div>
                                            <h4 class="text-2xl font-bold text-red-800 mb-2">Access Restricted</h4>
                                            <p class="text-red-600 max-w-md mx-auto">Please contact the registrar to open your grades. Outstanding Balance: ₱<?php echo number_format($remainingBalance, 2); ?></p>
                                            <div class="mt-6 flex justify-center gap-4">
                                                <button onclick="navigateTo('payables')" class="bg-red-600 text-white px-6 py-2 rounded-lg font-semibold hover:bg-red-700 transition">Check Balance</button>
                                                <a href="mailto:registrar@baa.edu.ph" class="bg-white text-red-600 border border-red-600 px-6 py-2 rounded-lg font-semibold hover:bg-red-50 transition">Contact Registrar</a>
                                            </div>
                                        </div>
                                    <?php elseif (!empty($grades)): ?>
                                        <?php
                                        $groupedGrades = [];
                                        foreach ($grades as $grade) {
                                            $subjectName = $grade['subject_name'];
                                            $quarter = $grade['quarter'];
                                            if (!isset($groupedGrades[$subjectName])) {
                                                $groupedGrades[$subjectName] = [
                                                    'quarters' => [],
                                                    'average' => 0,
                                                    'count' => 0,
                                                    'total' => 0
                                                ];
                                            }
                                            $groupedGrades[$subjectName]['quarters'][$quarter] = $grade['grade'];
                                            $groupedGrades[$subjectName]['count']++;
                                            $groupedGrades[$subjectName]['total'] += $grade['grade'];
                                        }
                                        
                                        foreach ($groupedGrades as $subjectName => &$data) {
                                            if ($data['count'] > 0) {
                                                $data['average'] = round($data['total'] / $data['count']);
                                            }
                                        }
                                        ?>
                                        <table class="grades-table w-full border-collapse bg-white shadow-sm rounded min-w-[600px] md:min-w-full mt-4">
                                            <thead class="bg-[#0a2d63] text-white">
                                                <tr>
                                                    <th class="p-4 text-left font-semibold">Subject</th>
                                                    <th class="p-4 text-center font-semibold">Q1</th>
                                                    <th class="p-4 text-center font-semibold">Q2</th>
                                                    <th class="p-4 text-center font-semibold">Q3</th>
                                                    <th class="p-4 text-center font-semibold">Q4</th>
                                                    <th class="p-4 text-center font-semibold">Average</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($groupedGrades as $subjectName => $data): ?>
                                                <tr class="border-b border-gray-200 hover:bg-gray-50">
                                                    <td class="p-4 font-semibold text-gray-800 whitespace-nowrap"><?php echo htmlspecialchars($subjectName); ?></td>
                                                    <td class="p-4 text-center text-gray-700"><?php echo isset($data['quarters'][1]) ? $data['quarters'][1] : '-'; ?></td>
                                                    <td class="p-4 text-center text-gray-700"><?php echo isset($data['quarters'][2]) ? $data['quarters'][2] : '-'; ?></td>
                                                    <td class="p-4 text-center text-gray-700"><?php echo isset($data['quarters'][3]) ? $data['quarters'][3] : '-'; ?></td>
                                                    <td class="p-4 text-center text-gray-700"><?php echo isset($data['quarters'][4]) ? $data['quarters'][4] : '-'; ?></td>
                                                    <td class="p-4 text-center">
                                                        <?php if ($data['average'] > 0): ?>
                                                            <span class="grade-score inline-block bg-green-600 text-white font-semibold py-2 px-4 rounded min-w-[50px]"><?php echo $data['average']; ?></span>
                                                        <?php else: ?>
                                                            -
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    <?php else: ?>
                                        <table class="grades-table w-full border-collapse bg-white shadow-sm rounded overflow-hidden mt-4">
                                            <thead class="bg-[#0a2d63] text-white">
                                                <tr>
                                                    <th class="p-4 text-left font-semibold">Subject</th>
                                                    <th class="p-4 text-center font-semibold">Q1</th>
                                                    <th class="p-4 text-center font-semibold">Q2</th>
                                                    <th class="p-4 text-center font-semibold">Q3</th>
                                                    <th class="p-4 text-center font-semibold">Q4</th>
                                                    <th class="p-4 text-center font-semibold">Average</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <td colspan="6" class="p-10 text-center text-gray-500">No grades available yet. Check back later.</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="dashboard-card bg-white shadow-lg border border-gray-200 hidden subjects-card" id="subjectsCard">
                            <div class="card-content p-8 space-y-6 w-full">
                                <div class="subjects-header flex flex-col md:flex-row justify-between items-start md:items-center gap-4 pb-4 border-b border-gray-200">
                                    <div>
                                        <h3 class="text-2xl font-semibold text-[#0a2d63]">Today's Subjects</h3>
                                    </div>
                                    <button class="view-all-btn bg-[#0a2d63] text-white px-4 py-2 rounded font-medium hover:bg-[#08306b] transition w-full md:w-auto" onclick="toggleSubjectCard()" id="subjectsCardBtn">View All Subjects</button>
                                </div>
                                <p class="text-gray-600">Your subjects scheduled for today.</p>
                                
                                <div class="subject-list overflow-x-auto w-full" id="todaySubjectsCardList">
                                    <table class="grades-table w-full border-collapse bg-white shadow-sm rounded min-w-[600px] md:min-w-full">
                                        <thead class="bg-[#0a2d63] text-white">
                                            <tr>
                                                <th class="p-4 text-left font-semibold">Subject</th>
                                                <th class="p-4 text-left font-semibold">Teacher</th>
                                                <th class="p-4 text-left font-semibold">Day</th>
                                                <th class="p-4 text-left font-semibold">Time</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($todaySubjects)): ?>
                                                <?php foreach ($todaySubjects as $subject): ?>
                                                    <tr class="border-b border-gray-200 hover:bg-gray-50">
                                                        <td class="p-4 font-semibold text-gray-800 whitespace-nowrap"><?php echo htmlspecialchars($subject['subject_name'] ?? ''); ?></td>
                                                        <td class="p-4 text-gray-700">—</td>
                                                        <td class="p-4 text-gray-700"><?php echo htmlspecialchars($subject['day_of_week'] ?? ''); ?></td>
                                                        <td class="p-4 text-gray-700"><?php echo htmlspecialchars($subject['start_time_formatted'] ?? '') . ' - ' . htmlspecialchars($subject['end_time_formatted'] ?? ''); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr><td colspan="4" class="p-10 text-center text-gray-500">No subjects scheduled for today.</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <div class="subject-list hidden overflow-x-auto w-full" id="allSubjectsCardList">
                                    <table class="grades-table w-full border-collapse bg-white shadow-sm rounded min-w-[600px] md:min-w-full">
                                        <thead class="bg-[#0a2d63] text-white">
                                            <tr>
                                                <th class="p-4 text-left font-semibold">Subject</th>
                                                <th class="p-4 text-left font-semibold">Teacher</th>
                                                <th class="p-4 text-left font-semibold">Schedule</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($groupedSubjectsForDisplay)): ?>
                                                <?php foreach ($groupedSubjectsForDisplay as $item): ?>
                                                    <tr class="border-b border-gray-200 hover:bg-gray-50">
                                                        <td class="p-4 font-semibold text-gray-800 whitespace-nowrap"><?php echo htmlspecialchars($item['subject_name']); ?></td>
                                                        <td class="p-4 text-gray-700">—</td>
                                                        <td class="p-4 text-gray-700 schedule-cell"><?php echo $item['schedules_display']; ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr><td colspan="3" class="p-10 text-center text-gray-500">No subjects enrolled for your section.</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <div class="dashboard-card bg-white shadow-lg border border-gray-200 hidden events-card" id="eventsCard">
                            <div class="card-content p-8 space-y-6 w-full">
                                <div>
                                    <h3 class="text-2xl font-semibold text-[#0a2d63] mb-2">Upcoming School Events</h3>
                                    <p class="text-gray-600">View upcoming school events, activities, and important dates for the next 15 days.</p>
                                </div>
                                <div class="event-list space-y-4 max-h-[400px] overflow-y-auto pr-2" id="eventList">
                                    <?php if (!empty($events)): ?>
                                        <?php foreach ($events as $event): ?>
                                            <div class="event-item bg-gray-50 p-5 hover:bg-gray-100 transition animate-slideIn">
                                                <div class="event-date bg-[#0a2d63] text-white px-4 py-2 rounded inline-block mb-3 font-semibold text-sm min-w-[200px] text-center">
                                                    <?php 
                                                    $eventDate = new DateTime($event['event_date']);
                                                    $today = new DateTime();
                                                    $interval = $today->diff($eventDate);
                                                    $daysDiff = $interval->days;
                                                    
                                                    $formattedDate = date('F j, Y', strtotime($event['event_date']));
                                                    if ($daysDiff == 0) {
                                                        echo 'Today - ' . $formattedDate;
                                                    } elseif ($daysDiff == 1) {
                                                        echo 'Tomorrow - ' . $formattedDate;
                                                    } else {
                                                        echo $formattedDate;
                                                    }
                                                    ?>
                                                </div>
                                                <div class="event-details">
                                                    <h4 class="text-lg font-semibold text-[#0a2d63] mb-2"><?php echo htmlspecialchars($event['event_name']); ?></h4>
                                                    <?php if (!empty($event['description'])): ?>
                                                        <p class="text-gray-600 text-sm mb-1"><?php echo htmlspecialchars($event['description']); ?></p>
                                                    <?php endif; ?>
                                                    <?php if (!empty($event['responsible_dept'])): ?>
                                                        <p class="text-gray-500 text-sm"><?php echo htmlspecialchars($event['responsible_dept']); ?></p>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="event-item bg-gray-50 p-5 no-events-message">
                                            <div class="event-details">
                                                <h4 class="text-lg font-semibold text-[#0a2d63] mb-2">No upcoming events in the next 15 days</h4>
                                                <p class="text-gray-600">Check back later for upcoming events.</p>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="dashboard-card bg-white shadow-lg border border-gray-200 hidden payables-card" id="payablesCard">
                            <div class="card-content p-8 space-y-6 w-full">
                                <div>
                                    <h3 class="text-2xl font-semibold text-[#0a2d63] mb-2">Payables</h3>
                                    <p class="text-gray-600">View your tuition fees, payment history, and outstanding balances.</p>
                                </div>
                                <div class="payable-list space-y-4" id="payableList">
                                    <div class="loading text-center text-gray-500 py-10">Loading payables...</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="dashboard-card bg-white shadow-lg border border-gray-200 hidden profile-card" id="profileCard">
                            <div class="card-content p-8 space-y-6 w-full">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <h3 class="text-2xl font-semibold text-[#0a2d63] mb-2">Profile</h3>
                                        <p class="text-gray-600">View and update your personal information.</p>
                                    </div>
                                    <button type="button" onclick="openChangePasswordModal()" class="bg-[#0a2d63] text-white px-4 py-2 rounded font-medium hover:bg-[#08306b] transition">Change Password</button>
                                </div>
                                <div class="profile-info bg-gray-50 p-8 space-y-4" id="profileInfo">
                                    <div class="info-item flex flex-col sm:flex-row justify-between items-start sm:items-center py-4 border-b border-gray-200 last:border-0 gap-2">
                                        <span class="label font-semibold text-gray-800">Full Name:</span>
                                        <span class="value text-gray-600"><?php echo htmlspecialchars(trim(($user['first_name'] ?? '') . ' ' . ($user['middle_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))); ?></span>
                                    </div>
                                    <div class="info-item flex flex-col sm:flex-row justify-between items-start sm:items-center py-4 border-b border-gray-200 last:border-0 gap-2">
                                        <span class="label font-semibold text-gray-800">Username:</span>
                                        <span class="value text-gray-600"><?php echo htmlspecialchars($userName); ?></span>
                                    </div>
                                    <div class="info-item flex flex-col sm:flex-row justify-between items-start sm:items-center py-4 border-b border-gray-200 last:border-0 gap-2">
                                        <span class="label font-semibold text-gray-800">User Role:</span>
                                        <span class="value text-gray-600"><?php echo htmlspecialchars(ucfirst($userRole)); ?></span>
                                    </div>
                                    <div class="info-item flex flex-col sm:flex-row justify-between items-start sm:items-center py-4 border-b border-gray-200 last:border-0 gap-2">
                                        <span class="label font-semibold text-gray-800">Grade Level:</span>
                                        <span class="value text-gray-600"><?php echo htmlspecialchars($gradeLevel); ?></span>
                                    </div>
                                    <div class="info-item flex flex-col sm:flex-row justify-between items-start sm:items-center py-4 border-b border-gray-200 last:border-0 gap-2">
                                        <span class="label font-semibold text-gray-800">Section:</span>
                                        <span class="value text-gray-600"><?php echo htmlspecialchars($section); ?></span>
                                    </div>
                                    <div class="info-item flex flex-col sm:flex-row justify-between items-start sm:items-center py-4 border-b border-gray-200 last:border-0 gap-2">
                                        <span class="label font-semibold text-gray-800">LRN:</span>
                                        <span class="value text-gray-600"><?php echo htmlspecialchars($lrn); ?></span>
                                    </div>
                                    <div class="info-item flex flex-col sm:flex-row justify-between items-start sm:items-center py-4 border-b border-gray-200 last:border-0 gap-2">
                                        <span class="label font-semibold text-gray-800">Payment Status:</span>
                                        <span class="value <?php echo $isFullyPaid ? 'text-green-600 font-bold' : 'text-red-600 font-bold'; ?>">
                                            <?php echo $isFullyPaid ? 'Fully Paid' : '₱' . number_format($remainingBalance, 2) . ' Outstanding'; ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="bg-gray-50 p-6 rounded border border-gray-200 space-y-4">
                                    <h4 class="text-lg font-semibold text-[#0a2d63]">Required Documents</h4>
                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3 items-end">
                                        <div class="md:col-span-2">
                                            <label for="studentRequirementSelect" class="block mb-1 font-medium text-gray-700">Requirement</label>
                                            <select id="studentRequirementSelect" class="w-full p-2 border border-gray-300 rounded"></select>
                                        </div>
                                        <div>
                                            <label for="studentDocumentFile" class="block mb-1 font-medium text-gray-700">File</label>
                                            <input type="file" id="studentDocumentFile" class="w-full p-2 border border-gray-300 rounded" accept=".pdf,.jpg,.jpeg,.png">
                                        </div>
                                    </div>
                                    <div>
                                        <button type="button" onclick="uploadStudentRequirementDocument()" class="bg-[#0a2d63] text-white px-4 py-2 rounded font-medium hover:bg-[#08306b] transition">Upload Document</button>
                                    </div>
                                    <div id="studentRequirementsChecklist" class="space-y-2"></div>
                                    <div id="studentUploadedDocuments" class="space-y-2"></div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="dashboard-card bg-white shadow-lg border border-gray-200 hidden announcements-card" id="announcementsCard">
                            <div class="card-content p-8 space-y-6 w-full">
                                <div>
                                    <h3 class="text-2xl font-semibold text-[#0a2d63] mb-2">Announcements</h3>
                                    <p class="text-gray-600">Latest school announcements and updates.</p>
                                </div>
                                <div class="announcement-list space-y-4" id="announcementList">
                                    <div class="loading text-center text-gray-500 py-10">Loading announcements...</div>
                                </div>
                            </div>
                        </div>

                    <?php elseif ($userRole == 'teacher'): ?>
                        <div class="dashboard-card bg-white shadow-lg border border-gray-200 hidden active" id="teacherHomeCard">
                            <div class="card-content p-8 space-y-6 w-full">
                                <div class="bg-white rounded-xl shadow-lg p-6 mb-8">
                                    <h3 class="text-2xl font-bold text-[#0a2d63] mb-4">Attendance Analytics</h3>
                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                                        <div class="bg-green-50 p-4 rounded-lg"><p class="text-gray-600">Present Today</p><p class="text-3xl font-bold text-green-700" id="presentCountDisplay">0</p></div>
                                        <div class="bg-orange-50 p-4 rounded-lg"><p class="text-gray-600">Late Arrivals</p><p class="text-3xl font-bold text-orange-700" id="lateCountDisplay">0</p></div>
                                        <div class="bg-red-50 p-4 rounded-lg"><p class="text-gray-600">Excused Absences</p><p class="text-3xl font-bold text-red-700" id="absentCountDisplay">0</p></div>
                                    </div>
                                    <div class="mt-4 h-2 w-full bg-gray-200 rounded-full overflow-hidden"><div class="h-full bg-green-500 rounded-full" style="width: <?php echo count($studentsList) > 0 ? ($presentToday / count($studentsList)) * 100 : 0; ?>%"></div></div>
                                    <p class="text-sm text-gray-500 mt-2">Average Daily Attendance: <?php echo count($studentsList) > 0 ? round(($presentToday / count($studentsList)) * 100) : 0; ?>%</p>
                                </div>
                                <div class="bg-white rounded-xl shadow-lg p-6 mb-8">
                                    <div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-4 mb-4">
                                        <div>
                                            <h3 class="text-2xl font-bold text-[#0a2d63]">All Subjects Performance</h3>
                                            <p class="text-gray-600">View actual student grades for the subjects you teach.</p>
                                        </div>
                                        <div class="flex flex-wrap items-center gap-3 w-full md:w-auto">
                                            <button class="bg-[#0a2d63] text-white px-4 py-2 rounded-lg w-full md:w-auto" onclick="openTeacherStudentSearchModal()">Search Student</button>
                                            <button id="clearTeacherStudentFilterBtn" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-lg hidden w-full md:w-auto" onclick="clearTeacherStudentFilter()">Clear Filter</button>
                                            <span id="teacherStudentFilterLabel" class="text-sm text-gray-500 w-full md:w-auto text-center md:text-left"></span>
                                        </div>
                                    </div>
                                    <table class="min-w-full border-collapse">
                                        <thead>
                                            <tr class="bg-gray-100">
                                                <th class="p-3 text-left">Subject</th>
                                                <th class="p-3 text-center">Grade</th>
                                            </tr>
                                        </thead>
                                        <tbody id="teacherPerformanceTableBody">
                                        </tbody>
                                    </table>
                                </div>
                                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                                    <div class="bg-white rounded-xl shadow-lg p-6">
                                        <h3 class="text-2xl font-bold text-[#0a2d63] mb-4">Disciplinary Records</h3>
                                        <?php foreach ($allTeacherStudents as $student): $disc = $disciplinary[$student['id']] ?? null; ?>
                                        <div class="mb-4 p-3 border rounded-lg teacher-student-card" data-student-id="<?php echo $student['id']; ?>" style="display:none;">
                                            <div class="font-semibold"><?php echo htmlspecialchars($student['full_name']); ?></div>
                                            <?php if ($disc && $disc['suspension_count'] > 0): ?>
                                                <span class="inline-block bg-red-100 text-red-800 text-xs px-2 py-1 rounded mt-1">Suspensions: <?php echo $disc['suspension_count']; ?></span>
                                                <p class="text-sm text-gray-600 mt-1">Dates: <?php echo htmlspecialchars($disc['suspension_dates']); ?></p>
                                                <p class="text-sm text-gray-600 break-words">Reason: <?php echo htmlspecialchars($disc['reason']); ?></p>
                                            <?php else: ?>
                                                <p class="text-sm text-gray-500 italic">No disciplinary record</p>
                                            <?php endif; ?>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="bg-white rounded-xl shadow-lg p-6">
                                        <h3 class="text-2xl font-bold text-[#0a2d63] mb-4">Extracurricular Activities</h3>
                                        <?php foreach ($allTeacherStudents as $student): $activities = $extracurricular[$student['id']] ?? []; ?>
                                        <div class="mb-4 p-3 border rounded-lg teacher-student-card" data-student-id="<?php echo $student['id']; ?>" style="display:none;">
                                            <div class="font-semibold"><?php echo htmlspecialchars($student['full_name']); ?></div>
                                            <div class="flex flex-wrap gap-1 mt-2">
                                                <?php if (!empty($activities)): foreach ($activities as $act): ?>
                                                    <span class="tag break-words text-center"><?php echo htmlspecialchars($act); ?></span>
                                                <?php endforeach; else: ?>
                                                    <span class="text-gray-400 text-sm">No activities</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="dashboard-card bg-white shadow-lg border border-gray-200 hidden" id="teacherGradeEncodingCard">
                            <div class="card-content p-6 md:p-8 space-y-5 w-full">

                                <!-- Header row -->
                                <div class="flex flex-col md:flex-row gap-4 items-start md:items-center justify-between">
                                    <div>
                                        <h3 class="text-2xl font-bold text-[#0a2d63]">Grade Encoding</h3>
                                        <p class="text-sm text-gray-500 mt-0.5" id="gradeEncodingSubjectLabel">Select a section and subject to begin</p>
                                    </div>
                                    <div class="flex flex-wrap gap-3 w-full md:w-auto items-center">
                                        <!-- Section select — auto-loads subjects -->
                                        <select id="gradeSectionSelect" class="filter-select w-full sm:w-auto flex-1" onchange="filterGradeSubjectsBySection()">
                                            <option value="">Select Section</option>
                                            <?php foreach ($teacherSections as $sec): ?>
                                                <option value="<?php echo htmlspecialchars($sec['section']); ?>" data-grade="<?php echo htmlspecialchars($sec['grade_level']); ?>"><?php echo htmlspecialchars($sec['grade_level'] . ' - ' . $sec['section']); ?></option>
                                            <?php endforeach; ?>
                                        </select>

                                        <!-- Subject select — auto-loads students on change -->
                                        <select id="gradeSubjectSelect" class="filter-select w-full sm:w-auto flex-1" onchange="autoLoadGradeStudents()">
                                            <option value="">Select Subject</option>
                                            <?php $renderedSubjectKeys = [];
                                            foreach ($teacherSubjects as $subj):
                                                $subjectKey = $subj['subject_name'] . '|' . $subj['grade_level'] . '|' . $subj['section'];
                                                if (in_array($subjectKey, $renderedSubjectKeys)) continue;
                                                $renderedSubjectKeys[] = $subjectKey;
                                            ?>
                                                <option value="<?php echo $subj['id']; ?>" data-name="<?php echo htmlspecialchars($subj['subject_name']); ?>" data-section="<?php echo htmlspecialchars($subj['section']); ?>" data-grade="<?php echo htmlspecialchars($subj['grade_level']); ?>"><?php echo htmlspecialchars($subj['subject_name'] . ' — ' . $subj['grade_level'] . ' ' . $subj['section']); ?></option>
                                            <?php endforeach; ?>
                                        </select>

                                        <!-- Add Activity (visible after students load) -->
                                        <button id="addActivityBtn" onclick="openAddActivityModal()" class="hidden bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg font-medium transition text-sm whitespace-nowrap">Add Activity</button>
                                    </div>
                                </div>

                                <!-- Semester tabs -->
                                <div id="semesterTabsRow" class="hidden flex gap-1 border-b border-gray-200">
                                    <button class="sem-tab px-5 py-2 text-sm font-semibold rounded-t-lg border border-b-0 border-gray-200 bg-[#0a2d63] text-white transition" data-sem="1" onclick="switchSemester(1)">1st Semester</button>
                                    <button class="sem-tab px-5 py-2 text-sm font-semibold rounded-t-lg border border-b-0 border-gray-200 bg-white text-gray-600 hover:text-[#0a2d63] transition" data-sem="2" onclick="switchSemester(2)">2nd Semester</button>
                                    <button class="sem-tab px-5 py-2 text-sm font-semibold rounded-t-lg border border-b-0 border-gray-200 bg-white text-gray-600 hover:text-[#0a2d63] transition" data-sem="3" onclick="switchSemester(3)">3rd Semester</button>
                                </div>

                                <!-- Grade table -->
                                <div id="gradeEncodingTableContainer" class="overflow-x-auto w-full">
                                    <p class="text-gray-400 text-center py-14 text-sm">← Select a section and subject above to load students.</p>
                                </div>

                                <!-- Stats row -->
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4" id="gradeStats">
                                    <div class="bg-blue-50 border border-blue-100 p-4 rounded-xl text-center">
                                        <div class="text-xs font-semibold text-blue-600 uppercase tracking-wide mb-1">Class Average</div>
                                        <div id="classAvg" class="text-2xl font-bold text-[#0a2d63]">—</div>
                                    </div>
                                    <div class="bg-green-50 border border-green-100 p-4 rounded-xl text-center">
                                        <div class="text-xs font-semibold text-green-600 uppercase tracking-wide mb-1">Passing Rate</div>
                                        <div id="passRate" class="text-2xl font-bold text-green-700">—</div>
                                    </div>
                                    <div class="bg-yellow-50 border border-yellow-100 p-4 rounded-xl text-center">
                                        <div class="text-xs font-semibold text-yellow-600 uppercase tracking-wide mb-1">Highest Grade</div>
                                        <div id="highGrade" class="text-2xl font-bold text-yellow-700">—</div>
                                    </div>
                                </div>

                                <!-- Save row (hidden until students loaded) -->
                                <div id="gradeSaveRow" class="hidden mt-4 flex flex-col sm:flex-row gap-3 justify-end">
                                    <button onclick="saveAllGrades()" class="bg-green-600 hover:bg-green-700 text-white px-8 py-2.5 rounded-lg font-semibold transition w-full sm:w-auto">Save All Grades</button>
                                </div>
                            </div>
                        </div>

                    <div class="dashboard-card bg-white shadow-lg border border-gray-200 hidden teacher-attendance-card" id="teacherAttendanceCard">
                        <div class="card-content p-8 space-y-6 w-full">
                            <div class="flex flex-col lg:flex-row items-start lg:items-center justify-between gap-4 mb-6">
                                <div>
                                    <h3 class="text-2xl font-bold text-[#0a2d63]">Attendance Tracker</h3>
                                </div>
                                <div class="flex flex-wrap items-center gap-3 w-full lg:w-auto">
                                    <button onclick="addAttendanceDate()" class="bg-[#0a2d63] text-white px-4 py-2 rounded-lg w-full sm:w-auto">Add Today's Date</button>
                                    
                                    <button onclick="document.getElementById('attendanceSpecificDateInput').showPicker()" class="bg-[#0a2d63] text-white px-4 py-2 rounded-lg w-full sm:w-auto">Add Specific Date</button>
                                    <input id="attendanceSpecificDateInput" type="date" max="<?php echo date('Y-m-d'); ?>" class="hidden" onchange="addAttendanceSpecificDate()" />
                                </div>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Filter by Grade:</label>
                                    <select id="attendanceGradeFilter" class="filter-select mt-1" onchange="updateAttendanceSectionOptions()">
                                        <option value="all">All Grades</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Filter by Section:</label>
                                    <select id="attendanceSectionFilter" class="filter-select mt-1" onchange="updateAttendanceAnalysis()">
                                        <option value="all">All Sections</option>
                                    </select>
                                </div>
                            </div> 
                            <div class="overflow-x-auto w-full">
                                <table id="attendanceTable" class="min-w-full border-collapse min-w-[600px] lg:min-w-full">
                                    <thead id="attendanceHeader">
                                        <tr class="bg-gray-100">
                                            <th class="p-3 sticky left-0 bg-gray-100 z-10 whitespace-nowrap">Student Name</th>
                                        </tr>
                                    </thead>
                                    <tbody id="attendanceBody">
                                        <?php foreach ($studentsList as $student): ?>
                                        <tr class="border-b border-gray-200 hover:bg-gray-50" data-student-id="<?php echo (int) $student['id']; ?>">
                                            <td class="p-3 font-semibold sticky left-0 bg-white z-10 whitespace-nowrap"><?php echo htmlspecialchars($student['full_name']); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="mt-6 flex flex-col sm:flex-row sm:justify-end gap-3">
                                <button onclick="promptAttendanceLedger()" class="bg-green-600 text-white px-6 py-2 rounded-lg w-full sm:w-auto hover:bg-green-700 transition">Make Attendance Ledger</button>
                                <button onclick="promptAttendanceReportRange()" class="bg-[#0a2d63] text-white px-6 py-2 rounded-lg w-full sm:w-auto hover:bg-[#08306b] transition">Generate Attendance PDF</button>
                                <button onclick="saveAttendanceLog()" class="bg-[#0a2d63] text-white px-6 py-2 rounded-lg w-full sm:w-auto">Save Daily Log</button>
                            </div>
                        </div>
                    </div>

                        <!-- ========== TEACHER CLASS LIST TAB ========== -->
                        <div class="dashboard-card bg-white shadow-lg border border-gray-200 hidden" id="teacherClassListCard">
                            <div class="card-content p-6 md:p-8 space-y-6 w-full">
                                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                                    <div>
                                        <h3 class="text-2xl font-bold text-[#0a2d63]">Class List</h3>
                                        <p class="text-sm text-gray-500 mt-0.5">View and search students in your assigned sections</p>
                                    </div>
                                    <div class="flex gap-2 w-full sm:w-auto">
                                        <button onclick="exportTeacherClassListPDF()" class="bg-[#0a2d63] hover:bg-[#08306b] text-white px-4 py-2 rounded-lg font-medium transition text-sm flex items-center justify-center gap-2 w-full sm:w-auto">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                            </svg>
                                            Export PDF
                                        </button>
                                    </div>
                                </div>

                                <!-- Filter Sections -->
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div class="filter-section bg-white p-4 rounded-lg border border-gray-200 flex-1">
                                        <h4 class="text-sm font-semibold text-[#0a2d63] mb-2">Grade Level Filters</h4>
                                        <div id="teacherClassListGradeCheckboxes" class="checkbox-group flex flex-wrap gap-3">
                                            <!-- Will be dynamically populated with unique grades from window.teacherSections -->
                                        </div>
                                    </div>
                                    <div id="teacherClassListSectionContainer" class="filter-section bg-white p-4 rounded-lg border border-gray-200 flex-1 hidden">
                                        <h4 class="text-sm font-semibold text-[#0a2d63] mb-2">Section Filters</h4>
                                        <div id="teacherClassListSectionCheckboxes" class="checkbox-group flex flex-wrap gap-3">
                                            <!-- Dynamically populated sections -->
                                        </div>
                                    </div>
                                </div>

                                <!-- Sort and Search Row -->
                                <div class="mt-4">
                                    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                                        <div class="flex flex-col sm:flex-row gap-2 sm:items-center">
                                            <span class="text-sm font-semibold text-[#0a2d63] mb-1 sm:mb-0">Sort:</span>
                                            <div class="grid grid-cols-2 sm:flex sm:flex-wrap gap-2 items-center">
                                                <span class="teacher-class-sort-option px-3 py-1 border border-gray-300 rounded-full cursor-pointer text-sm hover:bg-gray-200 transition active text-center flex items-center justify-center whitespace-nowrap font-medium" onclick="setTeacherClassListSort('name')" id="teacher-class-sort-name">Name</span>
                                                <span class="teacher-class-sort-option px-3 py-1 border border-gray-300 rounded-full cursor-pointer text-sm hover:bg-gray-200 transition text-center flex items-center justify-center whitespace-nowrap font-medium" onclick="setTeacherClassListSort('grade')" id="teacher-class-sort-grade">Grade</span>
                                                <span class="teacher-class-sort-option px-3 py-1 border border-gray-300 rounded-full cursor-pointer text-sm hover:bg-gray-200 transition text-center flex items-center justify-center whitespace-nowrap font-medium" onclick="setTeacherClassListSort('lrn')" id="teacher-class-sort-lrn">LRN</span>
                                            </div>
                                        </div>
                                        <div class="flex-1 w-full md:max-w-md px-0 md:px-4">
                                            <input type="text" id="teacherClassListSearch" placeholder="Search student name, username, email, or LRN..." class="w-full p-2 border border-gray-300 rounded focus:ring-2 focus:ring-[#0a2d63] outline-none" onkeyup="applyTeacherClassListFilters()">
                                        </div>
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="text-sm font-semibold text-[#0a2d63]">Show:</span>
                                            <select id="teacherClassListPageSizeSelect" class="w-full sm:w-auto p-2 border border-gray-300 rounded focus:ring-2 focus:ring-[#0a2d63] outline-none" onchange="setTeacherClassListPageSize(this.value)">
                                                <option value="5">5</option>
                                                <option value="10" selected>10</option>
                                                <option value="20">20</option>
                                                <option value="30">30</option>
                                                <option value="50">50</option>
                                                <option value="100">100</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <div id="teacherClassListContainer" class="border border-gray-200 rounded min-h-[200px] bg-white">
                                    <!-- Populated dynamically -->
                                </div>
                            </div>
                        </div>
                <?php endif; ?>

            </div>
        </div>
    </div>

    <div id="attendanceRangeModal" class="modal-overlay fixed inset-0 bg-black bg-opacity-50 items-center justify-center z-[1000] p-4" style="display: none;">
        <div class="modal-container bg-white rounded-lg w-full max-w-md shadow-xl flex flex-col">
            <div class="modal-header p-4 border-b border-gray-200 bg-gray-50 rounded-t-lg flex justify-between items-center">
                <h3 class="text-lg font-semibold text-[#0a2d63]">Generate Attendance PDF</h3>
                <button class="modal-close text-2xl text-gray-600 hover:text-gray-800 w-8 h-8 flex items-center justify-center rounded hover:bg-gray-200 transition" onclick="closeAttendanceRangeModal()">×</button>
            </div>
            <div class="modal-body p-5 grid grid-cols-1 gap-3">
                <button class="bg-[#0a2d63] text-white px-4 py-2 rounded font-medium hover:bg-[#08306b] transition" onclick="generateAttendanceReport('day')">Today</button>
                <button class="bg-[#0a2d63] text-white px-4 py-2 rounded font-medium hover:bg-[#08306b] transition" onclick="generateAttendanceReport('week')">This Week</button>
                <button class="bg-[#0a2d63] text-white px-4 py-2 rounded font-medium hover:bg-[#08306b] transition" onclick="generateAttendanceReport('month')">This Month</button>
                <button class="bg-[#0a2d63] text-white px-4 py-2 rounded font-medium hover:bg-[#08306b] transition" onclick="generateAttendanceReport('school_year')">School Year (4-5 months)</button>
            </div>
        </div>
    </div>

    <!-- Attendance Ledger Modal -->
    <div id="attendanceLedgerModal" class="modal-overlay fixed inset-0 bg-black bg-opacity-50 items-center justify-center z-[1000] p-4" style="display: none;">
        <div class="modal-container bg-white rounded-lg w-full max-w-md shadow-xl flex flex-col">
            <div class="modal-header p-4 border-b border-gray-200 bg-gray-50 rounded-t-lg flex justify-between items-center">
                <h3 class="text-lg font-semibold text-[#0a2d63]">Make Attendance Ledger</h3>
                <button class="modal-close text-2xl text-gray-600 hover:text-gray-800 w-8 h-8 flex items-center justify-center rounded hover:bg-gray-200 transition" onclick="closeAttendanceLedgerModal()">×</button>
            </div>
            <form id="attendanceLedgerForm" action="php/generate_ledger_pdf.php" method="GET" target="_blank" onsubmit="closeAttendanceLedgerModal()">
                <div class="modal-body p-5 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Select Student</label>
                        <select name="student_id" required class="w-full p-2 border border-gray-300 rounded focus:ring-2 focus:ring-[#0a2d63] outline-none">
                            <option value="">-- Select Student --</option>
                            <?php foreach ($studentsList as $student): ?>
                                <option value="<?php echo (int)$student['id']; ?>"><?php echo htmlspecialchars($student['full_name'] . ' (' . $student['grade_level'] . ' - ' . $student['section'] . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Date Range</label>
                        <select id="ledgerRangeType" name="range_type" class="w-full p-2 border border-gray-300 rounded focus:ring-2 focus:ring-[#0a2d63] outline-none" onchange="toggleLedgerCustomDates()">
                            <option value="month">This Month</option>
                            <option value="school_year">School Year</option>
                            <option value="custom">Custom Date Range</option>
                        </select>
                    </div>
                    <div id="ledgerCustomDates" class="grid grid-cols-2 gap-3" style="display: none;">
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1">Start Date</label>
                            <input type="date" id="ledgerStartDate" name="start_date" class="w-full p-2 border border-gray-300 rounded focus:ring-2 focus:ring-[#0a2d63] outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1">End Date</label>
                            <input type="date" id="ledgerEndDate" name="end_date" class="w-full p-2 border border-gray-300 rounded focus:ring-2 focus:ring-[#0a2d63] outline-none">
                        </div>
                    </div>
                </div>
                <div class="modal-footer p-4 border-t border-gray-200 bg-gray-50 rounded-b-lg flex justify-end gap-2">
                    <button type="button" class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-4 py-2 rounded font-medium transition" onclick="closeAttendanceLedgerModal()">Cancel</button>
                    <button type="submit" class="bg-[#0a2d63] text-white px-5 py-2 rounded font-medium hover:bg-[#08306b] transition">Generate Ledger</button>
                </div>
            </form>
        </div>
    </div>

    <div id="teacherStudentSearchModal" class="modal-overlay fixed inset-0 bg-black bg-opacity-50 items-center justify-center z-[1000] p-4" style="display: none;">
        <div class="modal-container bg-white rounded-lg w-full max-w-4xl max-h-[90vh] overflow-y-auto shadow-xl flex flex-col">
            <div class="modal-header p-4 md:p-5 border-b border-gray-200 bg-gray-50 rounded-t-lg flex justify-between items-center sticky top-0 z-10">
                <h3 class="text-lg md:text-xl font-semibold text-[#0a2d63]">Search Students</h3>
                <button class="modal-close text-2xl text-gray-600 hover:text-gray-800 w-8 h-8 flex items-center justify-center rounded hover:bg-gray-200 transition" onclick="closeTeacherStudentSearchModal()">×</button>
            </div>
            <div class="modal-body p-4 md:p-6 flex-1 overflow-y-auto">
                <div class="form-group mb-4">
                    <label class="block mb-2 font-medium text-gray-700">Search by name, email, or LRN</label>
                    <input type="text" id="teacherStudentSearchInput" placeholder="Type to search..." class="w-full p-2 border border-gray-300 rounded focus:ring-2 focus:ring-[#0a2d63] outline-none" onkeyup="searchTeacherStudents()">
                </div>

                <div class="filter-section bg-gray-50 p-4 rounded mb-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <h4 class="text-sm font-semibold text-[#0a2d63] mb-2">Filter by Grade Level</h4>
                            <select id="teacherStudentFilterGrade" class="w-full p-2 border border-gray-300 rounded focus:ring-2 focus:ring-[#0a2d63] outline-none" onchange="updateTeacherStudentFilterSections(); searchTeacherStudents()">
                                <option value="">All Grades</option>
                                <option value="Grade 7">Grade 7</option>
                                <option value="Grade 8">Grade 8</option>
                                <option value="Grade 9">Grade 9</option>
                                <option value="Grade 10">Grade 10</option>
                                <option value="Grade 11">Grade 11</option>
                                <option value="Grade 12">Grade 12</option>
                            </select>
                        </div>
                        <div>
                            <h4 class="text-sm font-semibold text-[#0a2d63] mb-2">Filter by Section</h4>
                            <select id="teacherStudentFilterSection" class="w-full p-2 border border-gray-300 rounded focus:ring-2 focus:ring-[#0a2d63] outline-none" onchange="searchTeacherStudents()">
                                <option value="">All Sections</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div id="teacherStudentSearchResults" class="search-results min-h-[200px] border border-gray-200 rounded">
                    <div class="text-center p-10 text-gray-500">Loading students...</div>
                </div>

                <div id="teacherStudentSearchPagination" class="pagination-controls hidden mt-4 p-4 bg-gray-50 rounded flex flex-col sm:flex-row justify-between items-center gap-4">
                    <div class="pagination-info text-sm text-gray-600 text-center" id="teacherStudentSearchInfo"></div>
                    <div class="pagination-buttons flex flex-wrap justify-center gap-1" id="teacherStudentSearchButtons"></div>
                </div>
            </div>
            <div class="modal-footer p-4 md:p-5 border-t border-gray-200 bg-gray-50 rounded-b-lg text-right sticky bottom-0 z-10">
                <button class="bg-gray-600 text-white px-5 py-2 rounded font-medium hover:bg-gray-700 transition w-full sm:w-auto" onclick="closeTeacherStudentSearchModal()">Close</button>
            </div>
        </div>
    </div>

    <div id="addUserModal" class="modal-overlay fixed inset-0 bg-black bg-opacity-50 items-center justify-center z-[1000] p-4" style="display: none;">
        <div class="modal-container bg-white rounded-lg w-full max-w-lg max-h-[90vh] overflow-y-auto shadow-xl flex flex-col">
            <div class="modal-header p-4 md:p-5 border-b border-gray-200 bg-gray-50 rounded-t-lg flex justify-between items-center sticky top-0 z-10">
                <h3 class="text-lg md:text-xl font-semibold text-[#0a2d63]">Add New User</h3>
                <button class="modal-close text-2xl text-gray-600 hover:text-gray-800 w-8 h-8 flex items-center justify-center rounded hover:bg-gray-200 transition" onclick="closeAddUserModal()">×</button>
            </div>
            <div class="modal-body p-4 md:p-6 flex-1 overflow-y-auto">
                <form id="createUserForm">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                        <div class="form-group sm:col-span-2">
                            <label class="block mb-2 font-medium text-gray-700">Username *</label>
                            <input type="text" name="username" required class="w-full p-2 border border-gray-300 rounded focus:outline-none focus:border-[#0a2d63] focus:ring-2 focus:ring-[#0a2d63] focus:ring-opacity-50">
                        </div>
                        <div class="form-group sm:col-span-2">
                            <label class="block mb-2 font-medium text-gray-700">Email *</label>
                            <input type="email" name="email" required class="w-full p-2 border border-gray-300 rounded focus:ring-2 focus:ring-[#0a2d63] outline-none">
                        </div>
                        <div class="form-group sm:col-span-2">
                            <label class="block mb-2 font-medium text-gray-700">Password *</label>
                            <input type="password" name="password" required class="w-full p-2 border border-gray-300 rounded focus:ring-2 focus:ring-[#0a2d63] outline-none">
                        </div>
                        <div class="form-group">
                            <label class="block mb-2 font-medium text-gray-700">First Name *</label>
                            <input type="text" name="first_name" required class="w-full p-2 border border-gray-300 rounded focus:ring-2 focus:ring-[#0a2d63] outline-none">
                        </div>
                        <div class="form-group">
                            <label class="block mb-2 font-medium text-gray-700">Middle Name</label>
                            <input type="text" name="middle_name" class="w-full p-2 border border-gray-300 rounded focus:ring-2 focus:ring-[#0a2d63] outline-none">
                        </div>
                        <div class="form-group">
                            <label class="block mb-2 font-medium text-gray-700">Last Name *</label>
                            <input type="text" name="last_name" required class="w-full p-2 border border-gray-300 rounded focus:ring-2 focus:ring-[#0a2d63] outline-none">
                        </div>
                        <div class="form-group">
                            <label class="block mb-2 font-medium text-gray-700">Suffix</label>
                            <input type="text" name="suffix" class="w-full p-2 border border-gray-300 rounded focus:ring-2 focus:ring-[#0a2d63] outline-none">
                        </div>
                        <div class="form-group sm:col-span-2">
                            <label class="block mb-2 font-medium text-gray-700">Role *</label>
                            <select name="role" id="modalRoleSelect" onchange="toggleModalStudentFields()" required class="w-full p-2 border border-gray-300 rounded focus:ring-2 focus:ring-[#0a2d63] outline-none">
                                <option value="">Select Role</option>
                                <option value="student">Student</option>
                                <?php if ($userRole == 'admin'): ?>
                                <option value="teacher">Teacher</option>
                                <option value="cashier">Cashier</option>
                                <option value="registrar">Registrar</option>
                                <option value="admin">Admin</option>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                        <div class="form-group">
                            <label class="block mb-2 font-medium text-gray-700">Age <span class="text-gray-500 font-normal">(from birthdate)</span></label>
                            <input type="number" name="age" id="modalAge" min="8" max="50" readonly class="w-full p-2 border border-gray-300 rounded bg-gray-100 focus:ring-2 focus:ring-[#0a2d63] outline-none">
                        </div>
                        <div class="form-group">
                            <label class="block mb-2 font-medium text-gray-700">Gender *</label>
                            <select name="gender" id="modalGender" required class="w-full p-2 border border-gray-300 rounded focus:ring-2 focus:ring-[#0a2d63] outline-none">
                                <option value="">Select Gender</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                        </div>
                        <div class="form-group sm:col-span-2">
                            <label class="block mb-2 font-medium text-gray-700">Birthdate *</label>
                            <input type="date" name="birthdate" id="modalBirthdate" required class="w-full p-2 border border-gray-300 rounded focus:ring-2 focus:ring-[#0a2d63] outline-none">
                        </div>
                        <div class="form-group sm:col-span-2">
                            <label class="block mb-2 font-medium text-gray-700">Phone Number *</label>
                            <div class="phone-input-wrapper flex items-center border border-gray-300 rounded focus-within:ring-2 focus-within:ring-[#0a2d63]">
                                <span class="phone-prefix bg-gray-100 px-3 py-2 rounded-l border-r border-gray-300">+63</span>
                                <input type="text" name="phone" id="modalPhone" maxlength="10" placeholder="9XXXXXXXXX" pattern="[0-9]{10}" oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0,10)" required class="w-full p-2 border-0 rounded-r focus:ring-0 outline-none">
                            </div>
                            <small class="text-gray-500 block mt-1">Enter 10 digits (without +63)</small>
                        </div>
                    </div>

                    <div id="modalStudentFields" class="student-fields hidden p-4 bg-gray-50 rounded mb-4 border border-gray-200">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div class="form-group">
                                <label class="block mb-2 font-medium text-gray-700">Grade Level *</label>
                                <select name="gradeLevel" id="modalGradeLevel" onchange="updateModalSections()" class="w-full p-2 border border-gray-300 rounded focus:ring-2 focus:ring-[#0a2d63] outline-none">
                                    <option value="">Select Grade Level</option>
                                    <option value="Grade 7">Grade 7</option>
                                    <option value="Grade 8">Grade 8</option>
                                    <option value="Grade 9">Grade 9</option>
                                    <option value="Grade 10">Grade 10</option>
                                    <option value="Grade 11">Grade 11</option>
                                    <option value="Grade 12">Grade 12</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="block mb-2 font-medium text-gray-700">Section *</label>
                                <select name="section" id="modalSectionSelect" class="w-full p-2 border border-gray-300 rounded focus:ring-2 focus:ring-[#0a2d63] outline-none">
                                    <option value="">Select Section</option>
                                </select>
                            </div>
                            <div class="form-group sm:col-span-2">
                                <label class="block mb-2 font-medium text-gray-700">LRN</label>
                                <input type="text" name="lrn" id="modalLrnField" inputmode="numeric" maxlength="12" pattern="[0-9]{12}" placeholder="12-digit LRN" class="w-full p-2 border border-gray-300 rounded focus:ring-2 focus:ring-[#0a2d63] outline-none">
                                <small class="text-gray-500 block mt-1">Must be exactly 12 digits</small>
                            </div>
                            <div id="modalStrandContainer" class="form-group sm:col-span-2 hidden">
                                <label class="block mb-2 font-medium text-gray-700">Strand *</label>
                                <select name="strand" id="modalStrand" class="w-full p-2 border border-gray-300 rounded focus:ring-2 focus:ring-[#0a2d63] outline-none">
                                    <option value="">Select Strand</option>
                                    <option value="STEM">STEM</option>
                                    <option value="ABM">ABM</option>
                                    <option value="HUMSS">HUMSS</option>
                                </select>
                            </div>
                            <input type="hidden" id="modalEnrollmentId" value="">
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer p-4 md:p-5 border-t border-gray-200 bg-gray-50 rounded-b-lg flex flex-col-reverse sm:flex-row justify-end gap-2 sticky bottom-0 z-10">
                <button class="bg-gray-600 text-white px-5 py-2 rounded font-medium hover:bg-gray-700 transition w-full sm:w-auto" onclick="closeAddUserModal()">Cancel</button>
                <button class="bg-[#0a2d63] text-white px-5 py-2 rounded font-medium hover:bg-[#08306b] transition w-full sm:w-auto" onclick="submitAddUser()">Add User</button>
            </div>
        </div>
    </div>

    <div id="editUserModal" class="modal-overlay fixed inset-0 bg-black bg-opacity-50 items-center justify-center z-[1000] p-4" style="display: none;">
        <div class="modal-container bg-white rounded-lg w-full max-w-4xl max-h-[90vh] overflow-y-auto shadow-xl flex flex-col">
            <div class="modal-header p-4 md:p-5 border-b border-gray-200 bg-gray-50 rounded-t-lg flex justify-between items-center sticky top-0 z-10">
                <h3 class="text-lg md:text-xl font-semibold text-[#0a2d63]">Edit User</h3>
                <button class="modal-close text-2xl text-gray-600 hover:text-gray-800 w-8 h-8 flex items-center justify-center rounded hover:bg-gray-200 transition" onclick="closeEditUserModal()">×</button>
            </div>
            <div class="modal-body p-4 md:p-6 flex-1 overflow-y-auto">
                <div id="editUserDetails">
                    <h4 class="text-base md:text-lg font-semibold text-[#0a2d63] mb-3">User Details</h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="p-4 bg-blue-50 rounded-lg border border-blue-100">
                            <h5 class="font-semibold text-blue-800 mb-2">User Information</h5>
                            <div id="editUserInfo" class="space-y-2 text-sm break-words">
                                </div>
                        </div>

                        <div class="p-4 bg-green-50 rounded-lg border border-green-100">
                            <h5 class="font-semibold text-green-800 mb-2">Edit Details</h5>
                            <div id="editUserForm" class="space-y-4">
                                </div>
                        </div>
                    </div>

                    <div id="teacherSubjectSection" class="hidden mt-6">
                        <h4 class="text-base md:text-lg font-semibold text-[#0a2d63] mb-3">Subject Assignments</h4>
                        <div class="p-4 bg-yellow-50 rounded-lg border border-yellow-200">
                            <p class="text-sm text-yellow-800 mb-4">Select a grade level and check/uncheck subjects to assign or unassign them to this teacher:</p>
                            
                            <div id="selectedSubjectsSummary" class="mb-4 p-3 bg-white rounded border border-yellow-300 hidden">
                                <p class="font-medium text-sm text-gray-700 mb-2">Selected Subjects:</p>
                                <div id="selectedSubjectsDisplay" class="flex flex-wrap gap-2">
                                    </div>
                            </div>
                            
                            <div class="mb-4">
                                <label class="block mb-2 font-medium text-gray-700">Filter by Grade Level</label>
                                <div class="flex flex-col sm:flex-row sm:items-center gap-2">
                                    <select id="subjectGradeFilter" onchange="filterSubjectsByGrade()" class="w-full sm:w-1/2 p-2 border border-gray-300 rounded focus:ring-2 focus:ring-yellow-500 outline-none">
                                        <option value="">All Grades</option>
                                        <option value="Grade 7">Grade 7</option>
                                        <option value="Grade 8">Grade 8</option>
                                        <option value="Grade 9">Grade 9</option>
                                        <option value="Grade 10">Grade 10</option>
                                        <option value="Grade 11">Grade 11</option>
                                        <option value="Grade 12">Grade 12</option>
                                    </select>
                                    <div class="flex gap-2 w-full sm:w-auto">
                                        <button type="button" id="subjectSelectAllBtn" onclick="toggleSelectAllSubjects()" class="flex-1 sm:flex-none px-4 py-2 rounded border border-yellow-300 bg-white text-yellow-800 font-medium hover:bg-yellow-50 transition">
                                            Select All
                                        </button>
                                        <button type="button" id="subjectClearAllBtn" onclick="clearAllSubjects()" class="flex-1 sm:flex-none px-4 py-2 rounded border border-yellow-300 bg-white text-yellow-800 font-medium hover:bg-yellow-50 transition">
                                            Clear All
                                        </button>
                                    </div>
                                </div>
                            </div>
                            
                            <div id="subjectCheckboxes" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 max-h-80 overflow-y-auto p-2 bg-white rounded border border-yellow-100">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer p-4 md:p-5 border-t border-gray-200 bg-gray-50 rounded-b-lg flex flex-col-reverse sm:flex-row justify-end gap-2 sticky bottom-0 z-10">
                <button class="bg-gray-600 text-white px-5 py-2 rounded font-medium hover:bg-gray-700 transition w-full sm:w-auto" onclick="closeEditUserModal()">Cancel</button>
                <button id="saveEditUserBtn" class="bg-[#0a2d63] text-white px-5 py-2 rounded font-medium hover:bg-[#08306b] transition w-full sm:w-auto disabled:opacity-50 disabled:cursor-not-allowed" onclick="saveEditUser()" disabled>Save Changes</button>
            </div>
        </div>
    </div>

    <div id="enrollmentSearchModal" class="modal-overlay fixed inset-0 bg-black bg-opacity-50 items-center justify-center z-[1000] p-4" style="display: none;">
        <div class="modal-container bg-white rounded-lg w-full max-w-3xl max-h-[90vh] overflow-y-auto shadow-xl flex flex-col">
            <div class="modal-header p-4 md:p-5 border-b border-gray-200 bg-gray-50 rounded-t-lg flex justify-between items-center sticky top-0 z-10">
                <h3 class="text-lg md:text-xl font-semibold text-[#0a2d63]">Search Enrollees</h3>
                <button class="modal-close text-2xl text-gray-600 hover:text-gray-800 w-8 h-8 flex items-center justify-center rounded hover:bg-gray-200 transition" onclick="closeEnrollmentSearchModal()">×</button>
            </div>
            <div class="modal-body p-4 md:p-6 flex-1 overflow-y-auto">
                <div class="form-group mb-4">
                    <label class="block mb-2 font-medium text-gray-700">Search by name, email, or phone</label>
                    <input type="text" id="enrollmentSearchInput" placeholder="Type to search..." class="w-full p-2 border border-gray-300 rounded focus:ring-2 focus:ring-[#0a2d63] outline-none" onkeyup="filterEnrollments()">
                </div>

                <div class="filter-section bg-gray-50 p-4 rounded-lg mb-4 border border-gray-200">
                    <h4 class="text-sm font-semibold text-[#0a2d63] mb-3">Filter by Status</h4>
                    <div class="checkbox-group flex flex-wrap gap-4">
                        <div class="checkbox-item flex items-center gap-2">
                            <input type="checkbox" id="filterPending" class="w-4 h-4 text-[#0a2d63]" value="pending" onchange="filterEnrollments()" checked>
                            <label for="filterPending" class="text-sm text-gray-700 cursor-pointer">Pending</label>
                        </div>
                        <div class="checkbox-item flex items-center gap-2">
                            <input type="checkbox" id="filterApproved" class="w-4 h-4 text-[#0a2d63]" value="approved" onchange="filterEnrollments()" checked>
                            <label for="filterApproved" class="text-sm text-gray-700 cursor-pointer">Approved</label>
                        </div>
                        <div class="checkbox-item flex items-center gap-2">
                            <input type="checkbox" id="filterNeedsDocs" class="w-4 h-4 text-[#0a2d63]" value="needs_docs" onchange="filterEnrollments()" checked>
                            <label for="filterNeedsDocs" class="text-sm text-gray-700 cursor-pointer">Needs Documents</label>
                        </div>
                        <div class="checkbox-item flex items-center gap-2">
                            <input type="checkbox" id="filterRejected" class="w-4 h-4 text-[#0a2d63]" value="rejected" onchange="filterEnrollments()" checked>
                            <label for="filterRejected" class="text-sm text-gray-700 cursor-pointer">Rejected</label>
                        </div>
                    </div>
                </div>

                <div class="filter-section bg-gray-50 p-4 rounded-lg mb-4 border border-gray-200">
                    <h4 class="text-sm font-semibold text-[#0a2d63] mb-2">Results Per Page</h4>
                    <div class="custom-per-page flex flex-wrap items-center gap-2 mt-2">
                        <select id="enrollmentPerPage" class="border border-gray-300 rounded px-2 py-1 text-sm focus:ring-2 focus:ring-[#0a2d63] outline-none" onchange="changeEnrollmentPerPage()">
                            <option value="10">10</option>
                            <option value="25">25</option>
                            <option value="50">50</option>
                            <option value="75">75</option>
                            <option value="100">100</option>
                            <option value="custom">Custom</option>
                        </select>
                        <div id="enrollmentCustomPerPage" class="hidden flex items-center gap-2">
                            <input type="number" id="enrollmentCustomNumber" min="1" max="500" placeholder="Number" class="border border-gray-300 rounded px-2 py-1 w-20 text-sm focus:ring-2 focus:ring-[#0a2d63] outline-none">
                            <button onclick="applyEnrollmentCustomPerPage()" class="bg-[#0a2d63] text-white px-3 py-1 rounded text-sm">Apply</button>
                        </div>
                    </div>
                </div>

                <div id="enrollmentSearchResults" class="search-results min-h-[200px] border border-gray-200 rounded">
                    <div class="text-center p-10 text-gray-500">Loading enrollments...</div>
                </div>

                <div id="enrollmentSearchPagination" class="pagination-controls hidden mt-4 p-4 bg-gray-50 rounded flex flex-col sm:flex-row justify-between items-center gap-4">
                    <div class="pagination-info text-sm text-gray-600 text-center" id="enrollmentSearchInfo"></div>
                    <div class="pagination-buttons flex flex-wrap justify-center gap-1" id="enrollmentSearchButtons"></div>
                </div>
            </div>
            <div class="modal-footer p-4 md:p-5 border-t border-gray-200 bg-gray-50 rounded-b-lg text-right sticky bottom-0 z-10">
                <button class="bg-gray-600 text-white px-5 py-2 rounded font-medium hover:bg-gray-700 transition w-full sm:w-auto" onclick="closeEnrollmentSearchModal()">Close</button>
            </div>
        </div>
    </div>

    <div id="documentModal" class="modal-overlay fixed inset-0 bg-black bg-opacity-50 items-center justify-center z-[1000] p-4" style="display: none;">
        <div class="modal-container bg-white rounded-lg w-full max-w-4xl max-h-[90vh] overflow-y-auto shadow-xl flex flex-col">
            <div class="modal-header p-4 md:p-5 border-b border-gray-200 bg-gray-50 rounded-t-lg flex justify-between items-center sticky top-0 z-10">
                <h3 class="text-lg md:text-xl font-semibold text-[#0a2d63]">Student Documents</h3>
                <button class="modal-close text-2xl text-gray-600 hover:text-gray-800 w-8 h-8 flex items-center justify-center rounded hover:bg-gray-200 transition" onclick="closeDocumentModal()">×</button>
            </div>
            <div class="modal-body p-4 md:p-6 flex-1 overflow-y-auto">
                <div id="documentList" class="min-h-[200px]">
                    <div class="text-center p-10 text-gray-500">Loading documents...</div>
                </div>
            </div>
            <div class="modal-footer p-4 md:p-5 border-t border-gray-200 bg-gray-50 rounded-b-lg text-right sticky bottom-0 z-10">
                <button class="bg-gray-600 text-white px-5 py-2 rounded font-medium hover:bg-gray-700 transition w-full sm:w-auto" onclick="closeDocumentModal()">Close</button>
            </div>
        </div>
    </div>

    <div id="rejectEnrollmentModal" class="modal-overlay fixed inset-0 bg-black bg-opacity-50 items-center justify-center z-[1000] p-4" style="display: none;">
        <div class="modal-container bg-white rounded-lg w-full max-w-2xl max-h-[90vh] overflow-y-auto shadow-xl flex flex-col">
            <div class="modal-header p-4 md:p-5 border-b border-gray-200 bg-gray-50 rounded-t-lg flex justify-between items-center sticky top-0 z-10">
                <h3 class="text-lg md:text-xl font-semibold text-[#0a2d63]">Reject Enrollment</h3>
                <button class="modal-close text-2xl text-gray-600 hover:text-gray-800 w-8 h-8 flex items-center justify-center rounded hover:bg-gray-200 transition" onclick="closeRejectEnrollmentModal()">×</button>
            </div>
            <div class="modal-body p-4 md:p-6 flex-1 overflow-y-auto space-y-4">
                <input type="hidden" id="rejectEnrollmentId">
                <div class="bg-gray-50 border border-gray-200 rounded p-4 text-sm">
                    <p><span class="font-semibold">Enrollee:</span> <span id="rejectEnrollmentName">-</span></p>
                    <p><span class="font-semibold">Grade:</span> <span id="rejectEnrollmentGrade">-</span></p>
                </div>

                <div>
                    <p class="font-semibold text-[#0a2d63] mb-2">Reason(s) *</p>
                    <label class="flex items-center gap-2 mb-2">
                        <input type="checkbox" id="rejectReasonDocs" onchange="toggleMissingDocsBox()">
                        <span>Lack of documents</span>
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="checkbox" id="rejectReasonData">
                        <span>Insufficient data</span>
                    </label>
                    <p id="rejectReasonError" class="text-red-600 text-sm mt-2" style="display:none;">Select at least one reason.</p>
                </div>

                <div id="rejectMissingDocsContainer" class="bg-gray-50 border border-gray-200 rounded p-4" style="display:none;">
                    <p class="font-semibold text-[#0a2d63] mb-2">Missing documents *</p>
                    <div id="rejectMissingDocsList" class="space-y-2"></div>
                    <p id="rejectDocsError" class="text-red-600 text-sm mt-2" style="display:none;">Select at least one missing document.</p>
                </div>

                <div>
                    <label for="rejectCustomMessage" class="block mb-2 font-semibold text-[#0a2d63]">Custom message (optional)</label>
                    <textarea id="rejectCustomMessage" rows="4" class="w-full p-2 border border-gray-300 rounded focus:ring-2 focus:ring-[#0a2d63] outline-none" placeholder="Add additional details for the enrollee email."></textarea>
                </div>
            </div>
            <div class="modal-footer p-4 md:p-5 border-t border-gray-200 bg-gray-50 rounded-b-lg flex flex-col-reverse sm:flex-row justify-end gap-2 sticky bottom-0 z-10">
                <button class="bg-gray-600 text-white px-5 py-2 rounded font-medium hover:bg-gray-700 transition w-full sm:w-auto" onclick="closeRejectEnrollmentModal()">Cancel</button>
                <button class="bg-red-600 text-white px-5 py-2 rounded font-medium hover:bg-red-700 transition w-full sm:w-auto" onclick="submitRejectEnrollment()">Confirm Reject</button>
            </div>
        </div>
    </div>

    <div id="studentSelectModal" class="modal-overlay fixed inset-0 bg-black bg-opacity-50 items-center justify-center z-[1000] p-4" style="display: none;">
        <div class="modal-container bg-white rounded-lg w-full max-w-3xl max-h-[90vh] overflow-y-auto shadow-xl flex flex-col">
            <div class="modal-header p-4 md:p-5 border-b border-gray-200 bg-gray-50 rounded-t-lg flex justify-between items-center sticky top-0 z-10">
                <h3 class="text-lg md:text-xl font-semibold text-[#0a2d63]">Select Student</h3>
                <button class="modal-close text-2xl text-gray-600 hover:text-gray-800 w-8 h-8 flex items-center justify-center rounded hover:bg-gray-200 transition" onclick="closeStudentSelectModal()">×</button>
            </div>
            <div class="modal-body p-4 md:p-6 flex-1 overflow-y-auto">
                <div class="form-group mb-4">
                    <label class="block mb-2 font-medium text-gray-700">Search by name, email, grade, or section</label>
                    <input type="text" id="studentSearchInput" placeholder="Type to search..." class="w-full p-2 border border-gray-300 rounded focus:ring-2 focus:ring-[#0a2d63] outline-none" onkeyup="filterStudentsForSelect()">
                </div>

                <div class="filter-section bg-gray-50 p-4 rounded-lg mb-4 border border-gray-200">
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                        <div>
                            <h4 class="text-sm font-semibold text-[#0a2d63] mb-2">Filter by Grade Level</h4>
                            <select id="studentFilterGrade" class="w-full p-2 border border-gray-300 rounded focus:ring-2 focus:ring-[#0a2d63] outline-none" onchange="updateStudentFilterSections(); filterStudentsForSelect()">
                                <option value="">All Grades</option>
                                <option value="Grade 7">Grade 7</option>
                                <option value="Grade 8">Grade 8</option>
                                <option value="Grade 9">Grade 9</option>
                                <option value="Grade 10">Grade 10</option>
                                <option value="Grade 11">Grade 11</option>
                                <option value="Grade 12">Grade 12</option>
                            </select>
                        </div>
                        <div>
                            <h4 class="text-sm font-semibold text-[#0a2d63] mb-2">Filter by Section</h4>
                            <select id="studentFilterSection" class="w-full p-2 border border-gray-300 rounded focus:ring-2 focus:ring-[#0a2d63] outline-none" onchange="filterStudentsForSelect()">
                                <option value="">All Sections</option>
                            </select>
                        </div>
                        <div>
                            <h4 class="text-sm font-semibold text-[#0a2d63] mb-2">Results Per Page</h4>
                            <div class="flex flex-col gap-2">
                                <select id="studentResultsPerPage" class="w-full p-2 border border-gray-300 rounded focus:ring-2 focus:ring-[#0a2d63] outline-none" onchange="toggleStudentCustomPerPage(); filterStudentsForSelect()">
                                    <option value="5">5</option>
                                    <option value="10" selected>10</option>
                                    <option value="20">20</option>
                                    <option value="50">50</option>
                                    <option value="custom">Custom</option>
                                </select>
                                <div id="studentCustomPerPageContainer" class="hidden flex gap-2">
                                    <input type="number" id="studentCustomPerPage" min="1" placeholder="Amount" class="w-full p-2 border border-gray-300 rounded focus:ring-2 focus:ring-[#0a2d63] outline-none">
                                    <button type="button" onclick="applyStudentCustomPerPage()" class="bg-[#0a2d63] text-white px-3 py-2 rounded shrink-0">Apply</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="studentSelectResults" class="search-results min-h-[200px] border border-gray-200 rounded">
                    <div class="text-center p-10 text-gray-500">Loading students...</div>
                </div>
            </div>
            <div class="modal-footer p-4 md:p-5 border-t border-gray-200 bg-gray-50 rounded-b-lg text-right sticky bottom-0 z-10">
                <button class="bg-gray-600 text-white px-5 py-2 rounded font-medium hover:bg-gray-700 transition w-full sm:w-auto" onclick="closeStudentSelectModal()">Cancel</button>
            </div>
        </div>
    </div>

    <div id="paymentEnrolleeSelectModal" class="modal-overlay fixed inset-0 bg-black bg-opacity-50 items-center justify-center z-[1000] p-4" style="display: none;">
        <div class="modal-container bg-white rounded-lg w-full max-w-3xl max-h-[90vh] overflow-y-auto shadow-xl flex flex-col">
            <div class="modal-header p-4 md:p-5 border-b border-gray-200 bg-gray-50 rounded-t-lg flex justify-between items-center sticky top-0 z-10">
                <h3 class="text-lg md:text-xl font-semibold text-[#0a2d63]">Select Enrollee</h3>
                <button class="modal-close text-2xl text-gray-600 hover:text-gray-800 w-8 h-8 flex items-center justify-center rounded hover:bg-gray-200 transition" onclick="closePaymentEnrolleeBrowseModal()">×</button>
            </div>
            <div class="modal-body p-4 md:p-6 flex-1 overflow-y-auto">
                <div class="mb-6 p-4 bg-gray-50 rounded-lg border border-gray-200">
                    <h4 class="text-base md:text-lg font-semibold text-[#0a2d63] mb-3">Search Enrollee</h4>
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                        <div class="form-group mb-0">
                            <label class="block mb-1 font-medium text-gray-700 text-sm">Search by Name</label>
                            <input type="text" id="paymentEnrolleeSearchInput" placeholder="Type name..." class="w-full p-2 border border-gray-300 rounded focus:ring-2 focus:ring-[#0a2d63] outline-none" onkeyup="filterPaymentEnrolleesInModal()">
                        </div>
                        <div class="form-group mb-0">
                            <label class="block mb-1 font-medium text-gray-700 text-sm">Filter by Grade Level</label>
                            <select id="paymentEnrolleeFilterGrade" class="w-full p-2 border border-gray-300 rounded focus:ring-2 focus:ring-[#0a2d63] outline-none" onchange="filterPaymentEnrolleesInModal()">
                                <option value="">All Grades</option>
                                <option value="Grade 7">Grade 7</option>
                                <option value="Grade 8">Grade 8</option>
                                <option value="Grade 9">Grade 9</option>
                                <option value="Grade 10">Grade 10</option>
                                <option value="Grade 11">Grade 11</option>
                                <option value="Grade 12">Grade 12</option>
                            </select>
                        </div>
                        <div class="flex items-end">
                            <button type="button" onclick="filterPaymentEnrolleesInModal()" class="bg-[#0a2d63] text-white px-4 py-2 rounded font-medium hover:bg-[#08306b] transition w-full">Search</button>
                        </div>
                    </div>
                </div>
                <div id="paymentEnrolleeSelectResults" class="search-results min-h-[200px] border border-gray-200 rounded">
                    <div class="text-center p-10 text-gray-500">Loading enrollees...</div>
                </div>
            </div>
            <div class="modal-footer p-4 md:p-5 border-t border-gray-200 bg-gray-50 rounded-b-lg text-right sticky bottom-0 z-10">
                <button class="bg-gray-600 text-white px-5 py-2 rounded font-medium hover:bg-gray-700 transition w-full sm:w-auto" onclick="closePaymentEnrolleeBrowseModal()">Cancel</button>
            </div>
        </div>
    </div>

    <div id="paymentStudentSelectModal" class="modal-overlay fixed inset-0 bg-black bg-opacity-50 items-center justify-center z-[1000] p-4" style="display: none;">
        <div class="modal-container bg-white rounded-lg w-full max-w-3xl max-h-[90vh] overflow-y-auto shadow-xl flex flex-col">
            <div class="modal-header p-4 md:p-5 border-b border-gray-200 bg-gray-50 rounded-t-lg flex justify-between items-center sticky top-0 z-10">
                <h3 class="text-lg md:text-xl font-semibold text-[#0a2d63]">Select Student</h3>
                <button class="modal-close text-2xl text-gray-600 hover:text-gray-800 w-8 h-8 flex items-center justify-center rounded hover:bg-gray-200 transition" onclick="closePaymentStudentBrowseModal()">×</button>
            </div>
            <div class="modal-body p-4 md:p-6 flex-1 overflow-y-auto">
                <div class="mb-6 p-4 bg-gray-50 rounded-lg border border-gray-200">
                    <h4 class="text-base md:text-lg font-semibold text-[#0a2d63] mb-3">Search Student</h4>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div class="form-group mb-0">
                            <label class="block mb-1 font-medium text-gray-700 text-sm">Search by Name / Email</label>
                            <input type="text" id="paymentStudentSearchInput" placeholder="Type to search..." class="w-full p-2 border border-gray-300 rounded focus:ring-2 focus:ring-[#0a2d63] outline-none" onkeyup="filterPaymentStudentsInModal()">
                        </div>
                        <div class="form-group mb-0">
                            <label class="block mb-1 font-medium text-gray-700 text-sm">Filter by Grade Level</label>
                            <select id="paymentStudentFilterGrade" class="w-full p-2 border border-gray-300 rounded focus:ring-2 focus:ring-[#0a2d63] outline-none" onchange="updatePaymentStudentSections(); filterPaymentStudentsInModal()">
                                <option value="">All Grades</option>
                                <option value="Grade 7">Grade 7</option>
                                <option value="Grade 8">Grade 8</option>
                                <option value="Grade 9">Grade 9</option>
                                <option value="Grade 10">Grade 10</option>
                                <option value="Grade 11">Grade 11</option>
                                <option value="Grade 12">Grade 12</option>
                            </select>
                        </div>
                        <div class="form-group mb-0">
                            <label class="block mb-1 font-medium text-gray-700 text-sm">Filter by Section</label>
                            <select id="paymentStudentFilterSection" class="w-full p-2 border border-gray-300 rounded focus:ring-2 focus:ring-[#0a2d63] outline-none" onchange="filterPaymentStudentsInModal()">
                                <option value="">All Sections</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div id="paymentStudentSelectResults" class="search-results min-h-[200px] border border-gray-200 rounded">
                    <div class="text-center p-10 text-gray-500">Loading students...</div>
                </div>
            </div>
            <div class="modal-footer p-4 md:p-5 border-t border-gray-200 bg-gray-50 rounded-b-lg text-right sticky bottom-0 z-10">
                <button class="bg-gray-600 text-white px-5 py-2 rounded font-medium hover:bg-gray-700 transition w-full sm:w-auto" onclick="closePaymentStudentBrowseModal()">Cancel</button>
            </div>
        </div>
    </div>

    <div id="batchPromoteModal" class="modal-overlay fixed inset-0 bg-black bg-opacity-50 items-center justify-center z-[1000] p-4" style="display: none;">
        <div class="modal-container bg-white rounded-lg w-full max-w-md shadow-xl flex flex-col">
            <div class="modal-header p-4 md:p-5 border-b border-gray-200 bg-gray-50 rounded-t-lg flex justify-between items-center sticky top-0 z-10">
                <h3 class="text-lg md:text-xl font-semibold text-[#0a2d63]">Batch Promote Students</h3>
                <button class="modal-close text-2xl text-gray-600 hover:text-gray-800 w-8 h-8 flex items-center justify-center rounded hover:bg-gray-200 transition" onclick="closeBatchPromoteModal()">×</button>
            </div>
            <div class="modal-body p-4 md:p-6 flex-1">
                <p class="mb-4 text-gray-600">Select a grade and (optional) section to promote all students.</p>
                <select id="batchPromoteGrade" class="w-full p-2 border border-gray-300 rounded mb-4 focus:ring-2 focus:ring-blue-500 outline-none" onchange="updateBatchSections()">
                    <option value="">Select Grade</option>
                </select>
                <select id="batchPromoteSection" class="w-full p-2 border border-gray-300 rounded mb-4 focus:ring-2 focus:ring-blue-500 outline-none">
                    <option value="">All Sections</option>
                </select>
            </div>
            <div class="modal-footer p-4 md:p-5 border-t border-gray-200 bg-gray-50 rounded-b-lg flex flex-col-reverse sm:flex-row justify-end gap-2 sticky bottom-0 z-10">
                <button class="bg-gray-600 text-white px-5 py-2 rounded font-medium hover:bg-gray-700 transition w-full sm:w-auto" onclick="closeBatchPromoteModal()">Cancel</button>
                <button class="bg-blue-600 text-white px-5 py-2 rounded font-medium hover:bg-blue-700 transition w-full sm:w-auto" onclick="batchPromote()">Promote</button>
            </div> 
        </div>
    </div>

</div>

<script>
    // ==================== STUDENT SEARCH FUNCTIONS ====================
    // Lightweight stubs to ensure inline handlers don't throw before
    // the full implementations are parsed later in the file.
    function openExportStudentModal() {
        try {
            if (window.openExportStudentModal && window.openExportStudentModal !== openExportStudentModal) return window.openExportStudentModal();
            const m = document.getElementById('exportStudentModal'); if (m) { m.classList.remove('hidden'); m.classList.add('flex'); }
        } catch (e) { console.error('openExportStudentModal stub error', e); }
    }
    function closeExportStudentModal() {
        try {
            if (window.closeExportStudentModal && window.closeExportStudentModal !== closeExportStudentModal) return window.closeExportStudentModal();
            const m = document.getElementById('exportStudentModal'); if (m) { m.classList.add('hidden'); m.classList.remove('flex'); }
        } catch (e) { console.error('closeExportStudentModal stub error', e); }
    }
    let currentStudentSearchPage = 1;
    let currentStudentSearchFilters = {};

    function openTeacherStudentSearchModal() {
        document.getElementById('teacherStudentSearchModal').style.display = 'flex';
        document.getElementById('teacherStudentSearchInput').value = '';
        document.getElementById('teacherStudentFilterGrade').value = '';
        document.getElementById('teacherStudentFilterSection').value = '';
        document.getElementById('teacherStudentFilterSection').innerHTML = '<option value="">All Sections</option>';
        currentStudentSearchPage = 1;
        currentStudentSearchFilters = {};
        searchTeacherStudents();
    }

    function closeTeacherStudentSearchModal() {
        document.getElementById('teacherStudentSearchModal').style.display = 'none';
    }

    function searchTeacherStudents() {
        const search = document.getElementById('teacherStudentSearchInput').value;
        const grade = document.getElementById('teacherStudentFilterGrade').value;
        const section = document.getElementById('teacherStudentFilterSection').value;
        
        currentStudentSearchFilters = { search, grade, section };
        currentStudentSearchPage = 1;
        performTeacherStudentSearch();
    }

    function performTeacherStudentSearch() {
        const resultsDiv = document.getElementById('teacherStudentSearchResults');
        resultsDiv.innerHTML = '<div class="text-center p-10 text-gray-500">Searching...</div>';
        
        const formData = new FormData();
        formData.append('action', 'search_students');
        formData.append('search', currentStudentSearchFilters.search || '');
        formData.append('grade_filter', currentStudentSearchFilters.grade || '');
        formData.append('section_filter', currentStudentSearchFilters.section || '');
        formData.append('per_page', '10');
        formData.append('page', currentStudentSearchPage.toString());
        
        fetch(window.location.href, { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayTeacherStudentResults(data);
                } else {
                    const msg = data && data.message ? String(data.message) : 'Error loading students';
                    resultsDiv.innerHTML = '<div class="text-center p-10 text-red-500">Error loading students<br><span class="text-xs text-red-400 break-words">' + msg.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</span></div>';
                }
            })
            .catch(error => {
                console.error(error);
                resultsDiv.innerHTML = '<div class="text-center p-10 text-red-500">Error loading students<br><span class="text-xs text-red-400 break-words">' + (error?.message ? String(error.message) : 'Network error') + '</span></div>';
            });
    }

    function displayTeacherStudentResults(data) {
        const resultsDiv = document.getElementById('teacherStudentSearchResults');
        const paginationDiv = document.getElementById('teacherStudentSearchPagination');
        
        // Fixed Filtering: Filter results down to students only present in the teacher's localized list
        const localStudentIds = teacherHomeStudents.map(s => s.id.toString());
        const filteredStudents = data.students.filter(s => localStudentIds.includes(s.id.toString()));
        
        if (filteredStudents.length === 0) {
            resultsDiv.innerHTML = '<div class="text-center p-10 text-gray-500">No students found assigned to you</div>';
            paginationDiv.classList.add('hidden');
            return;
        }
        
        let html = '';
        filteredStudents.forEach(student => {
            html += `
                <div class="student-item p-4 border-b border-gray-200 hover:bg-gray-50 cursor-pointer flex flex-col sm:flex-row sm:justify-between sm:items-center" onclick="selectTeacherStudent(${student.id}, '${student.full_name.replace(/'/g, "\\'")}')">
                    <div>
                        <div class="font-semibold text-[#0a2d63]">${student.full_name}</div>
                        <div class="text-sm text-gray-600 break-all">${student.email}</div>
                    </div>
                    <div class="text-sm text-gray-500 mt-1 sm:mt-0 font-medium">Grade ${student.grade_level} - ${student.section}</div>
                </div>
            `;
        });
        resultsDiv.innerHTML = html;
        
        // Pagination logic (simplified for local filtering, though ideally API should be scoped to teacher)
        if (data.total_pages > 1) {
            let paginationHtml = '<div class="flex flex-wrap justify-center gap-1">';
            for (let i = 1; i <= data.total_pages; i++) {
                const activeClass = i === data.page ? 'bg-[#0a2d63] text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300';
                paginationHtml += `<button class="px-3 py-1 rounded min-w-[32px] ${activeClass}" onclick="changeTeacherStudentPage(${i})">${i}</button>`;
            }
            paginationHtml += '</div>';
            document.getElementById('teacherStudentSearchButtons').innerHTML = paginationHtml;
            document.getElementById('teacherStudentSearchInfo').innerHTML = `Page ${data.page} of ${data.total_pages}`;
            paginationDiv.classList.remove('hidden');
        } else {
            paginationDiv.classList.add('hidden');
        }
    }

    function changeTeacherStudentPage(page) {
        currentStudentSearchPage = page;
        performTeacherStudentSearch();
    }

    function selectTeacherStudent(id, name) {
        const tbody = document.getElementById('teacherPerformanceTableBody');
        tbody.innerHTML = '<tr><td colspan="2" class="p-3 text-center">Loading...</td></tr>';

        fetch(`php/teacher_actions.php?action=get_student_grades&student_id=${id}`)
            .then(response => parseJsonResponse(response))
            .then(data => {
                tbody.innerHTML = '';
                if (data.length > 0) {
                    data.forEach(rec => {
                        tbody.innerHTML += `
                            <tr class="border-b">
                                <td class="p-3">${escapeHtml(rec.subject_name)}</td>
                                <td class="p-3 font-bold text-center">${rec.grade}</td>
                            </tr>`;
                    });
                } else {
                    tbody.innerHTML = '<tr><td colspan="2" class="p-3 text-center">No grades recorded for this student.</td></tr>';
                }
            })
            .catch(error => {
                tbody.innerHTML = '<tr><td colspan="2" class="p-3 text-center text-red-500">Error loading grades.</td></tr>';
            });

        closeTeacherStudentSearchModal();
    }

    function clearTeacherStudentFilter() {
        document.getElementById('clearTeacherStudentFilterBtn').classList.add('hidden');
        document.getElementById('teacherStudentFilterLabel').textContent = '';
        filterTeacherPerformanceByStudent(null, null);
    }

    function filterTeacherPerformanceByStudent(studentId, studentName) {
        const table = document.getElementById('teacherPerformanceTable');
        const rows = table.querySelectorAll('tbody tr');
        const clearBtn = document.getElementById('clearTeacherStudentFilterBtn');
        const filterLabel = document.getElementById('teacherStudentFilterLabel');
        const cards = document.querySelectorAll('.teacher-student-card');
        
        if (!studentId) {
            // Show all rows and hide all single-student cards
            rows.forEach(row => row.style.display = '');
            cards.forEach(card => card.style.display = 'none');
            clearBtn.classList.add('hidden');
            filterLabel.textContent = '';
            teacherSelectedStudentId = null;
            return;
        }
        
        teacherSelectedStudentId = studentId;
        rows.forEach(row => {
            const studentCell = row.cells[0];
            if (studentCell && studentCell.textContent.trim() === studentName) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
        
        // Show corresponding extracurricular and disciplinary cards
        cards.forEach(card => {
            if (card.getAttribute('data-student-id') === String(studentId)) {
                card.style.display = 'block';
            } else {
                card.style.display = 'none';
            }
        });
        
        clearBtn.classList.remove('hidden');
        filterLabel.textContent = `Showing: ${studentName}`;
    }

    function updateTeacherStudentFilterSections() {
        const grade = document.getElementById('teacherStudentFilterGrade').value;
        const sectionSelect = document.getElementById('teacherStudentFilterSection');
        
        if (!grade) {
            sectionSelect.innerHTML = '<option value="">All Sections</option>';
            return;
        }
        
        const gradeSections = {
            'Grade 7': ['Love', 'Joy'],
            'Grade 8': ['Patience', 'Peace'],
            'Grade 9': ['Goodness', 'Kindness'],
            'Grade 10': ['Gentleness', 'Faithfulness'],
            'Grade 11': ['Self-Control', 'Honesty'],
            'Grade 12': ['Humility', 'Meekness']
        };
        const sections = gradeSections[grade] || [];
        let html = '<option value="">All Sections</option>';
        sections.forEach(section => {
            html += `<option value="${section}">${section}</option>`;
        });
        sectionSelect.innerHTML = html;
    }

    // ==================== STUDENT SELECT MODAL FUNCTIONS ====================
    function openStudentSelectModal() {
        document.getElementById('studentSelectModal').style.display = 'flex';
        filterStudentsForSelect();
    }

    function closeStudentSelectModal() {
        document.getElementById('studentSelectModal').style.display = 'none';
    }

    function filterStudentsForSelect() {
        const search = document.getElementById('studentSearchInput').value;
        const grade = document.getElementById('studentFilterGrade').value;
        const section = document.getElementById('studentFilterSection').value;
        const perPageSelection = document.getElementById('studentResultsPerPage').value;
        const customPerPage = parseInt(document.getElementById('studentCustomPerPage')?.value || '', 10);
        let perPage = parseInt(perPageSelection, 10);
        if (perPageSelection === 'custom') {
            perPage = Number.isFinite(customPerPage) && customPerPage > 0 ? customPerPage : 10;
        }
        perPage = Math.min(100, Math.max(1, perPage || 10));
        
        const resultsDiv = document.getElementById('studentSelectResults');
        resultsDiv.innerHTML = '<div class="text-center p-10 text-gray-500">Loading students...</div>';
        
        const formData = new FormData();
        formData.append('action', 'search_students');
        formData.append('search', search);
        formData.append('grade_filter', grade);
        formData.append('section_filter', section);
        formData.append('per_page', String(perPage));
        formData.append('page', '1');
        
        fetch(window.location.href, { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayStudentSelectResults(data);
                } else {
                    const msg = data && data.message ? String(data.message) : 'Error loading students';
                    resultsDiv.innerHTML = '<div class="text-center p-10 text-red-500">Error loading students<br><span class="text-xs text-red-400 break-words">' + msg.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</span></div>';
                }
            })
            .catch(error => {
                console.error(error);
                resultsDiv.innerHTML = '<div class="text-center p-10 text-red-500">Error loading students<br><span class="text-xs text-red-400 break-words">' + (error?.message ? String(error.message) : 'Network error') + '</span></div>';
            });
    }

    function displayStudentSelectResults(data) {
        const resultsDiv = document.getElementById('studentSelectResults');
        if (data.students.length === 0) {
            resultsDiv.innerHTML = '<div class="text-center p-10 text-gray-500">No students found</div>';
            return;
        }
        
        let html = '';
        data.students.forEach(student => {
            html += `
                <div class="student-item p-4 border-b border-gray-200 hover:bg-gray-50 cursor-pointer flex flex-col sm:flex-row sm:justify-between sm:items-center" onclick="selectStudent(${student.id}, '${student.full_name.replace(/'/g, "\\'")}')">
                    <div>
                        <div class="font-semibold text-[#0a2d63]">${student.full_name}</div>
                        <div class="text-sm text-gray-600 break-all">${student.email}</div>
                    </div>
                    <div class="text-sm text-gray-500 mt-1 sm:mt-0 font-medium">Grade ${student.grade_level} - ${student.section}</div>
                </div>
            `;
        });
        resultsDiv.innerHTML = html;
    }

    function selectStudent(id, name) {
        document.getElementById('studentSelect').value = id;
        document.getElementById('selectedStudentName').value = name;
        closeStudentSelectModal();

        // Auto-fill fee totals in Payables Calculator (if present)
        const tuitionFeeInput = document.getElementById('tuitionFee');
        const downPaymentInput = document.getElementById('downPayment');
        if (tuitionFeeInput || downPaymentInput) {
            fetch('php/get_student_payables.php?student_id=' + id)
                .then(parseJsonResponse)
                .then(data => {
                    const t = data?.totals || null;
                    if (!t) return;
                    if (tuitionFeeInput) {
                        tuitionFeeInput.value = (parseFloat(t.fee_total || 0) || 0).toFixed(2);
                    }
                    if (downPaymentInput) {
                        downPaymentInput.value = (parseFloat(t.downpayment_total || 0) || 0).toFixed(2);
                    }
                })
                .catch(err => console.error('Auto-fill fee totals failed:', err));
        }
    }

    function updateStudentFilterSections() {
        const grade = document.getElementById('studentFilterGrade').value;
        const sectionSelect = document.getElementById('studentFilterSection');
        
        if (!grade) {
            sectionSelect.innerHTML = '<option value="">All Sections</option>';
            return;
        }
        
        // Get sections for this grade
        const gradeSections = {
            'Grade 7': ['Love', 'Joy'],
            'Grade 8': ['Patience', 'Peace'],
            'Grade 9': ['Goodness', 'Kindness'],
            'Grade 10': ['Gentleness', 'Faithfulness'],
            'Grade 11': ['Self-Control', 'Honesty'],
            'Grade 12': ['Humility', 'Meekness']
        };
        const sections = gradeSections[grade] || [];
        let html = '<option value="">All Sections</option>';
        sections.forEach(section => {
            html += `<option value="${section}">${section}</option>`;
        });
        sectionSelect.innerHTML = html;
    }

    function toggleStudentCustomPerPage() {
        const select = document.getElementById('studentResultsPerPage');
        const customContainer = document.getElementById('studentCustomPerPageContainer');
        
        if (select.value === 'custom') {
            customContainer.classList.remove('hidden');
        } else {
            customContainer.classList.add('hidden');
        }
    }

    function applyStudentCustomPerPage() {
        const customInput = document.getElementById('studentCustomPerPage');
        const select = document.getElementById('studentResultsPerPage');
        
        if (customInput.value && customInput.value > 0) {
            select.value = 'custom';
            filterStudentsForSelect();
        }
    }

    // ==================== PAYMENT ENROLLEE BROWSE MODAL ====================
    function openPaymentEnrolleeBrowseModal() {
        const modal = document.getElementById('paymentEnrolleeSelectModal');
        if (!modal) return;
        modal.style.display = 'flex';
        const input = document.getElementById('paymentEnrolleeSearchInput');
        if (input) input.value = '';
        const results = document.getElementById('paymentEnrolleeSelectResults');
        if (results) results.innerHTML = '<div class="text-center p-10 text-gray-500">Loading enrollees...</div>';

        fetch('php/get_pending_enrollees.php')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success && Array.isArray(data.enrollments)) {
                    window.paymentEnrolleesCache = data.enrollments.map(function(e) {
                        return {
                            id: String(e.id),
                            name: String(e.full_name || '').trim(),
                            email: String(e.email || '').trim(),
                            grade: String(e.grade_level || ''),
                            downpayment_total: parseFloat(e.downpayment_total || 0)
                        };
                    }).filter(function(e) { return e.name !== ''; });
                    filterPaymentEnrolleesInModal();
                } else {
                    window.paymentEnrolleesCache = [];
                    if (results) results.innerHTML = '<div class="text-center p-10 text-gray-500">No enrollees found.</div>';
                }
            })
            .catch(err => {
                console.error(err);
                window.paymentEnrolleesCache = [];
                if (results) results.innerHTML = '<div class="text-center p-10 text-red-500">Error loading enrollees</div>';
            });
    }

    function closePaymentEnrolleeBrowseModal() {
        const modal = document.getElementById('paymentEnrolleeSelectModal');
        if (modal) modal.style.display = 'none';
    }

    function filterPaymentEnrolleesInModal() {
        const query = (document.getElementById('paymentEnrolleeSearchInput')?.value || '').trim().toLowerCase();
        const grade = document.getElementById('paymentEnrolleeFilterGrade')?.value || '';
        const results = document.getElementById('paymentEnrolleeSelectResults');
        if (!results) return;
        
        let filtered = window.paymentEnrolleesCache || [];
        if (query) {
            filtered = filtered.filter(e => e.name.toLowerCase().includes(query) || e.email.toLowerCase().includes(query));
        }
        if (grade) {
            filtered = filtered.filter(e => e.grade === grade);
        }

        if (filtered.length === 0) {
            results.innerHTML = '<div class="text-center p-10 text-gray-500">No enrollees found.</div>';
            return;
        }

        let html = '';
        filtered.forEach(e => {
            const dp = e.downpayment_total.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            html += `
                <div class="student-item p-4 border-b border-gray-200 hover:bg-gray-50 cursor-pointer flex flex-col sm:flex-row sm:justify-between sm:items-center" onclick="selectPaymentEnrollee('${e.id}', '${e.name.replace(/'/g, "\\'")}')">
                    <div>
                        <div class="font-semibold text-[#0a2d63]">${e.name}</div>
                        <div class="text-sm text-gray-600">Grade: ${e.grade || '-'}</div>
                    </div>
                    <div class="text-sm text-gray-500 mt-1 sm:mt-0 font-medium">Downpayment: ₱${dp}</div>
                </div>
            `;
        });
        results.innerHTML = html;
    }

    function selectPaymentEnrollee(id, name) {
        setPaymentEnrolleeSelection({ id, name });
        closePaymentEnrolleeBrowseModal();
    }

    // ==================== PAYMENT STUDENT BROWSE MODAL ====================
    function openPaymentStudentBrowseModal() {
        const modal = document.getElementById('paymentStudentSelectModal');
        if (!modal) return;
        modal.style.display = 'flex';
        const input = document.getElementById('paymentStudentSearchInput');
        if (input) input.value = '';
        const results = document.getElementById('paymentStudentSelectResults');
        if (results) results.innerHTML = '<div class="text-center p-10 text-gray-500">Loading students...</div>';

        fetch('php/get_users.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({})
        })
            .then(r => r.json())
            .then(data => {
                if (data.success && Array.isArray(data.users)) {
                    window.paymentStudentsCache = data.users
                        .filter(u => u.role === 'student')
                        .map(u => ({
                            id: String(u.id),
                            name: String(u.full_name || '').trim(),
                            email: String(u.email || ''),
                            grade_level: String(u.grade_level || ''),
                            section: String(u.section || ''),
                        }))
                        .filter(s => s.name !== '');
                    filterPaymentStudentsInModal();
                } else {
                    window.paymentStudentsCache = [];
                    if (results) results.innerHTML = '<div class="text-center p-10 text-gray-500">No students found.</div>';
                }
            })
            .catch(err => {
                console.error(err);
                window.paymentStudentsCache = [];
                if (results) results.innerHTML = '<div class="text-center p-10 text-red-500">Error loading students</div>';
            });
    }

    function closePaymentStudentBrowseModal() {
        const modal = document.getElementById('paymentStudentSelectModal');
        if (modal) modal.style.display = 'none';
    }

    function updatePaymentStudentSections() {
        const grade = document.getElementById('paymentStudentFilterGrade')?.value;
        const sectionSelect = document.getElementById('paymentStudentFilterSection');
        if (!sectionSelect) return;
        
        if (!grade) {
            sectionSelect.innerHTML = '<option value="">All Sections</option>';
            return;
        }
        
        const gradeSections = {
            'Grade 7': ['Love', 'Joy'],
            'Grade 8': ['Patience', 'Peace'],
            'Grade 9': ['Goodness', 'Kindness'],
            'Grade 10': ['Gentleness', 'Faithfulness'],
            'Grade 11': ['Self-Control', 'Honesty'],
            'Grade 12': ['Humility', 'Meekness']
        };
        const sections = gradeSections[grade] || [];
        let html = '<option value="">All Sections</option>';
        sections.forEach(section => {
            html += `<option value="${section}">${section}</option>`;
        });
        sectionSelect.innerHTML = html;
    }

    function filterPaymentStudentsInModal() {
        const query = (document.getElementById('paymentStudentSearchInput')?.value || '').trim().toLowerCase();
        const grade = document.getElementById('paymentStudentFilterGrade')?.value || '';
        const section = document.getElementById('paymentStudentFilterSection')?.value || '';
        const results = document.getElementById('paymentStudentSelectResults');
        if (!results) return;
        
        let filtered = window.paymentStudentsCache || [];
        if (query) {
            filtered = filtered.filter(s => s.name.toLowerCase().includes(query) || s.email.toLowerCase().includes(query));
        }
        if (grade) {
            filtered = filtered.filter(s => s.grade_level === grade);
        }
        if (section) {
            filtered = filtered.filter(s => s.section === section);
        }

        if (filtered.length === 0) {
            results.innerHTML = '<div class="text-center p-10 text-gray-500">No students found.</div>';
            return;
        }

        let html = '';
        filtered.forEach(s => {
            html += `
                <div class="student-item p-4 border-b border-gray-200 hover:bg-gray-50 cursor-pointer flex flex-col sm:flex-row sm:justify-between sm:items-center" onclick="selectPaymentStudent('${s.id}', '${s.name.replace(/'/g, "\\'")}')">
                    <div>
                        <div class="font-semibold text-[#0a2d63]">${s.name}</div>
                        <div class="text-sm text-gray-600 break-all">${s.email}</div>
                    </div>
                    <div class="text-sm text-gray-500 mt-1 sm:mt-0 font-medium">Grade ${s.grade_level} - ${s.section}</div>
                </div>
            `;
        });
        results.innerHTML = html;
    }

    function selectPaymentStudent(id, name) {
        setPaymentStudentSelection({ id, name });
        closePaymentStudentBrowseModal();
    }
</script>

    <!-- ========== ADD ACTIVITY MODAL ========== -->
    <div id="addActivityModal" class="modal-overlay fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[1050] p-4" style="display:none;">
        <div class="bg-white rounded-xl w-full max-w-sm shadow-2xl">
            <div class="p-5 border-b border-gray-200 flex justify-between items-center">
                <h3 class="text-lg font-bold text-[#0a2d63]">Add Activity Column</h3>
                <button onclick="document.getElementById('addActivityModal').style.display='none'" class="text-2xl text-gray-400 hover:text-gray-700 leading-none">×</button>
            </div>
            <div class="p-5 space-y-2">
                <p class="text-sm text-gray-500 mb-4">Choose the type of activity to add a scoring column for the current semester.</p>
                <button onclick="addActivityColumn('quiz')"       class="w-full text-left px-4 py-3 rounded-lg border border-gray-200 hover:bg-blue-50 hover:border-blue-300 transition flex items-center gap-3">
                    <span class="w-8 h-8 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center font-bold text-sm">Q</span>
                    <div><div class="font-semibold text-gray-800">Quiz</div><div class="text-xs text-gray-500">Part of 30% group (Quiz + Essay + Recitation)</div></div>
                </button>
                <button onclick="addActivityColumn('essay')"      class="w-full text-left px-4 py-3 rounded-lg border border-gray-200 hover:bg-purple-50 hover:border-purple-300 transition flex items-center gap-3">
                    <span class="w-8 h-8 rounded-full bg-purple-100 text-purple-700 flex items-center justify-center font-bold text-sm">E</span>
                    <div><div class="font-semibold text-gray-800">Essay</div><div class="text-xs text-gray-500">Part of 30% group (Quiz + Essay + Recitation)</div></div>
                </button>
                <button onclick="addActivityColumn('recitation')" class="w-full text-left px-4 py-3 rounded-lg border border-gray-200 hover:bg-green-50 hover:border-green-300 transition flex items-center gap-3">
                    <span class="w-8 h-8 rounded-full bg-green-100 text-green-700 flex items-center justify-center font-bold text-sm">R</span>
                    <div><div class="font-semibold text-gray-800">Recitation</div><div class="text-xs text-gray-500">Part of 30% group (Quiz + Essay + Recitation)</div></div>
                </button>
                <button onclick="addActivityColumn('periodic')"   class="w-full text-left px-4 py-3 rounded-lg border border-gray-200 hover:bg-orange-50 hover:border-orange-300 transition flex items-center gap-3">
                    <span class="w-8 h-8 rounded-full bg-orange-100 text-orange-700 flex items-center justify-center font-bold text-sm">P</span>
                    <div><div class="font-semibold text-gray-800">Periodic Test</div><div class="text-xs text-gray-500">40% of semester grade</div></div>
                </button>
            </div>
        </div>
    </div>

    <!-- ========== SUBMIT TO REGISTRAR MODAL ========== -->
    <div id="submitGradesModal" class="modal-overlay fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[1050] p-4" style="display:none;">
        <div class="bg-white rounded-xl w-full max-w-md shadow-2xl">
            <div class="p-5 border-b border-gray-200 flex justify-between items-center">
                <h3 class="text-lg font-bold text-[#0a2d63]">Submit Grades to Registrar?</h3>
                <button onclick="document.getElementById('submitGradesModal').style.display='none'" class="text-2xl text-gray-400 hover:text-gray-700 leading-none">×</button>
            </div>
            <div class="p-5 space-y-4">
                <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 text-sm text-yellow-800">
                    <strong>⚠ Important:</strong> Once submitted, grades for this semester will be locked and cannot be edited. Make sure all scores are correct before submitting.
                </div>
                <p class="text-sm text-gray-600" id="submitGradesModalDesc">Submit grades for <strong id="submitModalSubjectName"></strong> — <strong id="submitModalSemName"></strong> to the registrar?</p>
                <div class="flex flex-col sm:flex-row gap-3 justify-end mt-2">
                    <button onclick="document.getElementById('submitGradesModal').style.display='none'" class="px-5 py-2 rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-50 transition font-medium w-full sm:w-auto">Not Now</button>
                    <button onclick="submitGradesToRegistrar()" class="px-5 py-2 rounded-lg bg-[#0a2d63] text-white hover:bg-[#08306b] transition font-semibold w-full sm:w-auto">Yes, Submit</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ========== GRADE SUBMISSION DETAILS MODAL ========== -->
    <div id="gradeSubmissionDetailsModal" class="modal-overlay fixed inset-0 bg-black bg-opacity-50 flex items-start justify-center z-[1050] p-4 overflow-y-auto" style="display:none;">
        <div class="bg-white rounded-xl w-full max-w-4xl shadow-2xl mt-4 mb-8">
            <div class="p-5 border-b border-gray-200 bg-[#0a2d63] rounded-t-xl flex justify-between items-center">
                <div>
                    <h3 class="text-lg font-bold text-white" id="gsdModalTitle">Grade Sheet</h3>
                    <p class="text-blue-200 text-xs mt-0.5" id="gsdModalSubtitle"></p>
                </div>
                <button onclick="document.getElementById('gradeSubmissionDetailsModal').style.display='none'" class="text-2xl text-white hover:text-gray-200 leading-none">×</button>
            </div>
            <div class="p-6" id="gradeSubmissionDetailsContent">
                <div class="text-center py-10 text-gray-400">Loading...</div>
            </div>
        </div>
    </div>

    <div id="changePasswordModal" class="modal-overlay fixed inset-0 bg-black bg-opacity-50 items-center justify-center z-[1000] p-4" style="display: none;">
        <div class="modal-container bg-white rounded-lg w-full max-w-md shadow-xl flex flex-col">
            <div class="modal-header p-4 border-b border-gray-200 bg-gray-50 rounded-t-lg flex justify-between items-center">
                <h3 class="text-lg font-semibold text-[#0a2d63]">Change Password</h3>
                <button class="modal-close text-2xl text-gray-600 hover:text-gray-800 w-8 h-8 flex items-center justify-center rounded hover:bg-gray-200 transition" onclick="closeChangePasswordModal()">×</button>
            </div>
            <div class="modal-body p-5">
                <form id="changePasswordForm" onsubmit="event.preventDefault(); submitChangePassword();" class="space-y-4">
                    <div>
                        <label class="block mb-1 font-medium text-gray-700">Old Password</label>
                        <input type="password" id="changeOldPassword" class="w-full p-2 border border-gray-300 rounded" required>
                    </div>
                    <div>
                        <label class="block mb-1 font-medium text-gray-700">New Password</label>
                        <input type="password" id="changeNewPassword" class="w-full p-2 border border-gray-300 rounded" required>
                    </div>
                    <div>
                        <label class="block mb-1 font-medium text-gray-700">Re-enter New Password</label>
                        <input type="password" id="changeConfirmPassword" class="w-full p-2 border border-gray-300 rounded" required>
                    </div>
                    <div id="changePasswordError" class="text-red-600 text-sm"></div>
                    <button type="submit" class="w-full bg-blue-600 text-white px-4 py-2 rounded font-medium hover:bg-blue-700 transition">Update Password</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Announcement Modal -->
    <div id="announcementModal" class="modal-overlay fixed inset-0 bg-black bg-opacity-50 items-center justify-center z-[1000] p-4" style="display: none;">
        <div class="modal-container bg-white rounded-lg w-full max-w-lg shadow-xl flex flex-col">
            <div class="modal-header p-4 border-b border-gray-200 bg-gray-50 rounded-t-lg flex justify-between items-center">
                <h3 class="text-lg font-semibold text-[#0a2d63]" id="announcementModalTitle">Create Announcement</h3>
                <button class="modal-close text-2xl text-gray-600 hover:text-gray-800 w-8 h-8 flex items-center justify-center rounded hover:bg-gray-200 transition" onclick="closeAnnouncementModal()">×</button>
            </div>
            <div class="modal-body p-5">
                <form id="announcementForm" onsubmit="event.preventDefault(); submitAnnouncementForm();" class="space-y-4">
                    <input type="hidden" id="announcementFormId">
                    <div>
                        <label class="block mb-1 font-medium text-gray-700">Title <span class="text-red-500">*</span></label>
                        <input type="text" id="announcementFormTitle" class="w-full p-2 border border-gray-300 rounded" required>
                    </div>
                    <div>
                        <label class="block mb-1 font-medium text-gray-700">Content <span class="text-red-500">*</span></label>
                        <textarea id="announcementFormContent" rows="4" class="w-full p-2 border border-gray-300 rounded" required></textarea>
                    </div>
                    <div>
                        <label class="block mb-1 font-medium text-gray-700">Event Date <span class="text-gray-400 text-xs">(optional - fill if this is an Event)</span></label>
                        <input type="date" id="announcementFormEventDate" class="w-full p-2 border border-gray-300 rounded">
                    </div>
                    <div>
                        <label class="block mb-1 font-medium text-gray-700">Location <span class="text-gray-400 text-xs">(optional)</span></label>
                        <input type="text" id="announcementFormLocation" class="w-full p-2 border border-gray-300 rounded">
                    </div>
                    <div>
                        <label class="block mb-1 font-medium text-gray-700">Responsible Department <span class="text-gray-400 text-xs">(optional)</span></label>
                        <input type="text" id="announcementFormDept" class="w-full p-2 border border-gray-300 rounded">
                    </div>
                    <div id="announcementFormError" class="text-red-600 text-sm"></div>
                    <div class="flex justify-end gap-2">
                        <button type="button" onclick="closeAnnouncementModal()" class="bg-gray-200 text-gray-700 px-4 py-2 rounded font-medium hover:bg-gray-300 transition">Cancel</button>
                        <button type="submit" class="bg-[#0a2d63] text-white px-4 py-2 rounded font-medium hover:bg-[#08306b] transition">Save Announcement</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Session Validation: Check if user has been deactivated -->
    <script>
        // Check user status on page load
        function validateUserStatus() {
            fetch('php/check_user_status.php')
                .then(r => r.json())
                .then(data => {
                    if (!data.success) {
                        if (data.action === 'logout') {
                            alert(data.message || 'Your account has been deactivated. You are being logged out.');
                            window.location.href = 'php/logout.php?reason=inactive';
                        }
                    }
                })
                .catch(err => {
                    // Network error, don't logout - just log it
                    console.error('Status check failed:', err);
                });
        }

        // Check on page load
        validateUserStatus();

        // Check every 30 seconds in the background
        setInterval(validateUserStatus, 30000);
    </script>

<?php if (isset($_SESSION['require_password_change']) && $_SESSION['require_password_change'] === true): ?>
    <div class="fixed inset-0 bg-black bg-opacity-70 z-[9999] flex items-center justify-center pointer-events-auto backdrop-blur-sm" id="forcePasswordModal">
        <div class="bg-white rounded-lg p-6 max-w-sm w-full mx-4 shadow-2xl relative">
            <h2 class="text-2xl justify-center flex font-bold text-[#0a2d63] mb-2">Welcome!</h2>
            <p class="text-gray-600 mb-4 text-sm text-center">For your security, please change your default password to continue.</p>
            
            <form id="forcePasswordForm" onsubmit="event.preventDefault(); submitForcePassword();">
                <div class="mb-4">
                    <label class="block text-gray-700 font-medium mb-2 text-sm">New Password</label>
                    <input type="password" id="forceNewPassword" required class="w-full border border-gray-300 rounded p-2 focus:ring-[#0a2d63] focus:border-[#0a2d63] outline-none transition" />
                    <ul class="text-xs text-gray-500 mt-2 list-disc pl-5">
                        <li id="reqUpper" class="text-red-500 transition-colors">At least 1 uppercase letter</li>
                        <li id="reqSpecial" class="text-red-500 transition-colors">At least 1 special character</li>
                        <li id="reqLength" class="text-red-500 transition-colors">At least 6 characters long</li>
                    </ul>
                </div>
                <div class="mt-6 flex flex-col gap-2">
                    <button type="submit" id="forceSubmitBtn" class="bg-[#0a2d63] text-white px-4 py-2 rounded font-semibold disabled:opacity-50 transition w-full" disabled>Change Password</button>
                    <div id="forcePasswordError" class="text-red-500 text-sm text-center font-medium empty:hidden"></div>
                </div>
            </form>
        </div>
    </div>
    <script>
        const passInput = document.getElementById('forceNewPassword');
        const submitBtn = document.getElementById('forceSubmitBtn');
        const reqUpper = document.getElementById('reqUpper');
        const reqSpecial = document.getElementById('reqSpecial');
        const reqLength = document.getElementById('reqLength');

        passInput.addEventListener('input', function() {
            const val = this.value;
            let okUpper = /[A-Z]/.test(val);
            let okSpec = /[^a-zA-Z0-9]/.test(val);
            let okLen = val.length >= 6;

            reqUpper.className = 'transition-colors ' + (okUpper ? 'text-green-600' : 'text-red-500');
            reqSpecial.className = 'transition-colors ' + (okSpec ? 'text-green-600' : 'text-red-500');
            reqLength.className = 'transition-colors ' + (okLen ? 'text-green-600' : 'text-red-500');

            submitBtn.disabled = !(okUpper && okSpec && okLen);
        });

        function submitForcePassword() {
            const newPassword = passInput.value;
            const formData = new FormData();
            formData.append('new_password', newPassword);

            fetch('php/change_first_login_password.php', {
                method: 'POST',
                body: formData
            }).then(r => r.json()).then(data => {
                if (data.success) {
                    document.getElementById('forcePasswordModal').style.display = 'none';
                    alert('Password successfully updated!');
                } else {
                    document.getElementById('forcePasswordError').innerText = data.message || 'Error updating password';
                }
            }).catch(err => {
                document.getElementById('forcePasswordError').innerText = 'Network error. Try again.';
            });
        }
    </script>
<?php endif; ?>

<script>
    // Grants/Discounts Manager
    let currentDiscounts = [];

    async function loadDiscountsManager() {
        const container = document.getElementById('discountsManagerContainer');
        const status = document.getElementById('discountsManagerStatus');
        if (!container) return;
        
        container.innerHTML = '<div class="text-gray-500 p-4 text-center">Loading...</div>';
        status.innerHTML = '';
        
        try {
            const response = await fetch('php/get_discounts.php');
            const result = await response.json();
            
            if (result.success) {
                currentDiscounts = result.discounts;
                let html = '<table class="w-full text-left border-collapse bg-white shadow-sm rounded overflow-hidden">';
                html += '<thead class="bg-[#0a2d63] text-white"><tr><th class="p-3 border-b">Grant/Discount Name</th><th class="p-3 border-b">Amount (₱)</th><th class="p-3 border-b text-center">Actions</th></tr></thead>';
                html += '<tbody>';
                
                result.discounts.forEach(d => {
                    html += `<tr class="border-b hover:bg-gray-50">
                        <td class="p-3 align-middle font-medium text-gray-700">${escapeHtml(d.name)}</td>
                        <td class="p-3 align-middle">
                            <input type="number" id="discount_amount_${d.id}" value="${parseFloat(d.amount).toFixed(2)}" step="0.01" min="0" class="w-full md:w-32 p-2 border border-gray-300 rounded focus:ring-2 focus:ring-[#0a2d63] outline-none">
                        </td>
                        <td class="p-3 align-middle text-center">
                            <button type="button" onclick="deleteDiscount(${d.id}, '${escapeHtml(d.name)}')" class="text-red-600 hover:text-red-800 font-bold text-lg" title="Delete">&times;</button>
                        </td>
                    </tr>`;
                });
                
                html += '</tbody></table>';
                container.innerHTML = html;
            } else {
                container.innerHTML = `<div class="text-red-500 p-4 text-center">${result.message || 'Error loading discounts'}</div>`;
            }
        } catch (err) {
            console.error(err);
            container.innerHTML = '<div class="text-red-500 p-4 text-center">Network error loading discounts</div>';
        }
    }

    async function saveDiscountsManager() {
        const status = document.getElementById('discountsManagerStatus');
        status.innerHTML = '<span class="text-blue-500">Saving...</span>';
        
        const dataToSave = currentDiscounts.map(d => {
            const input = document.getElementById(`discount_amount_${d.id}`);
            return {
                id: d.id,
                amount: input ? parseFloat(input.value) : 0
            };
        });
        
        try {
            const response = await fetch('php/update_discounts.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(dataToSave)
            });
            const result = await response.json();
            
            if (result.success) {
                status.innerHTML = '<span class="text-green-600 font-medium">✓ Saved successfully</span>';
                setTimeout(() => { status.innerHTML = ''; }, 3000);
                loadDiscountsDropdown(); // Refresh dropdown in payment processing
            } else {
                status.innerHTML = `<span class="text-red-500">Error: ${result.message}</span>`;
            }
        } catch (err) {
            console.error(err);
            status.innerHTML = '<span class="text-red-500">Network error saving discounts</span>';
        }
    }

    async function addNewDiscount() {
        const name = prompt('Enter the name of the new grant/discount:');
        if (!name || name.trim() === '') return;
        
        const status = document.getElementById('discountsManagerStatus');
        status.innerHTML = '<span class="text-blue-500">Adding...</span>';
        
        try {
            const response = await fetch('php/update_discounts.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'add', name: name.trim() })
            });
            const result = await response.json();
            
            if (result.success) {
                status.innerHTML = '<span class="text-green-600 font-medium">✓ Added successfully</span>';
                setTimeout(() => { status.innerHTML = ''; }, 3000);
                loadDiscountsManager();
                loadDiscountsDropdown();
            } else {
                status.innerHTML = `<span class="text-red-500">Error: ${result.message}</span>`;
            }
        } catch (err) {
            console.error(err);
            status.innerHTML = '<span class="text-red-500">Network error adding discount</span>';
        }
    }

    async function deleteDiscount(id, name) {
        if (!confirm(`Are you sure you want to delete "${name}"? This action cannot be undone.`)) return;
        
        const status = document.getElementById('discountsManagerStatus');
        status.innerHTML = '<span class="text-blue-500">Deleting...</span>';
        
        try {
            const response = await fetch('php/update_discounts.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'delete', id: id })
            });
            const result = await response.json();
            
            if (result.success) {
                status.innerHTML = '<span class="text-green-600 font-medium">✓ Deleted successfully</span>';
                setTimeout(() => { status.innerHTML = ''; }, 3000);
                loadDiscountsManager();
                loadDiscountsDropdown();
            } else {
                status.innerHTML = `<span class="text-red-500">Error: ${result.message}</span>`;
            }
        } catch (err) {
            console.error(err);
            status.innerHTML = '<span class="text-red-500">Network error deleting discount</span>';
        }
    }

    async function loadDiscountsDropdown() {
        const select = document.getElementById('paymentDiscounts');
        if (!select) return;
        
        try {
            const response = await fetch('php/get_discounts.php');
            const result = await response.json();
            
            if (result.success) {
                const currentValue = select.value;
                let html = '<option value="">-- No Discount --</option>';
                result.discounts.forEach(d => {
                    const val = parseFloat(d.amount);
                    if (val > 0) {
                        html += `<option value="${d.id}" data-amount="${val}">${escapeHtml(d.name)} (₱${val.toLocaleString('en-PH', {minimumFractionDigits:2})})</option>`;
                    }
                });
                select.innerHTML = html;
                if (currentValue && select.querySelector(`option[value="${currentValue}"]`)) {
                    select.value = currentValue;
                }
            }
        } catch (err) {
            console.error(err);
        }
    }

    // Call loaders on init if admin/cashier
    document.addEventListener('DOMContentLoaded', () => {
        if (document.getElementById('discountsManagerContainer')) {
            loadDiscountsManager();
        }
        if (document.getElementById('paymentDiscounts')) {
            loadDiscountsDropdown();
        }
    });
</script>

<!-- Sections & Subjects Management JS -->
<script>
    let adminSubjectsData = [];

    function loadAdminSubjects() {
        const container = document.getElementById('adminSubjectsList');
        if (!container) return;
        container.innerHTML = '<div class="text-center p-10 text-gray-500">Loading subjects...</div>';
        const grade = document.getElementById('adminSubjectGradeFilter') ? document.getElementById('adminSubjectGradeFilter').value : '';
        const section = document.getElementById('adminSubjectSectionFilter') ? document.getElementById('adminSubjectSectionFilter').value : '';
        let url = 'php/get_subjects.php?';
        if (grade) url += 'grade_level=' + encodeURIComponent(grade) + '&';
        if (section) url += 'section=' + encodeURIComponent(section);
        fetch(url)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    adminSubjectsData = data.subjects || [];
                    renderAdminSubjectsTable(adminSubjectsData);
                    if (data.sections) {
                        const sf = document.getElementById('adminSubjectSectionFilter');
                        if (sf) {
                            const cv = sf.value;
                            sf.innerHTML = '<option value="">All Sections</option>';
                            data.sections.forEach(function(s) {
                                sf.innerHTML += '<option value="' + escapeHtml(s) + '">' + escapeHtml(s) + '</option>';
                            });
                            if (cv) sf.value = cv;
                        }
                    }
                } else {
                    container.innerHTML = '<div class="text-center p-10 text-red-500">' + (data.message || 'Error loading subjects') + '</div>';
                }
            })
            .catch(function(err) {
                console.error(err);
                container.innerHTML = '<div class="text-center p-10 text-red-500">Network error loading subjects</div>';
            });
    }

    function renderAdminSubjectsTable(subjects) {
        const container = document.getElementById('adminSubjectsList');
        if (!container) return;
        if (!subjects || subjects.length === 0) {
            container.innerHTML = '<div class="text-center p-10 text-gray-500">No subjects found. Click "Add Subject" to create one.</div>';
            return;
        }
        let html = '<div class="overflow-x-auto"><table class="w-full border-collapse bg-white min-w-[800px]">';
        html += '<thead class="bg-[#0a2d63] text-white"><tr>';
        html += '<th class="p-3 text-left font-semibold text-sm">Subject Name</th>';
        html += '<th class="p-3 text-left font-semibold text-sm">Code</th>';
        html += '<th class="p-3 text-left font-semibold text-sm">Grade</th>';
        html += '<th class="p-3 text-left font-semibold text-sm">Section</th>';
        html += '<th class="p-3 text-left font-semibold text-sm">Day</th>';
        html += '<th class="p-3 text-left font-semibold text-sm">Time</th>';
        html += '<th class="p-3 text-left font-semibold text-sm">Semester</th>';
        html += '<th class="p-3 text-center font-semibold text-sm">Actions</th>';
        html += '</tr></thead><tbody>';
        subjects.forEach(function(s) {
            var st = s.start_time ? formatSubjectTime12(s.start_time) : '';
            var et = s.end_time ? formatSubjectTime12(s.end_time) : '';
            var td = st && et ? st + ' - ' + et : (st || et || '-');
            html += '<tr class="border-b border-gray-200 hover:bg-gray-50">';
            html += '<td class="p-3 font-medium text-gray-800">' + escapeHtml(s.subject_name || '') + '</td>';
            html += '<td class="p-3 text-gray-600">' + escapeHtml(s.subject_code || '-') + '</td>';
            html += '<td class="p-3 text-gray-700">' + escapeHtml(s.grade_level || '-') + '</td>';
            html += '<td class="p-3 text-gray-700">' + escapeHtml(s.section || '-') + '</td>';
            html += '<td class="p-3 text-gray-700">' + escapeHtml(s.day_of_week || '-') + '</td>';
            html += '<td class="p-3 text-gray-700">' + td + '</td>';
            html += '<td class="p-3 text-gray-700">' + escapeHtml(s.semester || '-') + '</td>';
            html += '<td class="p-3 text-center"><div class="flex gap-2 justify-center">';
            html += '<button onclick="editSubject(' + s.id + ')" class="px-3 py-1 rounded bg-[#0a2d63] text-white text-xs font-medium hover:bg-[#08306b] transition">Edit</button>';
            html += '<button onclick="deleteSubject(' + s.id + ')" class="px-3 py-1 rounded bg-[#ef4444] text-white text-xs font-medium hover:bg-[#dc2626] transition">Delete</button>';
            html += '</div></td></tr>';
        });
        html += '</tbody></table></div>';
        container.innerHTML = html;
    }

    function formatSubjectTime12(timeStr) {
        if (!timeStr) return '';
        var parts = timeStr.split(':');
        if (parts.length < 2) return timeStr;
        var h = parseInt(parts[0], 10);
        var m = parts[1];
        var ampm = h >= 12 ? 'PM' : 'AM';
        h = h % 12 || 12;
        return h + ':' + m + ' ' + ampm;
    }

    function openAddSubjectModal() {
        document.getElementById('subjectEditId').value = '0';
        document.getElementById('subjectModalTitle').textContent = 'Add Subject';
        document.getElementById('subjectForm').reset();
        document.getElementById('subjectFormError').textContent = '';
        document.getElementById('addSubjectModal').style.display = 'flex';
    }

    function closeAddSubjectModal() {
        document.getElementById('addSubjectModal').style.display = 'none';
        document.getElementById('subjectForm').reset();
        document.getElementById('subjectFormError').textContent = '';
    }

    function editSubject(id) {
        var subject = adminSubjectsData.find(function(s) { return s.id == id; });
        if (!subject) return;
        document.getElementById('subjectEditId').value = subject.id;
        document.getElementById('subjectModalTitle').textContent = 'Edit Subject';
        document.getElementById('subjectNameInput').value = subject.subject_name || '';
        document.getElementById('subjectCodeInput').value = subject.subject_code || '';
        document.getElementById('subjectGradeInput').value = subject.grade_level || '';
        document.getElementById('subjectSectionInput').value = subject.section || '';
        document.getElementById('subjectDayInput').value = subject.day_of_week || '';
        document.getElementById('subjectSemesterInput').value = subject.semester || '';
        var st = subject.start_time ? subject.start_time.substring(0, 5) : '';
        var et = subject.end_time ? subject.end_time.substring(0, 5) : '';
        document.getElementById('subjectStartTimeInput').value = st;
        document.getElementById('subjectEndTimeInput').value = et;
        document.getElementById('subjectFormError').textContent = '';
        document.getElementById('addSubjectModal').style.display = 'flex';
    }

    function submitSubjectForm() {
        var errEl = document.getElementById('subjectFormError');
        errEl.textContent = '';
        var fd = new FormData();
        fd.append('id', document.getElementById('subjectEditId').value);
        fd.append('subject_name', document.getElementById('subjectNameInput').value.trim());
        fd.append('subject_code', document.getElementById('subjectCodeInput').value.trim());
        fd.append('grade_level', document.getElementById('subjectGradeInput').value);
        fd.append('section', document.getElementById('subjectSectionInput').value.trim());
        fd.append('day_of_week', document.getElementById('subjectDayInput').value);
        fd.append('start_time', document.getElementById('subjectStartTimeInput').value);
        fd.append('end_time', document.getElementById('subjectEndTimeInput').value);
        fd.append('semester', document.getElementById('subjectSemesterInput').value.trim());
        fetch('php/save_subject.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) { closeAddSubjectModal(); loadAdminSubjects(); }
                else { errEl.textContent = data.message || 'Error saving subject'; }
            })
            .catch(function(err) { console.error(err); errEl.textContent = 'Network error. Please try again.'; });
    }

    function deleteSubject(id) {
        var subject = adminSubjectsData.find(function(s) { return s.id == id; });
        var name = subject ? subject.subject_name : 'this subject';
        if (!confirm('Delete "' + name + '"? This removes related grades and teacher assignments.')) return;
        var fd = new FormData();
        fd.append('id', id);
        fetch('php/delete_subject.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) { loadAdminSubjects(); }
                else { alert(data.message || 'Error deleting subject'); }
            })
            .catch(function(err) { console.error(err); alert('Network error deleting subject'); });
    }
</script>

<!-- ========== SECTION MODAL FUNCTIONS ========== -->
<script>
    function openAddSectionModal() {
        document.getElementById('sectionNameInput').value = '';
        document.getElementById('sectionGradeInput').value = '';
        document.getElementById('sectionFormError').textContent = '';
        document.getElementById('addSectionModal').style.display = 'flex';
    }

    function closeAddSectionModal() {
        document.getElementById('addSectionModal').style.display = 'none';
        document.getElementById('sectionFormError').textContent = '';
    }

    function submitSectionForm() {
        var errEl = document.getElementById('sectionFormError');
        errEl.textContent = '';
        var name = document.getElementById('sectionNameInput').value.trim();
        var grade = document.getElementById('sectionGradeInput').value;
        if (!name) { errEl.textContent = 'Section name is required.'; return; }
        if (!grade) { errEl.textContent = 'Grade level is required.'; return; }
        var fd = new FormData();
        fd.append('section_name', name);
        fd.append('grade_level', grade);
        fetch('php/add_section.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    closeAddSectionModal();
                    loadAdminSubjects();
                    alert('Section "' + name + '" added successfully!');
                } else { errEl.textContent = data.message || 'Error adding section'; }
            })
            .catch(function(err) { console.error(err); errEl.textContent = 'Network error. Please try again.'; });
    }
</script>

<!-- ========== GRADE ENCODING ENGINE ========== -->
<script>
// ---- State ----
let _geSubjectId = null, _geSection = null, _geGradeLevel = null, _geSubjectName = '';
let _geSemester = 1;
// activities per semester: { 1:[{type,label,max},...], 2:[...], 3:[...] }
let _geActivities = { 1:[], 2:[], 3:[] };
let _geStudents = []; // [{id, full_name, sem1, sem2, sem3}]
let _geInputCache = {}; // {studentId_actIdx: value} — preserves inputs across re-renders

const SEM_LABELS = { 1:'1st Semester', 2:'2nd Semester', 3:'3rd Semester' };
const ACT_COLORS = { quiz:'blue', essay:'purple', recitation:'green', periodic:'orange' };
const ACT_LABELS = { quiz:'Quiz', essay:'Essay', recitation:'Recitation', periodic:'Periodic Test' };

// XSS-safe HTML escaping
function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

// ---- Section → Subject filter ----
function filterGradeSubjectsBySection() {
    const sel = document.getElementById('gradeSectionSelect');
    if (!sel) return;
    const section = sel.value;
    const gradeOpt = sel.options[sel.selectedIndex];
    const grade = gradeOpt ? gradeOpt.dataset.grade : '';
    const subjectSel = document.getElementById('gradeSubjectSelect');
    if (!subjectSel) return;
    Array.from(subjectSel.options).forEach(opt => {
        if (!opt.value) { opt.style.display = ''; return; }
        const match = (!section || opt.dataset.section === section) &&
                      (!grade   || opt.dataset.grade   === grade);
        opt.style.display = match ? '' : 'none';
    });
    // reset subject pick
    subjectSel.value = '';
    // auto-select first visible subject
    const first = Array.from(subjectSel.options).find(o => o.value && o.style.display !== 'none');
    if (first) { subjectSel.value = first.value; autoLoadGradeStudents(); }
}

// ---- Auto-load on subject change ----
function autoLoadGradeStudents() {
    const subjectId = document.getElementById('gradeSubjectSelect')?.value;
    if (!subjectId) return;
    loadGradeStudents();
}

// ---- Core loader ----
function loadGradeStudents() {
    const subjectSel = document.getElementById('gradeSubjectSelect');
    const sectionSel = document.getElementById('gradeSectionSelect');
    if (!subjectSel || !sectionSel) return;
    const subjectId = subjectSel.value;
    const sectionOpt = sectionSel.options[sectionSel.selectedIndex];
    const section = sectionSel.value;
    const gradeLevel = sectionOpt ? sectionOpt.dataset.grade : '';
    if (!subjectId || !section || !gradeLevel) return;

    const subjectOpt = subjectSel.options[subjectSel.selectedIndex];
    _geSubjectName = subjectOpt ? subjectOpt.dataset.name : 'Subject';
    _geSubjectId = subjectId;
    _geSection = section;
    _geGradeLevel = gradeLevel;

    const container = document.getElementById('gradeEncodingTableContainer');
    if (container) container.innerHTML = '<p class="text-center py-10 text-gray-400">Loading students…</p>';
    _geInputCache = {}; // clear stale cached inputs

    const fd = new FormData();
    fd.append('action', 'get_grade_students');
    fd.append('subject_id', subjectId);
    fd.append('section', section);
    fd.append('grade_level', gradeLevel);

    fetch('php/teacher_actions.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (!data.success) { if (container) container.innerHTML = `<p class="text-red-500 text-center py-10">${data.message||'Error loading students'}</p>`; return; }
            if (data.debug) console.log('Grade Encoding Debug:', data.debug);
            _geStudents = data.students || [];
            _geSemester = data.current_semester || 1;
            // Try restore session activities
            const saved = loadGradeSession();
            if (saved) { _geActivities = saved.activities || {1:[],2:[],3:[]}; _geSemester = saved.semester || _geSemester; }
            else { _geActivities = {1:[],2:[],3:[]}; }
            // Pre-populate activities from existing DB data for current sem
            if (_geStudents.length && _geActivities[_geSemester].length === 0) {
                _geActivities = _geActivities || {1:[],2:[],3:[]};
                const sample = _geStudents[0]['sem'+_geSemester];
                if (sample) {
                    if (sample.quiz_score !== null)       _geActivities[_geSemester].push({type:'quiz',      label:ACT_LABELS.quiz,      max: sample.quiz_max||100});
                    if (sample.essay_score !== null)      _geActivities[_geSemester].push({type:'essay',     label:ACT_LABELS.essay,     max: sample.essay_max||100});
                    if (sample.recitation_score !== null) _geActivities[_geSemester].push({type:'recitation',label:ACT_LABELS.recitation, max: sample.recitation_max||100});
                    if (sample.periodic_test_score!==null)_geActivities[_geSemester].push({type:'periodic',  label:ACT_LABELS.periodic,  max: sample.periodic_test_max||100});
                }
            }
            // Show UI
            document.getElementById('addActivityBtn')?.classList.remove('hidden');
            document.getElementById('semesterTabsRow')?.classList.remove('hidden');
            document.getElementById('gradeSaveRow')?.classList.remove('hidden');
            document.getElementById('gradeEncodingSubjectLabel').textContent = _geSubjectName + ' — ' + gradeLevel + ' ' + section;
            updateSemTabs();
            renderGradeTable();
        })
        .catch(err => { console.error(err); if (container) container.innerHTML = '<p class="text-red-500 text-center py-10">Network error</p>'; });
}

// ---- Semester tab switch ----
function switchSemester(sem) {
    snapshotInputCache(); // save current inputs before switching
    _geSemester = sem;
    _geInputCache = {}; // clear cache — new semester has different activity indices
    updateSemTabs();
    renderGradeTable();
    saveGradeSession();
}

function updateSemTabs() {
    document.querySelectorAll('.sem-tab').forEach(btn => {
        const active = parseInt(btn.dataset.sem) === _geSemester;
        btn.className = 'sem-tab px-5 py-2 text-sm font-semibold rounded-t-lg border border-b-0 border-gray-200 transition ' +
            (active ? 'bg-[#0a2d63] text-white' : 'bg-white text-gray-600 hover:text-[#0a2d63]');
    });
}

// ---- Render the grade table ----
function renderGradeTable() {
    const container = document.getElementById('gradeEncodingTableContainer');
    if (!container) return;
    if (!_geStudents.length) {
        container.innerHTML = '<p class="text-center py-14 text-gray-400">No students found for ' + (_geGradeLevel || 'this grade') + ' — ' + (_geSection || 'this section') + '.</p>';
        return;
    }
    const acts = _geActivities[_geSemester] || [];
    const semData = 'sem' + _geSemester;
    const isSubmitted = _geStudents.some(s => s[semData] && s[semData].is_submitted == 1);

    let html = '<div class="overflow-x-auto">';
    if (isSubmitted) html += '<div class="mb-3 px-4 py-2 bg-green-50 border border-green-200 rounded-lg text-green-800 text-sm font-medium">✓ Grades for ' + SEM_LABELS[_geSemester] + ' have been submitted to the Registrar and are locked.</div>';
    html += '<table class="min-w-full border-collapse text-sm">';

    // Header
    html += '<thead><tr class="bg-[#0a2d63] text-white">';
    html += '<th class="p-3 text-left font-semibold sticky left-0 bg-[#0a2d63] z-10 min-w-[180px]">#  Student Name</th>';
    acts.forEach((act, i) => {
        const colorMap = {quiz:'blue',essay:'purple',recitation:'green',periodic:'orange'};
        const c = colorMap[act.type] || 'gray';
        html += `<th class="p-3 text-center font-semibold min-w-[120px] relative">
            ${isSubmitted ? '' : `<button onclick="removeActivityColumn(${i})" class="absolute top-1 right-1 w-4 h-4 rounded-full bg-white bg-opacity-20 hover:bg-opacity-40 text-white text-[10px] leading-none flex items-center justify-center transition" title="Remove this activity">✕</button>`}
            <div class="text-xs font-bold uppercase tracking-wide mt-2">${act.label}</div>
            <div class="mt-1 flex items-center justify-center gap-1 text-xs font-normal opacity-80">
                /<input type="number" min="1" max="999" value="${act.max}" class="w-14 text-center bg-white bg-opacity-20 border border-white border-opacity-30 rounded px-1 py-0.5 text-white"
                    ${isSubmitted?'disabled':''} onchange="updateActMax(${i},this.value)" />pts
            </div>
        </th>`;
    });
    html += '<th class="p-3 text-center font-semibold min-w-[100px]">Grade</th>';
    html += '<th class="p-3 text-center font-semibold min-w-[90px]">Prev Sems</th>';
    html += '</tr></thead><tbody>';

    _geStudents.forEach((s, idx) => {
        const dbRow = s[semData] || {};
        const scoreMap = { quiz: dbRow.quiz_score, essay: dbRow.essay_score, recitation: dbRow.recitation_score, periodic: dbRow.periodic_test_score };
        const calcGrade = dbRow.calculated_grade;
        const rowBg = idx % 2 === 0 ? 'bg-white' : 'bg-gray-50';

        html += `<tr class="${rowBg} border-b border-gray-200 hover:bg-blue-50 transition">`;
        html += `<td class="p-3 font-medium text-gray-800 sticky left-0 ${rowBg} z-10">${idx+1}. ${escapeHtml(s.full_name)}</td>`;
        acts.forEach((act, i) => {
            // Prefer cached user input, then DB data
            const cacheKey = s.id + '_' + i;
            let val = '';
            if (_geInputCache[cacheKey] !== undefined) { val = _geInputCache[cacheKey]; }
            else if (scoreMap[act.type] !== null && scoreMap[act.type] !== undefined) { val = scoreMap[act.type]; }
            html += `<td class="p-2 text-center">
                <input type="number" min="0" max="${act.max}" step="0.5"
                    class="grade-score-input w-20 text-center border border-gray-300 rounded px-2 py-1.5 focus:ring-2 focus:ring-blue-400 outline-none"
                    data-student-id="${s.id}" data-act-idx="${i}" data-act-type="${act.type}"
                    value="${val}" ${isSubmitted?'disabled':''}
                    oninput="clampAndCalc(this, ${act.max}, ${s.id})" />
            </td>`;
        });
        const gradeDisp = calcGrade !== null && calcGrade !== undefined ? parseFloat(calcGrade).toFixed(2) : '—';
        const gradeColor = calcGrade === null ? 'text-gray-400' : (parseFloat(calcGrade) >= 75 ? 'text-green-700' : 'text-red-600');
        html += `<td class="p-3 text-center font-bold ${gradeColor}" id="grade-cell-${s.id}">${gradeDisp}</td>`;

        // Previous semesters
        let prevHtml = '';
        [1,2,3].forEach(ps => {
            if (ps !== _geSemester) {
                const pg = s['sem'+ps]?.calculated_grade;
                if (pg !== null && pg !== undefined) prevHtml += `<div class="text-xs text-gray-500">S${ps}: ${parseFloat(pg).toFixed(1)}</div>`;
            }
        });
        html += `<td class="p-3 text-center">${prevHtml||'<span class="text-xs text-gray-300">—</span>'}</td>`;
        html += '</tr>';
    });
    html += '</tbody></table></div>';
    container.innerHTML = html;
    updateGradeStats();
}

// ---- Activity operations ----
function openAddActivityModal() {
    document.getElementById('addActivityModal').style.display = 'flex';
}

function addActivityColumn(type) {
    document.getElementById('addActivityModal').style.display = 'none';
    // Save current input values before re-render
    snapshotInputCache();
    const acts = _geActivities[_geSemester];
    const count = acts.filter(a => a.type === type).length + 1;
    acts.push({ type, label: ACT_LABELS[type] + ' ' + count, max: 100 });
    renderGradeTable();
    saveGradeSession();
}

function removeActivityColumn(index) {
    if (!confirm('Remove this activity column? This will delete all grades entered for it.')) return;
    snapshotInputCache();
    const acts = _geActivities[_geSemester];
    if (index >= 0 && index < acts.length) {
        acts.splice(index, 1);
        const newCache = {};
        for (const key in _geInputCache) {
            const parts = key.split('_');
            const sId = parts[0];
            const actIdx = parseInt(parts[1]);
            if (actIdx < index) {
                newCache[key] = _geInputCache[key];
            } else if (actIdx > index) {
                newCache[`${sId}_${actIdx - 1}`] = _geInputCache[key];
            }
        }
        _geInputCache = newCache;
        renderGradeTable();
        saveGradeSession();
    }
}

// Clamp input value to 0–max, then recalculate
function clampAndCalc(input, max, studentId) {
    let v = parseFloat(input.value);
    if (isNaN(v)) { calcRowGrade(studentId); return; }
    if (v < 0)   { input.value = 0;   v = 0; }
    if (v > max) { input.value = max;  v = max; }
    calcRowGrade(studentId);
}

// Snapshot all current input values into cache
function snapshotInputCache() {
    _geInputCache = {};
    document.querySelectorAll('.grade-score-input').forEach(inp => {
        const key = inp.dataset.studentId + '_' + inp.dataset.actIdx;
        _geInputCache[key] = inp.value;
    });
}

function updateActMax(actIdx, val) {
    const acts = _geActivities[_geSemester];
    if (acts[actIdx]) { acts[actIdx].max = parseInt(val) || 100; calcAllGrades(); saveGradeSession(); }
}

// ---- Per-row grade calculation ----
function calcRowGrade(studentId) {
    const acts = _geActivities[_geSemester];
    const groups = { quiz:[], essay:[], recitation:[], periodic:[] };
    acts.forEach((act, i) => {
        const inp = document.querySelector(`.grade-score-input[data-student-id="${studentId}"][data-act-idx="${i}"]`);
        if (inp && inp.value !== '') {
            const score = parseFloat(inp.value);
            const pct = score / (act.max || 100) * 100;
            groups[act.type].push(pct);
        }
    });
    const groupComp = [];
    ['quiz','essay','recitation'].forEach(t => { if (groups[t].length) groupComp.push(groups[t].reduce((a,b)=>a+b,0)/groups[t].length); });
    let ws = 0, tw = 0;
    if (groupComp.length) { ws += (groupComp.reduce((a,b)=>a+b,0)/groupComp.length)*0.30; tw += 0.30; }
    if (groups.periodic.length) { ws += (groups.periodic.reduce((a,b)=>a+b,0)/groups.periodic.length)*0.40; tw += 0.40; }
    const grade = tw > 0 ? (ws/tw).toFixed(2) : null;
    const cell = document.getElementById('grade-cell-' + studentId);
    if (cell) {
        cell.textContent = grade !== null ? grade : '—';
        cell.className = 'p-3 text-center font-bold ' + (grade === null ? 'text-gray-400' : (parseFloat(grade) >= 75 ? 'text-green-700' : 'text-red-600'));
    }
    updateGradeStats();
    saveGradeSession();
}

function calcAllGrades() { _geStudents.forEach(s => calcRowGrade(s.id)); }

// ---- Stats ----
function updateGradeStats() {
    const grades = [];
    _geStudents.forEach(s => {
        const cell = document.getElementById('grade-cell-' + s.id);
        if (cell && cell.textContent !== '—') grades.push(parseFloat(cell.textContent));
    });
    const avg = grades.length ? (grades.reduce((a,b)=>a+b,0)/grades.length).toFixed(2) : '—';
    const pass = grades.length ? Math.round(grades.filter(g=>g>=75).length/grades.length*100)+'%' : '—';
    const high = grades.length ? Math.max(...grades).toFixed(2) : '—';
    const ca = document.getElementById('classAvg'); if(ca) ca.textContent = avg;
    const pr = document.getElementById('passRate'); if(pr) pr.textContent = pass;
    const hg = document.getElementById('highGrade'); if(hg) hg.textContent = high;
}

// ---- Session saver ----
function saveGradeSession() {
    if (!_geSubjectId) return;
    const scores = {};
    document.querySelectorAll('.grade-score-input').forEach(inp => {
        const sid = inp.dataset.studentId;
        const idx = inp.dataset.actIdx;
        if (!scores[sid]) scores[sid] = {};
        scores[sid][idx] = inp.value;
    });
    localStorage.setItem('baa_grade_' + _geSubjectId, JSON.stringify({
        subjectId: _geSubjectId, section: _geSection, gradeLevel: _geGradeLevel,
        semester: _geSemester, activities: _geActivities, scores
    }));
}

function loadGradeSession() {
    if (!_geSubjectId) return null;
    try { return JSON.parse(localStorage.getItem('baa_grade_' + _geSubjectId)); } catch { return null; }
}

// ---- Save All Grades ----
function saveAllGrades() {
    if (!_geSubjectId || !_geStudents.length) { alert('No students loaded.'); return; }
    const acts = _geActivities[_geSemester];
    const gradesPayload = _geStudents.map(s => {
        const groups = { quiz:[], essay:[], recitation:[], periodic:[] };
        const maxGroups = { quiz:[], essay:[], recitation:[], periodic:[] };
        acts.forEach((act, i) => {
            const inp = document.querySelector(`.grade-score-input[data-student-id="${s.id}"][data-act-idx="${i}"]`);
            if (inp && inp.value !== '') {
                groups[act.type].push(parseFloat(inp.value));
                maxGroups[act.type].push(act.max || 100);
            }
        });
        const avg = (arr) => arr.length ? arr.reduce((a,b)=>a+b,0)/arr.length : null;
        return {
            student_id: s.id,
            quiz: avg(groups.quiz), essay: avg(groups.essay),
            recitation: avg(groups.recitation), periodic: avg(groups.periodic)
        };
    });
    const maxPoints = {};
    const types = ['quiz','essay','recitation','periodic'];
    types.forEach(t => {
        const typeActs = acts.filter(a=>a.type===t);
        maxPoints[t] = typeActs.length ? typeActs.reduce((a,b)=>a+b.max,0)/typeActs.length : 100;
    });

    const fd = new FormData();
    fd.append('action', 'save_grades');
    fd.append('data', JSON.stringify({ subject_id: _geSubjectId, semester: _geSemester, grades: gradesPayload, max_points: maxPoints }));

    fetch('php/teacher_actions.php', { method:'POST', body:fd })
        .then(r=>r.json())
        .then(d => {
            if (d.success) {
                showToast('Grades saved successfully!', 'green');
                saveGradeSession();
                // Show submit modal
                setTimeout(() => {
                    document.getElementById('submitModalSubjectName').textContent = _geSubjectName;
                    document.getElementById('submitModalSemName').textContent = SEM_LABELS[_geSemester];
                    document.getElementById('submitGradesModal').style.display = 'flex';
                }, 600);
            } else { showToast('Error: ' + (d.message||'Could not save'), 'red'); }
        })
        .catch(()=>showToast('Network error saving grades','red'));
}

// ---- Submit to Registrar ----
function submitGradesToRegistrar() {
    document.getElementById('submitGradesModal').style.display = 'none';
    const fd = new FormData();
    fd.append('action', 'submit_grades_to_registrar');
    fd.append('subject_id', _geSubjectId);
    fd.append('semester', _geSemester);
    fetch('php/teacher_actions.php', { method:'POST', body:fd })
        .then(r=>r.json())
        .then(d => {
            if (d.success) { showToast('Grades submitted to Registrar!', 'green'); loadGradeStudents(); }
            else { showToast('Error: '+(d.message||'Could not submit'), 'red'); }
        })
        .catch(()=>showToast('Network error submitting grades','red'));
}

// ---- Toast helper ----
function showToast(msg, color='green') {
    const toast = document.createElement('div');
    toast.className = `fixed bottom-5 right-5 z-[9999] px-6 py-3 rounded-lg shadow-lg text-white font-semibold text-sm transition-all ${color==='green'?'bg-green-600':'bg-red-600'}`;
    toast.textContent = msg;
    document.body.appendChild(toast);
    setTimeout(()=>{ toast.style.opacity='0'; setTimeout(()=>toast.remove(),400); },3000);
}

// ---- Admin/Registrar: Load Grade Submissions ----
async function loadGradeSubmissions() {
    const container = document.getElementById('gradeSubmissionsTableContainer');
    if (!container) return;
    container.innerHTML = '<div class="text-center py-10 text-gray-400">Loading submissions…</div>';
    const semester = document.getElementById('gsFilterSemester')?.value || '';
    const grade    = document.getElementById('gsFilterGrade')?.value    || '';
    let url = 'php/get_grade_submissions.php?';
    if (semester) url += 'semester='+encodeURIComponent(semester)+'&';
    if (grade)    url += 'grade_level='+encodeURIComponent(grade)+'&';
    try {
        const r = await fetch(url);
        const data = await r.json();
        if (!data.success) { container.innerHTML = `<div class="text-red-500 text-center py-10">${data.message||'Error'}</div>`; return; }
        if (data.debug) console.log('Grade Submissions Debug:', data.debug);
        const rows = data.submissions || [];
        if (!rows.length) { 
            container.innerHTML = `<div class="text-gray-400 text-center py-10">No submitted grades found${grade?' for Grade '+grade:''}${semester?' for '+{1:'1st',2:'2nd',3:'3rd'}[semester]+' Semester':''}.</div>`; 
            return; 
        }
        let html = '<div class="overflow-x-auto"><table class="min-w-full border-collapse"><thead class="bg-[#0a2d63] text-white"><tr>';
        ['Teacher','Subject','Grade Level','Section','Semester','Students','Actions'].forEach(h => {
            html += `<th class="p-3 text-left text-sm font-semibold whitespace-nowrap">${h}</th>`;
        });
        html += '</tr></thead><tbody>';
        rows.forEach((row,i) => {
            const semName = {1:'1st',2:'2nd',3:'3rd'}[row.semester]||row.semester;
            html += `<tr class="${i%2?'bg-gray-50':'bg-white'} border-b border-gray-200 hover:bg-blue-50 transition">
                <td class="p-3 text-gray-800 font-medium whitespace-nowrap">${escapeHtml(row.teacher_name||'—')}</td>
                <td class="p-3 text-gray-700">${escapeHtml(row.subject_name||'—')}</td>
                <td class="p-3 text-gray-700 whitespace-nowrap">${escapeHtml(row.grade_level||'—')}</td>
                <td class="p-3 text-gray-700">${escapeHtml(row.section||'—')}</td>
                <td class="p-3"><span class="px-2 py-1 rounded-full text-xs font-semibold bg-blue-100 text-blue-800 whitespace-nowrap">${semName} Semester</span></td>
                <td class="p-3 text-center text-gray-700">${row.student_count||0}</td>
                <td class="p-3">
                    <div class="flex gap-2 items-center">
                        <button onclick="viewGradeSubmissionDetails(${row.subject_id},${row.semester})"
                            class="px-3 py-1.5 rounded bg-[#0a2d63] text-white text-xs font-medium hover:bg-[#08306b] transition whitespace-nowrap">
                            View Details
                        </button>
                        <button onclick="unlockGradeSubmission(${row.subject_id},${row.semester})"
                            class="px-3 py-1.5 rounded bg-[#0a2d63] text-white text-xs font-medium hover:bg-[#08306b] transition whitespace-nowrap"
                            title="Allow teacher to re-edit this submission">
                            Edit
                        </button>
                    </div>
                </td>
            </tr>`;
        });
        html += '</tbody></table></div>';
        container.innerHTML = html;
    } catch(e) { container.innerHTML = '<div class="text-red-500 text-center py-10">Network error</div>'; }
}

async function unlockGradeSubmission(subjectId, semester) {
    const semName = {1:'1st',2:'2nd',3:'3rd'}[semester]||semester;
    if (!confirm(`Allow the teacher to re-edit the ${semName} Semester grades for this subject? The submission will be unlocked until they save again.`)) return;
    try {
        const fd = new FormData();
        fd.append('action', 'unlock_submission');
        fd.append('subject_id', subjectId);
        fd.append('semester', semester);
        const r = await fetch('php/get_grade_submissions.php', { method: 'POST', body: fd });
        const data = await r.json();
        if (data.success) {
            alert('✓ Submission unlocked. The teacher can now re-edit and re-submit.');
            loadGradeSubmissions();
        } else {
            alert('Error: ' + (data.message || 'Could not unlock submission'));
        }
    } catch(e) { alert('Network error'); }
}

// ---- Admin/Registrar: View submission details ----
async function viewGradeSubmissionDetails(subjectId, semester) {
    const modal = document.getElementById('gradeSubmissionDetailsModal');
    const content = document.getElementById('gradeSubmissionDetailsContent');
    if (!modal || !content) return;
    modal.style.display = 'flex';
    content.innerHTML = '<div class="text-center py-10 text-gray-400">Loading grade sheet…</div>';
    try {
        const r = await fetch(`php/get_grade_submission_details.php?subject_id=${subjectId}&semester=${semester}`);
        const data = await r.json();
        if (!data.success) { content.innerHTML = `<div class="text-red-500 text-center py-6">${data.message||'Error'}</div>`; return; }
        const subj = data.subject || {};
        const grades = data.grades || [];
        const semName = {1:'1st',2:'2nd',3:'3rd'}[semester]||semester;
        document.getElementById('gsdModalTitle').textContent = `${subj.subject_name||'Subject'} — ${semName} Semester`;
        document.getElementById('gsdModalSubtitle').textContent = `${subj.grade_level||''} ${subj.section||''} | ${grades.length} student(s)`;
        let html = '<div class="overflow-x-auto"><table class="min-w-full border-collapse text-sm"><thead class="bg-gray-100"><tr>';
        ['#','Student Name','LRN','Quiz','Essay','Recitation','Periodic Test','Final Grade'].forEach(h=>{
            html+=`<th class="p-3 text-left font-semibold text-gray-700 border-b border-gray-200">${h}</th>`;
        });
        html+='</tr></thead><tbody>';
        grades.forEach((g,i)=>{
            const grade = g.calculated_grade !== null ? parseFloat(g.calculated_grade).toFixed(2) : '—';
            const gradeColor = g.calculated_grade===null?'text-gray-400':(parseFloat(g.calculated_grade)>=75?'text-green-700 font-bold':'text-red-600 font-bold');
            const fmt = v => v!==null&&v!==undefined&&v!=='' ? parseFloat(v).toFixed(1) : '—';
            html+=`<tr class="${i%2?'bg-gray-50':'bg-white'} border-b border-gray-100">
                <td class="p-3 text-gray-500">${i+1}</td>
                <td class="p-3 font-medium text-gray-800">${escapeHtml(g.student_name||'')}</td>
                <td class="p-3 text-gray-600 font-mono text-xs">${escapeHtml(g.lrn||'—')}</td>
                <td class="p-3 text-center">${fmt(g.quiz_score)}</td>
                <td class="p-3 text-center">${fmt(g.essay_score)}</td>
                <td class="p-3 text-center">${fmt(g.recitation_score)}</td>
                <td class="p-3 text-center">${fmt(g.periodic_test_score)}</td>
                <td class="p-3 text-center ${gradeColor}">${grade}</td>
            </tr>`;
        });
        html+='</tbody></table></div>';
        content.innerHTML = html;
    } catch(e) { content.innerHTML = '<div class="text-red-500 text-center py-6">Network error loading details</div>'; }
}

// ========== BOOK MANAGER FUNCTIONS ==========
async function loadBooks() {
    const container = document.getElementById('bookManagerTableContainer');
    if (!container) return;
    container.innerHTML = '<div class="text-center py-10 text-gray-400">Loading books…</div>';
    try {
        const r = await fetch('php/books.php?action=list');
        const data = await r.json();
        if (!data.success) { container.innerHTML = `<div class="text-red-500 text-center py-10">${data.message||'Error'}</div>`; return; }
        const books = data.books || [];
        if (!books.length) { container.innerHTML = '<div class="text-gray-400 text-center py-10">No books added yet. Click "Add Book" to get started.</div>'; return; }
        let html = '<div class="overflow-x-auto"><table class="min-w-full border-collapse"><thead class="bg-[#0a2d63] text-white"><tr>';
        ['Title','Price','Assigned Grades','Actions'].forEach(h => {
            html += `<th class="p-3 text-left text-sm font-semibold whitespace-nowrap">${h}</th>`;
        });
        html += '</tr></thead><tbody>';
        books.forEach((book, i) => {
            const grades = (book.grade_levels || []).join(', ') || '<span class="text-gray-400 italic">None</span>';
            html += `<tr class="${i%2?'bg-gray-50':'bg-white'} border-b border-gray-200 hover:bg-blue-50 transition">
                <td class="p-3 text-gray-800 font-medium">${escapeHtml(book.title)}</td>
                <td class="p-3 text-gray-700">₱${parseFloat(book.price).toLocaleString('en-PH', {minimumFractionDigits:2})}</td>
                <td class="p-3 text-gray-700 text-sm">${grades}</td>
                <td class="p-3">
                    <div class="flex gap-2 items-center">
                        <button onclick="editBook(${book.id})" class="px-3 py-1.5 rounded bg-[#0a2d63] text-white text-xs font-medium hover:bg-[#08306b] transition whitespace-nowrap">Edit</button>
                        <button onclick="deleteBook(${book.id}, '${escapeHtml(book.title).replace(/'/g, "\\'")}')" class="px-3 py-1.5 rounded bg-red-500 text-white text-xs font-medium hover:bg-red-600 transition whitespace-nowrap">Delete</button>
                    </div>
                </td>
            </tr>`;
        });
        html += '</tbody></table></div>';
        container.innerHTML = html;
    } catch(e) { container.innerHTML = '<div class="text-red-500 text-center py-10">Network error</div>'; }
}

function openAddBookModal(editData = null) {
    const modal = document.getElementById('addBookModal');
    const title = document.getElementById('bookModalTitle');
    const form = document.getElementById('bookForm');
    document.getElementById('bookFormError').textContent = '';
    document.getElementById('bookEditId').value = '0';
    document.getElementById('bookTitleInput').value = '';
    document.getElementById('bookPriceInput').value = '';
    document.querySelectorAll('.book-grade-cb').forEach(cb => cb.checked = false);

    if (editData) {
        title.textContent = 'Edit Book';
        document.getElementById('bookEditId').value = editData.id;
        document.getElementById('bookTitleInput').value = editData.title;
        document.getElementById('bookPriceInput').value = editData.price;
        (editData.grade_levels || []).forEach(gl => {
            const cb = document.querySelector(`.book-grade-cb[value="${gl}"]`);
            if (cb) cb.checked = true;
        });
    } else {
        title.textContent = 'Add Book';
    }
    modal.style.display = 'flex';
}

function closeAddBookModal() {
    const modal = document.getElementById('addBookModal');
    if (modal) modal.style.display = 'none';
}

async function editBook(id) {
    try {
        const r = await fetch('php/books.php?action=list');
        const data = await r.json();
        if (!data.success) return;
        const book = (data.books || []).find(b => b.id == id);
        if (book) openAddBookModal(book);
    } catch(e) { alert('Error loading book details'); }
}

async function deleteBook(id, title) {
    if (!confirm(`Delete the book "${title}"? This cannot be undone.`)) return;
    try {
        const fd = new FormData();
        fd.append('action', 'delete');
        fd.append('id', id);
        const r = await fetch('php/books.php', { method: 'POST', body: fd });
        const data = await r.json();
        if (data.success) {
            loadBooks();
        } else {
            alert('Error: ' + (data.message || 'Could not delete book'));
        }
    } catch(e) { alert('Network error'); }
}

async function submitBookForm() {
    const errorEl = document.getElementById('bookFormError');
    errorEl.textContent = '';
    const id = document.getElementById('bookEditId').value;
    const title = document.getElementById('bookTitleInput').value.trim();
    const price = document.getElementById('bookPriceInput').value;
    const gradeLevels = [];
    document.querySelectorAll('.book-grade-cb:checked').forEach(cb => gradeLevels.push(cb.value));

    if (!title) { errorEl.textContent = 'Book title is required.'; return; }
    if (!price || parseFloat(price) < 0) { errorEl.textContent = 'Valid price is required.'; return; }

    try {
        const fd = new FormData();
        fd.append('action', 'save');
        fd.append('id', id);
        fd.append('title', title);
        fd.append('price', price);
        fd.append('grade_levels', JSON.stringify(gradeLevels));
        const r = await fetch('php/books.php', { method: 'POST', body: fd });
        const data = await r.json();
        if (data.success) {
            closeAddBookModal();
            loadBooks();
        } else {
            errorEl.textContent = data.message || 'Error saving book.';
        }
    } catch(e) { errorEl.textContent = 'Network error.'; }
}

// ========== TEACHER CLASS LIST TAB JAVASCRIPT ==========
let teacherClassListSort = 'name';
let teacherClassListPageSize = 10;
let openTeacherClassDetails = new Set();

function loadTeacherClassList() {
    initTeacherClassListFilters();
    applyTeacherClassListFilters();
}

function initTeacherClassListFilters() {
    const gradeCheckboxes = document.getElementById('teacherClassListGradeCheckboxes');
    if (!gradeCheckboxes) return;

    if (gradeCheckboxes.children.length > 0) return; // already initialized

    if (!Array.isArray(window.teacherSections)) return;

    const grades = [...new Set(window.teacherSections.map(sec => sec.grade_level).filter(Boolean))].sort();
    
    gradeCheckboxes.innerHTML = '';
    grades.forEach(grade => {
        const label = document.createElement('label');
        label.className = 'flex items-center gap-2 text-sm text-gray-700 cursor-pointer';
        label.innerHTML = `<input type="checkbox" class="teacher-class-filter-grade-checkbox w-4 h-4" value="${grade}" onchange="updateTeacherClassListFilterSections(); applyTeacherClassListFilters()"> ${grade}`;
        gradeCheckboxes.appendChild(label);
    });

    updateTeacherClassListFilterSections();
}

function updateTeacherClassListFilterSections() {
    const selectedGrades = Array.from(document.querySelectorAll('.teacher-class-filter-grade-checkbox:checked')).map(el => el.value);
    const filterSectionContainer = document.getElementById('teacherClassListSectionContainer');
    const sectionCheckboxes = document.getElementById('teacherClassListSectionCheckboxes');
    if (!filterSectionContainer || !sectionCheckboxes) return;

    if (selectedGrades.length > 0 && Array.isArray(window.teacherSections)) {
        const matchingSections = window.teacherSections.filter(sec => selectedGrades.includes(sec.grade_level));
        const sectionSet = new Set(matchingSections.map(sec => sec.section_name));
        
        if (sectionSet.size > 0) {
            filterSectionContainer.classList.remove('hidden');
            sectionCheckboxes.innerHTML = '';
            Array.from(sectionSet).sort().forEach(section => {
                const label = document.createElement('label');
                label.className = 'flex items-center gap-2 text-sm text-gray-700 cursor-pointer';
                label.innerHTML = `<input type="checkbox" class="teacher-class-filter-section-checkbox w-4 h-4" value="${section}" onchange="applyTeacherClassListFilters()"> ${section}`;
                sectionCheckboxes.appendChild(label);
            });
        } else {
            filterSectionContainer.classList.add('hidden');
            sectionCheckboxes.innerHTML = '';
        }
    } else {
        filterSectionContainer.classList.add('hidden');
        sectionCheckboxes.innerHTML = '';
    }
}

function setTeacherClassListSort(sortBy) {
    teacherClassListSort = sortBy;
    document.querySelectorAll('.teacher-class-sort-option').forEach(opt => opt.classList.remove('active'));
    const sortEl = document.getElementById('teacher-class-sort-' + sortBy);
    if (sortEl) sortEl.classList.add('active');
    applyTeacherClassListFilters();
}

function setTeacherClassListPageSize(value) {
    const size = parseInt(value, 10);
    if (!isNaN(size) && size > 0) {
        teacherClassListPageSize = size;
        applyTeacherClassListFilters();
    }
}

function toggleTeacherClassListDetails(id) {
    const detailsDiv = document.getElementById('teacher-class-details-' + id);
    if (!detailsDiv) return;
    if (detailsDiv.classList.contains('hidden')) {
        detailsDiv.classList.remove('hidden');
        openTeacherClassDetails.add(id);
    } else {
        detailsDiv.classList.add('hidden');
        openTeacherClassDetails.delete(id);
    }
}

function applyTeacherClassListFilters() {
    const listEl = document.getElementById('teacherClassListContainer');
    if (!listEl) return;

    const searchInput = document.getElementById('teacherClassListSearch');
    const searchTerm = (searchInput && searchInput.value ? searchInput.value : '').toLowerCase().trim();

    const filterGrades = Array.from(document.querySelectorAll('.teacher-class-filter-grade-checkbox:checked')).map(el => el.value);
    const filterSections = Array.from(document.querySelectorAll('.teacher-class-filter-section-checkbox:checked')).map(el => el.value);

    let filtered = (teacherHomeStudents || []).filter(student => {
        const nameParts = [student.first_name, student.middle_name, student.last_name, student.suffix].map(p => (p && p.toUpperCase() === 'N/A') ? '' : p).filter(Boolean);
        const fullName = (student.full_name ? student.full_name.replace(/\bN\/A\b/gi, '').replace(/\s+/g, ' ').trim() : nameParts.join(' ')) || 'N/A';
        
        const matchesSearch = searchTerm === '' ||
            fullName.toLowerCase().includes(searchTerm) ||
            (student.username && student.username.toLowerCase().includes(searchTerm)) ||
            (student.email && student.email.toLowerCase().includes(searchTerm)) ||
            (student.lrn && student.lrn.toLowerCase().includes(searchTerm)) ||
            (student.grade_level && student.grade_level.toLowerCase().includes(searchTerm)) ||
            (student.section && student.section.toLowerCase().includes(searchTerm));
        
        if (!matchesSearch) return false;

        if (filterGrades.length > 0 && !filterGrades.includes(student.grade_level)) return false;
        if (filterSections.length > 0 && !filterSections.includes(student.section)) return false;

        return true;
    });

    filtered.sort((a, b) => {
        if (teacherClassListSort === 'name') {
            const nameAParts = [a.first_name, a.middle_name, a.last_name, a.suffix].map(p => (p && p.toUpperCase() === 'N/A') ? '' : p).filter(Boolean);
            const nameA = (a.full_name ? a.full_name.replace(/\bN\/A\b/gi, '').replace(/\s+/g, ' ').trim() : nameAParts.join(' ')) || 'N/A';
            const nameBParts = [b.first_name, b.middle_name, b.last_name, b.suffix].map(p => (p && p.toUpperCase() === 'N/A') ? '' : p).filter(Boolean);
            const nameB = (b.full_name ? b.full_name.replace(/\bN\/A\b/gi, '').replace(/\s+/g, ' ').trim() : nameBParts.join(' ')) || 'N/A';
            return nameA.localeCompare(nameB);
        } else if (teacherClassListSort === 'grade') {
            const gA = a.grade_level || '';
            const gB = b.grade_level || '';
            if (gA !== gB) return gA.localeCompare(gB);
            const sA = a.section || '';
            const sB = b.section || '';
            if (sA !== sB) return sA.localeCompare(sB);
            const nameAParts = [a.first_name, a.middle_name, a.last_name, a.suffix].map(p => (p && p.toUpperCase() === 'N/A') ? '' : p).filter(Boolean);
            const nameA = (a.full_name ? a.full_name.replace(/\bN\/A\b/gi, '').replace(/\s+/g, ' ').trim() : nameAParts.join(' ')) || 'N/A';
            const nameBParts = [b.first_name, b.middle_name, b.last_name, b.suffix].map(p => (p && p.toUpperCase() === 'N/A') ? '' : p).filter(Boolean);
            const nameB = (b.full_name ? b.full_name.replace(/\bN\/A\b/gi, '').replace(/\s+/g, ' ').trim() : nameBParts.join(' ')) || 'N/A';
            return nameA.localeCompare(nameB);
        } else if (teacherClassListSort === 'lrn') {
            const lA = a.lrn || '';
            const lB = b.lrn || '';
            return lA.localeCompare(lB);
        }
        return 0;
    });

    renderTeacherClassList(filtered);
}

function renderTeacherClassList(students) {
    const resultsDiv = document.getElementById('teacherClassListContainer');
    if (!resultsDiv) return;

    if (students.length === 0) {
        resultsDiv.innerHTML = '<div class="text-center text-gray-500 py-10">No students match your filters.</div>';
        return;
    }

    const displayed = students.slice(0, teacherClassListPageSize);
    let html = '';

    displayed.forEach(student => {
        const nameParts = [student.first_name, student.middle_name, student.last_name, student.suffix].map(p => (p && p.toUpperCase() === 'N/A') ? '' : p).filter(Boolean);
        const fullName = (student.full_name ? student.full_name.replace(/\bN\/A\b/gi, '').replace(/\s+/g, ' ').trim() : nameParts.join(' ')) || 'N/A';
        const gs = student.grade_level ? (student.grade_level + (student.section ? ' - ' + student.section : '')) : 'N/A';
        
        const phoneDisp = student.phone
            ? (String(student.phone).startsWith('+63') ? student.phone : '+63' + student.phone)
            : '—';
        
        const isDetailOpen = openTeacherClassDetails.has(student.id);

        html += `
            <div class="border-b border-gray-200 last:border-b-0">
                <div class="p-3 md:p-4 hover:bg-gray-50">
                    <div class="flex items-start gap-2 min-w-0">
                        <button type="button" class="text-[#0a2d63] font-bold px-1 shrink-0" title="Show details" onclick="event.stopPropagation(); toggleTeacherClassListDetails(${student.id})">▾</button>
                        <div class="cursor-pointer flex-1 min-w-0" onclick="toggleTeacherClassListDetails(${student.id})">
                            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-2">
                                <div class="min-w-0">
                                    <div class="font-semibold text-[#0a2d63] truncate">${fullName}</div>
                                    <div class="text-sm text-gray-600 mt-0.5 truncate">${gs}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div id="teacher-class-details-${student.id}" class="${isDetailOpen ? '' : 'hidden'} border-t border-gray-100 bg-gray-50 px-4 py-3 pl-10 text-sm text-gray-700">
                    <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 flex-1">
                            <div><span class="font-semibold">LRN:</span> ${student.lrn || '—'}</div>
                            <div><span class="font-semibold">Username:</span> ${student.username || '—'}</div>
                            <div><span class="font-semibold">Email:</span> ${student.email || '—'}</div>
                            <div><span class="font-semibold">Gender:</span> ${student.gender || '—'}</div>
                            <div><span class="font-semibold">Birthdate:</span> ${student.birthdate || '—'}</div>
                            <div><span class="font-semibold">Phone:</span> ${phoneDisp}</div>
                            <div><span class="font-semibold">Status:</span> ${(student.status == 1) ? 'Active' : 'Inactive'}</div>
                            <div><span class="font-semibold">Joined:</span> ${student.created_at || '—'}</div>
                        </div>
                        <div class="flex justify-end mt-2 md:mt-0">
                            <button onclick="triggerExcelExport(${student.id})" class="bg-green-600 text-white px-3 py-1.5 rounded-lg font-medium text-xs flex items-center gap-1 hover:bg-green-700 transition shadow-sm">
                                <i class="fas fa-file-excel"></i> Export Grades
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
    });

    const summary = `<div class="text-sm text-gray-600 p-3">Showing ${displayed.length} of ${students.length} student${students.length === 1 ? '' : 's'}.</div>`;
    resultsDiv.innerHTML = html + summary;
}

function exportTeacherClassListPDF() {
    const grades = Array.from(document.querySelectorAll('.teacher-class-filter-grade-checkbox:checked')).map(el => el.value);
    const sections = Array.from(document.querySelectorAll('.teacher-class-filter-section-checkbox:checked')).map(el => el.value);
    const search = document.getElementById('teacherClassListSearch')?.value || '';
    const query = '?grades=' + encodeURIComponent(grades.join(',')) + '&sections=' + encodeURIComponent(sections.join(',')) + '&search=' + encodeURIComponent(search) + '&sort=' + encodeURIComponent(teacherClassListSort);
    window.open('php/generate_class_list_pdf.php' + query, '_blank');
}

// ---------- Notifications Module ----------
let notifications = [];
let unreadCount = 0;

async function pollNotifications() {
    try {
        const resp = await fetch('php/get_notifications.php');
        const data = await resp.json();
        if (data.success) {
            notifications = data.notifications;
            unreadCount = data.unread_count;
            updateNotificationBadge();
            if (document.getElementById('notifDropdown').classList.contains('active')) {
                renderNotifications();
            }
        }
    } catch (e) {
        console.error('Notification polling error', e);
    }
}

function updateNotificationBadge() {
    const badge = document.getElementById('notifCount');
    if (unreadCount > 0) {
        badge.innerText = unreadCount > 99 ? '99+' : unreadCount;
        badge.classList.remove('hidden');
    } else {
        badge.classList.add('hidden');
    }
}

function toggleNotifDropdown(e) {
    if (e) {
        e.preventDefault();
        e.stopPropagation();
    }
    const dropdown = document.getElementById('notifDropdown');
    const isActive = dropdown.classList.contains('active');
    
    if (!isActive) {
        renderNotifications();
        dropdown.classList.add('active');
        // Close on click outside
        const closeOnOutside = (event) => {
            if (!event.target.closest('#notifBell')) {
                dropdown.classList.remove('active');
                document.removeEventListener('click', closeOnOutside);
            }
        };
        setTimeout(() => document.addEventListener('click', closeOnOutside), 10);
    } else {
        dropdown.classList.remove('active');
    }
}

function renderNotifications() {
    const list = document.getElementById('notifList');
    if (!list) return;

    if (!notifications || notifications.length === 0) {
        list.innerHTML = '<div class="p-4 text-center text-gray-500 italic">No notifications yet.</div>';
        return;
    }

    list.innerHTML = notifications.map(n => {
        // Ensure link is either a valid string or null (not the string 'null')
        const linkVal = (n.link === 'null' || !n.link) ? null : n.link;
        const linkAttr = linkVal ? `'${linkVal}'` : 'null';
        
        return `
            <div class="notification-item ${n.status === 'unread' ? 'unread' : ''}" onclick="handleNotifClick(${n.id}, ${linkAttr})">
                <div class="flex items-start gap-3">
                    <div class="mt-1">
                        ${getNotifIcon(n.type)}
                    </div>
                    <div class="flex-1">
                        <p class="text-sm font-medium ${n.status === 'unread' ? 'text-gray-900' : 'text-gray-600'}">${n.message}</p>
                        <p class="text-xs text-gray-400 mt-1">${formatTimeAgo(n.created_at)}</p>
                    </div>
                </div>
            </div>
        `;
    }).join('');
}

function getNotifIcon(type) {
    switch(type) {
        case 'enrollment': return '<i class="fas fa-user-plus text-blue-500"></i>';
        case 'document': return '<i class="fas fa-file-alt text-orange-500"></i>';
        case 'grade': return '<i class="fas fa-graduation-cap text-green-500"></i>';
        case 'photo': return '<i class="fas fa-camera text-purple-500"></i>';
        default: return '<i class="fas fa-bell text-gray-500"></i>';
    }
}

function parseNotificationDate(dateStr) {
    if (!dateStr) return null;
    const trimmed = dateStr.trim();

    if (trimmed.endsWith('Z') || /[+-]\d{2}:?\d{2}$/.test(trimmed)) {
        return new Date(trimmed);
    }

    if (trimmed.includes('T')) {
        return new Date(trimmed);
    }

    const [datePart, timePart = '00:00:00'] = trimmed.split(' ');
    const [year, month, day] = datePart.split('-').map(Number);
    const [hour = 0, minute = 0, second = 0] = timePart.split(':').map(Number);
    return new Date(year, month - 1, day, hour, minute, second);
}

function formatTimeAgo(dateStr) {
    const date = parseNotificationDate(dateStr);
    if (!date || isNaN(date.getTime())) return '';

    const now = new Date();
    const diff = Math.floor((now - date) / 1000);

    if (diff < 60) return 'Just now';
    if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
    if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
    if (diff < 604800) return Math.floor(diff / 86400) + 'd ago';
    return date.toLocaleDateString();
}

async function handleNotifClick(id, link) {
    try {
        await fetch('php/mark_notification_read.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'id=' + id
        });
        
        pollNotifications(); // Refresh
        
        if (link) {
            navigateTo(link);
        }
    } catch (e) {
        console.error('Mark read error', e);
    }
}

async function markAllRead(e) {
    if (e) {
        e.preventDefault();
        e.stopPropagation();
    }
    
    if (!confirm('Mark all notifications as read?')) return;
    
    try {
        await fetch('php/mark_notification_read.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'id=all'
        });
        pollNotifications();
    } catch (e) {
        console.error('Mark all read error', e);
    }
}

document.addEventListener('DOMContentLoaded', function() {
    pollNotifications();
    setInterval(pollNotifications, 30000); // Check every 30s
    
    document.getElementById('notifBell').addEventListener('click', toggleNotifDropdown);
});

// ---------- Student Export Module ----------
function openExportStudentModal() {
    document.getElementById('exportStudentModal').classList.remove('hidden');
    document.getElementById('exportStudentModal').classList.add('flex');
}
window.openExportStudentModal = openExportStudentModal;

function closeExportStudentModal() {
    document.getElementById('exportStudentModal').classList.add('hidden');
    document.getElementById('exportStudentModal').classList.remove('flex');
    document.getElementById('exportStudentSearch').value = '';
    document.getElementById('exportStudentResults').innerHTML = '<div class="p-4 text-center text-gray-500">Type above to search students...</div>';
}
window.closeExportStudentModal = closeExportStudentModal;

let exportSearchTimeout;
function searchExportStudents(query) {
    clearTimeout(exportSearchTimeout);
    if (query.trim().length < 2) return;

    exportSearchTimeout = setTimeout(function() {
        const formData = new FormData();
        formData.append('action', 'search_students');
        formData.append('search', query);
        formData.append('per_page', '10');

        fetch('dashboard.php', {
            method: 'POST',
            body: formData
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.success) {
                renderExportResults(data.students);
            }
        })
        .catch(function(err) { console.error(err); });
    }, 300);
}
window.searchExportStudents = searchExportStudents;

function renderExportResults(students) {
    const container = document.getElementById('exportStudentResults');
    if (students.length === 0) {
        container.innerHTML = '<div class="p-4 text-center text-gray-500">No students found.</div>';
        return;
    }

    container.innerHTML = students.map(function(s) {
        return `
        <div class="p-4 border-b border-gray-100 flex justify-between items-center hover:bg-gray-50 transition">
            <div>
                <div class="font-semibold text-gray-800">${s.full_name}</div>
                <div class="text-xs text-gray-500">${s.grade_level} ${s.section ? ' - ' + s.section : ''} | LRN: ${s.lrn || 'N/A'}</div>
            </div>
            <button onclick="triggerExcelExport(${s.id})" class="text-green-600 hover:text-green-800 font-medium text-sm flex items-center gap-1">
                <i class="fas fa-download"></i> Export
            </button>
        </div>
    `;
    }).join('');
}
window.renderExportResults = renderExportResults;

window.triggerExcelExport = function(studentId) {
    window.open(`php/export_grades_spreadsheet.php?student_id=${studentId}`, '_blank');
}

</script>

</body>
</html>