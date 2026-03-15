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

// STATS for admin and registrar
if ($userRole === 'admin' || $userRole === 'registrar') {
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
    } catch (PDOException $e) {
        error_log("Error fetching stats: " . $e->getMessage());
        $newRequests = $grades7to10 = $grades11to12 = $totalEnrollments = 0;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Baesa Adventist Academy - Dashboard</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
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
        }
        .dashboard-main {
            margin-left: 300px;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }
        .stat-card {
            background: white;
            border-radius: 0.75rem;
            padding: 1.5rem;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06);
            border: 1px solid #e5e7eb;
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
        .search-icon-outline img {
            width: 24px;
            height: 24px;
        }
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
        .enrollment-table tr:hover td {
            background-color: #f9fafb;
        }
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
        .three-dots:hover, .delete-btn:hover {
            color: #0a2d63;
        }
        .delete-btn:hover {
            color: #dc2626;
        }
        .details-row {
            background-color: #f9fafb;
        }
        .details-row td {
            padding: 1.5rem;
        }
        .hidden {
            display: none;
        }
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
        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
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
        input:checked + .slider {
            background-color: #0a2d63;
        }
        input:checked + .slider:before {
            transform: translateX(26px);
        }
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
        let totalEnrollments = <?php echo json_encode($totalEnrollments ?? 0); ?>;
        let currentUserId = <?php echo $userId; ?>;
        let userRole = '<?php echo $userRole; ?>';

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
                    <tr class="enrollment-row" data-id="${enrollment.id}">
                        <td class="font-medium">${enrollment.full_name}</td>
                        <td>${enrollment.email}</td>
                        <td><span class="status-badge ${statusClass}">${statusText}</span></td>
                        <td>
                            <div class="action-btn-group">
                                <button class="action-btn btn-accept" onclick="acceptEnrollment(${enrollment.id})">Accept</button>
                                <button class="action-btn btn-docs" onclick="updateStatus(${enrollment.id}, 'needs_docs')">Docs</button>
                                <button class="action-btn btn-reject" onclick="updateStatus(${enrollment.id}, 'rejected')">Reject</button>
                            </div>
                        </td>
                        <td>
                            <button class="three-dots" onclick="toggleDetails(${enrollment.id})">⋮</button>
                        </td>
                        <td>
                            <button class="delete-btn" onclick="deleteEnrollment(${enrollment.id}, '${enrollment.full_name}')" title="Delete enrollment">✕</button>
                        </td>
                    </tr>
                    <tr id="details-${enrollment.id}" class="details-row hidden">
                        <td colspan="6">
                            <div class="p-4 bg-gray-50 rounded">
                                <div class="grid grid-cols-2 gap-4">
                                    <div><span class="font-semibold">Age:</span> ${enrollment.age}</div>
                                    <div><span class="font-semibold">Gender:</span> ${enrollment.gender}</div>
                                    <div><span class="font-semibold">Birthdate:</span> ${enrollment.birthdate}</div>
                                    <div><span class="font-semibold">Phone:</span> ${phone}</div>
                                    <div><span class="font-semibold">Grade:</span> ${enrollment.grade_level}</div>
                                    <div><span class="font-semibold">Section:</span> ${enrollment.section}</div>
                                    <div><span class="font-semibold">LRN:</span> ${enrollment.lrn}</div>
                                    <div><span class="font-semibold">Submitted:</span> ${created}</div>
                                </div>
                                <div class="mt-4">
                                    <a href="#" onclick="viewDocuments(${enrollment.id}); return false;" class="text-blue-600 hover:underline">View Documents (${enrollment.document_count})</a>
                                    &nbsp;|&nbsp;
                                    <a href="#" onclick="generatePDF(${enrollment.id}); return false;" class="text-green-600 hover:underline">Generate PDF</a>
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

        // ---------- Payables Student Select Modal ----------
        function openStudentSelectModal() {
            const modal = document.getElementById('studentSelectModal');
            if (modal) modal.style.display = 'flex';
            loadStudentsForSelect();
        }

        function closeStudentSelectModal() {
            const modal = document.getElementById('studentSelectModal');
            if (modal) modal.style.display = 'none';
        }

        function loadStudentsForSelect() {
            const resultsDiv = document.getElementById('studentSelectResults');
            if (!resultsDiv) return;
            resultsDiv.innerHTML = '<div class="loading text-center text-gray-500 py-10">Loading students...</div>';
            
            fetch('php/get_users.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.users) {
                        studentsData = data.users.filter(u => u.role === 'student');
                        filterStudentsForSelect();
                    } else {
                        resultsDiv.innerHTML = '<div class="text-center text-gray-500 py-10">No students found.</div>';
                    }
                })
                .catch(error => {
                    console.error('Error loading students:', error);
                    resultsDiv.innerHTML = '<div class="text-center text-red-600 py-10">Error loading students</div>';
                });
        }

        function filterStudentsForSelect() {
            const searchTerm = document.getElementById('studentSearchInput')?.value.toLowerCase() || '';
            const gradeFilter = document.getElementById('studentFilterGrade')?.value || '';
            
            let filtered = studentsData.filter(student => {
                const matchesSearch = searchTerm === '' || 
                    student.full_name?.toLowerCase().includes(searchTerm) ||
                    student.email?.toLowerCase().includes(searchTerm) ||
                    student.grade_level?.toLowerCase().includes(searchTerm);
                
                if (!matchesSearch) return false;
                if (gradeFilter && student.grade_level !== gradeFilter) return false;
                return true;
            });
            
            displayStudentSelectResults(filtered);
        }

        function displayStudentSelectResults(students) {
            const resultsDiv = document.getElementById('studentSelectResults');
            if (!resultsDiv) return;
            
            if (students.length === 0) {
                resultsDiv.innerHTML = '<div class="text-center text-gray-500 py-10">No students found.</div>';
                return;
            }
            
            let html = '';
            students.forEach(student => {
                html += `
                    <div class="search-result-item p-4 border-b border-gray-200 cursor-pointer hover:bg-gray-50" onclick="selectStudent(${student.id}, '${student.full_name.replace(/'/g, "\\'")}', '${student.grade_level || ''}')">
                        <div class="font-semibold text-[#0a2d63]">${student.full_name}</div>
                        <div class="text-xs text-gray-600">${student.email} | ${student.grade_level || ''} - ${student.section || ''}</div>
                    </div>
                `;
            });
            
            resultsDiv.innerHTML = html;
        }

        function selectStudent(id, name, grade) {
            const studentSelect = document.getElementById('studentSelect');
            const selectedName = document.getElementById('selectedStudentName');
            if (studentSelect) studentSelect.value = id;
            if (selectedName) selectedName.value = name + (grade ? ` (${grade})` : '');
            closeStudentSelectModal();
            
            const tuitionFee = document.getElementById('tuitionFee');
            if (grade && gradeFees[grade] && tuitionFee) {
                tuitionFee.value = gradeFees[grade].toFixed(2);
            } else if (tuitionFee) {
                tuitionFee.value = '';
            }
        }

        // ---------- Payables Calculation ----------
        function calculatePayables() {
            const studentId = document.getElementById('studentSelect')?.value;
            const tuitionFee = parseFloat(document.getElementById('tuitionFee')?.value);
            const downPayment = parseFloat(document.getElementById('downPayment')?.value);
            const discounts = parseFloat(document.getElementById('discounts')?.value) || 0;
            const monthlyPayments = parseInt(document.getElementById('monthlyPayments')?.value) || 4;
            
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
            
            const totalPayable = tuitionFee - discounts;
            const remainingBalance = totalPayable - downPayment;
            const monthlyPaymentAmount = remainingBalance / monthlyPayments;
            
            const breakdown = { tuition: tuitionFee, misc: 0, aircon: 0, hsa: 0, books: 0 };
            
            const resultContent = document.getElementById('resultContent');
            const calculationResult = document.getElementById('calculationResult');
            const addPayableBtn = document.getElementById('addPayableBtn');
            const generatePdfBtn = document.getElementById('generatePdfBtn');
            
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

        function generateAssessmentPDF() {
            if (!window.calculatedPayables) {
                alert('No calculation data available. Please calculate first.');
                return;
            }
            const data = window.calculatedPayables;

            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'php/generate_assessment_pdf.php';
            form.target = '_blank';

            const fields = {
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
            };

            for (let key in fields) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = key;
                input.value = fields[key];
                form.appendChild(input);
            }

            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
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
                    const pdfBtn = document.getElementById('generatePdfBtn');
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
        function loadStudentPayables() {
            const studentId = document.getElementById('paymentStudentSelect')?.value;
            
            if (!studentId || studentId === "") {
                alert('Please select a student first');
                return;
            }
            
            const payablesList = document.getElementById('payablesList');
            if (!payablesList) return;
            payablesList.innerHTML = '<div class="loading text-center text-gray-500 py-10">Loading payables...</div>';
            const studentPayablesDiv = document.getElementById('studentPayables');
            if (studentPayablesDiv) studentPayablesDiv.style.display = 'block';
            
            fetch('php/get_student_payables.php?student_id=' + studentId)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.payables) {
                        displayStudentPayables(data.payables);
                    } else {
                        payablesList.innerHTML = '<div class="text-center text-gray-500 py-10">No payables found for this student.</div>';
                    }
                })
                .catch(error => {
                    console.error('Error loading student payables:', error);
                    payablesList.innerHTML = '<div class="text-center text-red-600 py-10">Error loading payables</div>';
                });
        }

        function displayStudentPayables(payables) {
            const payablesList = document.getElementById('payablesList');
            if (!payablesList) return;
            
            if (payables.length === 0) {
                payablesList.innerHTML = '<div class="text-center text-gray-500 py-10">No payables found for this student.</div>';
                return;
            }
            
            let html = '<div class="payables-grid grid grid-cols-1 md:grid-cols-4 gap-4 text-sm">';
            html += '<div class="payables-header font-semibold text-gray-700 pb-2 border-b-2 border-gray-300 mb-2">Description</div>';
            html += '<div class="payables-header font-semibold text-gray-700 pb-2 border-b-2 border-gray-300 mb-2">Amount</div>';
            html += '<div class="payables-header font-semibold text-gray-700 pb-2 border-b-2 border-gray-300 mb-2">Due Date</div>';
            html += '<div class="payables-header font-semibold text-gray-700 pb-2 border-b-2 border-gray-300 mb-2">Status</div>';
            
            payables.forEach(payable => {
                const dueDate = new Date(payable.due_date);
                const today = new Date();
                const isOverdue = dueDate < today && payable.status !== 'paid';
                let statusClass = 'bg-yellow-100 text-yellow-800';
                let statusText = 'Pending';
                
                if (payable.status === 'paid') {
                    statusClass = 'bg-green-100 text-green-800';
                    statusText = 'Paid';
                } else if (isOverdue) {
                    statusClass = 'bg-red-100 text-red-800';
                    statusText = 'Overdue';
                }
                
                html += `
                    <div class="payables-row contents">
                        <div class="py-3 border-b border-gray-200" data-label="Description">${payable.item_name}</div>
                        <div class="py-3 border-b border-gray-200" data-label="Amount">₱${parseFloat(payable.amount).toFixed(2)}</div>
                        <div class="py-3 border-b border-gray-200" data-label="Due Date">${dueDate.toLocaleDateString()}</div>
                        <div class="py-3 border-b border-gray-200" data-label="Status"><span class="px-3 py-1 rounded text-xs font-semibold ${statusClass}">${statusText}</span></div>
                    </div>
                `;
            });
            
            html += '</div>';
            payablesList.innerHTML = html;
        }

        function processPayment() {
            const studentId = document.getElementById('paymentStudentSelect')?.value;
            const amount = document.getElementById('paymentAmount')?.value;
            const paymentDate = document.getElementById('paymentDate')?.value;
            
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
                    if (paymentResult) {
                        paymentResult.style.display = 'block';
                        paymentResult.innerHTML = data.message;
                    }
                    const amountInput = document.getElementById('paymentAmount');
                    if (amountInput) amountInput.value = '';
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

        // ---------- User Management Functions ----------
        function openAddUserModal() {
            const modal = document.getElementById('addUserModal');
            if (modal) modal.style.display = 'flex';
        }

        function closeAddUserModal() {
            const modal = document.getElementById('addUserModal');
            if (modal) modal.style.display = 'none';
            const form = document.getElementById('createUserForm');
            if (form) form.reset();
            const studentFields = document.getElementById('modalStudentFields');
            if (studentFields) studentFields.style.display = 'none';
            const roleSelect = document.getElementById('modalRoleSelect');
            if (roleSelect) roleSelect.disabled = false;
            const enrollmentIdField = document.getElementById('modalEnrollmentId');
            if (enrollmentIdField) enrollmentIdField.value = '';
        }

        function openSearchModal() {
            const modal = document.getElementById('searchUserModal');
            if (modal) modal.style.display = 'flex';
            loadAllUsersForSearch();
        }

        function closeSearchModal() {
            const modal = document.getElementById('searchUserModal');
            if (modal) modal.style.display = 'none';
            const searchInput = document.getElementById('searchInput');
            if (searchInput) searchInput.value = '';
            document.querySelectorAll('.sort-option').forEach(opt => opt.classList.remove('active'));
            const sortName = document.getElementById('sort-name');
            if (sortName) sortName.classList.add('active');
            const filterStudent = document.getElementById('filterStudent');
            if (filterStudent) filterStudent.checked = false;
            const filterTeacher = document.getElementById('filterTeacher');
            if (filterTeacher) filterTeacher.checked = false;
            <?php if ($userRole == 'admin'): ?>
            const filterAdmin = document.getElementById('filterAdmin');
            if (filterAdmin) filterAdmin.checked = false;
            <?php endif; ?>
            const filterGradeLevel = document.getElementById('filterGradeLevel');
            if (filterGradeLevel) filterGradeLevel.value = '';
            const filterSectionContainer = document.getElementById('filterSectionContainer');
            if (filterSectionContainer) filterSectionContainer.style.display = 'none';
        }

        function openDeleteUserModal() {
            const modal = document.getElementById('deleteUserModal');
            if (modal) modal.style.display = 'flex';
            loadDeleteUserList();
        }

        function closeDeleteUserModal() {
            const modal = document.getElementById('deleteUserModal');
            if (modal) modal.style.display = 'none';
            const searchInput = document.getElementById('deleteSearchInput');
            if (searchInput) searchInput.value = '';
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

        // ---------- Search & Filter Functions ----------
        let allUsers = [];
        let currentSort = 'name';

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

        function setSort(sortBy) {
            currentSort = sortBy;
            document.querySelectorAll('.sort-option').forEach(opt => opt.classList.remove('active'));
            const sortEl = document.getElementById(`sort-${sortBy}`);
            if (sortEl) sortEl.classList.add('active');
            performSearch();
        }

        function applyFilters() {
            performSearch();
        }

        function performSearch() {
            const searchInput = document.getElementById('searchInput');
            if (!searchInput) return;
            const searchTerm = searchInput.value.toLowerCase();
            
            const filterStudent = document.getElementById('filterStudent')?.checked || false;
            const filterTeacher = document.getElementById('filterTeacher')?.checked || false;
            <?php if ($userRole == 'admin'): ?>
            const filterAdmin = document.getElementById('filterAdmin')?.checked || false;
            <?php endif; ?>
            
            const filterGrade = document.getElementById('filterGradeLevel')?.value;
            const filterSection = document.getElementById('filterSection')?.value;
            
            let filteredUsers = allUsers.filter(user => {
                const matchesSearch = searchTerm === '' || 
                    user.full_name?.toLowerCase().includes(searchTerm) ||
                    user.username?.toLowerCase().includes(searchTerm) ||
                    user.email?.toLowerCase().includes(searchTerm);
                
                if (!matchesSearch) return false;
                
                const roleFilters = [];
                if (filterStudent) roleFilters.push('student');
                if (filterTeacher) roleFilters.push('teacher');
                <?php if ($userRole == 'admin'): ?>
                if (filterAdmin) roleFilters.push('admin');
                <?php endif; ?>
                
                if (roleFilters.length > 0 && !roleFilters.includes(user.role)) return false;
                if (filterGrade && user.grade_level !== filterGrade) return false;
                if (filterSection && user.section !== filterSection) return false;
                
                return true;
            });
            
            filteredUsers.sort((a, b) => {
                switch(currentSort) {
                    case 'name': return (a.full_name || '').localeCompare(b.full_name || '');
                    case 'role': return (a.role || '').localeCompare(b.role || '');
                    case 'grade': return (a.grade_level || '').localeCompare(b.grade_level || '');
                    case 'date': return new Date(b.created_at) - new Date(a.created_at);
                    default: return 0;
                }
            });
            
            displaySearchResults(filteredUsers);
        }

        function displaySearchResults(users) {
            const resultsDiv = document.getElementById('searchResults');
            if (!resultsDiv) return;
            
            if (users.length === 0) {
                resultsDiv.innerHTML = '<div class="text-center text-gray-500 py-10">No users found matching your criteria.</div>';
                return;
            }
            
            let html = '';
            if (userRole === 'admin' && users.some(u => u.role === 'student')) {
                html += `
                    <div class="mb-4 p-3 bg-blue-50 rounded flex justify-between items-center">
                        <span class="text-blue-800 font-medium">Batch Promote Students</span>
                        <button onclick="openBatchPromoteModal()" class="bg-blue-600 text-white px-4 py-2 rounded text-sm font-medium hover:bg-blue-700 transition">
                            Promote by Grade & Section
                        </button>
                    </div>
                `;
            }

            users.forEach(user => {
                const roleDisplay = user.role ? user.role.charAt(0).toUpperCase() + user.role.slice(1) : 'N/A';
                let roleColor = user.role === 'admin' ? '#0a2d63' : (user.role === 'cashier' ? '#f59e0b' : (user.role === 'registrar' ? '#ef4444' : (user.role === 'teacher' ? '#10b981' : '#6c757d')));
                const isActive = user.status == 1;
                
                html += `
                    <div class="search-result-item p-4 border-b border-gray-200 hover:bg-gray-50">
                        <div class="flex justify-between items-start">
                            <div class="flex-1">
                                <div class="user-name font-semibold text-[#0a2d63] mb-1">${user.full_name || 'N/A'}</div>
                                <div class="user-details text-xs text-gray-600 flex gap-2 flex-wrap">
                                    <span>${user.username || 'N/A'}</span>
                                    <span>•</span>
                                    <span>${user.email || 'N/A'}</span>
                                    <span>•</span>
                                    <span class="px-2 py-0.5 rounded text-xs font-semibold text-white" style="background: ${roleColor};">${roleDisplay}</span>
                                    ${user.grade_level ? `<span>•</span><span>${user.grade_level} - ${user.section || ''}</span>` : ''}
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                ${userRole === 'admin' && user.role === 'student' ? `
                                    <button onclick="promoteStudent(${user.id})" 
                                            class="bg-green-600 text-white px-3 py-1 rounded text-xs font-medium hover:bg-green-700 transition"
                                            title="Promote to next grade">
                                        Promote
                                    </button>
                                ` : ''}
                                ${userRole === 'admin' && user.id != currentUserId ? `
                                    <div class="status-toggle ml-2">
                                        <label class="switch">
                                            <input type="checkbox" ${isActive ? 'checked' : ''} onchange="toggleUserStatus(${user.id}, this.checked)">
                                            <span class="slider"></span>
                                        </label>
                                        <span class="text-xs ${isActive ? 'text-green-600' : 'text-red-600'} ml-1">${isActive ? 'Active' : 'Inactive'}</span>
                                    </div>
                                ` : ''}
                            </div>
                        </div>
                    </div>
                `;
            });
            
            resultsDiv.innerHTML = html;
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
                    loadAllUsersForSearch();
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
            
            fetch('php/get_users.php')
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
                    loadAllUsersForSearch();
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
                    loadAllUsersForSearch();
                } else {
                    alert('Error updating status: ' + data.message);
                    loadAllUsersForSearch();
                }
            })
            .catch(error => {
                console.error('Error toggling status:', error);
                alert('Network error');
                loadAllUsersForSearch();
            });
        }

        function updateFilterSections() {
            const gradeLevel = document.getElementById('filterGradeLevel')?.value;
            const filterSectionContainer = document.getElementById('filterSectionContainer');
            const sectionSelect = document.getElementById('filterSection');
            
            if (gradeLevel) {
                if (filterSectionContainer) filterSectionContainer.style.display = 'block';
                
                const gradeSections = {
                    'Grade 7': ['Love', 'Joy'],
                    'Grade 8': ['Patience', 'Peace'],
                    'Grade 9': ['Goodness', 'Kindness'],
                    'Grade 10': ['Gentleness', 'Faithfulness'],
                    'Grade 11': ['Self-Control', 'Honesty'],
                    'Grade 12': ['Humility', 'Meekness']
                };
                
                if (sectionSelect) {
                    sectionSelect.innerHTML = '<option value="">All Sections</option>';
                    if (gradeSections[gradeLevel]) {
                        gradeSections[gradeLevel].forEach(section => {
                            const option = document.createElement('option');
                            option.value = section;
                            option.textContent = section;
                            sectionSelect.appendChild(option);
                        });
                    }
                }
            } else {
                if (filterSectionContainer) filterSectionContainer.style.display = 'none';
                if (sectionSelect) sectionSelect.innerHTML = '<option value="">All Sections</option>';
            }
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
                                <div class="document-item bg-gray-50 border border-gray-200 rounded p-5 text-center hover:-translate-y-1 hover:shadow-md transition">
                                    <div class="document-icon text-sm font-bold text-[#0a2d63] bg-gray-200 px-3 py-1 rounded inline-block mx-auto mb-4 font-mono">${icon}</div>
                                    <div class="document-name font-semibold text-gray-800 mb-2 break-words">${docType}</div>
                                    <div class="document-type text-xs text-gray-600 mb-2 bg-gray-100 px-2 py-1 rounded inline-block">${fileName}</div>
                                    ${fileSize ? `<div class="text-xs text-gray-500 mb-2">${fileSize}</div>` : ''}
                                    ${uploadDate ? `<div class="text-xs text-gray-500 mb-2">Uploaded: ${uploadDate}</div>` : ''}
                                    <div class="document-actions flex gap-2 justify-center mt-2">
                                        <a href="${filePath}" target="_blank" class="document-btn bg-[#0a2d63] text-white px-4 py-2 rounded text-sm font-medium hover:bg-[#08306b] transition no-underline inline-block">View</a>
                                        <a href="${filePath}" download="${fileName}" class="document-btn bg-green-600 text-white px-4 py-2 rounded text-sm font-medium hover:bg-green-700 transition no-underline inline-block">Download</a>
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

        function acceptEnrollment(enrollmentId) {
            fetch(`php/get_enrollment_details.php?id=${enrollmentId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        openAddUserModalWithEnrollment(data.data);
                    } else {
                        alert('Error loading enrollment details: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Failed to load enrollment details');
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

            // Hide the student fields but keep them as hidden inputs
            const studentFields = document.getElementById('modalStudentFields');
            if (studentFields) {
                studentFields.style.display = 'none';
                // Ensure all student fields are present as hidden (they already are inside the div)
            }

            // Pre-fill hidden fields with enrollment data
            setElementValue('modalGradeLevel', enrollee.grade_level);
            setElementValue('modalSectionSelect', enrollee.section);
            setElementValue('modalLrnField', enrollee.lrn);
            setElementValue('modalAge', enrollee.age);
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
                studentFields.style.display = 'block';
            } else {
                studentFields.style.display = 'none';
            }
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
        }

        function updateStatus(enrollmentId, status) {
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

        function generatePDF(enrollmentId) {
            window.open('php/enrollment_pdf.php?enrollment_id=' + enrollmentId, '_blank');
        }

        // ---------- Delete User Functions ----------
        function loadDeleteUserList() {
            const deleteList = document.getElementById('deleteUserList');
            if (!deleteList) return;
            const searchTerm = document.getElementById('deleteSearchInput')?.value.toLowerCase() || '';
            
            deleteList.innerHTML = '<div class="text-center text-gray-500 py-10">Loading users...</div>';
            
            fetch('php/get_users.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.users) {
                        const currentUserId = <?php echo $userId; ?>;
                        let filteredUsers = data.users.filter(user => user.id != currentUserId);
                        
                        if (searchTerm) {
                            filteredUsers = filteredUsers.filter(user => 
                                user.full_name?.toLowerCase().includes(searchTerm) ||
                                user.username?.toLowerCase().includes(searchTerm) ||
                                user.email?.toLowerCase().includes(searchTerm)
                            );
                        }
                        
                        displayDeleteUserList(filteredUsers);
                    } else {
                        deleteList.innerHTML = '<div class="text-center text-gray-500 py-10">Error loading users</div>';
                    }
                })
                .catch(error => {
                    console.error('Error loading users for deletion:', error);
                    deleteList.innerHTML = '<div class="text-center text-red-600 py-10">Error loading users</div>';
                });
        }

        function displayDeleteUserList(users) {
            const deleteList = document.getElementById('deleteUserList');
            if (!deleteList) return;
            
            if (users.length === 0) {
                deleteList.innerHTML = '<div class="text-center text-gray-500 py-10">No users found.</div>';
                document.getElementById('selectedCount').textContent = '0';
                return;
            }
            
            let html = '';
            users.forEach(user => {
                const roleDisplay = user.role ? user.role.charAt(0).toUpperCase() + user.role.slice(1) : 'N/A';
                html += `
                    <div class="user-delete-item flex justify-between items-center p-4 border-b border-gray-200 last:border-0">
                        <div class="flex-1">
                            <div class="font-semibold text-[#0a2d63]">${user.full_name || 'N/A'}</div>
                            <div class="text-xs text-gray-600">${user.username || 'N/A'} • ${user.email || 'N/A'} • ${roleDisplay}</div>
                        </div>
                        <input type="checkbox" class="delete-checkbox w-5 h-5 cursor-pointer" value="${user.id}" onchange="updateSelectedCount()">
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
        
            const deleteBtn = document.querySelector('#deleteUserModal .btn-delete');
            if (!deleteBtn) return;
            const originalText = deleteBtn.textContent;
            deleteBtn.textContent = 'Deleting...';
            deleteBtn.disabled = true;
        
            let deletedCount = 0;
            let failedCount = 0;
        
            function deleteNextUser(index) {
                if (index >= selectedIds.length) {
                    deleteBtn.textContent = originalText;
                    deleteBtn.disabled = false;
                    
                    if (deletedCount > 0) {
                        alert(`Successfully deleted ${deletedCount} user(s). ${failedCount > 0 ? failedCount + ' failed.' : ''}`);
                        closeDeleteUserModal();
                        if (typeof loadDeleteUserList === 'function') {
                            loadDeleteUserList();
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
                    if (!response.ok) throw new Error('Network response was not ok');
                    return response.text();
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
                    deleteNextUser(index + 1);
                })
                .catch(error => {
                    console.error('Error deleting user:', error);
                    failedCount++;
                    deleteNextUser(index + 1);
                });
            }
            
            deleteNextUser(0);
        }

        // ---------- Navigation ----------
        function navigateTo(page) {
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
                    break;
                case 'users':
                    if (['admin','registrar'].includes(userRole)) {
                        activate('usersCard');
                    }
                    break;
                case 'payables':
                    if (userRole === 'student') {
                        activate('payablesCard');
                        if (typeof loadPayables === 'function') loadPayables();
                    } 
                    else if (['admin','cashier'].includes(userRole)) {
                        activate('payablesManagementCard');
                    }
                    break;
                case 'payments':
                    if (['admin','cashier'].includes(userRole)) {
                        activate('paymentsCard');
                        if (typeof loadPaymentStudents === 'function') loadPaymentStudents();
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
            }

            if (userRole === 'teacher') {
                activate('teacherCard');
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
                    if (data.success && data.payables && data.payables.length > 0) {
                        let html = '';
                        data.payables.forEach(payable => {
                            const dueDate = new Date(payable.due_date);
                            const today = new Date();
                        
                            if (payable.status === 'paid') {
                                // handled
                            } else if (payable.status === 'partially_paid') {
                                html += `
                                    <div class="payable-item bg-gray-50 p-6 hover:bg-gray-100 transition flex flex-col md:flex-row justify-between items-start md:items-center">
                                        <div class="payable-details flex-1">
                                            <h4 class="text-lg font-semibold text-gray-800 mb-2">Tuition Fee</h4>
                                            <p class="text-gray-600 mb-2">Due: <span class="payable-date bg-gray-200 text-gray-600 px-3 py-1 rounded text-sm">${dueDate.toLocaleDateString()}</span></p>
                                            <p class="text-blue-600 text-sm">Partially Paid</p>
                                        </div>
                                        <div class="payable-amount text-right min-w-[150px]">
                                            <div class="payable-total font-bold text-xl text-[#0a2d63]">₱${parseFloat(payable.amount).toLocaleString()}</div>
                                            <div class="payable-status bg-blue-100 text-blue-800 px-3 py-1 rounded text-sm inline-block">Partially Paid</div>
                                        </div>
                                    </div>
                                `;
                            } else {
                                const isOverdue = dueDate < today;
                                html += `
                                    <div class="payable-item bg-gray-50 p-6 hover:bg-gray-100 transition flex flex-col md:flex-row justify-between items-start md:items-center">
                                        <div class="payable-details flex-1">
                                            <h4 class="text-lg font-semibold text-gray-800 mb-2">Tuition Fee</h4>
                                            <p class="text-gray-600 mb-2">Due: <span class="payable-date bg-gray-200 text-gray-600 px-3 py-1 rounded text-sm">${dueDate.toLocaleDateString()}</span></p>
                                        </div>
                                        <div class="payable-amount text-right min-w-[150px]">
                                            <div class="payable-total font-bold text-xl text-[#0a2d63]">₱${parseFloat(payable.amount).toLocaleString()}</div>
                                            <div class="payable-status px-3 py-1 rounded text-sm inline-block ${isOverdue ? 'bg-red-100 text-red-800' : 'bg-yellow-100 text-yellow-800'}">${isOverdue ? 'Overdue' : 'Pending'}</div>
                                        </div>
                                    </div>
                                `;
                            }
                        });
                        payableList.innerHTML = html;
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
            fetch('php/get_announcements.php')
                .then(response => response.json())
                .then(data => {
                    const announcementList = document.getElementById('announcementList');
                    if (!announcementList) return;
                    if (data.success && data.announcements && data.announcements.length > 0) {
                        let html = '';
                        data.announcements.forEach(announcement => {
                            const created = new Date(announcement.created_at);
                            html += `
                                <div class="announcement-item bg-gray-50 p-6 hover:bg-gray-100 transition">
                                    <div class="announcement-header flex flex-col md:flex-row justify-between items-start md:items-center mb-4">
                                        <h4 class="text-lg font-semibold text-[#0a2d63] flex-1">${announcement.title}</h4>
                                        <span class="announcement-date bg-gray-200 text-gray-600 px-3 py-1 rounded text-sm whitespace-nowrap">${created.toLocaleDateString()}</span>
                                    </div>
                                    <p class="text-gray-700 text-base leading-relaxed">${announcement.content}</p>
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

        // ---------- DOM Ready ----------
        document.addEventListener('DOMContentLoaded', function() {
            <?php if (in_array($userRole, ['admin', 'registrar'])): ?>
            updateChart();
            loadEnrollments();
            setInterval(loadEnrollments, 30000);
            <?php endif; ?>
            
            <?php if ($userRole === 'cashier'): ?>
            // Pre-load payment students when payments card becomes active
            <?php endif; ?>
            
            <?php if ($userRole == 'student'): ?>
            if (document.getElementById('homeCard').classList.contains('active')) {
                renderStudentChart();
            }
            <?php endif; ?>
            
            window.onclick = function(event) {
                const addModal = document.getElementById('addUserModal');
                const searchModal = document.getElementById('searchUserModal');
                const deleteModal = document.getElementById('deleteUserModal');
                const documentModal = document.getElementById('documentModal');
                const enrollmentSearchModal = document.getElementById('enrollmentSearchModal');
                const studentSelectModal = document.getElementById('studentSelectModal');
                const batchPromoteModal = document.getElementById('batchPromoteModal');
                
                if (event.target === addModal) closeAddUserModal();
                if (event.target === searchModal) closeSearchModal();
                if (event.target === deleteModal) closeDeleteUserModal();
                if (event.target === documentModal) closeDocumentModal();
                if (event.target === enrollmentSearchModal) closeEnrollmentSearchModal();
                if (event.target === studentSelectModal) closeStudentSelectModal();
                if (event.target === batchPromoteModal) closeBatchPromoteModal();
            }
        });
    </script>
</head>
<body class="bg-gray-100 font-sans <?php echo in_array($userRole, ['admin', 'cashier', 'registrar']) ? 'admin-mode' : ''; ?>">
    <div class="dashboard-page relative min-h-screen" id="dashboardPage">
        <!-- Permanent Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header p-8 text-center bg-white bg-opacity-10 border-b border-white border-opacity-10">
                <img src="images/logo.png" alt="BAA Logo" class="sidebar-logo w-[110px] h-[110px] mx-auto mb-5 object-contain">
                <h3 class="text-xl font-semibold text-white">Baesa Adventist Academy</h3>
            </div>
            <ul class="py-5">
                <?php if ($userRole === 'admin'): ?>
                    <li><a href="#" onclick="navigateTo('home'); return false;" class="block px-6 py-4 text-white text-opacity-90 hover:bg-white hover:bg-opacity-10 hover:text-white active:bg-white active:bg-opacity-20 active:border-l-4 active:border-green-500 font-medium transition" id="menu-home">Enrollment Requests</a></li>
                    <li><a href="#" onclick="navigateTo('users'); return false;" class="block px-6 py-4 text-white text-opacity-90 hover:bg-white hover:bg-opacity-10 hover:text-white active:bg-white active:bg-opacity-20 active:border-l-4 active:border-green-500 font-medium transition" id="menu-users">User Management</a></li>
                    <li><a href="#" onclick="navigateTo('payables'); return false;" class="block px-6 py-4 text-white text-opacity-90 hover:bg-white hover:bg-opacity-10 hover:text-white active:bg-white active:bg-opacity-20 active:border-l-4 active:border-green-500 font-medium transition" id="menu-payables">Payables Management</a></li>
                    <li><a href="#" onclick="navigateTo('payments'); return false;" class="block px-6 py-4 text-white text-opacity-90 hover:bg-white hover:bg-opacity-10 hover:text-white active:bg-white active:bg-opacity-20 active:border-l-4 active:border-green-500 font-medium transition" id="menu-payments">Payment Processing</a></li>
                    <li><a href="#" onclick="navigateTo('profile'); return false;" class="block px-6 py-4 text-white text-opacity-90 hover:bg-white hover:bg-opacity-10 hover:text-white active:bg-white active:bg-opacity-20 active:border-l-4 active:border-green-500 font-medium transition" id="menu-profile">Profile</a></li>
                <?php elseif ($userRole === 'cashier'): ?>
                    <li><a href="#" onclick="navigateTo('payables'); return false;" class="block px-6 py-4 text-white text-opacity-90 hover:bg-white hover:bg-opacity-10 hover:text-white active:bg-white active:bg-opacity-20 active:border-l-4 active:border-green-500 font-medium transition" id="menu-payables">Payables Management</a></li>
                    <li><a href="#" onclick="navigateTo('payments'); return false;" class="block px-6 py-4 text-white text-opacity-90 hover:bg-white hover:bg-opacity-10 hover:text-white active:bg-white active:bg-opacity-20 active:border-l-4 active:border-green-500 font-medium transition" id="menu-payments">Payment Processing</a></li>
                    <li><a href="#" onclick="navigateTo('profile'); return false;" class="block px-6 py-4 text-white text-opacity-90 hover:bg-white hover:bg-opacity-10 hover:text-white active:bg-white active:bg-opacity-20 active:border-l-4 active:border-green-500 font-medium transition" id="menu-profile">Profile</a></li>
                <?php elseif ($userRole === 'registrar'): ?>
                    <li><a href="#" onclick="navigateTo('home'); return false;" class="block px-6 py-4 text-white text-opacity-90 hover:bg-white hover:bg-opacity-10 hover:text-white active:bg-white active:bg-opacity-20 active:border-l-4 active:border-green-500 font-medium transition" id="menu-home">Enrollment Requests</a></li>
                    <li><a href="#" onclick="navigateTo('users'); return false;" class="block px-6 py-4 text-white text-opacity-90 hover:bg-white hover:bg-opacity-10 hover:text-white active:bg-white active:bg-opacity-20 active:border-l-4 active:border-green-500 font-medium transition" id="menu-users">User Management</a></li>
                    <li><a href="#" onclick="navigateTo('profile'); return false;" class="block px-6 py-4 text-white text-opacity-90 hover:bg-white hover:bg-opacity-10 hover:text-white active:bg-white active:bg-opacity-20 active:border-l-4 active:border-green-500 font-medium transition" id="menu-profile">Profile</a></li>
                <?php else: ?>
                    <li><a href="#" onclick="navigateTo('home'); return false;" class="block px-6 py-4 text-white text-opacity-90 hover:bg-white hover:bg-opacity-10 hover:text-white active:bg-white active:bg-opacity-20 active:border-l-4 active:border-green-500 font-medium transition" id="menu-home">Home</a></li>
                    <li><a href="#" onclick="navigateTo('grades'); return false;" class="block px-6 py-4 text-white text-opacity-90 hover:bg-white hover:bg-opacity-10 hover:text-white active:bg-white active:bg-opacity-20 active:border-l-4 active:border-green-500 font-medium transition" id="menu-grades">Grades</a></li>
                    <li><a href="#" onclick="navigateTo('subjects'); return false;" class="block px-6 py-4 text-white text-opacity-90 hover:bg-white hover:bg-opacity-10 hover:text-white active:bg-white active:bg-opacity-20 active:border-l-4 active:border-green-500 font-medium transition" id="menu-subjects">Subjects</a></li>
                    <li><a href="#" onclick="navigateTo('payables'); return false;" class="block px-6 py-4 text-white text-opacity-90 hover:bg-white hover:bg-opacity-10 hover:text-white active:bg-white active:bg-opacity-20 active:border-l-4 active:border-green-500 font-medium transition" id="menu-payables">Payables</a></li>
                    <li><a href="#" onclick="navigateTo('events'); return false;" class="block px-6 py-4 text-white text-opacity-90 hover:bg-white hover:bg-opacity-10 hover:text-white active:bg-white active:bg-opacity-20 active:border-l-4 active:border-green-500 font-medium transition" id="menu-events">Events</a></li>
                    <li><a href="#" onclick="navigateTo('profile'); return false;" class="block px-6 py-4 text-white text-opacity-90 hover:bg-white hover:bg-opacity-10 hover:text-white active:bg-white active:bg-opacity-20 active:border-l-4 active:border-green-500 font-medium transition" id="menu-profile">Profile</a></li>
                    <li><a href="#" onclick="navigateTo('announcements'); return false;" class="block px-6 py-4 text-white text-opacity-90 hover:bg-white hover:bg-opacity-10 hover:text-white active:bg-white active:bg-opacity-20 active:border-l-4 active:border-green-500 font-medium transition" id="menu-announcements">Announcements</a></li>
                <?php endif; ?>
                <?php if ($userRole == 'teacher'): ?>
                <li><a href="teacher_grades.php" class="block px-6 py-4 text-white text-opacity-90 hover:bg-white hover:bg-opacity-10 hover:text-white active:bg-white active:bg-opacity-20 active:border-l-4 active:border-green-500 font-medium transition" id="menu-teacher">Grade Encoding</a></li>
                <?php endif; ?>
            </ul>
        </div>
        
        <!-- Main Content -->
        <div class="dashboard-main flex flex-col min-h-screen">
            <!-- Dashboard Header -->
            <div class="dashboard-header bg-[#0a2d63] text-white px-4 md:px-10 py-4 shadow-md w-full">
                <div class="header-content flex items-center justify-between max-w-7xl mx-auto">
                    <div class="header-left flex items-center flex-1"></div>
                    <div class="header-center text-center flex-1">
                        <?php if (in_array($userRole, ['student', 'teacher'])): ?>
                            <h2 class="text-2xl md:text-3xl font-semibold mb-1">Welcome <?php echo ucfirst($userRole) . ' ' . htmlspecialchars($fullName); ?> to Your Dashboard</h2>
                        <?php else: ?>
                            <h2 class="text-2xl md:text-3xl font-semibold mb-1">Welcome to Your Dashboard</h2>
                        <?php endif; ?>
                        <p class="text-sm md:text-base opacity-90 whitespace-nowrap">Stay updated with your academic progress and school activities</p>
                    </div>
                    <div class="header-right flex items-center justify-end gap-4 flex-1">
                        <div class="user-info-container flex items-center gap-4">
                            <span class="user-name font-bold text-xl md:text-2xl text-white"><?php echo htmlspecialchars($fullName); ?></span>
                            <button class="logout-btn bg-white text-[#0a2d63] px-5 py-2 rounded-full font-semibold hover:bg-gray-100 hover:-translate-y-0.5 transition" onclick="window.location.href='php/logout.php'">Logout</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Dashboard Content -->
            <div class="dashboard-content flex justify-center items-start w-full p-5 min-h-[calc(100vh-120px)]">
                <div class="centered-container w-full max-w-[1200px] mx-auto">
                    <?php if (in_array($userRole, ['admin', 'registrar'])): ?>
                        <!-- Admin/Registrar Enrollment Card -->
                        <div class="dashboard-card bg-white shadow-lg border border-gray-200 hidden <?php echo (in_array($userRole, ['admin', 'registrar'])) ? 'active' : ''; ?>" id="adminEnrollmentCard">
                            <div class="card-content p-8 space-y-6 w-full">
                                <div class="stats-grid grid grid-cols-1 md:grid-cols-3 gap-6">
                                    <div class="stat-card">
                                        <h3>New Requests</h3>
                                        <div class="value"><?php echo $newRequests; ?></div>
                                    </div>
                                    <div class="stat-card">
                                        <h3>Grades 7-10</h3>
                                        <div class="value"><?php echo $grades7to10; ?></div>
                                    </div>
                                    <div class="stat-card">
                                        <h3>Grades 11-12</h3>
                                        <div class="value"><?php echo $grades11to12; ?></div>
                                    </div>
                                </div>

                                <div class="chart-container">
                                    <div class="flex flex-wrap gap-4 mb-4">
                                        <select id="dataTypeFilter" class="filter-select" onchange="updateChart()">
                                            <option value="enrollees">Enrollment Requests</option>
                                            <option value="students">Registered Students</option>
                                            <option value="both">Both</option>
                                        </select>
                                        <select id="chartGradeFilter" class="filter-select" onchange="updateChart()">
                                            <option value="">All Grades</option>
                                            <option value="Grade 7">Grade 7</option>
                                            <option value="Grade 8">Grade 8</option>
                                            <option value="Grade 9">Grade 9</option>
                                            <option value="Grade 10">Grade 10</option>
                                            <option value="Grade 11">Grade 11</option>
                                            <option value="Grade 12">Grade 12</option>
                                        </select>
                                        <select id="chartSectionFilter" class="filter-select" onchange="updateChart()">
                                            <option value="">All Sections</option>
                                        </select>
                                    </div>
                                    <canvas id="enrollmentChart" style="width:100%; max-height:300px;"></canvas>
                                </div>

                                <div class="enrollment-controls flex flex-col md:flex-row justify-between items-center gap-4 p-4 bg-gray-50 rounded">
                                    <div class="enrollment-stats flex flex-col md:flex-row items-center gap-4">
                                        <h3 class="text-2xl font-semibold text-[#0a2d63]">Student Access Requests</h3>
                                    </div>
                                    <button class="search-enrollment-btn bg-[#0a2d63] text-white px-4 py-2 rounded font-medium hover:bg-[#08306b] transition flex items-center gap-2" onclick="openEnrollmentSearchModal()">
                                        Search Enrollees
                                    </button>
                                </div>
                                <p class="text-gray-600">Review and manage pending student enrollments</p>
                                
                                <div id="enrollmentList" class="space-y-4">
                                    <table class="enrollment-table">
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
                                    <div class="custom-per-page flex items-center gap-2">
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
                                    <div class="pagination-info text-sm text-gray-600" id="paginationInfo"></div>
                                    <div class="pagination-buttons flex gap-1 ml-auto" id="paginationButtons"></div>
                                </div>
                            </div>
                        </div>

                        <!-- User Management Card -->
                        <div class="dashboard-card bg-white shadow-lg border border-gray-200 hidden" id="usersCard">
                            <div class="card-content p-8 space-y-6 w-full">
                                <div class="flex justify-between items-center">
                                    <div>
                                        <h3 class="text-2xl font-semibold text-[#0a2d63] mb-2">User Management</h3>
                                        <p class="text-gray-600">Manage user accounts - add and delete users</p>
                                    </div>
                                    <button onclick="openSearchModal()" class="search-icon-outline bg-white border-2 border-[#0a2d63] rounded p-2 hover:bg-gray-100 transition">
                                        <img src="images/search_icon.png" alt="Search" class="w-6 h-6">
                                    </button>
                                </div>

                                <div class="flex gap-8 justify-center">
                                    <button onclick="openAddUserModal()" class="bg-green-600 text-white px-6 py-3 rounded font-medium hover:bg-green-700 transition flex items-center gap-2">
                                        Add User
                                    </button>
                                    <button onclick="openDeleteUserModal()" class="bg-red-600 text-white px-6 py-3 rounded font-medium hover:bg-red-700 transition flex items-center gap-2">
                                        Delete User
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Payables Management Card -->
                        <div class="dashboard-card bg-white shadow-lg border border-gray-200 hidden" id="payablesManagementCard">
                            <div class="card-content p-8 space-y-6 w-full">
                                <div>
                                    <h3 class="text-2xl font-semibold text-[#0a2d63] mb-2">Payables Management</h3>
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
                                            <label class="block mb-1 font-medium text-gray-700">Number of Monthly Payments</label>
                                            <input type="number" id="monthlyPayments" placeholder="4" min="1" max="12" value="4" class="w-full p-2 border border-gray-300 rounded">
                                        </div>
                                        <div class="md:col-span-2 text-center">
                                            <button type="button" onclick="calculatePayables()" class="bg-[#0a2d63] text-white px-5 py-2 rounded font-medium hover:bg-[#08306b] transition">Calculate Remaining Balance</button>
                                        </div>
                                    </form>
                                </div>

                                <div id="calculationResult" class="hidden p-5 bg-gray-50 border border-gray-200 rounded space-y-4">
                                    <h4 class="text-lg font-semibold text-[#0a2d63]">Calculation Result</h4>
                                    <div id="resultContent"></div>
                                    <div class="flex gap-4 justify-center">
                                        <button onclick="generateAssessmentPDF()" id="generatePdfBtn" class="hidden bg-[#0a2d63] text-white px-5 py-2 rounded font-medium hover:bg-[#08306b] transition">
                                            Generate Assessment PDF
                                        </button>
                                        <button onclick="addPayable()" id="addPayableBtn" class="hidden bg-green-600 text-white px-5 py-2 rounded font-medium hover:bg-green-700 transition">
                                            Add to Student Payables
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Payment Processing Card -->
                        <div class="dashboard-card bg-white shadow-lg border border-gray-200 hidden" id="paymentsCard">
                            <div class="card-content p-8 space-y-6 w-full">
                                <div>
                                    <h3 class="text-2xl font-semibold text-[#0a2d63] mb-2">Payment Processing</h3>
                                    <p class="text-gray-600">Process student payments and update payable status</p>
                                </div>

                                <div class="p-6 bg-gray-50 rounded space-y-4">
                                    <h4 class="text-lg font-semibold text-[#0a2d63]">Process Payment</h4>
                                    <form id="paymentForm" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div class="form-group">
                                            <label for="paymentStudentSelect" class="block mb-2 font-medium text-gray-700">Select Student *</label>
                                            <select id="paymentStudentSelect" class="w-full p-2 border border-gray-300 rounded" required>
                                                <option value="">Select Student</option>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label for="paymentAmount" class="block mb-2 font-medium text-gray-700">Payment Amount *</label>
                                            <input type="number" id="paymentAmount" placeholder="0.00" step="0.01" min="0" class="w-full p-2 border border-gray-300 rounded" required>
                                        </div>
                                        <div class="form-group md:col-span-2">
                                            <label for="paymentDate" class="block mb-2 font-medium text-gray-700">Payment Date</label>
                                            <input type="date" id="paymentDate" value="<?php echo date('Y-m-d'); ?>" class="w-full p-2 border border-gray-300 rounded">
                                        </div>
                                        <div class="form-actions md:col-span-2 text-center">
                                            <button type="button" onclick="loadStudentPayables()" class="bg-[#0a2d63] text-white px-5 py-2 rounded font-medium hover:bg-[#08306b] transition mr-2">
                                                Load Student Payables
                                            </button>
                                            <button type="button" onclick="processPayment()" class="bg-green-600 text-white px-5 py-2 rounded font-medium hover:bg-green-700 transition">
                                                Process Payment
                                            </button>
                                        </div>
                                    </form>
                                </div>

                                <div id="studentPayables" class="hidden p-6 bg-gray-50 border border-gray-200 rounded space-y-4">
                                    <h4 class="text-lg font-semibold text-[#0a2d63]">Student Payables</h4>
                                    <div id="payablesList" class="loading">Loading payables...</div>
                                </div>

                                <div id="paymentResult" class="hidden p-6 bg-green-100 border border-green-300 rounded text-green-700"></div>
                            </div>
                        </div>

                        <!-- Profile Card for Admin/Registrar -->
                        <div class="dashboard-card bg-white shadow-lg border border-gray-200 hidden" id="adminProfileCard">
                            <div class="card-content p-8 space-y-6 w-full">
                                <div>
                                    <h3 class="text-2xl font-semibold text-[#0a2d63] mb-2">Profile</h3>
                                    <p class="text-gray-600">View and update your personal information.</p>
                                </div>
                                <div class="profile-info bg-gray-50 p-8 space-y-4" id="adminProfileInfo">
                                    <div class="info-item flex justify-between items-center py-4 border-b border-gray-200 last:border-0">
                                        <span class="label font-semibold text-gray-800">Full Name:</span>
                                        <span class="value text-gray-600"><?php echo htmlspecialchars($fullName); ?></span>
                                    </div>
                                    <div class="info-item flex justify-between items-center py-4 border-b border-gray-200 last:border-0">
                                        <span class="label font-semibold text-gray-800">Username:</span>
                                        <span class="value text-gray-600"><?php echo htmlspecialchars($userName); ?></span>
                                    </div>
                                    <div class="info-item flex justify-between items-center py-4 border-b border-gray-200 last:border-0">
                                        <span class="label font-semibold text-gray-800">User Role:</span>
                                        <span class="value text-gray-600"><?php echo htmlspecialchars(ucfirst($userRole)); ?></span>
                                    </div>
                                    <div class="info-item flex justify-between items-center py-4 border-b border-gray-200 last:border-0">
                                        <span class="label font-semibold text-gray-800">Email:</span>
                                        <span class="value text-gray-600"><?php echo htmlspecialchars($user['email'] ?? 'N/A'); ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>

                    <?php elseif ($userRole == 'cashier'): ?>
                        <!-- Cashier Dashboard Cards -->
                        <div class="dashboard-card bg-white shadow-lg border border-gray-200 hidden active" id="payablesManagementCard">
                            <div class="card-content p-8 space-y-6 w-full">
                                <div>
                                    <h3 class="text-2xl font-semibold text-[#0a2d63] mb-2">Payables Management</h3>
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
                                            <label class="block mb-1 font-medium text-gray-700">Number of Monthly Payments</label>
                                            <input type="number" id="monthlyPayments" placeholder="4" min="1" max="12" value="4" class="w-full p-2 border border-gray-300 rounded">
                                        </div>
                                        <div class="md:col-span-2 text-center">
                                            <button type="button" onclick="calculatePayables()" class="bg-[#0a2d63] text-white px-5 py-2 rounded font-medium hover:bg-[#08306b] transition">Calculate Remaining Balance</button>
                                        </div>
                                    </form>
                                </div>
                                <div id="calculationResult" class="hidden p-5 bg-gray-50 border border-gray-200 rounded space-y-4">
                                    <h4 class="text-lg font-semibold text-[#0a2d63]">Calculation Result</h4>
                                    <div id="resultContent"></div>
                                    <div class="flex gap-4 justify-center">
                                        <button onclick="generateAssessmentPDF()" id="generatePdfBtn" class="hidden bg-[#0a2d63] text-white px-5 py-2 rounded font-medium hover:bg-[#08306b] transition">
                                            Generate Assessment PDF
                                        </button>
                                        <button onclick="addPayable()" id="addPayableBtn" class="hidden bg-green-600 text-white px-5 py-2 rounded font-medium hover:bg-green-700 transition">
                                            Add to Student Payables
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="dashboard-card bg-white shadow-lg border border-gray-200 hidden" id="paymentsCard">
                            <div class="card-content p-8 space-y-6 w-full">
                                <div>
                                    <h3 class="text-2xl font-semibold text-[#0a2d63] mb-2">Payment Processing</h3>
                                    <p class="text-gray-600">Process student payments and update payable status</p>
                                </div>
                                <div class="p-6 bg-gray-50 rounded space-y-4">
                                    <h4 class="text-lg font-semibold text-[#0a2d63]">Process Payment</h4>
                                    <form id="paymentForm" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div class="form-group">
                                            <label for="paymentStudentSelect" class="block mb-2 font-medium text-gray-700">Select Student *</label>
                                            <select id="paymentStudentSelect" class="w-full p-2 border border-gray-300 rounded" required>
                                                <option value="">Select Student</option>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label for="paymentAmount" class="block mb-2 font-medium text-gray-700">Payment Amount *</label>
                                            <input type="number" id="paymentAmount" placeholder="0.00" step="0.01" min="0" class="w-full p-2 border border-gray-300 rounded" required>
                                        </div>
                                        <div class="form-group md:col-span-2">
                                            <label for="paymentDate" class="block mb-2 font-medium text-gray-700">Payment Date</label>
                                            <input type="date" id="paymentDate" value="<?php echo date('Y-m-d'); ?>" class="w-full p-2 border border-gray-300 rounded">
                                        </div>
                                        <div class="form-actions md:col-span-2 text-center">
                                            <button type="button" onclick="loadStudentPayables()" class="bg-[#0a2d63] text-white px-5 py-2 rounded font-medium hover:bg-[#08306b] transition mr-2">
                                                Load Student Payables
                                            </button>
                                            <button type="button" onclick="processPayment()" class="bg-green-600 text-white px-5 py-2 rounded font-medium hover:bg-green-700 transition">
                                                Process Payment
                                            </button>
                                        </div>
                                    </form>
                                </div>
                                <div id="studentPayables" class="hidden p-6 bg-gray-50 border border-gray-200 rounded space-y-4">
                                    <h4 class="text-lg font-semibold text-[#0a2d63]">Student Payables</h4>
                                    <div id="payablesList" class="loading">Loading payables...</div>
                                </div>
                                <div id="paymentResult" class="hidden p-6 bg-green-100 border border-green-300 rounded text-green-700"></div>
                            </div>
                        </div>

                        <div class="dashboard-card bg-white shadow-lg border border-gray-200 hidden" id="adminProfileCard">
                            <div class="card-content p-8 space-y-6 w-full">
                                <div>
                                    <h3 class="text-2xl font-semibold text-[#0a2d63] mb-2">Profile</h3>
                                    <p class="text-gray-600">View your personal information.</p>
                                </div>
                                <div class="profile-info bg-gray-50 p-8 space-y-4">
                                    <div class="info-item flex justify-between items-center py-4 border-b border-gray-200 last:border-0">
                                        <span class="label font-semibold text-gray-800">Full Name:</span>
                                        <span class="value text-gray-600"><?php echo htmlspecialchars($fullName); ?></span>
                                    </div>
                                    <div class="info-item flex justify-between items-center py-4 border-b border-gray-200 last:border-0">
                                        <span class="label font-semibold text-gray-800">Username:</span>
                                        <span class="value text-gray-600"><?php echo htmlspecialchars($userName); ?></span>
                                    </div>
                                    <div class="info-item flex justify-between items-center py-4 border-b border-gray-200 last:border-0">
                                        <span class="label font-semibold text-gray-800">User Role:</span>
                                        <span class="value text-gray-600"><?php echo htmlspecialchars(ucfirst($userRole)); ?></span>
                                    </div>
                                    <div class="info-item flex justify-between items-center py-4 border-b border-gray-200 last:border-0">
                                        <span class="label font-semibold text-gray-800">Email:</span>
                                        <span class="value text-gray-600"><?php echo htmlspecialchars($user['email'] ?? 'N/A'); ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>

                    <?php elseif ($userRole == 'student'): ?>
                        <!-- Student Home Card -->
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
                                        <?php if (!empty($grades)): ?>
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
                                                    <?php foreach ($groupedGrades as $subjectName => $data): ?>
                                                    <tr class="border-b border-gray-200 hover:bg-gray-50">
                                                        <td class="p-4 font-semibold text-gray-800"><?php echo htmlspecialchars($subjectName); ?></td>
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
                                        <button class="view-all-btn bg-[#0a2d63] text-white px-4 py-2 rounded font-medium hover:bg-[#08306b] transition" onclick="toggleHomeSubjects()">View All Subjects</button>
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

                        <!-- Grades Card -->
                        <div class="dashboard-card bg-white shadow-lg border border-gray-200 hidden" id="gradesCard">
                            <div class="card-content p-8 space-y-6 w-full">
                                <div>
                                    <h3 class="text-2xl font-semibold text-[#0a2d63] mb-2">Grades</h3>
                                    <p class="text-gray-600">Check your current grades, academic performance, and progress reports.</p>
                                </div>
                                
                                <div class="grade-summary overflow-x-auto">
                                    <?php if (!empty($grades)): ?>
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
                                                <?php foreach ($groupedGrades as $subjectName => $data): ?>
                                                <tr class="border-b border-gray-200 hover:bg-gray-50">
                                                    <td class="p-4 font-semibold text-gray-800"><?php echo htmlspecialchars($subjectName); ?></td>
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

                        <!-- Subjects Card (grouped with day abbreviations) -->
                        <div class="dashboard-card bg-white shadow-lg border border-gray-200 hidden" id="subjectsCard">
                            <div class="card-content p-8 space-y-6 w-full">
                                <div class="subjects-header flex flex-col md:flex-row justify-between items-start md:items-center gap-4 pb-4 border-b border-gray-200">
                                    <div>
                                        <h3 class="text-2xl font-semibold text-[#0a2d63]">Today's Subjects</h3>
                                    </div>
                                    <button class="view-all-btn bg-[#0a2d63] text-white px-4 py-2 rounded font-medium hover:bg-[#08306b] transition" onclick="toggleSubjectCard()" id="subjectsCardBtn">View All Subjects</button>
                                </div>
                                <p class="text-gray-600">Your subjects scheduled for today.</p>
                                
                                <div class="subject-list" id="todaySubjectsCardList">
                                    <table class="grades-table w-full border-collapse bg-white shadow-sm rounded overflow-hidden">
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
                                                        <td class="p-4 font-semibold text-gray-800"><?php echo htmlspecialchars($subject['subject_name'] ?? ''); ?></td>
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
                                
                                <div class="subject-list hidden" id="allSubjectsCardList">
                                    <table class="grades-table w-full border-collapse bg-white shadow-sm rounded overflow-hidden">
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
                                                        <td class="p-4 font-semibold text-gray-800"><?php echo htmlspecialchars($item['subject_name']); ?></td>
                                                        <td class="p-4 text-gray-700">—</td>
                                                        <td class="p-4 text-gray-700"><?php echo $item['schedules_display']; ?></td>
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

                        <!-- Events Card -->
                        <div class="dashboard-card bg-white shadow-lg border border-gray-200 hidden" id="eventsCard">
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

                        <!-- Payables Card -->
                        <div class="dashboard-card bg-white shadow-lg border border-gray-200 hidden" id="payablesCard">
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
                        
                        <!-- Profile Card -->
                        <div class="dashboard-card bg-white shadow-lg border border-gray-200 hidden" id="profileCard">
                            <div class="card-content p-8 space-y-6 w-full">
                                <div>
                                    <h3 class="text-2xl font-semibold text-[#0a2d63] mb-2">Profile</h3>
                                    <p class="text-gray-600">View and update your personal information.</p>
                                </div>
                                <div class="profile-info bg-gray-50 p-8 space-y-4" id="profileInfo">
                                    <div class="info-item flex justify-between items-center py-4 border-b border-gray-200 last:border-0">
                                        <span class="label font-semibold text-gray-800">Full Name:</span>
                                        <span class="value text-gray-600"><?php echo htmlspecialchars($fullName); ?></span>
                                    </div>
                                    <div class="info-item flex justify-between items-center py-4 border-b border-gray-200 last:border-0">
                                        <span class="label font-semibold text-gray-800">Username:</span>
                                        <span class="value text-gray-600"><?php echo htmlspecialchars($userName); ?></span>
                                    </div>
                                    <div class="info-item flex justify-between items-center py-4 border-b border-gray-200 last:border-0">
                                        <span class="label font-semibold text-gray-800">User Role:</span>
                                        <span class="value text-gray-600"><?php echo htmlspecialchars(ucfirst($userRole)); ?></span>
                                    </div>
                                    <div class="info-item flex justify-between items-center py-4 border-b border-gray-200 last:border-0">
                                        <span class="label font-semibold text-gray-800">Grade Level:</span>
                                        <span class="value text-gray-600"><?php echo htmlspecialchars($gradeLevel); ?></span>
                                    </div>
                                    <div class="info-item flex justify-between items-center py-4 border-b border-gray-200 last:border-0">
                                        <span class="label font-semibold text-gray-800">Section:</span>
                                        <span class="value text-gray-600"><?php echo htmlspecialchars($section); ?></span>
                                    </div>
                                    <div class="info-item flex justify-between items-center py-4 border-b border-gray-200 last:border-0">
                                        <span class="label font-semibold text-gray-800">LRN:</span>
                                        <span class="value text-gray-600"><?php echo htmlspecialchars($lrn); ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Announcements Card -->
                        <div class="dashboard-card bg-white shadow-lg border border-gray-200 hidden" id="announcementsCard">
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
                        <!-- Teacher Dashboard -->
                        <div class="dashboard-card bg-white shadow-lg border border-gray-200 hidden active" id="teacherCard">
                            <div class="card-content p-8 space-y-6 w-full">
                                <div>
                                    <h3 class="text-2xl font-semibold text-[#0a2d63] mb-2">Teacher Dashboard</h3>
                                    <p class="text-gray-600">Welcome, <?php echo htmlspecialchars($fullName); ?>!</p>
                                </div>
                                <div class="teacher-actions flex flex-col sm:flex-row gap-4 mt-5">
                                    <a href="teacher_grades.php" class="action-btn bg-[#0a2d63] text-white px-6 py-3 rounded font-medium hover:bg-[#08306b] transition">Encode Grades</a>
                                    <a href="teacher_subjects.php" class="action-btn bg-[#0a2d63] text-white px-6 py-3 rounded font-medium hover:bg-[#08306b] transition">My Subjects</a>
                                    <a href="teacher_students.php" class="action-btn bg-[#0a2d63] text-white px-6 py-3 rounded font-medium hover:bg-[#08306b] transition">My Students</a>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                </div>
            </div>
        </div>

        <!-- ========== GLOBAL MODALS ========== -->
        <!-- Add User Modal -->
        <div id="addUserModal" class="modal-overlay fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-[1000]">
            <div class="modal-container bg-white rounded-lg w-[90%] max-w-lg max-h-[90vh] overflow-y-auto shadow-xl">
                <div class="modal-header p-5 border-b border-gray-200 bg-gray-50 rounded-t-lg flex justify-between items-center">
                    <h3 class="text-xl font-semibold text-[#0a2d63]">Add New User</h3>
                    <button class="modal-close text-2xl text-gray-600 hover:text-gray-800 w-8 h-8 flex items-center justify-center rounded hover:bg-gray-200 transition" onclick="closeAddUserModal()">×</button>
                </div>
                <div class="modal-body p-6">
                    <form id="createUserForm">
                        <div class="form-group mb-4">
                            <label class="block mb-2 font-medium text-gray-700">Username *</label>
                            <input type="text" name="username" required class="w-full p-2 border border-gray-300 rounded focus:outline-none focus:border-[#0a2d63] focus:ring-2 focus:ring-[#0a2d63] focus:ring-opacity-10">
                        </div>
                        <div class="form-group mb-4">
                            <label class="block mb-2 font-medium text-gray-700">Email *</label>
                            <input type="email" name="email" required class="w-full p-2 border border-gray-300 rounded">
                        </div>
                        <div class="form-group mb-4">
                            <label class="block mb-2 font-medium text-gray-700">Password *</label>
                            <input type="password" name="password" required class="w-full p-2 border border-gray-300 rounded">
                        </div>
                        <div class="form-group mb-4">
                            <label class="block mb-2 font-medium text-gray-700">First Name *</label>
                            <input type="text" name="first_name" required class="w-full p-2 border border-gray-300 rounded">
                        </div>
                        <div class="form-group mb-4">
                            <label class="block mb-2 font-medium text-gray-700">Middle Name</label>
                            <input type="text" name="middle_name" class="w-full p-2 border border-gray-300 rounded">
                        </div>
                        <div class="form-group mb-4">
                            <label class="block mb-2 font-medium text-gray-700">Last Name *</label>
                            <input type="text" name="last_name" required class="w-full p-2 border border-gray-300 rounded">
                        </div>
                        <div class="form-group mb-4">
                            <label class="block mb-2 font-medium text-gray-700">Suffix</label>
                            <input type="text" name="suffix" class="w-full p-2 border border-gray-300 rounded">
                        </div>
                        <div class="form-group mb-4">
                            <label class="block mb-2 font-medium text-gray-700">Role *</label>
                            <select name="role" id="modalRoleSelect" onchange="toggleModalStudentFields()" required class="w-full p-2 border border-gray-300 rounded">
                                <option value="">Select Role</option>
                                <option value="student">Student</option>
                                <option value="teacher">Teacher</option>
                                <?php if ($userRole == 'admin'): ?>
                                <option value="cashier">Cashier</option>
                                <option value="registrar">Registrar</option>
                                <option value="admin">Admin</option>
                                <?php endif; ?>
                            </select>
                        </div>
                        
                        <!-- Student-specific fields -->
                        <div id="modalStudentFields" class="student-fields hidden p-4 bg-gray-50 rounded mb-4">
                            <div class="form-group mb-4">
                                <label class="block mb-2 font-medium text-gray-700">Grade Level *</label>
                                <select name="gradeLevel" id="modalGradeLevel" onchange="updateModalSections()" class="w-full p-2 border border-gray-300 rounded">
                                    <option value="">Select Grade Level</option>
                                    <option value="Grade 7">Grade 7</option>
                                    <option value="Grade 8">Grade 8</option>
                                    <option value="Grade 9">Grade 9</option>
                                    <option value="Grade 10">Grade 10</option>
                                    <option value="Grade 11">Grade 11</option>
                                    <option value="Grade 12">Grade 12</option>
                                </select>
                            </div>
                            <div class="form-group mb-4">
                                <label class="block mb-2 font-medium text-gray-700">Section *</label>
                                <select name="section" id="modalSectionSelect" class="w-full p-2 border border-gray-300 rounded">
                                    <option value="">Select Section</option>
                                </select>
                            </div>
                            <div class="form-group mb-4">
                                <label class="block mb-2 font-medium text-gray-700">LRN *</label>
                                <input type="text" name="lrn" id="modalLrnField" class="w-full p-2 border border-gray-300 rounded">
                            </div>
                            <div class="form-group mb-4">
                                <label class="block mb-2 font-medium text-gray-700">Age *</label>
                                <input type="number" name="age" id="modalAge" min="1" max="120" class="w-full p-2 border border-gray-300 rounded" required>
                            </div>
                            <div class="form-group mb-4">
                                <label class="block mb-2 font-medium text-gray-700">Gender *</label>
                                <select name="gender" id="modalGender" class="w-full p-2 border border-gray-300 rounded" required>
                                    <option value="">Select Gender</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                </select>
                            </div>
                            <div class="form-group mb-4">
                                <label class="block mb-2 font-medium text-gray-700">Birthdate *</label>
                                <input type="date" name="birthdate" id="modalBirthdate" class="w-full p-2 border border-gray-300 rounded" required>
                            </div>
                            <div id="modalStrandContainer" class="form-group mb-4 hidden">
                                <label class="block mb-2 font-medium text-gray-700">Strand *</label>
                                <select name="strand" id="modalStrand" class="w-full p-2 border border-gray-300 rounded">
                                    <option value="">Select Strand</option>
                                    <option value="STEM">STEM</option>
                                    <option value="ABM">ABM</option>
                                    <option value="HUMSS">HUMSS</option>
                                </select>
                            </div>
                            <div class="form-group mb-4">
                                <label class="block mb-2 font-medium text-gray-700">Phone Number *</label>
                                <div class="phone-input-wrapper flex items-center border border-gray-300 rounded">
                                    <span class="phone-prefix bg-gray-100 px-3 py-2 rounded-l border-r border-gray-300">+63</span>
                                    <input type="text" name="phone" id="modalPhone" maxlength="10" placeholder="9XXXXXXXXX" pattern="[0-9]{10}" oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0,10)" class="w-full p-2 border-0 rounded-r focus:ring-0" required>
                                </div>
                                <small class="text-gray-500">Enter 10 digits (without +63)</small>
                            </div>
                            <input type="hidden" id="modalEnrollmentId" value="">
                        </div>
                    </form>
                </div>
                <div class="modal-footer p-5 border-t border-gray-200 bg-gray-50 rounded-b-lg text-right">
                    <button class="bg-gray-600 text-white px-5 py-2 rounded font-medium hover:bg-gray-700 transition mr-2" onclick="closeAddUserModal()">Cancel</button>
                    <button class="bg-[#0a2d63] text-white px-5 py-2 rounded font-medium hover:bg-[#08306b] transition" onclick="submitAddUser()">Add User</button>
                </div>
            </div>
        </div>

        <!-- Search Users Modal -->
        <div id="searchUserModal" class="modal-overlay fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-[1000]">
            <div class="modal-container bg-white rounded-lg w-[90%] max-w-3xl max-h-[90vh] overflow-y-auto shadow-xl">
                <div class="modal-header p-5 border-b border-gray-200 bg-gray-50 rounded-t-lg flex justify-between items-center">
                    <h3 class="text-xl font-semibold text-[#0a2d63]">Search Users</h3>
                    <button class="modal-close text-2xl text-gray-600 hover:text-gray-800 w-8 h-8 flex items-center justify-center rounded hover:bg-gray-200 transition" onclick="closeSearchModal()">×</button>
                </div>
                <div class="modal-body p-6">
                    <div class="form-group mb-4">
                        <label class="block mb-2 font-medium text-gray-700">Search by name, email, or username</label>
                        <input type="text" id="searchInput" placeholder="Type to search..." class="w-full p-2 border border-gray-300 rounded" onkeyup="performSearch()">
                    </div>

                    <div class="filter-section bg-gray-50 p-4 rounded mb-4">
                        <h4 class="text-sm font-semibold text-[#0a2d63] mb-2">Filter by Role</h4>
                        <div class="checkbox-group flex flex-wrap gap-4">
                            <div class="checkbox-item flex items-center gap-2">
                                <input type="checkbox" id="filterStudent" value="student" onchange="applyFilters()">
                                <label for="filterStudent" class="text-sm text-gray-700">Student</label>
                            </div>
                            <div class="checkbox-item flex items-center gap-2">
                                <input type="checkbox" id="filterTeacher" value="teacher" onchange="applyFilters()">
                                <label for="filterTeacher" class="text-sm text-gray-700">Teacher</label>
                            </div>
                            <div class="checkbox-item flex items-center gap-2">
                                <input type="checkbox" id="filterAdmin" value="admin" onchange="applyFilters()">
                                <label for="filterAdmin" class="text-sm text-gray-700">Admin</label>
                            </div>
                        </div>
                    </div>

                    <div class="filter-section bg-gray-50 p-4 rounded mb-4">
                        <h4 class="text-sm font-semibold text-[#0a2d63] mb-2">Filter by Grade Level</h4>
                        <div class="form-group mb-3">
                            <select id="filterGradeLevel" class="w-full p-2 border border-gray-300 rounded" onchange="updateFilterSections(); applyFilters();">
                                <option value="">All Grade Levels</option>
                                <option value="Grade 7">Grade 7</option>
                                <option value="Grade 8">Grade 8</option>
                                <option value="Grade 9">Grade 9</option>
                                <option value="Grade 10">Grade 10</option>
                                <option value="Grade 11">Grade 11</option>
                                <option value="Grade 12">Grade 12</option>
                            </select>
                        </div>
                        <div id="filterSectionContainer" class="hidden">
                            <label class="block mb-2 font-medium text-gray-700">Section</label>
                            <select id="filterSection" class="w-full p-2 border border-gray-300 rounded" onchange="applyFilters()">
                                <option value="">All Sections</option>
                            </select>
                        </div>
                    </div>

                    <div class="filter-section bg-gray-50 p-4 rounded mb-4">
                        <h4 class="text-sm font-semibold text-[#0a2d63] mb-2">Sort By</h4>
                        <div class="sort-options flex gap-2 flex-wrap">
                            <span class="sort-option px-3 py-1 border border-gray-300 rounded-full cursor-pointer text-sm hover:bg-gray-200 transition active:bg-[#0a2d63] active:text-white active:border-[#0a2d63]" onclick="setSort('name')" id="sort-name">Name</span>
                            <span class="sort-option px-3 py-1 border border-gray-300 rounded-full cursor-pointer text-sm hover:bg-gray-200 transition" onclick="setSort('role')" id="sort-role">Role</span>
                            <span class="sort-option px-3 py-1 border border-gray-300 rounded-full cursor-pointer text-sm hover:bg-gray-200 transition" onclick="setSort('grade')" id="sort-grade">Grade Level</span>
                            <span class="sort-option px-3 py-1 border border-gray-300 rounded-full cursor-pointer text-sm hover:bg-gray-200 transition" onclick="setSort('date')" id="sort-date">Date Joined</span>
                        </div>
                    </div>

                    <div id="searchResults" class="search-results max-h-72 overflow-y-auto border border-gray-200 rounded mt-4">
                        <div class="text-center p-10 text-gray-500">Start typing to search for users</div>
                    </div>
                </div>
                <div class="modal-footer p-5 border-t border-gray-200 bg-gray-50 rounded-b-lg text-right">
                    <button class="bg-gray-600 text-white px-5 py-2 rounded font-medium hover:bg-gray-700 transition" onclick="closeSearchModal()">Close</button>
                </div>
            </div>
        </div>

        <!-- Enrollment Search Modal -->
        <div id="enrollmentSearchModal" class="modal-overlay fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-[1000]">
            <div class="modal-container bg-white rounded-lg w-[90%] max-w-3xl max-h-[90vh] overflow-y-auto shadow-xl">
                <div class="modal-header p-5 border-b border-gray-200 bg-gray-50 rounded-t-lg flex justify-between items-center">
                    <h3 class="text-xl font-semibold text-[#0a2d63]">Search Enrollees</h3>
                    <button class="modal-close text-2xl text-gray-600 hover:text-gray-800 w-8 h-8 flex items-center justify-center rounded hover:bg-gray-200 transition" onclick="closeEnrollmentSearchModal()">×</button>
                </div>
                <div class="modal-body p-6">
                    <div class="form-group mb-4">
                        <label class="block mb-2 font-medium text-gray-700">Search by name, email, or phone</label>
                        <input type="text" id="enrollmentSearchInput" placeholder="Type to search..." class="w-full p-2 border border-gray-300 rounded" onkeyup="filterEnrollments()">
                    </div>

                    <div class="filter-section bg-gray-50 p-4 rounded mb-4">
                        <h4 class="text-sm font-semibold text-[#0a2d63] mb-2">Filter by Status</h4>
                        <div class="checkbox-group flex flex-wrap gap-4">
                            <div class="checkbox-item flex items-center gap-2">
                                <input type="checkbox" id="filterPending" value="pending" onchange="filterEnrollments()" checked>
                                <label for="filterPending" class="text-sm text-gray-700">Pending</label>
                            </div>
                            <div class="checkbox-item flex items-center gap-2">
                                <input type="checkbox" id="filterApproved" value="approved" onchange="filterEnrollments()" checked>
                                <label for="filterApproved" class="text-sm text-gray-700">Approved</label>
                            </div>
                            <div class="checkbox-item flex items-center gap-2">
                                <input type="checkbox" id="filterNeedsDocs" value="needs_docs" onchange="filterEnrollments()" checked>
                                <label for="filterNeedsDocs" class="text-sm text-gray-700">Needs Documents</label>
                            </div>
                            <div class="checkbox-item flex items-center gap-2">
                                <input type="checkbox" id="filterRejected" value="rejected" onchange="filterEnrollments()" checked>
                                <label for="filterRejected" class="text-sm text-gray-700">Rejected</label>
                            </div>
                        </div>
                    </div>

                    <div class="filter-section bg-gray-50 p-4 rounded mb-4">
                        <h4 class="text-sm font-semibold text-[#0a2d63] mb-2">Results Per Page</h4>
                        <div class="custom-per-page flex items-center gap-2 mt-2">
                            <select id="enrollmentPerPage" class="border border-gray-300 rounded px-2 py-1 text-sm" onchange="changeEnrollmentPerPage()">
                                <option value="10">10</option>
                                <option value="25">25</option>
                                <option value="50">50</option>
                                <option value="75">75</option>
                                <option value="100">100</option>
                                <option value="custom">Custom</option>
                            </select>
                            <div id="enrollmentCustomPerPage" class="hidden flex items-center gap-2">
                                <input type="number" id="enrollmentCustomNumber" min="1" max="500" placeholder="Number" class="border border-gray-300 rounded px-2 py-1 w-20 text-sm">
                                <button onclick="applyEnrollmentCustomPerPage()" class="bg-[#0a2d63] text-white px-3 py-1 rounded text-sm">Apply</button>
                            </div>
                        </div>
                    </div>

                    <div id="enrollmentSearchResults" class="search-results max-h-96 overflow-y-auto border border-gray-200 rounded">
                        <div class="text-center p-10 text-gray-500">Loading enrollments...</div>
                    </div>

                    <div id="enrollmentSearchPagination" class="pagination-controls hidden mt-4 p-4 bg-gray-50 rounded flex flex-col md:flex-row items-center gap-4">
                        <div class="pagination-info text-sm text-gray-600" id="enrollmentSearchInfo"></div>
                        <div class="pagination-buttons flex gap-1" id="enrollmentSearchButtons"></div>
                    </div>
                </div>
                <div class="modal-footer p-5 border-t border-gray-200 bg-gray-50 rounded-b-lg text-right">
                    <button class="bg-gray-600 text-white px-5 py-2 rounded font-medium hover:bg-gray-700 transition" onclick="closeEnrollmentSearchModal()">Close</button>
                </div>
            </div>
        </div>

        <!-- Document View Modal -->
        <div id="documentModal" class="modal-overlay fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-[1000]">
            <div class="modal-container bg-white rounded-lg w-[90%] max-w-4xl max-h-[90vh] overflow-y-auto shadow-xl">
                <div class="modal-header p-5 border-b border-gray-200 bg-gray-50 rounded-t-lg flex justify-between items-center">
                    <h3 class="text-xl font-semibold text-[#0a2d63]">Student Documents</h3>
                    <button class="modal-close text-2xl text-gray-600 hover:text-gray-800 w-8 h-8 flex items-center justify-center rounded hover:bg-gray-200 transition" onclick="closeDocumentModal()">×</button>
                </div>
                <div class="modal-body p-6">
                    <div id="documentList" class="min-h-[200px]">
                        <div class="text-center p-10 text-gray-500">Loading documents...</div>
                    </div>
                </div>
                <div class="modal-footer p-5 border-t border-gray-200 bg-gray-50 rounded-b-lg text-right">
                    <button class="bg-gray-600 text-white px-5 py-2 rounded font-medium hover:bg-gray-700 transition" onclick="closeDocumentModal()">Close</button>
                </div>
            </div>
        </div>

        <!-- Delete User Modal -->
        <div id="deleteUserModal" class="modal-overlay fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-[1000]">
            <div class="modal-container bg-white rounded-lg w-[90%] max-w-lg max-h-[90vh] overflow-y-auto shadow-xl">
                <div class="modal-header p-5 border-b border-gray-200 bg-gray-50 rounded-t-lg flex justify-between items-center">
                    <h3 class="text-xl font-semibold text-[#0a2d63]">Delete Users</h3>
                    <button class="modal-close text-2xl text-gray-600 hover:text-gray-800 w-8 h-8 flex items-center justify-center rounded hover:bg-gray-200 transition" onclick="closeDeleteUserModal()">×</button>
                </div>
                <div class="modal-body p-6">
                    <p class="mb-5 text-gray-600">Select users to delete. This action cannot be undone.</p>
                    <div class="form-group mb-4">
                        <input type="text" id="deleteSearchInput" placeholder="Search users..." class="w-full p-2 border border-gray-300 rounded" onkeyup="loadDeleteUserList()">
                    </div>
                    <div id="deleteUserList" class="max-h-72 overflow-y-auto border border-gray-200 rounded">
                        <div class="text-center p-10 text-gray-500">Loading users...</div>
                    </div>
                    <div class="mt-4 text-sm text-gray-600">
                        <span id="selectedCount">0</span> user(s) selected
                    </div>
                </div>
                <div class="modal-footer p-5 border-t border-gray-200 bg-gray-50 rounded-b-lg text-right">
                    <button class="bg-gray-600 text-white px-5 py-2 rounded font-medium hover:bg-gray-700 transition mr-2" onclick="closeDeleteUserModal()">Cancel</button>
                    <button class="bg-red-600 text-white px-5 py-2 rounded font-medium hover:bg-red-700 transition" onclick="confirmDeleteUsers()">Delete Selected</button>
                </div>
            </div>
        </div>

        <!-- Student Select Modal -->
        <div id="studentSelectModal" class="modal-overlay fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-[1000]">
            <div class="modal-container bg-white rounded-lg w-[90%] max-w-3xl max-h-[90vh] overflow-y-auto shadow-xl">
                <div class="modal-header p-5 border-b border-gray-200 bg-gray-50 rounded-t-lg flex justify-between items-center">
                    <h3 class="text-xl font-semibold text-[#0a2d63]">Select Student</h3>
                    <button class="modal-close text-2xl text-gray-600 hover:text-gray-800 w-8 h-8 flex items-center justify-center rounded hover:bg-gray-200 transition" onclick="closeStudentSelectModal()">×</button>
                </div>
                <div class="modal-body p-6">
                    <div class="form-group mb-4">
                        <label class="block mb-2 font-medium text-gray-700">Search by name, email, or grade</label>
                        <input type="text" id="studentSearchInput" placeholder="Type to search..." class="w-full p-2 border border-gray-300 rounded" onkeyup="filterStudentsForSelect()">
                    </div>

                    <div class="filter-section bg-gray-50 p-4 rounded mb-4">
                        <h4 class="text-sm font-semibold text-[#0a2d63] mb-2">Filter by Grade Level</h4>
                        <select id="studentFilterGrade" class="w-full p-2 border border-gray-300 rounded" onchange="filterStudentsForSelect()">
                            <option value="">All Grades</option>
                            <option value="Grade 7">Grade 7</option>
                            <option value="Grade 8">Grade 8</option>
                            <option value="Grade 9">Grade 9</option>
                            <option value="Grade 10">Grade 10</option>
                            <option value="Grade 11">Grade 11</option>
                            <option value="Grade 12">Grade 12</option>
                        </select>
                    </div>

                    <div id="studentSelectResults" class="search-results max-h-72 overflow-y-auto border border-gray-200 rounded">
                        <div class="text-center p-10 text-gray-500">Loading students...</div>
                    </div>
                </div>
                <div class="modal-footer p-5 border-t border-gray-200 bg-gray-50 rounded-b-lg text-right">
                    <button class="bg-gray-600 text-white px-5 py-2 rounded font-medium hover:bg-gray-700 transition" onclick="closeStudentSelectModal()">Cancel</button>
                </div>
            </div>
        </div>

        <!-- Batch Promote Modal -->
        <div id="batchPromoteModal" class="modal-overlay fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-[1000]">
            <div class="modal-container bg-white rounded-lg w-[90%] max-w-md shadow-xl">
                <div class="modal-header p-5 border-b border-gray-200 bg-gray-50 rounded-t-lg flex justify-between items-center">
                    <h3 class="text-xl font-semibold text-[#0a2d63]">Batch Promote Students</h3>
                    <button class="modal-close text-2xl text-gray-600 hover:text-gray-800 w-8 h-8 flex items-center justify-center rounded hover:bg-gray-200 transition" onclick="closeBatchPromoteModal()">×</button>
                </div>
                <div class="modal-body p-6">
                    <p class="mb-4 text-gray-600">Select a grade and (optional) section to promote all students.</p>
                    <select id="batchPromoteGrade" class="w-full p-2 border border-gray-300 rounded mb-4" onchange="updateBatchSections()">
                        <option value="">Select Grade</option>
                    </select>
                    <select id="batchPromoteSection" class="w-full p-2 border border-gray-300 rounded mb-4">
                        <option value="">All Sections</option>
                    </select>
                </div>
                <div class="modal-footer p-5 border-t border-gray-200 bg-gray-50 rounded-b-lg text-right">
                    <button class="bg-gray-600 text-white px-5 py-2 rounded font-medium hover:bg-gray-700 transition mr-2" onclick="closeBatchPromoteModal()">Cancel</button>
                    <button class="bg-blue-600 text-white px-5 py-2 rounded font-medium hover:bg-blue-700 transition" onclick="batchPromote()">Promote</button>
                </div>
            </div>
        </div>

    </div>
</body>
</html>