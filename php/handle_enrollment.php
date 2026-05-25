<?php
session_start();
header('Content-Type: application/json');
require_once 'db.php';
require_once 'file_security.php';
require_once '../fpdf/fpdf.php';

$appConfig = [];
$appConfigPath = __DIR__ . '/config.local.php';
if (file_exists($appConfigPath)) {
    $appConfig = require $appConfigPath;
}

$recaptchaSiteKey = $appConfig['recaptcha_site_key'] ?? getenv('RECAPTCHA_SITEKEY') ?: '';
$recaptchaSecret = $appConfig['recaptcha_secret'] ?? getenv('RECAPTCHA_SECRET') ?: '';
$recaptchaEnterpriseProjectId = $appConfig['recaptcha_enterprise_project_id'] ?? getenv('RECAPTCHA_ENTERPRISE_PROJECT_ID') ?: '';
$recaptchaEnterpriseApiKey = $appConfig['recaptcha_enterprise_api_key'] ?? getenv('RECAPTCHA_ENTERPRISE_API_KEY') ?: '';

function isRecaptchaEnterpriseConfigured($projectId, $apiKey) {
    if (empty($projectId) || empty($apiKey)) {
        return false;
    }

    $placeholders = [
        'YOUR_RECAPTCHA_ENTERPRISE_API_KEY',
        'my-project-6794-177580215738',
        'YOUR_RECAPTCHA_ENTERPRISE_PROJECT_ID',
    ];

    return !in_array($projectId, $placeholders, true)
        && !in_array($apiKey, $placeholders, true);
}

function isRecaptchaSecretConfigured($secret) {
    $placeholders = [
        'YOUR_RECAPTCHA_SECRET',
        '6LdacN4sAAAAAKtdBS7qB9Y86dq3PhP9MC6iNpNL',
    ];

    return !empty($secret) && !in_array($secret, $placeholders, true);
}

