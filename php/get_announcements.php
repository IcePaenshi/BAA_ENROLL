<?php
session_start();
header('Content-Type: application/json');

try {
    require_once 'db.php';
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database connection error']);
    exit();
}

function ensureAnnouncementsSchema(PDO $pdo)
{
    try {
        // Check if table exists
        $stmt = $pdo->prepare("SHOW TABLES LIKE 'announcements'");
        $stmt->execute();
        $tableExists = $stmt->rowCount() > 0;
        
        if (!$tableExists) {
            // Create table if it doesn't exist
            $pdo->exec("CREATE TABLE IF NOT EXISTS announcements (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title TEXT NOT NULL,
                content TEXT NOT NULL,
                event_date DATE NULL,
                location VARCHAR(255) NULL,
                responsible_dept VARCHAR(255) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
        } else {
            // Table exists, check if created_at column exists
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'announcements' AND COLUMN_NAME = 'created_at'");
            $stmt->execute();
            if ($stmt->fetchColumn() == 0) {
                // Add created_at column if it doesn't exist
                $pdo->exec("ALTER TABLE announcements ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
            }
        }
        
        // Ensure optional columns exist
        $columns = [
            'event_date' => "ALTER TABLE announcements ADD COLUMN event_date DATE NULL",
            'location' => "ALTER TABLE announcements ADD COLUMN location VARCHAR(255) NULL",
            'responsible_dept' => "ALTER TABLE announcements ADD COLUMN responsible_dept VARCHAR(255) NULL"
        ];

        foreach ($columns as $column => $alterSql) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'announcements' AND COLUMN_NAME = ?");
            $stmt->execute([$column]);
            if ($stmt->fetchColumn() == 0) {
                try {
                    $pdo->exec($alterSql);
                } catch (PDOException $e) {
                    // Ignore if schema migration is not possible
                }
            }
        }
    } catch (PDOException $e) {
        // Log error but don't stop announcement loading
        error_log('Announcements schema error: ' . $e->getMessage());
    }
}

ensureAnnouncementsSchema($pdo);

try {
    $stmt = $pdo->prepare("
        SELECT 
            id,
            title,
            content,
            event_date,
            location,
            responsible_dept,
            created_at
        FROM announcements 
        ORDER BY created_at DESC
    ");
    $stmt->execute();
    $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'announcements' => $announcements
    ]);
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching announcements: ' . $e->getMessage()
    ]);
}
?>