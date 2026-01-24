<?php
ob_clean();
ob_start();
header('Content-Type: application/json');
require_once 'db.php';

// Validate request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

// Create enrollments table
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS enrollments (
            id INT PRIMARY KEY AUTO_INCREMENT,
            full_name VARCHAR(255) NOT NULL,
            age INT NOT NULL,
            gender ENUM('Male', 'Female') NOT NULL,
            birthdate DATE NOT NULL,
            email VARCHAR(255) NOT NULL UNIQUE,
            phone VARCHAR(20) NOT NULL,
            status ENUM('pending', 'approved', 'rejected', 'needs_docs') DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS enrollment_documents (
            id INT PRIMARY KEY AUTO_INCREMENT,
            enrollment_id INT NOT NULL,
            document_filename VARCHAR(255) NOT NULL,
            document_path VARCHAR(500) NOT NULL,
            file_size INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (enrollment_id) REFERENCES enrollments(id) ON DELETE CASCADE
        )
    ");
} catch(PDOException $e) {
    error_log("Database table creation error: " . $e->getMessage());
}

// Get form data
$fullName = trim($_POST['fullName'] ?? '');
$age = trim($_POST['age'] ?? '');
$gender = trim($_POST['gender'] ?? '');
$birthdate = trim($_POST['birthdate'] ?? '');
$email = trim($_POST['email'] ?? '');
$phone = trim($_POST['phone'] ?? '');

// Validation
$errors = [];

if (empty($fullName)) {
    $errors[] = 'Full name is required';
}

if (empty($age) || !is_numeric($age) || $age < 1 || $age > 120) {
    $errors[] = 'Valid age is required';
}

if (empty($gender) || !in_array($gender, ['Male', 'Female'])) {
    $errors[] = 'Valid gender selection is required';
}

if (empty($birthdate)) {
    $errors[] = 'Birthdate is required';
}

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Valid email is required';
}

if (empty($phone) || !preg_match('/^\+63\d{10}$/', $phone)) {
    $errors[] = 'Valid phone number is required';
}

// Validate file upload
if (empty($_FILES['documents']['name'][0])) {
    $errors[] = 'At least one document must be uploaded';
}

if (!empty($errors)) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => implode(', ', $errors)]);
    exit();
}

// Create enrollment directory
$enrollmentDir = __DIR__ . '/../enrollments';
if (!is_dir($enrollmentDir)) {
    mkdir($enrollmentDir, 0755, true);
}

// Handle file uploads
$uploadedFiles = [];
$maxFileSize = 5 * 1024 * 1024; // 5MB
$tempDir = $enrollmentDir . '/' . 'temp_' . time();

// Create temporary directory for uploads
if (!is_dir($tempDir)) {
    mkdir($tempDir, 0755, true);
}

if (!empty($_FILES['documents'])) {
    $fileCount = count($_FILES['documents']['name']);
    
    for ($i = 0; $i < $fileCount; $i++) {
        $fileName = $_FILES['documents']['name'][$i];
        $fileTmpName = $_FILES['documents']['tmp_name'][$i];
        $fileSize = $_FILES['documents']['size'][$i];
        $fileError = $_FILES['documents']['error'][$i];

        // Check for upload errors
        if ($fileError !== UPLOAD_ERR_OK) {
            $errors[] = "Upload error for file: $fileName";
            continue;
        }

        // Validate file size
        if ($fileSize > $maxFileSize) {
            $errors[] = "File $fileName exceeds maximum size of 5MB";
            continue;
        }

        // Validate file type
        $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png'];
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        
        if (!in_array($fileExtension, $allowedExtensions)) {
            $errors[] = "File type not allowed for: $fileName";
            continue;
        }

        // Create unique filename
        $uniqueFileName = time() . '_' . md5($fileName) . '.' . $fileExtension;
        $destinationPath = $tempDir . '/' . $uniqueFileName;

        // Move uploaded file
        if (move_uploaded_file($fileTmpName, $destinationPath)) {
            $uploadedFiles[] = [
                'originalName' => $fileName,
                'savedName' => $uniqueFileName,
                'size' => $fileSize
            ];
        } else {
            $errors[] = "Failed to save file: $fileName";
        }
    }
}

if (!empty($errors)) {
    echo json_encode(['success' => false, 'message' => implode(', ', $errors)]);
    exit();
}

// Save enrollment data to MySQL database
try {
    $stmt = $pdo->prepare("
        INSERT INTO enrollments (full_name, age, gender, birthdate, email, phone, status)
        VALUES (?, ?, ?, ?, ?, ?, 'pending')
    ");
    
    $stmt->execute([$fullName, $age, $gender, $birthdate, $email, $phone]);
    $enrollmentId = $pdo->lastInsertId();

    // Now rename the temporary directory to use enrollment ID
    $finalDir = $enrollmentDir . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $fullName) . '_' . $enrollmentId;
    if (is_dir($tempDir) && !is_dir($finalDir)) {
        rename($tempDir, $finalDir);
    }

    // Insert documents
    $docStmt = $pdo->prepare("
        INSERT INTO enrollment_documents (enrollment_id, document_filename, document_path, file_size)
        VALUES (?, ?, ?, ?)
    ");

    foreach ($uploadedFiles as $file) {
        $docPath = 'enrollments/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $fullName) . '_' . $enrollmentId . '/' . $file['savedName'];
        $docStmt->execute([$enrollmentId, $file['originalName'], $docPath, $file['size']]);
    }

    // Send confirmation email
    try {
        $subject = "Enrollment Application Received - Baesa Adventist Academy";
        $body = "Dear $fullName,\n\n";
        $body .= "Thank you for submitting your enrollment application to Baesa Adventist Academy.\n\n";
        $body .= "We have received your submission and will review your documents shortly.\n";
        $body .= "We will contact you at $phone or $email once we have completed our review.\n\n";
        $body .= "Best regards,\nBaesa Adventist Academy Enrollment Office";
        
        @mail($email, $subject, $body, "From: enrollment@baa.edu");
    } catch(Exception $e) {
        error_log("Mail error: " . $e->getMessage());
    }

    ob_end_clean();
    echo json_encode(['success' => true, 'message' => 'Enrollment submitted successfully', 'enrollmentId' => $enrollmentId]);
    exit();
} catch(PDOException $e) {
    error_log("Enrollment insertion error: " . $e->getMessage());
    
    ob_end_clean();
    // Check if it's a duplicate email error
    if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
        echo json_encode(['success' => false, 'message' => 'This email address is already registered']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to process enrollment. Please try again.']);
    }
    exit();
} catch(Exception $e) {
    error_log("Unexpected error in enrollment: " . $e->getMessage());
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'An unexpected error occurred']);
    exit();
}
?>
