<?php

/**
 * File Security Helper Library for BAA School Portal.
 * Handles double-extension checks, image sanitization (GD library metadata stripping),
 * and static analysis of PDF files for execution streams.
 */

/**
 * Rejects double extensions or filenames with multiple dots to prevent execution bypasses.
 */
function baa_check_double_extension(string $filename): bool
{
    // Return true if filename has multiple dots
    return substr_count($filename, '.') > 1;
}

/**
 * Re-encodes JPEGs and PNGs using PHP GD to strip EXIF data, comments, and hidden payloads.
 */
function baa_sanitize_image(string $tmpPath, string $ext): bool
{
    if (!extension_loaded('gd')) {
        // GD is not loaded, fallback to checking image size or fail secure.
        // On modern PHP/XAMPP, GD is default. If missing, we'll try to load it or log.
        error_log("GD extension is not loaded. Skipping re-encoding but validating image size.");
        $size = @getimagesize($tmpPath);
        return ($size !== false);
    }

    $ext = strtolower($ext);
    if ($ext === 'jpg' || $ext === 'jpeg') {
        $img = @imagecreatefromjpeg($tmpPath);
        if (!$img) {
            return false;
        }
        $res = @imagejpeg($img, $tmpPath, 90);
        imagedestroy($img);
        return $res;
    } elseif ($ext === 'png') {
        $img = @imagecreatefrompng($tmpPath);
        if (!$img) {
            return false;
        }
        // Preserve transparency
        imagealphablending($img, false);
        imagesavealpha($img, true);
        $res = @imagepng($img, $tmpPath, 8);
        imagedestroy($img);
        return $res;
    }
    return false;
}

/**
 * Scans PDF files for active streams / dangerous execution keywords.
 */
function baa_validate_pdf_security(string $tmpPath): bool
{
    $content = @file_get_contents($tmpPath);
    if ($content === false) {
        return false;
    }

    // PDF specifications allow case-insensitive or obfuscated execution objects.
    // Check for standard PDF execution objects: /JavaScript, /JS, /Launch, /EmbeddedFiles, /OpenAction.
    // Use regex to look for these names preceded by a forward slash.
    $dangerous = [
        '/\/JavaScript/i',
        '/\/JS/i',
        '/\/Launch/i',
        '/\/EmbeddedFiles/i',
        '/\/OpenAction/i'
    ];

    foreach ($dangerous as $pattern) {
        if (preg_match($pattern, $content)) {
            return false; // File contains potentially malicious active content
        }
    }

    return true;
}
