<?php
require_once 'db.php';
try {
    $sql = file_get_contents('../backend/init.sql');
    if ($sql === false) {
        $sql = file_get_contents('backend/init.sql');
    }
    if ($sql === false) {
        throw new Exception("init.sql not found");
    }
    
    // Execute SQL
    $pdo->exec($sql);
    echo "✅ Successfully imported init.sql\n";
    
    // Now run update_db.php
    require_once 'update_db.php';
    echo "✅ Successfully ran update_db.php\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
