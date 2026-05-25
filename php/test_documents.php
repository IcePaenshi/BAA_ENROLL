<?php
// Quick test to verify documents are accessible
$baseDir = __DIR__ . '/..';
$enrollmentsDir = $baseDir . '/enrollments';

echo "=== Document Directory Test ===\n\n";

if (is_dir($enrollmentsDir)) {
    $dirs = scandir($enrollmentsDir);
    $count = 0;
    
    foreach ($dirs as $dir) {
        if ($dir === '.' || $dir === '..') continue;
        
        $fullDirPath = $enrollmentsDir . '/' . $dir;
        if (!is_dir($fullDirPath)) continue;
        
        $count++;
        echo "$count. Directory: $dir\n";
        
        $files = scandir($fullDirPath);
        $fileCount = 0;
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            $fileCount++;
            $filePath = $fullDirPath . '/' . $file;
            $size = filesize($filePath);
            echo "   - $file (" . round($size / 1024, 2) . " KB)\n";
        }
        
        if ($fileCount === 0) {
            echo "   - (empty)\n";
        }
    }
    
    echo "\nTotal directories: " . max(0, count($dirs) - 2) . "\n";
} else {
    echo "Enrollments directory not found!\n";
}
?>
