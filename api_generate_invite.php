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

/**
 * Generate a cryptographically secure random 6-char alphanumeric code.
 */
function generateInviteCode($length = 6) {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $code;
}

// Get code from POST data or generate a random one
if (isset($_POST['code']) && trim($_POST['code']) !== '') {
    $code = strtoupper(trim($_POST['code']));

    // Validate: 1-10 chars alphanumeric only
    if (!preg_match('/^[A-Z0-9]{1,10}$/', $code)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid code format. Use only letters and numbers (1-10 characters)']);
        exit;
    }

    // Check if code already exists
    $check = $conn->prepare("SELECT id FROM invite_codes WHERE code = ?");
    $check->execute([$code]);
    if ($check->fetch()) {
        http_response_code(409);
        echo json_encode(['error' => 'This code already exists. Please choose a different code.']);
        exit;
    }
} else {
    // Generate a secure random 6-char alphanumeric code, retry on collision
    $check = $conn->prepare("SELECT id FROM invite_codes WHERE code = ?");
    do {
        $code = generateInviteCode(6);
        $check->execute([$code]);
    } while ($check->fetch());
}

// Get max_uses from POST data (default to 1)
$max_uses = isset($_POST['max_uses']) ? (int)$_POST['max_uses'] : 1;
if ($max_uses < 1) {
    $max_uses = 1;
}

// Insert into database
$stmt = $conn->prepare("INSERT INTO invite_codes (code, created_by, max_uses, isActive) VALUES (?, ?, ?, 1)");

if ($stmt->execute([$code, $current_user['id'], $max_uses])) {
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

$conn = null;
?>
