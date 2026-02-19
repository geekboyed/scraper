<?php
/**
 * API endpoint to get user preferences
 * Returns JSON with preferred sources and categories
 */

header('Content-Type: application/json');

require_once 'config.php';
require_once 'auth_check.php';

// Check if user is authenticated
if (!$current_user) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    // Get user's preferences
    $user_id = $current_user['id'];
    $stmt = $conn->prepare("SELECT preferenceJSON FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    // Parse preferences JSON
    $preferences = null;
    if ($user && $user['preferenceJSON']) {
        $preferences = json_decode($user['preferenceJSON'], true);
    }

    // Get sources visible to this user
    if ($current_user['isAdmin'] == 'Y') {
        $sources_result = $conn->query("SELECT id, name FROM sources WHERE enabled = 1 ORDER BY name");
    } else {
        $vis_stmt = $conn->prepare("SELECT id, name FROM sources WHERE enabled = 1 AND (isBase = 'Y' OR (isBase = 'N' AND id IN (SELECT source_id FROM users_sources WHERE user_id = ?))) ORDER BY name");
        $vis_stmt->bind_param("i", $user_id);
        $vis_stmt->execute();
        $sources_result = $vis_stmt->get_result();
    }
    $sources = [];
    while ($source = $sources_result->fetch_assoc()) {
        $sources[] = $source;
    }

    // Get all categories
    $categories_result = $conn->query("SELECT id, name FROM categories ORDER BY name");
    $categories = [];
    while ($category = $categories_result->fetch_assoc()) {
        $categories[] = $category;
    }

    // Return response
    echo json_encode([
        'success' => true,
        'preferences' => $preferences,
        'sources' => $sources,
        'categories' => $categories
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

$conn->close();
?>
