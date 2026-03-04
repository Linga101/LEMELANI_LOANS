<?php
require_once 'config/config.php';

// Hybrid rollout: route forgot-password UI to Next.js when enabled.
$nextForgotPasswordUrl = nextjs_url('/forgot-password');
if (feature_enabled('nextjs_auth') && $nextForgotPasswordUrl !== '') {
    redirect($nextForgotPasswordUrl);
}

$errors = [];
$success = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_fail();
    $email = strtolower(trim((string)($_POST['email'] ?? '')));

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if (empty($errors)) {
        $success = 'If an account exists with that email, a password reset link has been sent.';

        try {
            $database = new Database();
            $db = $database->getConnection();

            $stmt = $db->prepare("SELECT user_id, full_name, email, account_status
                                  FROM users
                                  WHERE email = :email
                                  LIMIT 1");
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && $user['account_status'] !== 'closed') {
                $canRequest = can_request_password_reset((int)$user['user_id']);
                if ($canRequest) {
                    $token = create_password_reset_token((int)$user['user_id']);
                    if ($token) {
                        $resetUrl = site_url('reset-password.php?selector=' . urlencode($token['selector']) . '&token=' . urlencode($token['validator']));
                        try {
                            send_password_reset_email($user['email'], $user['full_name'], $resetUrl, $token['expires_at']);
                            log_audit((int)$user['user_id'], 'PASSWORD_RESET_REQUESTED', 'users', (int)$user['user_id']);
                        } catch (Throwable $mailError) {
                            error_log('Password reset email error: ' . $mailError->getMessage());
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('Forgot password request failed: ' . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo asset_url('assets/css/style.css'); ?>">
</head>
<body>
    <div class="gradient-overlay"></div>
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-header">
                <img src="assets/images/logo.png" alt="<?php echo SITE_NAME; ?>" class="auth-logo" onerror="this.style.display='none'">
                <h1 class="auth-title">Forgot Password</h1>
                <p class="auth-subtitle">Enter your email to receive a reset link</p>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <?php foreach ($errors as $error): ?>
                        <?php echo h($error); ?><br>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-info"><?php echo h($success); ?></div>
            <?php endif; ?>

            <form method="POST">
                <?php echo csrf_input(); ?>
                <div class="form-group">
                    <label for="email" class="form-label">Email Address</label>
                    <input type="email" id="email" name="email" class="form-control"
                           placeholder="your@email.com"
                           value="<?php echo h($email); ?>"
                           required autofocus>
                </div>

                <button type="submit" class="btn btn-primary btn-block btn-lg">Send Reset Link</button>
            </form>

            <div class="auth-footer">
                <a href="<?php echo site_url('login.php'); ?>">Back to Login</a>
            </div>
        </div>
    </div>

    <style>
        .auth-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }

        .auth-card {
            background: var(--dark-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 2.5rem;
            max-width: 450px;
            width: 100%;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
        }

        .auth-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .auth-logo {
            height: 50px;
            margin-bottom: 1rem;
        }

        .auth-title {
            font-size: 1.75rem;
            margin-bottom: 0.5rem;
        }

        .auth-subtitle {
            color: var(--text-secondary);
        }

        .auth-footer {
            text-align: center;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border-color);
        }
    </style>
</body>
</html>
