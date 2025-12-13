<?php
require_once '../config/config.php';

// Require admin or manager role
require_role(['admin', 'manager']);

$database = new Database();
$db = $database->getConnection();

// Get platform statistics
$stats = [];

// Total users
$user_query = "SELECT 
                COUNT(*) as total_users,
                SUM(CASE WHEN verification_status = 'verified' THEN 1 ELSE 0 END) as verified_users,
                SUM(CASE WHEN verification_status = 'pending' THEN 1 ELSE 0 END) as pending_verification,
                SUM(CASE WHEN account_status = 'active' THEN 1 ELSE 0 END) as active_users
               FROM users WHERE role = 'customer'";
$user_stmt = $db->prepare($user_query);
$user_stmt->execute();
$stats['users'] = $user_stmt->fetch(PDO::FETCH_ASSOC);

// Loan statistics
$loan_query = "SELECT 
                COUNT(*) as total_loans,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_loans,
                SUM(CASE WHEN status IN ('approved', 'disbursed', 'active') THEN 1 ELSE 0 END) as active_loans,
                SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) as overdue_loans,
                SUM(CASE WHEN status = 'repaid' THEN 1 ELSE 0 END) as repaid_loans,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_loans,
                SUM(loan_amount) as total_disbursed,
                SUM(remaining_balance) as total_outstanding
               FROM loans";
$loan_stmt = $db->prepare($loan_query);
$loan_stmt->execute();
$stats['loans'] = $loan_stmt->fetch(PDO::FETCH_ASSOC);

// Payment statistics
$payment_query = "SELECT 
                   COUNT(*) as total_payments,
                   SUM(payment_amount) as total_collected,
                   SUM(CASE WHEN payment_status = 'completed' THEN payment_amount ELSE 0 END) as successful_amount
                  FROM repayments";
$payment_stmt = $db->prepare($payment_query);
$payment_stmt->execute();
$stats['payments'] = $payment_stmt->fetch(PDO::FETCH_ASSOC);

// Calculate rates
$approval_rate = $stats['loans']['total_loans'] > 0 
    ? round((($stats['loans']['active_loans'] + $stats['loans']['repaid_loans']) / $stats['loans']['total_loans']) * 100) 
    : 0;

$default_rate = $stats['loans']['total_loans'] > 0 
    ? round(($stats['loans']['overdue_loans'] / $stats['loans']['total_loans']) * 100, 1) 
    : 0;

$repayment_rate = $stats['loans']['total_disbursed'] > 0 
    ? round((($stats['loans']['total_disbursed'] - $stats['loans']['total_outstanding']) / $stats['loans']['total_disbursed']) * 100, 1) 
    : 0;

// Recent activities
$activity_query = "SELECT 'loan' as type, l.loan_id as id, l.user_id, u.full_name, l.loan_amount as amount, 
                          l.status, l.created_at as activity_date
                   FROM loans l
                   JOIN users u ON l.user_id = u.user_id
                   ORDER BY l.created_at DESC
                   LIMIT 10";
$activity_stmt = $db->prepare($activity_query);
$activity_stmt->execute();
$recent_activities = $activity_stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent payments
$recent_payments_query = "SELECT r.*, u.full_name, l.loan_id 
                          FROM repayments r
                          JOIN users u ON r.user_id = u.user_id
                          JOIN loans l ON r.loan_id = l.loan_id
                          ORDER BY r.payment_date DESC
                          LIMIT 10";
$recent_payments_stmt = $db->prepare($recent_payments_query);
$recent_payments_stmt->execute();
$recent_payments = $recent_payments_stmt->fetchAll(PDO::FETCH_ASSOC);

// Pending verifications
$pending_verifications_query = "SELECT * FROM users 
                                WHERE verification_status = 'pending' 
                                AND role = 'customer'
                                ORDER BY created_at DESC
                                LIMIT 5";
$pending_stmt = $db->prepare($pending_verifications_query);
$pending_stmt->execute();
$pending_verifications = $pending_stmt->fetchAll(PDO::FETCH_ASSOC);

// High risk loans
$high_risk_query = "SELECT l.*, u.full_name, u.email, u.credit_score
                    FROM loans l
                    JOIN users u ON l.user_id = u.user_id
                    WHERE l.status = 'overdue'
                    ORDER BY l.due_date ASC
                    LIMIT 5";
$high_risk_stmt = $db->prepare($high_risk_query);
$high_risk_stmt->execute();
$high_risk_loans = $high_risk_stmt->fetchAll(PDO::FETCH_ASSOC);

