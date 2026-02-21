<?php
/**
 * API Endpoint - Update invite code (admin only)
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
    echo json_encode(['success' => false, 'error' => 'Valid invite code ID is required']);
    exit;
}

// Handle toggle active action
if ($action === 'toggle_active') {
    $stmt = $conn->prepare("SELECT isActive FROM invite_codes WHERE id = ?");
    $stmt->execute([$id]);
    $code = $stmt->fetch();

    if (!$code) {
        echo json_encode(['success' => false, 'error' => 'Invite code not found']);
        exit;
    }

    $new_status = $code['isActive'] ? 0 : 1;
    $stmt = $conn->prepare("UPDATE invite_codes SET isActive = ? WHERE id = ?");

    if ($stmt->execute([$new_status, $id])) {
        echo json_encode(['success' => true, 'isActive' => $new_status]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to update active status']);
    }
    $conn = null;
    exit;
}

// Handle update max uses action
if ($action === 'update_max_uses') {
    $max_uses = (int)($_POST['max_uses'] ?? 0);

    if ($max_uses < 1 || $max_uses > 999) {
        echo json_encode(['success' => false, 'error' => 'Max uses must be between 1 and 999']);
        exit;
    }

    // Get current uses to validate
    $stmt = $conn->prepare("SELECT current_uses FROM invite_codes WHERE id = ?");
    $stmt->execute([$id]);
    $code = $stmt->fetch();

    if (!$code) {
        echo json_encode(['success' => false, 'error' => 'Invite code not found']);
        exit;
    }

    $stmt = $conn->prepare("UPDATE invite_codes SET max_uses = ? WHERE id = ?");

    if ($stmt->execute([$max_uses, $id])) {
        // Check if code should be marked as used/unused based on new max_uses
        $is_used = $code['current_uses'] >= $max_uses ? 1 : 0;
        $update_used = $conn->prepare("UPDATE invite_codes SET is_used = ? WHERE id = ?");
        $update_used->execute([$is_used, $id]);

        echo json_encode(['success' => true, 'max_uses' => $max_uses]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to update max uses']);
    }
    $conn = null;
    exit;
}

// Handle delete action
if ($action === 'delete') {
    // Check if code has been used
    $stmt = $conn->prepare("SELECT current_uses FROM invite_codes WHERE id = ?");
    $stmt->execute([$id]);
    $code = $stmt->fetch();

    if (!$code) {
        echo json_encode(['success' => false, 'error' => 'Invite code not found']);
        exit;
    }

    // Allow deletion even if used (admin decision)
    $stmt = $conn->prepare("DELETE FROM invite_codes WHERE id = ?");

    if ($stmt->execute([$id])) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to delete invite code']);
    }
    $conn = null;
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid action']);
$conn = null;
