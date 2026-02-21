<?php
require_once '../config/config.php';

// Require admin role only (not manager)
require_role(['admin']);

$database = new Database();
$db = $database->getConnection();

$success = '';
$errors = [];

// Handle settings update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $settings_to_update = [
        'min_loan_amount',
        'max_loan_amount',
        'default_interest_rate',
        'default_loan_term',
        'late_payment_penalty_rate',
        'min_credit_score',
        'max_active_loans'
    ];
    
    $updated_count = 0;
    
    foreach ($settings_to_update as $key) {
        if (isset($_POST[$key])) {
            $value = sanitize_input($_POST[$key]);
            
            $update_query = "UPDATE system_settings 
                           SET setting_value = :value, updated_by = :updated_by
                           WHERE setting_key = :key";
            
            $update_stmt = $db->prepare($update_query);
            
            if ($update_stmt->execute([
                ':value' => $value,
                ':updated_by' => get_user_id(),
                ':key' => $key
            ])) {
                $updated_count++;
            }
        }
    }
    
    if ($updated_count > 0) {
        $success = "Settings updated successfully ($updated_count settings changed)";
        log_audit(get_user_id(), 'SETTINGS_UPDATED', 'system_settings', null, null, $_POST);
    } else {
        $errors[] = "No settings were updated";
    }
}

// Get current settings
$settings_query = "SELECT * FROM system_settings ORDER BY setting_key";
$settings_stmt = $db->prepare($settings_query);
$settings_stmt->execute();
$all_settings = $settings_stmt->fetchAll(PDO::FETCH_ASSOC);

// Organize settings by key
$settings = [];
foreach ($all_settings as $setting) {
    $settings[$setting['setting_key']] = $setting['setting_value'];
}

// Get system statistics
$stats_query = "SELECT 
                 (SELECT COUNT(*) FROM users WHERE role = 'customer') as total_users,
                 (SELECT COUNT(*) FROM loans) as total_loans,
                 (SELECT COUNT(*) FROM repayments) as total_payments,
                 (SELECT COALESCE(SUM(principal_mwk), 0) FROM loans) as total_disbursed";