// Daily loan applications (last 7 days)
$daily_loans_query = "SELECT DATE(created_at) as date, COUNT(*) as count
                      FROM loans
                      WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                      GROUP BY DATE(created_at)
                      ORDER BY date ASC";
$daily_loans_stmt = $db->prepare($daily_loans_query);
$daily_loans_stmt->execute();
$daily_loans = $daily_loans_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .admin-header {
            background: var(--dark-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .metric-card {
            background: var(--dark-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1.5rem;
            transition: all 0.3s ease;
        }

        .metric-card:hover {
            border-color: var(--primary-green);
            transform: translateY(-2px);
        }

        .metric-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 1rem;
        }

        .metric-icon {
            width: 48px;
            height: 48px;
            background: rgba(16, 185, 129, 0.1);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }

        .metric-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 0.25rem;
        }

        .metric-label {
            font-size: 0.875rem;
            color: var(--text-secondary);
        }

        .metric-change {
            font-size: 0.875rem;
            margin-top: 0.5rem;
        }

        .metric-change.positive {
            color: var(--success);
        }

        .metric-change.negative {
            color: var(--error);
        }

        .activity-item {
            display: flex;
            align-items: center;
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
            transition: background 0.3s ease;
        }

        .activity-item:hover {
            background: var(--dark-card-hover);
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-icon {
            width: 40px;
            height: 40px;
            background: rgba(16, 185, 129, 0.1);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1rem;
            font-size: 1.25rem;
        }

        .activity-content {
            flex: 1;
        }

        .activity-time {
            font-size: 0.75rem;
            color: var(--text-muted);
            white-space: nowrap;
        }

        .chart-container {
            height: 200px;
            position: relative;
        }

        .chart-bar {
            display: flex;
            align-items: flex-end;
            height: 100%;
            gap: 0.5rem;
        }

        .bar {
            flex: 1;
            background: linear-gradient(to top, var(--primary-green), var(--primary-dark-green));
            border-radius: 4px 4px 0 0;
            min-height: 10px;
            position: relative;
            transition: all 0.3s ease;
        }

        .bar:hover {
            opacity: 0.8;
        }

        .bar-label {
            position: absolute;
            bottom: -25px;
            left: 50%;
            transform: translateX(-50%);
            font-size: 0.75rem;
            color: var(--text-secondary);
            white-space: nowrap;
        }

        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .quick-action-btn {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            background: var(--dark-card);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            text-decoration: none;
            color: var(--text-primary);
            transition: all 0.3s ease;
        }

        .quick-action-btn:hover {
            border-color: var(--primary-green);
            transform: translateY(-2px);
        }

        .quick-action-icon {
            font-size: 1.5rem;
        }
    </style>
</head>
<body>
    <div class="gradient-overlay"></div>

    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-brand">
            <img src="../assets/images/logo.png" alt="<?php echo SITE_NAME; ?>" onerror="this.style.display='none'">
            <span><?php echo SITE_NAME; ?></span>
        </div>

        <ul class="sidebar-menu">
            <li><a href="dashboard.php" class="active">
                <i>📊</i> Dashboard
            </a></li>
            <li><a href="users.php">
                <i>👥</i> Users
            </a></li>
            <li><a href="loans.php">
                <i>💰</i> Loans
            </a></li>
            <li><a href="payments.php">
                <i>💳</i> Payments
            </a></li>
            <li><a href="verifications.php">
                <i>✅</i> Verifications
                <?php if (count($pending_verifications) > 0): ?>
                    <span class="badge badge-warning" style="margin-left: auto; font-size: 0.75rem;">
                        <?php echo count($pending_verifications); ?>
                    </span>
                <?php endif; ?>
            </a></li>
            <li><a href="reports.php">
                <i>📈</i> Reports
            </a></li>
            <li><a href="settings.php">
                <i>⚙️</i> Settings
            </a></li>
            <li style="margin-top: auto; padding-top: 1rem; border-top: 1px solid var(--border-color);">
                <a href="../dashboard.php">
                    <i>👤</i> User View
                </a>
            </li>
            <li><a href="../logout.php">
                <i>🚪</i> Logout
            </a></li>
        </ul>
    </aside>

    <!-- Main Content -->
    <div class="main-wrapper">
        <div class="main-content">
            <!-- Header -->
            <div class="admin-header">
                <div class="flex-between">
                    <div>
                        <h1>Admin Dashboard</h1>
                        <p class="text-secondary">Welcome back, <?php echo htmlspecialchars($_SESSION['full_name']); ?>! Here's your platform overview.</p>
                    </div>
                    <div>
                        <span class="badge badge-success">Admin</span>
                    </div>
                </div>
            </div>

            <!-- Key Metrics -->
            <div class="stats-grid mb-4">
                <div class="metric-card">
                    <div class="metric-header">
                        <div>
                            <div class="metric-value"><?php echo number_format($stats['users']['total_users']); ?></div>
                            <div class="metric-label">Total Users</div>
                        </div>
                        <div class="metric-icon">👥</div>
                    </div>
                    <div class="metric-change">
                        <?php echo number_format($stats['users']['verified_users']); ?> verified
                    </div>
                </div>

                <div class="metric-card">
                    <div class="metric-header">
                        <div>
                            <div class="metric-value"><?php echo format_currency($stats['loans']['total_disbursed']); ?></div>
                            <div class="metric-label">Total Disbursed</div>
                        </div>
                        <div class="metric-icon">💰</div>
                    </div>
                    <div class="metric-change">
                        <?php echo number_format($stats['loans']['total_loans']); ?> loans
                    </div>
                </div>

                <div class="metric-card">
                    <div class="metric-header">
                        <div>
                            <div class="metric-value"><?php echo format_currency($stats['loans']['total_outstanding']); ?></div>
                            <div class="metric-label">Outstanding Balance</div>
                        </div>
                        <div class="metric-icon">📊</div>
                    </div>
                    <div class="metric-change">
                        <?php echo number_format($stats['loans']['active_loans']); ?> active loans
                    </div>
                </div>

                <div class="metric-card">
                    <div class="metric-header">
                        <div>
                            <div class="metric-value"><?php echo $repayment_rate; ?>%</div>
                            <div class="metric-label">Repayment Rate</div>
                        </div>
                        <div class="metric-icon">✅</div>
                    </div>
                    <div class="metric-change <?php echo $default_rate > 10 ? 'negative' : 'positive'; ?>">
                        <?php echo $default_rate; ?>% default rate
                    </div>
                </div>
            </div>

            <!-- Secondary Metrics -->
            <div class="stats-grid mb-4" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
                <div class="stat-card">
                    <div class="stat-label">Pending Loans</div>
                    <div class="stat-value" style="color: var(--warning);"><?php echo $stats['loans']['pending_loans']; ?></div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Overdue Loans</div>
                    <div class="stat-value" style="color: var(--error);"><?php echo $stats['loans']['overdue_loans']; ?></div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Approval Rate</div>
                    <div class="stat-value" style="color: var(--success);"><?php echo $approval_rate; ?>%</div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Total Collected</div>
                    <div class="stat-value" style="color: var(--success); font-size: 1.25rem;">
                        <?php echo format_currency($stats['payments']['successful_amount']); ?>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Pending Verifications</div>
                    <div class="stat-value" style="color: var(--warning);"><?php echo $stats['users']['pending_verification']; ?></div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Repaid Loans</div>
                    <div class="stat-value" style="color: var(--info);"><?php echo $stats['loans']['repaid_loans']; ?></div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="card mb-4">
                <div class="card-header">
                    <h3 class="card-title">Quick Actions</h3>
                </div>
                <div style="padding: 1rem;">
                    <div class="quick-actions">
                        <a href="verifications.php" class="quick-action-btn">
                            <span class="quick-action-icon">✅</span>
                            <div>
                                <strong>Verify Users</strong>
                                <p class="text-secondary" style="margin: 0; font-size: 0.75rem;">
                                    <?php echo $stats['users']['pending_verification']; ?> pending
                                </p>
                            </div>
                        </a>

                        <a href="loans.php?status=pending" class="quick-action-btn">
                            <span class="quick-action-icon">💰</span>
                            <div>
                                <strong>Review Loans</strong>
                                <p class="text-secondary" style="margin: 0; font-size: 0.75rem;">
                                    <?php echo $stats['loans']['pending_loans']; ?> pending
                                </p>
                            </div>
                        </a>

                        <a href="loans.php?status=overdue" class="quick-action-btn">
                            <span class="quick-action-icon">⚠️</span>
                            <div>
                                <strong>Manage Overdue</strong>
                                <p class="text-secondary" style="margin: 0; font-size: 0.75rem;">
                                    <?php echo $stats['loans']['overdue_loans']; ?> overdue
                                </p>
                            </div>
                        </a>

                        <a href="reports.php" class="quick-action-btn">
                            <span class="quick-action-icon">📈</span>
                            <div>
                                <strong>View Reports</strong>
                                <p class="text-secondary" style="margin: 0; font-size: 0.75rem;">Generate insights</p>
                            </div>
                        </a>
                    </div>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 2rem; margin-bottom: 2rem;">
                <!-- Loan Applications Chart -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Loan Applications (Last 7 Days)</h3>
                    </div>
                    <div style="padding: 2rem;">
                        <div class="chart-container">
                            <div class="chart-bar">
                                <?php 
                                $max_count = max(array_column($daily_loans, 'count')) ?: 1;
                                foreach ($daily_loans as $day): 
                                    $height = ($day['count'] / $max_count) * 100;
                                ?>
                                    <div class="bar" style="height: <?php echo $height; ?>%;" title="<?php echo $day['count']; ?> applications">
                                        <span class="bar-label"><?php echo date('M d', strtotime($day['date'])); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- High Risk Loans -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">High Risk Loans</h3>
                        <p class="card-subtitle">Overdue loans requiring attention</p>
                    </div>
                    <?php if (empty($high_risk_loans)): ?>
                        <div style="padding: 2rem; text-align: center; color: var(--text-secondary);">
                            <div style="font-size: 2rem; margin-bottom: 0.5rem;">✅</div>
                            <p style="font-size: 0.875rem;">No high risk loans</p>
                        </div>
                    <?php else: ?>
                        <div>
                            <?php foreach ($high_risk_loans as $risk_loan): ?>
                                <div class="activity-item">
                                    <div class="activity-icon">⚠️</div>
                                    <div class="activity-content">
                                        <div style="font-weight: 500;">
                                            <?php echo htmlspecialchars($risk_loan['full_name']); ?>
                                        </div>
                                        <div class="text-secondary" style="font-size: 0.875rem;">
                                            <?php echo format_currency($risk_loan['remaining_balance']); ?> overdue
                                        </div>
                                    </div>
                                    <div class="activity-time">
                                        Due: <?php echo format_date($risk_loan['due_date']); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem;">
                <!-- Recent Activities -->
                <div class="card">
                    <div class="card-header flex-between">
                        <h3 class="card-title">Recent Activities</h3>
                        <a href="loans.php" class="text-secondary" style="font-size: 0.875rem;">View all →</a>
                    </div>
                    <div>
                        <?php foreach (array_slice($recent_activities, 0, 5) as $activity): ?>
                            <div class="activity-item">
                                <div class="activity-icon">
                                    <?php 
                                    echo match($activity['status']) {
                                        'pending' => '⏳',
                                        'approved' => '✅',
                                        'rejected' => '❌',
                                        'active' => '💰',
                                        'repaid' => '✔️',
                                        'overdue' => '⚠️',
                                        default => '📄'
                                    };
                                    ?>
                                </div>
                                <div class="activity-content">
                                    <div style="font-weight: 500;">
                                        <?php echo htmlspecialchars($activity['full_name']); ?>
                                    </div>
                                    <div class="text-secondary" style="font-size: 0.875rem;">
                                        Applied for <?php echo format_currency($activity['amount']); ?>
                                        <span class="badge badge-<?php 
                                            echo match($activity['status']) {
                                                'approved', 'active' => 'success',
                                                'pending' => 'warning',
                                                'rejected' => 'danger',
                                                'repaid' => 'info',
                                                default => 'secondary'
                                            };
                                        ?>" style="margin-left: 0.5rem; font-size: 0.7rem;">
                                            <?php echo ucfirst($activity['status']); ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="activity-time">
                                    <?php echo date('M d', strtotime($activity['activity_date'])); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Recent Payments -->
                <div class="card">
                    <div class="card-header flex-between">
                        <h3 class="card-title">Recent Payments</h3>
                        <a href="payments.php" class="text-secondary" style="font-size: 0.875rem;">View all →</a>
                    </div>
                    <div>
                        <?php foreach (array_slice($recent_payments, 0, 5) as $payment): ?>
                            <div class="activity-item">
                                <div class="activity-icon" style="background: rgba(16, 185, 129, 0.2);">💳</div>
                                <div class="activity-content">
                                    <div style="font-weight: 500;">
                                        <?php echo htmlspecialchars($payment['full_name']); ?>
                                    </div>
                                    <div class="text-secondary" style="font-size: 0.875rem;">
                                        Paid <?php echo format_currency($payment['payment_amount']); ?>
                                        <span class="badge badge-success" style="margin-left: 0.5rem; font-size: 0.7rem;">
                                            <?php echo ucfirst(str_replace('_', ' ', $payment['payment_method'])); ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="activity-time">
                                    <?php echo date('M d', strtotime($payment['payment_date'])); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>