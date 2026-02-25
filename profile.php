<?php
require_once 'config/config.php';
require_once 'classes/User.php';

// Require login
require_login();

$database = new Database();
$db = $database->getConnection();
$user = new User($db);

$success = '';
$errors = [];

// Get user data
$user_data = $user->getUserById(get_user_id());

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_profile') {
        $full_name = sanitize_input($_POST['full_name'] ?? '');
        $email = sanitize_input($_POST['email'] ?? '');
        $phone = sanitize_input($_POST['phone'] ?? '');
        
        // Validation
        if (empty($full_name)) {
            $errors[] = "Full name is required";
        }
        
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Valid email is required";
        }
        
        if (empty($phone)) {
            $errors[] = "Phone number is required";
        }
        
        // Check if email already exists for another user
        if (empty($errors)) {
            $check_query = "SELECT user_id FROM users WHERE email = :email AND user_id != :user_id";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->execute([':email' => $email, ':user_id' => get_user_id()]);
            
            if ($check_stmt->rowCount() > 0) {
                $errors[] = "Email is already in use by another account";
            }
        }
        
        // Update profile
        if (empty($errors)) {
            $update_data = [
                'full_name' => $full_name,
                'email' => $email,
                'phone' => $phone
            ];
            
            if ($user->updateProfile(get_user_id(), $update_data)) {
                $success = "Profile updated successfully";
                $_SESSION['full_name'] = $full_name;
                $_SESSION['email'] = $email;
                
                log_audit(get_user_id(), 'PROFILE_UPDATED', 'users', get_user_id(), null, $update_data);
                
                // Refresh user data
                $user_data = $user->getUserById(get_user_id());
            } else {
                $errors[] = "Failed to update profile";
            }
        }
        
    } elseif ($action === 'change_password') {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        // Validation
        if (empty($current_password)) {
            $errors[] = "Current password is required";
        }
        
        if (empty($new_password)) {
            $errors[] = "New password is required";
        } elseif (strlen($new_password) < 6) {
            $errors[] = "New password must be at least 6 characters";
        }
        
        if ($new_password !== $confirm_password) {
            $errors[] = "New passwords do not match";
        }
        
        // Verify current password
        if (empty($errors)) {
            if (!password_verify($current_password, $user_data['password_hash'])) {
                $errors[] = "Current password is incorrect";
            }
        }
        
        // Update password
        if (empty($errors)) {
            if ($user->updatePassword(get_user_id(), $new_password)) {
                $success = "Password changed successfully";
                log_audit(get_user_id(), 'PASSWORD_CHANGED', 'users', get_user_id());
            } else {
                $errors[] = "Failed to change password";
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
    <title>My Profile - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <!-- FontAwesome icons -->
    <link rel="stylesheet" href="assets/css/fontawesome-all.min.css" />
    <style>
        .profile-header {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.2) 0%, rgba(16, 185, 129, 0.05) 100%);
            border: 1px solid var(--primary-green);
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 2rem;
        }

        .profile-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-green), var(--primary-dark-green));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            font-weight: 700;
            color: var(--dark-bg);
            flex-shrink: 0;
        }

        .profile-info h2 {
            margin-bottom: 0.5rem;
        }

        .profile-meta {
            display: flex;
            gap: 2rem;
            margin-top: 1rem;
            flex-wrap: wrap;
        }

        .profile-meta-item {
            display: flex;
            flex-direction: column;
        }

        .profile-meta-label {
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin-bottom: 0.25rem;
        }

        .profile-meta-value {
            font-weight: 600;
            color: var(--text-primary);
        }

        .section-tabs {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            border-bottom: 1px solid var(--border-color);
        }

        .section-tab {
            padding: 1rem 1.5rem;
            color: var(--text-secondary);
            cursor: pointer;
            border-bottom: 2px solid transparent;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .section-tab:hover {
            color: var(--text-primary);
        }

        .section-tab.active {
            color: var(--primary-green);
            border-bottom-color: var(--primary-green);
        }

        .section-content {
            display: none;
        }

        .section-content.active {
            display: block;
        }

        .document-preview {
            background: var(--dark-card);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 1rem;
            text-align: center;
        }

        .document-preview img {
            max-width: 100%;
            max-height: 200px;
            border-radius: 4px;
            margin-bottom: 1rem;
        }

        .info-item {
            display: flex;
            justify-content: space-between;
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
        }

        .info-item:last-child {
            border-bottom: none;
        }

        .info-label {
            color: var(--text-secondary);
        }

        .info-value {
            font-weight: 600;
            color: var(--text-primary);
        }

        @media (max-width: 768px) {
            .profile-header {
                flex-direction: column;
                text-align: center;
            }

            .profile-meta {
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="gradient-overlay"></div>

    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <button id="sidebarToggle" class="sidebar-toggle">
            <i class="fas fa-chevron-left"></i>
        </button>
        <div class="sidebar-brand">
            <img src="assets/images/logo.png" alt="<?php echo SITE_NAME; ?>" onerror="this.style.display='none'">
            <span><?php echo SITE_NAME; ?></span>
        </div>

        <ul class="sidebar-menu">
            <li><a href="<?php echo site_url('dashboard.php'); ?>">
                <i class="fas fa-home"></i> Dashboard
            </a></li>
            <li><a href="<?php echo site_url('loans.php'); ?>">
                <i class="fas fa-wallet"></i> My Loans
            </a></li>
            <li><a href="<?php echo site_url('apply-loan.php'); ?>">
                <i class="fas fa-plus-circle"></i> Apply for Loan
            </a></li>
            <li><a href="<?php echo site_url('repayments.php'); ?>">
                <i class="fas fa-credit-card"></i> Repayments
            </a></li>
            <li><a href="<?php echo site_url('credit-history.php'); ?>">
                <i class="fas fa-chart-line"></i> Credit History
            </a></li>
            <li><a href="<?php echo site_url('notifications.php'); ?>">
                <i class="fas fa-bell"></i> Notifications
            </a></li>
            <li><a href="<?php echo site_url('profile.php'); ?>" class="active">
                <i class="fas fa-user"></i> Profile
            </a></li>
            <li><a href="<?php echo site_url('logout.php'); ?>">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a></li>
        </ul>
    </aside>

    <!-- Main Content -->
    <div class="main-wrapper">
        <div class="main-content">
            <h1>My Profile</h1>
            <p class="text-secondary mb-4">Manage your account settings and personal information</p>

            <?php if ($success): ?>
                <div class="alert alert-success mb-3"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error mb-3">
                    <?php foreach ($errors as $error): ?>
                        <?php echo htmlspecialchars($error); ?><br>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Profile Header -->
            <div class="profile-header">
                <div class="profile-avatar">
                    <?php echo strtoupper(substr($user_data['full_name'], 0, 2)); ?>
                </div>
                <div class="profile-info" style="flex: 1;">
                    <h2><?php echo htmlspecialchars($user_data['full_name']); ?></h2>
                    <p class="text-secondary"><?php echo htmlspecialchars($user_data['email']); ?></p>
                    
                    <div class="profile-meta">
                        <div class="profile-meta-item">
                            <span class="profile-meta-label">National ID</span>
                            <span class="profile-meta-value"><?php echo htmlspecialchars($user_data['national_id']); ?></span>
                        </div>
                        
                        <div class="profile-meta-item">
                            <span class="profile-meta-label">Credit Score</span>
                            <span class="profile-meta-value" style="color: <?php 
                                echo $user_data['credit_score'] >= 700 ? 'var(--success)' : 
                                     ($user_data['credit_score'] >= 500 ? 'var(--warning)' : 'var(--error)');
                            ?>">
                                <?php echo $user_data['credit_score']; ?>
                            </span>
                        </div>
                        
                        <div class="profile-meta-item">
                            <span class="profile-meta-label">Verification Status</span>
                            <span class="badge badge-<?php 
                                echo match($user_data['verification_status']) {
                                    'verified' => 'success',
                                    'pending' => 'warning',
                                    'rejected' => 'danger',
                                    default => 'secondary'
                                };
                            ?>">
                                <?php echo ucfirst($user_data['verification_status']); ?>
                            </span>
                        </div>
                        
                        <div class="profile-meta-item">
                            <span class="profile-meta-label">Member Since</span>
                            <span class="profile-meta-value"><?php echo format_date($user_data['created_at']); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section Tabs -->
            <div class="section-tabs">
                <div class="section-tab active" onclick="switchSection('personal')">Personal Information</div>
                <div class="section-tab" onclick="switchSection('security')">Security</div>
                <div class="section-tab" onclick="switchSection('documents')">Documents</div>
                <div class="section-tab" onclick="switchSection('account')">Account Details</div>
            </div>

            <!-- Personal Information Section -->
            <div id="personal-section" class="section-content active">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Edit Personal Information</h3>
                        <p class="card-subtitle">Update your profile details</p>
                    </div>
                    <div style="padding: 2rem;">
                        <form method="POST">
                            <input type="hidden" name="action" value="update_profile">
                            
                            <div class="form-group">
                                <label for="full_name" class="form-label">Full Name *</label>
                                <input type="text" id="full_name" name="full_name" class="form-control" 
                                       value="<?php echo htmlspecialchars($user_data['full_name']); ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="email" class="form-label">Email Address *</label>
                                <input type="email" id="email" name="email" class="form-control" 
                                       value="<?php echo htmlspecialchars($user_data['email']); ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="phone" class="form-label">Phone Number *</label>
                                <input type="tel" id="phone" name="phone" class="form-control" 
                                       value="<?php echo htmlspecialchars($user_data['phone']); ?>" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label">National ID</label>
                                <input type="text" class="form-control" 
                                       value="<?php echo htmlspecialchars($user_data['national_id']); ?>" 
                                       disabled>
                                <small class="form-text">National ID cannot be changed after registration</small>
                            </div>

                            <button type="submit" class="btn btn-primary">
                                💾 Save Changes
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Security Section -->
            <div id="security-section" class="section-content">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Change Password</h3>
                        <p class="card-subtitle">Update your account password</p>
                    </div>
                    <div style="padding: 2rem;">
                        <form method="POST">
                            <input type="hidden" name="action" value="change_password">
                            
                            <div class="form-group">
                                <label for="current_password" class="form-label">Current Password *</label>
                                <input type="password" id="current_password" name="current_password" 
                                       class="form-control" required>
                            </div>

                            <div class="form-group">
                                <label for="new_password" class="form-label">New Password *</label>
                                <input type="password" id="new_password" name="new_password" 
                                       class="form-control" minlength="6" required>
                                <small class="form-text">Minimum 6 characters</small>
                            </div>

                            <div class="form-group">
                                <label for="confirm_password" class="form-label">Confirm New Password *</label>
                                <input type="password" id="confirm_password" name="confirm_password" 
                                       class="form-control" required>
                            </div>

                            <button type="submit" class="btn btn-primary">
                                🔐 Change Password
                            </button>
                        </form>
                    </div>
                </div>

                <div class="card mt-3">
                    <div class="card-header">
                        <h3 class="card-title">Login History</h3>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Last Login</span>
                        <span class="info-value">
                            <?php echo $user_data['last_login'] ? date('M d, Y g:i A', strtotime($user_data['last_login'])) : 'Never'; ?>
                        </span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Current Session</span>
                        <span class="info-value">
                            <?php echo isset($_SESSION['login_time']) ? date('M d, Y g:i A', $_SESSION['login_time']) : 'Active'; ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Documents Section -->
            <div id="documents-section" class="section-content">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Verification Documents</h3>
                        <p class="card-subtitle">Your uploaded identification documents</p>
                    </div>
                    <div style="padding: 2rem;">
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 2rem;">
                            <!-- Selfie -->
                            <div class="document-preview">
                                <h4 style="margin-bottom: 1rem;">Selfie Photo</h4>
                                <?php if (!empty($user_data['selfie_path'])): ?>

                                    <img src="<?php echo UPLOAD_URL . $user_data['selfie_path']; ?>" alt="Selfie">
                                    <div class="text-secondary" style="font-size: 0.875rem;">
                                        <i class="fas fa-check" style="color: var(--success);"></i> Uploaded and verified
                                    </div>
                                <?php else: ?>
                                    <div style="font-size: 4rem; margin: 2rem 0;"><i class="fas fa-camera"></i></div>
                                    <div class="text-secondary">No selfie uploaded</div>
                                <?php endif; ?>
                            </div>

                            <!-- National ID -->
                            <div class="document-preview">
                                <h4 style="margin-bottom: 1rem;">National ID</h4>
                                <?php if (!empty($user_data['id_document_path'])): ?>

                                    <?php if (strpos($user_data['id_document_path'], '.pdf') !== false): ?>
                                        <div style="font-size: 4rem; margin: 2rem 0;"><i class="fas fa-file-alt"></i></div>
                                        <a href="<?php echo UPLOAD_URL . $user_data['id_document_path']; ?>" 
                                           target="_blank" class="btn btn-secondary btn-sm">
                                            View PDF
                                        </a>
                                    <?php else: ?>
                                        <img src="<?php echo UPLOAD_URL . $user_data['id_document_path']; ?>" alt="National ID">
                                    <?php endif; ?>
                                    <div class="text-secondary" style="font-size: 0.875rem; margin-top: 1rem;">
                                        <i class="fas fa-check" style="color: var(--success);"></i> Uploaded and verified
                                    </div>
                                <?php else: ?>
                                    <div style="font-size: 4rem; margin: 2rem 0;"><i class="fas fa-id-card"></i></div>
                                    <div class="text-secondary">No ID uploaded</div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ($user_data['verification_status'] === 'pending'): ?>
                            <div class="alert alert-warning mt-3">
                                <strong><i class="fas fa-hourglass-half"></i> Verification Pending</strong><br>
                                Your documents are currently being reviewed. You will receive a notification once verified.
                            </div>
                        <?php elseif ($user_data['verification_status'] === 'rejected'): ?>
                            <div class="alert alert-error mt-3">
                                <strong><i class="fas fa-times-circle" style="color: var(--error);"></i> Verification Rejected</strong><br>
                                Your documents were not approved. Please contact support for assistance.
                            </div>
                        <?php else: ?>
                            <div class="alert alert-success mt-3">
                                <strong><i class="fas fa-check-circle" style="color: var(--success);"></i> Documents Verified</strong><br>
                                Your account is fully verified and you can apply for loans.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Account Details Section -->
            <div id="account-section" class="section-content">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Account Information</h3>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Account Type</span>
                        <span class="info-value"><?php echo ucfirst($user_data['role']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Account Status</span>
                        <span class="badge badge-<?php 
                            echo match($user_data['account_status']) {
                                'active' => 'success',
                                'suspended' => 'danger',
                                'closed' => 'secondary',
                                default => 'secondary'
                            };
                        ?>">
                            <?php echo ucfirst($user_data['account_status']); ?>
                        </span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Credit Score</span>
                        <span class="info-value" style="color: <?php 
                            echo $user_data['credit_score'] >= 700 ? 'var(--success)' : 
                                 ($user_data['credit_score'] >= 500 ? 'var(--warning)' : 'var(--error)');
                        ?>">
                            <?php echo $user_data['credit_score']; ?>/850
                        </span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Member Since</span>
                        <span class="info-value"><?php echo format_date($user_data['created_at']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Last Updated</span>
                        <span class="info-value"><?php echo format_date($user_data['updated_at']); ?></span>
                    </div>
                </div>

                <div class="card mt-3">
                    <div class="card-header">
                        <h3 class="card-title" style="color: var(--error);">Danger Zone</h3>
                    </div>
                    <div style="padding: 2rem;">
                        <p class="text-secondary mb-3">
                            Closing your account is permanent and cannot be undone. All your data will be retained for compliance purposes.
                        </p>
                        <button class="btn btn-danger" onclick="alert('Please contact support to close your account.')">
                            🗑️ Close Account
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function switchSection(sectionName) {
            // Hide all sections
            document.querySelectorAll('.section-content').forEach(section => {
                section.classList.remove('active');
            });
            document.querySelectorAll('.section-tab').forEach(tab => {
                tab.classList.remove('active');
            });

            // Show selected section
            document.getElementById(sectionName + '-section').classList.add('active');
            event.target.classList.add('active');
        }

        // Password confirmation validation
        document.getElementById('confirm_password')?.addEventListener('input', function() {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = this.value;
            
            if (confirmPassword && newPassword !== confirmPassword) {
                this.setCustomValidity('Passwords do not match');
            } else {
                this.setCustomValidity('');
            }
        });
    </script>
    <script src="assets/js/sidebar.js"></script>
</body>
</html>