<?php
/**
 * API: Recategorize articles for a given level 1 category
 *
 * Executes recategorize_articles.py in the background and streams
 * progress back to the client via Server-Sent Events (SSE).
 *
 * Query params:
 *   category_id (int) - Level 1 category ID to recategorize
 */
require_once 'config.php';
require_once 'auth_check.php';

// Only admins can recategorize
if (!$current_user || $current_user['isAdmin'] !== 'Y') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Unauthorized: Admin access required'
    ]);
    exit;
}

$categoryId = (int)($_GET['category_id'] ?? 0);

if ($categoryId <= 0) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Invalid category ID'
    ]);
    exit;
}

// Validate that the category exists and is level 1
$stmt = $conn->prepare("SELECT id, name FROM categories WHERE id = ? AND level = 1");
$stmt->bind_param('i', $categoryId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Category not found or not a level 1 category'
    ]);
    $stmt->close();
    $conn->close();
    exit;
}

$category = $result->fetch_assoc();
$stmt->close();
$conn->close();

// Set up Server-Sent Events
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

// Disable output buffering
while (ob_get_level()) {
    ob_end_flush();
}

$scriptDir = __DIR__;
$scriptPath = $scriptDir . '/recategorize_articles.py';

if (!file_exists($scriptPath)) {
    echo "data: " . json_encode(['type' => 'error', 'message' => 'Recategorization script not found. Please ensure recategorize_articles.py exists.']) . "\n\n";
    flush();
    exit;
}

// Build the command - use venv python if available, otherwise system python3
$pythonBin = file_exists($scriptDir . '/venv/bin/python3')
    ? $scriptDir . '/venv/bin/python3'
    : 'python3';

$cmd = escapeshellcmd($pythonBin) . ' ' . escapeshellarg($scriptPath) . ' ' . escapeshellarg((string)$categoryId);

// Send start event
echo "data: " . json_encode([
    'type' => 'start',
    'message' => "Starting recategorization for \"{$category['name']}\"..."
]) . "\n\n";
flush();

// Open process and stream output
$descriptorSpec = [
    0 => ['pipe', 'r'],  // stdin
    1 => ['pipe', 'w'],  // stdout
    2 => ['pipe', 'w'],  // stderr
];

$env = null; // inherit environment
$process = proc_open($cmd, $descriptorSpec, $pipes, $scriptDir, $env);

if (!is_resource($process)) {
    echo "data: " . json_encode(['type' => 'error', 'message' => 'Failed to start recategorization process']) . "\n\n";
    flush();
    exit;
}

// Close stdin - we don't need it
fclose($pipes[0]);

// Make stdout non-blocking
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

$outputBuffer = '';
$errorBuffer = '';

while (true) {
    $stdout = fgets($pipes[1]);
    $stderr = fgets($pipes[2]);

    if ($stdout !== false && $stdout !== '') {
        $line = trim($stdout);
        if ($line !== '') {
            echo "data: " . json_encode(['type' => 'progress', 'message' => $line]) . "\n\n";
            flush();
        }
    }

    if ($stderr !== false && $stderr !== '') {
        $line = trim($stderr);
        if ($line !== '') {
            $errorBuffer .= $line . "\n";
        }
    }

    // Check if process has ended
    $status = proc_get_status($process);
    if (!$status['running']) {
        // Read any remaining output
        while (($line = fgets($pipes[1])) !== false) {
            $trimmed = trim($line);
            if ($trimmed !== '') {
                echo "data: " . json_encode(['type' => 'progress', 'message' => $trimmed]) . "\n\n";
                flush();
            }
        }
        while (($line = fgets($pipes[2])) !== false) {
            $errorBuffer .= trim($line) . "\n";
        }
        break;
    }

    // Small delay to avoid busy-waiting
    usleep(100000); // 100ms
}

fclose($pipes[1]);
fclose($pipes[2]);

$exitCode = $status['exitcode'];
proc_close($process);

if ($exitCode === 0) {
    echo "data: " . json_encode([
        'type' => 'complete',
        'message' => "Recategorization complete for \"{$category['name']}\"."
    ]) . "\n\n";
} else {
    $errorMsg = trim($errorBuffer) ?: "Process exited with code $exitCode";
    echo "data: " . json_encode([
        'type' => 'error',
        'message' => "Recategorization failed: $errorMsg"
    ]) . "\n\n";
}

flush();
?>