// Function to generate PDF receipt using FPDF
function generatePDFReceipt($data, $enrollmentId) {
    // Build full name from parts
    $suffix = baa_normalize_name_part($data['suffix'] ?? '');
    $fullName = baa_build_full_name([$data['first_name'] ?? '', $data['middle_name'] ?? '', $data['last_name'] ?? '']);
    if ($suffix !== '') {
        $fullName .= ', ' . $suffix;
    }

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
        'Full Name' => $fullName,
        'Age' => $data['age'] . ' years old',
        'Gender' => $data['gender'],
        'Birthdate' => date('F j, Y', strtotime($data['birthdate'])),
        'Grade Level' => 'Grade ' . $data['grade_level'],
        'LRN' => $data['lrn'],
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

// Create enrollments table if not exists (updated to match your schema)
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS enrollments (
            id INT PRIMARY KEY AUTO_INCREMENT,
            first_name VARCHAR(100) NOT NULL,
            middle_name VARCHAR(100),
            last_name VARCHAR(100) NOT NULL,
            suffix VARCHAR(20),
            age INT NOT NULL,
            gender ENUM('Male', 'Female') NOT NULL,
            birthdate DATE NOT NULL,
            email VARCHAR(255) NOT NULL UNIQUE,
            phone VARCHAR(20) NOT NULL,
            grade_level VARCHAR(10) NULL,
            strand VARCHAR(50) NULL,
            student_type ENUM('New Student','Transferee') NOT NULL DEFAULT 'New Student',
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

    $columnExists = $pdo->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'enrollments' AND COLUMN_NAME = 'student_type'"
    );
    $columnExists->execute();
    if ($columnExists->fetchColumn() == 0) {
        $pdo->exec("ALTER TABLE enrollments ADD COLUMN student_type ENUM('New Student','Transferee') NOT NULL DEFAULT 'New Student'");
    }

    // Add LRN column if it doesn't exist
    $lrnColumnExists = $pdo->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'enrollments' AND COLUMN_NAME = 'lrn'"
    );
    $lrnColumnExists->execute();
    if ($lrnColumnExists->fetchColumn() == 0) {
        $pdo->exec("ALTER TABLE enrollments ADD COLUMN lrn VARCHAR(20) NULL");
    }
} catch(PDOException $e) {
    error_log("Database table creation error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database setup failed']);
    exit();
}

// Verify reCAPTCHA
$recaptchaResponse = $_POST['g-recaptcha-response'] ?? '';

if (empty($recaptchaResponse)) {
    echo json_encode(['success' => false, 'message' => 'reCAPTCHA verification is required']);
    exit();
}

if (!isRecaptchaSecretConfigured($recaptchaSecret)) {
    echo json_encode([
        'success' => false,
        'message' => 'reCAPTCHA is not properly configured on the server. Please set a valid recaptcha_secret in php/config.local.php.'
    ]);
    exit();
}

function verifyRecaptchaResponse($secret, $response, $remoteIp = null, $siteKey = '', $enterpriseProjectId = '', $enterpriseApiKey = '', $expectedAction = 'USER_ACTION') {
    if (!empty($enterpriseProjectId) && !empty($enterpriseApiKey)) {
        $recaptchaUrl = sprintf(
            'https://recaptchaenterprise.googleapis.com/v1/projects/%s/assessments?key=%s',
            rawurlencode($enterpriseProjectId),
            rawurlencode($enterpriseApiKey)
        );
        $recaptchaData = [
            'event' => [
                'token' => $response,
                'siteKey' => $siteKey,
                'expectedAction' => $expectedAction,
            ]
        ];
        if ($remoteIp) {
            $recaptchaData['event']['userIpAddress'] = $remoteIp;
        }
        if (!empty($_SERVER['HTTP_USER_AGENT'])) {
            $recaptchaData['event']['userAgent'] = $_SERVER['HTTP_USER_AGENT'];
        }
    } else {
        $recaptchaUrl = 'https://www.google.com/recaptcha/api/siteverify';
        $recaptchaData = [
            'secret' => $secret,
            'response' => $response
        ];
        if ($remoteIp) {
            $recaptchaData['remoteip'] = $remoteIp;
        }
    }

    // Prefer cURL when available (more reliable on Windows/XAMPP).
    if (function_exists('curl_init')) {
        $ch = curl_init($recaptchaUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        if (!empty($enterpriseProjectId) && !empty($enterpriseApiKey)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($recaptchaData));
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($recaptchaData));
        }
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        // Some Windows/XAMPP installs lack CA bundles; allow a fallback.
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        $result = curl_exec($ch);
        $curlErrNo = curl_errno($ch);
        $curlErr = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($result !== false && $httpCode >= 200 && $httpCode < 300) {
            $decoded = json_decode($result, true);
            if (is_array($decoded)) {
                $decoded['_meta'] = ['transport' => 'curl', 'http_code' => $httpCode];
                return $decoded;
            }
        }

        // Retry once with relaxed SSL verification (pragmatic dev-hosting fallback).
        $ch2 = curl_init($recaptchaUrl);
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch2, CURLOPT_POST, true);
        if (!empty($enterpriseProjectId) && !empty($enterpriseApiKey)) {
            curl_setopt($ch2, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch2, CURLOPT_POSTFIELDS, json_encode($recaptchaData));
        } else {
            curl_setopt($ch2, CURLOPT_POSTFIELDS, http_build_query($recaptchaData));
        }
        curl_setopt($ch2, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch2, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch2, CURLOPT_SSL_VERIFYHOST, 0);
        $result2 = curl_exec($ch2);
        $curlErrNo2 = curl_errno($ch2);
        $curlErr2 = curl_error($ch2);
        $httpCode2 = (int) curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        curl_close($ch2);

        if ($result2 !== false && $httpCode2 >= 200 && $httpCode2 < 300) {
            $decoded2 = json_decode($result2, true);
            if (is_array($decoded2)) {
                $decoded2['_meta'] = ['transport' => 'curl_insecure', 'http_code' => $httpCode2];
                return $decoded2;
            }
        }

        return [
            'success' => false,
            'error-codes' => ['siteverify_unreachable'],
            '_meta' => [
                'transport' => 'curl_failed',
                'http_code' => $httpCode ?: null,
                'curl_errno' => $curlErrNo ?: null,
                'curl_error' => $curlErr ?: null,
                'curl_errno_retry' => $curlErrNo2 ?: null,
                'curl_error_retry' => $curlErr2 ?: null,
                'http_code_retry' => $httpCode2 ?: null,
            ],
        ];
    }

    // Fallback: file_get_contents (if cURL is unavailable)
    $options = [
        'http' => [
            'header' => "Content-type: application/x-www-form-urlencoded\r\n",
            'method' => 'POST',
            'content' => http_build_query($recaptchaData),
            'timeout' => 15
        ]
    ];
    if (!empty($enterpriseProjectId) && !empty($enterpriseApiKey)) {
        $options['http']['header'] = "Content-type: application/json\r\n";
        $options['http']['content'] = json_encode($recaptchaData);
    }
    $context = stream_context_create($options);
    $result = @file_get_contents($recaptchaUrl, false, $context);
    $decoded = $result ? json_decode($result, true) : null;
    if (is_array($decoded)) {
        $decoded['_meta'] = ['transport' => 'fopen'];
        return $decoded;
    }
    return [
        'success' => false,
        'error-codes' => ['siteverify_unreachable'],
        '_meta' => ['transport' => 'fopen_failed'],
    ];
}

