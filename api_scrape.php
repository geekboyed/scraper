<?php
/**
 * API Endpoint - Trigger scraper and return results
 */

header('Content-Type: application/json');

// Prevent timeout
set_time_limit(300); // 5 minutes max

// Load environment
require_once __DIR__ . '/config.php';

// Run scraper and capture output
$log_dir = getenv('LOG_DIR') ?: '/var/log/scraper';
$output_file = $log_dir . '/scrape_' . date('YmdHis') . '.log';
$command = "cd " . escapeshellarg(__DIR__) . " && ./run_scrape.sh 2>&1 | tee " . escapeshellarg($output_file);

// Execute and wait for completion
exec($command, $output, $return_code);

// Parse output to find number of new articles
$new_articles = 0;
$output_text = implode("\n", $output);

// Look for "TOTAL: X new articles" pattern
if (preg_match('/TOTAL:\s*(\d+)\s*new articles?/i', $output_text, $matches)) {
    $new_articles = (int)$matches[1];
}

// Return response with article count
echo json_encode([
    'success' => true,
    'message' => 'Scraper completed',
    'new_articles' => $new_articles,
    'log_file' => basename($output_file)
]);
