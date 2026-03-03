<?php
require_once 'config/config.php';
require_once 'classes/Loan.php';

// Require login
require_login();

$database = new Database();
$db = $database->getConnection();
$loan = new Loan($db);

// Get filter
$filter_status = $_GET['status'] ?? 'all';

// Get user's loans
if ($filter_status === 'all') {
    $loans = $loan->getUserLoans(get_user_id());
} else {
    $loans = $loan->getUserLoans(get_user_id(), $filter_status);
}

// Calculate statistics
$total_borrowed = 0;
$total_repaid = 0;
$active_loans = 0;
$overdue_loans = 0;

foreach ($loans as $l) {
    if ($l['status'] !== 'rejected') {
        $total_borrowed += $l['loan_amount'];
    }
    if ($l['status'] === 'completed') {
        $total_repaid += $l['total_amount'];
    }
    if ($l['status'] === 'active' || $l['status'] === 'disbursed') {
        $active_loans++;
    }
    if ($l['status'] === 'overdue') {
        $overdue_loans++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Loans - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo asset_url('assets/css/style.css'); ?>">
    <!-- FontAwesome icons -->
    <link rel="stylesheet" href="<?php echo asset_url('assets/css/fontawesome-all.min.css'); ?>" />
    <style>
        .filter-tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }

        .filter-tab {
            padding: 0.75rem 1.5rem;
            background: var(--dark-card);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            color: var(--text-secondary);
            text-decoration: none;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .filter-tab:hover {
            border-color: var(--primary-green);
            color: var(--text-primary);
        }

        .filter-tab.active {
            background: var(--primary-green);
            color: var(--dark-bg);
            border-color: var(--primary-green);
        }

        .loan-card {
            background: var(--dark-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            transition: all 0.3s ease;
        }

        .loan-card:hover {
            border-color: var(--primary-green);
            transform: translateY(-2px);
        }

        .loan-card-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 1rem;
        }

        .loan-card-body {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
        }

        .loan-info-item {
            display: flex;
            flex-direction: column;
        }

        .loan-info-label {
            font-size: 0.75rem;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.25rem;
        }

        .loan-info-value {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--text-secondary);
        }

        .empty-state-icon {
            font-size: 4rem;
            margin-bottom: 1rem;
        }

        @media (max-width: 768px) {
            .loan-card-header {
                flex-direction: column;
                gap: 1rem;
            }

            .loan-card-body {
                grid-template-columns: 1fr;
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
                <i class="fas fa-home"></i> <span class="link-text">Dashboard</span>
            </a></li>
            <li><a href="<?php echo site_url('loans.php'); ?>" class="active">
                <i class="fas fa-wallet"></i> <span class="link-text">My Loans</span>
            </a></li>
            <li><a href="<?php echo site_url('apply-loan.php'); ?>">
                <i class="fas fa-plus-circle"></i> <span class="link-text">Apply for Loan</span>
            </a></li>
            <li><a href="<?php echo site_url('repayments.php'); ?>">
                <i class="fas fa-credit-card"></i> <span class="link-text">Repayments</span>
            </a></li>
            <li><a href="<?php echo site_url('credit-history.php'); ?>">
                <i class="fas fa-chart-line"></i> <span class="link-text">Credit History</span>
            </a></li>
            <li><a href="<?php echo site_url('notifications.php'); ?>">
                <i class="fas fa-bell"></i> <span class="link-text">Notifications</span>
            </a></li>
            <li><a href="<?php echo site_url('profile.php'); ?>">
                <i class="fas fa-user"></i> <span class="link-text">Profile</span>
            </a></li>
            <li><a href="<?php echo site_url('logout.php'); ?>">
                <i class="fas fa-sign-out-alt"></i> <span class="link-text">Logout</span>
            </a></li>
        </ul>
    </aside>

    <!-- Main Content -->
    <div class="main-wrapper">
        <div class="main-content">
            <div class="flex-between mb-4">
                <div>
                    <h1>My Loans</h1>
                    <p class="text-secondary">View and manage all your loan applications</p>
                </div>
                <div>
                    <a href="<?php echo site_url('apply-loan.php'); ?>" class="btn btn-primary">Apply for New Loan</a>
                </div>
            </div>

            <!-- Statistics -->
            <div class="stats-grid mb-4">
                <div class="stat-card">
                    <div class="stat-label">Total Borrowed</div>
                    <div class="stat-value"><?php echo format_currency($total_borrowed); ?></div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Total Repaid</div>
                    <div class="stat-value" style="color: var(--success);"><?php echo format_currency($total_repaid); ?></div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Active Loans</div>
                    <div class="stat-value"><?php echo $active_loans; ?></div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Overdue Loans</div>
                    <div class="stat-value" style="color: <?php echo $overdue_loans > 0 ? 'var(--error)' : 'var(--text-primary)'; ?>">
                        <?php echo $overdue_loans; ?>
                    </div>
                </div>
            </div>

            <!-- Filter Tabs -->
            <div class="filter-tabs">
                <a href="loans.php?status=all" class="filter-tab <?php echo $filter_status === 'all' ? 'active' : ''; ?>">
                    All Loans (<?php echo count($loan->getUserLoans(get_user_id())); ?>)
                </a>
                <a href="loans.php?status=active" class="filter-tab <?php echo $filter_status === 'active' ? 'active' : ''; ?>">
                    Active
                </a>
                <a href="loans.php?status=pending" class="filter-tab <?php echo $filter_status === 'pending' ? 'active' : ''; ?>">
                    Pending
                </a>
                <a href="loans.php?status=approved" class="filter-tab <?php echo $filter_status === 'approved' ? 'active' : ''; ?>">
                    Approved
                </a>
                <a href="loans.php?status=completed" class="filter-tab <?php echo $filter_status === 'completed' ? 'active' : ''; ?>">
                    Repaid
                </a>
                <a href="loans.php?status=overdue" class="filter-tab <?php echo $filter_status === 'overdue' ? 'active' : ''; ?>">
                    Overdue
                </a>
            </div>

            <!-- Loans List -->
            <?php if (empty($loans)): ?>
                <div class="card">
                    <div class="empty-state">
                        <div class="empty-state-icon"><i class="fas fa-wallet"></i></div>
                        <h3>No loans found</h3>
                        <p>
                            <?php if ($filter_status === 'all'): ?>
                                You haven't applied for any loans yet.
                            <?php else: ?>
                                You don't have any <?php echo $filter_status; ?> loans.
                            <?php endif; ?>
                        </p>
                        <a href="<?php echo site_url('apply-loan.php'); ?>" class="btn btn-primary mt-2">Apply for Your First Loan</a>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($loans as $l): ?>
                    <div class="loan-card">
                        <div class="loan-card-header">
                            <div>
                                <h3 style="margin-bottom: 0.5rem;">
                                    Loan #LML-<?php echo str_pad($l['loan_id'], 6, '0', STR_PAD_LEFT); ?>
                                </h3>
                                <p class="text-secondary" style="font-size: 0.875rem; margin: 0;">
                                    Applied on <?php echo format_date($l['created_at']); ?>
                                </p>
                            </div>
                            <span class="badge badge-<?php 
                                echo match($l['status']) {
                                    'approved', 'disbursed', 'active' => 'success',
                                    'pending' => 'warning',
                                    'overdue' => 'danger',
                                    'completed' => 'info',
                                    'rejected' => 'secondary',
                                    default => 'secondary'
                                };
                            ?>">
                                <?php echo $l['status'] === 'completed' ? 'Repaid' : ucfirst($l['status']); ?>
                            </span>
                        </div>

                        <div class="loan-card-body">
                            <div class="loan-info-item">
                                <span class="loan-info-label">Loan Amount</span>
                                <span class="loan-info-value"><?php echo format_currency($l['loan_amount']); ?></span>
                            </div>

                            <div class="loan-info-item">
                                <span class="loan-info-label">Interest Rate</span>
                                <span class="loan-info-value"><?php echo $l['interest_rate']; ?>%</span>
                            </div>

                            <div class="loan-info-item">
                                <span class="loan-info-label">Total Amount</span>
                                <span class="loan-info-value"><?php echo format_currency($l['total_amount']); ?></span>
                            </div>

                            <div class="loan-info-item">
                                <span class="loan-info-label">Remaining Balance</span>
                                <span class="loan-info-value" style="color: <?php echo $l['remaining_balance'] > 0 ? 'var(--warning)' : 'var(--success)'; ?>">
                                    <?php echo format_currency($l['remaining_balance'] ?? $l['total_amount']); ?>
                                </span>
                            </div>

                            <?php if ($l['due_date']): ?>
                                <div class="loan-info-item">
                                    <span class="loan-info-label">Due Date</span>
                                    <span class="loan-info-value" style="font-size: 1rem;">
                                        <?php echo format_date($l['due_date']); ?>
                                    </span>
                                </div>
                            <?php endif; ?>

                            <?php if ($l['disbursement_date']): ?>
                                <div class="loan-info-item">
                                    <span class="loan-info-label">Disbursed On</span>
                                    <span class="loan-info-value" style="font-size: 1rem;">
                                        <?php echo format_date($l['disbursement_date']); ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($l['loan_purpose']): ?>
                            <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--border-color);">
                                <span class="loan-info-label">Purpose</span>
                                <p class="text-secondary" style="margin: 0.25rem 0 0 0; font-size: 0.875rem;">
                                    <?php echo htmlspecialchars($l['loan_purpose']); ?>
                                </p>
                            </div>
                        <?php endif; ?>

                        <?php if ($l['rejection_reason'] && $l['status'] === 'rejected'): ?>
                            <div style="margin-top: 1rem; padding: 1rem; background: rgba(239, 68, 68, 0.1); border-radius: 8px;">
                                <span class="loan-info-label" style="color: var(--error);">Rejection Reason</span>
                                <p class="text-secondary" style="margin: 0.25rem 0 0 0; font-size: 0.875rem;">
                                    <?php echo htmlspecialchars($l['rejection_reason']); ?>
                                </p>
                            </div>
                        <?php endif; ?>

                        <div style="margin-top: 1rem; display: flex; gap: 0.5rem; flex-wrap: wrap;">
                            <?php if (in_array($l['status'], ['active', 'overdue'])): ?>
                                <a href="repayments.php?loan_id=<?php echo $l['loan_id']; ?>" class="btn btn-primary btn-sm">
                                    Make Payment
                                </a>
                            <?php endif; ?>
                            
                            <a href="loan-details.php?id=<?php echo $l['loan_id']; ?>" class="btn btn-secondary btn-sm">
                                View Details
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <script src="<?php echo asset_url('assets/js/sidebar.js'); ?>"></script>
</body>
</html>
