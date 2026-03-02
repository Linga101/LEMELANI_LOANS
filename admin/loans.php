<?php
require_once '../config/config.php';
require_once '../classes/Loan.php';

// Require admin role
require_role(['admin', 'manager']);

$database = new Database();
$db = $database->getConnection();
$loan_obj = new Loan($db);

$success = '';
$errors = [];

// Handle loan actions (FIFO: application_id for pending applications)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_fail();
    $action = $_POST['action'] ?? '';
    $application_id = (int)($_POST['application_id'] ?? 0);

    switch ($action) {
        case 'approve':
            $result = $loan_obj->processLoanApplication($application_id, get_user_id());
            if ($result['success']) {
                $success = "Application processed: " . $result['message'];
                log_audit(get_user_id(), 'LOAN_APPLICATION_PROCESSED', 'loan_applications', $application_id, null, $result);
            } else {
                $errors[] = $result['message'];
            }
            break;

        case 'reject':
            $rejection_reason = sanitize_input($_POST['rejection_reason'] ?? 'Rejected by admin');
            $result = $loan_obj->forceRejectApplication($application_id, $rejection_reason, get_user_id());
            if ($result['success']) {
                $success = "Application rejected.";
                log_audit(get_user_id(), 'LOAN_APPLICATION_REJECTED', 'loan_applications', $application_id, null, ['reason' => $rejection_reason]);
            } else {
                $errors[] = $result['message'];
            }
            break;

        case 'waive_penalty':
            $success = "Penalty waiver (implement when needed)";
            break;
    }
}

// Pending applications in FIFO order (oldest first)
$pending_applications = $loan_obj->getPendingApplicationsFifo(100);
$search = sanitize_input($_GET['search'] ?? '');
if ($search) {
    $pending_applications = array_filter($pending_applications, function ($a) use ($search) {
        return (stripos($a['full_name'], $search) !== false || stripos($a['email'], $search) !== false || stripos($a['application_ref'], $search) !== false);
    });
}

// Disbursed loans (from loans table with legacy aliases)
$loans_query = "SELECT l.loan_id, l.application_id, l.user_id, l.principal_mwk AS loan_amount,
                       l.outstanding_balance_mwk AS remaining_balance, l.due_date, l.status, l.created_at,
                       u.full_name, u.email, u.phone, u.credit_score
                FROM loans l
                JOIN users u ON l.user_id = u.user_id
                WHERE 1=1";
$loans_params = [];
$filter_status = $_GET['status'] ?? 'all';
if ($filter_status !== 'all') {
    $loans_query .= " AND l.status = :status";
    $loans_params[':status'] = $filter_status === 'repaid' ? 'completed' : $filter_status;
}
if ($search) {
    $loans_query .= " AND (u.full_name LIKE :search OR u.email LIKE :search OR l.loan_id = :search_id)";
    $loans_params[':search'] = '%' . $search . '%';
    $loans_params[':search_id'] = ctype_digit($search) ? $search : -1;
}
$loans_query .= " ORDER BY l.created_at DESC";
$stmt = $db->prepare($loans_query);
$stmt->execute($loans_params);
$loans = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistics: pending from loan_applications, rest from loans
$pending_count = $db->query("SELECT COUNT(*) FROM loan_applications WHERE status IN ('pending','under_review')")->fetchColumn();
$stats_loans = $db->query("SELECT
    COUNT(*) as total,
    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
    SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) as overdue,
    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as repaid,
    COALESCE(SUM(principal_mwk), 0) as total_amount,
    COALESCE(SUM(CASE WHEN status IN ('active','overdue') THEN outstanding_balance_mwk ELSE 0 END), 0) as outstanding
