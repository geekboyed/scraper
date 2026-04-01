<?php
/**
 * Authentication Helper
 * Include this file in any page that needs to check user authentication.
 * Sets $current_user with user data if logged in, or null if not.
 */

// Ensure config.php is loaded (for $conn)
if (!isset($conn)) {
    require_once __DIR__ . '/config.php';
}

$current_user = null;

if (isset($_COOKIE['user_session'])) {
    $token = $_COOKIE['user_session'];
    if (strlen($token) === 64 && ctype_xdigit($token)) {
        $stmt = $conn->prepare(
            "SELECT u.id, u.username, u.email, u.isAdmin, u.sourceCount, u.isActive,
                    u.created_at, u.last_login, u.last_activity, u.session_count,
                    u.total_hours, u.current_session_start, u.preferenceJSON
             FROM user_sessions s
             JOIN users u ON u.id = s.user_id
             WHERE s.token = ? AND u.isActive = 'Y'
               AND (s.expires_at IS NULL OR s.expires_at > NOW())"
        );
        $stmt->execute([$token]);
        $current_user = $stmt->fetch();

        if ($current_user) {
            $user_id = $current_user['id'];

            // Track usage - 4-hour session blocks
            $now = new DateTime();
            $last_activity = $current_user['last_activity'] ? new DateTime($current_user['last_activity']) : null;
            $session_start = $current_user['current_session_start'] ? new DateTime($current_user['current_session_start']) : null;

            // Check if this is a new session (more than 4 hours since last activity, or no last activity)
            $is_new_session = false;
            if (!$last_activity) {
                // First time user is active
                $is_new_session = true;
            } else {
                $hours_since_last = ($now->getTimestamp() - $last_activity->getTimestamp()) / 3600;
                if ($hours_since_last > 4) {
                    $is_new_session = true;
                }
            }

            if ($is_new_session) {
                // Start new session
                $update_stmt = $conn->prepare("UPDATE users SET session_count = session_count + 1, current_session_start = NOW(), last_activity = NOW() WHERE id = ?");
                $update_stmt->execute([$user_id]);
            } else {
                // Continue existing session - calculate hours since session start
                if ($session_start) {
                    $hours_this_session = ($now->getTimestamp() - $session_start->getTimestamp()) / 3600;
                    // Update total hours and last activity
                    $update_stmt = $conn->prepare("UPDATE users SET total_hours = ?, last_activity = NOW() WHERE id = ?");
                    $update_stmt->execute([$hours_this_session, $user_id]);
                } else {
                    // Just update last activity
                    $update_stmt = $conn->prepare("UPDATE users SET last_activity = NOW() WHERE id = ?");
                    $update_stmt->execute([$user_id]);
                }
            }
        }
    }
}
?>
