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

// Get grades for student
if ($userRole == 'student') {
    $gradesStmt = $pdo->prepare("
        SELECT s.subject_name, g.grade, g.quarter 
        FROM grades g 
        JOIN subjects s ON g.subject_id = s.id 
        WHERE g.student_id = ? 
        ORDER BY s.subject_name
    ");
    $gradesStmt->execute([$userId]);
    $grades = $gradesStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get subjects
$subjectsStmt = $pdo->prepare("SELECT * FROM subjects ORDER BY subject_name");
$subjectsStmt->execute();
$subjects = $subjectsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get events
$eventsStmt = $pdo->prepare("SELECT * FROM events ORDER BY event_date");
$eventsStmt->execute();
$events = $eventsStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Baesa Adventist Academy - Dashboard</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .dashboard-card .card-content {
            padding: 30px;
            height: 100%;
            display: flex;
            flex-direction: column;
        }

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
        .payables-card .payable-list {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .announcements-card .announcement-item,
        .payables-card .payable-item {
            background: #f8f9fa;
            padding: 25px;
            transition: background 0.3s;
            border-radius: 0;
        }

        .announcements-card .announcement-item:hover,
        .payables-card .payable-item:hover {
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

        .payables-card .payable-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .payables-card .payable-details {
            flex: 1;
        }

        .payables-card .payable-details h4 {
            margin: 0 0 10px 0;
            color: #333;
            font-size: 18px;
            font-weight: 600;
        }

        .payables-card .payable-date {
            font-size: 14px;
            color: #666;
            background: #e9ecef;
            padding: 5px 10px;
            border-radius: 4px;
            display: inline-block;
        }

        .payables-card .payable-amount {
            text-align: right;
            min-width: 150px;
        }

        .payables-card .payable-total {
            font-weight: 700;
            font-size: 20px;
            color: #0a2d63;
            display: block;
            margin-bottom: 5px;
        }

        .payables-card .payable-status {
            font-size: 14px;
            color: #666;
            background: #e9ecef;
            padding: 5px 10px;
            border-radius: 4px;
            display: inline-block;
        }

        .layout-payables .payables-card,
        .layout-profile .profile-card,
        .layout-announcements .announcements-card {
            display: flex !important;
            width: 100%;
            max-width: 900px;
            margin: 0 auto;
            min-height: 600px;
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

        <?php if ($userRole == 'admin'): ?>
        .dashboard-left {
            display: none;
        }

        .dashboard-right {
            width: 100%;
            display: flex !important;
        }
        <?php endif; ?>
    </style>
</head>
<body>
    <!-- Dashboard Page -->
    <div class="dashboard-page" id="dashboardPage">
        <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <img src="images/logo.png" alt="BAA Logo" class="sidebar-logo">
                <h3>Baesa Adventist Academy</h3>
            </div>
            <ul>
                <?php if ($userRole == 'admin'): ?>
                    <li><a href="#" onclick="navigateTo('home'); return false;" class="active" id="menu-home">Enrollment Requests</a></li>
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
        
        <div class="dashboard-main layout-home">
            <!-- Dashboard Header -->
            <div class="dashboard-header">
                <div class="header-content">
                    <div class="header-left">
                        <button class="menu-btn" onclick="toggleSidebar()">☰</button>
                        <div class="section-info">
                            <div class="section-label">Section: <?php echo htmlspecialchars($section); ?></div>
                            <div class="lrn-label">LRN: <?php echo htmlspecialchars($lrn); ?></div>
                        </div>
                    </div>
                    
                    <div class="header-center">
                        <h2>Welcome to Your Dashboard</h2>
                        <p>Stay updated with your academic progress and school activities</p>
                    </div>
                    
                    <div class="header-right">
                        <div class="user-info-container">
                            <span class="user-name" id="userName"><?php echo htmlspecialchars($fullName); ?></span>
                            <button class="logout-btn" onclick="handleLogout()">Logout</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <!-- Home Page Layout -->
                <div class="dashboard-left">
                    <?php if ($userRole != 'admin'): ?>
                    <div class="dashboard-card events-card">
                        <div class="card-content">
                            <h3>School Events</h3>
                            <p>View upcoming school events, activities, and important dates for the academic year.</p>
                            <div class="event-list" id="eventList">
                                <?php if (!empty($events)): ?>
                                    <?php foreach ($events as $event): ?>
                                        <div class="event-item">
                                            <div class="event-date"><?php echo date('F j, Y', strtotime($event['event_date'])); ?></div>
                                            <div class="event-details">
                                                <h4><?php echo htmlspecialchars($event['title']); ?></h4>
                                                <p><?php echo htmlspecialchars($event['description'] ?? ''); ?></p>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="event-item">
                                        <div class="event-details">
                                            <h4>No upcoming events</h4>
                                            <p>Check back later for upcoming events.</p>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="dashboard-right">
                    <?php if ($userRole == 'admin'): ?>
                        <!-- Admin Enrollments Dashboard -->
                        <div class="dashboard-card grades-card" style="width: 100%; min-height: auto;">
                            <div class="card-content" style="width: 100%;">
                                <h3>Student Enrollment Requests</h3>
                                <p>Review and manage pending student enrollments</p>
                                
                                <div id="enrollmentList" style="width: 100%;">
                                    <div style="text-align: center; color: #999; padding: 40px 20px;">
                                        Loading enrollments...
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php elseif ($userRole == 'student'): ?>
                        <div class="dashboard-card grades-card">
                            <div class="card-content">
                                <h3>Grades</h3>
                                <p>Check your current grades, academic performance, and progress reports.</p>
                                <div class="grade-summary" id="gradeSummary">
                                    <?php if (!empty($grades)): ?>
                                        <?php foreach ($grades as $grade): ?>
                                            <div class="grade-item">
                                                <span class="subject"><?php echo htmlspecialchars($grade['subject_name']); ?> (Q<?php echo $grade['quarter']; ?>)</span>
                                                <span class="grade"><?php echo $grade['grade']; ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="grade-item">
                                            <span class="subject">No grades available</span>
                                            <span class="grade">-</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="dashboard-card subjects-card">
                            <div class="card-content">
                                <h3>Subjects</h3>
                                <p>Access your enrolled subjects, schedules, and course materials.</p>
                                <div class="subject-list" id="subjectList">
                                    <?php if (!empty($subjects)): ?>
                                        <?php foreach ($subjects as $subject): ?>
                                            <div class="subject-item">
                                                <h4><?php echo htmlspecialchars($subject['subject_name']); ?></h4>
                                                <p><?php echo htmlspecialchars($subject['schedule'] ?? 'Schedule not set'); ?></p>
                                                <p class="teacher"><?php echo htmlspecialchars($subject['teacher_name'] ?? 'Teacher not assigned'); ?></p>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="subject-item">
                                            <h4>No subjects enrolled</h4>
                                            <p>Contact your advisor</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php elseif ($userRole == 'teacher'): ?>
                        <!-- Teacher-specific content -->
                        <div class="dashboard-card teacher-card">
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
                    <?php elseif ($userRole == 'admin'): ?>
                        <!-- Admin Enrollments Dashboard -->
                        <div class="dashboard-card grades-card" style="width: 100%; min-height: auto;">
                            <div class="card-content" style="width: 100%;">
                                <h3>Student Enrollment Requests</h3>
                                <p>Review and manage pending student enrollments</p>
                                
                                <div id="enrollmentList" style="width: 100%;">
                                    <div style="text-align: center; color: #999; padding: 40px 20px;">
                                        Loading enrollments...
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Hidden cards for other pages -->
                <div class="dashboard-card payables-card" style="display: none;">
                    <div class="card-content">
                        <h3>Payables</h3>
                        <p>View your tuition fees, payment history, and outstanding balances.</p>
                        <div class="payable-list" id="payableList">
                            <!-- Content will be loaded by JavaScript -->
                        </div>
                    </div>
                </div>
                
                <div class="dashboard-card profile-card" style="display: none;">
                    <div class="card-content">
                        <h3>Profile</h3>
                        <p>View and update your personal information.</p>
                        <div class="profile-info" id="profileInfo">
                            <!-- Content will be loaded by JavaScript -->
                        </div>
                    </div>
                </div>
                
                <div class="dashboard-card announcements-card" style="display: none;">
                    <div class="card-content">
                        <h3>Announcements</h3>
                        <p>Latest school announcements and updates.</p>
                        <div class="announcement-list" id="announcementList">
                            <!-- Content will be loaded by JavaScript -->
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="js/script.js"></script>
    <script>
        // Load enrollments for admin
        <?php if ($userRole == 'admin'): ?>
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
        const statusClass = 'status-' + (enrollment.status || 'pending');
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

            //PDF generation 
            function generatePDF(enrollmentId) {
                if (confirm('Generate PDF for this enrollment?')) {
                window.open('php/enrollment_pdf.php?enrollment_id=' + enrollmentId, '_blank');
    }
}

        function viewDocuments(enrollmentId) {
            fetch('php/get_enrollment_documents.php?enrollment_id=' + enrollmentId)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.documents && data.documents.length > 0) {
                        let docHtml = '<h4 style="margin-top: 0; color: #0a2d63; margin-bottom: 15px;">Uploaded Documents:</h4>';
                        docHtml += '<div style="display: flex; flex-direction: column; gap: 10px;">';
                        
                        data.documents.forEach(doc => {
                            const fileSize = (doc.file_size / 1024).toFixed(2);
                            const docPath = doc.document_path;
                            docHtml += `<div style="padding: 10px; background: #f8f9fa; border-radius: 4px; display: flex; justify-content: space-between; align-items: center;">
                                <span>${doc.document_filename} <span style="color: #999; font-size: 12px;">(${fileSize} KB)</span></span>
                                <a href="${docPath}" target="_blank" style="background: #2563eb; color: white; padding: 6px 12px; border-radius: 4px; text-decoration: none; cursor: pointer; font-size: 12px;">View</a>
                            </div>`;
                        });
                        
                        docHtml += '</div>';
                        
                        // Create a modal
                        const modal = document.createElement('div');
                        modal.style.cssText = 'position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1000; display: flex; align-items: center; justify-content: center;';
                        
                        const content = document.createElement('div');
                        content.style.cssText = 'background: white; padding: 25px; border-radius: 8px; max-width: 500px; width: 90%; max-height: 80vh; overflow-y: auto; box-shadow: 0 10px 40px rgba(0,0,0,0.2);';
                        content.innerHTML = docHtml + '<div style="margin-top: 20px; text-align: right;"><button onclick="this.closest(\'div\').parentElement.parentElement.remove();" style="background: #6c757d; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; font-weight: 500;">Close</button></div>';
                        
                        modal.appendChild(content);
                        modal.onclick = (e) => {
                            if (e.target === modal) {
                                modal.remove();
                            }
                        };
                        document.body.appendChild(modal);
                    } else {
                        alert('No documents found for this enrollment.');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading documents');
                });
        }

        function updateStatus(enrollmentId, status) {
            const formData = new FormData();
            formData.append('enrollment_id', enrollmentId);
            formData.append('status', status);
            
            fetch('php/update_enrollment_status.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    loadEnrollments();
                } else {
                    alert('Error updating status: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error updating enrollment status');
            });
        }

        function deleteEnrollment(enrollmentId, studentName) {
            if (confirm(`Do you want to delete ${studentName}'s submission?`)) {
                const formData = new FormData();
                formData.append('enrollment_id', enrollmentId);
                
                fetch('php/delete_enrollment.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        loadEnrollments();
                    } else {
                        alert('Error deleting submission: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error deleting enrollment submission');
                });
            }
        }

        // Load enrollments when page loads
        document.addEventListener('DOMContentLoaded', function() {
            loadEnrollments();
            // Refresh every 30 seconds
            setInterval(loadEnrollments, 30000);
        });
        <?php endif; ?>
    </script>
