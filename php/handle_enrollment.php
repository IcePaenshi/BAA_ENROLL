<?php
session_start();
header('Content-Type: application/json');
require_once 'db.php';
require_once '../fpdf/fpdf.php'; 

// Function to generate PDF receipt using FPDF
function generatePDFReceipt($data, $enrollmentId) {
    // Create PDF
    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->AddPage();
    
    // Set font
    $pdf->SetFont('Arial', 'B', 16);
    
    // School Header
    $pdf->SetTextColor(10, 45, 99);
    $pdf->Cell(0, 10, 'Baesa Adventist Academy', 0, 1, 'C');
    $pdf->SetFont('Arial', '', 12);
    $pdf->Cell(0, 8, 'Enrollment Office', 0, 1, 'C');
    $pdf->Cell(0, 8, '123 Education Street, Baesa, Quezon City', 0, 1, 'C');
    
    // Line separator
    $pdf->SetDrawColor(10, 45, 99);
    $pdf->SetLineWidth(0.5);
    $pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
    $pdf->Ln(10);
    
    // Title
    $pdf->SetFont('Arial', 'B', 20);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(0, 15, 'ENROLLMENT RECEIPT', 0, 1, 'C');
    $pdf->Ln(5);
    
    // Receipt Details
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(50, 10, 'Receipt Number:', 0, 0);
    $pdf->SetFont('Arial', '', 12);
    $pdf->Cell(0, 10, 'ENR-' . str_pad($enrollmentId, 6, '0', STR_PAD_LEFT), 0, 1);
    
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(50, 10, 'Date:', 0, 0);
    $pdf->SetFont('Arial', '', 12);
    $pdf->Cell(0, 10, date('F j, Y, g:i a'), 0, 1);
    
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(50, 10, 'Enrollment ID:', 0, 0);
    $pdf->SetFont('Arial', '', 12);
    $pdf->Cell(0, 10, $enrollmentId, 0, 1);
    
    $pdf->Ln(10);
    
    // Student Information
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->SetFillColor(240, 240, 240);
    $pdf->Cell(0, 10, 'STUDENT INFORMATION', 0, 1, 'C', true);
    $pdf->Ln(5);
    
    // Table headers
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(60, 10, 'Field', 1, 0, 'C', true);
    $pdf->Cell(130, 10, 'Information', 1, 1, 'C', true);
    
    // Table data
    $pdf->SetFont('Arial', '', 11);
    
    $fields = [
        'Full Name' => $data['full_name'],
        'Age' => $data['age'] . ' years old',
        'Gender' => $data['gender'],
        'Birthdate' => date('F j, Y', strtotime($data['birthdate'])),
        'Grade Level' => 'Grade ' . $data['grade_level'],
    ];
    
    if (!empty($data['strand'])) {
        $fields['Strand'] = $data['strand'];
    }
    
    $fields['Email Address'] = $data['email'];
    $fields['Phone Number'] = $data['phone'];
    $fields['Application Status'] = 'PENDING REVIEW';
    
    foreach ($fields as $field => $value) {
        $pdf->Cell(60, 10, $field, 1, 0);
        $pdf->Cell(130, 10, $value, 1, 1);
    }
    
    $pdf->Ln(15);
    
    // Important Notes
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->SetTextColor(10, 45, 99);
    $pdf->Cell(0, 10, 'IMPORTANT NOTES:', 0, 1);
    $pdf->Ln(2);
    
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Arial', '', 10);
    $notes = [
        "1. This receipt confirms that we have received your enrollment application.",
        "2. Please keep this receipt for your records and future reference.",
        "3. Your Enrollment ID ($enrollmentId) will be used for all communications.",
        "4. We will review your documents and contact you within 3-5 working days.",
        "5. For inquiries, please contact: enrollment@baa.edu",
    ];
    
    foreach ($notes as $note) {
        $pdf->MultiCell(0, 6, $note);
        $pdf->Ln(2);
    }
    
    $pdf->Ln(10);
    
    // Footer
    $pdf->SetFont('Arial', 'I', 9);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->Cell(0, 10, 'This is a computer-generated receipt. No signature is required.', 0, 1, 'C');
    
    // Save PDF
    $pdfDir = __DIR__ . '/../enrollments/receipts';
    if (!is_dir($pdfDir)) {
        mkdir($pdfDir, 0755, true);
    }
    
    $filename = "receipt_{$enrollmentId}.pdf";
    $filepath = $pdfDir . '/' . $filename;
    $pdf->Output($filepath, 'F');
    
    return 'enrollments/receipts/' . $filename;
}

// Validate request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

