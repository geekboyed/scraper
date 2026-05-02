<?php
/**
 * Login Page
 * Simple login with username or email (no password required)
 */

require_once 'config.php';

$error = '';
$success = '';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['identifier'] ?? '');

    if (empty($identifier)) {
        $error = 'Please enter your username or email.';
    } else {
        // Look up user by username or email
        $stmt = $conn->prepare("SELECT id, username, email, isActive FROM users WHERE (username = ? OR email = ?) AND isActive = 'Y'");
        $stmt->execute([$identifier, $identifier]);
        $user = $stmt->fetch();

        if ($user) {
            // Update last_login timestamp
            $update_stmt = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
            $update_stmt->execute([$user['id']]);

            // Generate a random opaque token and store in DB
            $token = bin2hex(random_bytes(32));
            $stmt = $conn->prepare("INSERT INTO user_sessions (token, user_id) VALUES (?, ?)");
            $stmt->execute([$token, $user['id']]);
            setcookie('user_session', $token, [
                'expires'  => 0,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);

            header('Location: index.php');
            exit;
        } else {
            $error = 'No active account found with that username or email.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - News Dashboard</title>
    <link rel="stylesheet" href="style.css">
    <style>
        body {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .form-group label {
            margin-bottom: 6px;
            font-size: 0.9em;
        }

        .form-group input {
            padding: 12px 15px;
            transition: border-color 0.3s;
        }

        .btn {
            width: 100%;
            padding: 12px 25px;
        }
    </style>
</head>
<body>
    <div class="auth-card">
        <h1>News Dashboard</h1>
        <p class="subtitle">Sign in to your account</p>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <form method="POST" action="login.php">
            <div class="form-group">
                <label for="identifier">Username or Email</label>
                <input type="text" id="identifier" name="identifier" placeholder="Enter your username or email" value="<?php echo htmlspecialchars($_POST['identifier'] ?? ''); ?>" required autofocus>
            </div>

            <button type="submit" class="btn">Sign In</button>
        </form>

        <div style="margin-top: 20px; text-align: center;">
            <div style="color: #999; font-size: 0.85em; margin-bottom: 10px;">— or —</div>
            <a href="signup.php" style="display:inline-block; width:100%; padding:12px 25px; background:#ededea; color:#667eea; border:2px solid #667eea; border-radius:5px; font-size:14px; font-weight:600; text-decoration:none; box-sizing:border-box; text-align:center; transition:all 0.3s;">
                Use Invite Code
            </a>
        </div>
    </div>
</body>
</html>
