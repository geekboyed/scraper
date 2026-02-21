<?php
/**
 * API: Get all categories with hierarchy
 */
require_once 'config.php';

header('Content-Type: application/json');

try {
    // Fetch all categories ordered by level and parent
    $query = "SELECT id, name, description, level, parentID
              FROM categories
              ORDER BY level ASC, parentID ASC, name ASC";

    $stmt = $conn->query($query);
    $categories = [];

    while ($row = $stmt->fetch()) {
        $categories[] = [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'description' => $row['description'],
            'level' => (int)$row['level'],
            'parentID' => $row['parentID'] ? (int)$row['parentID'] : null
        ];
    }

    echo json_encode([
        'success' => true,
        'categories' => $categories,
        'count' => count($categories)
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $e->getMessage()
    ]);
}

$conn = null;
?>
