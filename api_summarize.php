<?php
/**
 * API Endpoint - Trigger summarizer asynchronously
 */

header('Content-Type: application/json');

// Load environment
require_once __DIR__ . '/config.php';

// Prevent timeout
set_time_limit(0);

// Start summarizer in background using wrapper script
$wrapper = __DIR__ . '/start_summarizer.sh';
$log_file = 'summarize_' . date('YmdHis') . '.log';

// Execute wrapper and capture output
$output = shell_exec($wrapper . ' 2>&1');

// Log any errors for debugging
$log_dir = getenv('LOG_DIR') ?: '/var/log/scraper';
if ($output && strpos($output, 'Permission denied') !== false) {
    file_put_contents($log_dir . '/api_error.log', date('Y-m-d H:i:s') . " - " . $output . "\n", FILE_APPEND);
}

// Return immediate response
echo json_encode([
    'success' => true,
    'message' => 'Summarizer started in background',
    'log_file' => $log_file
]);
