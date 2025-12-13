<?php
require_once '../config/config.php';
require_once '../classes/Payment.php';

// Require admin role
require_role(['admin', 'manager']);

$database = new Database();
$db = $database->getConnection();
$payment = new Payment($db);

// Get filters
$filter_method = $_GET['method'] ?? 'all';
$filter_status = $_GET['status'] ?? 'all';
$search = sanitize_input($_GET['search'] ?? '');
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Build query
$query = "SELECT r.*, u.full_name, u.email, l.loan_amount
          FROM repayments r
          JOIN users u ON r.user_id = u.user_id
          JOIN loans l ON r.loan_id = l.loan_id
          WHERE 1=1";

$params = [];

if ($filter_method !== 'all') {
    $query .= " AND r.payment_method = :method";
    $params[':method'] = $filter_method;
}

if ($filter_status !== 'all') {
    $query .= " AND r.payment_status = :status";
    $params[':status'] = $filter_status;
}

if ($search) {
    $query .= " AND (u.full_name LIKE :search OR u.email LIKE :search OR r.transaction_reference LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

if ($date_from) {
    $query .= " AND DATE(r.payment_date) >= :date_from";
    $params[':date_from'] = $date_from;
}

if ($date_to) {
    $query .= " AND DATE(r.payment_date) <= :date_to";
    $params[':date_to'] = $date_to;
}

$query .= " ORDER BY r.payment_date DESC LIMIT 100";

$stmt = $db->prepare($query);
$stmt->execute($params);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate statistics
$stats_query = "SELECT 
                 COUNT(*) as total_transactions,
                 SUM(CASE WHEN payment_status = 'completed' THEN 1 ELSE 0 END) as successful,
                 SUM(CASE WHEN payment_status = 'pending' THEN 1 ELSE 0 END) as pending,
                 SUM(CASE WHEN payment_status = 'failed' THEN 1 ELSE 0 END) as failed,
                 SUM(CASE WHEN payment_status = 'completed' THEN payment_amount ELSE 0 END) as total_collected,
                 SUM(payment_amount) as total_amount
                FROM repayments";
$stats_stmt = $db->prepare($stats_query);
$stats_stmt->execute();
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Payment method breakdown
$method_query = "SELECT payment_method, 
                        COUNT(*) as count,
                        SUM(CASE WHEN payment_status = 'completed' THEN payment_amount ELSE 0 END) as total
                 FROM repayments
                 WHERE payment_status = 'completed'
                 GROUP BY payment_method";
$method_stmt = $db->prepare($method_query);
$method_stmt->execute();
$payment_methods = $method_stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent large payments
$large_payments_query = "SELECT r.*, u.full_name, l.loan_id
                         FROM repayments r
                         JOIN users u ON r.user_id = u.user_id
                         JOIN loans l ON r.loan_id = l.loan_id
                         WHERE r.payment_status = 'completed'
                         AND r.payment_amount >= 50000
                         ORDER BY r.payment_date DESC
                         LIMIT 5";
$large_stmt = $db->prepare($large_payments_query);
$large_stmt->execute();
$large_payments = $large_stmt->fetchAll(PDO::FETCH_ASSOC);

$success_rate = $stats['total_transactions'] > 0 
    ? round(($stats['successful'] / $stats['total_transactions']) * 100, 1) 
    : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Management - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
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
            <li><a href="dashboard.php">
                <i>📊</i> Dashboard
            </a></li>
            <li><a href="users.php">
                <i>👥</i> Users
            </a></li>
            <li><a href="loans.php">
                <i>💰</i> Loans
            </a></li>
            <li><a href="payments.php" class="active">
                <i>💳</i> Payments
            </a></li>
            <li><a href="verifications.php">
                <i>✅</i> Verifications
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
                </a></li>
            <li><a href="../logout.php">
                <i>🚪</i> Logout
            </a></li>
        </ul>
    </aside>

    <!-- Main Content -->
    <div class="main-wrapper">
        <div class="main-content">
            <h1>Payment Management</h1>
            <p class="text-secondary mb-4">Monitor and manage all payment transactions</p>

            <!-- Statistics -->
            <div class="stats-grid mb-4">
                <div class="stat-card">
                    <div class="stat-label">Total Collected</div>
                    <div class="stat-value" style="color: var(--success); font-size: 1.75rem;">
                        <?php echo format_currency($stats['total_collected']); ?>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Total Transactions</div>
                    <div class="stat-value"><?php echo number_format($stats['total_transactions']); ?></div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Successful</div>
                    <div class="stat-value" style="color: var(--success);"><?php echo $stats['successful']; ?></div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Success Rate</div>
                    <div class="stat-value" style="color: var(--success);"><?php echo $success_rate; ?>%</div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Pending</div>
                    <div class="stat-value" style="color: var(--warning);"><?php echo $stats['pending']; ?></div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Failed</div>
                    <div class="stat-value" style="color: var(--error);"><?php echo $stats['failed']; ?></div>
                </div>
            </div>

            <!-- Payment Methods Breakdown -->
            <div class="card mb-4">
                <div class="card-header">
                    <h3 class="card-title">Payment Methods Breakdown</h3>
                </div>
                <div style="padding: 1.5rem;">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                        <?php foreach ($payment_methods as $method): ?>
                            <div style="background: rgba(16, 185, 129, 0.05); border: 1px solid var(--border-color); 
                                      border-radius: 8px; padding: 1rem; text-align: center;">
                                <div style="font-size: 2rem; margin-bottom: 0.5rem;">
                                    <?php 
                                    echo match($method['payment_method']) {
                                        'airtel_money' => '📱',
                                        'tnm_mpamba' => '💰',
                                        'sticpay' => '💳',
                                        'mastercard' => '💳',
                                        'visa' => '💳',
                                        'binance' => '₿',
                                        default => '💳'
                                    };
                                    ?>
                                </div>
                                <div style="font-weight: 600; margin-bottom: 0.25rem;">
                                    <?php echo ucfirst(str_replace('_', ' ', $method['payment_method'])); ?>
                                </div>
                                <div style="font-size: 1.25rem; color: var(--primary-green); font-weight: 700;">
                                    <?php echo format_currency($method['total']); ?>
                                </div>
                                <div class="text-secondary" style="font-size: 0.875rem;">
                                    <?php echo $method['count']; ?> transactions
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Large Payments Alert -->
            <?php if (!empty($large_payments)): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h3 class="card-title">Recent Large Payments (≥ MK 50,000)</h3>
                    </div>
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>User</th>
                                    <th>Amount</th>
                                    <th>Method</th>
                                    <th>Reference</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($large_payments as $p): ?>
                                    <tr>
                                        <td><?php echo date('M d, Y g:i A', strtotime($p['payment_date'])); ?></td>
                                        <td><?php echo htmlspecialchars($p['full_name']); ?></td>
                                        <td><strong><?php echo format_currency($p['payment_amount']); ?></strong></td>
                                        <td><?php echo ucfirst(str_replace('_', ' ', $p['payment_method'])); ?></td>
                                        <td><code style="font-size: 0.75rem;"><?php echo $p['transaction_reference']; ?></code></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Filters -->
            <form method="GET" style="background: var(--dark-card); border: 1px solid var(--border-color); 
                                     border-radius: 12px; padding: 1.5rem; margin-bottom: 2rem; 
                                     display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem;">
                <div>
                    <label class="form-label">Search</label>
                    <input type="text" name="search" class="form-control" 
                           placeholder="Name, email, ref..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                </div>

                <div>
                    <label class="form-label">Payment Method</label>
                    <select name="method" class="form-control">
                        <option value="all">All Methods</option>
                        <option value="airtel_money" <?php echo $filter_method === 'airtel_money' ? 'selected' : ''; ?>>Airtel Money</option>
                        <option value="tnm_mpamba" <?php echo $filter_method === 'tnm_mpamba' ? 'selected' : ''; ?>>TNM Mpamba</option>
                        <option value="sticpay" <?php echo $filter_method === 'sticpay' ? 'selected' : ''; ?>>Sticpay</option>
                        <option value="mastercard" <?php echo $filter_method === 'mastercard' ? 'selected' : ''; ?>>Mastercard</option>
                        <option value="visa" <?php echo $filter_method === 'visa' ? 'selected' : ''; ?>>Visa</option>
                        <option value="binance" <?php echo $filter_method === 'binance' ? 'selected' : ''; ?>>Binance</option>
                    </select>
                </div>

                <div>
                    <label class="form-label">Status</label>
                    <select name="status" class="form-control">
                        <option value="all">All Status</option>
                        <option value="completed" <?php echo $filter_status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="pending" <?php echo $filter_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="failed" <?php echo $filter_status === 'failed' ? 'selected' : ''; ?>>Failed</option>
                    </select>
                </div>

                <div>
                    <label class="form-label">Date From</label>
                    <input type="date" name="date_from" class="form-control" value="<?php echo $date_from; ?>">
                </div>

                <div>
                    <label class="form-label">Date To</label>
                    <input type="date" name="date_to" class="form-control" value="<?php echo $date_to; ?>">
                </div>

                <div style="display: flex; align-items: end;">
                    <button type="submit" class="btn btn-primary btn-block">Apply Filters</button>
                </div>
            </form>

            <!-- Payments Table -->
            <div class="card">
                <div class="card-header flex-between">
                    <h3 class="card-title">Payment Transactions</h3>
                    <span class="text-secondary">Showing <?php echo count($payments); ?> transactions</span>
                </div>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Date & Time</th>
                                <th>User</th>
                                <th>Loan ID</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Reference</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($payments)): ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; padding: 3rem; color: var(--text-secondary);">
                                        No payments found
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($payments as $p): ?>
                                    <tr>
                                        <td>
                                            <div><?php echo date('M d, Y', strtotime($p['payment_date'])); ?></div>
                                            <div class="text-secondary" style="font-size: 0.75rem;">
                                                <?php echo date('g:i A', strtotime($p['payment_date'])); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="font-weight: 600;"><?php echo htmlspecialchars($p['full_name']); ?></div>
                                            <div class="text-secondary" style="font-size: 0.875rem;">
                                                <?php echo htmlspecialchars($p['email']); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <a href="loan-details.php?id=<?php echo $p['loan_id']; ?>" 
                                               style="color: var(--primary-green); text-decoration: none;">
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
                                            echo match($p['payment_method']) {
                                                'airtel_money' => '📱',
                                                'tnm_mpamba' => '💰',
                                                'sticpay' => '💳',
                                                'mastercard' => '💳',
                                                'visa' => '💳',
                                                'binance' => '₿',
                                                default => '💳'
                                            };
                                            ?>
                                            <?php echo ucfirst(str_replace('_', ' ', $p['payment_method'])); ?>
                                        </td>
                                        <td>
                                            <code style="font-size: 0.75rem; padding: 0.25rem 0.5rem; 
                                                       background: rgba(255,255,255,0.05); border-radius: 4px;">
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
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</body>
</html>