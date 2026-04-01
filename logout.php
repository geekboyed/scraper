<?php
/**
 * Logout Handler
 * Clears the user session cookie and redirects to login page.
 */

// Delete the session token from DB before clearing cookie
require_once __DIR__ . '/config.php';
if (isset($_COOKIE['user_session'])) {
    $token = $_COOKIE['user_session'];
    if (strlen($token) === 64 && ctype_xdigit($token)) {
        $stmt = $conn->prepare("DELETE FROM user_sessions WHERE token = ?");
        $stmt->execute([$token]);
    }
}

// Clear the session cookie
setcookie('user_session', '', [
    'expires' => time() - 3600,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax'
]);

// Destroy PHP session if active
if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}

header('Location: login.php');
exit;
?>
