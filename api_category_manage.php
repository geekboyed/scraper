<?php
/**
 * API: Manage categories (Create, Update, Delete)
 */
require_once 'config.php';
require_once 'auth_check.php';

header('Content-Type: application/json');

// Only admins can manage categories
if (!$current_user || $current_user['isAdmin'] !== 'Y') {
    echo json_encode([
        'success' => false,
        'error' => 'Unauthorized: Admin access required'
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'error' => 'Invalid request method'
    ]);
    exit;
}

$action = $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'create':
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $level = (int)($_POST['level'] ?? 2);
            $parentID = !empty($_POST['parentID']) ? (int)$_POST['parentID'] : null;

            // Validation
            if (empty($name)) {
                throw new Exception('Category name is required');
            }

            if ($level === 2 && $parentID === null) {
                throw new Exception('Parent category is required for level 2 categories');
            }

            if ($level === 1 && $parentID !== null) {
                $parentID = null; // Level 1 categories cannot have parents
            }

            // Check for duplicate name
            $stmt = $conn->prepare("SELECT id FROM categories WHERE name = ? AND level = ?");
            $stmt->execute([$name, $level]);
            if ($stmt->fetch()) {
                throw new Exception('A category with this name already exists at this level');
            }

            // Insert new category
            $stmt = $conn->prepare("INSERT INTO categories (name, description, level, parentID) VALUES (?, ?, ?, ?)");

            if ($stmt->execute([$name, $description, $level, $parentID])) {
                $newId = $conn->lastInsertId();
                echo json_encode([
                    'success' => true,
                    'message' => 'Category added successfully',
                    'id' => $newId
                ]);
            } else {
                throw new Exception('Failed to insert category');
            }
            break;

        case 'update':
            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $level = (int)($_POST['level'] ?? 2);
            $parentID = !empty($_POST['parentID']) ? (int)$_POST['parentID'] : null;

            // Validation
            if ($id <= 0) {
                throw new Exception('Invalid category ID');
            }

            if (empty($name)) {
                throw new Exception('Category name is required');
            }

            if ($level === 2 && $parentID === null) {
                throw new Exception('Parent category is required for level 2 categories');
            }

            if ($level === 1 && $parentID !== null) {
                $parentID = null; // Level 1 categories cannot have parents
            }

            // Check if category exists
            $stmt = $conn->prepare("SELECT id FROM categories WHERE id = ?");
            $stmt->execute([$id]);
            if (!$stmt->fetch()) {
                throw new Exception('Category not found');
            }

            // Check for duplicate name (excluding current category)
            $stmt = $conn->prepare("SELECT id FROM categories WHERE name = ? AND level = ? AND id != ?");
            $stmt->execute([$name, $level, $id]);
            if ($stmt->fetch()) {
                throw new Exception('A category with this name already exists at this level');
            }

            // Prevent circular reference
            if ($parentID === $id) {
                throw new Exception('A category cannot be its own parent');
            }

            // Update category
            $stmt = $conn->prepare("UPDATE categories SET name = ?, description = ?, level = ?, parentID = ? WHERE id = ?");

            if ($stmt->execute([$name, $description, $level, $parentID, $id])) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Category updated successfully'
                ]);
            } else {
                throw new Exception('Failed to update category');
            }
            break;

        case 'delete':
            $id = (int)($_POST['id'] ?? 0);

            // Validation
            if ($id <= 0) {
                throw new Exception('Invalid category ID');
            }

            // Check if category exists
            $stmt = $conn->prepare("SELECT name, level FROM categories WHERE id = ?");
            $stmt->execute([$id]);
            $category = $stmt->fetch();
            if (!$category) {
                throw new Exception('Category not found');
            }

            // Check if category has articles
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM article_categories WHERE category_id = ?");
            $stmt->execute([$id]);
            $articleCount = $stmt->fetch()['count'];

            if ($articleCount > 0) {
                throw new Exception("Cannot delete category '{$category['name']}': it has {$articleCount} associated articles. Please reassign the articles first.");
            }

            // Check if it has children (will be handled by CASCADE, but warn user)
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM categories WHERE parentID = ?");
            $stmt->execute([$id]);
            $childCount = $stmt->fetch()['count'];

            // Delete category (CASCADE will delete children if any)
            $stmt = $conn->prepare("DELETE FROM categories WHERE id = ?");

            if ($stmt->execute([$id])) {
                $message = $childCount > 0
                    ? "Category deleted successfully (including {$childCount} subcategories)"
                    : 'Category deleted successfully';

                echo json_encode([
                    'success' => true,
                    'message' => $message
                ]);
            } else {
                throw new Exception('Failed to delete category');
            }
            break;

        default:
            throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

$conn = null;
?>
