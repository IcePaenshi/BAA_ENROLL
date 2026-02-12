<?php
session_start();
require_once 'php/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$userRole = $_SESSION['role'];
$userId = $_SESSION['user_id'];
$fullName = $_SESSION['full_name'];
$gradeLevel = $_SESSION['grade_level'] ?? '';
$section = $_SESSION['section'] ?? '';
$lrn = $_SESSION['lrn'] ?? '';
$userName = isset($_SESSION['username']) ? $_SESSION['username'] : $fullName;

// Get user-specific data based on role
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Get grades for student from database
if ($userRole == 'student') {
    try {
        $gradesStmt = $pdo->prepare("
            SELECT s.subject_name, g.grade, g.quarter 
            FROM grades g 
            JOIN subjects s ON g.subject_id = s.id 
            WHERE g.student_id = ? 
            ORDER BY s.subject_name, g.quarter
        ");
        $gradesStmt->execute([$userId]);
        $grades = $gradesStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching grades: " . $e->getMessage());
        $grades = [];
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
                description,
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
                    'description' => $subject['description'],
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Baesa Adventist Academy - Dashboard</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        /* ========== CENTERING FIXES ========== */
        .dashboard-main {
            width: 100%;
            max-width: 100%;
            margin: 0;
            padding: 0;
        }

        .dashboard-content {
            display: flex !important;
            justify-content: center !important;
            align-items: flex-start !important;
            width: 100% !important;
            max-width: 100% !important;
            margin: 0 !important;
            padding: 20px !important;
            min-height: calc(100vh - 120px);
        }

        /* Center container for all cards */
        .centered-container {
            width: 100%;
            max-width: 1000px;
            margin: 0 auto;
            display: flex;
            justify-content: center;
        }

        /* All cards - hidden by default, centered when shown */
        .dashboard-card {
            display: none !important;
            max-width: 900px !important;
            width: 100% !important;
            margin: 0 auto !important;
            min-height: 600px !important;
        }

        /* Show only active cards */
        .dashboard-card.active {
            display: flex !important;
        }

        /* Ensure proper spacing */
        .card-content {
            width: 100%;
            padding: 30px;
        }

        /* ========== COMMON STYLES ========== */
        .profile-card .profile-info {
            background: #f8f9fa;
            padding: 30px;
            border-radius: 0;
            flex: 1;
        }

        .profile-card .info-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 0;
            border-bottom: 1px solid #e0e0e0;
        }

        .profile-card .info-item:last-child {
            border-bottom: none;
        }

        .profile-card .info-item .label {
            font-weight: 600;
            color: #333;
            font-size: 16px;
        }

        .profile-card .info-item .value {
            color: #666;
            font-size: 16px;
            text-align: right;
        }

        .announcements-card .announcement-list,
        .payables-card .payable-list,
        .payables-management-card .payable-list {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .announcements-card .announcement-item,
        .payables-card .payable-item,
        .payables-management-card .payable-item {
            background: #f8f9fa;
            padding: 25px;
            transition: background 0.3s;
            border-radius: 0;
        }

        .announcements-card .announcement-item:hover,
        .payables-card .payable-item:hover,
        .payables-management-card .payable-item:hover {
            background: #f0f2f5;
        }

        .announcements-card .announcement-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .announcements-card .announcement-header h4 {
            margin: 0;
            color: #0a2d63;
            font-size: 18px;
            font-weight: 600;
            flex: 1;
        }

        .announcements-card .announcement-date {
            font-size: 14px;
            color: #666;
            background: #e9ecef;
            padding: 5px 10px;
            border-radius: 4px;
            white-space: nowrap;
        }

        .announcements-card .announcement-item p {
            margin: 0;
            color: #333;
            font-size: 15px;
            line-height: 1.6;
        }

        .payables-card .payable-item,
        .payables-management-card .payable-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .payables-card .payable-details,
        .payables-management-card .payable-details {
            flex: 1;
        }

        .payables-card .payable-details h4,
        .payables-management-card .payable-details h4 {
            margin: 0 0 10px 0;
            color: #333;
            font-size: 18px;
            font-weight: 600;
        }

        .payables-card .payable-date,
        .payables-management-card .payable-date {
            font-size: 14px;
            color: #666;
            background: #e9ecef;
            padding: 5px 10px;
            border-radius: 4px;
            display: inline-block;
        }

        .payables-card .payable-amount,
        .payables-management-card .payable-amount {
            text-align: right;
            min-width: 150px;
        }

        .payables-card .payable-total,
        .payables-management-card .payable-total {
            font-weight: 700;
            font-size: 20px;
            color: #0a2d63;
            display: block;
            margin-bottom: 5px;
        }

        .payables-card .payable-status,
        .payables-management-card .payable-status {
            font-size: 14px;
            color: #666;
            background: #e9ecef;
            padding: 5px 10px;
            border-radius: 4px;
            display: inline-block;
        }

        .status-btn {
            transition: all 0.3s ease;
        }

        .status-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }

        .status-btn:active {
            transform: translateY(0);
        }

        .event-item {
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Subject item styling */
        .subject-item {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 15px;
            transition: transform 0.2s;
        }
        
        .subject-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .subject-item h4 {
            margin: 0 0 10px 0;
            color: #0a2d63;
            font-size: 18px;
        }
        
        .subject-item p {
            margin: 5px 0;
            color: #555;
            font-size: 14px;
        }
        
        .subject-item .description {
            color: #666;
            font-style: italic;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid #e0e0e0;
        }
        
        .subject-item .schedule-list {
            margin-top: 10px;
            padding-left: 15px;
        }
        
        .subject-item .schedule-item {
            margin: 3px 0;
            color: #555;
            font-size: 13px;
        }
        
        .subject-item .schedule-item .day {
            font-weight: 600;
            color: #0a2d63;
        }
        
        .subject-item .schedule-item .time {
            color: #666;
        }

        /* Live clock styling */
        .live-clock {
            background: #0a2d63;
            color: white;
            padding: 8px 15px;
            border-radius: 4px;
            font-family: monospace;
            font-size: 16px;
            font-weight: 600;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            min-width: 180px;
        }

        .live-clock .time {
            font-size: 18px;
            letter-spacing: 1px;
        }

        .live-clock .date {
            font-size: 12px;
            opacity: 0.9;
            margin-top: 2px;
            display: block !important;
        }

        /* No events message styling */
        .no-events-message h4 {
            color: #0a2d63 !important;
        }

        .no-events-message p {
            color: #666 !important;
        }

        /* Subjects header styling */
        .subjects-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #e0e0e0;
        }

        .subjects-header h3 {
            margin: 0;
            color: #0a2d63;
        }

        .view-all-btn {
            background: #0a2d63;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: background 0.3s;
        }

        .view-all-btn:hover {
            background: #08306b;
        }

        .view-all-btn:active {
            transform: translateY(1px);
        }

        .today-badge {
            background: #10b981;
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            margin-left: 10px;
            vertical-align: middle;
        }

        .no-subjects-today {
            text-align: center;
            padding: 30px 20px;
            color: #666;
            background: #f8f9fa;
            border-radius: 8px;
            margin-top: 20px;
        }

        .no-subjects-today h4 {
            color: #0a2d63;
            margin-bottom: 10px;
        }

        /* Event item styling */
        .event-item {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 15px;
            transition: transform 0.2s;
        }

        .event-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .event-item .event-date {
            background: #0a2d63;
            color: white;
            padding: 10px 15px;
            border-radius: 4px;
            font-weight: 600;
            font-size: 14px;
            display: inline-block;
            margin-bottom: 10px;
            min-width: 200px;
            text-align: center;
        }

        .event-item .event-details h4 {
            margin: 0 0 8px 0;
            color: #0a2d63;
            font-size: 18px;
            font-weight: 600;
        }

        .event-item .event-details p {
            margin: 5px 0;
            color: #555;
            font-size: 14px;
            line-height: 1.5;
        }

        /* Event list container */
        .event-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
            margin-top: 20px;
            max-height: 400px;
            overflow-y: auto;
            padding-right: 5px;
        }

        /* Scrollbar styling */
        .event-list::-webkit-scrollbar {
            width: 6px;
        }

        .event-list::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 3px;
        }

        .event-list::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 3px;
        }

        .event-list::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }

        /* Grades table styling */
        .grades-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            background: white;
        }

        .grades-table th {
            background: #0a2d63;
            color: white;
            padding: 12px;
            text-align: left;
            font-weight: 600;
        }

        .grades-table td {
            padding: 12px;
            border-bottom: 1px solid #e0e0e0;
        }

        .grades-table tr:hover {
            background: #f8f9fa;
        }

        .grade-score {
            font-weight: 600;
            color: #0a2d63;
            font-size: 16px;
        }

        /* Payment Processing Styling */
        .payments-card .payment-form-container {
            background: #f8f9fa;
            padding: 25px;
            border-radius: 8px;
            margin-bottom: 25px;
        }

        .payments-card .payment-form-container h4 {
            margin: 0 0 20px 0;
            color: #0a2d63;
            font-size: 18px;
            font-weight: 600;
        }

        .payments-card .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .payments-card .form-group {
            margin-bottom: 15px;
        }

        .payments-card .form-group.full-width {
            grid-column: span 2;
        }

        .payments-card label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #333;
            font-size: 14px;
        }

        .payments-card input,
        .payments-card select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            color: #333;
            background: white;
            transition: border 0.3s;
        }

        .payments-card input:focus,
        .payments-card select:focus {
            outline: none;
            border-color: #0a2d63;
            box-shadow: 0 0 0 2px rgba(10, 45, 99, 0.1);
        }

        .payments-card .form-actions {
            grid-column: span 2;
            text-align: center;
            margin-top: 10px;
        }

        .payments-card .btn {
            padding: 10px 24px;
            border: none;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .payments-card .btn-primary {
            background: #0a2d63;
            color: white;
        }

        .payments-card .btn-primary:hover {
            background: #08306b;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(10, 45, 99, 0.2);
        }

        .payments-card .btn-success {
            background: #28a745;
            color: white;
        }

        .payments-card .btn-success:hover {
            background: #218838;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.2);
        }

        .payments-card .btn-load {
            background: #6c757d;
            color: white;
        }

        .payments-card .btn-load:hover {
            background: #5a6268;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(108, 117, 125, 0.2);
        }

        .payments-card .btn + .btn {
            margin-left: 10px;
        }

        /* Payables List Styling */
        .payables-list-container {
            background: #f8f9fa;
            padding: 25px;
            border-radius: 8px;
            margin-bottom: 25px;
            border: 1px solid #e0e0e0;
        }

        .payables-list-container h4 {
            margin: 0 0 20px 0;
            color: #0a2d63;
            font-size: 18px;
            font-weight: 600;
        }

        .payables-grid {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr auto;
            gap: 15px;
            font-size: 14px;
        }

        .payables-header {
            font-weight: 600;
            color: #333;
            padding-bottom: 10px;
            border-bottom: 2px solid #ddd;
            margin-bottom: 10px;
        }

        .payables-row {
            display: contents;
        }

        .payables-row > div {
            padding: 12px 0;
            border-bottom: 1px solid #eee;
            display: flex;
            align-items: center;
        }

        .payables-row:last-child > div {
            border-bottom: none;
        }

        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-paid {
            background: #d4edda;
            color: #155724;
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
        }

        .status-overdue {
            background: #f8d7da;
            color: #721c24;
        }

        /* Payment Result Styling */
        .payment-result-container {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            border-radius: 8px;
            padding: 25px;
            margin-bottom: 20px;
        }

        .payment-result-container h4 {
            margin: 0 0 15px 0;
            color: #155724;
            font-size: 18px;
            font-weight: 600;
        }

        .payment-result-container p {
            margin: 8px 0;
            color: #155724;
            font-size: 14px;
        }

        .payment-result-container ul {
            margin: 10px 0 0 0;
            padding-left: 20px;
        }

        .payment-result-container li {
            margin: 5px 0;
            color: #155724;
            font-size: 14px;
        }

        /* Loading State */
        .loading {
            text-align: center;
            padding: 40px 20px;
            color: #666;
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

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Teacher-specific styling */
        .teacher-actions {
            display: flex;
            gap: 15px;
            margin-top: 20px;
        }

        .action-btn {
            background: #0a2d63;
            color: white;
            padding: 12px 24px;
            border-radius: 4px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s;
            display: inline-block;
        }

        .action-btn:hover {
            background: #08306b;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }

        /* Remove emojis from buttons */
        .payments-card .btn::before {
            display: none;
        }
    </style>
</head>
<body class="<?php echo in_array($userRole, ['admin', 'super_admin']) ? 'admin-mode' : ''; ?>">
    <!-- Dashboard Page -->
    <div class="dashboard-page" id="dashboardPage">
        <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <img src="images/logo.png" alt="BAA Logo" class="sidebar-logo">
                <h3>Baesa Adventist Academy</h3>
            </div>
            <ul>
                <?php if ($userRole == 'admin' || $userRole == 'super_admin'): ?>
                    <li><a href="#" onclick="navigateTo('home'); return false;" class="active" id="menu-home">Enrollment Requests</a></li>
                    <li><a href="#" onclick="navigateTo('users'); return false;" id="menu-users">User Management</a></li>
                    <li><a href="#" onclick="navigateTo('payables'); return false;" id="menu-payables">Payables Management</a></li>
                    <li><a href="#" onclick="navigateTo('payments'); return false;" id="menu-payments">Payment Processing</a></li>
                    <li><a href="#" onclick="navigateTo('profile'); return false;" id="menu-profile">Profile</a></li>
                <?php else: ?>
                    <li><a href="#" onclick="navigateTo('home'); return false;" class="active" id="menu-home">Home</a></li>
                    <li><a href="#" onclick="navigateTo('grades'); return false;" id="menu-grades">Grades</a></li>
                    <li><a href="#" onclick="navigateTo('subjects'); return false;" id="menu-subjects">Subjects</a></li>
                    <li><a href="#" onclick="navigateTo('payables'); return false;" id="menu-payables">Payables</a></li>
                    <li><a href="#" onclick="navigateTo('events'); return false;" id="menu-events">Events</a></li>
                    <li><a href="#" onclick="navigateTo('profile'); return false;" id="menu-profile">Profile</a></li>
                    <li><a href="#" onclick="navigateTo('announcements'); return false;" id="menu-announcements">Announcements</a></li>
                <?php endif; ?>
                <?php if ($userRole == 'teacher'): ?>
                <li><a href="teacher_grades.php" id="menu-teacher">Grade Encoding</a></li>
                <?php endif; ?>
            </ul>
        </div>
        
        <div class="dashboard-main">
            <!-- Dashboard Header -->
            <div class="dashboard-header">
                <div class="header-content">
                    <div class="header-left">
                        <button class="menu-btn" onclick="toggleSidebar()">☰</button>
                        <!-- Live Clock -->
                        <div class="live-clock" id="liveClock">
                            <div class="time"><?php echo $currentTime; ?></div>
                            <div class="date"><?php echo $currentDate; ?></div>
                        </div>
                    </div>
                    
                    <div class="header-center">
                        <h2>Welcome to Your Dashboard</h2>
                        <p>Stay updated with your academic progress and school activities</p>
                    </div>
                    
                    <div class="header-right">
                        <div class="user-info-container">
                            <span class="user-name" id="userName"><?php echo htmlspecialchars($fullName); ?></span>
                            <button class="logout-btn" onclick="window.location.href='php/logout.php'">Logout</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Dashboard Content - Centered Container -->
            <div class="dashboard-content">
                <div class="centered-container">
                    <?php if (in_array($userRole, ['admin', 'super_admin'])): ?>
                        <!-- Admin Enrollments Dashboard -->
                        <div class="dashboard-card grades-card active" id="adminEnrollmentCard">
                            <div class="card-content">
                                <h3>Student Enrollment Requests</h3>
                                <p>Review and manage pending student enrollments</p>
                                
                                <div id="enrollmentList">
                                    <div style="text-align: center; color: #999; padding: 40px 20px;">
                                        Loading enrollments...
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- User Management Card -->
                        <div class="dashboard-card users-card" id="usersCard">
                            <div class="card-content">
                                <h3>User Management</h3>
                                <p>Manage user accounts - create and view users</p>

                                <!-- Create User Form -->
                                <div class="user-form-container">
                                    <h4>Create New User</h4>
                                    <form id="createUserForm" style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                                        <div>
                                            <label style="display: block; margin-bottom: 5px; font-weight: 500;">Username *</label>
                                            <input type="text" name="username" required style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                                        </div>
                                        <div>
                                            <label style="display: block; margin-bottom: 5px; font-weight: 500;">Email *</label>
                                            <input type="email" name="email" required style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                                        </div>
                                        <div>
                                            <label style="display: block; margin-bottom: 5px; font-weight: 500;">Password *</label>
                                            <input type="password" name="password" required style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                                        </div>
                                        <div>
                                            <label style="display: block; margin-bottom: 5px; font-weight: 500;">Full Name *</label>
                                            <input type="text" name="fullName" required style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                                        </div>
                                        <div>
                                            <label style="display: block; margin-bottom: 5px; font-weight: 500;">Role *</label>
                                            <select name="role" id="roleSelect" required style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;" onchange="toggleStudentFields()">
                                                <option value="">Select Role</option>
                                                <option value="student">Student</option>
                                                <option value="teacher">Teacher</option>
                                                <?php if ($userRole == 'super_admin'): ?>
                                                <option value="admin">Admin</option>
                                                <?php endif; ?>
                                            </select>
                                        </div>
                                        
                                        <!-- Student-specific fields (hidden by default) -->
                                        <div id="studentFields" style="display: none; grid-column: span 2;">
                                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-top: 10px; padding: 15px; background: #f8f9fa; border-radius: 4px;">
                                                <div>
                                                    <label style="display: block; margin-bottom: 5px; font-weight: 500;">Grade Level *</label>
                                                    <select name="gradeLevel" id="gradeLevel" required style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;" onchange="updateSections()">
                                                        <option value="">Select Grade Level</option>
                                                        <option value="Grade 7">Grade 7</option>
                                                        <option value="Grade 8">Grade 8</option>
                                                        <option value="Grade 9">Grade 9</option>
                                                        <option value="Grade 10">Grade 10</option>
                                                        <option value="Grade 11">Grade 11</option>
                                                        <option value="Grade 12">Grade 12</option>
                                                    </select>
                                                </div>
                                                <div>
                                                    <label style="display: block; margin-bottom: 5px; font-weight: 500;">Section *</label>
                                                    <select name="section" id="sectionSelect" required style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                                                        <option value="">Select Section</option>
                                                    </select>
                                                </div>
                                                <div style="grid-column: span 2;">
                                                    <label style="display: block; margin-bottom: 5px; font-weight: 500;">LRN *</label>
                                                    <input type="text" name="lrn" id="lrnField" required style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div style="grid-column: span 2; text-align: right;">
                                            <button type="submit" style="background: #0a2d63; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; font-weight: 500;">Create User</button>
                                        </div>
                                    </form>
                                </div>

                                <!-- Show Users Button -->
                                <div style="margin-bottom: 20px; text-align: center;">
                                    <button id="showUsersBtn" style="background: #0a2d63; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; font-weight: 500;">Show All Users</button>
                                </div>

                                <div id="userList" style="width: 100%; display: none;">
                                </div>
                            </div>
                        </div>

                        <!-- Payables Management Card -->
                        <div class="dashboard-card payables-management-card" id="payablesManagementCard">
                            <div class="card-content">
                                <h3>Payables Management</h3>
                                <p>Calculate and manage student payables</p>

                                <!-- Payables Calculator Form -->
                                <div style="margin-bottom: 30px; padding: 20px; background: #f8f9fa; border-radius: 8px;">
                                    <h4 style="margin-top: 0; color: #0a2d63;">Payables Calculator</h4>
                                    <form id="payablesForm" style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                                        <div>
                                            <label style="display: block; margin-bottom: 5px; font-weight: 500;">Select Student *</label>
                                            <select id="studentSelect" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;" required>
                                                <option value="">Select Student</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label style="display: block; margin-bottom: 5px; font-weight: 500;">Total Tuition Fee *</label>
                                            <input type="number" id="tuitionFee" placeholder="0.00" step="0.01" min="0" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;" required>
                                        </div>
                                        <div>
                                            <label style="display: block; margin-bottom: 5px; font-weight: 500;">Down Payment *</label>
                                            <input type="number" id="downPayment" placeholder="0.00" step="0.01" min="0" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;" required>
                                        </div>
                                        <div>
                                            <label style="display: block; margin-bottom: 5px; font-weight: 500;">Discounts/Grants</label>
                                            <input type="number" id="discounts" placeholder="0.00" step="0.01" min="0" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                                        </div>
                                        <div>
                                            <label style="display: block; margin-bottom: 5px; font-weight: 500;">Number of Monthly Payments</label>
                                            <input type="number" id="monthlyPayments" placeholder="4" min="1" max="12" value="4" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                                        </div>
                                        <div style="grid-column: span 2; text-align: center;">
                                            <button type="button" onclick="calculatePayables()" style="background: #0a2d63; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; font-weight: 500;">Calculate Remaining Balance</button>
                                        </div>
                                    </form>
                                </div>

                                <div id="calculationResult" style="margin-bottom: 20px; padding: 20px; background: #f8f9fa; border: 1px solid #e0e0e0; border-radius: 8px; display: none;">
                                    <h4 style="margin-top: 0; color: #0a2d63; margin-bottom: 15px;">Calculation Result</h4>
                                    <div id="resultContent"></div>
                                </div>

                                <div style="text-align: center;">
                                    <button onclick="addPayable()" id="addPayableBtn" style="background: #28a745; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; font-weight: 500; display: none;">Add to Student Payables</button>
                                </div>
                            </div>
                        </div>

                        <!-- Payment Processing Card -->
                        <div class="dashboard-card payments-card" id="paymentsCard">
                            <div class="card-content">
                                <h3>Payment Processing</h3>
                                <p>Process student payments and update payable status</p>

                                <!-- Payment Processing Form -->
                                <div class="payment-form-container">
                                    <h4>Process Payment</h4>
                                    <form id="paymentForm" class="form-grid">
                                        <div class="form-group">
                                            <label for="paymentStudentSelect">Select Student *</label>
                                            <select id="paymentStudentSelect" required>
                                                <option value="">Select Student</option>
                                            </select>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="paymentAmount">Payment Amount *</label>
                                            <input type="number" id="paymentAmount" placeholder="0.00" step="0.01" min="0" required>
                                        </div>
                                        
                                        <div class="form-group full-width">
                                            <label for="paymentDate">Payment Date</label>
                                            <input type="date" id="paymentDate" value="<?php echo date('Y-m-d'); ?>">
                                        </div>
                                        
                                        <div class="form-actions">
                                            <button type="button" onclick="loadStudentPayables()" class="btn btn-load">
                                                Load Student Payables
                                            </button>
                                            <button type="button" onclick="processPayment()" class="btn btn-success">
                                                Process Payment
                                            </button>
                                        </div>
                                    </form>
                                </div>

                                <div id="studentPayables" class="payables-list-container" style="display: none;">
                                    <h4>Student Payables</h4>
                                    <div id="payablesList" class="loading">Loading payables...</div>
                                </div>

                                <div id="paymentResult" class="payment-result-container" style="display: none;">
                                    <!-- Payment result will be inserted here -->
                                </div>
                            </div>
                        </div>

                        <!-- Profile Card for Admin -->
                        <div class="dashboard-card profile-card" id="adminProfileCard">
                            <div class="card-content">
                                <h3>Profile</h3>
                                <p>View and update your personal information.</p>
                                <div class="profile-info" id="adminProfileInfo">
                                    <div class="info-item">
                                        <span class="label">Full Name:</span>
                                        <span class="value"><?php echo htmlspecialchars($fullName); ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="label">Username:</span>
                                        <span class="value"><?php echo htmlspecialchars($userName); ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="label">User Role:</span>
                                        <span class="value"><?php echo htmlspecialchars(ucfirst($userRole)); ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="label">Email:</span>
                                        <span class="value"><?php echo htmlspecialchars($user['email'] ?? 'N/A'); ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>

                    <?php elseif ($userRole == 'student'): ?>
                        <!-- Student Dashboard - All cards centered -->
                        <!-- Home Card (Grades + Subjects) -->
                        <div class="dashboard-card home-card active" id="homeCard">
                            <div class="card-content">
                                <div style="margin-bottom: 30px;">
                                    <h3>Grades</h3>
                                    <p>Check your current grades, academic performance, and progress reports.</p>
                                    
                                    <div class="grade-summary" id="gradeSummary">
                                        <?php if (!empty($grades)): ?>
                                            <?php
                                            // Group grades by subject
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
                                            
                                            // Calculate averages
                                            foreach ($groupedGrades as $subjectName => &$data) {
                                                if ($data['count'] > 0) {
                                                    $data['average'] = round($data['total'] / $data['count']);
                                                }
                                            }
                                            ?>
                                            
                                            <table class="grades-table">
                                                <thead>
                                                    <tr>
                                                        <th>Subject</th>
                                                        <th>Q1</th>
                                                        <th>Q2</th>
                                                        <th>Q3</th>
                                                        <th>Q4</th>
                                                        <th>Average</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($groupedGrades as $subjectName => $data): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($subjectName); ?></td>
                                                        <td><?php echo isset($data['quarters'][1]) ? $data['quarters'][1] : '-'; ?></td>
                                                        <td><?php echo isset($data['quarters'][2]) ? $data['quarters'][2] : '-'; ?></td>
                                                        <td><?php echo isset($data['quarters'][3]) ? $data['quarters'][3] : '-'; ?></td>
                                                        <td><?php echo isset($data['quarters'][4]) ? $data['quarters'][4] : '-'; ?></td>
                                                        <td>
                                                            <?php if ($data['average'] > 0): ?>
                                                                <span class="grade-score"><?php echo $data['average']; ?></span>
                                                            <?php else: ?>
                                                                -
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                            
                                        <?php else: ?>
                                            <table class="grades-table">
                                                <thead>
                                                    <tr>
                                                        <th>Subject</th>
                                                        <th>Q1</th>
                                                        <th>Q2</th>
                                                        <th>Q3</th>
                                                        <th>Q4</th>
                                                        <th>Average</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <tr>
                                                        <td colspan="6" style="text-align: center; padding: 40px; color: #666;">
                                                            No grades available yet. Check back later.
                                                        </td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div style="margin-top: 40px; border-top: 2px solid #e0e0e0; padding-top: 30px;">
                                    <div class="subjects-header">
                                        <div>
                                            <h3>Subjects for Today</h3>
                                        </div>
                                        <button class="view-all-btn" onclick="toggleHomeSubjects()">View All Subjects</button>
                                    </div>
                                    <p>Your scheduled subjects for today.</p>
                                    
                                    <div class="subject-list" id="todaySubjectList">
                                        <?php if (!empty($todaySubjects)): ?>
                                            <?php foreach ($todaySubjects as $subject): ?>
                                                <div class="subject-item">
                                                    <h4><?php echo htmlspecialchars($subject['subject_name'] ?? ''); ?> <span class="today-badge">TODAY</span></h4>
                                                    <p><strong>Schedule:</strong> <?php echo htmlspecialchars($subject['schedule'] ?? 'Schedule not set'); ?></p>
                                                    <?php if (!empty($subject['description'])): ?>
                                                        <p class="description"><?php echo htmlspecialchars($subject['description']); ?></p>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <div class="no-subjects-today">
                                                <h4>No subjects scheduled for today</h4>
                                                <p>You have no classes scheduled for <?php echo $currentDay; ?>.</p>
                                                <p>Click "View All Subjects" to see your complete weekly schedule.</p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="subject-list" id="allSubjectList" style="display: none;">
                                        <?php if (!empty($allSubjects)): ?>
                                            <?php foreach ($allSubjects as $subjectName => $subjectData): ?>
                                                <div class="subject-item">
                                                    <h4><?php echo htmlspecialchars($subjectData['subject_name']); ?></h4>
                                                    <p><strong>Code:</strong> <?php echo htmlspecialchars($subjectData['subject_code'] ?? ''); ?></p>
                                                    <p><strong>Semester:</strong> <?php echo htmlspecialchars($subjectData['semester'] ?? ''); ?></p>
                                                    
                                                    <div class="schedule-list">
                                                        <p><strong>All Schedules:</strong></p>
                                                        <?php foreach ($subjectData['schedules'] as $schedule): ?>
                                                            <div class="schedule-item">
                                                                <span class="day"><?php echo htmlspecialchars($schedule['day_of_week']); ?>:</span>
                                                                <span class="time"><?php echo htmlspecialchars($schedule['start_time_formatted']); ?> - <?php echo htmlspecialchars($schedule['end_time_formatted']); ?></span>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                    
                                                    <?php if (!empty($subjectData['description'])): ?>
                                                        <p class="description"><?php echo htmlspecialchars($subjectData['description']); ?></p>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <div class="subject-item">
                                                <h4>No subjects enrolled for your section</h4>
                                                <p>Grade Level: <?php echo htmlspecialchars($gradeLevel); ?> | Section: <?php echo htmlspecialchars($section); ?></p>
                                                <p class="description">Contact your advisor if you believe this is incorrect.</p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Grades Card -->
                        <div class="dashboard-card grades-card" id="gradesCard">
                            <div class="card-content">
                                <h3>Grades</h3>
                                <p>Check your current grades, academic performance, and progress reports.</p>
                                
                                <div class="grade-summary">
                                    <?php if (!empty($grades)): ?>
                                        <?php
                                        // Group grades by subject
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
                                        
                                        // Calculate averages
                                        foreach ($groupedGrades as $subjectName => &$data) {
                                            if ($data['count'] > 0) {
                                                $data['average'] = round($data['total'] / $data['count']);
                                            }
                                        }
                                        ?>
                                        
                                        <table class="grades-table">
                                            <thead>
                                                <tr>
                                                    <th>Subject</th>
                                                    <th>Q1</th>
                                                    <th>Q2</th>
                                                    <th>Q3</th>
                                                    <th>Q4</th>
                                                    <th>Average</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($groupedGrades as $subjectName => $data): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($subjectName); ?></td>
                                                    <td><?php echo isset($data['quarters'][1]) ? $data['quarters'][1] : '-'; ?></td>
                                                    <td><?php echo isset($data['quarters'][2]) ? $data['quarters'][2] : '-'; ?></td>
                                                    <td><?php echo isset($data['quarters'][3]) ? $data['quarters'][3] : '-'; ?></td>
                                                    <td><?php echo isset($data['quarters'][4]) ? $data['quarters'][4] : '-'; ?></td>
                                                    <td>
                                                        <?php if ($data['average'] > 0): ?>
                                                            <span class="grade-score"><?php echo $data['average']; ?></span>
                                                        <?php else: ?>
                                                            -
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                        
                                    <?php else: ?>
                                        <table class="grades-table">
                                            <thead>
                                                <tr>
                                                    <th>Subject</th>
                                                    <th>Q1</th>
                                                    <th>Q2</th>
                                                    <th>Q3</th>
                                                    <th>Q4</th>
                                                    <th>Average</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <td colspan="6" style="text-align: center; padding: 40px; color: #666;">
                                                        No grades available yet. Check back later.
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Subjects Card -->
                        <div class="dashboard-card subjects-card" id="subjectsCard">
                            <div class="card-content">
                                <div class="subjects-header">
                                    <div>
                                        <h3>Today's Subjects</h3>
                                    </div>
                                    <button class="view-all-btn" onclick="toggleSubjectCard()" id="subjectsCardBtn">View All Subjects</button>
                                </div>
                                <p>Your subjects scheduled for today.</p>
                                
                                <!-- Today's Subjects (shown by default) -->
                                <div class="subject-list" id="todaySubjectsCardList">
                                    <?php if (!empty($todaySubjects)): ?>
                                        <?php foreach ($todaySubjects as $subject): ?>
                                            <div class="subject-item">
                                                <h4><?php echo htmlspecialchars($subject['subject_name'] ?? ''); ?> <span class="today-badge">TODAY</span></h4>
                                                <p><strong>Schedule:</strong> <?php echo htmlspecialchars($subject['schedule'] ?? 'Schedule not set'); ?></p>
                                                <?php if (!empty($subject['description'])): ?>
                                                    <p class="description"><?php echo htmlspecialchars($subject['description']); ?></p>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="no-subjects-today">
                                            <h4>No subjects scheduled for today</h4>
                                            <p>You have no classes scheduled for <?php echo $currentDay; ?>.</p>
                                            <p>Click "View All Subjects" to see your complete weekly schedule.</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- All Subjects (hidden by default) -->
                                <div class="subject-list" id="allSubjectsCardList" style="display: none;">
                                    <?php if (!empty($allSubjects)): ?>
                                        <?php foreach ($allSubjects as $subjectName => $subjectData): ?>
                                            <div class="subject-item">
                                                <h4><?php echo htmlspecialchars($subjectData['subject_name']); ?></h4>
                                                <p><strong>Code:</strong> <?php echo htmlspecialchars($subjectData['subject_code'] ?? ''); ?></p>
                                                <p><strong>Semester:</strong> <?php echo htmlspecialchars($subjectData['semester'] ?? ''); ?></p>
                                                
                                                <div class="schedule-list">
                                                    <p><strong>All Schedules:</strong></p>
                                                    <?php foreach ($subjectData['schedules'] as $schedule): ?>
                                                        <div class="schedule-item">
                                                            <span class="day"><?php echo htmlspecialchars($schedule['day_of_week']); ?>:</span>
                                                            <span class="time"><?php echo htmlspecialchars($schedule['start_time_formatted']); ?> - <?php echo htmlspecialchars($schedule['end_time_formatted']); ?></span>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                                
                                                <?php if (!empty($subjectData['description'])): ?>
                                                    <p class="description"><?php echo htmlspecialchars($subjectData['description']); ?></p>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="subject-item">
                                            <h4>No subjects enrolled for your section</h4>
                                            <p>Grade Level: <?php echo htmlspecialchars($gradeLevel); ?> | Section: <?php echo htmlspecialchars($section); ?></p>
                                            <p class="description">Contact your advisor if you believe this is incorrect.</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Events Card -->
                        <div class="dashboard-card events-card" id="eventsCard">
                            <div class="card-content">
                                <h3>Upcoming School Events</h3>
                                <p>View upcoming school events, activities, and important dates for the next 15 days.</p>
                                <div class="event-list" id="eventList">
                                    <?php if (!empty($events)): ?>
                                        <?php foreach ($events as $event): ?>
                                            <div class="event-item">
                                                <div class="event-date">
                                                    <?php 
                                                    $eventDate = new DateTime($event['event_date']);
                                                    $today = new DateTime();
                                                    $interval = $today->diff($eventDate);
                                                    $daysDiff = $interval->days;
                                                    
                                                    // Format date as "Month day, Year"
                                                    $formattedDate = date('F j, Y', strtotime($event['event_date']));
                                                    
                                                    // Always show in format: "Today - February 1, 2026"
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
                                                    <h4><?php echo htmlspecialchars($event['event_name']); ?></h4>
                                                    <?php if (!empty($event['description'])): ?>
                                                        <p><?php echo htmlspecialchars($event['description']); ?></p>
                                                    <?php endif; ?>
                                                    <?php if (!empty($event['responsible_dept'])): ?>
                                                        <p><?php echo htmlspecialchars($event['responsible_dept']); ?></p>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="event-item no-events-message">
                                            <div class="event-details">
                                                <h4>No upcoming events in the next 15 days</h4>
                                                <p>Check back later for upcoming events.</p>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Payables Card -->
                        <div class="dashboard-card payables-card" id="payablesCard">
                            <div class="card-content">
                                <h3>Payables</h3>
                                <p>View your tuition fees, payment history, and outstanding balances.</p>
                                <div class="payable-list" id="payableList">
                                    <div class="loading">Loading payables...</div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Profile Card -->
                        <div class="dashboard-card profile-card" id="profileCard">
                            <div class="card-content">
                                <h3>Profile</h3>
                                <p>View and update your personal information.</p>
                                <div class="profile-info" id="profileInfo">
                                    <div class="info-item">
                                        <span class="label">Full Name:</span>
                                        <span class="value"><?php echo htmlspecialchars($fullName); ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="label">Username:</span>
                                        <span class="value"><?php echo htmlspecialchars($userName); ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="label">User Role:</span>
                                        <span class="value"><?php echo htmlspecialchars(ucfirst($userRole)); ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="label">Grade Level:</span>
                                        <span class="value"><?php echo htmlspecialchars($gradeLevel); ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="label">Section:</span>
                                        <span class="value"><?php echo htmlspecialchars($section); ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="label">LRN:</span>
                                        <span class="value"><?php echo htmlspecialchars($lrn); ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Announcements Card -->
                        <div class="dashboard-card announcements-card" id="announcementsCard">
                            <div class="card-content">
                                <h3>Announcements</h3>
                                <p>Latest school announcements and updates.</p>
                                <div class="announcement-list" id="announcementList">
                                    <div class="loading">Loading announcements...</div>
                                </div>
                            </div>
                        </div>

                    <?php elseif ($userRole == 'teacher'): ?>
                        <!-- Teacher Dashboard -->
                        <div class="dashboard-card teacher-card active" id="teacherCard">
                            <div class="card-content">
                                <h3>Teacher Dashboard</h3>
                                <p>Welcome, <?php echo htmlspecialchars($fullName); ?>!</p>
                                <div class="teacher-actions">
                                    <a href="teacher_grades.php" class="action-btn">Encode Grades</a>
                                    <a href="teacher_subjects.php" class="action-btn">My Subjects</a>
                                    <a href="teacher_students.php" class="action-btn">My Students</a>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>
    <script src="js/script.js"></script>
    <script>
        // JavaScript to update the clock every second
        function updateLiveClock() {
            const now = new Date();
            const options = { timeZone: 'Asia/Manila' };
            
            // Get time
            const timeStr = now.toLocaleTimeString('en-US', { 
                hour12: true,
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                ...options
            });
            
            // Get date
            const dateStr = now.toLocaleDateString('en-US', { 
                month: 'long',
                day: 'numeric',
                year: 'numeric',
                ...options
            });
            
            const clockElement = document.getElementById('liveClock');
            if (clockElement) {
                const timeElement = clockElement.querySelector('.time');
                const dateElement = clockElement.querySelector('.date');
                
                if (timeElement) timeElement.textContent = timeStr;
                if (dateElement) dateElement.textContent = dateStr;
            }
        }

        // Toggle between today's subjects and all subjects in Home Card
        function toggleHomeSubjects() {
            const todayList = document.getElementById('todaySubjectList');
            const allList = document.getElementById('allSubjectList');
            const viewAllBtn = document.querySelector('#homeCard .view-all-btn');
            
            if (todayList && allList && viewAllBtn) {
                if (todayList.style.display === 'none' || todayList.style.display === '') {
                    // Show today's subjects
                    todayList.style.display = 'block';
                    allList.style.display = 'none';
                    viewAllBtn.textContent = 'View All Subjects';
                    viewAllBtn.style.background = '#0a2d63';
                } else {
                    // Show all subjects
                    todayList.style.display = 'none';
                    allList.style.display = 'block';
                    viewAllBtn.textContent = 'View Today\'s Subjects';
                    viewAllBtn.style.background = '#10b981';
                }
            }
        }

        // Toggle between today's subjects and all subjects in Subjects Card
        function toggleSubjectCard() {
            const todayList = document.getElementById('todaySubjectsCardList');
            const allList = document.getElementById('allSubjectsCardList');
            const viewAllBtn = document.getElementById('subjectsCardBtn');
            
            if (todayList && allList && viewAllBtn) {
                if (allList.style.display === 'none' || allList.style.display === '') {
                    // Show all subjects
                    todayList.style.display = 'none';
                    allList.style.display = 'block';
                    viewAllBtn.textContent = 'View Today\'s Subjects';
                    viewAllBtn.style.background = '#0a2d63';
                } else {
                    // Show today's subjects
                    todayList.style.display = 'block';
                    allList.style.display = 'none';
                    viewAllBtn.textContent = 'View All Subjects';
                    viewAllBtn.style.background = '#10b981';
                }
            }
        }

        // Simple navigation for all users
        function navigateTo(page) {
            toggleSidebar();
            
            // Remove active class from all menu items
            const menuItems = document.querySelectorAll('.sidebar ul li a');
            menuItems.forEach(item => {
                item.classList.remove('active');
            });
            
            // Add active class to clicked menu item
            const clickedItem = document.getElementById(`menu-${page}`);
            if (clickedItem) {
                clickedItem.classList.add('active');
            }
            
            // Hide all cards
            const allCards = document.querySelectorAll('.dashboard-card');
            allCards.forEach(card => {
                card.classList.remove('active');
            });
            
            // Show the selected card based on user role
            <?php if ($userRole == 'student'): ?>
            // Student navigation
            switch(page) {
                case 'home':
                    document.getElementById('homeCard').classList.add('active');
                    break;
                case 'grades':
                    document.getElementById('gradesCard').classList.add('active');
                    break;
                case 'subjects':
                    document.getElementById('subjectsCard').classList.add('active');
                    break;
                case 'events':
                    document.getElementById('eventsCard').classList.add('active');
                    break;
                case 'payables':
                    document.getElementById('payablesCard').classList.add('active');
                    loadPayables();
                    break;
                case 'profile':
                    document.getElementById('profileCard').classList.add('active');
                    break;
                case 'announcements':
                    document.getElementById('announcementsCard').classList.add('active');
                    loadAnnouncements();
                    break;
            }
            
            <?php elseif (in_array($userRole, ['admin', 'super_admin'])): ?>
            // Admin navigation
            switch(page) {
                case 'home':
                    document.getElementById('adminEnrollmentCard').classList.add('active');
                    loadEnrollments();
                    break;
                case 'users':
                    document.getElementById('usersCard').classList.add('active');
                    break;
                case 'payables':
                    document.getElementById('payablesManagementCard').classList.add('active');
                    setTimeout(() => {
                        if (typeof loadStudents === 'function') {
                            loadStudents();
                        }
                    }, 100);
                    break;
                case 'payments':
                    document.getElementById('paymentsCard').classList.add('active');
                    if (typeof loadPaymentStudents === 'function') {
                        loadPaymentStudents();
                    }
                    break;
                case 'profile':
                    document.getElementById('adminProfileCard').classList.add('active');
                    break;
            }
            
            <?php elseif ($userRole == 'teacher'): ?>
            // Teacher navigation
            document.getElementById('teacherCard').classList.add('active');
            
            <?php endif; ?>
        }

        // Load payables for student
        function loadPayables() {
            // Clear previous content first
            const payableList = document.getElementById('payableList');
            payableList.innerHTML = '<div class="loading">Loading payables...</div>';
        
            // Add timestamp to prevent caching
            const timestamp = new Date().getTime();
        
            fetch('php/get_payables.php?_=' + timestamp)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.payables && data.payables.length > 0) {
                        let html = '';
                        data.payables.forEach(payable => {
                            const dueDate = new Date(payable.due_date);
                            const today = new Date();
                        
                            // First check if status is 'paid'
                            if (payable.status === 'paid') {
                                // Already handled
                            } else if (payable.status === 'partially_paid') {
                                // Show partially paid status
                                html += `
                                    <div class="payable-item">
                                        <div class="payable-details">
                                            <h4>${payable.item_name}</h4>
                                            <p style="margin: 5px 0; color: #666;">Due: <span class="payable-date">${dueDate.toLocaleDateString()}</span></p>
                                            <p style="margin: 5px 0; color: #3b82f6; font-size: 13px;">Partially Paid</p>
                                        </div>
                                        <div class="payable-amount">
                                            <div class="payable-total">₱${parseFloat(payable.amount).toLocaleString()}</div>
                                            <div class="payable-status" style="background: #dbeafe; color: #1d4ed8;">Partially Paid</div>
                                        </div>
                                    </div>
                                `;
                            } else {
                                const isOverdue = dueDate < today;
                                
                                html += `
                                    <div class="payable-item">
                                        <div class="payable-details">
                                            <h4>${payable.item_name}</h4>
                                            <p style="margin: 5px 0; color: #666;">Due: <span class="payable-date">${dueDate.toLocaleDateString()}</span></p>
                                        </div>
                                        <div class="payable-amount">
                                            <div class="payable-total">₱${parseFloat(payable.amount).toLocaleString()}</div>
                                            <div class="payable-status" style="background: ${isOverdue ? '#fee2e2' : '#fff3cd'}; color: ${isOverdue ? '#dc2626' : '#856404'};">${isOverdue ? 'Overdue' : 'Pending'}</div>
                                        </div>
                                    </div>
                                `;
                            }
                        });
                        payableList.innerHTML = html;
                    } else {
                        payableList.innerHTML = '<div style="text-align: center; color: #999; padding: 40px 20px;">No payables found.</div>';
                    }
                })
                .catch(error => {
                    console.error('Error loading payables:', error);
                    payableList.innerHTML = '<div style="text-align: center; color: #999; padding: 40px 20px;">Error loading payables</div>';
                });
        }

        // Load announcements
        function loadAnnouncements() {
            fetch('php/get_announcements.php')
                .then(response => response.json())
                .then(data => {
                    const announcementList = document.getElementById('announcementList');
                    if (data.success && data.announcements && data.announcements.length > 0) {
                        let html = '';
                        data.announcements.forEach(announcement => {
                            const created = new Date(announcement.created_at);
                            html += `
                                <div class="announcement-item">
                                    <div class="announcement-header">
                                        <h4>${announcement.title}</h4>
                                        <span class="announcement-date">${created.toLocaleDateString()}</span>
                                    </div>
                                    <p>${announcement.content}</p>
                                </div>
                            `;
                        });
                        announcementList.innerHTML = html;
                    } else {
                        announcementList.innerHTML = '<div style="text-align: center; color: #999; padding: 40px 20px;">No announcements available.</div>';
                    }
                })
                .catch(error => {
                    console.error('Error loading announcements:', error);
                    document.getElementById('announcementList').innerHTML = '<div style="text-align: center; color: #999; padding: 40px 20px;">Error loading announcements</div>';
                });
        }

        // ========== ADMIN FUNCTIONS ==========
        
        // Load and display all users
        function loadUsers() {
            const userList = document.getElementById('userList');
            userList.style.display = 'block';
            userList.innerHTML = '<div style="text-align: center; padding: 20px;">Loading users...</div>';

            fetch('php/get_users.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.users) {
                        displayUsers(data.users);
                    } else {
                        userList.innerHTML = '<div style="text-align: center; padding: 20px; color: #666;">Error loading users: ' + (data.message || 'Unknown error') + '</div>';
                    }
                })
                .catch(error => {
                    console.error('Error loading users:', error);
                    userList.innerHTML = '<div style="text-align: center; padding: 20px; color: #dc2626;">Error loading users. Please try again.</div>';
                });
        }

        function displayUsers(users) {
            const userList = document.getElementById('userList');
            
            if (users.length === 0) {
                userList.innerHTML = '<div style="text-align: center; padding: 20px; color: #666;">No users found.</div>';
                return;
            }
            
            let html = `
                <div style="margin-top: 20px; padding: 20px; background: #f8f9fa; border-radius: 8px;">
                    <h4 style="margin-top: 0; margin-bottom: 15px; color: #0a2d63;">User List</h4>
                    <div style="overflow-x: auto;">
                        <table style="width: 100%; border-collapse: collapse; background: white;">
                            <thead>
                                <tr style="background: #0a2d63; color: white;">
                                    <th style="padding: 12px; text-align: left; font-weight: 600;">Full Name</th>
                                    <th style="padding: 12px; text-align: left; font-weight: 600;">Username</th>
                                    <th style="padding: 12px; text-align: left; font-weight: 600;">Email</th>
                                    <th style="padding: 12px; text-align: left; font-weight: 600;">Role</th>
                                    <th style="padding: 12px; text-align: left; font-weight: 600;">Grade Level</th>
                                    <th style="padding: 12px; text-align: left; font-weight: 600;">Section</th>
                                    <th style="padding: 12px; text-align: left; font-weight: 600;">LRN</th>
                                    <th style="padding: 12px; text-align: left; font-weight: 600;">Created At</th>
                                </tr>
                            </thead>
                            <tbody>
            `;
            
            users.forEach(user => {
                const created = new Date(user.created_at).toLocaleDateString();
                const roleDisplay = user.role ? user.role.charAt(0).toUpperCase() + user.role.slice(1) : 'N/A';
                
                html += `
                    <tr style="border-bottom: 1px solid #e0e0e0;">
                        <td style="padding: 12px;">${user.full_name || 'N/A'}</td>
                        <td style="padding: 12px;">${user.username || 'N/A'}</td>
                        <td style="padding: 12px;">${user.email || 'N/A'}</td>
                        <td style="padding: 12px;">
                            <span style="background: ${user.role === 'admin' || user.role === 'super_admin' ? '#0a2d63' : user.role === 'teacher' ? '#10b981' : '#6c757d'}; color: white; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 600;">
                                ${roleDisplay}
                            </span>
                        </td>
                        <td style="padding: 12px;">${user.grade_level || 'N/A'}</td>
                        <td style="padding: 12px;">${user.section || 'N/A'}</td>
                        <td style="padding: 12px;">${user.lrn || 'N/A'}</td>
                        <td style="padding: 12px;">${created}</td>
                    </tr>
                `;
            });
            
            html += `
                            </tbody>
                        </table>
                    </div>
                </div>
            `;
            
            userList.innerHTML = html;
        }

        // Load enrollments for admin
        function loadEnrollments() {
            fetch('php/get_enrollments.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.enrollments) {
                        displayEnrollments(data.enrollments);
                    }
                })
                .catch(error => console.error('Error loading enrollments:', error));
        }

        function displayEnrollments(enrollments) {
            const enrollmentList = document.getElementById('enrollmentList');
            
            if (enrollments.length === 0) {
                enrollmentList.innerHTML = '<div style="text-align: center; color: #999; padding: 40px 20px;">No enrollment requests yet.</div>';
                return;
            }
            
            let html = '';
            enrollments.forEach(enrollment => {
                const created = new Date(enrollment.created_at).toLocaleDateString();
                const statusText = enrollment.status ? enrollment.status.charAt(0).toUpperCase() + enrollment.status.slice(1) : 'Pending';
                const phone = enrollment.phone.startsWith('+63') ? enrollment.phone : '+63' + enrollment.phone;
                
                html += `
                    <div class="event-item" style="position: relative;">
                        <button class="enrollment-delete-btn" onclick="deleteEnrollment(${enrollment.id}, '${enrollment.full_name}');" style="position: absolute; top: 12px; right: 12px; background: transparent; color: #333; border: none; width: 24px; height: 24px; cursor: pointer; font-size: 24px; display: flex; align-items: center; justify-content: center; line-height: 1; padding: 0;">×</button>
                        <div class="event-details">
                            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
                                <div>
                                    <h4 style="margin: 0 0 5px 0;">${enrollment.full_name}</h4>
                                    <p style="margin: 3px 0; color: #666; font-size: 14px;">${enrollment.email}</p>
                                    <p style="margin: 3px 0; color: #666; font-size: 14px;">Phone: ${phone}</p>
                                </div>
                            </div>
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                                <p style="margin: 0; font-size: 14px; color: #555;">Age: ${enrollment.age} | Gender: ${enrollment.gender} | Birthdate: ${enrollment.birthdate}</p>
                                <span style="background: #e9ecef; padding: 10px 16px; border-radius: 4px; font-size: 14px; font-weight: 600; color: #0a2d63; white-space: nowrap;">${statusText}</span>
                            </div>
                            <p style="margin: 8px 0; font-size: 12px; color: #999;">Submitted: ${created}</p>
                            <p style="margin: 8px 0; font-size: 13px;">
                                <a href="#" onclick="viewDocuments(${enrollment.id}); return false;" style="color: #2563eb; text-decoration: none; cursor: pointer;">View Documents (${enrollment.document_count})</a>
                                &nbsp;|&nbsp;
                                <a href="#" onclick="generatePDF(${enrollment.id}); return false;" style="color: #059669; text-decoration: none; cursor: pointer;">Generate PDF</a>
                            </p>
                            <div style="display: flex; gap: 8px; margin-top: 12px;">
                                <button class="status-btn" onclick="updateStatus(${enrollment.id}, 'approved')" style="background: #10b981; color: white; padding: 6px 12px; border: none; border-radius: 4px; cursor: pointer; font-size: 13px;">Accept</button>
                                <button class="status-btn" onclick="updateStatus(${enrollment.id}, 'needs_docs')" style="background: #f59e0b; color: white; padding: 6px 12px; border: none; border-radius: 4px; cursor: pointer; font-size: 13px;">Request Docs</button>
                                <button class="status-btn" onclick="updateStatus(${enrollment.id}, 'rejected')" style="background: #ef4444; color: white; padding: 6px 12px; border: none; border-radius: 4px; cursor: pointer; font-size: 13px;">Reject</button>
                            </div>
                        </div>
                    </div>
                `;
            });
            
            enrollmentList.innerHTML = html;
        }

        // Load students for Payables Management tab
        function loadStudents() {
            const studentSelect = document.getElementById('studentSelect');
            if (!studentSelect) {
                console.log('studentSelect element not found - maybe not on payables management tab');
                return;
            }
        
            studentSelect.innerHTML = '<option value="">Loading students...</option>';
        
            fetch('php/get_users.php')
                .then(response => response.json())
                .then(data => {
                    console.log('Students data received:', data); // Debug log
                    if (data.success && data.users) {
                        let options = '<option value="">Select Student</option>';
                        let studentCount = 0;
                    
                        data.users.forEach(user => {
                            if (user.role === 'student') {
                                studentCount++;
                                const displayText = `${user.full_name}${user.grade_level ? ` (${user.grade_level}` : ''}${user.section ? ` - ${user.section}` : ''}${user.grade_level ? ')' : ''}`;
                                options += `<option value="${user.id}">${displayText}</option>`;
                            }
                        });
                        
                        studentSelect.innerHTML = options;
                        console.log(`Loaded ${studentCount} students for payables management`);
                    } else {
                        studentSelect.innerHTML = '<option value="">No students found</option>';
                        console.error('Failed to load users:', data.message);
                    }
                })
                .catch(error => {
                    console.error('Error loading students:', error);
                    studentSelect.innerHTML = '<option value="">Error loading students</option>';
                });
        }

        // Load students for Payment Processing
        function loadPaymentStudents() {
            const studentSelect = document.getElementById('paymentStudentSelect');
            if (!studentSelect) return;
            
            studentSelect.innerHTML = '<option value="">Loading students...</option>';
            
            fetch('php/get_users.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.users) {
                        let options = '<option value="">Select Student</option>';
                        data.users.forEach(user => {
                            if (user.role === 'student') {
                                options += `<option value="${user.id}">${user.full_name} (${user.grade_level || ''} - ${user.section || ''})</option>`;
                            }
                        });
                        studentSelect.innerHTML = options;
                    } else {
                        studentSelect.innerHTML = '<option value="">Error loading students</option>';
                    }
                })
                .catch(error => {
                    console.error('Error loading students:', error);
                    studentSelect.innerHTML = '<option value="">Error loading students</option>';
                });
        }

        // Calculate payables
        function calculatePayables() {
            const studentId = document.getElementById('studentSelect').value;
            const tuitionFee = parseFloat(document.getElementById('tuitionFee').value);
            const downPayment = parseFloat(document.getElementById('downPayment').value);
            const discounts = parseFloat(document.getElementById('discounts').value) || 0;
            const monthlyPayments = parseInt(document.getElementById('monthlyPayments').value) || 4;
            
            if (!studentId) {
                alert('Please select a student');
                return;
            }
            
            if (!tuitionFee || tuitionFee <= 0) {
                alert('Please enter a valid tuition fee');
                return;
            }
            
            if (!downPayment || downPayment < 0) {
                alert('Please enter a valid down payment');
                return;
            }
            
            if (downPayment > tuitionFee) {
                alert('Down payment cannot be greater than tuition fee');
                return;
            }
            
            // Calculate remaining balance
            const totalPayable = tuitionFee - discounts;
            const remainingBalance = totalPayable - downPayment;
            const monthlyPaymentAmount = remainingBalance / monthlyPayments;
            
            // Display result
            const resultContent = document.getElementById('resultContent');
            const calculationResult = document.getElementById('calculationResult');
            const addPayableBtn = document.getElementById('addPayableBtn');
            
            resultContent.innerHTML = `
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 15px;">
                    <div>
                        <strong>Total Tuition Fee:</strong>
                        <div style="font-size: 18px; color: #0a2d63;">₱${tuitionFee.toFixed(2)}</div>
                    </div>
                    <div>
                        <strong>Discounts/Grants:</strong>
                        <div style="font-size: 18px; color: #10b981;">₱${discounts.toFixed(2)}</div>
                    </div>
                    <div>
                        <strong>Total Payable:</strong>
                        <div style="font-size: 18px; color: #0a2d63;">₱${totalPayable.toFixed(2)}</div>
                    </div>
                    <div>
                        <strong>Down Payment:</strong>
                        <div style="font-size: 18px; color: #f59e0b;">₱${downPayment.toFixed(2)}</div>
                    </div>
                </div>
                <div style="text-align: center; padding: 15px; background: #f0f7ff; border-radius: 8px; margin: 15px 0;">
                    <strong style="display: block; margin-bottom: 5px; color: #0a2d63;">Remaining Balance:</strong>
                    <div style="font-size: 24px; font-weight: 700; color: #0a2d63;">₱${remainingBalance.toFixed(2)}</div>
                </div>
                <div style="text-align: center; padding: 15px; background: #f0fff4; border-radius: 8px;">
                    <strong style="display: block; margin-bottom: 5px; color: #10b981;">Monthly Payment (${monthlyPayments} months):</strong>
                    <div style="font-size: 20px; font-weight: 700; color: #10b981;">₱${monthlyPaymentAmount.toFixed(2)}</div>
                </div>
            `;
            
            calculationResult.style.display = 'block';
            addPayableBtn.style.display = 'inline-block';
            
            // Store calculated values for later use
            window.calculatedPayables = {
                studentId: studentId,
                tuitionFee: tuitionFee,
                discounts: discounts,
                downPayment: downPayment,
                remainingBalance: remainingBalance,
                monthlyPayments: monthlyPayments,
                monthlyPaymentAmount: monthlyPaymentAmount
            };
        }

        // Add payable to student
        function addPayable() {
            if (!window.calculatedPayables) {
                alert('Please calculate payables first');
                return;
            }
            
            const { studentId, tuitionFee, discounts, downPayment, remainingBalance, monthlyPayments, monthlyPaymentAmount } = window.calculatedPayables;
            
            // Get student name for display
            const studentSelect = document.getElementById('studentSelect');
            const studentName = studentSelect.options[studentSelect.selectedIndex].text;
            
            // Show confirmation dialog
            if (!confirm(`Add payables for ${studentName}?\n\nTotal: ₱${tuitionFee.toFixed(2)}\nDiscounts: ₱${discounts.toFixed(2)}\nDown Payment: ₱${downPayment.toFixed(2)}\nRemaining Balance: ₱${remainingBalance.toFixed(2)}\nMonthly Payment: ₱${monthlyPaymentAmount.toFixed(2)} x ${monthlyPayments} months`)) {
                return;
            }
            
            // Prepare data for API
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
                    // Reset form
                    document.getElementById('payablesForm').reset();
                    document.getElementById('calculationResult').style.display = 'none';
                    document.getElementById('addPayableBtn').style.display = 'none';
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

        // Load student payables for payment processing
        function loadStudentPayables() {
            const studentId = document.getElementById('paymentStudentSelect').value;
            
            if (!studentId || studentId === "") {
                alert('Please select a student first');
                return;
            }
            
            const payablesList = document.getElementById('payablesList');
            payablesList.innerHTML = '<div class="loading">Loading payables...</div>';
            document.getElementById('studentPayables').style.display = 'block';
            
            fetch('php/get_student_payables.php?student_id=' + studentId)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.payables) {
                        displayStudentPayables(data.payables);
                    } else {
                        payablesList.innerHTML = '<div style="text-align: center; padding: 40px; color: #666;">No payables found for this student.</div>';
                    }
                })
                .catch(error => {
                    console.error('Error loading student payables:', error);
                    payablesList.innerHTML = '<div style="text-align: center; padding: 40px; color: #dc2626;">Error loading payables</div>';
                });
        }
        
        function displayStudentPayables(payables) {
            const payablesList = document.getElementById('payablesList');
            
            if (payables.length === 0) {
                payablesList.innerHTML = '<div style="text-align: center; padding: 40px; color: #666;">No payables found for this student.</div>';
                return;
            }
            
            let html = '<div class="payables-grid">';
            html += '<div class="payables-header">Description</div>';
            html += '<div class="payables-header">Amount</div>';
            html += '<div class="payables-header">Due Date</div>';
            html += '<div class="payables-header">Status</div>';
            
            payables.forEach(payable => {
                const dueDate = new Date(payable.due_date);
                const today = new Date();
                const isOverdue = dueDate < today && payable.status !== 'paid';
                let statusClass = 'status-pending';
                let statusText = 'Pending';
                
                if (payable.status === 'paid') {
                    statusClass = 'status-paid';
                    statusText = 'Paid';
                } else if (isOverdue) {
                    statusClass = 'status-overdue';
                    statusText = 'Overdue';
                }
                
                html += `
                    <div class="payables-row">
                        <div>${payable.item_name}</div>
                        <div>₱${parseFloat(payable.amount).toFixed(2)}</div>
                        <div>${dueDate.toLocaleDateString()}</div>
                        <div><span class="status-badge ${statusClass}">${statusText}</span></div>
                    </div>
                `;
            });
            
            html += '</div>';
            payablesList.innerHTML = html;
        }
        
        // Process payment
        function processPayment() {
            const studentId = document.getElementById('paymentStudentSelect').value;
            const amount = document.getElementById('paymentAmount').value;
            const paymentDate = document.getElementById('paymentDate').value;
            
            if (!studentId || studentId === "") {
                alert('Please select a student');
                return;
            }
            
            if (!amount || parseFloat(amount) <= 0) {
                alert('Please enter a valid payment amount');
                return;
            }
            
            const formData = new FormData();
            formData.append('student_id', studentId);
            formData.append('amount', amount);
            formData.append('payment_date', paymentDate);
            
            fetch('php/process_payment.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                const paymentResult = document.getElementById('paymentResult');
                if (data.success) {
                    paymentResult.style.display = 'block';
                    paymentResult.innerHTML = data.message;
                    
                    // Reset form
                    document.getElementById('paymentAmount').value = '';
                    document.getElementById('paymentDate').value = '<?php echo date('Y-m-d'); ?>';
                    
                    // Reload student payables
                    loadStudentPayables();
                } else {
                    alert('Error processing payment: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error processing payment:', error);
                alert('Error processing payment');
            });
        }
        
        // Toggle student-specific fields based on role selection
        function toggleStudentFields() {
            const roleSelect = document.getElementById('roleSelect');
            const studentFields = document.getElementById('studentFields');
            const gradeLevel = document.getElementById('gradeLevel');
            const sectionSelect = document.getElementById('sectionSelect');
            const lrnField = document.getElementById('lrnField');
            
            if (roleSelect.value === 'student') {
                studentFields.style.display = 'block';
                gradeLevel.required = true;
                sectionSelect.required = true;
                lrnField.required = true;
            } else {
                studentFields.style.display = 'none';
                gradeLevel.required = false;
                sectionSelect.required = false;
                lrnField.required = false;
                // Clear student fields
                gradeLevel.value = '';
                sectionSelect.innerHTML = '<option value="">Select Section</option>';
                lrnField.value = '';
            }
        }

        // Update available sections based on selected grade level
        function updateSections() {
            const gradeLevel = document.getElementById('gradeLevel').value;
            const sectionSelect = document.getElementById('sectionSelect');
            
            // Define grade-section mapping
            const gradeSections = {
                'Grade 7': ['Love', 'Joy'],
                'Grade 8': ['Patience', 'Peace'],
                'Grade 9': ['Goodness', 'Kindness'],
                'Grade 10': ['Gentleness', 'Faithfulness'],
                'Grade 11': ['Self-Control', 'Honesty'],
                'Grade 12': ['Humility', 'Meekness']
            };
            
            // Clear current options
            sectionSelect.innerHTML = '<option value="">Select Section</option>';
            
            // Add options based on selected grade
            if (gradeLevel && gradeSections[gradeLevel]) {
                gradeSections[gradeLevel].forEach(section => {
                    const option = document.createElement('option');
                    option.value = section;
                    option.textContent = section;
                    sectionSelect.appendChild(option);
                });
            }
        }

        // Other admin helper functions
        function updateStatus(enrollmentId, status) {
            const statusText = status === 'approved' ? 'accept' : status === 'rejected' ? 'reject' : 'request documents';
            if (confirm(`Are you sure you want to ${statusText} this enrollment?`)) {
                fetch('php/update_enrollment_status.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
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
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
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


        // Update clock immediately and every second
        updateLiveClock();
        setInterval(updateLiveClock, 1000);

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Add event listener for Show Users button
            const showUsersBtn = document.getElementById('showUsersBtn');
            if (showUsersBtn) {
                showUsersBtn.addEventListener('click', loadUsers);
            }
            
            // Add event listener for Create User form
            const createUserForm = document.getElementById('createUserForm');
            if (createUserForm) {
                createUserForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    const formData = new FormData(this);
                    
                    // If role is not student, remove student-specific fields
                    const role = document.getElementById('roleSelect').value;
                    if (role !== 'student') {
                        formData.delete('gradeLevel');
                        formData.delete('section');
                        formData.delete('lrn');
                    }
                    
                    fetch('php/handle_user.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert('User created successfully!');
                            this.reset();
                            // Reset student fields visibility
                            document.getElementById('studentFields').style.display = 'none';
                            document.getElementById('gradeLevel').required = false;
                            document.getElementById('sectionSelect').required = false;
                            document.getElementById('lrnField').required = false;
                            document.getElementById('sectionSelect').innerHTML = '<option value="">Select Section</option>';
                        } else {
                            alert('Error creating user: ' + data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Error creating user:', error);
                        alert('Error creating user');
                    });
                });
            }
            
            <?php if (in_array($userRole, ['admin', 'super_admin'])): ?>
            loadEnrollments();
            setInterval(loadEnrollments, 30000);
            <?php endif; ?>
        });

        // Sidebar toggle
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            sidebar.classList.toggle('open');
            overlay.classList.toggle('active');
        }
    </script>
</body>
</html>