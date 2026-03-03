<?php
require_once '../config/config.php';
require_once '../classes/Loan.php';

require_role(['admin', 'manager']);

$database = new Database();
$db = $database->getConnection();
$loan = new Loan($db);

$success = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_fail();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $data = [
            'account_type' => sanitize_input($_POST['account_type'] ?? ''),
            'account_provider' => sanitize_input($_POST['account_provider'] ?? ''),
            'account_name' => sanitize_input($_POST['account_name'] ?? ''),
            'account_number' => sanitize_input($_POST['account_number'] ?? ''),
            'currency_code' => sanitize_input($_POST['currency_code'] ?? 'MWK'),
            'current_balance_mwk' => (float)($_POST['current_balance_mwk'] ?? 0),
            'is_default' => !empty($_POST['is_default']) ? 1 : 0,
            'is_active' => !empty($_POST['is_active']) ? 1 : 0,
        ];
        $result = $loan->createPlatformAccount($data);
        if ($result['success']) {
            $success = 'Platform account added successfully.';
            log_audit(get_user_id(), 'PLATFORM_ACCOUNT_CREATED', 'platform_accounts', $result['account_id'], null, $data);
        } else {
            $errors[] = $result['message'] ?? 'Failed to create platform account';
        }
    } elseif ($action === 'set_default') {
        $account_id = (int)($_POST['account_id'] ?? 0);
        $result = $loan->setDefaultPlatformAccount($account_id);
        if ($result['success']) {
            $success = 'Default platform account updated.';
            log_audit(get_user_id(), 'PLATFORM_ACCOUNT_SET_DEFAULT', 'platform_accounts', $account_id);
        } else {
            $errors[] = $result['message'] ?? 'Failed to set default account';
        }
    } elseif ($action === 'toggle_status') {
        $account_id = (int)($_POST['account_id'] ?? 0);
        $is_active = (int)($_POST['is_active'] ?? 0);
        $result = $loan->setPlatformAccountStatus($account_id, $is_active);
        if ($result['success']) {
            $success = $is_active ? 'Account activated.' : 'Account deactivated.';
            log_audit(get_user_id(), 'PLATFORM_ACCOUNT_STATUS_CHANGED', 'platform_accounts', $account_id, null, ['is_active' => $is_active]);
        } else {
            $errors[] = $result['message'] ?? 'Failed to update account status';
        }
    } elseif ($action === 'update_balance') {
        $account_id = (int)($_POST['account_id'] ?? 0);
        $new_balance = (float)($_POST['new_balance_mwk'] ?? 0);
        $result = $loan->updatePlatformAccountBalance($account_id, $new_balance);
        if ($result['success']) {
            $success = 'Account balance updated.';
            log_audit(get_user_id(), 'PLATFORM_ACCOUNT_BALANCE_UPDATED', 'platform_accounts', $account_id, null, ['current_balance_mwk' => $new_balance]);
        } else {
            $errors[] = $result['message'] ?? 'Failed to update account balance';
        }
    }
}

