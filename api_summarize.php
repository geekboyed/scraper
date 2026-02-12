<?php
/**
 * API Endpoint - Trigger summarizer asynchronously
 */

header('Content-Type: application/json');

// Prevent timeout
set_time_limit(0);

// Start summarizer in background
$output_file = __DIR__ . '/logs/summarize_' . date('YmdHis') . '.log';
$command = "cd " . escapeshellarg(__DIR__) . " && python3 summarizer_parallel.py 20 5 > " . escapeshellarg($output_file) . " 2>&1 &";

// Execute in background
exec($command, $output, $return_code);

// Return immediate response
echo json_encode([
    'success' => true,
    'message' => 'Summarizer started in background',
    'log_file' => basename($output_file)
]);
