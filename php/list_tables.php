<?php
require_once 'db.php';
try {
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        echo "Table: $table\n";
        try {
            $cols = $pdo->query("DESCRIBE $table")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($cols as $col) {
                echo "  - {$col['Field']} ({$col['Type']})\n";
            }
        } catch (Exception $e) {
            echo "  Error: " . $e->getMessage() . "\n";
        }
        echo "\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
