<?php
/**
 * API Endpoint - Update a user (admin only)
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

$id = (int)($_POST['id'] ?? 0);
$action = $_POST['action'] ?? 'update';

if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Valid user ID is required']);
    exit;
}

// Handle toggle admin action
if ($action === 'toggle_admin') {
    // Prevent admin from removing their own admin status
    if ($id === (int)$current_user['id']) {
        echo json_encode(['success' => false, 'error' => 'Cannot change your own admin status']);
        exit;
    }

    $stmt = $conn->prepare("SELECT isAdmin FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $user = $stmt->fetch();

    if (!$user) {
        echo json_encode(['success' => false, 'error' => 'User not found']);
        exit;
    }

    $new_status = $user['isAdmin'] == 'Y' ? 'N' : 'Y';
    $stmt = $conn->prepare("UPDATE users SET isAdmin = ? WHERE id = ?");

    if ($stmt->execute([$new_status, $id])) {
        echo json_encode(['success' => true, 'isAdmin' => $new_status]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to update admin status']);
    }
    $conn = null;
    exit;
}

// Handle toggle active action
if ($action === 'toggle_active') {
    // Prevent admin from deactivating themselves
    if ($id === (int)$current_user['id']) {
        echo json_encode(['success' => false, 'error' => 'Cannot deactivate your own account']);
        exit;
    }

    $stmt = $conn->prepare("SELECT isActive FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $user = $stmt->fetch();

    if (!$user) {
        echo json_encode(['success' => false, 'error' => 'User not found']);
        exit;
    }

    $new_status = $user['isActive'] == 'Y' ? 'N' : 'Y';
    $stmt = $conn->prepare("UPDATE users SET isActive = ? WHERE id = ?");

    if ($stmt->execute([$new_status, $id])) {
        echo json_encode(['success' => true, 'isActive' => $new_status]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to update active status']);
    }
    $conn = null;
    exit;
}

// Handle full update
$username = trim($_POST['username'] ?? '');
$email = trim($_POST['email'] ?? '');
$isAdmin = isset($_POST['isAdmin']) ? ($_POST['isAdmin'] === 'Y' ? 'Y' : 'N') : null;
$isActive = isset($_POST['isActive']) ? ($_POST['isActive'] === 'Y' ? 'Y' : 'N') : null;

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

// Prevent admin from removing their own admin status via full update
if ($id === (int)$current_user['id'] && $isAdmin !== null && $isAdmin == 'N') {
    echo json_encode(['success' => false, 'error' => 'Cannot remove your own admin status']);
    exit;
}

// Prevent admin from deactivating their own account via full update
if ($id === (int)$current_user['id'] && $isActive !== null && $isActive == 'N') {
    echo json_encode(['success' => false, 'error' => 'Cannot deactivate your own account']);
    exit;
}

// Build update query based on what fields are provided
if ($isAdmin !== null && $isActive !== null) {
    $stmt = $conn->prepare("UPDATE users SET username = ?, email = ?, isAdmin = ?, isActive = ? WHERE id = ?");
    $params = [$username, $email, $isAdmin, $isActive, $id];
} elseif ($isAdmin !== null) {
    $stmt = $conn->prepare("UPDATE users SET username = ?, email = ?, isAdmin = ? WHERE id = ?");
    $params = [$username, $email, $isAdmin, $id];
} elseif ($isActive !== null) {
    $stmt = $conn->prepare("UPDATE users SET username = ?, email = ?, isActive = ? WHERE id = ?");
    $params = [$username, $email, $isActive, $id];
} else {
    $stmt = $conn->prepare("UPDATE users SET username = ?, email = ? WHERE id = ?");
    $params = [$username, $email, $id];
}

try {
    if ($stmt->execute($params)) {
        // Fetch updated user
        $fetch = $conn->prepare("SELECT id, username, email, isAdmin, isActive, created_at, last_login FROM users WHERE id = ?");
        $fetch->execute([$id]);
        $user = $fetch->fetch();

        echo json_encode(['success' => true, 'user' => $user]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to update user']);
    }
} catch (PDOException $e) {
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

$conn = null;
