<?php
require_once 'config/config.php';

// Redirect if already logged in
if (is_logged_in()) {
    redirect('/dashboard.php');
}

$errors = [];
$success = '';
$form_data = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize inputs
    $national_id = sanitize_input($_POST['national_id'] ?? '');
    $full_name = sanitize_input($_POST['full_name'] ?? '');
    $email = sanitize_input($_POST['email'] ?? '');
    $phone = sanitize_input($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Store form data for repopulation
    $form_data = compact('national_id', 'full_name', 'email', 'phone');
    
    // Validation
    if (empty($national_id)) {
        $errors[] = "National ID is required";
    }
    
    if (empty($full_name)) {
        $errors[] = "Full name is required";
    }
    
    if (empty($email)) {
        $errors[] = "Email is required";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format";
    }
    
    if (empty($phone)) {
        $errors[] = "Phone number is required";
    }
    
    if (empty($password)) {
        $errors[] = "Password is required";
    } elseif (strlen($password) < 6) {
        $errors[] = "Password must be at least 6 characters";
    }
    
    if ($password !== $confirm_password) {
        $errors[] = "Passwords do not match";
    }
    
    // Handle file uploads
    $selfie_path = null;
    $id_document_path = null;
    
    if (isset($_FILES['selfie']) && $_FILES['selfie']['error'] === UPLOAD_ERR_OK) {
        $selfie_ext = pathinfo($_FILES['selfie']['name'], PATHINFO_EXTENSION);
        $allowed_ext = ['jpg', 'jpeg', 'png'];
        
        if (!in_array(strtolower($selfie_ext), $allowed_ext)) {
            $errors[] = "Selfie must be JPG or PNG";
        } elseif ($_FILES['selfie']['size'] > 5000000) { // 5MB
            $errors[] = "Selfie file too large (max 5MB)";
        } else {
            $selfie_name = uniqid() . '_selfie.' . $selfie_ext;
            $selfie_path = 'selfies/' . $selfie_name;
            
            if (!move_uploaded_file($_FILES['selfie']['tmp_name'], SELFIE_UPLOAD_DIR . $selfie_name)) {
                $errors[] = "Failed to upload selfie";
                $selfie_path = null;
            }
        }
    } else {
        $errors[] = "Selfie is required for verification";
    }
    
    if (isset($_FILES['id_document']) && $_FILES['id_document']['error'] === UPLOAD_ERR_OK) {
        $id_ext = pathinfo($_FILES['id_document']['name'], PATHINFO_EXTENSION);
        $allowed_ext = ['jpg', 'jpeg', 'png', 'pdf'];
        
        if (!in_array(strtolower($id_ext), $allowed_ext)) {
            $errors[] = "ID document must be JPG, PNG or PDF";
        } elseif ($_FILES['id_document']['size'] > 5000000) { // 5MB
            $errors[] = "ID document file too large (max 5MB)";
        } else {
            $id_name = uniqid() . '_id.' . $id_ext;
            $id_document_path = 'ids/' . $id_name;
            
            if (!move_uploaded_file($_FILES['id_document']['tmp_name'], ID_UPLOAD_DIR . $id_name)) {
                $errors[] = "Failed to upload ID document";
                $id_document_path = null;
            }
        }
    } else {
        $errors[] = "National ID document is required for verification";
    }
    
    // Check if email or national ID already exists
    if (empty($errors)) {
        try {
            $database = new Database();
            $db = $database->getConnection();
            
            $check_query = "SELECT user_id FROM users WHERE email = :email OR national_id = :national_id";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->execute([
                ':email' => $email,
                ':national_id' => $national_id
            ]);
            
            if ($check_stmt->rowCount() > 0) {
                $errors[] = "Email or National ID already registered";
            }
        } catch(PDOException $e) {
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
    
    // Insert user if no errors
    if (empty($errors)) {
        try {
            $password_hash = password_hash($password, HASH_ALGO);
            
            $insert_query = "INSERT INTO users (national_id, full_name, email, phone, password_hash, profile_photo, is_verified, verification_status) 
                           VALUES (:national_id, :full_name, :email, :phone, :password_hash, :profile_photo, 0, 'pending')";
            
            $insert_stmt = $db->prepare($insert_query);
            $insert_stmt->execute([
                ':national_id' => $national_id,
                ':full_name' => $full_name,
                ':email' => $email,
                ':phone' => $phone,
                ':password_hash' => $password_hash,
                ':profile_photo' => $selfie_path
            ]);
            
            $user_id = $db->lastInsertId();
            
            // Log audit
            log_audit($user_id, 'USER_REGISTERED', 'users', $user_id);
            
            // Create notification
            $notif_query = "INSERT INTO notifications (user_id, notification_type, title, message) 
                          VALUES (:user_id, 'system', 'Welcome to Lemelani Loans', 'Your account has been created successfully. Please wait for verification.')";
            $notif_stmt = $db->prepare($notif_query);
            $notif_stmt->execute([':user_id' => $user_id]);
            
            $success = "Registration successful! Please login to continue. Your account will be verified shortly.";
            $form_data = []; // Clear form data
            
        } catch(PDOException $e) {
            $errors[] = "Registration failed: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - <?php echo SITE_NAME; ?></title>
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
            max-width: 500px;
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

        .file-upload-wrapper {
            position: relative;
            margin-bottom: 1.5rem;
        }

        .file-upload-input {
            display: none;
        }

        .file-upload-label {
            display: block;
            padding: 1rem;
            background: var(--dark-card);
            border: 2px dashed var(--border-color);
            border-radius: 8px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .file-upload-label:hover {
            border-color: var(--primary-green);
            background: var(--dark-card-hover);
        }

        .file-upload-label.has-file {
            border-color: var(--primary-green);
            border-style: solid;
        }

        .file-upload-icon {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }

        .file-name {
            color: var(--primary-green);
            font-size: 0.875rem;
            margin-top: 0.5rem;
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
    </style>
</head>
<body>
    <div class="gradient-overlay"></div>

    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-header">
                <img src="assets/images/logo.png" alt="<?php echo SITE_NAME; ?>" class="auth-logo" onerror="this.style.display='none'">
                <h1 class="auth-title">Create Account</h1>
                <p class="auth-subtitle">Join Lemelani Loans today</p>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <strong>Please fix the following errors:</strong>
                    <ul style="margin: 0.5rem 0 0 1.5rem;">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <?php echo htmlspecialchars($success); ?>
                    <br><a href="<?php echo site_url('login.php'); ?>" style="color: inherit; text-decoration: underline;">Click here to login</a>
                </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="national_id" class="form-label">National ID *</label>
                    <input type="text" id="national_id" name="national_id" class="form-control" 
                           value="<?php echo htmlspecialchars($form_data['national_id'] ?? ''); ?>" required>
                    <small class="form-text">Enter your Malawi National ID number</small>
                </div>

                <div class="form-group">
                    <label for="full_name" class="form-label">Full Name *</label>
                    <input type="text" id="full_name" name="full_name" class="form-control" 
                           value="<?php echo htmlspecialchars($form_data['full_name'] ?? ''); ?>" required>
                </div>

                <div class="form-group">
                    <label for="email" class="form-label">Email Address *</label>
                    <input type="email" id="email" name="email" class="form-control" 
                           value="<?php echo htmlspecialchars($form_data['email'] ?? ''); ?>" required>
                </div>

                <div class="form-group">
                    <label for="phone" class="form-label">Phone Number *</label>
                    <input type="tel" id="phone" name="phone" class="form-control" 
                           placeholder="+265..." value="<?php echo htmlspecialchars($form_data['phone'] ?? ''); ?>" required>
                </div>

                <div class="form-group">
                    <label for="password" class="form-label">Password *</label>
                    <input type="password" id="password" name="password" class="form-control" 
                           minlength="6" required>
                    <small class="form-text">Minimum 6 characters</small>
                </div>

                <div class="form-group">
                    <label for="confirm_password" class="form-label">Confirm Password *</label>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" required>
                </div>

                <div class="file-upload-wrapper">
                    <label class="form-label">Selfie Photo *</label>
                    <input type="file" id="selfie" name="selfie" class="file-upload-input" 
                           accept="image/jpeg,image/png" required>
                    <label for="selfie" class="file-upload-label" id="selfie-label">
                        <div class="file-upload-icon">📸</div>
                        <div>Click to upload your selfie</div>
                        <small class="form-text">JPG or PNG, max 5MB</small>
                        <div class="file-name" id="selfie-name"></div>
                    </label>
                </div>

                <div class="file-upload-wrapper">
                    <label class="form-label">National ID Document *</label>
                    <input type="file" id="id_document" name="id_document" class="file-upload-input" 
                           accept="image/jpeg,image/png,application/pdf" required>
                    <label for="id_document" class="file-upload-label" id="id-label">
                        <div class="file-upload-icon">🆔</div>
                        <div>Click to upload your National ID</div>
                        <small class="form-text">JPG, PNG or PDF, max 5MB</small>
                        <div class="file-name" id="id-name"></div>
                    </label>
                </div>

                <button type="submit" class="btn btn-primary btn-block btn-lg">
                    Create Account
                </button>
            </form>

            <div class="auth-footer">
                Already have an account? <a href="<?php echo site_url('login.php'); ?>">Login here</a>
            </div>
        </div>
    </div>

    <script>
        // File upload preview
        document.getElementById('selfie').addEventListener('change', function(e) {
            const fileName = e.target.files[0]?.name;
            const label = document.getElementById('selfie-label');
            const nameDiv = document.getElementById('selfie-name');
            
            if (fileName) {
                nameDiv.textContent = '✓ ' + fileName;
                label.classList.add('has-file');
            } else {
                nameDiv.textContent = '';
                label.classList.remove('has-file');
            }
        });

        document.getElementById('id_document').addEventListener('change', function(e) {
            const fileName = e.target.files[0]?.name;
            const label = document.getElementById('id-label');
            const nameDiv = document.getElementById('id-name');
            
            if (fileName) {
                nameDiv.textContent = '✓ ' + fileName;
                label.classList.add('has-file');
            } else {
                nameDiv.textContent = '';
                label.classList.remove('has-file');
            }
        });

        // Password match validation
        document.getElementById('confirm_password').addEventListener('input', function() {
            const password = document.getElementById('password').value;
            const confirm = this.value;
            
            if (confirm && password !== confirm) {
                this.setCustomValidity('Passwords do not match');
            } else {
                this.setCustomValidity('');
            }
        });
    </script>
</body>
</html>