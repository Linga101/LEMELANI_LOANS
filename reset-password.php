<?php
require_once 'config/config.php';

if (is_logged_in()) {
    redirect('/dashboard.php');
}

$errors = [];
$success = '';
$selector = trim((string)($_GET['selector'] ?? $_POST['selector'] ?? ''));
$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$tokenRecord = null;

try {
    $tokenRecord = validate_password_reset_token($selector, $token);
} catch (Throwable $e) {
    error_log('Reset token validation error: ' . $e->getMessage());
    $tokenRecord = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_fail();
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (!$tokenRecord) {
        $errors[] = 'This reset link is invalid or has expired.';
    }

    if ($newPassword === '') {
        $errors[] = 'New password is required.';
    } elseif (strlen($newPassword) < 6) {
        $errors[] = 'New password must be at least 6 characters.';
    }

    if ($newPassword !== $confirmPassword) {
        $errors[] = 'Passwords do not match.';
    }

    if (empty($errors) && $tokenRecord) {
        try {
            $database = new Database();
            $db = $database->getConnection();
            $db->beginTransaction();

            $update = $db->prepare("UPDATE users
                                    SET password_hash = :password_hash
                                    WHERE user_id = :user_id");
            $update->execute([
                ':password_hash' => password_hash($newPassword, HASH_ALGO),
                ':user_id' => (int)$tokenRecord['user_id'],
            ]);

            if ($update->rowCount() < 1) {
                throw new RuntimeException('Unable to update password.');
            }

            $consume = $db->prepare("UPDATE password_reset_tokens
                                     SET used_at = NOW()
                                     WHERE id = :id AND used_at IS NULL");
            $consume->execute([':id' => (int)$tokenRecord['id']]);
            if ($consume->rowCount() < 1) {
                throw new RuntimeException('Token already used.');
            }

            $db->prepare("DELETE FROM password_reset_tokens WHERE user_id = :user_id")
               ->execute([':user_id' => (int)$tokenRecord['user_id']]);

            // Revoke all active remember-me sessions after password reset.
            create_remember_tokens_table($db);
            $db->prepare("DELETE FROM remember_tokens WHERE user_id = :user_id")
               ->execute([':user_id' => (int)$tokenRecord['user_id']]);

            $db->commit();

            log_audit((int)$tokenRecord['user_id'], 'PASSWORD_RESET_COMPLETED', 'users', (int)$tokenRecord['user_id']);
            $success = 'Your password has been reset successfully. You can now login.';
        } catch (Throwable $e) {
            if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log('Reset password failure: ' . $e->getMessage());
            $errors[] = 'Unable to reset password right now. Please request a new reset link.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo asset_url('assets/css/style.css'); ?>">
</head>
<body>
    <div class="gradient-overlay"></div>
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-header">
                <img src="assets/images/logo.png" alt="<?php echo SITE_NAME; ?>" class="auth-logo" onerror="this.style.display='none'">
                <h1 class="auth-title">Reset Password</h1>
                <p class="auth-subtitle">Set a new password for your account</p>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <?php foreach ($errors as $error): ?>
                        <?php echo h($error); ?><br>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <?php echo h($success); ?>
                    <br><a href="<?php echo site_url('login.php'); ?>" style="color: inherit; text-decoration: underline;">Continue to login</a>
                </div>
            <?php elseif (!$tokenRecord): ?>
                <div class="alert alert-error">
                    This reset link is invalid or has expired.
                    <br><a href="<?php echo site_url('forgot-password.php'); ?>" style="color: inherit; text-decoration: underline;">Request a new reset link</a>
                </div>
            <?php else: ?>
                <form method="POST">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="selector" value="<?php echo h($selector); ?>">
                    <input type="hidden" name="token" value="<?php echo h($token); ?>">

                    <div class="form-group">
                        <label for="new_password" class="form-label">New Password</label>
                        <input type="password" id="new_password" name="new_password" class="form-control"
                               minlength="6" required autofocus>
                        <small class="form-text">Minimum 6 characters</small>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password" class="form-label">Confirm New Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" class="form-control"
                               minlength="6" required>
                    </div>

                    <button type="submit" class="btn btn-primary btn-block btn-lg">Reset Password</button>
                </form>
            <?php endif; ?>

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

    <script>
        const newPassword = document.getElementById('new_password');
        const confirmPassword = document.getElementById('confirm_password');

        if (newPassword && confirmPassword) {
            const validate = () => {
                if (confirmPassword.value && newPassword.value !== confirmPassword.value) {
                    confirmPassword.setCustomValidity('Passwords do not match');
                } else {
                    confirmPassword.setCustomValidity('');
                }
            };
            newPassword.addEventListener('input', validate);
            confirmPassword.addEventListener('input', validate);
        }
    </script>
</body>
</html>
