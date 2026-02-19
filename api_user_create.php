<?php
/**
 * API Endpoint - Create a new user (admin only)
 */

header('Content-Type: application/json');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_check.php';

// Require admin
if (!$current_user || $current_user['isAdmin'] != 'Y') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin access required']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$username = trim($_POST['username'] ?? '');
$email = trim($_POST['email'] ?? '');
$isAdmin = isset($_POST['isAdmin']) && $_POST['isAdmin'] === 'Y' ? 'Y' : 'N';
$isActive = isset($_POST['isActive']) && $_POST['isActive'] === 'N' ? 'N' : 'Y';

// Validate inputs
if (empty($username)) {
    echo json_encode(['success' => false, 'error' => 'Username is required']);
    exit;
}

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'error' => 'Valid email is required']);
    exit;
}

if (strlen($username) < 3 || strlen($username) > 50) {
    echo json_encode(['success' => false, 'error' => 'Username must be 3-50 characters']);
    exit;
}

// Create user (no password auth in this system)
// Non-admin users get sourceCount=5 (remaining sources), admins get 0 (unlimited)
$sourceCount = ($isAdmin === 'Y') ? 0 : 5;
$stmt = $conn->prepare("INSERT INTO users (username, email, isAdmin, sourceCount, isActive) VALUES (?, ?, ?, ?, ?)");
$stmt->bind_param("sssis", $username, $email, $isAdmin, $sourceCount, $isActive);

try {
    if ($stmt->execute()) {
        $new_id = $conn->insert_id;
        // Fetch the created user
        $fetch = $conn->prepare("SELECT id, username, email, isAdmin, isActive, created_at, last_login FROM users WHERE id = ?");
        $fetch->bind_param("i", $new_id);
        $fetch->execute();
        $user = $fetch->get_result()->fetch_assoc();
        $fetch->close();

        echo json_encode(['success' => true, 'user' => $user]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to create user']);
    }
} catch (mysqli_sql_exception $e) {
    if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
        if (strpos($e->getMessage(), 'username') !== false) {
            echo json_encode(['success' => false, 'error' => 'Username already exists']);
        } elseif (strpos($e->getMessage(), 'email') !== false) {
            echo json_encode(['success' => false, 'error' => 'Email already exists']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Username or email already exists']);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
}

$stmt->close();
$conn->close();
