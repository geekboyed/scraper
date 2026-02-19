<?php
/**
 * Signup Page
 * Registration with invite code validation
 */

require_once 'config.php';

$error = '';
$success = '';

// Handle signup form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $invite_code = trim($_POST['invite_code'] ?? '');

    // Validation
    if (empty($username) || empty($email) || empty($invite_code)) {
        $error = 'All fields are required.';
    } elseif (strlen($username) < 3 || strlen($username) > 50) {
        $error = 'Username must be between 3 and 50 characters.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($email) > 255) {
        $error = 'Email address is too long.';
    } else {
        // Check if username already exists
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->bind_param('s', $username);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $error = 'Username is already taken.';
            $stmt->close();
        } else {
            $stmt->close();

            // Check if email already exists
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->bind_param('s', $email);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $error = 'An account with this email already exists.';
                $stmt->close();
            } else {
                $stmt->close();

                // Validate invite code (check if still has uses available)
                $stmt = $conn->prepare("SELECT id, current_uses, max_uses FROM invite_codes WHERE code = ? AND current_uses < max_uses AND (expires_at IS NULL OR expires_at > NOW())");
                $stmt->bind_param('s', $invite_code);
                $stmt->execute();
                $invite_result = $stmt->get_result();

                if ($invite_result->num_rows === 0) {
                    $error = 'Invalid, expired, or fully used invite code.';
                    $stmt->close();
                } else {
                    $invite_row = $invite_result->fetch_assoc();
                    $invite_code_id = $invite_row['id'];
                    $stmt->close();

                    // Create user (isAdmin defaults to 'N', sourceCount=5 for non-admin)
                    $stmt = $conn->prepare("INSERT INTO users (username, email, isAdmin, sourceCount, invite_code_id, isActive) VALUES (?, ?, 'N', 5, ?, 'Y')");
                    $stmt->bind_param('ssi', $username, $email, $invite_code_id);

                    if ($stmt->execute()) {
                        $new_user_id = $stmt->insert_id;
                        $stmt->close();

                        // Increment invite code usage
                        $stmt = $conn->prepare("UPDATE invite_codes SET current_uses = current_uses + 1, used_by = ?, used_at = NOW(), is_used = IF(current_uses + 1 >= max_uses, 1, 0) WHERE id = ?");
                        $stmt->bind_param('ii', $new_user_id, $invite_code_id);
                        $stmt->execute();
                        $stmt->close();

                        // Set login cookie
                        setcookie('user_session', $new_user_id, [
                            'expires' => 0,
                            'path' => '/',
                            'httponly' => true,
                            'samesite' => 'Lax'
                        ]);

                        header('Location: index.php');
                        exit;
                    } else {
                        $error = 'An error occurred creating your account. Please try again.';
                        $stmt->close();
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up - News Dashboard</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #1e5128 0%, #2d6a4f 50%, #52b788 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .auth-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 8px 16px rgba(0,0,0,0.2);
            padding: 40px;
            width: 100%;
            max-width: 420px;
        }

        .auth-card h1 {
            color: #333;
            font-size: 1.8em;
            margin-bottom: 8px;
            text-align: center;
        }

        .auth-card .subtitle {
            color: #666;
            font-size: 0.95em;
            text-align: center;
            margin-bottom: 30px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            color: #333;
            font-weight: 600;
            margin-bottom: 6px;
            font-size: 0.9em;
        }

        .form-group input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 5px;
            font-size: 14px;
            transition: border-color 0.3s;
        }

        .form-group input:focus {
            outline: none;
            border-color: #667eea;
        }

        .btn {
            width: 100%;
            padding: 12px 25px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: background 0.3s;
        }

        .btn:hover {
            background: #5568d3;
        }

        .alert {
            padding: 12px 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            font-size: 0.9em;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .auth-links {
            text-align: center;
            margin-top: 20px;
            font-size: 0.9em;
            color: #666;
        }

        .auth-links a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }

        .auth-links a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="auth-card">
        <h1>News Dashboard</h1>
        <p class="subtitle">Create your account</p>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" action="signup.php">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" placeholder="Choose a username" value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" required autofocus minlength="3" maxlength="50">
            </div>

            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" placeholder="Enter your email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required maxlength="255">
            </div>

            <div class="form-group">
                <label for="invite_code">Invite Code</label>
                <input type="text" id="invite_code" name="invite_code" placeholder="Enter your invite code" value="<?php echo htmlspecialchars($_POST['invite_code'] ?? ''); ?>" required>
            </div>

            <button type="submit" class="btn">Create Account</button>
        </form>

        <div class="auth-links">
            Already have an account? <a href="login.php">Sign in</a>
        </div>
    </div>
</body>
</html>
