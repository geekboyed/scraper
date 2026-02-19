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

    $result = $conn->query($query);

    if ($result) {
        $categories = [];

        while ($row = $result->fetch_assoc()) {
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
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Database query failed: ' . $conn->error
        ]);
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $e->getMessage()
    ]);
}

$conn->close();
?>
