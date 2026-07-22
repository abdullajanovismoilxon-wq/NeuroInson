<?php
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');

try {
    $outputDir = __DIR__ . '/../output';
    if (!is_dir($outputDir)) {
        echo json_encode(['status' => 'ok', 'deleted' => 0]);
        exit;
    }

    $now = time();
    $threshold = 3 * 24 * 60 * 60;
    $deleted = 0;

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($outputDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($files as $file) {
        if ($file->isFile() && ($now - $file->getMTime()) > $threshold) {
            unlink($file->getRealPath());
            $deleted++;
        }
    }

    echo json_encode(['status' => 'ok', 'deleted' => $deleted]);
}
