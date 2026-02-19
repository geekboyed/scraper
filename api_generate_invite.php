<?php
/**
 * API Endpoint: Generate a new invite code
 * Only accessible by admin users
 * Returns JSON with the new invite code
 */

header('Content-Type: application/json');

require_once 'config.php';
require_once 'auth_check.php';

// Must be logged in
if (!isset($current_user)) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

// Must be admin
if (!$current_user || $current_user['isAdmin'] != 'Y') {
    http_response_code(403);
    echo json_encode(['error' => 'Admin access required']);
    exit;
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Get code from POST data or generate a random one
if (isset($_POST['code']) && !empty(trim($_POST['code']))) {
    $code = trim($_POST['code']);

    // Validate code (alphanumeric and dashes only, 3-50 chars)
    if (!preg_match('/^[A-Za-z0-9\-]{3,50}$/', $code)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid code format. Use only letters, numbers, and dashes (3-50 characters)']);
        exit;
    }

    // Check if code already exists
    $check = $conn->prepare("SELECT id FROM invite_codes WHERE code = ?");
    $check->bind_param("s", $code);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        http_response_code(409);
        echo json_encode(['error' => 'This code already exists. Please choose a different code.']);
        $check->close();
        exit;
    }
    $check->close();
} else {
    // Generate a random 32-character hex code
    $code = bin2hex(random_bytes(16));
}

// Get max_uses from POST data (default to 1)
$max_uses = isset($_POST['max_uses']) ? (int)$_POST['max_uses'] : 1;
if ($max_uses < 1) {
    $max_uses = 1; // Minimum 1 use
}

// Insert into database
$stmt = $conn->prepare("INSERT INTO invite_codes (code, created_by, max_uses) VALUES (?, ?, ?)");
$stmt->bind_param("sii", $code, $current_user['id'], $max_uses);

if ($stmt->execute()) {
    echo json_encode([
        'success' => true,
        'code' => $code,
        'max_uses' => $max_uses,
        'created_at' => date('Y-m-d H:i:s')
    ]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to create invite code']);
}

$stmt->close();
$conn->close();
?>
