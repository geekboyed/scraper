<?php
/**
 * Signup Page
 * Two-step registration: (1) validate invite code, (2) enter email
 */

session_start();
require_once 'config.php';

$error = '';
$step = 1;

// ── Step 2 POST: create account ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['step']) && $_POST['step'] === '2') {
    $email       = trim($_POST['email'] ?? '');
    $invite_code = $_SESSION['pending_invite_code'] ?? '';

    if (empty($invite_code)) {
        // Session expired or tampered – restart
        unset($_SESSION['pending_invite_code']);
        $step = 1;
        $error = 'Your session expired. Please enter your invite code again.';
    } elseif (empty($email)) {
        $step = 2;
        $error = 'Please enter your email address.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $step = 2;
        $error = 'Please enter a valid email address.';
    } elseif (strlen($email) > 255) {
        $step = 2;
        $error = 'Email address is too long.';
    } else {
        // Re-validate invite code (it might have been fully used since step 1)
        $stmt = $conn->prepare(
            "SELECT id, code, current_uses, max_uses FROM invite_codes
             WHERE code = ? AND isActive = 1 AND current_uses < max_uses
               AND (expires_at IS NULL OR expires_at > NOW())"
        );
        $stmt->execute([$invite_code]);
        $invite_row = $stmt->fetch();

        if (!$invite_row) {
            unset($_SESSION['pending_invite_code']);
            $step = 1;
            $error = 'Your invite code is no longer valid. Please try a different code.';
        } else {
            // Check email uniqueness
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $step = 2;
                $error = 'An account with this email already exists.';
            } else {
                // Derive a unique username from the email local-part
                $base_username = preg_replace('/[^a-zA-Z0-9_]/', '', explode('@', $email)[0]);
                if (strlen($base_username) < 3) {
                    $base_username = 'user' . $base_username;
                }
                $base_username = substr($base_username, 0, 45); // leave room for suffix
                $username = $base_username;
                $suffix = 1;
                $ucheck = $conn->prepare("SELECT id FROM users WHERE username = ?");
                while (true) {
                    $ucheck->execute([$username]);
                    if (!$ucheck->fetch()) {
                        break; // available
                    }
                    $username = $base_username . $suffix;
                    $suffix++;
                }

                $invite_code_id = $invite_row['id'];

                // Create the user account
                $stmt = $conn->prepare(
                    "INSERT INTO users (username, email, isAdmin, sourceCount, isActive, invite_code_id, invite_code)
                     VALUES (?, ?, 'N', 5, 'Y', ?, ?)"
                );

                if ($stmt->execute([$username, $email, $invite_code_id, $invite_code])) {
                    $new_user_id = $conn->lastInsertId();

                    // Increment invite code usage
                    $stmt = $conn->prepare(
                        "UPDATE invite_codes
                         SET current_uses = current_uses + 1,
                             used_at = NOW()
                         WHERE id = ?"
                    );
                    $stmt->execute([$invite_code_id]);

                    // Clear session key
                    unset($_SESSION['pending_invite_code']);

                    // Generate a random opaque token and store in DB
                    $token = bin2hex(random_bytes(32));
                    $stmt2 = $conn->prepare("INSERT INTO user_sessions (token, user_id) VALUES (?, ?)");
                    $stmt2->execute([$token, $new_user_id]);
                    setcookie('user_session', $token, [
                        'expires'  => 0,
                        'path'     => '/',
                        'httponly' => true,
                        'samesite' => 'Lax',
                    ]);

                    header('Location: index.php');
                    exit;
                } else {
                    $step = 2;
                    $error = 'An error occurred creating your account. Please try again.';
                }
            }
        }
    }

// ── Step 1 POST: validate invite code ────────────────────────────────────────
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['step']) && $_POST['step'] === '1') {
    $invite_code = strtoupper(trim($_POST['invite_code'] ?? ''));

    if (empty($invite_code)) {
        $step = 1;
        $error = 'Please enter your invite code.';
    } else {
        $stmt = $conn->prepare(
            "SELECT id FROM invite_codes
             WHERE code = ? AND isActive = 1 AND current_uses < max_uses
               AND (expires_at IS NULL OR expires_at > NOW())"
        );
        $stmt->execute([$invite_code]);
        $invite_row = $stmt->fetch();

        if (!$invite_row) {
            $step = 1;
            $error = 'Invalid, expired, or fully used invite code.';
        } else {
            // Store code in session and advance to step 2
            $_SESSION['pending_invite_code'] = $invite_code;
            $step = 2;
        }
    }

// ── GET: determine step from session ─────────────────────────────────────────
} else {
    if (isset($_GET['reset'])) {
        unset($_SESSION['pending_invite_code']);
    }
    $step = isset($_SESSION['pending_invite_code']) ? 2 : 1;
}

// Shorthand for display in step 2
$pending_code = $_SESSION['pending_invite_code'] ?? '';
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

        .invite-code-display {
            padding: 12px 15px;
            background: #f5f5f5;
            border: 2px solid #e0e0e0;
            border-radius: 5px;
            font-size: 14px;
            color: #888;
            font-family: monospace;
            letter-spacing: 0.1em;
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

        .back-link {
            display: inline-block;
            margin-top: 14px;
            font-size: 0.88em;
            color: #999;
            text-decoration: none;
        }

        .back-link:hover {
            color: #667eea;
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

        <?php if ($step === 1): ?>
        <!-- ── Step 1: Enter invite code ── -->
        <form method="POST" action="signup.php">
            <input type="hidden" name="step" value="1">
            <div class="form-group">
                <label for="invite_code">Invite Code</label>
                <input type="text" id="invite_code" name="invite_code"
                       placeholder="Enter your invite code"
                       value="<?php echo htmlspecialchars($_POST['invite_code'] ?? ''); ?>"
                       required autofocus maxlength="20"
                       style="text-transform:uppercase; letter-spacing:0.1em;">
            </div>
            <button type="submit" class="btn">Continue</button>
        </form>

        <?php else: ?>
        <!-- ── Step 2: Enter email ── -->
        <form method="POST" action="signup.php">
            <input type="hidden" name="step" value="2">
            <div class="form-group">
                <label>Invite Code</label>
                <div class="invite-code-display"><?php echo htmlspecialchars($pending_code); ?></div>
            </div>
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email"
                       placeholder="Enter your email address"
                       value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                       required autofocus maxlength="255">
            </div>
            <button type="submit" class="btn">Create Account</button>
            <div style="text-align:center;">
                <a href="signup.php?reset=1" class="back-link">&larr; Use a different code</a>
            </div>
        </form>
        <?php endif; ?>

        <div class="auth-links">
            Already have an account? <a href="login.php">Sign in</a>
        </div>
    </div>
</body>
</html>
