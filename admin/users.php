<?php
require_once '../config/config.php';
require_once '../classes/User.php';

// Require admin role
require_role(['admin', 'manager']);

$database = new Database();
$db = $database->getConnection();
$user = new User($db);

$success = '';
$errors = [];

// Handle user actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_fail();
    $action = $_POST['action'] ?? '';
    $user_id = intval($_POST['user_id'] ?? 0);
    
    switch ($action) {
        case 'verify':
            if ($user->updateVerificationStatus($user_id, 'verified')) {
                $success = "User verified successfully";
                log_audit(get_user_id(), 'USER_VERIFIED', 'users', $user_id);
                
                // Send notification
                $notif_query = "INSERT INTO notifications (user_id, notification_type, title, message) 
                              VALUES (:user_id, 'system', 'Account Verified!', 
                                     'Your account has been verified. You can now apply for loans.')";
                $notif_stmt = $db->prepare($notif_query);
                $notif_stmt->execute([':user_id' => $user_id]);
            } else {
                $errors[] = "Failed to verify user";
            }
            break;
            
        case 'reject_verification':
            if ($user->updateVerificationStatus($user_id, 'rejected')) {
                $success = "Verification rejected";
                log_audit(get_user_id(), 'USER_VERIFICATION_REJECTED', 'users', $user_id);
            } else {
                $errors[] = "Failed to reject verification";
            }
            break;
            
        case 'suspend':
            if ($user->updateAccountStatus($user_id, 'suspended')) {
                $success = "User account suspended";
                log_audit(get_user_id(), 'USER_SUSPENDED', 'users', $user_id);
                
                // Send notification
                $notif_query = "INSERT INTO notifications (user_id, notification_type, title, message) 
                              VALUES (:user_id, 'system', 'Account Suspended', 
                                     'Your account has been suspended. Please contact support.')";
                $notif_stmt = $db->prepare($notif_query);
                $notif_stmt->execute([':user_id' => $user_id]);
            } else {
                $errors[] = "Failed to suspend user";
            }
            break;
            
        case 'activate':
            if ($user->updateAccountStatus($user_id, 'active')) {
                $success = "User account activated";
                log_audit(get_user_id(), 'USER_ACTIVATED', 'users', $user_id);
            } else {
                $errors[] = "Failed to activate user";
            }
            break;
            
        case 'adjust_credit':
            $new_score = intval($_POST['credit_score'] ?? 0);
            $reason = sanitize_input($_POST['reason'] ?? '');
            
            if ($new_score >= 300 && $new_score <= 850) {
                if ($user->updateCreditScore($user_id, $new_score, $reason)) {
                    $success = "Credit score updated successfully";
                    log_audit(get_user_id(), 'CREDIT_SCORE_ADJUSTED', 'users', $user_id, null, ['new_score' => $new_score, 'reason' => $reason]);
                } else {
                    $errors[] = "Failed to update credit score";
                }
            } else {
                $errors[] = "Credit score must be between 300 and 850";
            }
            break;
    }
}

// Get filters
$filter_status = $_GET['status'] ?? 'all';
$filter_verification = $_GET['verification'] ?? 'all';
$search = sanitize_input($_GET['search'] ?? '');

// Build filters
$filters = ['role' => 'customer'];
if ($filter_status !== 'all') {
    $filters['account_status'] = $filter_status;
}
if ($filter_verification !== 'all') {
    $filters['verification_status'] = $filter_verification;
}
if ($search) {
    $filters['search'] = $search;
}

// Get users
$users = $user->getAllUsers($filters);

