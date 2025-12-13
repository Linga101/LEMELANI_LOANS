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

// Handle loan actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $loan_id = intval($_POST['loan_id'] ?? 0);
    
    switch ($action) {
        case 'approve':
            $result = $loan_obj->processLoanApplication($loan_id, get_user_id());
            if ($result['success']) {
                $success = "Loan processed: " . $result['message'];
            } else {
                $errors[] = $result['message'];
            }
            break;
            
        case 'reject':
            $rejection_reason = sanitize_input($_POST['rejection_reason'] ?? 'Rejected by admin');
            
            $update_query = "UPDATE loans 
                           SET status = 'rejected', rejection_reason = :reason 
                           WHERE loan_id = :loan_id";
            $update_stmt = $db->prepare($update_query);
            
            if ($update_stmt->execute([':reason' => $rejection_reason, ':loan_id' => $loan_id])) {
                $success = "Loan rejected successfully";
                log_audit(get_user_id(), 'LOAN_REJECTED', 'loans', $loan_id, null, ['reason' => $rejection_reason]);
            } else {
                $errors[] = "Failed to reject loan";
            }
            break;
            
        case 'waive_penalty':
            // Add penalty waiver logic here
            $success = "Penalty waived (feature to be implemented)";
            break;
    }
}

// Get filters
$filter_status = $_GET['status'] ?? 'all';
$search = sanitize_input($_GET['search'] ?? '');

// Build query
$query = "SELECT l.*, u.full_name, u.email, u.phone, u.credit_score 
          FROM loans l
          JOIN users u ON l.user_id = u.user_id
          WHERE 1=1";

$params = [];

if ($filter_status !== 'all') {
    $query .= " AND l.status = :status";
    $params[':status'] = $filter_status;
}

if ($search) {
    $query .= " AND (u.full_name LIKE :search OR u.email LIKE :search OR l.loan_id LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

$query .= " ORDER BY l.created_at DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$loans = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate statistics
$stats_query = "SELECT 
                 COUNT(*) as total,
                 SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                 SUM(CASE WHEN status IN ('active', 'approved', 'disbursed') THEN 1 ELSE 0 END) as active,
                 SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) as overdue,
                 SUM(CASE WHEN status = 'repaid' THEN 1 ELSE 0 END) as repaid,
                 SUM(loan_amount) as total_amount,
                 SUM(remaining_balance) as outstanding
                FROM loans";
$stats_stmt = $db->prepare($stats_query);
$stats_stmt->execute();
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loan Management - <?php echo SITE_NAME; ?></title>
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
            <li><a href="loans.php" class="active">
                <i>💰</i> Loans
            </a></li>
            <li><a href="payments.php">
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
                    <label class="form-label">Status</label>
                    <select name="status" class="form-control">
                        <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All Loans</option>
                        <option value="pending" <?php echo $filter_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="approved" <?php echo $filter_status === 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="active" <?php echo $filter_status === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="overdue" <?php echo $filter_status === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                        <option value="repaid" <?php echo $filter_status === 'repaid' ? 'selected' : ''; ?>>Repaid</option>
                        <option value="rejected" <?php echo $filter_status === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                    </select>
                </div>

                <div>
                    <button type="submit" class="btn btn-primary">Apply Filters</button>
                </div>
            </form>

            <!-- Loans Table -->
            <div class="card">
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Loan ID</th>
                                <th>Borrower</th>
                                <th>Amount</th>
                                <th>Credit Score</th>
                                <th>Status</th>
                                <th>Applied</th>
                                <th>Due Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($loans)): ?>
                                <tr>
                                    <td colspan="8" style="text-align: center; padding: 3rem; color: var(--text-secondary);">
                                        No loans found
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($loans as $l): ?>
                                    <tr>
                                        <td>
                                            <strong>#LML-<?php echo str_pad($l['loan_id'], 6, '0', STR_PAD_LEFT); ?></strong>
                                        </td>
                                        <td>
                                            <div style="font-weight: 600;"><?php echo htmlspecialchars($l['full_name']); ?></div>
                                            <div class="text-secondary" style="font-size: 0.875rem;">
                                                <?php echo htmlspecialchars($l['email']); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="font-weight: 600;"><?php echo format_currency($l['loan_amount']); ?></div>
                                            <?php if ($l['remaining_balance'] > 0): ?>
                                                <div class="text-secondary" style="font-size: 0.875rem;">
                                                    Balance: <?php echo format_currency($l['remaining_balance']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong style="color: <?php 
                                                echo $l['credit_score'] >= 700 ? 'var(--success)' : 
                                                     ($l['credit_score'] >= 500 ? 'var(--warning)' : 'var(--error)');
                                            ?>">
                                                <?php echo $l['credit_score']; ?>
                                            </strong>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?php 
                                                echo match($l['status']) {
                                                    'approved', 'disbursed', 'active' => 'success',
                                                    'pending' => 'warning',
                                                    'overdue' => 'danger',
                                                    'repaid' => 'info',
                                                    'rejected' => 'secondary',
                                                    default => 'secondary'
                                                };
                                            ?>">
                                                <?php echo ucfirst($l['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo format_date($l['created_at']); ?></td>
                                        <td>
                                            <?php echo $l['due_date'] ? format_date($l['due_date']) : '-'; ?>
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                                                <a href="loan-details.php?id=<?php echo $l['loan_id']; ?>" 
                                                   class="btn btn-secondary btn-sm">View</a>
                                                
                                                <?php if ($l['status'] === 'pending'): ?>
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="loan_id" value="<?php echo $l['loan_id']; ?>">
                                                        <input type="hidden" name="action" value="approve">
                                                        <button type="submit" class="btn btn-sm" 
                                                                style="background: var(--success); color: white;"
                                                                onclick="return confirm('Process this loan application?')">
                                                            Process
                                                        </button>
                                                    </form>
                                                    
                                                    <button onclick="openRejectModal(<?php echo $l['loan_id']; ?>)" 
                                                            class="btn btn-danger btn-sm">
                                                        Reject
                                                    </button>
                                                <?php endif; ?>
                                            </div>
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
                <input type="hidden" name="loan_id" id="rejectLoanId">
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
        function openRejectModal(loanId) {
            document.getElementById('rejectLoanId').value = loanId;
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
</body>
</html>