// If an enterprise project ID + API key are configured, verify using the enterprise assessments endpoint.
$useEnterprise = isRecaptchaEnterpriseConfigured($recaptchaEnterpriseProjectId, $recaptchaEnterpriseApiKey);
$recaptchaResult = verifyRecaptchaResponse(
    $recaptchaSecret,
    $recaptchaResponse,
    $_SERVER['REMOTE_ADDR'] ?? null,
    $recaptchaSiteKey,
    $useEnterprise ? $recaptchaEnterpriseProjectId : '',
    $useEnterprise ? $recaptchaEnterpriseApiKey : '',
    'USER_ACTION'
);

// Enterprise responses do not always include a top-level `success` flag.
$recaptchaSuccess = false;
if (!empty($recaptchaResult['success'])) {
    $recaptchaSuccess = true;
} elseif (!empty($recaptchaResult['tokenProperties']['valid'])) {
    $recaptchaSuccess = true;
}

if (empty($recaptchaResult) || !$recaptchaSuccess) {
    $message = 'reCAPTCHA verification failed';
    if (!empty($recaptchaResult['error-codes']) && is_array($recaptchaResult['error-codes'])) {
        $message .= ': ' . implode(', ', $recaptchaResult['error-codes']);
    }
    if (!empty($recaptchaResult['tokenProperties']['invalidReason'])) {
        $message .= ': ' . $recaptchaResult['tokenProperties']['invalidReason'];
    }
    if (!empty($recaptchaResult['_meta']) && is_array($recaptchaResult['_meta'])) {
        $meta = $recaptchaResult['_meta'];
        $message .= ' (debug: ' . json_encode($meta) . ')';
    }
    echo json_encode(['success' => false, 'message' => $message]);
    exit();
}

// Get form data with proper sanitization
$fullNameInput = trim($_POST['fullName'] ?? '');
$age        = trim($_POST['age'] ?? '');
$gender     = trim($_POST['gender'] ?? '');
$birthdate  = trim($_POST['birthdate'] ?? '');
$email      = trim($_POST['email'] ?? '');
$phone      = trim($_POST['phone'] ?? '');
$grade      = trim($_POST['grade'] ?? '');
$lrn        = trim($_POST['lrn'] ?? '');
$strand     = trim($_POST['strand'] ?? '');
$studentType = trim($_POST['studentType'] ?? 'New Student');

// Parse full name into parts
$nameParts = preg_split('/\s+/', $fullNameInput, -1, PREG_SPLIT_NO_EMPTY);
$firstName = $nameParts[0] ?? '';
$middleName = '';
$lastName = '';
$suffix = '';

if (count($nameParts) >= 2) {
    $lastName = array_pop($nameParts);
    if (count($nameParts) > 1) {
        $middleName = implode(' ', array_slice($nameParts, 1));
    }
}

// Check for suffix in last name
if (preg_match('/^(.*),\s*(.+)$/', $lastName, $matches)) {
    $lastName = trim($matches[1]);
    $suffix = trim($matches[2]);
}

// Validation
$errors = [];

if (empty($firstName) || strlen($firstName) < 2) {
    $errors[] = 'First name is required (minimum 2 characters)';
}
if (empty($lastName) || strlen($lastName) < 2) {
    $errors[] = 'Last name is required (minimum 2 characters)';
}

if (empty($age) || !is_numeric($age) || $age < 8 || $age > 50) {
    $errors[] = 'Valid age (8-50) is required';
}

if (empty($gender) || !in_array($gender, ['Male', 'Female'])) {
    $errors[] = 'Valid gender selection is required';
}

if (empty($lrn) || !preg_match('/^\d{12}$/', $lrn)) {
    $errors[] = 'LRN must be exactly 12 digits';
}