// Create enrollments table if not exists
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
            grade_level VARCHAR(10) NULL,
            strand VARCHAR(50) NULL,
            receipt_path VARCHAR(500) NULL,
            status ENUM('pending', 'approved', 'rejected', 'needs_docs') DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_email (email),
            INDEX idx_status (status)
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
            FOREIGN KEY (enrollment_id) REFERENCES enrollments(id) ON DELETE CASCADE,
            INDEX idx_enrollment_id (enrollment_id)
        )
    ");
} catch(PDOException $e) {
    error_log("Database table creation error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database setup failed']);
    exit();
}

// Get form data with proper sanitization
$fullName = trim($_POST['fullName'] ?? '');
$age = trim($_POST['age'] ?? '');
$gender = trim($_POST['gender'] ?? '');
$birthdate = trim($_POST['birthdate'] ?? '');
$email = trim($_POST['email'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$grade = trim($_POST['grade'] ?? '');
$strand = trim($_POST['strand'] ?? '');

// Validation
$errors = [];

if (empty($fullName) || strlen($fullName) < 2) {
    $errors[] = 'Full name is required (minimum 2 characters)';
}

if (empty($age) || !is_numeric($age) || $age < 1 || $age > 120) {
    $errors[] = 'Valid age (1-120) is required';
}

if (empty($gender) || !in_array($gender, ['Male', 'Female'])) {
    $errors[] = 'Valid gender selection is required';
}

if (empty($birthdate)) {
    $errors[] = 'Birthdate is required';
} elseif (!strtotime($birthdate)) {
    $errors[] = 'Invalid birthdate format';
}

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Valid email is required';
}

// Phone validation
if (empty($phone)) {
    $errors[] = 'Phone number is required';
} else {
    // Remove any non-numeric characters except +
    $phone = preg_replace('/[^0-9+]/', '', $phone);
    // Ensure it starts with +63
    if (!preg_match('/^\+63\d{10}$/', $phone)) {
        $errors[] = 'Phone number must be in format: +639XXXXXXXXX';
    }
}

// Grade validation
if (empty($grade) || !in_array($grade, ['7', '8', '9', '10', '11', '12'])) {
    $errors[] = 'Valid grade level is required';
}

// Strand validation for grades 11-12
if (in_array($grade, ['11', '12']) && empty($strand)) {
    $errors[] = 'Strand selection is required for Grade 11-12';
}

// Validate file upload
if (!isset($_FILES['documents']) || empty($_FILES['documents']['name'][0])) {
    $errors[] = 'At least one document must be uploaded';
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => implode('. ', $errors)]);
    exit();
}

// Create enrollment directories
$baseDir = __DIR__ . '/../enrollments';
if (!is_dir($baseDir)) {
    if (!mkdir($baseDir, 0755, true)) {
        echo json_encode(['success' => false, 'message' => 'Failed to create base enrollment directory']);
        exit();
    }
}

// Create subdirectories
$directories = [
    'documents',
    'receipts'
];

foreach ($directories as $dir) {
    $dirPath = $baseDir . '/' . $dir;
    if (!is_dir($dirPath)) {
        if (!mkdir($dirPath, 0755, true)) {
            echo json_encode(['success' => false, 'message' => "Failed to create $dir directory"]);
            exit();
        }
    }
}

// Handle file uploads
$uploadedFiles = [];
$maxFileSize = 5 * 1024 * 1024; // 5MB
$allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png'];

if (isset($_FILES['documents'])) {
    $fileCount = count($_FILES['documents']['name']);
    
    for ($i = 0; $i < $fileCount; $i++) {
        // Skip if no file uploaded for this index
        if ($_FILES['documents']['error'][$i] === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        
        $fileName = $_FILES['documents']['name'][$i];
        $fileTmpName = $_FILES['documents']['tmp_name'][$i];
        $fileSize = $_FILES['documents']['size'][$i];
        $fileError = $_FILES['documents']['error'][$i];

        // Check for upload errors
        if ($fileError !== UPLOAD_ERR_OK) {
            $errors[] = "Upload error for file: $fileName (Error code: $fileError)";
            continue;
        }

        // Validate file size
        if ($fileSize > $maxFileSize) {
            $errors[] = "File $fileName exceeds maximum size of 5MB";
            continue;
        }

        // Validate file type
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        
        if (!in_array($fileExtension, $allowedExtensions)) {
            $errors[] = "File type not allowed for: $fileName (Allowed: PDF, JPG, JPEG, PNG)";
            continue;
        }

        // Create unique filename
        $uniqueFileName = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9\._-]/', '_', $fileName);
        $destinationPath = $baseDir . '/documents/' . $uniqueFileName;

        // Move uploaded file
        if (move_uploaded_file($fileTmpName, $destinationPath)) {
            $uploadedFiles[] = [
                'originalName' => $fileName,
                'savedName' => $uniqueFileName,
                'size' => $fileSize
            ];
        } else {
            $errors[] = "Failed to save file: $fileName";
            error_log("Failed to move file from $fileTmpName to $destinationPath");
        }
    }
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => implode('. ', $errors)]);
    
    // Clean up any uploaded files
    foreach ($uploadedFiles as $file) {
        $filePath = $baseDir . '/documents/' . $file['savedName'];
        if (file_exists($filePath)) {
            @unlink($filePath);
        }
    }
    exit();
}

