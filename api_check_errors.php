<?php
/**
 * API Endpoint - Check logs for blocking errors
 */

header('Content-Type: application/json');

$logs_dir = __DIR__ . '/logs';
$errors = [];
$error_patterns = [
    '401' => 'Unauthorized',
    '403' => 'Forbidden',
    '429' => 'Rate Limited',
    'blocked' => 'Blocked',
    'captcha' => 'CAPTCHA',
    'Access Denied' => 'Access Denied',
    'bot detection' => 'Bot Detection',
    'Error' => 'Error',
    'Failed' => 'Failed',
    'timeout' => 'Timeout'
];

// Get latest log files
$log_files = [
    'scrape.log',
    'summarize.log'
];

// Add timestamped logs from last 24 hours
$all_logs = scandir($logs_dir);
$cutoff_time = time() - (24 * 60 * 60); // 24 hours ago

foreach ($all_logs as $file) {
    if (preg_match('/\.(log)$/', $file)) {
        $filepath = $logs_dir . '/' . $file;
        if (filemtime($filepath) >= $cutoff_time) {
            $log_files[] = $file;
        }
    }
}

$log_files = array_unique($log_files);

// Scan each log file
foreach ($log_files as $log_file) {
    $filepath = $logs_dir . '/' . $log_file;

    if (!file_exists($filepath)) {
        continue;
    }

    // Get last 500 lines of log
    $lines = [];
    $file_handle = fopen($filepath, 'r');

    if ($file_handle) {
        // Read file in chunks from the end
        fseek($file_handle, -1, SEEK_END);
        $line_count = 0;
        $pos = ftell($file_handle);
        $lines_arr = [];

        while ($pos >= 0 && $line_count < 500) {
            $char = fgetc($file_handle);
            if ($char === "\n" || $pos === 0) {
                if (!empty($current_line)) {
                    $lines_arr[] = strrev($current_line);
                    $line_count++;
                    $current_line = '';
                }
            } else {
                $current_line .= $char;
            }
            $pos--;
            if ($pos >= 0) {
                fseek($file_handle, $pos, SEEK_SET);
            }
        }

        fclose($file_handle);

        // Simpler approach: just read last 50KB
        $content = file_get_contents($filepath, false, null, -50000);
        $lines = explode("\n", $content);

        // Check each line for error patterns
        foreach ($lines as $line_num => $line) {
            $line_lower = strtolower($line);

            // Skip non-error informational messages
            $skip_patterns = [
                'already exists',
                'duplicate',
                'skipping',
                'no articles need',
                'successfully processed',
                'saved to database'
            ];

            $should_skip = false;
            foreach ($skip_patterns as $skip) {
                if (stripos($line, $skip) !== false) {
                    $should_skip = true;
                    break;
                }
            }

            if ($should_skip) {
                continue;
            }

            foreach ($error_patterns as $pattern => $label) {
                if (stripos($line, $pattern) !== false) {
                    // Extract context (timestamp if available)
                    $timestamp = 'Unknown';
                    if (preg_match('/(\d{4}-\d{2}-\d{2}[T\s]\d{2}:\d{2}:\d{2})/', $line, $matches)) {
                        $timestamp = $matches[1];
                    }

                    // Extract source name from URL
                    $source = 'Unknown';
                    if (preg_match('/(businessinsider\.com|marketwatch\.com|reuters\.com|bloomberg\.com|wsj\.com)/i', $line, $matches)) {
                        $domain_map = [
                            'businessinsider.com' => 'Business Insider',
                            'marketwatch.com' => 'MarketWatch',
                            'reuters.com' => 'Reuters',
                            'bloomberg.com' => 'Bloomberg',
                            'wsj.com' => 'Wall Street Journal'
                        ];
                        $domain = strtolower($matches[1]);
                        $source = $domain_map[$domain] ?? ucfirst(str_replace('.com', '', $domain));
                    }

                    // Generate helpful description
                    $description = '';
                    switch ($pattern) {
                        case '401':
                            $description = 'Authentication required - API key may be invalid or missing';
                            break;
                        case '403':
                            $description = 'Access forbidden - IP blocked or permissions issue';
                            break;
                        case '429':
                            $description = 'Rate limit exceeded - too many requests to API/website';
                            break;
                        case 'blocked':
                            $description = 'Request blocked - possible bot detection or firewall';
                            break;
                        case 'captcha':
                            $description = 'CAPTCHA challenge detected - human verification required';
                            break;
                        case 'Access Denied':
                            $description = 'Access denied - check credentials or IP whitelist';
                            break;
                        case 'bot detection':
                            $description = 'Bot detection triggered - may need different user agent or proxy';
                            break;
                        case 'timeout':
                            $description = 'Request timeout - server not responding or network issue';
                            break;
                        case 'Error':
                            $description = 'General error - check log details for specifics';
                            break;
                        case 'Failed':
                            $description = 'Operation failed - see log line for details';
                            break;
                        default:
                            $description = 'Issue detected - review log line for details';
                    }

                    $errors[] = [
                        'file' => $log_file,
                        'type' => $label,
                        'source' => $source,
                        'description' => $description,
                        'line' => trim($line),
                        'timestamp' => $timestamp,
                        'severity' => in_array($pattern, ['401', '403', '429', 'blocked', 'captcha']) ? 'high' : 'medium'
                    ];

                    break; // Only count once per line
                }
            }
        }
    }
}

// Remove duplicates and limit
$errors = array_slice(array_unique($errors, SORT_REGULAR), 0, 100);

// Sort by severity
usort($errors, function($a, $b) {
    if ($a['severity'] === $b['severity']) {
        return 0;
    }
    return ($a['severity'] === 'high') ? -1 : 1;
});

// Count by type
$error_counts = [];
foreach ($errors as $error) {
    $type = $error['type'];
    if (!isset($error_counts[$type])) {
        $error_counts[$type] = 0;
    }
    $error_counts[$type]++;
}

echo json_encode([
    'success' => true,
    'total_errors' => count($errors),
    'error_counts' => $error_counts,
    'errors' => $errors,
    'checked_files' => count($log_files),
    'timestamp' => date('Y-m-d H:i:s')
]);