if (!strtotime($birthdate)) {
    $errors[] = 'Invalid birthdate format';
} else {
    $birthTimestamp = strtotime($birthdate);
    $birthYear = (int) date('Y', $birthTimestamp);
    $birthMonth = (int) date('n', $birthTimestamp);
    $birthDay = (int) date('j', $birthTimestamp);
    $today = new DateTime('now');
    $dob = DateTime::createFromFormat('Y-m-d', $birthdate);
    if (!$dob) {
        $errors[] = 'Invalid birthdate format';
    } else {
        $ageInterval = $today->diff($dob);
        $computedAge = (int) $ageInterval->y;
        if ($computedAge < 8 || $computedAge > 50) {
            $errors[] = 'Birthdate must make the applicant between 8 and 50 years old';
        } elseif (abs($computedAge - (int)$age) > 1) {
            $errors[] = 'Age does not match the birthdate';
        }
    }
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

if (empty($studentType) || !in_array($studentType, ['New Student', 'Transferee'], true)) {
    $errors[] = 'Valid student type is required';
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

// Enforce a sane file limit for uploaded documents.
$maxFiles = 5;

if (isset($_FILES['documents'])) {
    $fileCount = count($_FILES['documents']['name']);

    if ($fileCount > $maxFiles) {
        $errors[] = "You may only upload up to $maxFiles files.";
        $fileCount = $maxFiles;
    }
    
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

        // Strict validation: Reject double extensions (e.g., .php.jpg)
        if (substr_count($fileName, '.') > 1) {
            $errors[] = "File name cannot contain multiple extensions: $fileName";
            continue;
        }

        // Strict MIME type checking
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $fileTmpName);
        finfo_close($finfo);

        if (in_array($mimeType, ['image/jpeg', 'image/png'], true)) {
            $imageInfo = @getimagesize($fileTmpName);
            if ($imageInfo === false) {
                $errors[] = "Invalid or corrupted image file: $fileName";
                continue;
            }
        }

        $allowedMimeTypes = [
            'pdf' => 'application/pdf',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png'
        ];

        if (!isset($allowedMimeTypes[$fileExtension]) || $allowedMimeTypes[$fileExtension] !== $mimeType) {
            $errors[] = "Invalid file content detected for: $fileName";
            continue;
        }

        // Perform security checks based on extension
        if ($fileExtension === 'pdf') {
            if (!baa_validate_pdf_security($fileTmpName)) {
                $errors[] = "Security threat detected in PDF file: $fileName";
                continue;
            }
        } else {
            // It's image/jpg/png/jpeg
            if (!baa_sanitize_image($fileTmpName, $fileExtension)) {
                $errors[] = "Invalid or corrupted image file: $fileName";
                continue;
            }
            // Update the file size since re-encoding changes it
            $fileSize = filesize($fileTmpName);
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
    
    // Insert enrollment record with the new columns
    $stmt = $pdo->prepare("
        INSERT INTO enrollments 
        (first_name, middle_name, last_name, suffix, age, gender, birthdate, email, phone, grade_level, lrn, strand, student_type, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
    ");
    
    $stmt->execute([
        $firstName, $middleName, $lastName, $suffix,
        $age, $gender, $birthdate, $email, $phone, $grade, $lrn, $strand, $studentType
    ]);
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

    // Prepare data for PDF receipt
    $enrollmentData = [
        'first_name'  => $firstName,
        'middle_name' => $middleName,
        'last_name'   => $lastName,
        'suffix'      => $suffix,
        'age'         => $age,
        'gender'      => $gender,
        'birthdate'   => $birthdate,
        'grade_level' => $grade,
        'lrn'         => $lrn,
        'strand'      => $strand,
        'email'       => $email,
        'phone'       => $phone
    ];
    
    $receiptPath = generatePDFReceipt($enrollmentData, $enrollmentId);
    
    // Update enrollment with receipt path
    $updateStmt = $pdo->prepare("UPDATE enrollments SET receipt_path = ? WHERE id = ?");
    $updateStmt->execute([$receiptPath, $enrollmentId]);

    // Build full name for email
    $normalizedSuffix = baa_normalize_name_part($suffix);
    $fullName = baa_build_full_name([$firstName, $middleName, $lastName]);
    if ($normalizedSuffix !== '') {
        $fullName .= ', ' . $normalizedSuffix;
    }

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

    // Add notification for Admin and Registrar
    try {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE role IN ('admin', 'registrar')");
        $stmt->execute();
        $staffIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $notifStmt = $pdo->prepare("INSERT INTO notifications (user_id, type, message, link) VALUES (?, 'enrollment', ?, 'home')");
        foreach ($staffIds as $staffId) {
            $notifStmt->execute([$staffId, "New enrollment request from $fullName (Grade $grade)"]);
        }
    } catch (Exception $e) {
        error_log("Notification error: " . $e->getMessage());
    }

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