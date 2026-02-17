<?php
/**
 * Trigger Scraper - Runs the scraper immediately
 */

// Set execution time limit
set_time_limit(120);

// Buffer output so we can show progress
ob_implicit_flush(true);
ob_end_flush();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scraping...</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .container {
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            max-width: 800px;
            width: 100%;
        }
        h1 { color: #333; margin-bottom: 20px; }
        .output {
            background: #000;
            color: #0f0;
            padding: 20px;
            border-radius: 5px;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            line-height: 1.6;
            max-height: 500px;
            overflow-y: auto;
            margin-bottom: 20px;
        }
        .output .success { color: #0f0; }
        .output .error { color: #f00; }
        .output .info { color: #0ff; }
        .btn {
            padding: 12px 25px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
        }
        .btn:hover { background: #5568d3; }
        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #667eea;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            display: inline-block;
            margin-right: 10px;
            vertical-align: middle;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .status {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
            font-size: 18px;
            color: #333;
        }
        .complete { display: none; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔄 Running Scraper</h1>

        <div class="status" id="status">
            <div class="spinner"></div>
            <span>Scraping articles...</span>
        </div>

        <div class="output" id="output">
<?php
flush();

// Change to scraper directory
chdir(__DIR__);

// Run the scraper
$command = "cd " . escapeshellarg(__DIR__) . " && ./run_scrape.sh 2>&1";
$handle = popen($command, 'r');

if ($handle) {
    while (!feof($handle)) {
        $line = fgets($handle);
        if ($line) {
            // Color code output
            if (strpos($line, '✓') !== false || strpos($line, 'Successfully') !== false) {
                echo '<span class="success">' . htmlspecialchars($line) . '</span>';
            } elseif (strpos($line, '✗') !== false || strpos($line, 'Error') !== false) {
                echo '<span class="error">' . htmlspecialchars($line) . '</span>';
            } else {
                echo htmlspecialchars($line) . "\n";
            }
            flush();
        }
    }
    $return_code = pclose($handle);

    if ($return_code === 0) {
        echo '<span class="success">✓ Scraping completed successfully!</span>' . "\n";
    } else {
        echo '<span class="error">✗ Scraping completed with errors (exit code: ' . $return_code . ')</span>' . "\n";
    }
} else {
    echo '<span class="error">✗ Failed to start scraper</span>' . "\n";
}

flush();
?>
        </div>

        <script>
            document.getElementById('status').innerHTML = '<span style="color: #28a745; font-weight: bold;">✓ Complete!</span>';
        </script>

        <div class="complete">
            <a href="index.php" class="btn">← Back to Dashboard</a>
            <a href="scrape_now.php" class="btn" style="background: #28a745;">Run Again</a>
        </div>

        <script>
            document.querySelector('.complete').style.display = 'block';
            // Auto-scroll output to bottom
            var output = document.getElementById('output');
            output.scrollTop = output.scrollHeight;
        </script>
    </div>
</body>
</html>