FROM loans")->fetch(PDO::FETCH_ASSOC);
$stats = [
    'total' => $stats_loans['total'],
    'pending' => $pending_count,
    'active' => $stats_loans['active'],
    'overdue' => $stats_loans['overdue'],
    'repaid' => $stats_loans['repaid'],
    'total_amount' => $stats_loans['total_amount'],
    'outstanding' => $stats_loans['outstanding'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loan Management - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <!-- FontAwesome icons -->
    <link rel="stylesheet" href="../assets/css/fontawesome-all.min.css" />
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
            <li><a href="<?php echo site_url('admin/users.php'); ?>">
                <i class="fas fa-users"></i> Users
            </a></li>
            <li><a href="<?php echo site_url('admin/loans.php'); ?>" class="active">
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
            <li><a href="<?php echo site_url('admin/settings.php'); ?>">
                <i class="fas fa-cog"></i> Settings
            </a></li>
            <li style="margin-top: auto; padding-top: 1rem; border-top: 1px solid var(--border-color);">
                <a href="../dashboard.php">
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
            <h1>Loan Management</h1>
            <p class="text-secondary mb-4">Review, approve, and manage all loan applications</p>

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
                    <div class="stat-label">Total Loans</div>
                    <div class="stat-value"><?php echo $stats['total']; ?></div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Pending Review</div>
                    <div class="stat-value" style="color: var(--warning);"><?php echo $stats['pending']; ?></div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Active Loans</div>
                    <div class="stat-value" style="color: var(--success);"><?php echo $stats['active']; ?></div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Overdue</div>
                    <div class="stat-value" style="color: var(--error);"><?php echo $stats['overdue']; ?></div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Total Disbursed</div>
                    <div class="stat-value" style="font-size: 1.5rem;"><?php echo format_currency($stats['total_amount']); ?></div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Outstanding</div>
                    <div class="stat-value" style="font-size: 1.5rem; color: var(--warning);">
                        <?php echo format_currency($stats['outstanding']); ?>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <form method="GET" style="background: var(--dark-card); border: 1px solid var(--border-color); 
                                     border-radius: 12px; padding: 1.5rem; margin-bottom: 2rem; 
                                     display: flex; gap: 1rem; flex-wrap: wrap; align-items: end;">
                <div style="flex: 1; min-width: 200px;">
                    <label class="form-label">Search</label>
                    <input type="text" name="search" class="form-control" 
                           placeholder="Name, email, or loan ID..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                </div>

                <div style="flex: 1; min-width: 200px;">
                    <label class="form-label">Status (disbursed loans)</label>
                    <select name="status" class="form-control">
                        <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All</option>
                        <option value="active" <?php echo $filter_status === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="overdue" <?php echo $filter_status === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                        <option value="repaid" <?php echo $filter_status === 'repaid' ? 'selected' : ''; ?>>Repaid</option>
                        <option value="completed" <?php echo $filter_status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                    </select>
                </div>

                <div>
                    <button type="submit" class="btn btn-primary">Apply Filters</button>
                </div>
            </form>

            <!-- Pending applications (FIFO) -->
            <?php if (!empty($pending_applications)): ?>
            <h2 style="margin-bottom: 1rem;">Pending applications (FIFO — oldest first)</h2>
            <div class="card mb-4">
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Application ref</th>
                                <th>Borrower</th>
                                <th>Product</th>
                                <th>Amount</th>
                                <th>Credit</th>
                                <th>Applied</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pending_applications as $a): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($a['application_ref']); ?></strong></td>
                                    <td>
                                        <div style="font-weight: 600;"><?php echo htmlspecialchars($a['full_name']); ?></div>
                                        <div class="text-secondary" style="font-size: 0.875rem;"><?php echo htmlspecialchars($a['email']); ?></div>
                                    </td>
                                    <td><?php echo htmlspecialchars($a['product_name']); ?></td>
                                    <td><?php echo format_currency($a['requested_amount_mwk']); ?></td>
                                    <td><?php echo (int)($a['credit_score'] ?? 0); ?></td>
                                    <td><?php echo format_date($a['applied_at']); ?></td>
                                    <td>
                                        <form method="POST" style="display: inline;">
                                            <?php echo csrf_input(); ?>
                                            <input type="hidden" name="application_id" value="<?php echo $a['id']; ?>">
                                            <input type="hidden" name="action" value="approve">
                                            <button type="submit" class="btn btn-sm" style="background: var(--success); color: white;"
                                                    onclick="return confirm('Process this application (FIFO)?')">Process</button>
                                        </form>
                                        <button type="button" onclick="openRejectModal(<?php echo $a['id']; ?>)" class="btn btn-danger btn-sm">Reject</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- Disbursed loans -->
            <h2 style="margin-bottom: 1rem;">Disbursed loans</h2>
            <div class="card">
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Loan ID</th>
                                <th>Borrower</th>
                                <th>Amount</th>
                                <th>Credit</th>
                                <th>Status</th>
                                <th>Disbursed</th>
                                <th>Due Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($loans)): ?>
                                <tr>
                                    <td colspan="8" style="text-align: center; padding: 3rem; color: var(--text-secondary);">No disbursed loans</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($loans as $l): ?>
                                    <tr>
                                        <td><strong>#LML-<?php echo str_pad($l['loan_id'], 6, '0', STR_PAD_LEFT); ?></strong></td>
                                        <td>
                                            <div style="font-weight: 600;"><?php echo htmlspecialchars($l['full_name']); ?></div>
                                            <div class="text-secondary" style="font-size: 0.875rem;"><?php echo htmlspecialchars($l['email']); ?></div>
                                        </td>
                                        <td>
                                            <div style="font-weight: 600;"><?php echo format_currency($l['loan_amount']); ?></div>
                                            <?php if (($l['remaining_balance'] ?? 0) > 0): ?>
                                                <div class="text-secondary" style="font-size: 0.875rem;">Balance: <?php echo format_currency($l['remaining_balance']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo (int)($l['credit_score'] ?? 0); ?></td>
                                        <td>
                                            <span class="badge badge-<?php echo in_array($l['status'], ['active']) ? 'success' : ($l['status'] === 'overdue' ? 'danger' : 'info'); ?>">
                                                <?php echo $l['status'] === 'completed' ? 'Repaid' : ucfirst($l['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo format_date($l['created_at']); ?></td>
                                        <td><?php echo $l['due_date'] ? format_date($l['due_date']) : '-'; ?></td>
                                        <td>
                                            <a href="../loan-details.php?id=<?php echo $l['loan_id']; ?>" class="btn btn-secondary btn-sm">View</a>
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

    <!-- Reject Modal -->
    <div class="modal" id="rejectModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
                background: rgba(0, 0, 0, 0.8); z-index: 9999; align-items: center; justify-content: center; padding: 2rem;">
        <div style="background: var(--dark-card); border: 1px solid var(--border-color); border-radius: 12px; 
                    padding: 2rem; max-width: 500px; width: 100%;">
            <h3 style="margin-bottom: 1rem;">Reject Loan Application</h3>
            
            <form method="POST">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="application_id" id="rejectApplicationId">
                <input type="hidden" name="action" value="reject">
                
                <div class="form-group">
                    <label class="form-label">Rejection Reason</label>
                    <textarea name="rejection_reason" class="form-control" rows="4" 
                              placeholder="Provide a reason for rejecting this loan..." required></textarea>
                </div>
                
                <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                    <button type="submit" class="btn btn-danger" style="flex: 1;">Reject Loan</button>
                    <button type="button" onclick="closeRejectModal()" class="btn btn-secondary" style="flex: 1;">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openRejectModal(applicationId) {
            document.getElementById('rejectApplicationId').value = applicationId;
            document.getElementById('rejectModal').style.display = 'flex';
        }

        function closeRejectModal() {
            document.getElementById('rejectModal').style.display = 'none';
        }

        // Close modal on outside click
        document.getElementById('rejectModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeRejectModal();
            }
        });
    </script>
    <script src="../assets/js/sidebar.js"></script>
</body>
</html>
