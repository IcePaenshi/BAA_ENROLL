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

    $sql = "SELECT id, CONCAT_WS(' ', first_name, middle_name, last_name, suffix) AS full_name, grade_level, section FROM users WHERE role = 'student' AND (" . implode(' OR ', $conditions) . ") ORDER BY grade_level, section, full_name";
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

            $studentStmt = $pdo->prepare("SELECT id, CONCAT_WS(' ', first_name, middle_name, last_name, suffix) AS full_name FROM users WHERE role = 'student' AND section = ? AND grade_level = ? ORDER BY full_name");
            $studentStmt->execute([$section, $grade_level]);
            $studentList = $studentStmt->fetchAll(PDO::FETCH_ASSOC);

            $subj = $pdo->prepare("SELECT subject_name FROM subjects WHERE id = ?");
            $subj->execute([$subject_id]);
            $subjectName = $subj->fetchColumn() ?: 'Subject';

            $grades = [];
            if (!empty($studentList)) {
                $ids = array_column($studentList, 'id');
                $in = str_repeat('?,', count($ids) - 1) . '?';
                $gradeStmt = $pdo->prepare("SELECT student_id, quarter, grade FROM grades WHERE subject_id = ? AND student_id IN ($in)");
                $gradeStmt->execute(array_merge([$subject_id], $ids));
                while ($row = $gradeStmt->fetch(PDO::FETCH_ASSOC)) {
                    $grades[$row['student_id']][$row['quarter']] = $row['grade'];
                }
            }

            $result = [];
            foreach ($studentList as $s) {
                $result[] = [
                    'id' => $s['id'],
                    'full_name' => $s['full_name'],
                    'q1' => $grades[$s['id']][1] ?? null,
                    'q2' => $grades[$s['id']][2] ?? null,
                    'q3' => $grades[$s['id']][3] ?? null,
                    'q4' => $grades[$s['id']][4] ?? null,
                ];
            }

            sendJson(['success' => true, 'students' => $result, 'subject_name' => $subjectName]);
        }

        if ($action === 'save_grades') {
            $data = json_decode($_POST['data'] ?? '', true);
            if (!is_array($data)) {
                sendJson(['success' => false, 'message' => 'Invalid grade data']);
            }

            $subject_id = isset($data['subject_id']) ? (int) $data['subject_id'] : 0;
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
                    if (!$student_id) {
                        continue;
                    }
                    for ($quarter = 1; $quarter <= 4; $quarter++) {
                        $field = 'q' . $quarter;
                        if (!isset($g[$field]) || $g[$field] === '' || $g[$field] === null) {
                            continue;
                        }
                        $gradeValue = floatval($g[$field]);
                        $stmt = $pdo->prepare("INSERT INTO grades (student_id, subject_id, quarter, grade, created_at) VALUES (?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE grade = ?");
                        $stmt->execute([$student_id, $subject_id, $quarter, $gradeValue, $gradeValue]);
                    }
                }
                $pdo->commit();
                sendJson(['success' => true]);
            } catch (Exception $e) {
                $pdo->rollBack();
                sendJson(['success' => false, 'message' => $e->getMessage()]);
            }
        }

        if ($action === 'save_attendance') {
            $data = json_decode($_POST['data'] ?? '', true);
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
                    $status = $record['status'] ?? null;
                    if (!$date || !$student_name || !$status) {
                        continue;
                    }

                    $student = $pdo->prepare("SELECT id FROM users WHERE CONCAT_WS(' ', first_name, middle_name, last_name, suffix) = ? AND role = 'student'");
                    $student->execute([$student_name]);
                    $student_id = $student->fetchColumn();
                    if (!$student_id || !in_array($student_id, $allowedStudentIds, true)) {
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
                $stmt = $pdo->prepare("SELECT CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name, u.suffix) AS full_name, a.status FROM attendance a JOIN users u ON a.student_id = u.id WHERE a.date = ? AND a.student_id IN ($in)");
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

            $stmt = $pdo->prepare("SELECT s.subject_name, g.quarter, g.grade FROM grades g JOIN subjects s ON g.subject_id = s.id WHERE g.student_id = ? ORDER BY s.subject_name, g.quarter");
            $stmt->execute([$student_id]);
            $grades = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $result = [];
            foreach ($grades as $g) {
                $subj = $g['subject_name'];
                if (!isset($result[$subj])) $result[$subj] = [];
                $result[$subj][$g['quarter']] = $g['grade'];
            }

            $final = [];
            foreach ($result as $subj => $quarters) {
                if (!empty($quarters)) {
                    $avg = array_sum($quarters) / count($quarters);
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
