<?php
/**
 * API endpoint to save user preferences
 * Accepts POST with source IDs and category IDs
 */

header('Content-Type: application/json');

require_once 'config.php';
require_once 'auth_check.php';

// Check if user is authenticated
if (!$current_user) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    // Get POST data
    $post_data = file_get_contents('php://input');
    $data = json_decode($post_data, true);

    if (!$data) {
        echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
        exit;
    }

    // Extract sources, categories, and defaultView
    $sources = isset($data['sources']) ? array_map('intval', $data['sources']) : [];
    $categories = isset($data['categories']) ? array_map('intval', $data['categories']) : [];
    $defaultView = isset($data['defaultView']) ? $data['defaultView'] : 'all';

    // Validate defaultView dynamically from level 1 categories
    $valid_views = ['all'];
    $views_result = $conn->query("SELECT LOWER(name) AS view_name FROM categories WHERE level = 1 ORDER BY name");
    while ($view_row = $views_result->fetch()) {
        $valid_views[] = $view_row['view_name'];
    }
    if (!in_array($defaultView, $valid_views)) {
        $defaultView = 'all';
    }

    // Create preferences JSON
    // If both arrays are empty and defaultView is 'all', set to NULL (show all)
    if (empty($sources) && empty($categories) && $defaultView === 'all') {
        $preferences_json = null;
    } else {
        $preferences = [
            'sources' => $sources,
            'categories' => $categories,
            'defaultView' => $defaultView
        ];
        $preferences_json = json_encode($preferences);
    }

    // Update user's preferences
    $user_id = $current_user['id'];
    $stmt = $conn->prepare("UPDATE users SET preferenceJSON = ? WHERE id = ?");

    if ($stmt->execute([$preferences_json, $user_id])) {
        echo json_encode(['success' => true, 'message' => 'Preferences saved successfully']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to save preferences']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

$conn = null;
?>
