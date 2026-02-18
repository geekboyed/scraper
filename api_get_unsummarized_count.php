<?php
/**
 * API Endpoint - Get count of unsummarized articles
 */

header('Content-Type: application/json');

require_once 'config.php';

// Get count of unsummarized articles (excluding failed)
$query = "SELECT COUNT(*) as count FROM articles
          WHERE (summary IS NULL OR summary = '')
          AND (isSummaryFailed IS NULL OR isSummaryFailed != 'Y')";

$result = $conn->query($query);
$count = $result->fetch_assoc()['count'];

echo json_encode([
    'success' => true,
    'count' => (int)$count
]);

$conn->close();
