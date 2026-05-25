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
        $pdo->exec("CREATE TABLE IF NOT EXISTS announcements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title TEXT NOT NULL,
            content TEXT NOT NULL,
            event_date DATE NULL,
            location VARCHAR(255) NULL,
            responsible_dept VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

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
                    // Ignore if schema migration is not possible here.
                }
            }
        }
    } catch (PDOException $e) {
        // Ignore schema creation errors to preserve announcement handling flow.
    }
}

ensureAnnouncementsSchema($pdo);

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'registrar'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Support both form-data/urlencoded and JSON input
$input = $_POST;
if (empty($_POST)) {
    $raw = file_get_contents('php://input');
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $input = $decoded;
    } else {
        $input = [];
    }
}

$action = trim($input['action'] ?? '');

if (empty($action)) {
    echo json_encode(['success' => false, 'message' => 'Action is required.']);
    exit();
}

switch ($action) {
    case 'create':
        $title = trim($input['title'] ?? '');
        $content = trim($input['content'] ?? '');
        $event_date = !empty($input['event_date']) ? trim($input['event_date']) : null;
        $location = !empty($input['location']) ? trim($input['location']) : null;
        $responsible_dept = !empty($input['responsible_dept']) ? trim($input['responsible_dept']) : null;

        if (empty($title) || empty($content)) {
            echo json_encode(['success' => false, 'message' => 'Title and Content are required.']);
            exit();
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO announcements (title, content, event_date, location, responsible_dept) 
                VALUES (:title, :content, :event_date, :location, :responsible_dept)
            ");
            $stmt->execute([
                ':title' => $title,
                ':content' => $content,
                ':event_date' => $event_date,
                ':location' => $location,
                ':responsible_dept' => $responsible_dept
            ]);
            echo json_encode(['success' => true, 'message' => 'Announcement created successfully.']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        break;

    case 'update':
        $id = intval($input['id'] ?? 0);
        $title = trim($input['title'] ?? '');
        $content = trim($input['content'] ?? '');
        $event_date = !empty($input['event_date']) ? trim($input['event_date']) : null;
        $location = !empty($input['location']) ? trim($input['location']) : null;
        $responsible_dept = !empty($input['responsible_dept']) ? trim($input['responsible_dept']) : null;

        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid announcement ID.']);
            exit();
        }
        if (empty($title) || empty($content)) {
            echo json_encode(['success' => false, 'message' => 'Title and Content are required.']);
            exit();
        }

        try {
            $stmt = $pdo->prepare("
                UPDATE announcements 
                SET title = :title, content = :content, event_date = :event_date, location = :location, responsible_dept = :responsible_dept
                WHERE id = :id
            ");
            $stmt->execute([
                ':title' => $title,
                ':content' => $content,
                ':event_date' => $event_date,
                ':location' => $location,
                ':responsible_dept' => $responsible_dept,
                ':id' => $id
            ]);
            echo json_encode(['success' => true, 'message' => 'Announcement updated successfully.']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        break;

    case 'delete':
        $id = intval($input['id'] ?? 0);

        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid announcement ID.']);
            exit();
        }

        try {
            $stmt = $pdo->prepare("DELETE FROM announcements WHERE id = :id");
            $stmt->execute([':id' => $id]);
            echo json_encode(['success' => true, 'message' => 'Announcement deleted successfully.']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
        break;
}
?>
