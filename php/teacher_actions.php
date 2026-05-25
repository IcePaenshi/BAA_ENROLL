<?php
session_start();
header('Content-Type: application/json');

try {
    require_once __DIR__ . '/db.php';
} catch (Exception $e) {
    error_log('Teacher actions DB connection failed: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database connection error']);
    exit;
}

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$userId = $_SESSION['user_id'];

function sendJson(array $data) {
    echo json_encode($data);
    exit;
}

function normalizeGradeLevel(string $gradeLevel): string {
    return trim(str_ireplace('Grade ', '', $gradeLevel));
}

function getTeacherStudents(PDO $pdo, int $teacherId): array {
    $stmt = $pdo->prepare("SELECT DISTINCT s.grade_level, s.section FROM subjects s JOIN teacher_subjects ts ON s.id = ts.subject_id WHERE ts.teacher_id = ?");
    $stmt->execute([$teacherId]);
    $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($sections)) {
        return [];
    }

    $conditions = [];
    $params = [];
    foreach ($sections as $sec) {
        $conditions[] = "(section = ? AND (grade_level = ? OR REPLACE(grade_level, 'Grade ', '') = REPLACE(?, 'Grade ', '')))";
        $params[] = $sec['section'];
        $params[] = $sec['grade_level'];
        $params[] = $sec['grade_level'];
    }

    $sql = "SELECT id, " . baa_full_name_sql() . " AS full_name, grade_level, section FROM users WHERE role = 'student' AND (" . implode(' OR ', $conditions) . ") ORDER BY grade_level, section, full_name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $method === 'POST' ? ($_POST['action'] ?? '') : ($_GET['action'] ?? '');

    if ($method === 'POST') {
        if ($action === 'get_grade_students') {
            $subject_id = isset($_POST['subject_id']) ? (int) $_POST['subject_id'] : 0;
            $grade_level = trim($_POST['grade_level'] ?? '');
            $section = trim($_POST['section'] ?? '');

            if (!$subject_id || $grade_level === '' || $section === '') {
                sendJson(['success' => false, 'message' => 'Missing required parameters']);
            }

            $check = $pdo->prepare("SELECT 1 FROM teacher_subjects WHERE teacher_id = ? AND subject_id = ?");
            $check->execute([$userId, $subject_id]);
            if (!$check->fetchColumn()) {
                sendJson(['success' => false, 'message' => 'Unauthorized']);
            }

            $studentStmt = $pdo->prepare("SELECT id, " . baa_full_name_sql() . " AS full_name FROM users WHERE role = 'student' AND TRIM(section) = TRIM(?) AND (TRIM(REPLACE(REPLACE(LOWER(grade_level), 'grade ', ''), 'grade', '')) = TRIM(REPLACE(REPLACE(LOWER(?), 'grade ', ''), 'grade', ''))) ORDER BY last_name, first_name");
            $studentStmt->execute([$section, $grade_level]);
            $studentList = $studentStmt->fetchAll(PDO::FETCH_ASSOC);

            $subj = $pdo->prepare("SELECT subject_name FROM subjects WHERE id = ?");
            $subj->execute([$subject_id]);
            $subjectName = $subj->fetchColumn() ?: 'Subject';

            $grades = [];
            $currentSemester = 1;
            
            if (!empty($studentList)) {
                $ids = array_column($studentList, 'id');
                $in = str_repeat('?,', count($ids) - 1) . '?';
                $gradeStmt = $pdo->prepare("SELECT student_id, semester, quiz_score, quiz_max, essay_score, essay_max, recitation_score, recitation_max, periodic_test_score, periodic_test_max, attendance_score, calculated_grade, is_submitted FROM trimester_grades WHERE subject_id = ? AND student_id IN ($in)");
                $gradeStmt->execute(array_merge([$subject_id], $ids));
                
                $maxSubmitted = 0;
                while ($row = $gradeStmt->fetch(PDO::FETCH_ASSOC)) {
                    $grades[$row['student_id']][$row['semester']] = $row;
                    if ($row['is_submitted']) {
                        if ($row['semester'] > $maxSubmitted) {
                            $maxSubmitted = $row['semester'];
                        }
                    }
                }
                
                if ($maxSubmitted >= 1 && $maxSubmitted < 3) {
                    $currentSemester = $maxSubmitted + 1;
                } else if ($maxSubmitted == 3) {
                    $currentSemester = 3;
                }
            }

            $result = [];
            foreach ($studentList as $s) {
                $result[] = [
                    'id' => $s['id'],
                    'full_name' => $s['full_name'],
                    'sem1' => $grades[$s['id']][1] ?? null,
                    'sem2' => $grades[$s['id']][2] ?? null,
                    'sem3' => $grades[$s['id']][3] ?? null,
                ];
            }

            sendJson([
                'success' => true,
                'students' => $result,
                'subject_name' => $subjectName,
                'current_semester' => $currentSemester,
                'debug' => [
                    'received_section' => $section,
                    'received_grade' => $grade_level,
                    'student_count' => count($result)
                ]
            ]);
        }

        if ($action === 'save_grades') {
            $data = json_decode($_POST['data'] ?? '', true);
            if (!is_array($data)) {
                sendJson(['success' => false, 'message' => 'Invalid grade data']);
            }

            $subject_id = isset($data['subject_id']) ? (int) $data['subject_id'] : 0;
            $semester = isset($data['semester']) ? (int) $data['semester'] : 1;
            $grades = $data['grades'] ?? [];
            if (!$subject_id || !is_array($grades)) {
                sendJson(['success' => false, 'message' => 'Missing grades payload']);
            }

            $check = $pdo->prepare("SELECT 1 FROM teacher_subjects WHERE teacher_id = ? AND subject_id = ?");
            $check->execute([$userId, $subject_id]);
            if (!$check->fetchColumn()) {
                sendJson(['success' => false, 'message' => 'Unauthorized']);
            }

            $pdo->beginTransaction();
            try {
                foreach ($grades as $g) {
                    $student_id = $g['student_id'] ?? null;
                    if (!$student_id) continue;
                    
                    $quiz = isset($g['quiz']) && $g['quiz'] !== '' ? floatval($g['quiz']) : null;
                    $essay = isset($g['essay']) && $g['essay'] !== '' ? floatval($g['essay']) : null;
                    $recitation = isset($g['recitation']) && $g['recitation'] !== '' ? floatval($g['recitation']) : null;
                    $periodic = isset($g['periodic']) && $g['periodic'] !== '' ? floatval($g['periodic']) : null;
                    
                    $quiz_max = isset($data['max_points']['quiz']) ? (int)$data['max_points']['quiz'] : 100;
                    $essay_max = isset($data['max_points']['essay']) ? (int)$data['max_points']['essay'] : 100;
                    $recitation_max = isset($data['max_points']['recitation']) ? (int)$data['max_points']['recitation'] : 100;
                    $periodic_max = isset($data['max_points']['periodic']) ? (int)$data['max_points']['periodic'] : 100;
                    
                    $calcGrade = null;
                    $totalWeight = 0;
                    $weightedSum = 0;
                    
                    // Quiz + Essay + Recitation are GROUPED → together they contribute 30%
                    $groupComponents = [];
                    if ($quiz !== null && $quiz_max > 0)       { $groupComponents[] = ($quiz / $quiz_max) * 100; }
                    if ($essay !== null && $essay_max > 0)      { $groupComponents[] = ($essay / $essay_max) * 100; }
                    if ($recitation !== null && $recitation_max > 0) { $groupComponents[] = ($recitation / $recitation_max) * 100; }
                    if (!empty($groupComponents)) {
                        $groupAvg = array_sum($groupComponents) / count($groupComponents);
                        $weightedSum += $groupAvg * 0.30;
                        $totalWeight += 0.30;
                    }
                    
                    // Periodic Test → 40%
                    if ($periodic !== null && $periodic_max > 0) {
                        $weightedSum += ($periodic / $periodic_max) * 100 * 0.40;
                        $totalWeight += 0.40;
                    }
                    
                    if ($totalWeight > 0) {
                        $calcGrade = round($weightedSum / $totalWeight, 2);
                    }

                    $stmt = $pdo->prepare("
                        INSERT INTO trimester_grades 
                        (student_id, subject_id, semester, quiz_score, quiz_max, essay_score, essay_max, recitation_score, recitation_max, periodic_test_score, periodic_test_max, calculated_grade) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) 
                        ON DUPLICATE KEY UPDATE 
                        quiz_score = VALUES(quiz_score), 
                        quiz_max = VALUES(quiz_max),
                        essay_score = VALUES(essay_score), 
                        essay_max = VALUES(essay_max),
                        recitation_score = VALUES(recitation_score), 
                        recitation_max = VALUES(recitation_max),
                        periodic_test_score = VALUES(periodic_test_score),
                        periodic_test_max = VALUES(periodic_test_max),
                        calculated_grade = VALUES(calculated_grade)
                    ");
                    $stmt->execute([$student_id, $subject_id, $semester, $quiz, $quiz_max, $essay, $essay_max, $recitation, $recitation_max, $periodic, $periodic_max, $calcGrade]);
                }
                $pdo->commit();
                sendJson(['success' => true]);
            } catch (Exception $e) {
                $pdo->rollBack();
                sendJson(['success' => false, 'message' => $e->getMessage()]);
            }
        }
        
        if ($action === 'submit_grades_to_registrar') {
            $subject_id = isset($_POST['subject_id']) ? (int) $_POST['subject_id'] : 0;
            $semester = isset($_POST['semester']) ? (int) $_POST['semester'] : 1;
            
            if (!$subject_id) {
                sendJson(['success' => false, 'message' => 'Missing subject_id']);
            }
            
            $check = $pdo->prepare("SELECT 1 FROM teacher_subjects WHERE teacher_id = ? AND subject_id = ?");
            $check->execute([$userId, $subject_id]);
            if (!$check->fetchColumn()) {
                sendJson(['success' => false, 'message' => 'Unauthorized']);
            }
            
            try {
                $stmt = $pdo->prepare("UPDATE trimester_grades SET is_submitted = 1 WHERE subject_id = ? AND semester = ?");
                $stmt->execute([$subject_id, $semester]);

                // Add notification for Admin and Registrar
                try {
                    $tNameStmt = $pdo->prepare("SELECT " . baa_full_name_sql() . " FROM users WHERE id = ?");
                    $tNameStmt->execute([$userId]);
                    $teacherName = $tNameStmt->fetchColumn() ?: 'A teacher';

                    $sNameStmt = $pdo->prepare("SELECT subject_name FROM subjects WHERE id = ?");
                    $sNameStmt->execute([$subject_id]);
                    $subjectName = $sNameStmt->fetchColumn() ?: 'a subject';

                    $staffStmt = $pdo->prepare("SELECT id FROM users WHERE role IN ('admin', 'registrar')");
                    $staffStmt->execute();
                    $staffIds = $staffStmt->fetchAll(PDO::FETCH_COLUMN);

                    $notifStmt = $pdo->prepare("INSERT INTO notifications (user_id, type, message, link) VALUES (?, 'grade', ?, 'grade-submissions')");
                    foreach ($staffIds as $staffId) {
                        $notifStmt->execute([$staffId, "$teacherName submitted grades for $subjectName (Sem $semester)"]);
                    }

                    // Also notify students
                    $studStmt = $pdo->prepare("SELECT DISTINCT student_id FROM trimester_grades WHERE subject_id = ? AND semester = ?");
                    $studStmt->execute([$subject_id, $semester]);
                    $studentIds = $studStmt->fetchAll(PDO::FETCH_COLUMN);

                    $notifStudStmt = $pdo->prepare("INSERT INTO notifications (user_id, type, message, link) VALUES (?, 'grade', ?, 'grades')");
                    foreach ($studentIds as $studentId) {
                        $notifStudStmt->execute([$studentId, "Your grades for $subjectName (Sem $semester) have been submitted to the Registrar."]);
                    }
                } catch (Exception $e) {
                    error_log("Notification error: " . $e->getMessage());
                }

                sendJson(['success' => true]);
            } catch (Exception $e) {
                sendJson(['success' => false, 'message' => $e->getMessage()]);
            }
        }

        if ($action === 'save_attendance') {
            $rawAttendance = $_POST['data'] ?? ($_POST['attendance_data'] ?? '');
            $data = json_decode($rawAttendance, true);
            if (!is_array($data)) {
                sendJson(['success' => false, 'message' => 'Invalid attendance payload']);
            }

            $teacherStudents = getTeacherStudents($pdo, $userId);
            $allowedStudentIds = array_column($teacherStudents, 'id');

            $pdo->beginTransaction();
            try {
                foreach ($data as $record) {
                    $date = $record['date'] ?? null;
                    $student_name = $record['student_name'] ?? null;
                    $student_id = isset($record['student_id']) ? (int) $record['student_id'] : 0;
                    $status = $record['status'] ?? null;
                    if (!$date || !$status) {
                        continue;
                    }

                    if ($student_id < 1) {
                        if (!$student_name) {
                            continue;
                        }
                        $student = $pdo->prepare("SELECT id FROM users WHERE " . baa_full_name_sql() . " = ? AND role = 'student'");
                        $student->execute([$student_name]);
                        $student_id = (int) $student->fetchColumn();
                    }
                    if ($student_id < 1 || !in_array($student_id, $allowedStudentIds, true)) {
                        continue;
                    }

                    $stmt = $pdo->prepare("INSERT INTO attendance (student_id, teacher_id, date, status, encoded_at) VALUES (?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE status = ?, encoded_at = NOW()");
                    $stmt->execute([$student_id, $userId, $date, $status, $status]);
                }
                $pdo->commit();
                sendJson(['success' => true]);
            } catch (Exception $e) {
                $pdo->rollBack();
                sendJson(['success' => false, 'message' => $e->getMessage()]);
            }
        }

        if ($action === 'get_attendance_by_date') {
            $date = $_POST['date'] ?? '';
            if ($date === '') {
                sendJson(['success' => false, 'message' => 'Missing date']);
            }

            try {
                $teacherStudents = getTeacherStudents($pdo, $userId);
                if (empty($teacherStudents)) {
                    sendJson(['success' => true, 'records' => []]);
                }

                $ids = array_column($teacherStudents, 'id');
                $in = str_repeat('?,', count($ids) - 1) . '?';
                $stmt = $pdo->prepare("SELECT " . baa_full_name_sql('u') . " AS full_name, a.status FROM attendance a JOIN users u ON a.student_id = u.id WHERE a.date = ? AND a.student_id IN ($in)");
                $stmt->execute(array_merge([$date], $ids));
                $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
                sendJson(['success' => true, 'records' => $records]);
            } catch (Exception $e) {
                sendJson(['success' => false, 'message' => $e->getMessage()]);
            }
        }

        sendJson(['success' => false, 'message' => 'Invalid action']);
    }

    if ($method === 'GET') {
        if ($action === 'get_attendance_dates') {
            $teacherStudents = getTeacherStudents($pdo, $userId);
            if (empty($teacherStudents)) {
                sendJson([]);
            }

            $ids = array_column($teacherStudents, 'id');
            $in = str_repeat('?,', count($ids) - 1) . '?';
            $stmt = $pdo->prepare("SELECT DISTINCT date FROM attendance WHERE student_id IN ($in) ORDER BY date");
            $stmt->execute($ids);
            sendJson($stmt->fetchAll(PDO::FETCH_COLUMN));
        }

        if ($action === 'get_student_grades') {
            $student_id = $_GET['student_id'] ?? '';
            if ($student_id === '') {
                sendJson([]);
            }

            $teacherStudents = getTeacherStudents($pdo, $userId);
            $ids = array_column($teacherStudents, 'id');
            if (!in_array($student_id, $ids, true)) {
                sendJson(['success' => false, 'message' => 'Unauthorized']);
            }

            $stmt = $pdo->prepare("SELECT s.subject_name, g.semester, g.calculated_grade FROM trimester_grades g JOIN subjects s ON g.subject_id = s.id WHERE g.student_id = ? ORDER BY s.subject_name, g.semester");
            $stmt->execute([$student_id]);
            $grades = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $result = [];
            foreach ($grades as $g) {
                $subj = $g['subject_name'];
                if (!isset($result[$subj])) $result[$subj] = [];
                $result[$subj][$g['semester']] = $g['calculated_grade'];
            }

            $final = [];
            foreach ($result as $subj => $semesters) {
                if (!empty($semesters)) {
                    $avg = array_sum($semesters) / count($semesters);
                    $final[] = ['subject_name' => $subj, 'grade' => round($avg, 2)];
                }
            }

            sendJson($final);
        }

        sendJson(['success' => false, 'message' => 'Invalid action']);
    }

    sendJson(['success' => false, 'message' => 'Invalid request method']);
} catch (Exception $e) {
    error_log('Teacher actions error: ' . $e->getMessage());
    sendJson(['success' => false, 'message' => 'Server error']);
}
