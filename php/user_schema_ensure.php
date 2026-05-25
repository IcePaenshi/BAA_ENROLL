<?php

/**
 * Ensures users table supports NULL grade/section for staff and student profile columns.
 * Safe to call once per request (internally deduped).
 */
function baa_user_schema_ensure(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $hasColumn = function (string $col) use ($pdo): bool {
        $q = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $q->execute(['users', $col]);

        return (int) $q->fetchColumn() > 0;
    };

    try {
        $pdo->exec('ALTER TABLE users MODIFY grade_level VARCHAR(20) NULL');
    } catch (PDOException $e) {
    }
    try {
        $pdo->exec('ALTER TABLE users MODIFY section VARCHAR(50) NULL');
    } catch (PDOException $e) {
    }

    try {
        $pdo->exec('ALTER TABLE users MODIFY age TINYINT UNSIGNED NULL AFTER lrn');
    } catch (PDOException $e) {
    }
    if (!$hasColumn('age')) {
        try {
            $pdo->exec('ALTER TABLE users ADD COLUMN age TINYINT UNSIGNED NULL AFTER lrn');
        } catch (PDOException $e) {
            error_log('baa_user_schema_ensure age: ' . $e->getMessage());
        }
    }

    try {
        $after = $hasColumn('age') ? 'age' : 'lrn';
        $pdo->exec("ALTER TABLE users MODIFY gender ENUM('Male','Female') NULL AFTER `$after`");
    } catch (PDOException $e) {
    }
    if (!$hasColumn('gender')) {
        $after = $hasColumn('age') ? 'age' : 'lrn';
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN gender ENUM('Male','Female') NULL AFTER `$after`");
        } catch (PDOException $e) {
            error_log('baa_user_schema_ensure gender: ' . $e->getMessage());
        }
    }

    try {
        $after = $hasColumn('gender') ? 'gender' : ($hasColumn('age') ? 'age' : 'lrn');
        $pdo->exec("ALTER TABLE users MODIFY birthdate DATE NULL AFTER `$after`");
    } catch (PDOException $e) {
    }
    if (!$hasColumn('birthdate')) {
        $after = $hasColumn('gender') ? 'gender' : ($hasColumn('age') ? 'age' : 'lrn');
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN birthdate DATE NULL AFTER `$after`");
        } catch (PDOException $e) {
            error_log('baa_user_schema_ensure birthdate: ' . $e->getMessage());
        }
    }

    try {
        $after = $hasColumn('birthdate') ? 'birthdate' : 'lrn';
        $pdo->exec("ALTER TABLE users MODIFY phone VARCHAR(25) NULL AFTER `$after`");
    } catch (PDOException $e) {
    }
    if (!$hasColumn('phone')) {
        $after = $hasColumn('birthdate') ? 'birthdate' : 'lrn';
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN phone VARCHAR(25) NULL AFTER `$after`");
        } catch (PDOException $e) {
            error_log('baa_user_schema_ensure phone: ' . $e->getMessage());
        }
    }

    try {
        $after = $hasColumn('phone') ? 'phone' : 'lrn';
        $pdo->exec("ALTER TABLE users MODIFY strand VARCHAR(20) NULL AFTER `$after`");
    } catch (PDOException $e) {
    }
    if (!$hasColumn('strand')) {
        $after = $hasColumn('phone') ? 'phone' : 'lrn';
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN strand VARCHAR(20) NULL AFTER `$after`");
        } catch (PDOException $e) {
            error_log('baa_user_schema_ensure strand: ' . $e->getMessage());
        }
    }

    try {
        $after = $hasColumn('strand') ? 'strand' : 'lrn';
        $pdo->exec("ALTER TABLE users MODIFY teacher_grade_level VARCHAR(20) NULL AFTER `$after`");
    } catch (PDOException $e) {
    }
    if (!$hasColumn('teacher_grade_level')) {
        $after = $hasColumn('strand') ? 'strand' : 'lrn';
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN teacher_grade_level VARCHAR(20) NULL AFTER `$after`");
        } catch (PDOException $e) {
            error_log('baa_user_schema_ensure teacher_grade_level: ' . $e->getMessage());
        }
    }

    try {
        $after = $hasColumn('teacher_grade_level') ? 'teacher_grade_level' : ($hasColumn('strand') ? 'strand' : 'lrn');
        $pdo->exec("ALTER TABLE users MODIFY teacher_section VARCHAR(50) NULL AFTER `$after`");
    } catch (PDOException $e) {
    }
    if (!$hasColumn('teacher_section')) {
        $after = $hasColumn('teacher_grade_level') ? 'teacher_grade_level' : ($hasColumn('strand') ? 'strand' : 'lrn');
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN teacher_section VARCHAR(50) NULL AFTER `$after`");
        } catch (PDOException $e) {
            error_log('baa_user_schema_ensure teacher_section: ' . $e->getMessage());
        }
    }

    try {
        $after = $hasColumn('teacher_section') ? 'teacher_section' : 'lrn';
        $pdo->exec("ALTER TABLE users MODIFY payment_plan_months INT DEFAULT 4 AFTER `$after`");
    } catch (PDOException $e) {
    }
    if (!$hasColumn('payment_plan_months')) {
        $after = $hasColumn('teacher_section') ? 'teacher_section' : 'lrn';
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN payment_plan_months INT DEFAULT 4 AFTER `$after`");
        } catch (PDOException $e) {
            error_log('baa_user_schema_ensure payment_plan_months: ' . $e->getMessage());
        }
    }

    try {
        $after = $hasColumn('payment_plan_months') ? 'payment_plan_months' : 'lrn';
        $pdo->exec("ALTER TABLE users MODIFY payment_start_date DATE NULL AFTER `$after`");
    } catch (PDOException $e) {
    }
    if (!$hasColumn('payment_start_date')) {
        $after = $hasColumn('payment_plan_months') ? 'payment_plan_months' : 'lrn';
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN payment_start_date DATE NULL AFTER `$after`");
        } catch (PDOException $e) {
            error_log('baa_user_schema_ensure payment_start_date: ' . $e->getMessage());
        }
    }

    // Ensure discounts tables exist
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS discounts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                amount DECIMAL(10,2) NOT NULL DEFAULT 0,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )
        ");
        
        $stmt = $pdo->query("SELECT COUNT(*) FROM discounts");
        if ($stmt->fetchColumn() == 0) {
            $pdo->exec("INSERT INTO discounts (name, amount) VALUES ('Scholarship', 0), ('Sibling Discount', 0), ('DepEd Voucher', 0)");
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS student_discounts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                student_id INT NOT NULL,
                discount_id INT NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                applied_date DATE NOT NULL,
                INDEX idx_student_id (student_id)
            )
        ");
    } catch (PDOException $e) {
        error_log('baa_user_schema_ensure discounts tables: ' . $e->getMessage());
    }

    // Ensure trimester_grades table exists
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS trimester_grades (
                id INT AUTO_INCREMENT PRIMARY KEY,
                student_id INT NOT NULL,
                subject_id INT NOT NULL,
                semester TINYINT NOT NULL COMMENT '1, 2, or 3',
                quiz_score DECIMAL(5,2) NULL,
                quiz_max INT NULL,
                essay_score DECIMAL(5,2) NULL,
                essay_max INT NULL,
                recitation_score DECIMAL(5,2) NULL,
                recitation_max INT NULL,
                periodic_test_score DECIMAL(5,2) NULL,
                periodic_test_max INT NULL,
                attendance_score DECIMAL(5,2) NULL,
                calculated_grade DECIMAL(5,2) NULL,
                is_submitted TINYINT(1) NOT NULL DEFAULT 0,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY student_subject_sem (student_id, subject_id, semester),
                INDEX idx_subject_semester (subject_id, semester)
            )
        ");
        
        // Add max point columns if they don't exist
        $stmt = $pdo->query("SHOW COLUMNS FROM trimester_grades");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $hasQuizMax = in_array('quiz_max', $columns);
        if (!$hasQuizMax) {
            $pdo->exec("ALTER TABLE trimester_grades ADD COLUMN quiz_max INT NULL AFTER quiz_score");
            $pdo->exec("ALTER TABLE trimester_grades ADD COLUMN essay_max INT NULL AFTER essay_score");
            $pdo->exec("ALTER TABLE trimester_grades ADD COLUMN recitation_max INT NULL AFTER recitation_score");
            $pdo->exec("ALTER TABLE trimester_grades ADD COLUMN periodic_test_max INT NULL AFTER periodic_test_score");
        }
        
        
    } catch (PDOException $e) {
        error_log('baa_user_schema_ensure trimester_grades table: ' . $e->getMessage());
    }

    // Ensure payment_mode exists in payments, student_downpayments, and enrollment_downpayments
    try {
        if (!$hasColumn('payment_mode')) {
            $pdo->exec("ALTER TABLE payments ADD COLUMN payment_mode VARCHAR(50) DEFAULT 'cash'");
        }
    } catch (PDOException $e) {
    }

    $hasEnrCol = function(string $col) use ($pdo): bool {
        $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'enrollment_downpayments' AND COLUMN_NAME = ?");
        $q->execute([$col]);
        return (int) $q->fetchColumn() > 0;
    };
    try {
        if (!$hasEnrCol('payment_mode')) {
            $pdo->exec("ALTER TABLE enrollment_downpayments ADD COLUMN payment_mode VARCHAR(50) DEFAULT 'cash'");
        }
    } catch (PDOException $e) {
    }

    $hasStudCol = function(string $col) use ($pdo): bool {
        $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student_downpayments' AND COLUMN_NAME = ?");
        $q->execute([$col]);
        return (int) $q->fetchColumn() > 0;
    };
    try {
        if (!$hasStudCol('payment_mode')) {
            $pdo->exec("ALTER TABLE student_downpayments ADD COLUMN payment_mode VARCHAR(50) DEFAULT 'cash'");
        }
    } catch (PDOException $e) {
    }

    // Profile Picture support in users table
    if (!$hasColumn('profile_picture')) {
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN profile_picture VARCHAR(255) NULL");
        } catch (PDOException $e) {
            error_log('baa_user_schema_ensure profile_picture: ' . $e->getMessage());
        }
    }
    if (!$hasColumn('profile_picture_status')) {
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN profile_picture_status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending'");
        } catch (PDOException $e) {
            error_log('baa_user_schema_ensure profile_picture_status: ' . $e->getMessage());
        }
    }

    // User status flag (active = 1, inactive = 0)
    if (!$hasColumn('status')) {
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN status TINYINT DEFAULT 1");
        } catch (PDOException $e) {
            error_log('baa_user_schema_ensure status: ' . $e->getMessage());
        }
    }

    // Unified Announcements & Events table
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS announcements (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                content TEXT NOT NULL,
                event_date DATE NULL,
                location VARCHAR(255) NULL,
                responsible_dept VARCHAR(255) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )
        ");

        // Helper to check column existence in announcements
        $hasAnnColumn = function (string $col) use ($pdo): bool {
            $q = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
            );
            $q->execute(['announcements', $col]);
            return (int) $q->fetchColumn() > 0;
        };

        // Table exists, ensure columns exist
        if (!$hasAnnColumn('created_at')) {
            $pdo->exec("ALTER TABLE announcements ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
        }
        if (!$hasAnnColumn('updated_at')) {
            $pdo->exec("ALTER TABLE announcements ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
        }
        if (!$hasAnnColumn('location')) {
            $pdo->exec("ALTER TABLE announcements ADD COLUMN location VARCHAR(255) NULL");
        }
        if (!$hasAnnColumn('responsible_dept')) {
            $pdo->exec("ALTER TABLE announcements ADD COLUMN responsible_dept VARCHAR(255) NULL");
        }
        if (!$hasAnnColumn('event_date')) {
            $pdo->exec("ALTER TABLE announcements ADD COLUMN event_date DATE NULL");
        }
    } catch (PDOException $e) {
        error_log('baa_user_schema_ensure announcements table: ' . $e->getMessage());
    }

    // Ensure subjects table has correct columns, recreate if it has the old layout
    $hasSubjectName = false;
    try {
        $q = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $q->execute(['subjects', 'subject_name']);
        $hasSubjectName = ((int) $q->fetchColumn() > 0);
    } catch (PDOException $e) {
    }

    if (!$hasSubjectName) {
        try {
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
            $pdo->exec("DROP TABLE IF EXISTS student_subject");
            $pdo->exec("DROP TABLE IF EXISTS teacher_subjects");
            $pdo->exec("DROP TABLE IF EXISTS subjects");
            
            $pdo->exec("
                CREATE TABLE subjects (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    subject_name VARCHAR(100) NOT NULL,
                    subject_code VARCHAR(50) NULL,
                    grade_level VARCHAR(20) NOT NULL,
                    section VARCHAR(50) NULL,
                    day_of_week VARCHAR(20) NULL,
                    start_time TIME NULL,
                    end_time TIME NULL,
                    semester VARCHAR(20) NULL
                )
            ");
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
        } catch (PDOException $e) {
            error_log('baa_user_schema_ensure recreate subjects: ' . $e->getMessage());
        }
    }

    // Ensure teacher_subjects table exists
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS teacher_subjects (
                teacher_id INT NOT NULL,
                subject_id INT NOT NULL,
                PRIMARY KEY (teacher_id, subject_id),
                FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
            )
        ");
    } catch (PDOException $e) {
        error_log('baa_user_schema_ensure teacher_subjects: ' . $e->getMessage());
    }

    // Populate subjects if empty
    try {
        $count = (int)$pdo->query("SELECT COUNT(*) FROM subjects")->fetchColumn();
        if ($count === 0) {
            // Insert sample subjects for Grade 10 - Self-Control
            $stmt = $pdo->prepare("INSERT INTO subjects (subject_name, subject_code, grade_level, section) VALUES (?, ?, ?, ?)");
            $stmt->execute(['English', 'ENG10', 'Grade 10', 'Self-Control']);
            $engId = $pdo->lastInsertId();
            $stmt->execute(['Mathematics', 'MATH10', 'Grade 10', 'Self-Control']);
            $mathId = $pdo->lastInsertId();
            $stmt->execute(['Science', 'SCI10', 'Grade 10', 'Self-Control']);
            $sciId = $pdo->lastInsertId();

            // Insert sample subjects for other grades if needed
            $stmt->execute(['English', 'ENG7', 'Grade 7', 'Love']);
            $stmt->execute(['English', 'ENG8', 'Grade 8', 'Patience']);
            $stmt->execute(['English', 'ENG9', 'Grade 9', 'Goodness']);
            $stmt->execute(['English', 'ENG11', 'Grade 11', 'Self-Control']);
            $stmt->execute(['English', 'ENG12', 'Grade 12', 'Humility']);

            // Get teacher user ID
            $teacherUid = $pdo->query("SELECT id FROM users WHERE username = 'teacher' LIMIT 1")->fetchColumn();
            if ($teacherUid) {
                // Assign English, Mathematics, and Science to teacher
                $tsStmt = $pdo->prepare("INSERT IGNORE INTO teacher_subjects (teacher_id, subject_id) VALUES (?, ?)");
                $tsStmt->execute([$teacherUid, $engId]);
                $tsStmt->execute([$teacherUid, $mathId]);
                $tsStmt->execute([$teacherUid, $sciId]);
            }
        }
    } catch (PDOException $e) {
        error_log('baa_user_schema_ensure populate subjects/teacher_subjects: ' . $e->getMessage());
    }

    // Ensure notifications table exists
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                type VARCHAR(50) NOT NULL,
                message TEXT NOT NULL,
                link VARCHAR(255) NULL,
                status ENUM('unread', 'read') DEFAULT 'unread',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_status (user_id, status)
            )
        ");
    } catch (PDOException $e) {
        error_log('baa_user_schema_ensure notifications table: ' . $e->getMessage());
    }
}
