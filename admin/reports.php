<?php
require_once '../config/config.php';

// Require admin role
require_role(['admin', 'manager']);

$database = new Database();
$db = $database->getConnection();

// Get date range
$date_from = $_GET['date_from'] ?? date('Y-m-01'); // First day of current month
$date_to = $_GET['date_to'] ?? date('Y-m-d'); // Today

// Financial Summary (Malawi: principal_mwk, outstanding_balance_mwk, amount_paid_mwk, paid_at)
$financial_query = "SELECT 
                     COALESCE(SUM(l.principal_mwk), 0) as total_disbursed,
                     COALESCE(SUM(CASE WHEN l.status IN ('active','overdue') THEN l.outstanding_balance_mwk ELSE 0 END), 0) as total_outstanding,
                     (SELECT COALESCE(SUM(amount_paid_mwk), 0) FROM repayments WHERE payment_status = 'completed' 
                      AND DATE(paid_at) BETWEEN :date_from1 AND :date_to1) as total_collected,
                     COUNT(DISTINCT CASE WHEN l.created_at BETWEEN :date_from2 AND :date_to2 THEN l.user_id END) as active_borrowers
                    FROM loans l
                    WHERE l.created_at BETWEEN :date_from3 AND :date_to3";

$financial_stmt = $db->prepare($financial_query);
$financial_stmt->execute([
    ':date_from1' => $date_from,
    ':date_to1' => $date_to,
    ':date_from2' => $date_from,
    ':date_to2' => $date_to,
    ':date_from3' => $date_from,
    ':date_to3' => $date_to
]);
$financial = $financial_stmt->fetch(PDO::FETCH_ASSOC);

// Loan Performance (Malawi: status completed = repaid)
$performance_query = "SELECT 
                       COUNT(*) as total_loans,
                       SUM(CASE WHEN status IN ('active', 'completed') THEN 1 ELSE 0 END) as approved_loans,
                       0 as rejected_loans,
                       SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) as overdue_loans,
                       SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as repaid_loans,
                       AVG(principal_mwk) as avg_loan_amount
                      FROM loans
                      WHERE created_at BETWEEN :date_from AND :date_to";

$performance_stmt = $db->prepare($performance_query);
$performance_stmt->execute([':date_from' => $date_from, ':date_to' => $date_to]);
$performance = $performance_stmt->fetch(PDO::FETCH_ASSOC);

// Daily disbursed loans
$daily_query = "SELECT DATE(created_at) as date, 
                       COUNT(*) as applications,
                       SUM(CASE WHEN status IN ('active', 'completed') THEN 1 ELSE 0 END) as approved
                FROM loans
                WHERE created_at BETWEEN :date_from AND :date_to
                GROUP BY DATE(created_at)
                ORDER BY date ASC";

$daily_stmt = $db->prepare($daily_query);
$daily_stmt->execute([':date_from' => $date_from, ':date_to' => $date_to]);
$daily_loans = $daily_stmt->fetchAll(PDO::FETCH_ASSOC);

// User growth
$user_growth_query = "SELECT DATE(created_at) as date, COUNT(*) as new_users
                      FROM users
                      WHERE created_at BETWEEN :date_from AND :date_to
                      AND role = 'customer'
                      GROUP BY DATE(created_at)
                      ORDER BY date ASC";

$user_growth_stmt = $db->prepare($user_growth_query);
$user_growth_stmt->execute([':date_from' => $date_from, ':date_to' => $date_to]);
$user_growth = $user_growth_stmt->fetchAll(PDO::FETCH_ASSOC);

// Top borrowers (Malawi: principal_mwk, status completed)
$top_borrowers_query = "SELECT u.full_name, u.email, u.credit_score,
                               COUNT(l.loan_id) as loan_count,
                               SUM(l.principal_mwk) as total_borrowed,
                               SUM(CASE WHEN l.status = 'completed' THEN 1 ELSE 0 END) as repaid_count
                        FROM users u
                        JOIN loans l ON u.user_id = l.user_id
                        WHERE l.created_at BETWEEN :date_from AND :date_to
                        GROUP BY u.user_id
                        ORDER BY total_borrowed DESC
                        LIMIT 10";

$top_borrowers_stmt = $db->prepare($top_borrowers_query);
$top_borrowers_stmt->execute([':date_from' => $date_from, ':date_to' => $date_to]);
$top_borrowers = $top_borrowers_stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate rates
$approval_rate = $performance['total_loans'] > 0 
    ? round(($performance['approved_loans'] / $performance['total_loans']) * 100, 1) 
    : 0;

$default_rate = $performance['total_loans'] > 0 
    ? round(($performance['overdue_loans'] / $performance['total_loans']) * 100, 1) 
    : 0;

