<?php
/**
 * API Endpoint - Trigger scraper asynchronously
 */

header('Content-Type: application/json');

// Prevent timeout
set_time_limit(0);

// Start scraper in background
$output_file = __DIR__ . '/logs/scrape_' . date('YmdHis') . '.log';
$command = "cd " . escapeshellarg(__DIR__) . " && ./run_scrape.sh > " . escapeshellarg($output_file) . " 2>&1 &";

// Execute in background
exec($command, $output, $return_code);

// Return immediate response
echo json_encode([
    'success' => true,
    'message' => 'Scraper started in background',
    'log_file' => basename($output_file)
]);
