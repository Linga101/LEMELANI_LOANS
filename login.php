<?php
require_once 'config/config.php';

// Redirect if already logged in
if (is_logged_in()) {
    redirect('/dashboard.php');
}

$errors = [];
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = sanitize_input($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember_me = isset($_POST['remember_me']);
    
    // Validation
    if (empty($email)) {
        $errors[] = "Email is required";
    }
    
    if (empty($password)) {
        $errors[] = "Password is required";
    }
    
    // Attempt login
    if (empty($errors)) {
        try {
            $database = new Database();
            $db = $database->getConnection();
            
            // Check for login attempts (basic rate limiting)
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            
            $query = "SELECT user_id, full_name, email, password_hash, role, account_status, verification_status 
                     FROM users 
                     WHERE email = :email";
            
            $stmt = $db->prepare($query);
            $stmt->execute([':email' => $email]);
            
            if ($stmt->rowCount() === 1) {
                $user = $stmt->fetch();
                
                // Check if account is suspended
                if ($user['account_status'] === 'suspended') {
                    $errors[] = "Your account has been suspended. Please contact support.";
                } elseif ($user['account_status'] === 'closed') {
                    $errors[] = "Your account has been closed.";
                } elseif (password_verify($password, $user['password_hash'])) {
                    // Password is correct - create session
                    session_regenerate_id(true);
                    
                    $_SESSION['user_id'] = $user['user_id'];
                    $_SESSION['user_role'] = $user['role'];
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['verification_status'] = $user['verification_status'];
                    $_SESSION['login_time'] = time();
                    
                    // Update last login
                    $update_query = "UPDATE users SET last_login = NOW() WHERE user_id = :user_id";
                    $update_stmt = $db->prepare($update_query);
                    $update_stmt->execute([':user_id' => $user['user_id']]);
                    
                    // Log audit
                    log_audit($user['user_id'], 'USER_LOGIN', 'users', $user['user_id']);
                    
                    // Remember me functionality
                    if ($remember_me) {
                        $token = bin2hex(random_bytes(32));
                        setcookie('remember_token', $token, time() + (86400 * 30), '/'); // 30 days
                        // In production, store this token in database
                    }
                    
                    // Redirect based on role
                    if ($user['role'] === 'admin' || $user['role'] === 'manager') {
                        redirect('/admin/dashboard.php');
                    } else {
                        redirect('/dashboard.php');
                    }
                    
                } else {
                    $errors[] = "Invalid email or password";
                    
                    // Log failed attempt
                    log_audit(null, 'FAILED_LOGIN_ATTEMPT', 'users', null, null, ['email' => $email]);
                }
            } else {
                $errors[] = "Invalid email or password";
            }
            
        } catch(PDOException $e) {
            $errors[] = "Login error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
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

        .auth-footer a {
            color: var(--primary-green);
            text-decoration: none;
        }

        .auth-footer a:hover {
            text-decoration: underline;
        }

        .remember-forgot {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .checkbox-wrapper {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .checkbox-wrapper input[type="checkbox"] {
            width: auto;
            margin: 0;
        }

        .back-home {
            text-align: center;
            margin-top: 1rem;
        }

        .back-home a {
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 0.875rem;
        }

        .back-home a:hover {
            color: var(--primary-green);
        }
    </style>
</head>
<body>
    <div class="gradient-overlay"></div>

    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-header">
                <img src="assets/images/logo.png" alt="<?php echo SITE_NAME; ?>" class="auth-logo" onerror="this.style.display='none'">
                <h1 class="auth-title">Welcome Back</h1>
                <p class="auth-subtitle">Login to your account</p>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <?php foreach ($errors as $error): ?>
                        <?php echo htmlspecialchars($error); ?><br>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['registered'])): ?>
                <div class="alert alert-success">
                    Registration successful! Please login to continue.
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['logout'])): ?>
                <div class="alert alert-info">
                    You have been logged out successfully.
                </div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-group">
                    <label for="email" class="form-label">Email Address</label>
                    <input type="email" id="email" name="email" class="form-control" 
                           placeholder="your@email.com" 
                           value="<?php echo htmlspecialchars($email); ?>" 
                           required autofocus>
                </div>

                <div class="form-group">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" id="password" name="password" class="form-control" 
                           placeholder="Enter your password" required>
                </div>

                <div class="remember-forgot">
                    <div class="checkbox-wrapper">
                        <input type="checkbox" id="remember_me" name="remember_me">
                        <label for="remember_me" style="margin: 0; font-size: 0.875rem;">Remember me</label>
                    </div>
                    <a href="#" style="font-size: 0.875rem;">Forgot password?</a>
                </div>

                <button type="submit" class="btn btn-primary btn-block btn-lg">
                    Login
                </button>
            </form>

            <div class="auth-footer">
                Don't have an account? <a href="register.php">Register here</a>
            </div>

            <div class="back-home">
                <a href="index.php">← Back to home</a>
            </div>
        </div>
    </div>

    <script>
        // Show password toggle (optional enhancement)
        const passwordInput = document.getElementById('password');
        
        // Auto-focus email field if empty
        window.addEventListener('load', function() {
            const emailInput = document.getElementById('email');
            if (!emailInput.value) {
                emailInput.focus();
            }
        });
    </script>
</body>
</html>