$collection_efficiency = $financial['total_disbursed'] > 0 
    ? round(($financial['total_collected'] / $financial['total_disbursed']) * 100, 1) 
    : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Analytics - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <!-- FontAwesome icons -->
    <link rel="stylesheet" href="../assets/css/fontawesome-all.min.css" />
    <style>
        .chart-container {
            height: 300px;
            position: relative;
            margin-top: 1rem;
        }

        .chart-bar-container {
            display: flex;
            align-items: flex-end;
            height: 100%;
            gap: 0.5rem;
        }

        .chart-bar-wrapper {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .chart-bar {
            width: 100%;
            background: linear-gradient(to top, var(--primary-green), var(--primary-dark-green));
            border-radius: 4px 4px 0 0;
            min-height: 10px;
            transition: all 0.3s ease;
            position: relative;
        }

        .chart-bar:hover {
            opacity: 0.8;
        }

        .chart-bar-label {
            margin-top: 0.5rem;
            font-size: 0.75rem;
            color: var(--text-secondary);
            transform: rotate(-45deg);
            transform-origin: top left;
        }

        .chart-bar-value {
            position: absolute;
            top: -25px;
            left: 50%;
            transform: translateX(-50%);
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-primary);
            white-space: nowrap;
        }

        .export-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        @media print {
            .sidebar, .export-buttons, button {
                display: none !important;
            }
            .main-wrapper {
                margin-left: 0 !important;
            }
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
            <li><a href="<?php echo site_url('admin/dashboard.php'); ?>">
                <i class="fas fa-chart-bar"></i> Dashboard
            </a></li>
            <li><a href="<?php echo site_url('admin/users.php'); ?>">
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
            <li><a href="<?php echo site_url('admin/reports.php'); ?>" class="active">
                <i class="fas fa-chart-bar"></i> Reports
            </a></li>
            <li><a href="<?php echo site_url('admin/settings.php'); ?>">
                <i class="fas fa-cog"></i> Settings
            </a></li>
            <li style="margin-top: auto; padding-top: 1rem; border-top: 1px solid var(--border-color);">
                <a href="<?php echo site_url('dashboard.php'); ?>">
                    <i class="fas fa-user"></i> User View
                </a>
            </li>
            <li><a href="<?php echo site_url('logout.php'); ?>">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a></li>
        </ul>
    </aside>

    <!-- Main Content -->
    <div class="main-wrapper">
        <div class="main-content">
            <div class="flex-between mb-4">
                <div>
                    <h1>Reports & Analytics</h1>
                    <p class="text-secondary">Comprehensive platform insights and performance metrics</p>
                </div>
                <div class="export-buttons">
                    <button onclick="window.print()" class="btn btn-secondary">
                        🖨️ Print Report
                    </button>
                    <button onclick="exportToCSV()" class="btn btn-primary">
                        <i class="fas fa-chart-line"></i> Export CSV
                    </button>
                </div>
            </div>

            <!-- Date Range Filter -->
            <form method="GET" style="background: var(--dark-card); border: 1px solid var(--border-color); 
                                     border-radius: 12px; padding: 1.5rem; margin-bottom: 2rem; 
                                     display: flex; gap: 1rem; flex-wrap: wrap; align-items: end;">
                <div style="flex: 1; min-width: 200px;">
                    <label class="form-label">Date From</label>
                    <input type="date" name="date_from" class="form-control" 
                           value="<?php echo $date_from; ?>" required>
                </div>

                <div style="flex: 1; min-width: 200px;">
                    <label class="form-label">Date To</label>
                    <input type="date" name="date_to" class="form-control" 
                           value="<?php echo $date_to; ?>" required>
                </div>

                <div>
                    <button type="submit" class="btn btn-primary">Generate Report</button>
                </div>
            </form>

            <div style="background: rgba(16, 185, 129, 0.05); border: 1px solid var(--primary-green); 
                       border-radius: 8px; padding: 1rem; margin-bottom: 2rem; text-align: center;">
                <strong>Report Period:</strong> <?php echo format_date($date_from); ?> to <?php echo format_date($date_to); ?>
            </div>

            <!-- Financial Overview -->
            <div class="card mb-4">
                <div class="card-header">
                    <h3 class="card-title">Financial Overview</h3>
                </div>
                <div style="padding: 1.5rem;">
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-label">Total Disbursed</div>
                            <div class="stat-value" style="color: var(--primary-green); font-size: 1.75rem;">
                                <?php echo format_currency($financial['total_disbursed']); ?>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-label">Total Collected</div>
                            <div class="stat-value" style="color: var(--success); font-size: 1.75rem;">
                                <?php echo format_currency($financial['total_collected']); ?>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-label">Outstanding Balance</div>
                            <div class="stat-value" style="color: var(--warning); font-size: 1.75rem;">
                                <?php echo format_currency($financial['total_outstanding']); ?>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-label">Collection Efficiency</div>
                            <div class="stat-value" style="color: var(--info);">
                                <?php echo $collection_efficiency; ?>%
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Loan Performance -->
            <div class="card mb-4">
                <div class="card-header">
                    <h3 class="card-title">Loan Performance</h3>
                </div>
                <div style="padding: 1.5rem;">
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-label">Total Applications</div>
                            <div class="stat-value"><?php echo number_format($performance['total_loans'] ?? 0); ?></div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-label">Approved Loans</div>
                            <div class="stat-value" style="color: var(--success);">
                                <?php echo number_format($performance['approved_loans'] ?? 0); ?>
                            </div>
                            <div class="text-secondary" style="font-size: 0.875rem;">
                                <?php echo $approval_rate; ?>% approval rate
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-label">Rejected Loans</div>
                            <div class="stat-value" style="color: var(--error);">
                                <?php echo number_format($performance['rejected_loans'] ?? 0); ?>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-label">Overdue Loans</div>
                            <div class="stat-value" style="color: var(--error);">
                                <?php echo number_format($performance['overdue_loans'] ?? 0); ?>
                            </div>
                            <div class="text-secondary" style="font-size: 0.875rem;">
                                <?php echo $default_rate; ?>% default rate
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-label">Repaid Loans</div>
                            <div class="stat-value" style="color: var(--info);">
                                <?php echo number_format($performance['repaid_loans'] ?? 0); ?>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-label">Avg Loan Amount</div>
                            <div class="stat-value" style="font-size: 1.25rem;">
                                <?php echo format_currency($performance['avg_loan_amount']); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-bottom: 2rem;">
                <!-- Daily Loan Applications Chart -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Daily Loan Applications</h3>
                        <p class="card-subtitle">Applications vs Approvals</p>
                    </div>
                    <div style="padding: 2rem 1.5rem;">
                        <div class="chart-container">
                            <div class="chart-bar-container">
                                <?php 
                                $max_value = !empty($daily_loans) ? max(array_column($daily_loans, 'applications')) : 1;
                                foreach ($daily_loans as $day): 
                                    $height = ($day['applications'] / $max_value) * 100;
                                ?>
                                    <div class="chart-bar-wrapper">
                                        <div class="chart-bar" style="height: <?php echo $height; ?>%;">
                                            <span class="chart-bar-value"><?php echo $day['applications']; ?></span>
                                        </div>
                                        <div class="chart-bar-label"><?php echo date('m/d', strtotime($day['date'])); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- User Growth Chart -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">User Registration Growth</h3>
                        <p class="card-subtitle">New user signups</p>
                    </div>
                    <div style="padding: 2rem 1.5rem;">
                        <div class="chart-container">
                            <div class="chart-bar-container">
                                <?php 
                                $max_users = !empty($user_growth) ? max(array_column($user_growth, 'new_users')) : 1;
                                foreach ($user_growth as $day): 
                                    $height = ($day['new_users'] / $max_users) * 100;
                                ?>
                                    <div class="chart-bar-wrapper">
                                        <div class="chart-bar" style="height: <?php echo $height; ?>%;">
                                            <span class="chart-bar-value"><?php echo $day['new_users']; ?></span>
                                        </div>
                                        <div class="chart-bar-label"><?php echo date('m/d', strtotime($day['date'])); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Top Borrowers -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Top Borrowers</h3>
                    <p class="card-subtitle">Highest volume borrowers in selected period</p>
                </div>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Rank</th>
                                <th>Borrower</th>
                                <th>Credit Score</th>
                                <th>Total Loans</th>
                                <th>Total Borrowed</th>
                                <th>Repaid Loans</th>
                                <th>Repayment Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($top_borrowers)): ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; padding: 2rem; color: var(--text-secondary);">
                                        No data for selected period
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($top_borrowers as $index => $borrower): ?>
                                    <?php $repayment_rate = $borrower['loan_count'] > 0 ? round(($borrower['repaid_count'] / $borrower['loan_count']) * 100) : 0; ?>
                                    <tr>
                                        <td>
                                            <strong style="font-size: 1.25rem; color: <?php 
                                                echo $index === 0 ? '#FFD700' : ($index === 1 ? '#C0C0C0' : ($index === 2 ? '#CD7F32' : 'var(--text-primary)'));
                                            ?>">
                                                #<?php echo $index + 1; ?>
                                            </strong>
                                        </td>
                                        <td>
                                            <div style="font-weight: 600;"><?php echo htmlspecialchars($borrower['full_name']); ?></div>
                                            <div class="text-secondary" style="font-size: 0.875rem;">
                                                <?php echo htmlspecialchars($borrower['email']); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <strong style="color: <?php 
                                                echo $borrower['credit_score'] >= 700 ? 'var(--success)' : 
                                                     ($borrower['credit_score'] >= 500 ? 'var(--warning)' : 'var(--error)');
                                            ?>">
                                                <?php echo $borrower['credit_score']; ?>
                                            </strong>
                                        </td>
                                        <td><?php echo $borrower['loan_count']; ?></td>
                                        <td><strong><?php echo format_currency($borrower['total_borrowed']); ?></strong></td>
                                        <td><?php echo $borrower['repaid_count']; ?></td>
                                        <td>
                                            <span style="color: <?php echo $repayment_rate >= 80 ? 'var(--success)' : 'var(--warning)'; ?>">
                                                <?php echo $repayment_rate; ?>%
                                            </span>
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

    <script>
        function exportToCSV() {
            // This is a basic implementation - you can enhance it
            alert('CSV export feature will download the report data. This would be implemented with actual CSV generation in production.');
            
            // In production, you would:
            // window.location.href = 'export-csv.php?date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>';
        }
    </script>
</body>
</html>