$stats_stmt = $db->prepare($stats_query);
$stats_stmt->execute();
$system_stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <!-- FontAwesome icons -->
    <link rel="stylesheet" href="../assets/css/fontawesome-all.min.css" />
    <style>
        .settings-section {
            background: var(--dark-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .settings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
        }

        .setting-item {
            display: flex;
            flex-direction: column;
        }

        .setting-label {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text-primary);
        }

        .setting-description {
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin-bottom: 0.75rem;
        }

        .setting-value {
            padding: 0.75rem 1rem;
            background: var(--dark-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            color: var(--text-primary);
            font-size: 1rem;
        }

        .setting-value:focus {
            outline: none;
            border-color: var(--primary-green);
        }

        .info-box {
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.3);
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 2rem;
        }

        .warning-box {
            background: rgba(245, 158, 11, 0.1);
            border: 1px solid rgba(245, 158, 11, 0.3);
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 2rem;
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
            <li><a href="<?php echo site_url('admin/reports.php'); ?>">
                <i class="fas fa-chart-bar"></i> Reports
            </a></li>
            <li><a href="<?php echo site_url('admin/settings.php'); ?>" class="active">
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
            <h1>System Settings</h1>
            <p class="text-secondary mb-4">Configure platform parameters and business rules</p>

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

            <div class="warning-box">
                <strong><i class="fas fa-exclamation-triangle"></i> Important:</strong> Changes to these settings will affect all new loan applications and calculations. 
                Existing loans will not be affected. Please review carefully before saving.
            </div>

            <!-- System Information -->
            <div class="card mb-4">
                <div class="card-header">
                    <h3 class="card-title">System Information</h3>
                </div>
                <div style="padding: 1.5rem;">
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-label">Platform Name</div>
                            <div class="stat-value" style="font-size: 1.25rem;"><?php echo SITE_NAME; ?></div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-label">Total Users</div>
                            <div class="stat-value"><?php echo number_format($system_stats['total_users'] ?? 0); ?></div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-label">Total Loans</div>
                            <div class="stat-value"><?php echo number_format($system_stats['total_loans'] ?? 0); ?></div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-label">Total Disbursed</div>
                            <div class="stat-value" style="font-size: 1.25rem;">
                                <?php echo format_currency($system_stats['total_disbursed']); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <form method="POST">
                <!-- Loan Settings -->
                <div class="settings-section">
                    <h3 style="margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fas fa-wallet"></i> Loan Configuration
                    </h3>
                    
                    <div class="settings-grid">
                        <div class="setting-item">
                            <label class="setting-label">Minimum Loan Amount</label>
                            <p class="setting-description">The smallest loan amount a user can apply for</p>
                            <input type="number" 
                                   name="min_loan_amount" 
                                   class="setting-value" 
                                   value="<?php echo $settings['min_loan_amount']; ?>"
                                   min="1000"
                                   step="1000"
                                   required>
                        </div>

                        <div class="setting-item">
                            <label class="setting-label">Maximum Loan Amount</label>
                            <p class="setting-description">The largest loan amount a user can apply for</p>
                            <input type="number" 
                                   name="max_loan_amount" 
                                   class="setting-value" 
                                   value="<?php echo $settings['max_loan_amount']; ?>"
                                   min="10000"
                                   step="10000"
                                   required>
                        </div>

                        <div class="setting-item">
                            <label class="setting-label">Default Interest Rate (%)</label>
                            <p class="setting-description">Standard interest rate applied to loans</p>
                            <input type="number" 
                                   name="default_interest_rate" 
                                   class="setting-value" 
                                   value="<?php echo $settings['default_interest_rate']; ?>"
                                   min="0"
                                   max="100"
                                   step="0.1"
                                   required>
                        </div>

                        <div class="setting-item">
                            <label class="setting-label">Default Loan Term (Days)</label>
                            <p class="setting-description">Standard repayment period for loans</p>
                            <input type="number" 
                                   name="default_loan_term" 
                                   class="setting-value" 
                                   value="<?php echo $settings['default_loan_term']; ?>"
                                   min="7"
                                   max="365"
                                   required>
                        </div>

                        <div class="setting-item">
                            <label class="setting-label">Late Payment Penalty Rate (% per day)</label>
                            <p class="setting-description">Daily penalty for overdue payments</p>
                            <input type="number" 
                                   name="late_payment_penalty_rate" 
                                   class="setting-value" 
                                   value="<?php echo $settings['late_payment_penalty_rate']; ?>"
                                   min="0"
                                   max="10"
                                   step="0.1"
                                   required>
                        </div>

                        <div class="setting-item">
                            <label class="setting-label">Maximum Active Loans per User</label>
                            <p class="setting-description">How many active loans one user can have</p>
                            <input type="number" 
                                   name="max_active_loans" 
                                   class="setting-value" 
                                   value="<?php echo $settings['max_active_loans']; ?>"
                                   min="1"
                                   max="10"
                                   required>
                        </div>
                    </div>
                </div>

                <!-- Credit Score Settings -->
                <div class="settings-section">
                    <h3 style="margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fas fa-chart-line"></i> Credit Score Configuration
                    </h3>
                    
                    <div class="settings-grid">
                        <div class="setting-item">
                            <label class="setting-label">Minimum Credit Score</label>
                            <p class="setting-description">Minimum score required to apply for loans</p>
                            <input type="number" 
                                   name="min_credit_score" 
                                   class="setting-value" 
                                   value="<?php echo $settings['min_credit_score']; ?>"
                                   min="300"
                                   max="850"
                                   required>
                        </div>
                    </div>

                    <div class="info-box" style="margin-top: 1.5rem;">
                        <strong><i class="fas fa-info-circle"></i> Credit Score Guide:</strong><br>
                        • 300-499: Poor (High risk)<br>
                        • 500-649: Fair (Medium risk)<br>
                        • 650-749: Good (Low risk)<br>
                        • 750-850: Excellent (Very low risk)
                    </div>
                </div>

                <!-- System Configuration -->
                <div class="settings-section">
                    <h3 style="margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.5rem;">
                        ⚙️ System Configuration
                    </h3>
                    
                    <div class="info-box">
                        <strong>Platform Information:</strong><br>
                        • Site Name: <?php echo SITE_NAME; ?><br>
                        • Site URL: <?php echo SITE_URL; ?><br>
                        • Support Email: <?php echo SITE_EMAIL; ?><br>
                        • Support Phone: <?php echo SITE_PHONE; ?><br><br>
                        <small>To change these settings, edit the config/config.php file</small>
                    </div>

                    <div style="background: rgba(16, 185, 129, 0.05); border: 1px solid var(--border-color); 
                              border-radius: 8px; padding: 1.5rem;">
                        <h4 style="margin-bottom: 1rem;">Payment Gateways Status</h4>
                        <div style="display: grid; gap: 0.75rem;">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <span><i class="fas fa-mobile-alt"></i> Airtel Money</span>
                                <span class="badge badge-success">Active</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <span><i class="fas fa-wallet"></i> TNM Mpamba</span>
                                <span class="badge badge-success">Active</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <span><i class="fas fa-credit-card"></i> Sticpay</span>
                                <span class="badge badge-success">Active</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <span><i class="fas fa-credit-card"></i> Mastercard/Visa</span>
                                <span class="badge badge-success">Active</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <span>₿ Binance</span>
                                <span class="badge badge-success">Active</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Save Button -->
                <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                    <button type="reset" class="btn btn-secondary">
                        Reset Changes
                    </button>
                    <button type="submit" class="btn btn-primary btn-lg" 
                            onclick="return confirm('Are you sure you want to update these settings? This will affect all new loan applications.')">
                        💾 Save Settings
                    </button>
                </div>
            </form>

            <!-- Audit Log -->
            <div class="card mt-4">
                <div class="card-header">
                    <h3 class="card-title">Recent Settings Changes</h3>
                </div>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Admin User</th>
                                <th>Action</th>
                                <th>IP Address</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $audit_query = "SELECT al.*, u.full_name 
                                          FROM audit_log al
                                          LEFT JOIN users u ON al.user_id = u.user_id
                                          WHERE al.action = 'SETTINGS_UPDATED'
                                          ORDER BY al.created_at DESC
                                          LIMIT 10";
                            $audit_stmt = $db->prepare($audit_query);
                            $audit_stmt->execute();
                            $audit_logs = $audit_stmt->fetchAll(PDO::FETCH_ASSOC);
                            ?>
                            
                            <?php if (empty($audit_logs)): ?>
                                <tr>
                                    <td colspan="4" style="text-align: center; padding: 2rem; color: var(--text-secondary);">
                                        No settings changes recorded yet
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($audit_logs as $log): ?>
                                    <tr>
                                        <td><?php echo date('M d, Y g:i A', strtotime($log['created_at'])); ?></td>
                                        <td><?php echo htmlspecialchars($log['full_name'] ?? 'Unknown'); ?></td>
                                        <td>Settings Updated</td>
                                        <td><?php echo htmlspecialchars($log['ip_address']); ?></td>
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
        // Validate loan amount range
        document.querySelector('input[name="max_loan_amount"]').addEventListener('change', function() {
            const minAmount = parseFloat(document.querySelector('input[name="min_loan_amount"]').value);
            const maxAmount = parseFloat(this.value);
            
            if (maxAmount <= minAmount) {
                alert('Maximum loan amount must be greater than minimum loan amount');
                this.value = minAmount + 10000;
            }
        });
    </script>
</body>
</html>