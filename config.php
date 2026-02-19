<?php
/**
 * Database Configuration
 */

// Load environment variables from .env file
function loadEnv($path) {
    if (!file_exists($path)) {
        die("Error: .env file not found at $path");
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Skip comments
        if (strpos(trim($line), '#') === 0) {
            continue;
        }

        // Parse key=value
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Remove quotes if present
            $value = trim($value, '"\'');

            // Set as environment variable
            if (!array_key_exists($key, $_ENV)) {
                $_ENV[$key] = $value;
                putenv("$key=$value");
            }
        }
    }
}

// Load .env file
loadEnv(__DIR__ . '/.env');

// Load AI configuration from user's home directory
$ai_env = getenv('HOME') . '/.env_AI';
if (file_exists($ai_env)) {
    loadEnv($ai_env);
}

// Database credentials
$db_host = getenv('DB_HOST') ?: 'localhost';
$db_name = getenv('DB_NAME') ?: 'scrapeDB';
$db_user = getenv('DB_USER') ?: 'root';
$db_pass = getenv('DB_PASS') ?: '';

// Create connection
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set charset
$conn->set_charset("utf8mb4");

// Set timezone to PST
$conn->query("SET time_zone = '-08:00'");

// Set PHP timezone to PST
date_default_timezone_set('America/Los_Angeles');

// ============================================================
// Category Hierarchy Helper Functions
// ============================================================

/**
 * Get a parent (level 1) category's ID by name.
 *
 * @param mysqli $conn  Database connection
 * @param string $name  Parent category name (e.g. 'Business', 'Technology')
 * @return int|null     Category ID or null if not found
 */
function get_parent_category_id($conn, $name) {
    $stmt = $conn->prepare("SELECT id FROM categories WHERE name = ? AND level = 1 LIMIT 1");
    $stmt->bind_param("s", $name);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row ? (int)$row['id'] : null;
}

/**
 * Get all child (level 2) category IDs for a given parent category.
 *
 * @param mysqli $conn      Database connection
 * @param int    $parent_id Parent category ID
 * @return array            Array of child category IDs
 */
function get_child_category_ids($conn, $parent_id) {
    $stmt = $conn->prepare("SELECT id FROM categories WHERE parentID = ? AND level = 2 ORDER BY name");
    $stmt->bind_param("i", $parent_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $ids = [];
    while ($row = $result->fetch_assoc()) {
        $ids[] = (int)$row['id'];
    }
    $stmt->close();
    return $ids;
}

/**
 * Get all level 1 (parent) categories.
 *
 * @param mysqli $conn  Database connection
 * @return array        Array of associative arrays with 'id', 'name', 'description'
 */
function get_level1_categories($conn) {
    $result = $conn->query("SELECT id, name, description FROM categories WHERE level = 1 ORDER BY name");
    $categories = [];
    while ($row = $result->fetch_assoc()) {
        $row['id'] = (int)$row['id'];
        $categories[] = $row;
    }
    return $categories;
}

/**
 * Get all level 2 (child) categories grouped by parent.
 * Returns an associative array keyed by parent ID.
 *
 * @param mysqli $conn  Database connection
 * @return array        [parent_id => [child_category, ...], ...]
 */
function get_categories_grouped($conn) {
    $result = $conn->query(
        "SELECT c.id, c.name, c.parentID, p.name AS parent_name
         FROM categories c
         LEFT JOIN categories p ON c.parentID = p.id
         WHERE c.level = 2
         ORDER BY p.name, c.name"
    );
    $grouped = [];
    while ($row = $result->fetch_assoc()) {
        $parent_id = (int)$row['parentID'];
        $grouped[$parent_id][] = [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'parent_name' => $row['parent_name']
        ];
    }
    return $grouped;
}
?>