// Check if files were uploaded
if (empty($uploadedFiles)) {
    echo json_encode(['success' => false, 'message' => 'No valid files were uploaded']);
    exit();
}

// Save enrollment data to MySQL database
try {
    // Start transaction
    $pdo->beginTransaction();
    
    // Insert enrollment record
    $stmt = $pdo->prepare("
        INSERT INTO enrollments (full_name, age, gender, birthdate, email, phone, grade_level, strand, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')
    ");
    
    $stmt->execute([$fullName, $age, $gender, $birthdate, $email, $phone, $grade, $strand]);
    $enrollmentId = $pdo->lastInsertId();

    // Insert documents
    $docStmt = $pdo->prepare("
        INSERT INTO enrollment_documents (enrollment_id, document_filename, document_path, file_size)
        VALUES (?, ?, ?, ?)
    ");

    foreach ($uploadedFiles as $file) {
        $docPath = 'enrollments/documents/' . $file['savedName'];
        $docStmt->execute([$enrollmentId, $file['originalName'], $docPath, $file['size']]);
    }

    // Generate PDF receipt
    $enrollmentData = [
        'full_name' => $fullName,
        'age' => $age,
        'gender' => $gender,
        'birthdate' => $birthdate,
        'grade_level' => $grade,
        'strand' => $strand,
        'email' => $email,
        'phone' => $phone
    ];
    
    $receiptPath = generatePDFReceipt($enrollmentData, $enrollmentId);
    
    // Update enrollment with receipt path
    $updateStmt = $pdo->prepare("UPDATE enrollments SET receipt_path = ? WHERE id = ?");
    $updateStmt->execute([$receiptPath, $enrollmentId]);

    // Send confirmation email
    try {
        $to = $email;
        $subject = "Enrollment Application Received - Baesa Adventist Academy";
        $message = "Dear $fullName,\n\n";
        $message .= "Thank you for submitting your enrollment application to Baesa Adventist Academy.\n\n";
        $message .= "Application Details:\n";
        $message .= "- Full Name: $fullName\n";
        $message .= "- Grade Level: Grade $grade\n";
        $message .= "- Enrollment ID: $enrollmentId\n";
        $message .= "- Date Submitted: " . date('F j, Y, g:i a') . "\n\n";
        $message .= "We have received your submission and will review your documents shortly.\n";
        $message .= "We will contact you at $phone or $email once we have completed our review.\n\n";
        $message .= "You can download your receipt from: http://" . $_SERVER['HTTP_HOST'] . "/" . $receiptPath . "\n\n";
        $message .= "Best regards,\nBaesa Adventist Academy Enrollment Office\n";
        $message .= "Phone: (02) 1234-5678\n";
        $message .= "Email: enrollment@baa.edu";
        
        $headers = "From: enrollment@baa.edu\r\n";
        $headers .= "Reply-To: enrollment@baa.edu\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion();
        
        @mail($to, $subject, $message, $headers);
    } catch(Exception $e) {
        error_log("Mail error: " . $e->getMessage());
        // Don't fail the enrollment if email fails
    }

    // Commit transaction
    $pdo->commit();

    echo json_encode([
        'success' => true, 
        'message' => 'Enrollment submitted successfully', 
        'enrollmentId' => $enrollmentId,
        'pdf_url' => $receiptPath
    ]);
    exit();
    
} catch(PDOException $e) {
    // Rollback transaction on error
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log("Enrollment insertion error: " . $e->getMessage());
    
    // Clean up uploaded files on error
    foreach ($uploadedFiles as $file) {
        $filePath = $baseDir . '/documents/' . $file['savedName'];
        if (file_exists($filePath)) {
            @unlink($filePath);
        }
    }
    
    http_response_code(500);
    // Check if it's a duplicate email error
    if (strpos($e->getMessage(), 'Duplicate entry') !== false && strpos($e->getMessage(), 'email')) {
        echo json_encode(['success' => false, 'message' => 'This email address is already registered']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
} catch(Exception $e) {
    // Rollback transaction on error
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log("Unexpected error in enrollment: " . $e->getMessage());
    
    // Clean up uploaded files on error
    foreach ($uploadedFiles as $file) {
        $filePath = $baseDir . '/documents/' . $file['savedName'];
        if (file_exists($filePath)) {
            @unlink($filePath);
        }
    }
    
    echo json_encode(['success' => false, 'message' => 'An unexpected error occurred: ' . $e->getMessage()]);
    exit();
}
?>