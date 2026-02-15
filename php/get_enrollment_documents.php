<?php
session_start();
require_once 'db.php';

// Check if user is admin or super_admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$enrollmentId = $_GET['enrollment_id'] ?? '';

if (!$enrollmentId) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Missing enrollment ID']);
    exit();
}

try {
    // First get the enrollment info
    $enrollStmt = $pdo->prepare("
        SELECT id, full_name 
        FROM enrollments 
        WHERE id = ?
    ");
    $enrollStmt->execute([$enrollmentId]);
    $enrollment = $enrollStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$enrollment) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Enrollment not found']);
        exit();
    }
    
    $stmt = $pdo->prepare("
        SELECT 
            id,
            enrollment_id,
            document_filename,
            document_path,
            file_size,
            created_at
        FROM enrollment_documents 
        WHERE enrollment_id = ?
        ORDER BY created_at
    ");
    $stmt->execute([$enrollmentId]);
    $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Verify files exist and build correct paths
    $baseDir = __DIR__ . '/..';
    $enrollmentsDir = $baseDir . '/enrollments';
    $validDocuments = [];
    $studentName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $enrollment['full_name']);
    
    // If no documents in database, check the filesystem directly
    if (empty($documents)) {
        // Look for directories that might contain files for this enrollment
        if (is_dir($enrollmentsDir)) {
            $dirs = scandir($enrollmentsDir);
            foreach ($dirs as $dir) {
                if ($dir === '.' || $dir === '..' || !is_dir($enrollmentsDir . '/' . $dir)) {
                    continue;
                }
                
                // Check if directory name starts with the student name or contains the enrollment ID
                if (strpos($dir, $studentName) === 0 || strpos($dir, (string)$enrollmentId) !== false) {
                    $studentDir = $enrollmentsDir . '/' . $dir;
                    $files = scandir($studentDir);
                    
                    foreach ($files as $file) {
                        if ($file === '.' || $file === '..' || is_dir($studentDir . '/' . $file)) {
                            continue;
                        }
                        
                        // Determine document type from filename
                        $docType = 'Document';
                        $lowerFile = strtolower($file);
                        
                        if (strpos($lowerFile, 'birth') !== false || strpos($lowerFile, 'birthcert') !== false) {
                            $docType = 'Birth Certificate';
                        } elseif (strpos($lowerFile, 'grade') !== false || strpos($lowerFile, 'report') !== false) {
                            $docType = 'Grade Report';
                        } elseif (strpos($lowerFile, 'good') !== false || strpos($lowerFile, 'moral') !== false) {
                            $docType = 'Good Moral Character';
                        } elseif (strpos($lowerFile, 'id') !== false || strpos($lowerFile, 'picture') !== false || strpos($lowerFile, 'photo') !== false) {
                            $docType = 'ID Picture';
                        } elseif (strpos($lowerFile, 'form') !== false || strpos($lowerFile, 'enrollment') !== false) {
                            $docType = 'Enrollment Form';
                        } elseif (strpos($lowerFile, 'psa') !== false) {
                            $docType = 'PSA Birth Certificate';
                        } elseif (strpos($lowerFile, 'report') !== false || strpos($lowerFile, 'card') !== false) {
                            $docType = 'Report Card';
                        }
                        
                        $filePath = 'enrollments/' . $dir . '/' . $file;
                        $fileSize = filesize($studentDir . '/' . $file);
                        
                        $validDocuments[] = [
                            'id' => count($validDocuments) + 1,
                            'enrollment_id' => $enrollmentId,
                            'document_filename' => $file,
                            'document_path' => $filePath,
                            'document_type' => $docType,
                            'file_size' => $fileSize,
                            'created_at' => date('Y-m-d H:i:s', filemtime($studentDir . '/' . $file))
                        ];
                    }
                }
            }
        }
    } else {
        // Process database documents
        foreach ($documents as $doc) {
            $filename = basename($doc['document_path']);
            $found = false;
            
            // Try the exact stored path first
            $fullPath = $baseDir . '/' . $doc['document_path'];
            if (file_exists($fullPath)) {
                // Determine document type from filename
                $docType = 'Document';
                $lowerFile = strtolower($doc['document_filename']);
                
                if (strpos($lowerFile, 'birth') !== false || strpos($lowerFile, 'birthcert') !== false) {
                    $docType = 'Birth Certificate';
                } elseif (strpos($lowerFile, 'grade') !== false || strpos($lowerFile, 'report') !== false) {
                    $docType = 'Grade Report';
                } elseif (strpos($lowerFile, 'good') !== false || strpos($lowerFile, 'moral') !== false) {
                    $docType = 'Good Moral Character';
                } elseif (strpos($lowerFile, 'id') !== false || strpos($lowerFile, 'picture') !== false) {
                    $docType = 'ID Picture';
                } elseif (strpos($lowerFile, 'form') !== false) {
                    $docType = 'Enrollment Form';
                }
                
                $doc['document_type'] = $docType;
                $validDocuments[] = $doc;
                $found = true;
            }
            
            // If not found, search in all subdirectories matching the student name
            if (!$found && is_dir($enrollmentsDir)) {
                $dirs = scandir($enrollmentsDir);
                foreach ($dirs as $dir) {
                    if ($dir === '.' || $dir === '..' || !is_dir($enrollmentsDir . '/' . $dir)) {
                        continue;
                    }
                    
                    // Check if directory name starts with the student name
                    if (strpos($dir, $studentName) === 0) {
                        $filePath = $enrollmentsDir . '/' . $dir . '/' . $filename;
                        if (file_exists($filePath)) {
                            // Update the path for the response
                            $doc['document_path'] = 'enrollments/' . $dir . '/' . $filename;
                            
                            // Determine document type from filename
                            $docType = 'Document';
                            $lowerFile = strtolower($doc['document_filename']);
                            
                            if (strpos($lowerFile, 'birth') !== false || strpos($lowerFile, 'birthcert') !== false) {
                                $docType = 'Birth Certificate';
                            } elseif (strpos($lowerFile, 'grade') !== false || strpos($lowerFile, 'report') !== false) {
                                $docType = 'Grade Report';
                            } elseif (strpos($lowerFile, 'good') !== false || strpos($lowerFile, 'moral') !== false) {
                                $docType = 'Good Moral Character';
                            } elseif (strpos($lowerFile, 'id') !== false || strpos($lowerFile, 'picture') !== false) {
                                $docType = 'ID Picture';
                            } elseif (strpos($lowerFile, 'form') !== false) {
                                $docType = 'Enrollment Form';
                            }
                            
                            $doc['document_type'] = $docType;
                            $validDocuments[] = $doc;
                            $found = true;
                            break;
                        }
                    }
                }
            }
        }
    }
    
    // Output the JSON response
    header('Content-Type: application/json');
    if (empty($validDocuments)) {
        echo json_encode([
            'success' => true, 
            'documents' => [],
            'message' => 'No documents found for this enrollment'
        ]);
    } else {
        echo json_encode([
            'success' => true, 
            'documents' => $validDocuments,
            'count' => count($validDocuments)
        ]);
    }
    
} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>