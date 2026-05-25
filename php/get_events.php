<?php
header('Content-Type: application/json');

try {
    require_once 'db.php';
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database connection error']);
    exit();
}

try {
    $stmt = $pdo->prepare("
        SELECT 
            id,
            title,
            content AS description,
            event_date,
            location,
            created_at
        FROM announcements 
        WHERE event_date IS NOT NULL AND event_date >= CURDATE()
        ORDER BY event_date ASC
        LIMIT 10
    ");
    $stmt->execute();
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'events' => $events
    ]);
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching events: ' . $e->getMessage()
    ]);
}
?>