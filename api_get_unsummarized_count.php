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

$stmt = $conn->query($query);
$count = $stmt->fetch()['count'];

echo json_encode([
    'success' => true,
    'count' => (int)$count
]);

$conn = null;
