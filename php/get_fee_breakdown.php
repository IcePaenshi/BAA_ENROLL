<?php
/**
 * Tuition fee breakdown source of truth.
 *
 * - Defaults live in code (original values).
 * - Current values live in DB table `tuition_fees` (seeded from defaults on first run).
 */

require_once __DIR__ . '/db.php';

function baa_default_fee_breakdowns(): array
{
    return [
        'Grade 7' => ['tuition' => 21175.00, 'misc' => 14927.50, 'aircon' => 3000.00, 'hsa' => 0.00, 'books' => 0.00],
        'Grade 8' => ['tuition' => 21795.73, 'misc' => 14927.50, 'aircon' => 3000.00, 'hsa' => 0.00, 'books' => 0.00],
        'Grade 9' => ['tuition' => 23298.55, 'misc' => 14927.50, 'aircon' => 3000.00, 'hsa' => 0.00, 'books' => 0.00],
        'Grade 10' => ['tuition' => 25159.53, 'misc' => 16427.50, 'aircon' => 3000.00, 'hsa' => 0.00, 'books' => 0.00],
        'Grade 11' => ['tuition' => 27225.00, 'misc' => 14602.50, 'aircon' => 3000.00, 'hsa' => 0.00, 'books' => 0.00],
        'Grade 12' => ['tuition' => 27225.00, 'misc' => 16452.50, 'aircon' => 3000.00, 'hsa' => 0.00, 'books' => 0.00],
    ];
}

function baa_ensure_tuition_fees_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tuition_fees (
            grade_level VARCHAR(20) PRIMARY KEY,
            tuition DECIMAL(10,2) NOT NULL DEFAULT 0,
            misc DECIMAL(10,2) NOT NULL DEFAULT 0,
            aircon DECIMAL(10,2) NOT NULL DEFAULT 0,
            hsa DECIMAL(10,2) NOT NULL DEFAULT 0,
            books DECIMAL(10,2) NOT NULL DEFAULT 0,
            updated_by INT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");

    // Seed from defaults if empty / missing rows
    $defaults = baa_default_fee_breakdowns();
    foreach ($defaults as $grade => $b) {
        $stmt = $pdo->prepare("SELECT grade_level FROM tuition_fees WHERE grade_level = ? LIMIT 1");
        $stmt->execute([$grade]);
        if ($stmt->fetchColumn()) {
            continue;
        }
        $ins = $pdo->prepare("
            INSERT INTO tuition_fees (grade_level, tuition, misc, aircon, hsa, books, updated_by)
            VALUES (?, ?, ?, ?, ?, ?, NULL)
        ");
        $ins->execute([
            $grade,
            (float) $b['tuition'],
            (float) $b['misc'],
            (float) $b['aircon'],
            (float) $b['hsa'],
            (float) $b['books'],
        ]);
    }
}

function baa_get_all_fee_breakdowns(PDO $pdo): array
{
    baa_ensure_tuition_fees_table($pdo);
    $rows = $pdo->query("SELECT grade_level, tuition, misc, aircon, hsa, books FROM tuition_fees")->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $out[$r['grade_level']] = [
            'tuition' => (float) $r['tuition'],
            'misc' => (float) $r['misc'],
            'aircon' => (float) $r['aircon'],
            'hsa' => (float) $r['hsa'],
            'books' => (float) $r['books'],
        ];
    }
    return $out;
}

function baa_get_fee_breakdown(PDO $pdo, string $gradeLevel): ?array
{
    baa_ensure_tuition_fees_table($pdo);
    $stmt = $pdo->prepare("SELECT tuition, misc, aircon, hsa, books FROM tuition_fees WHERE grade_level = ? LIMIT 1");
    $stmt->execute([$gradeLevel]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    return [
        'tuition' => (float) $r['tuition'],
        'misc' => (float) $r['misc'],
        'aircon' => (float) $r['aircon'],
        'hsa' => (float) $r['hsa'],
        'books' => (float) $r['books'],
    ];
}

function baa_fee_total(array $breakdown): float
{
    $sum = 0.0;
    foreach (['tuition', 'misc', 'aircon', 'hsa', 'books'] as $k) {
        $sum += (float) ($breakdown[$k] ?? 0);
    }
    return round($sum, 2);
}