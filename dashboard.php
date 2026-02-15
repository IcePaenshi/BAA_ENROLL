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

// Get total enrollment count
$totalEnrollments = 0;
if (in_array($userRole, ['admin', 'super_admin'])) {
    try {
        $countStmt = $pdo->query("SELECT COUNT(*) FROM enrollments");
        $totalEnrollments = $countStmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Error counting enrollments: " . $e->getMessage());
    }
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

        /* COMMON STYLES */
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

        /* Modal Styles */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal-container {
            background: white;
            border-radius: 8px;
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 4px 20px rgba(0,0,0,0.2);
        }

        .modal-header {
            padding: 20px 25px;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f8f9fa;
            border-radius: 8px 8px 0 0;
        }

        .modal-header h3 {
            margin: 0;
            color: #0a2d63;
            font-size: 20px;
            font-weight: 600;
        }

        .modal-close {
            background: transparent;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #666;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
        }

        .modal-close:hover {
            background: #e0e0e0;
            color: #333;
        }

        .modal-body {
            padding: 25px;
        }

        .modal-footer {
            padding: 20px 25px;
            border-top: 1px solid #e0e0e0;
            text-align: right;
            background: #f8f9fa;
            border-radius: 0 0 8px 8px;
        }

        .modal-footer button {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            margin-left: 10px;
        }

        .modal-footer .btn-cancel {
            background: #6c757d;
            color: white;
        }

        .modal-footer .btn-cancel:hover {
            background: #5a6268;
        }

        .modal-footer .btn-confirm {
            background: #0a2d63;
            color: white;
        }

        .modal-footer .btn-confirm:hover {
            background: #08306b;
        }

        .modal-footer .btn-delete {
            background: #dc2626;
            color: white;
        }

        .modal-footer .btn-delete:hover {
            background: #b91c1c;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #333;
            font-size: 14px;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            color: #333;
            background: white;
            transition: border 0.3s;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #0a2d63;
            box-shadow: 0 0 0 2px rgba(10, 45, 99, 0.1);
        }

        .student-fields {
            display: none;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 4px;
            margin-top: 10px;
        }

        .checkbox-group {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 15px;
        }

        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .checkbox-item input[type="checkbox"] {
            width: auto;
            margin-right: 5px;
        }

        .search-results {
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid #e0e0e0;
            border-radius: 4px;
            margin-top: 15px;
        }

        .search-result-item {
            padding: 12px 15px;
            border-bottom: 1px solid #e0e0e0;
            cursor: pointer;
            transition: background 0.2s;
        }

        .search-result-item:hover {
            background: #f0f2f5;
        }

        .search-result-item:last-child {
            border-bottom: none;
        }

        .search-result-item .user-name {
            font-weight: 600;
            color: #0a2d63;
            margin-bottom: 3px;
        }

        .search-result-item .user-details {
            font-size: 12px;
            color: #666;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .search-result-item .user-role {
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .role-badge-student { background: #6c757d; color: white; }
        .role-badge-teacher { background: #10b981; color: white; }
        .role-badge-admin { background: #0a2d63; color: white; }
        .role-badge-super_admin { background: #7c3aed; color: white; }

        .user-delete-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 15px;
            border-bottom: 1px solid #e0e0e0;
        }

        .user-delete-item:last-child {
            border-bottom: none;
        }

        .delete-checkbox {
            width: 20px;
            height: 20px;
            cursor: pointer;
        }

        .filter-section {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 15px;
        }

        .filter-section h4 {
            margin: 0 0 10px 0;
            color: #0a2d63;
            font-size: 14px;
            font-weight: 600;
        }

        .sort-options {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }

        .sort-option {
            padding: 6px 12px;
            border: 1px solid #ddd;
            border-radius: 20px;
            cursor: pointer;
            font-size: 13px;
            transition: all 0.2s;
        }

        .sort-option:hover {
            background: #e0e0e0;
        }

        .sort-option.active {
            background: #0a2d63;
            color: white;
            border-color: #0a2d63;
        }

        /* Document Modal Styling */
        .document-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 20px;
            margin-top: 15px;
        }

        .document-item {
            background: #f8f9fa;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 20px 15px;
            text-align: center;
            transition: transform 0.2s, box-shadow 0.2s;
            display: flex;
            flex-direction: column;
        }

        .document-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .document-icon {
            font-size: 14px !important;
            font-weight: bold;
            color: #0a2d63;
            background: #e9ecef;
            padding: 8px 12px;
            border-radius: 4px;
            display: inline-block;
            margin: 0 auto 15px auto;
            width: fit-content;
            font-family: monospace;
            letter-spacing: 0.5px;
        }

        .document-name {
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
            word-break: break-word;
            font-size: 16px;
        }

        .document-type {
            font-size: 12px;
            color: #666;
            margin-bottom: 10px;
            word-break: break-word;
            background: #f1f3f5;
            padding: 4px 8px;
            border-radius: 4px;
            display: inline-block;
            margin-left: auto;
            margin-right: auto;
        }

        .document-actions {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-top: 10px;
        }

        .document-btn {
            padding: 6px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            text-decoration: none;
            display: inline-block;
            transition: background 0.2s;
        }

        .btn-view {
            background: #0a2d63;
            color: white;
        }

        .btn-view:hover {
            background: #08306b;
        }

        .btn-download {
            background: #28a745;
            color: white;
        }

        .btn-download:hover {
            background: #218838;
        }

        /* Enrollment Controls */
        .enrollment-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
        }

        .enrollment-stats {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .enrollment-count {
            background: #0a2d63;
            color: white;
            padding: 8px 15px;
            border-radius: 4px;
            font-weight: 600;
            font-size: 14px;
        }

        .search-enrollment-btn {
            background: #0a2d63;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .search-enrollment-btn:hover {
            background: #08306b;
        }

        /* Pagination Controls */
        .pagination-controls {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            flex-wrap: wrap;
        }

        .pagination-controls select {
            padding: 6px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }

        .pagination-buttons {
            display: flex;
            gap: 5px;
            margin-left: auto;
        }

        .pagination-btn {
            padding: 6px 12px;
            border: 1px solid #ddd;
            background: white;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }

        .pagination-btn:hover {
            background: #e9ecef;
        }

        .pagination-btn.active {
            background: #0a2d63;
            color: white;
            border-color: #0a2d63;
        }

        .pagination-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .pagination-info {
            color: #666;
            font-size: 14px;
            margin-left: 10px;
        }

        .custom-per-page {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .custom-per-page input {
            width: 60px;
            padding: 6px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }

        .custom-per-page button {
            padding: 6px 12px;
            background: #0a2d63;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }

        .custom-per-page button:hover {
            background: #08306b;
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
                        <!-- Search Icon Button -->
                        <button onclick="openSearchModal()" style="background: transparent; border: none; cursor: pointer; margin-right: 15px; display: flex; align-items: center;">
                            <img src="images/search_icon.png" alt="Search" style="width: 24px; height: 24px;">
                        </button>
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
                                <div class="enrollment-controls">
                                    <div class="enrollment-stats">
                                        <h3>Student Access Requests</h3>
                                        <span class="enrollment-count" id="enrollmentCount">Total Number of Enrolees: <?php echo $totalEnrollments; ?></span>
                                    </div>
                                    <button class="search-enrollment-btn" onclick="openEnrollmentSearchModal()">
                                        Search Enrollees
                                    </button>
                                </div>
                                <p>Review and manage pending student enrollments</p>
                                
                                <div id="enrollmentList">
                                    <div style="text-align: center; color: #999; padding: 40px 20px;">
                                        Loading enrollments...
                                    </div>
                                </div>

                                <!-- Pagination Controls -->
                                <div id="enrollmentPagination" class="pagination-controls" style="display: none;">
                                    <div class="custom-per-page">
                                        <span>Show:</span>
                                        <select id="perPageSelect" onchange="changePerPage()">
                                            <option value="10">10</option>
                                            <option value="25">25</option>
                                            <option value="50">50</option>
                                            <option value="75">75</option>
                                            <option value="100">100</option>
                                            <option value="custom">Custom</option>
                                        </select>
                                        <div id="customPerPageInput" style="display: none;">
                                            <input type="number" id="customPerPage" min="1" max="500" placeholder="Number">
                                            <button onclick="applyCustomPerPage()">Apply</button>
                                        </div>
                                    </div>
                                    <div class="pagination-info" id="paginationInfo"></div>
                                    <div class="pagination-buttons" id="paginationButtons"></div>
                                </div>
                            </div>
                        </div>

                        <!-- User Management Card -->
                        <div class="dashboard-card users-card" id="usersCard">
                            <div class="card-content">
                                <h3>User Management</h3>
                                <p>Manage user accounts - add and delete users</p>

                                <!-- User Management Actions -->
                                <div style="display: flex; gap: 30px; margin-top: 50px; justify-content: center;">
                                    <button onclick="openAddUserModal()" style="background: #28a745; color: white; border: none; padding: 12px 24px; border-radius: 4px; cursor: pointer; font-weight: 500; display: flex; align-items: center; gap: 8px;">
                                        Add User
                                    </button>
                                    <button onclick="openDeleteUserModal()" style="background: #dc2626; color: white; border: none; padding: 12px 24px; border-radius: 4px; cursor: pointer; font-weight: 500; display: flex; align-items: center; gap: 8px;">
                                        Delete User
                                    </button>
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

                        <!-- Add User Modal -->
                        <div id="addUserModal" class="modal-overlay">
                            <div class="modal-container">
                                <div class="modal-header">
                                    <h3>Add New User</h3>
                                    <button class="modal-close" onclick="closeAddUserModal()">×</button>
                                </div>
                                <div class="modal-body">
                                    <form id="createUserForm">
                                        <div class="form-group">
                                            <label>Username *</label>
                                            <input type="text" name="username" required>
                                        </div>
                                        <div class="form-group">
                                            <label>Email *</label>
                                            <input type="email" name="email" required>
                                        </div>
                                        <div class="form-group">
                                            <label>Password *</label>
                                            <input type="password" name="password" required>
                                        </div>
                                        <div class="form-group">
                                            <label>Full Name *</label>
                                            <input type="text" name="fullName" required>
                                        </div>
                                        <div class="form-group">
                                            <label>Role *</label>
                                            <select name="role" id="modalRoleSelect" onchange="toggleModalStudentFields()" required>
                                                <option value="">Select Role</option>
                                                <option value="student">Student</option>
                                                <option value="teacher">Teacher</option>
                                                <?php if ($userRole == 'super_admin'): ?>
                                                <option value="admin">Admin</option>
                                                <?php endif; ?>
                                            </select>
                                        </div>
                                        
                                        <!-- Student-specific fields -->
                                        <div id="modalStudentFields" class="student-fields">
                                            <div class="form-group">
                                                <label>Grade Level *</label>
                                                <select name="gradeLevel" id="modalGradeLevel" onchange="updateModalSections()">
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
                                                <label>Section *</label>
                                                <select name="section" id="modalSectionSelect">
                                                    <option value="">Select Section</option>
                                                </select>
                                            </div>
                                            <div class="form-group">
                                                <label>LRN *</label>
                                                <input type="text" name="lrn" id="modalLrnField">
                                            </div>
                                        </div>
                                    </form>
                                </div>
                                <div class="modal-footer">
                                    <button class="btn-cancel" onclick="closeAddUserModal()">Cancel</button>
                                    <button class="btn-confirm" onclick="submitAddUser()">Add User</button>
                                </div>
                            </div>
                        </div>

                        <!-- Search Users Modal -->
                        <div id="searchUserModal" class="modal-overlay">
                            <div class="modal-container" style="max-width: 700px;">
                                <div class="modal-header">
                                    <h3>Search Users</h3>
                                    <button class="modal-close" onclick="closeSearchModal()">×</button>
                                </div>
                                <div class="modal-body">
                                    <!-- Search Input -->
                                    <div class="form-group">
                                        <label>Search by name, email, or username</label>
                                        <input type="text" id="searchInput" placeholder="Type to search..." onkeyup="performSearch()">
                                    </div>

                                    <!-- Filters Section -->
                                    <div class="filter-section">
                                        <h4>Filter by Role</h4>
                                        <div class="checkbox-group">
                                            <div class="checkbox-item">
                                                <input type="checkbox" id="filterStudent" value="student" onchange="applyFilters()">
                                                <label for="filterStudent">Student</label>
                                            </div>
                                            <div class="checkbox-item">
                                                <input type="checkbox" id="filterTeacher" value="teacher" onchange="applyFilters()">
                                                <label for="filterTeacher">Teacher</label>
                                            </div>
                                            <div class="checkbox-item">
                                                <input type="checkbox" id="filterAdmin" value="admin" onchange="applyFilters()">
                                                <label for="filterAdmin">Admin</label>
                                            </div>
                                            <?php if ($userRole == 'super_admin'): ?>
                                            <div class="checkbox-item">
                                                <input type="checkbox" id="filterSuperAdmin" value="super_admin" onchange="applyFilters()">
                                                <label for="filterSuperAdmin">Super Admin</label>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="filter-section">
                                        <h4>Filter by Grade Level</h4>
                                        <div class="form-group" style="margin-bottom: 10px;">
                                            <select id="filterGradeLevel" onchange="updateFilterSections(); applyFilters();">
                                                <option value="">All Grade Levels</option>
                                                <option value="Grade 7">Grade 7</option>
                                                <option value="Grade 8">Grade 8</option>
                                                <option value="Grade 9">Grade 9</option>
                                                <option value="Grade 10">Grade 10</option>
                                                <option value="Grade 11">Grade 11</option>
                                                <option value="Grade 12">Grade 12</option>
                                            </select>
                                        </div>
                                        <div id="filterSectionContainer" style="display: none;">
                                            <label>Section</label>
                                            <select id="filterSection" onchange="applyFilters()">
                                                <option value="">All Sections</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="filter-section">
                                        <h4>Sort By</h4>
                                        <div class="sort-options">
                                            <span class="sort-option active" onclick="setSort('name')" id="sort-name">Name</span>
                                            <span class="sort-option" onclick="setSort('role')" id="sort-role">Role</span>
                                            <span class="sort-option" onclick="setSort('grade')" id="sort-grade">Grade Level</span>
                                            <span class="sort-option" onclick="setSort('date')" id="sort-date">Date Joined</span>
                                        </div>
                                    </div>

                                    <!-- Search Results -->
                                    <div id="searchResults" class="search-results">
                                        <div style="text-align: center; padding: 40px; color: #666;">
                                            Start typing to search for users
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button class="btn-cancel" onclick="closeSearchModal()">Close</button>
                                </div>
                            </div>
                        </div>

                        <!-- Enrollment Search Modal -->
                        <div id="enrollmentSearchModal" class="modal-overlay">
                            <div class="modal-container" style="max-width: 700px;">
                                <div class="modal-header">
                                    <h3>Search Enrollees</h3>
                                    <button class="modal-close" onclick="closeEnrollmentSearchModal()">×</button>
                                </div>
                                <div class="modal-body">
                                    <!-- Search Input -->
                                    <div class="form-group">
                                        <label>Search by name, email, or phone</label>
                                        <input type="text" id="enrollmentSearchInput" placeholder="Type to search..." onkeyup="filterEnrollments()">
                                    </div>

                                    <!-- Status Filters -->
                                    <div class="filter-section">
                                        <h4>Filter by Status</h4>
                                        <div class="checkbox-group">
                                            <div class="checkbox-item">
                                                <input type="checkbox" id="filterPending" value="pending" onchange="filterEnrollments()" checked>
                                                <label for="filterPending">Pending</label>
                                            </div>
                                            <div class="checkbox-item">
                                                <input type="checkbox" id="filterApproved" value="approved" onchange="filterEnrollments()" checked>
                                                <label for="filterApproved">Approved</label>
                                            </div>
                                            <div class="checkbox-item">
                                                <input type="checkbox" id="filterNeedsDocs" value="needs_docs" onchange="filterEnrollments()" checked>
                                                <label for="filterNeedsDocs">Needs Documents</label>
                                            </div>
                                            <div class="checkbox-item">
                                                <input type="checkbox" id="filterRejected" value="rejected" onchange="filterEnrollments()" checked>
                                                <label for="filterRejected">Rejected</label>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Results Per Page -->
                                    <div class="filter-section">
                                        <h4>Results Per Page</h4>
                                        <div class="custom-per-page" style="margin-top: 10px;">
                                            <select id="enrollmentPerPage" onchange="changeEnrollmentPerPage()">
                                                <option value="10">10</option>
                                                <option value="25">25</option>
                                                <option value="50">50</option>
                                                <option value="75">75</option>
                                                <option value="100">100</option>
                                                <option value="custom">Custom</option>
                                            </select>
                                            <div id="enrollmentCustomPerPage" style="display: none;">
                                                <input type="number" id="enrollmentCustomNumber" min="1" max="500" placeholder="Number">
                                                <button onclick="applyEnrollmentCustomPerPage()">Apply</button>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Search Results -->
                                    <div id="enrollmentSearchResults" class="search-results" style="max-height: 400px;">
                                        <div style="text-align: center; padding: 40px; color: #666;">
                                            Loading enrollments...
                                        </div>
                                    </div>

                                    <!-- Pagination for Search Results -->
                                    <div id="enrollmentSearchPagination" class="pagination-controls" style="margin-top: 15px; display: none;">
                                        <div class="pagination-info" id="enrollmentSearchInfo"></div>
                                        <div class="pagination-buttons" id="enrollmentSearchButtons"></div>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button class="btn-cancel" onclick="closeEnrollmentSearchModal()">Close</button>
                                </div>
                            </div>
                        </div>

                        <!-- Document View Modal -->
                        <div id="documentModal" class="modal-overlay">
                            <div class="modal-container" style="max-width: 800px;">
                                <div class="modal-header">
                                    <h3>Student Documents</h3>
                                    <button class="modal-close" onclick="closeDocumentModal()">×</button>
                                </div>
                                <div class="modal-body">
                                    <div id="documentList" style="min-height: 200px;">
                                        <div style="text-align: center; padding: 40px; color: #666;">
                                            Loading documents...
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button class="btn-cancel" onclick="closeDocumentModal()">Close</button>
                                </div>
                            </div>
                        </div>

                        <!-- Delete User Modal -->
                        <div id="deleteUserModal" class="modal-overlay">
                            <div class="modal-container" style="max-width: 600px;">
                                <div class="modal-header">
                                    <h3>Delete Users</h3>
                                    <button class="modal-close" onclick="closeDeleteUserModal()">×</button>
                                </div>
                                <div class="modal-body">
                                    <p style="margin-bottom: 20px; color: #666;">Select users to delete. This action cannot be undone.</p>
                                    
                                    <!-- Search within delete modal -->
                                    <div class="form-group">
                                        <input type="text" id="deleteSearchInput" placeholder="Search users..." onkeyup="loadDeleteUserList()">
                                    </div>

                                    <!-- User List for Deletion -->
                                    <div id="deleteUserList" style="max-height: 300px; overflow-y: auto; border: 1px solid #e0e0e0; border-radius: 4px;">
                                        <div style="text-align: center; padding: 40px; color: #666;">
                                            Loading users...
                                        </div>
                                    </div>

                                    <!-- Selected Count -->
                                    <div style="margin-top: 15px; font-size: 14px; color: #666;">
                                        <span id="selectedCount">0</span> user(s) selected
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button class="btn-cancel" onclick="closeDeleteUserModal()">Cancel</button>
                                    <button class="btn-delete" onclick="confirmDeleteUsers()">Delete Selected</button>
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
                    loadUsers();
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

        // ADMIN FUNCTIONS
        // Load and display all users
        function loadUsers() {
            const userList = document.getElementById('userList');
            userList.innerHTML = '<div class="loading">Loading users...</div>';

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

        // Load enrollments for admin with pagination
        let currentPage = 1;
        let perPage = 10;
        let totalEnrollments = <?php echo $totalEnrollments; ?>;
        let allEnrollments = [];

        function loadEnrollments(page = currentPage) {
            currentPage = page;
            fetch(`php/get_enrollments.php?page=${currentPage}&per_page=${perPage}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.enrollments) {
                        allEnrollments = data.enrollments;
                        displayEnrollments(allEnrollments);
                        updatePagination(data.total, data.page, data.per_page);
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

        function updatePagination(total, page, perPage) {
            const paginationDiv = document.getElementById('enrollmentPagination');
            const paginationInfo = document.getElementById('paginationInfo');
            const paginationButtons = document.getElementById('paginationButtons');
            
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
            
            // Previous button
            buttonsHtml += `<button class="pagination-btn" onclick="loadEnrollments(${page - 1})" ${page === 1 ? 'disabled' : ''}>Previous</button>`;
            
            // Page numbers
            for (let i = 1; i <= totalPages; i++) {
                if (i === 1 || i === totalPages || (i >= page - 2 && i <= page + 2)) {
                    buttonsHtml += `<button class="pagination-btn ${i === page ? 'active' : ''}" onclick="loadEnrollments(${i})">${i}</button>`;
                } else if (i === page - 3 || i === page + 3) {
                    buttonsHtml += `<button class="pagination-btn" disabled>...</button>`;
                }
            }
            
            // Next button
            buttonsHtml += `<button class="pagination-btn" onclick="loadEnrollments(${page + 1})" ${page === totalPages ? 'disabled' : ''}>Next</button>`;
            
            paginationButtons.innerHTML = buttonsHtml;
        }

        function changePerPage() {
            const select = document.getElementById('perPageSelect');
            const customInput = document.getElementById('customPerPageInput');
            
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
            const customValue = document.getElementById('customPerPage').value;
            if (customValue && customValue > 0) {
                perPage = parseInt(customValue);
                currentPage = 1;
                loadEnrollments(1);
                document.getElementById('customPerPageInput').style.display = 'none';
                document.getElementById('perPageSelect').value = 'custom';
            }
        }

        // View documents function
        function viewDocuments(enrollmentId) {
            if (!enrollmentId) {
                alert('Invalid enrollment ID');
                return;
        }
    
                document.getElementById('documentModal').style.display = 'flex';
                const documentList = document.getElementById('documentList');
                documentList.innerHTML = '<div class="loading">Loading documents...</div>';
    
            fetch(`php/get_enrollment_documents.php?enrollment_id=${enrollmentId}`)
            .then(response => response.json())
            .then(data => {
            console.log('Documents data received:', data); // Debug log
            
            if (data.success) {
                // Check if documents array exists and has items
                if (data.documents && Array.isArray(data.documents) && data.documents.length > 0) {
                    let html = '<div class="document-grid">';
                    
                    data.documents.forEach(doc => {
                        // Get filename - check different possible field names
                        let fileName = doc.document_filename || doc.file_name || 'Document';
                        let fileExt = '';
                        
                        // Safely split filename
                        if (fileName && typeof fileName === 'string' && fileName.includes('.')) {
                            fileExt = fileName.split('.').pop().toLowerCase();
                        }
                        
                        // Determine icon based on file extension (text-based)
                        let icon = '[FILE]'; // Default icon
                        if (fileExt) {
                            if (['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'].includes(fileExt)) 
                                icon = '[IMAGE]';
                            else if (['pdf'].includes(fileExt)) 
                                icon = '[PDF]';
                            else if (['doc', 'docx'].includes(fileExt)) 
                                icon = '[DOC]';
                            else if (['xls', 'xlsx', 'csv'].includes(fileExt)) 
                                icon = '[SPREADSHEET]';
                            else if (['ppt', 'pptx'].includes(fileExt)) 
                                icon = '[PRESENTATION]';
                            else if (['txt', 'rtf'].includes(fileExt)) 
                                icon = '[TEXT]';
                            else if (['zip', 'rar', '7z'].includes(fileExt)) 
                                icon = '[ARCHIVE]';
                        }
                        
                        // Get document type or use filename as fallback
                        let docType = doc.document_type || 'Document';
                        
                        // Get file path - handle different possible field names
                        let filePath = doc.document_path || doc.file_path || doc.path || '';
                        
                        // Make sure the path is correct for viewing
                        if (filePath && !filePath.startsWith('/') && !filePath.startsWith('http')) {
                            // Ensure the path is relative to the root
                            filePath = filePath;
                        }
                        
                        // Format file size
                        let fileSize = '';
                        if (doc.file_size) {
                            let size = parseInt(doc.file_size);
                            if (size < 1024) {
                                fileSize = size + ' B';
                            } else if (size < 1024 * 1024) {
                                fileSize = (size / 1024).toFixed(1) + ' KB';
                            } else {
                                fileSize = (size / (1024 * 1024)).toFixed(1) + ' MB';
                            }
                        }
                        
                        // Format date
                        let uploadDate = '';
                        if (doc.created_at) {
                            uploadDate = new Date(doc.created_at).toLocaleDateString();
                        }
                        
                        html += `
                            <div class="document-item">
                                <div class="document-icon" style="font-size: 14px; font-weight: bold; color: #0a2d63;">${icon}</div>
                                <div class="document-name">${docType}</div>
                                <div class="document-type">${fileName}</div>
                                ${fileSize ? `<div style="font-size: 11px; color: #999; margin-bottom: 10px;">${fileSize}</div>` : ''}
                                ${uploadDate ? `<div style="font-size: 11px; color: #999; margin-bottom: 10px;">Uploaded: ${uploadDate}</div>` : ''}
                                <div class="document-actions">
                                    <a href="${filePath}" target="_blank" class="document-btn btn-view" onclick="if(!this.href) return false;">View</a>
                                    <a href="${filePath}" download="${fileName}" class="document-btn btn-download" onclick="if(!this.href) return false;">Download</a>
                                </div>
                            </div>
                        `;
                    });
                    
                    html += '</div>';
                    documentList.innerHTML = html;
                } else {
                    documentList.innerHTML = '<div style="text-align: center; padding: 40px; color: #666;">No documents uploaded for this enrollment.</div>';
                }
            } else {
                documentList.innerHTML = '<div style="text-align: center; padding: 40px; color: #666;">' + (data.message || 'Error loading documents.') + '</div>';
            }
        })
        .catch(error => {
            console.error('Error loading documents:', error);
            documentList.innerHTML = '<div style="text-align: center; padding: 40px; color: #dc2626;">Error loading documents. Please try again.</div>';
        });
}

        function closeDocumentModal() {
            document.getElementById('documentModal').style.display = 'none';
        }

        // Enrollment Search Modal Functions
        function openEnrollmentSearchModal() {
            document.getElementById('enrollmentSearchModal').style.display = 'flex';
            filterEnrollments();
        }

        function closeEnrollmentSearchModal() {
            document.getElementById('enrollmentSearchModal').style.display = 'none';
        }

        let enrollmentSearchPage = 1;
        let enrollmentSearchPerPage = 10;

        function filterEnrollments(page = 1) {
            enrollmentSearchPage = page;
            
            const searchTerm = document.getElementById('enrollmentSearchInput').value.toLowerCase();
            
            // Get status filters
            const filterPending = document.getElementById('filterPending')?.checked || false;
            const filterApproved = document.getElementById('filterApproved')?.checked || false;
            const filterNeedsDocs = document.getElementById('filterNeedsDocs')?.checked || false;
            const filterRejected = document.getElementById('filterRejected')?.checked || false;
            
            // Build active status array
            const activeStatuses = [];
            if (filterPending) activeStatuses.push('pending');
            if (filterApproved) activeStatuses.push('approved');
            if (filterNeedsDocs) activeStatuses.push('needs_docs');
            if (filterRejected) activeStatuses.push('rejected');
            
            // Filter enrollments
            let filtered = allEnrollments.filter(enrollment => {
                // Search term filter
                const matchesSearch = searchTerm === '' || 
                    enrollment.full_name?.toLowerCase().includes(searchTerm) ||
                    enrollment.email?.toLowerCase().includes(searchTerm) ||
                    enrollment.phone?.toLowerCase().includes(searchTerm);
                
                if (!matchesSearch) return false;
                
                // Status filter
                if (activeStatuses.length > 0 && !activeStatuses.includes(enrollment.status)) return false;
                
                return true;
            });
            
            // Calculate pagination
            const totalFiltered = filtered.length;
            const startIndex = (enrollmentSearchPage - 1) * enrollmentSearchPerPage;
            const endIndex = Math.min(startIndex + enrollmentSearchPerPage, totalFiltered);
            const paginatedResults = filtered.slice(startIndex, endIndex);
            
            displayEnrollmentSearchResults(paginatedResults);
            updateEnrollmentSearchPagination(totalFiltered, enrollmentSearchPage, enrollmentSearchPerPage);
        }

        function displayEnrollmentSearchResults(enrollments) {
            const resultsDiv = document.getElementById('enrollmentSearchResults');
            
            if (enrollments.length === 0) {
                resultsDiv.innerHTML = '<div style="text-align: center; padding: 40px; color: #666;">No enrollments found matching your criteria.</div>';
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
                    <div class="search-result-item" onclick="viewEnrollmentDetails(${enrollment.id})">
                        <div class="user-name">${enrollment.full_name}</div>
                        <div class="user-details">
                            <span>${enrollment.email}</span>
                            <span>•</span>
                            <span>${enrollment.phone}</span>
                            <span>•</span>
                            <span style="color: ${statusColor}; font-weight: 600;">${statusText}</span>
                        </div>
                        <div style="font-size: 12px; color: #666; margin-top: 5px;">
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
            
            // Previous button
            buttonsHtml += `<button class="pagination-btn" onclick="filterEnrollments(${page - 1})" ${page === 1 ? 'disabled' : ''}>Previous</button>`;
            
            // Page numbers
            for (let i = 1; i <= totalPages; i++) {
                if (i === 1 || i === totalPages || (i >= page - 2 && i <= page + 2)) {
                    buttonsHtml += `<button class="pagination-btn ${i === page ? 'active' : ''}" onclick="filterEnrollments(${i})">${i}</button>`;
                } else if (i === page - 3 || i === page + 3) {
                    buttonsHtml += `<button class="pagination-btn" disabled>...</button>`;
                }
            }
            
            // Next button
            buttonsHtml += `<button class="pagination-btn" onclick="filterEnrollments(${page + 1})" ${page === totalPages ? 'disabled' : ''}>Next</button>`;
            
            paginationButtons.innerHTML = buttonsHtml;
        }

        function changeEnrollmentPerPage() {
            const select = document.getElementById('enrollmentPerPage');
            const customInput = document.getElementById('enrollmentCustomPerPage');
            
            if (select.value === 'custom') {
                customInput.style.display = 'flex';
            } else {
                customInput.style.display = 'none';
                enrollmentSearchPerPage = parseInt(select.value);
                enrollmentSearchPage = 1;
                filterEnrollments(1);
            }
        }

        function applyEnrollmentCustomPerPage() {
            const customValue = document.getElementById('enrollmentCustomNumber').value;
            if (customValue && customValue > 0) {
                enrollmentSearchPerPage = parseInt(customValue);
                enrollmentSearchPage = 1;
                filterEnrollments(1);
                document.getElementById('enrollmentCustomPerPage').style.display = 'none';
                document.getElementById('enrollmentPerPage').value = 'custom';
            }
        }

        function viewEnrollmentDetails(enrollmentId) {
            closeEnrollmentSearchModal();
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
        
        // Toggle student-specific fields in modal based on role selection
        function toggleModalStudentFields() {
            const roleSelect = document.getElementById('modalRoleSelect');
            const studentFields = document.getElementById('modalStudentFields');
            const gradeLevel = document.getElementById('modalGradeLevel');
            const sectionSelect = document.getElementById('modalSectionSelect');
            const lrnField = document.getElementById('modalLrnField');
            
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

        // Update available sections based on selected grade level in modal
        function updateModalSections() {
            const gradeLevel = document.getElementById('modalGradeLevel').value;
            const sectionSelect = document.getElementById('modalSectionSelect');
            
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

        // Update filter sections based on selected grade level
        function updateFilterSections() {
            const gradeLevel = document.getElementById('filterGradeLevel').value;
            const filterSectionContainer = document.getElementById('filterSectionContainer');
            const sectionSelect = document.getElementById('filterSection');
            
            if (gradeLevel) {
                filterSectionContainer.style.display = 'block';
                
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
                sectionSelect.innerHTML = '<option value="">All Sections</option>';
                
                // Add options based on selected grade
                if (gradeSections[gradeLevel]) {
                    gradeSections[gradeLevel].forEach(section => {
                        const option = document.createElement('option');
                        option.value = section;
                        option.textContent = section;
                        sectionSelect.appendChild(option);
                    });
                }
            } else {
                filterSectionContainer.style.display = 'none';
                sectionSelect.innerHTML = '<option value="">All Sections</option>';
            }
        }

        // Modal functions
        function openAddUserModal() {
            document.getElementById('addUserModal').style.display = 'flex';
        }

        function closeAddUserModal() {
            document.getElementById('addUserModal').style.display = 'none';
            document.getElementById('createUserForm').reset();
            document.getElementById('modalStudentFields').style.display = 'none';
        }

        function openSearchModal() {
            document.getElementById('searchUserModal').style.display = 'flex';
            loadAllUsersForSearch();
        }

        function closeSearchModal() {
            document.getElementById('searchUserModal').style.display = 'none';
            // Reset filters
            document.getElementById('searchInput').value = '';
            document.querySelectorAll('.sort-option').forEach(opt => opt.classList.remove('active'));
            document.getElementById('sort-name').classList.add('active');
            document.getElementById('filterStudent').checked = false;
            document.getElementById('filterTeacher').checked = false;
            document.getElementById('filterAdmin').checked = false;
            <?php if ($userRole == 'super_admin'): ?>
            document.getElementById('filterSuperAdmin').checked = false;
            <?php endif; ?>
            document.getElementById('filterGradeLevel').value = '';
            document.getElementById('filterSectionContainer').style.display = 'none';
        }

        function openDeleteUserModal() {
            document.getElementById('deleteUserModal').style.display = 'flex';
            loadDeleteUserList();
        }

        function closeDeleteUserModal() {
            document.getElementById('deleteUserModal').style.display = 'none';
            document.getElementById('deleteSearchInput').value = '';
        }

        // Submit add user form
        function submitAddUser() {
            const form = document.getElementById('createUserForm');
            const formData = new FormData(form);
            
            // If role is not student, remove student-specific fields
            const role = document.getElementById('modalRoleSelect').value;
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
                    closeAddUserModal();
                    loadUsers(); // Refresh user list
                } else {
                    alert('Error creating user: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error creating user:', error);
                alert('Error creating user');
            });
        }

        // Load all users for search
        let allUsers = [];
        
        function loadAllUsersForSearch() {
            fetch('php/get_users.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.users) {
                        allUsers = data.users;
                        performSearch();
                    }
                })
                .catch(error => console.error('Error loading users for search:', error));
        }

        // Perform search with filters
        let currentSort = 'name';
        
        function setSort(sortBy) {
            currentSort = sortBy;
            document.querySelectorAll('.sort-option').forEach(opt => opt.classList.remove('active'));
            document.getElementById(`sort-${sortBy}`).classList.add('active');
            performSearch();
        }

        function applyFilters() {
            performSearch();
        }

        function performSearch() {
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            
            // Get role filters
            const filterStudent = document.getElementById('filterStudent')?.checked || false;
            const filterTeacher = document.getElementById('filterTeacher')?.checked || false;
            const filterAdmin = document.getElementById('filterAdmin')?.checked || false;
            <?php if ($userRole == 'super_admin'): ?>
            const filterSuperAdmin = document.getElementById('filterSuperAdmin')?.checked || false;
            <?php endif; ?>
            
            // Get grade and section filters
            const filterGrade = document.getElementById('filterGradeLevel').value;
            const filterSection = document.getElementById('filterSection').value;
            
            // Filter users
            let filteredUsers = allUsers.filter(user => {
                // Search term filter
                const matchesSearch = searchTerm === '' || 
                    user.full_name?.toLowerCase().includes(searchTerm) ||
                    user.username?.toLowerCase().includes(searchTerm) ||
                    user.email?.toLowerCase().includes(searchTerm);
                
                if (!matchesSearch) return false;
                
                // Role filter
                const roleFilters = [];
                if (filterStudent) roleFilters.push('student');
                if (filterTeacher) roleFilters.push('teacher');
                if (filterAdmin) roleFilters.push('admin');
                <?php if ($userRole == 'super_admin'): ?>
                if (filterSuperAdmin) roleFilters.push('super_admin');
                <?php endif; ?>
                
                if (roleFilters.length > 0 && !roleFilters.includes(user.role)) return false;
                
                // Grade filter
                if (filterGrade && user.grade_level !== filterGrade) return false;
                
                // Section filter
                if (filterSection && user.section !== filterSection) return false;
                
                return true;
            });
            
            // Sort users
            filteredUsers.sort((a, b) => {
                switch(currentSort) {
                    case 'name':
                        return (a.full_name || '').localeCompare(b.full_name || '');
                    case 'role':
                        return (a.role || '').localeCompare(b.role || '');
                    case 'grade':
                        return (a.grade_level || '').localeCompare(b.grade_level || '');
                    case 'date':
                        return new Date(b.created_at) - new Date(a.created_at);
                    default:
                        return 0;
                }
            });
            
            // Display results
            displaySearchResults(filteredUsers);
        }

        function displaySearchResults(users) {
            const resultsDiv = document.getElementById('searchResults');
            
            if (users.length === 0) {
                resultsDiv.innerHTML = '<div style="text-align: center; padding: 40px; color: #666;">No users found matching your criteria.</div>';
                return;
            }
            
            let html = '';
            users.forEach(user => {
                const roleClass = `role-badge-${user.role}`;
                const roleDisplay = user.role ? user.role.charAt(0).toUpperCase() + user.role.slice(1) : 'N/A';
                
                html += `
                    <div class="search-result-item">
                        <div class="user-name">${user.full_name || 'N/A'}</div>
                        <div class="user-details">
                            <span>${user.username || 'N/A'}</span>
                            <span>•</span>
                            <span>${user.email || 'N/A'}</span>
                            <span>•</span>
                            <span class="user-role ${roleClass}">${roleDisplay}</span>
                            ${user.grade_level ? `<span>•</span><span>${user.grade_level} - ${user.section || ''}</span>` : ''}
                        </div>
                    </div>
                `;
            });
            
            resultsDiv.innerHTML = html;
        }

        // Load users for deletion
        function loadDeleteUserList() {
            const deleteList = document.getElementById('deleteUserList');
            const searchTerm = document.getElementById('deleteSearchInput')?.value.toLowerCase() || '';
            
            deleteList.innerHTML = '<div style="text-align: center; padding: 40px; color: #666;">Loading users...</div>';
            
            fetch('php/get_users.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.users) {
                        // Filter out current user and apply search
                        const currentUserId = <?php echo $userId; ?>;
                        let filteredUsers = data.users.filter(user => user.id != currentUserId);
                        
                        // Apply search filter
                        if (searchTerm) {
                            filteredUsers = filteredUsers.filter(user => 
                                user.full_name?.toLowerCase().includes(searchTerm) ||
                                user.username?.toLowerCase().includes(searchTerm) ||
                                user.email?.toLowerCase().includes(searchTerm)
                            );
                        }
                        
                        displayDeleteUserList(filteredUsers);
                    } else {
                        deleteList.innerHTML = '<div style="text-align: center; padding: 40px; color: #666;">Error loading users</div>';
                    }
                })
                .catch(error => {
                    console.error('Error loading users for deletion:', error);
                    deleteList.innerHTML = '<div style="text-align: center; padding: 40px; color: #dc2626;">Error loading users</div>';
                });
        }

        function displayDeleteUserList(users) {
            const deleteList = document.getElementById('deleteUserList');
            
            if (users.length === 0) {
                deleteList.innerHTML = '<div style="text-align: center; padding: 40px; color: #666;">No users found.</div>';
                document.getElementById('selectedCount').textContent = '0';
                return;
            }
            
            let html = '';
            users.forEach(user => {
                const roleDisplay = user.role ? user.role.charAt(0).toUpperCase() + user.role.slice(1) : 'N/A';
                html += `
                    <div class="user-delete-item">
                        <div style="flex: 1;">
                            <div style="font-weight: 600; color: #0a2d63;">${user.full_name || 'N/A'}</div>
                            <div style="font-size: 12px; color: #666;">${user.username || 'N/A'} • ${user.email || 'N/A'} • ${roleDisplay}</div>
                        </div>
                        <input type="checkbox" class="delete-checkbox" value="${user.id}" onchange="updateSelectedCount()">
                    </div>
                `;
            });
            
            deleteList.innerHTML = html;
            updateSelectedCount();
        }

        function updateSelectedCount() {
            const checkboxes = document.querySelectorAll('#deleteUserList .delete-checkbox:checked');
            document.getElementById('selectedCount').textContent = checkboxes.length;
        }

        function confirmDeleteUsers() {
        const checkboxes = document.querySelectorAll('#deleteUserList .delete-checkbox:checked');
        const selectedIds = Array.from(checkboxes).map(cb => cb.value);
    
        if (selectedIds.length === 0) {
            alert('Please select at least one user to delete.');
            return;
        }
    
        if (!confirm(`Are you sure you want to delete ${selectedIds.length} user(s)? This action cannot be undone.`)) {
            return;
        }
    
        // Show loading state
        const deleteBtn = document.querySelector('#deleteUserModal .btn-delete');
        const originalText = deleteBtn.textContent;
            deleteBtn.textContent = 'Deleting...';
            deleteBtn.disabled = true;
    
        // Delete users one by one
        let deletedCount = 0;
        let failedCount = 0;
    
        function deleteNextUser(index) {
            if (index >= selectedIds.length) {
            // All done
            deleteBtn.textContent = originalText;
            deleteBtn.disabled = false;
            
            if (deletedCount > 0) {
                alert(`Successfully deleted ${deletedCount} user(s). ${failedCount > 0 ? failedCount + ' failed.' : ''}`);
                closeDeleteUserModal();
                loadUsers(); // Refresh user list
                if (typeof loadDeleteUserList === 'function') {
                    loadDeleteUserList(); // Refresh delete modal list
                }
            } else {
                alert('No users were deleted.');
            }
            return;
        }
        
        const formData = new FormData();
        formData.append('user_id', selectedIds[index]);
        
        fetch('php/delete_users.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.text(); // Get as text first to debug
        })
        .then(text => {
            try {
                const data = JSON.parse(text);
                if (data.success) {
                    deletedCount++;
                } else {
                    console.error('Delete failed:', data.message);
                    failedCount++;
                }
            } catch (e) {
                console.error('Invalid JSON response:', text);
                failedCount++;
            }
            // Delete next user
            deleteNextUser(index + 1);
        })
        .catch(error => {
            console.error('Error deleting user:', error);
            failedCount++;
            deleteNextUser(index + 1);
        });
    }
    
    // Start deleting from first user
    deleteNextUser(0);
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
            <?php if (in_array($userRole, ['admin', 'super_admin'])): ?>
            loadEnrollments();
            setInterval(loadEnrollments, 30000);
            <?php endif; ?>
            
            // Close modals when clicking outside
            window.onclick = function(event) {
                const addModal = document.getElementById('addUserModal');
                const searchModal = document.getElementById('searchUserModal');
                const deleteModal = document.getElementById('deleteUserModal');
                const documentModal = document.getElementById('documentModal');
                const enrollmentSearchModal = document.getElementById('enrollmentSearchModal');
                
                if (event.target === addModal) {
                    closeAddUserModal();
                }
                if (event.target === searchModal) {
                    closeSearchModal();
                }
                if (event.target === deleteModal) {
                    closeDeleteUserModal();
                }
                if (event.target === documentModal) {
                    closeDocumentModal();
                }
                if (event.target === enrollmentSearchModal) {
                    closeEnrollmentSearchModal();
                }
            }
        });

        // Sidebar toggle
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            sidebar.classList.toggle('open');
            overlay.classList.toggle('active');
        }

        // Generate PDF function
        function generatePDF(enrollmentId) {
            alert('PDF generation feature coming soon!');
        }
    </script>
</body>
</html>