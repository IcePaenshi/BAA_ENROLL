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
}
