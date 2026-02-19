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
    $user_id = (int)$_COOKIE['user_session'];
    if ($user_id > 0) {
        $stmt = $conn->prepare("SELECT id, username, email, isAdmin, sourceCount, isActive, created_at, last_login, last_activity, session_count, total_hours, current_session_start, preferenceJSON FROM users WHERE id = ? AND isActive = 'Y'");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 1) {
            $current_user = $result->fetch_assoc();

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
                $update_stmt->bind_param('i', $user_id);
                $update_stmt->execute();
                $update_stmt->close();
            } else {
                // Continue existing session - calculate hours since session start
                if ($session_start) {
                    $hours_this_session = ($now->getTimestamp() - $session_start->getTimestamp()) / 3600;
                    // Update total hours and last activity
                    $update_stmt = $conn->prepare("UPDATE users SET total_hours = ?, last_activity = NOW() WHERE id = ?");
                    $update_stmt->bind_param('di', $hours_this_session, $user_id);
                    $update_stmt->execute();
                    $update_stmt->close();
                } else {
                    // Just update last activity
                    $update_stmt = $conn->prepare("UPDATE users SET last_activity = NOW() WHERE id = ?");
                    $update_stmt->bind_param('i', $user_id);
                    $update_stmt->execute();
                    $update_stmt->close();
                }
            }
        }
        $stmt->close();
    }
}
?>
