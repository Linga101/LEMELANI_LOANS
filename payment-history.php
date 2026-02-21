<?php
require_once 'config/config.php';
require_once 'classes/Payment.php';

// Require login
require_login();

$database = new Database();
$db = $database->getConnection();
$payment = new Payment($db);

// Get all payments for user
$all_payments = $payment->getUserPayments(get_user_id());

// Calculate statistics
$total_paid = 0;
$payment_count = count($all_payments);
$successful_payments = 0;

foreach ($all_payments as $p) {
    if ($p['payment_status'] === 'completed') {
        $total_paid += $p['payment_amount'];
        $successful_payments++;
    }
}

// Group payments by month
$payments_by_month = [];
foreach ($all_payments as $p) {
    $month_key = date('Y-m', strtotime($p['payment_date']));
    if (!isset($payments_by_month[$month_key])) {
        $payments_by_month[$month_key] = [];
    }
    $payments_by_month[$month_key][] = $p;
}

krsort($payments_by_month); // Sort by most recent first
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment History - <?php echo SITE_NAME; ?></title>
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
            <li><a href="<?php echo site_url('dashboard.php'); ?>">
                <i>🏠</i> Dashboard
            </a></li>
            <li><a href="<?php echo site_url('loans.php'); ?>">
                <i>💰</i> My Loans
            </a></li>
            <li><a href="<?php echo site_url('apply-loan.php'); ?>">
                <i>➕</i> Apply for Loan
            </a></li>
            <li><a href="<?php echo site_url('repayments.php'); ?>" class="active">
                <i>💳</i> Repayments
            </a></li>
            <li><a href="<?php echo site_url('credit-history.php'); ?>">
                <i>📊</i> Credit History
            </a></li>
            <li><a href="<?php echo site_url('notifications.php'); ?>">
                <i>🔔</i> Notifications
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
            <div class="flex-between mb-4">
                <div>
                    <h1>Payment History</h1>
                    <p class="text-secondary">View all your loan repayment transactions</p>
                </div>
                <div>
                    <a href="<?php echo site_url('repayments.php'); ?>" class="btn btn-primary">Make Payment</a>
                </div>
            </div>

            <!-- Statistics -->
            <div class="stats-grid mb-4">
                <div class="stat-card">
                    <div class="stat-label">Total Paid</div>
                    <div class="stat-value" style="color: var(--success);"><?php echo format_currency($total_paid); ?></div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Total Transactions</div>
                    <div class="stat-value"><?php echo $payment_count; ?></div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Successful Payments</div>
                    <div class="stat-value"><?php echo $successful_payments; ?></div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Success Rate</div>
                    <div class="stat-value">
                        <?php echo $payment_count > 0 ? round(($successful_payments / $payment_count) * 100) : 0; ?>%
                    </div>
                </div>
            </div>

            <!-- Payment History by Month -->
            <?php if (empty($all_payments)): ?>
                <div class="card">
                    <div style="text-align: center; padding: 3rem;">
                        <div style="font-size: 4rem; margin-bottom: 1rem;">💳</div>
                        <h3>No Payment History</h3>
                        <p class="text-secondary">You haven't made any payments yet.</p>
                        <a href="<?php echo site_url('repayments.php'); ?>" class="btn btn-primary mt-2">Make Your First Payment</a>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($payments_by_month as $month => $payments): ?>
                    <div class="card mb-3">
                        <div class="card-header">
                            <h3 class="card-title">
                                <?php echo date('F Y', strtotime($month . '-01')); ?>
                            </h3>
                            <p class="card-subtitle">
                                <?php echo count($payments); ?> transaction(s)
                            </p>
                        </div>

                        <div class="table-wrapper">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Date & Time</th>
                                        <th>Loan</th>
                                        <th>Amount</th>
                                        <th>Method</th>
                                        <th>Reference</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($payments as $p): ?>
                                        <tr>
                                            <td>
                                                <div><?php echo date('M d, Y', strtotime($p['payment_date'])); ?></div>
                                                <div class="text-secondary" style="font-size: 0.75rem;">
                                                    <?php echo date('g:i A', strtotime($p['payment_date'])); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <a href="loan-details.php?id=<?php echo $p['loan_id']; ?>" style="color: var(--primary-green); text-decoration: none;">
                                                    #LML-<?php echo str_pad($p['loan_id'], 6, '0', STR_PAD_LEFT); ?>
                                                </a>
                                            </td>
                                            <td>
                                                <strong><?php echo format_currency($p['payment_amount']); ?></strong>
                                                <?php if ($p['is_partial']): ?>
                                                    <span class="badge badge-info" style="font-size: 0.7rem; margin-left: 0.5rem;">Partial</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php 
                                                $method_icons = [
                                                    'airtel_money' => '📱',
                                                    'tnm_mpamba' => '💰',
                                                    'sticpay' => '💳',
                                                    'mastercard' => '💳',
                                                    'visa' => '💳',
                                                    'binance' => '₿'
                                                ];
                                                echo $method_icons[$p['payment_method']] ?? '💳';
                                                ?>
                                                <?php echo ucfirst(str_replace('_', ' ', $p['payment_method'])); ?>
                                            </td>
                                            <td>
                                                <code style="font-size: 0.75rem; padding: 0.25rem 0.5rem; background: rgba(255,255,255,0.05); border-radius: 4px;">
                                                    <?php echo $p['transaction_reference']; ?>
                                                </code>
                                            </td>
                                            <td>
                                                <span class="badge badge-<?php 
                                                    echo match($p['payment_status']) {
                                                        'completed' => 'success',
                                                        'pending' => 'warning',
                                                        'failed' => 'danger',
                                                        'cancelled' => 'secondary',
                                                        default => 'secondary'
                                                    };
                                                ?>">
                                                    <?php echo ucfirst($p['payment_status']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>