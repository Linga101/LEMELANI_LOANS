<?php
require_once 'config/config.php';
require_once 'classes/Loan.php';

// Require login
require_login();

$database = new Database();
$db = $database->getConnection();
$loan = new Loan($db);

$loan_id = intval($_GET['id'] ?? 0);

if (!$loan_id) {
    redirect('/loans.php');
}

// Get loan details
$loan_data = $loan->getLoanById($loan_id);

if (!$loan_data || $loan_data['user_id'] != get_user_id()) {
    redirect('/loans.php');
}

// Get repayment schedule
$schedule_query = "SELECT * FROM repayment_schedule WHERE loan_id = :loan_id ORDER BY installment_number ASC";
$schedule_stmt = $db->prepare($schedule_query);
$schedule_stmt->execute([':loan_id' => $loan_id]);
$schedule = $schedule_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get payment history
$payment_query = "SELECT * FROM repayments WHERE loan_id = :loan_id ORDER BY payment_date DESC";
$payment_stmt = $db->prepare($payment_query);
$payment_stmt->execute([':loan_id' => $loan_id]);
$payments = $payment_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loan Details - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <!-- FontAwesome icons -->
    <link rel="stylesheet" href="assets/css/fontawesome-all.min.css" />
    <style>
        .detail-card {
            background: var(--dark-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 2rem;
        }

        .detail-item {
            display: flex;
            flex-direction: column;
        }

        .detail-label {
            font-size: 0.875rem;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.5rem;
        }

        .detail-value {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        .timeline {
            position: relative;
            padding-left: 2rem;
        }

        .timeline::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 2px;
            background: var(--border-color);
        }

        .timeline-item {
            position: relative;
            margin-bottom: 2rem;
        }

        .timeline-item::before {
            content: '';
            position: absolute;
            left: -2.5rem;
            top: 0.25rem;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: var(--primary-green);
            border: 2px solid var(--dark-card);
        }

        .timeline-date {
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin-bottom: 0.25rem;
        }

        .timeline-content {
            font-weight: 500;
        }

        .progress-bar {
            width: 100%;
            height: 12px;
            background: var(--border-color);
            border-radius: 6px;
            overflow: hidden;
            margin-top: 0.5rem;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary-green), var(--primary-dark-green));
            transition: width 0.3s ease;
        }
    </style>
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
            <li><a href="<?php echo site_url('dashboard.php'); ?>">
                <i class="fas fa-home"></i> Dashboard
            </a></li>
            <li><a href="<?php echo site_url('loans.php'); ?>" class="active">
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
            <li><a href="<?php echo site_url('profile.php'); ?>">
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
            <div class="flex-between mb-4">
                <div>
                    <a href="<?php echo site_url('loans.php'); ?>" class="text-secondary" style="text-decoration: none; font-size: 0.875rem;">← Back to Loans</a>
                    <h1>Loan #LML-<?php echo str_pad($loan_data['loan_id'], 6, '0', STR_PAD_LEFT); ?></h1>
                    <p class="text-secondary">
                        Applied on <?php echo format_date($loan_data['created_at']); ?>
                        <span class="badge badge-<?php 
                            echo match($loan_data['status']) {
                                'approved', 'disbursed', 'active' => 'success',
                                'pending' => 'warning',
                                'overdue' => 'danger',
                                'repaid' => 'info',
                                'rejected' => 'secondary',
                                default => 'secondary'
                            };
                        ?>" style="margin-left: 1rem;">
                            <?php echo ucfirst($loan_data['status']); ?>
                        </span>
                    </p>
                </div>
            </div>

            <!-- Loan Summary -->
            <div class="detail-card">
                <h3 style="margin-bottom: 1.5rem;">Loan Summary</h3>
                <div class="detail-grid">
                    <div class="detail-item">
                        <span class="detail-label">Loan Amount</span>
                        <span class="detail-value"><?php echo format_currency($loan_data['loan_amount']); ?></span>
                    </div>

                    <div class="detail-item">
                        <span class="detail-label">Interest Rate</span>
                        <span class="detail-value"><?php echo $loan_data['interest_rate']; ?>%</span>
                    </div>

                    <div class="detail-item">
                        <span class="detail-label">Total Amount</span>
                        <span class="detail-value"><?php echo format_currency($loan_data['total_amount']); ?></span>
                    </div>

                    <div class="detail-item">
                        <span class="detail-label">Remaining Balance</span>
                        <span class="detail-value" style="color: <?php echo $loan_data['remaining_balance'] > 0 ? 'var(--warning)' : 'var(--success)'; ?>">
                            <?php echo format_currency($loan_data['remaining_balance']); ?>
                        </span>
                    </div>

                    <div class="detail-item">
                        <span class="detail-label">Loan Term</span>
                        <span class="detail-value"><?php echo $loan_data['loan_term_days']; ?> days</span>
                    </div>

                    <?php if ($loan_data['disbursement_date']): ?>
                        <div class="detail-item">
                            <span class="detail-label">Disbursement Date</span>
                            <span class="detail-value" style="font-size: 1.125rem;">
                                <?php echo format_date($loan_data['disbursement_date']); ?>
                            </span>
                        </div>
                    <?php endif; ?>

                    <?php if ($loan_data['due_date']): ?>
                        <div class="detail-item">
                            <span class="detail-label">Due Date</span>
                            <span class="detail-value" style="font-size: 1.125rem;">
                                <?php echo format_date($loan_data['due_date']); ?>
                            </span>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($loan_data['loan_purpose']): ?>
                    <div style="margin-top: 2rem; padding-top: 2rem; border-top: 1px solid var(--border-color);">
                        <span class="detail-label">Loan Purpose</span>
                        <p class="text-secondary" style="margin: 0.5rem 0 0 0;">
                            <?php echo htmlspecialchars($loan_data['loan_purpose']); ?>
                        </p>
                    </div>
                <?php endif; ?>

                <!-- Repayment Progress -->
                <?php if (in_array($loan_data['status'], ['active', 'overdue', 'repaid'])): ?>
                    <?php
                    $paid_amount = $loan_data['total_amount'] - $loan_data['remaining_balance'];
                    $progress_percent = ($paid_amount / $loan_data['total_amount']) * 100;
                    ?>
                    <div style="margin-top: 2rem; padding-top: 2rem; border-top: 1px solid var(--border-color);">
                        <div class="flex-between" style="margin-bottom: 0.5rem;">
                            <span class="detail-label" style="margin: 0;">Repayment Progress</span>
                            <span class="text-secondary" style="font-size: 0.875rem;">
                                <?php echo format_currency($paid_amount); ?> / <?php echo format_currency($loan_data['total_amount']); ?>
                            </span>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo $progress_percent; ?>%"></div>
                        </div>
                        <p class="text-secondary" style="margin: 0.5rem 0 0 0; font-size: 0.875rem;">
                            <?php echo round($progress_percent); ?>% complete
                        </p>
                    </div>
                <?php endif; ?>

                <?php if (in_array($loan_data['status'], ['active', 'overdue'])): ?>
                    <div style="margin-top: 2rem;">
                        <a href="repayments.php?loan_id=<?php echo $loan_data['loan_id']; ?>" class="btn btn-primary">
                            Make Payment
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Repayment Schedule -->
            <?php if (!empty($schedule)): ?>
                <div class="card mb-3">
                    <div class="card-header">
                        <h3 class="card-title">Repayment Schedule</h3>
                    </div>
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>Installment</th>
                                    <th>Due Date</th>
                                    <th>Amount Due</th>
                                    <th>Amount Paid</th>
                                    <th>Status</th>
                                    <th>Paid On</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($schedule as $item): ?>
                                    <tr>
                                        <td>#<?php echo $item['installment_number']; ?></td>
                                        <td><?php echo format_date($item['due_date']); ?></td>
                                        <td><?php echo format_currency($item['amount_due']); ?></td>
                                        <td><?php echo format_currency($item['amount_paid']); ?></td>
                                        <td>
                                            <span class="badge badge-<?php 
                                                echo match($item['status']) {
                                                    'paid' => 'success',
                                                    'pending' => 'warning',
                                                    'overdue' => 'danger',
                                                    'partial' => 'info',
                                                    default => 'secondary'
                                                };
                                            ?>">
                                                <?php echo ucfirst($item['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo !empty($item['paid_at']) ? format_date($item['paid_at']) : (!empty($item['paid_date']) ? format_date($item['paid_date']) : '-'); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Payment History -->
            <?php if (!empty($payments)): ?>
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Payment History</h3>
                    </div>
                    <div class="timeline">
                        <?php foreach ($payments as $payment): ?>
                            <div class="timeline-item">
                                <div class="timeline-date">
                                    <?php echo date('M d, Y g:i A', strtotime($payment['payment_date'])); ?>
                                </div>
                                <div class="timeline-content">
                                    <strong><?php echo format_currency($payment['payment_amount']); ?></strong>
                                    via <?php echo ucfirst(str_replace('_', ' ', $payment['payment_method'])); ?>
                                    <span class="badge badge-<?php 
                                        echo match($payment['payment_status']) {
                                            'completed' => 'success',
                                            'pending' => 'warning',
                                            'failed' => 'danger',
                                            default => 'secondary'
                                        };
                                    ?>" style="margin-left: 0.5rem;">
                                        <?php echo ucfirst($payment['payment_status']); ?>
                                    </span>
                                </div>
                                <?php if ($payment['transaction_reference']): ?>
                                    <div class="text-secondary" style="font-size: 0.875rem; margin-top: 0.25rem;">
                                        Ref: <?php echo htmlspecialchars($payment['transaction_reference']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($loan_data['rejection_reason'] && $loan_data['status'] === 'rejected'): ?>
                <div class="alert alert-error">
                    <strong>Rejection Reason:</strong><br>
                    <?php echo htmlspecialchars($loan_data['rejection_reason']); ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>