<?php
/**
 * Logout Handler
 * Clears the user session cookie and redirects to login page.
 */

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