$accounts = $loan->getPlatformAccounts(false);
$active_accounts = array_filter($accounts, function($a) {
    return (int)$a['is_active'] === 1;
});
$default_account = null;
foreach ($accounts as $acc) {
    if ((int)$acc['is_default'] === 1) {
        $default_account = $acc;
        break;
    }
}
$total_balance = 0;
foreach ($active_accounts as $acc) {
    $total_balance += (float)$acc['current_balance_mwk'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Platform Accounts - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo asset_url('assets/css/style.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_url('assets/css/fontawesome-all.min.css'); ?>" />
    <style>
        .panel {
            background: var(--dark-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .account-meta {
            font-size: 0.875rem;
            color: var(--text-secondary);
        }
        .inline-form {
            display: inline-flex;
            gap: 0.5rem;
            align-items: center;
            flex-wrap: wrap;
        }
        .mini-input {
            max-width: 170px;
            padding: 0.5rem 0.75rem;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            background: var(--dark-bg);
            color: var(--text-primary);
        }
    </style>
</head>
<body>
    <div class="gradient-overlay"></div>
    <aside class="sidebar" id="sidebar">
        <button id="sidebarToggle" class="sidebar-toggle"><i class="fas fa-chevron-left"></i></button>
        <div class="sidebar-brand">
            <img src="../assets/images/logo.png" alt="<?php echo SITE_NAME; ?>" onerror="this.style.display='none'">
            <span><?php echo SITE_NAME; ?></span>
        </div>
        <ul class="sidebar-menu">
            <li><a href="<?php echo site_url('admin/dashboard.php'); ?>"><i class="fas fa-chart-bar"></i> Dashboard</a></li>
            <li><a href="<?php echo site_url('admin/users.php'); ?>"><i class="fas fa-users"></i> Users</a></li>
            <li><a href="<?php echo site_url('admin/loans.php'); ?>"><i class="fas fa-wallet"></i> Loans</a></li>
            <li><a href="<?php echo site_url('admin/payments.php'); ?>"><i class="fas fa-credit-card"></i> Payments</a></li>
            <li><a href="<?php echo site_url('admin/verifications.php'); ?>"><i class="fas fa-check-circle"></i> Verifications</a></li>
            <li><a href="<?php echo site_url('admin/reports.php'); ?>"><i class="fas fa-chart-bar"></i> Reports</a></li>
            <li><a href="<?php echo site_url('admin/platform-accounts.php'); ?>" class="active"><i class="fas fa-university"></i> Platform Accounts</a></li>
            <li><a href="<?php echo site_url('admin/settings.php'); ?>"><i class="fas fa-cog"></i> Settings</a></li>
            <li style="margin-top: auto; padding-top: 1rem; border-top: 1px solid var(--border-color);"><a href="<?php echo site_url('dashboard.php'); ?>"><i class="fas fa-user"></i> User View</a></li>
            <li><a href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </aside>

    <div class="main-wrapper">
        <div class="main-content">
            <h1>Platform Accounts</h1>
            <p class="text-secondary mb-4">Manage source accounts used to disburse loans.</p>

            <?php if ($success): ?>
                <div class="alert alert-success mb-3"><?php echo h($success); ?></div>
            <?php endif; ?>
            <?php if (!empty($errors)): ?>
                <div class="alert alert-error mb-3">
                    <?php foreach ($errors as $error): ?>
                        <?php echo h($error); ?><br>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="stats-grid mb-4">
                <div class="stat-card">
                    <div class="stat-label">Total Accounts</div>
                    <div class="stat-value"><?php echo count($accounts); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Active Accounts</div>
                    <div class="stat-value"><?php echo count($active_accounts); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Default Source</div>
                    <div class="stat-value" style="font-size:1rem;"><?php echo $default_account ? h($default_account['account_provider']) : 'Not Set'; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Active Balance</div>
                    <div class="stat-value" style="font-size:1.25rem;"><?php echo format_currency($total_balance); ?></div>
                </div>
            </div>

            <div class="panel">
                <h3 style="margin-bottom:1rem;">Add Platform Account</h3>
                <form method="POST">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="action" value="create">
                    <div class="settings-grid">
                        <div class="form-group">
                            <label class="form-label">Account Type *</label>
                            <select name="account_type" id="account_type" class="form-control" required>
                                <option value="airtel_money">Airtel Money</option>
                                <option value="tnm_mpamba">TNM Mpamba</option>
                                <option value="sticpay">Sticpay</option>
                                <option value="mastercard">Mastercard</option>
                                <option value="visa">Visa</option>
                                <option value="binance">Binance</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="escrow">Escrow</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Provider *</label>
                            <select name="account_provider" id="account_provider" class="form-control" required>
                                <option value="Airtel Money">Airtel Money</option>
                                <option value="TNM Mpamba">TNM Mpamba</option>
                                <option value="Sticpay">Sticpay</option>
                                <option value="Mastercard">Mastercard</option>
                                <option value="Visa">Visa</option>
                                <option value="Binance">Binance</option>
                                <option value="Bank Transfer">Bank Transfer</option>
                                <option value="Escrow">Escrow</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Account Name *</label>
                            <input type="text" name="account_name" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Account Number *</label>
                            <input type="text" name="account_number" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Currency *</label>
                            <input type="text" name="currency_code" class="form-control" value="MWK" maxlength="3" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Starting Balance (MWK)</label>
                            <input type="number" name="current_balance_mwk" class="form-control" min="0" step="0.01" value="0">
                        </div>
                    </div>
                    <div style="display:flex; gap:1.5rem; margin-top:0.5rem;">
                        <label><input type="checkbox" name="is_active" value="1" checked> Active</label>
                        <label><input type="checkbox" name="is_default" value="1"> Set as default</label>
                    </div>
                    <button type="submit" class="btn btn-primary mt-3">Add Account</button>
                </form>
            </div>

            <div class="card">
                <div class="card-header"><h3 class="card-title">Existing Platform Accounts</h3></div>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Account</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Balance</th>
                                <th>Default</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($accounts)): ?>
                                <tr><td colspan="6" style="text-align:center; padding:2rem; color:var(--text-secondary);">No platform accounts found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($accounts as $acc): ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight:600;"><?php echo h($acc['account_provider'] . ' - ' . $acc['account_name']); ?></div>
                                            <div class="account-meta"><?php echo h($acc['account_number']); ?> | <?php echo h($acc['currency_code']); ?></div>
                                        </td>
                                        <td><?php echo h(ucwords(str_replace('_', ' ', $acc['account_type']))); ?></td>
                                        <td>
                                            <span class="badge badge-<?php echo (int)$acc['is_active'] === 1 ? 'success' : 'secondary'; ?>">
                                                <?php echo (int)$acc['is_active'] === 1 ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </td>
                                        <td><?php echo format_currency($acc['current_balance_mwk']); ?></td>
                                        <td>
                                            <?php if ((int)$acc['is_default'] === 1): ?>
                                                <span class="badge badge-success">Default</span>
                                            <?php else: ?>
                                                <span class="text-secondary">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <form method="POST" class="inline-form">
                                                <?php echo csrf_input(); ?>
                                                <input type="hidden" name="action" value="update_balance">
                                                <input type="hidden" name="account_id" value="<?php echo (int)$acc['account_id']; ?>">
                                                <input type="number" name="new_balance_mwk" class="mini-input" min="0" step="0.01" value="<?php echo h($acc['current_balance_mwk']); ?>">
                                                <button type="submit" class="btn btn-sm btn-secondary">Update Balance</button>
                                            </form>

                                            <?php if ((int)$acc['is_active'] === 1 && (int)$acc['is_default'] !== 1): ?>
                                                <form method="POST" class="inline-form" style="margin-top:0.5rem;">
                                                    <?php echo csrf_input(); ?>
                                                    <input type="hidden" name="action" value="set_default">
                                                    <input type="hidden" name="account_id" value="<?php echo (int)$acc['account_id']; ?>">
                                                    <button type="submit" class="btn btn-sm" style="background:var(--success); color:#fff;">Set Default</button>
                                                </form>
                                            <?php endif; ?>

                                            <form method="POST" class="inline-form" style="margin-top:0.5rem;">
                                                <?php echo csrf_input(); ?>
                                                <input type="hidden" name="action" value="toggle_status">
                                                <input type="hidden" name="account_id" value="<?php echo (int)$acc['account_id']; ?>">
                                                <input type="hidden" name="is_active" value="<?php echo (int)$acc['is_active'] === 1 ? 0 : 1; ?>">
                                                <button type="submit" class="btn btn-sm <?php echo (int)$acc['is_active'] === 1 ? 'btn-danger' : 'btn-secondary'; ?>">
                                                    <?php echo (int)$acc['is_active'] === 1 ? 'Deactivate' : 'Activate'; ?>
                                                </button>
                                            </form>
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
        const accountTypeEl = document.getElementById('account_type');
        const accountProviderEl = document.getElementById('account_provider');
        const providerMap = {
            airtel_money: 'Airtel Money',
            tnm_mpamba: 'TNM Mpamba',
            sticpay: 'Sticpay',
            mastercard: 'Mastercard',
            visa: 'Visa',
            binance: 'Binance',
            bank_transfer: 'Bank Transfer',
            escrow: 'Escrow'
        };

        accountTypeEl?.addEventListener('change', function() {
            const provider = providerMap[this.value];
            if (provider && accountProviderEl) {
                accountProviderEl.value = provider;
            }
        });
    </script>
    <script src="<?php echo asset_url('assets/js/sidebar.js'); ?>"></script>
</body>
</html>