// Count statistics
$total_users = count($user->getAllUsers(['role' => 'customer']));
$verified_users = count($user->getAllUsers(['role' => 'customer', 'verification_status' => 'verified']));
$pending_users = count($user->getAllUsers(['role' => 'customer', 'verification_status' => 'pending']));
$active_users = count($user->getAllUsers(['role' => 'customer', 'account_status' => 'active']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo asset_url('assets/css/style.css'); ?>">
    <!-- FontAwesome icons -->
    <link rel="stylesheet" href="<?php echo asset_url('assets/css/fontawesome-all.min.css'); ?>" />
    <style>
        .filter-bar {
            background: var(--dark-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            align-items: end;
        }

        .filter-group {
            flex: 1;
            min-width: 200px;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-green), var(--primary-dark-green));
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            color: var(--dark-bg);
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: var(--dark-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 2rem;
            max-width: 500px;
            width: 100%;
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
            <img src="../assets/images/logo.png" alt="<?php echo SITE_NAME; ?>" onerror="this.style.display='none'">
            <span><?php echo SITE_NAME; ?></span>
        </div>

        <ul class="sidebar-menu">
            <li><a href="<?php echo site_url('admin/dashboard.php'); ?>">
                <i class="fas fa-chart-bar"></i> Dashboard
            </a></li>
            <li><a href="<?php echo site_url('admin/users.php'); ?>" class="active">
                <i class="fas fa-users"></i> Users
            </a></li>
            <li><a href="<?php echo site_url('admin/loans.php'); ?>">
                <i class="fas fa-wallet"></i> Loans
            </a></li>
            <li><a href="<?php echo site_url('admin/payments.php'); ?>">
                <i class="fas fa-credit-card"></i> Payments
            </a></li>
            <li><a href="<?php echo site_url('admin/verifications.php'); ?>">
                <i class="fas fa-check-circle"></i> Verifications
            </a></li>
            <li><a href="<?php echo site_url('admin/reports.php'); ?>">
                <i class="fas fa-chart-bar"></i> Reports
            </a></li>
            <li><a href="<?php echo site_url('admin/platform-accounts.php'); ?>">
                <i class="fas fa-university"></i> Platform Accounts
            </a></li>
            <li><a href="<?php echo site_url('admin/settings.php'); ?>">
                <i class="fas fa-cog"></i> Settings
            </a></li>
            <li style="margin-top: auto; padding-top: 1rem; border-top: 1px solid var(--border-color);">
                <a href="<?php echo site_url('dashboard.php'); ?>">
                    <i class="fas fa-user"></i> User View
                </a>
            </li>
            <li><a href="../logout.php">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a></li>
        </ul>
    </aside>

    <!-- Main Content -->
    <div class="main-wrapper">
        <div class="main-content">
            <h1>User Management</h1>
            <p class="text-secondary mb-4">Manage user accounts, verifications, and credit scores</p>

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

            <!-- Statistics -->
            <div class="stats-grid mb-4">
                <div class="stat-card">
                    <div class="stat-label">Total Users</div>
                    <div class="stat-value"><?php echo $total_users; ?></div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Verified Users</div>
                    <div class="stat-value" style="color: var(--success);"><?php echo $verified_users; ?></div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Pending Verification</div>
                    <div class="stat-value" style="color: var(--warning);"><?php echo $pending_users; ?></div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Active Accounts</div>
                    <div class="stat-value"><?php echo $active_users; ?></div>
                </div>
            </div>

            <!-- Filters -->
            <form method="GET" class="filter-bar">
                <div class="filter-group">
                    <label class="form-label">Search</label>
                    <input type="text" name="search" class="form-control" placeholder="Name, email, or ID..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                </div>

                <div class="filter-group">
                    <label class="form-label">Account Status</label>
                    <select name="status" class="form-control">
                        <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All Status</option>
                        <option value="active" <?php echo $filter_status === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="suspended" <?php echo $filter_status === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                        <option value="closed" <?php echo $filter_status === 'closed' ? 'selected' : ''; ?>>Closed</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label class="form-label">Verification</label>
                    <select name="verification" class="form-control">
                        <option value="all" <?php echo $filter_verification === 'all' ? 'selected' : ''; ?>>All</option>
                        <option value="verified" <?php echo $filter_verification === 'verified' ? 'selected' : ''; ?>>Verified</option>
                        <option value="pending" <?php echo $filter_verification === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="rejected" <?php echo $filter_verification === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                    </select>
                </div>

                <div>
                    <button type="submit" class="btn btn-primary">Apply Filters</button>
                </div>
            </form>

            <!-- Users Table -->
            <div class="card">
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Contact</th>
                                <th>Credit Score</th>
                                <th>Verification</th>
                                <th>Status</th>
                                <th>Joined</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($users)): ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; padding: 3rem; color: var(--text-secondary);">
                                        No users found
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($users as $u): ?>
                                    <tr>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 1rem;">
                                                <div class="user-avatar">
                                                    <?php echo strtoupper(substr($u['full_name'], 0, 2)); ?>
                                                </div>
                                                <div>
                                                    <div style="font-weight: 600;"><?php echo htmlspecialchars($u['full_name']); ?></div>
                                                    <div class="text-secondary" style="font-size: 0.875rem;">
                                                        ID: <?php echo htmlspecialchars($u['national_id']); ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div><?php echo htmlspecialchars($u['email']); ?></div>
                                            <div class="text-secondary" style="font-size: 0.875rem;">
                                                <?php echo htmlspecialchars($u['phone']); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <strong style="color: <?php 
                                                echo $u['credit_score'] >= 700 ? 'var(--success)' : 
                                                     ($u['credit_score'] >= 500 ? 'var(--warning)' : 'var(--error)');
                                            ?>">
                                                <?php echo $u['credit_score']; ?>
                                            </strong>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?php 
                                                echo match($u['verification_status']) {
                                                    'verified' => 'success',
                                                    'pending' => 'warning',
                                                    'rejected' => 'danger',
                                                    default => 'secondary'
                                                };
                                            ?>">
                                                <?php echo ucfirst($u['verification_status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?php 
                                                echo match($u['account_status']) {
                                                    'active' => 'success',
                                                    'suspended' => 'danger',
                                                    'closed' => 'secondary',
                                                    default => 'secondary'
                                                };
                                            ?>">
                                                <?php echo ucfirst($u['account_status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo format_date($u['created_at']); ?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <a href="user-details.php?id=<?php echo $u['user_id']; ?>" 
                                                   class="btn btn-secondary btn-sm">View</a>
                                                
                                                <button onclick="openAdjustCreditModal(<?php echo $u['user_id']; ?>, <?php echo $u['credit_score']; ?>, '<?php echo htmlspecialchars($u['full_name']); ?>')" 
                                                        class="btn btn-secondary btn-sm">
                                                    Credit
                                                </button>

                                                <a href="<?php echo site_url('admin/assess_score.php?user_id=' . $u['user_id']); ?>" class="btn btn-secondary btn-sm" target="_blank" rel="noopener">Assess</a>
                                                
                                                <?php if ($u['verification_status'] === 'pending'): ?>
                                                    <form method="POST" style="display: inline;">
                                                        <?php echo csrf_input(); ?>
                                                        <input type="hidden" name="user_id" value="<?php echo $u['user_id']; ?>">
                                                        <input type="hidden" name="action" value="verify">
                                                        <button type="submit" class="btn btn-sm" style="background: var(--success); color: white;">
                                                            Verify
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                                
                                                <?php if ($u['account_status'] === 'active'): ?>
                                                    <form method="POST" style="display: inline;" 
                                                          onsubmit="return confirm('Suspend this user account?')">
                                                        <?php echo csrf_input(); ?>
                                                        <input type="hidden" name="user_id" value="<?php echo $u['user_id']; ?>">
                                                        <input type="hidden" name="action" value="suspend">
                                                        <button type="submit" class="btn btn-danger btn-sm">
                                                            Suspend
                                                        </button>
                                                    </form>
                                                <?php elseif ($u['account_status'] === 'suspended'): ?>
                                                    <form method="POST" style="display: inline;">
                                                        <?php echo csrf_input(); ?>
                                                        <input type="hidden" name="user_id" value="<?php echo $u['user_id']; ?>">
                                                        <input type="hidden" name="action" value="activate">
                                                        <button type="submit" class="btn btn-sm" style="background: var(--success); color: white;">
                                                            Activate
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Adjust Credit Score Modal -->
    <div class="modal" id="creditModal">
        <div class="modal-content">
            <h3 style="margin-bottom: 1rem;">Adjust Credit Score</h3>
            <p class="text-secondary mb-3" id="modalUserName"></p>
            
            <form method="POST">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="user_id" id="modalUserId">
                <input type="hidden" name="action" value="adjust_credit">
                
                <div class="form-group">
                    <label class="form-label">New Credit Score</label>
                    <input type="number" name="credit_score" id="modalCreditScore" 
                           class="form-control" min="300" max="850" required>
                    <small class="form-text">Score must be between 300 and 850</small>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Reason for Adjustment</label>
                    <textarea name="reason" class="form-control" rows="3" 
                              placeholder="Explain why the credit score is being adjusted..." required></textarea>
                </div>
                
                <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                    <button type="submit" class="btn btn-primary" style="flex: 1;">Update Score</button>
                    <button type="button" onclick="closeCreditModal()" class="btn btn-secondary" style="flex: 1;">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openAdjustCreditModal(userId, currentScore, userName) {
            document.getElementById('modalUserId').value = userId;
            document.getElementById('modalCreditScore').value = currentScore;
            document.getElementById('modalUserName').textContent = 'Current score for ' + userName + ': ' + currentScore;
            document.getElementById('creditModal').classList.add('active');
        }

        function closeCreditModal() {
            document.getElementById('creditModal').classList.remove('active');
        }

        // Close modal on outside click
        document.getElementById('creditModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeCreditModal();
            }
        });
    </script>
    <script src="<?php echo asset_url('assets/js/sidebar.js'); ?>"></script>
</body>
</html>
