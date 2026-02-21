<?php
require_once 'config/config.php';
require_once 'classes/User.php';

// Require login
require_login();

// Get user data
$database = new Database();
$db = $database->getConnection();
$user = new User($db);

$user_data = $user->getUserById(get_user_id());
$user_stats = $user->getUserStats(get_user_id());

// Get recent loans
$loan_query = "SELECT * FROM loans WHERE user_id = :user_id ORDER BY created_at DESC LIMIT 5";
$loan_stmt = $db->prepare($loan_query);
$loan_stmt->execute([':user_id' => get_user_id()]);
$recent_loans = $loan_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent notifications
$notif_query = "SELECT * FROM notifications WHERE user_id = :user_id ORDER BY created_at DESC LIMIT 5";
$notif_stmt = $db->prepare($notif_query);
$notif_stmt->execute([':user_id' => get_user_id()]);
$notifications = $notif_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="gradient-overlay"></div>

    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-brand">
            <img src="assets/images/logo.png" alt="<?php echo SITE_NAME; ?>" onerror="this.style.display='none'">
            <span><?php echo SITE_NAME; ?></span>
        </div>

        <ul class="sidebar-menu">
            <li><a href="<?php echo site_url('dashboard.php'); ?>" class="active">
                <i>🏠</i> Dashboard
            </a></li>
            <li><a href="<?php echo site_url('loans.php'); ?>">
                <i>💰</i> My Loans
            </a></li>
            <li><a href="<?php echo site_url('apply-loan.php'); ?>">
                <i>➕</i> Apply for Loan
            </a></li>
            <li><a href="<?php echo site_url('repayments.php'); ?>">
                <i>💳</i> Repayments
            </a></li>
            <li><a href="<?php echo site_url('credit-history.php'); ?>">
                <i>📊</i> Credit History
            </a></li>
            <li><a href="<?php echo site_url('notifications.php'); ?>">
                <i>🔔</i> Notifications
                <?php if (count(array_filter($notifications, fn($n) => !$n['is_read'])) > 0): ?>
                    <span class="badge badge-danger" style="margin-left: auto;">
                        <?php echo count(array_filter($notifications, fn($n) => !$n['is_read'])); ?>
                    </span>
                <?php endif; ?>
            </a></li>
            <li><a href="<?php echo site_url('profile.php'); ?>">
                <i>👤</i> Profile
            </a></li>
            <li><a href="<?php echo site_url('logout.php'); ?>">
                <i>🚪</i> Logout
            </a></li>
        </ul>
    </aside>

    <!-- Main Content -->
    <div class="main-wrapper">
        <div class="main-content">
            <!-- Header -->
            <div class="flex-between mb-4">
                <div>
                    <h1>Welcome back, <?php echo htmlspecialchars($_SESSION['full_name']); ?>! 👋</h1>
                    <p class="text-secondary">Here's what's happening with your account today.</p>
                </div>
                <div>
                    <a href="<?php echo site_url('apply-loan.php'); ?>" class="btn btn-primary">Apply for Loan</a>
                </div>
            </div>

            <!-- Verification Alert -->
            <?php if ($user_data['verification_status'] !== 'verified'): ?>
                <div class="alert alert-warning mb-3">
                    <strong>⚠️ Account Verification Pending</strong><br>
                    Your account is currently under verification. You will be able to apply for loans once verified.
                </div>
            <?php endif; ?>

            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-label">Active Loans</div>
                    <div class="stat-value"><?php echo $user_stats['loans']['active_loans'] ?? 0; ?></div>
                    <div class="text-secondary" style="font-size: 0.875rem;">
                        <?php echo $user_stats['loans']['total_loans'] ?? 0; ?> total loans
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Outstanding Balance</div>
                    <div class="stat-value"><?php echo format_currency($user_stats['outstanding_balance']); ?></div>
                    <div class="text-secondary" style="font-size: 0.875rem;">
                        Total amount due
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Next Payment</div>
                    <div class="stat-value">
                        <?php 
                        if ($user_stats['next_payment']) {
                            echo format_currency($user_stats['next_payment']['amount_due']);
                        } else {
                            echo 'None';
                        }
                        ?>
                    </div>
                    <div class="text-secondary" style="font-size: 0.875rem;">
                        <?php 
                        if ($user_stats['next_payment']) {
                            echo 'Due ' . format_date($user_stats['next_payment']['next_due_date']);
                        } else {
                            echo 'No pending payments';
                        }
                        ?>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Credit Score</div>
                    <div class="stat-value" style="color: <?php 
                        echo $user_data['credit_score'] >= 700 ? 'var(--success)' : 
                             ($user_data['credit_score'] >= 500 ? 'var(--warning)' : 'var(--error)');
                    ?>">
                        <?php echo $user_data['credit_score']; ?>
                    </div>
                    <div class="text-secondary" style="font-size: 0.875rem;">
                        <?php 
                        if ($user_data['credit_score'] >= 700) {
                            echo 'Excellent';
                        } elseif ($user_data['credit_score'] >= 500) {
                            echo 'Good';
                        } else {
                            echo 'Fair';
                        }
                        ?>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="card mb-3">
                <div class="card-header">
                    <h3 class="card-title">Quick Actions</h3>
                </div>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                    <a href="<?php echo site_url('apply-loan.php'); ?>" class="btn btn-accent" style="text-decoration: none;">
                        ➕ Apply for New Loan
                    </a>
                    <a href="<?php echo site_url('repayments.php'); ?>" class="btn btn-primary" style="text-decoration: none;">
                        💳 Make Payment
                    </a>
                    <a href="<?php echo site_url('credit-history.php'); ?>" class="btn btn-secondary" style="text-decoration: none;">
                        📊 View Credit Report
                    </a>
                </div>
            </div>

            <!-- Recent Loans -->
            <div class="card mb-3">
                <div class="card-header flex-between">
                    <h3 class="card-title">Recent Loans</h3>
                    <a href="<?php echo site_url('loans.php'); ?>" class="text-secondary" style="font-size: 0.875rem;">View all →</a>
                </div>

                <?php if (empty($recent_loans)): ?>
                    <div style="text-align: center; padding: 2rem; color: var(--text-secondary);">
                        <div style="font-size: 3rem; margin-bottom: 1rem;">💰</div>
                        <p>No loans yet. Apply for your first loan today!</p>
                        <a href="<?php echo site_url('apply-loan.php'); ?>" class="btn btn-primary mt-2">Apply Now</a>
                    </div>
                <?php else: ?>
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>Loan ID</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Due Date</th>
                                    <th>Balance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_loans as $loan): ?>
                                    <tr>
                                        <td>#LML-<?php echo str_pad($loan['loan_id'], 6, '0', STR_PAD_LEFT); ?></td>
                                        <td><?php echo format_currency($loan['loan_amount']); ?></td>
                                        <td>
                                            <span class="badge badge-<?php 
                                                echo match($loan['status']) {
                                                    'approved', 'disbursed', 'active' => 'success',
                                                    'pending' => 'warning',
                                                    'overdue' => 'danger',
                                                    'repaid' => 'info',
                                                    default => 'secondary'
                                                };
                                            ?>">
                                                <?php echo ucfirst($loan['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo $loan['due_date'] ? format_date($loan['due_date']) : 'N/A'; ?></td>
                                        <td><?php echo format_currency($loan['remaining_balance'] ?? $loan['loan_amount']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Recent Notifications -->
            <div class="card">
                <div class="card-header flex-between">
                    <h3 class="card-title">Recent Notifications</h3>
                    <a href="<?php echo site_url('notifications.php'); ?>" class="text-secondary" style="font-size: 0.875rem;">View all →</a>
                </div>

                <?php if (empty($notifications)): ?>
                    <div style="text-align: center; padding: 2rem; color: var(--text-secondary);">
                        <p>No notifications yet.</p>
                    </div>
                <?php else: ?>
                    <div>
                        <?php foreach (array_slice($notifications, 0, 5) as $notification): ?>
                            <div style="padding: 1rem; border-bottom: 1px solid var(--border-color); 
                                        <?php echo !$notification['is_read'] ? 'background: rgba(16, 185, 129, 0.05);' : ''; ?>">
                                <div class="flex-between">
                                    <div>
                                        <strong><?php echo htmlspecialchars($notification['title']); ?></strong>
                                        <p class="text-secondary" style="margin: 0.25rem 0 0 0; font-size: 0.875rem;">
                                            <?php echo htmlspecialchars($notification['message']); ?>
                                        </p>
                                    </div>
                                    <small class="text-muted">
                                        <?php echo date('M d', strtotime($notification['created_at'])); ?>
                                    </small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>