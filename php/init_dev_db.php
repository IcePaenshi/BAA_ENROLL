<?php
define('SKIP_SCHEMA_ENSURE', true);
require_once 'db.php';
try {
    // 1. Drop existing tables if needed to avoid conflicts
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
    $tablesToDrop = ['student_discounts', 'discounts', 'trimester_grades', 'payables', 'attendance', 'users'];
    foreach ($tablesToDrop as $table) {
        $pdo->exec("DROP TABLE IF EXISTS $table");
    }
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
    echo "✅ Dropped tables\n";
    
    // 2. Run simple_setup.php logic (but clean up output)
    ob_start();
    include 'simple_setup.php';
    ob_end_clean();
    echo "✅ Ran simple_setup.php\n";
    
    // 3. Run update_db.php logic
    ob_start();
    include 'update_db.php';
    ob_end_clean();
    echo "✅ Ran update_db.php\n";
    
    // 4. Force run the schema check again now that users exists
    require_once 'user_schema_ensure.php';
    baa_user_schema_ensure($pdo);
    echo "✅ Ran baa_user_schema_ensure\n";
    
    echo "✅ Database fully reset and initialized!\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
