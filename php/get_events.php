<?php
header('Content-Type: application/json');
require_once 'db.php';

try {
    $stmt = $pdo->prepare("
        SELECT 
            id,
            title,
            description,
            event_date,
            location,
            created_at
        FROM events 
        WHERE event_date >= CURDATE()
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