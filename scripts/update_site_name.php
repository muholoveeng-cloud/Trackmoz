<?php
/**
 * Script to update all occurrences of the old site name to the new site name
 * across all PHP files in the project.
 * 
 * This script should be run from the command line:
 * php scripts/update_site_name.php
 */

// Configuration
$rootDir = __DIR__ . '/..';
$oldName = 'TrackMoz';
$newName = 'TrackMoz';
$fileExtensions = ['php', 'html', 'js', 'css', 'md', 'sql'];

// Initialize counters
$filesScanned = 0;
$filesModified = 0;
$totalReplacements = 0;

// Function to process a single file
function processFile($filePath, $oldName, $newName, &$totalReplacements) {
    $content = file_get_contents($filePath);
    $originalContent = $content;
    
    // Replace old name with new name (case-sensitive)
    $content = str_replace($oldName, $newName, $content, $count1);
    
    // Replace lowercase version too
    $content = str_replace(strtolower($oldName), strtolower($newName), $content, $count2);
    
    $replacements = $count1 + $count2;
    $totalReplacements += $replacements;
    
    if ($content !== $originalContent) {
        file_put_contents($filePath, $content);
        echo "Updated: $filePath ($replacements replacements)\n";
        return true;
    }
    
    return false;
}

// Function to recursively scan directories
function scanDirectory($dir, $extensions, $oldName, $newName, &$filesScanned, &$filesModified, &$totalReplacements) {
    $files = scandir($dir);
    
    foreach ($files as $file) {
        // Skip . and ..
        if ($file === '.' || $file === '..') {
            continue;
        }
        
        $path = $dir . '/' . $file;
        
        if (is_dir($path)) {
            // Recursively scan subdirectories
            scanDirectory($path, $extensions, $oldName, $newName, $filesScanned, $filesModified, $totalReplacements);
        } else {
            // Check if file has one of the target extensions
            $extension = pathinfo($path, PATHINFO_EXTENSION);
            if (in_array(strtolower($extension), $extensions)) {
                $filesScanned++;
                if (processFile($path, $oldName, $newName, $totalReplacements)) {
                    $filesModified++;
                }
            }
        }
    }
}

// Start the process
echo "Starting site name update from '$oldName' to '$newName'...\n";
echo "Root directory: $rootDir\n";
echo "Scanning files...\n";

scanDirectory($rootDir, $fileExtensions, $oldName, $newName, $filesScanned, $filesModified, $totalReplacements);

echo "\nSummary:\n";
echo "Files scanned: $filesScanned\n";
echo "Files modified: $filesModified\n";
echo "Total replacements: $totalReplacements\n";
echo "Done!\n"; 