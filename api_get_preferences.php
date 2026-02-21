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
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    // Parse preferences JSON
    $preferences = null;
    if ($user && $user['preferenceJSON']) {
        $preferences = json_decode($user['preferenceJSON'], true);
    }

    // Get sources visible to this user
    if ($current_user['isAdmin'] == 'Y') {
        $sources_result = $conn->query("SELECT id, name FROM sources WHERE isActive = 'Y' ORDER BY name");
    } else {
        $vis_stmt = $conn->prepare("SELECT id, name FROM sources WHERE isActive = 'Y' AND (isBase = 'Y' OR (isBase = 'N' AND id IN (SELECT source_id FROM users_sources WHERE user_id = ?))) ORDER BY name");
        $vis_stmt->execute([$user_id]);
        $sources_result = $vis_stmt;
    }
    $sources = [];
    while ($source = $sources_result->fetch()) {
        $sources[] = $source;
    }

    // Get level 1 (parent) categories
    $parent_result = $conn->query("SELECT id, name FROM categories WHERE level = 1 ORDER BY name");
    $parent_categories = [];
    while ($parent = $parent_result->fetch()) {
        $parent_categories[] = $parent;
    }

    // Get level 2 (child) categories for preference checkboxes
    $categories_result = $conn->query("SELECT id, name, parentID FROM categories WHERE level = 2 ORDER BY name");
    $categories = [];
    while ($category = $categories_result->fetch()) {
        $categories[] = $category;
    }

    // Return response
    echo json_encode([
        'success' => true,
        'preferences' => $preferences,
        'sources' => $sources,
        'categories' => $categories,
        'parent_categories' => $parent_categories
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

$conn = null;
?>
