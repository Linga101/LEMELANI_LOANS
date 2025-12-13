<?php
require_once '../config/config.php';

// Require admin role
require_role(['admin', 'manager']);

$database = new Database();
$db = $database->getConnection();

$success = '';
$errors = [];

// Handle verification actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $user_id = intval($_POST['user_id'] ?? 0);
    
    if ($action === 'verify') {
        $update_query = "UPDATE users SET verification_status = 'verified' WHERE user_id = :user_id";
        $update_stmt = $db->prepare($update_query);
        
        if ($update_stmt->execute([':user_id' => $user_id])) {
            $success = "User verified successfully";
            log_audit(get_user_id(), 'USER_VERIFIED', 'users', $user_id);
            
            // Send notification
            $notif_query = "INSERT INTO notifications (user_id, notification_type, title, message) 
                          VALUES (:user_id, 'system', 'Account Verified! ✅', 
                                 'Your account has been verified. You can now apply for loans.')";
            $notif_stmt = $db->prepare($notif_query);
            $notif_stmt->execute([':user_id' => $user_id]);
            
            // Increase credit score for verification
            $credit_query = "UPDATE users SET credit_score = credit_score + 50 WHERE user_id = :user_id";
            $credit_stmt = $db->prepare($credit_query);
            $credit_stmt->execute([':user_id' => $user_id]);
        } else {
            $errors[] = "Failed to verify user";
        }
        
    } elseif ($action === 'reject') {
        $rejection_reason = sanitize_input($_POST['rejection_reason'] ?? 'Verification documents rejected');
        
        $update_query = "UPDATE users SET verification_status = 'rejected' WHERE user_id = :user_id";
        $update_stmt = $db->prepare($update_query);
        
        if ($update_stmt->execute([':user_id' => $user_id])) {
            $success = "Verification rejected";
            log_audit(get_user_id(), 'USER_VERIFICATION_REJECTED', 'users', $user_id, null, ['reason' => $rejection_reason]);
            
            // Send notification
            $notif_query = "INSERT INTO notifications (user_id, notification_type, title, message) 
                          VALUES (:user_id, 'system', 'Verification Rejected', :message)";
            $notif_stmt = $db->prepare($notif_query);
            $notif_stmt->execute([
                ':user_id' => $user_id,
                ':message' => 'Your verification was rejected: ' . $rejection_reason . '. Please resubmit your documents.'
            ]);
        } else {
            $errors[] = "Failed to reject verification";
        }
    }
}

// Get pending verifications
$pending_query = "SELECT * FROM users 
                  WHERE verification_status = 'pending' 
                  AND role = 'customer'
                  ORDER BY created_at ASC";
$pending_stmt = $db->prepare($pending_query);
$pending_stmt->execute();
$pending_users = $pending_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recently verified
$verified_query = "SELECT * FROM users 
                   WHERE verification_status = 'verified' 
                   AND role = 'customer'
                   ORDER BY updated_at DESC
                   LIMIT 10";
$verified_stmt = $db->prepare($verified_query);
$verified_stmt->execute();
$verified_users = $verified_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get rejected verifications
$rejected_query = "SELECT * FROM users 
                   WHERE verification_status = 'rejected' 
                   AND role = 'customer'
                   ORDER BY updated_at DESC
                   LIMIT 10";
$rejected_stmt = $db->prepare($rejected_query);
$rejected_stmt->execute();
$rejected_users = $rejected_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifications - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .verification-card {
            background: var(--dark-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            transition: all 0.3s ease;
        }

        .verification-card:hover {
            border-color: var(--primary-green);
            transform: translateY(-2px);
        }

        .user-info {
            display: flex;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .user-avatar {
            width: 80px;
            height: 80px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--primary-green), var(--primary-dark-green));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark-bg);
        }

        .documents-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .document-preview {
            background: var(--dark-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 1rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .document-preview:hover {
            border-color: var(--primary-green);
        }

        .document-preview img {
            max-width: 100%;
            border-radius: 4px;
            margin-bottom: 0.5rem;
            max-height: 150px;
            object-fit: cover;
        }

        .document-icon {
            font-size: 4rem;
            margin-bottom: 0.5rem;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.9);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: var(--dark-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 2rem;
            max-width: 800px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-content img {
            max-width: 100%;
            border-radius: 8px;
        }

        .tabs {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            border-bottom: 1px solid var(--border-color);
        }

        .tab {
            padding: 1rem 1.5rem;
            color: var(--text-secondary);
            cursor: pointer;
            border-bottom: 2px solid transparent;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .tab:hover {
            color: var(--text-primary);
        }

        .tab.active {
            color: var(--primary-green);
            border-bottom-color: var(--primary-green);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
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
            <li><a href="dashboard.php">
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
            <li><a href="verifications.php" class="active">
                <i>✅</i> Verifications
                <?php if (count($pending_users) > 0): ?>
                    <span class="badge badge-warning" style="margin-left: auto; font-size: 0.75rem;">
                        <?php echo count($pending_users); ?>
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
            <h1>User Verifications</h1>
            <p class="text-secondary mb-4">Review and approve user verification documents</p>

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
                    <div class="stat-label">Pending Verifications</div>
                    <div class="stat-value" style="color: var(--warning);"><?php echo count($pending_users); ?></div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Recently Verified</div>
                    <div class="stat-value" style="color: var(--success);"><?php echo count($verified_users); ?></div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Recently Rejected</div>
                    <div class="stat-value" style="color: var(--error);"><?php echo count($rejected_users); ?></div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <div class="tab active" onclick="switchTab('pending')">
                    Pending (<?php echo count($pending_users); ?>)
                </div>
                <div class="tab" onclick="switchTab('verified')">
                    Verified (<?php echo count($verified_users); ?>)
                </div>
                <div class="tab" onclick="switchTab('rejected')">
                    Rejected (<?php echo count($rejected_users); ?>)
                </div>
            </div>

            <!-- Pending Verifications Tab -->
            <div id="pending-tab" class="tab-content active">
                <?php if (empty($pending_users)): ?>
                    <div class="card">
                        <div style="text-align: center; padding: 3rem; color: var(--text-secondary);">
                            <div style="font-size: 4rem; margin-bottom: 1rem;">✅</div>
                            <h3>No Pending Verifications</h3>
                            <p>All users have been verified or rejected.</p>
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach ($pending_users as $u): ?>
                        <div class="verification-card">
                            <div class="user-info">
                                <div class="user-avatar">
                                    <?php echo strtoupper(substr($u['full_name'], 0, 2)); ?>
                                </div>
                                <div style="flex: 1;">
                                    <h3 style="margin-bottom: 0.5rem;"><?php echo htmlspecialchars($u['full_name']); ?></h3>
                                    <div class="text-secondary" style="font-size: 0.875rem; margin-bottom: 0.5rem;">
                                        <strong>National ID:</strong> <?php echo htmlspecialchars($u['national_id']); ?>
                                    </div>
                                    <div class="text-secondary" style="font-size: 0.875rem; margin-bottom: 0.5rem;">
                                        <strong>Email:</strong> <?php echo htmlspecialchars($u['email']); ?>
                                    </div>
                                    <div class="text-secondary" style="font-size: 0.875rem;">
                                        <strong>Phone:</strong> <?php echo htmlspecialchars($u['phone']); ?>
                                    </div>
                                    <div class="text-secondary" style="font-size: 0.875rem; margin-top: 0.5rem;">
                                        <strong>Applied:</strong> <?php echo format_date($u['created_at']); ?>
                                    </div>
                                </div>
                            </div>

                            <h4 style="margin-bottom: 1rem;">Verification Documents</h4>
                            <div class="documents-grid">
                                <!-- Selfie -->
                                <div class="document-preview" onclick="viewDocument('<?php echo UPLOAD_URL . $u['selfie_path']; ?>', 'Selfie - <?php echo htmlspecialchars($u['full_name']); ?>')">
                                    <?php if ($u['selfie_path']): ?>
                                        <img src="<?php echo UPLOAD_URL . $u['selfie_path']; ?>" alt="Selfie">
                                    <?php else: ?>
                                        <div class="document-icon">📸</div>
                                    <?php endif; ?>
                                    <div style="font-size: 0.875rem; font-weight: 500;">Selfie Photo</div>
                                    <div class="text-secondary" style="font-size: 0.75rem;">Click to view</div>
                                </div>

                                <!-- National ID -->
                                <div class="document-preview" onclick="viewDocument('<?php echo UPLOAD_URL . $u['id_document_path']; ?>', 'National ID - <?php echo htmlspecialchars($u['full_name']); ?>')">
                                    <?php if ($u['id_document_path']): ?>
                                        <?php if (strpos($u['id_document_path'], '.pdf') !== false): ?>
                                            <div class="document-icon">📄</div>
                                        <?php else: ?>
                                            <img src="<?php echo UPLOAD_URL . $u['id_document_path']; ?>" alt="National ID">
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="document-icon">🆔</div>
                                    <?php endif; ?>
                                    <div style="font-size: 0.875rem; font-weight: 500;">National ID</div>
                                    <div class="text-secondary" style="font-size: 0.75rem;">Click to view</div>
                                </div>
                            </div>

                            <div style="display: flex; gap: 1rem;">
                                <form method="POST" style="flex: 1;" onsubmit="return confirm('Verify this user?')">
                                    <input type="hidden" name="user_id" value="<?php echo $u['user_id']; ?>">
                                    <input type="hidden" name="action" value="verify">
                                    <button type="submit" class="btn btn-primary btn-block">
                                        ✅ Verify User
                                    </button>
                                </form>

                                <button onclick="openRejectModal(<?php echo $u['user_id']; ?>, '<?php echo htmlspecialchars($u['full_name']); ?>')" 
                                        class="btn btn-danger btn-block" style="flex: 1;">
                                    ❌ Reject
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Verified Tab -->
            <div id="verified-tab" class="tab-content">
                <div class="card">
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>National ID</th>
                                    <th>Contact</th>
                                    <th>Verified On</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($verified_users)): ?>
                                    <tr>
                                        <td colspan="5" style="text-align: center; padding: 2rem; color: var(--text-secondary);">
                                            No verified users yet
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($verified_users as $u): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($u['full_name']); ?></strong>
                                            </td>
                                            <td><?php echo htmlspecialchars($u['national_id']); ?></td>
                                            <td>
                                                <div><?php echo htmlspecialchars($u['email']); ?></div>
                                                <div class="text-secondary" style="font-size: 0.875rem;">
                                                    <?php echo htmlspecialchars($u['phone']); ?>
                                                </div>
                                            </td>
                                            <td><?php echo format_date($u['updated_at']); ?></td>
                                            <td>
                                                <a href="user-details.php?id=<?php echo $u['user_id']; ?>" 
                                                   class="btn btn-secondary btn-sm">View</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Rejected Tab -->
            <div id="rejected-tab" class="tab-content">
                <div class="card">
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>National ID</th>
                                    <th>Contact</th>
                                    <th>Rejected On</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($rejected_users)): ?>
                                    <tr>
                                        <td colspan="5" style="text-align: center; padding: 2rem; color: var(--text-secondary);">
                                            No rejected verifications
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($rejected_users as $u): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($u['full_name']); ?></strong>
                                            </td>
                                            <td><?php echo htmlspecialchars($u['national_id']); ?></td>
                                            <td>
                                                <div><?php echo htmlspecialchars($u['email']); ?></div>
                                                <div class="text-secondary" style="font-size: 0.875rem;">
                                                    <?php echo htmlspecialchars($u['phone']); ?>
                                                </div>
                                            </td>
                                            <td><?php echo format_date($u['updated_at']); ?></td>
                                            <td>
                                                <a href="user-details.php?id=<?php echo $u['user_id']; ?>" 
                                                   class="btn btn-secondary btn-sm">View</a>
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
    </div>

    <!-- Document Viewer Modal -->
    <div class="modal" id="documentModal">
        <div class="modal-content">
            <div class="flex-between mb-3">
                <h3 id="documentTitle">Document Preview</h3>
                <button onclick="closeDocumentModal()" class="btn btn-secondary btn-sm">Close</button>
            </div>
            <div id="documentContent" style="text-align: center;">
                <!-- Document will be loaded here -->
            </div>
        </div>
    </div>

    <!-- Reject Modal -->
    <div class="modal" id="rejectModal">
        <div class="modal-content">
            <h3 style="margin-bottom: 1rem;">Reject Verification</h3>
            <p class="text-secondary mb-3" id="rejectUserName"></p>
            
            <form method="POST">
                <input type="hidden" name="user_id" id="rejectUserId">
                <input type="hidden" name="action" value="reject">
                
                <div class="form-group">
                    <label class="form-label">Rejection Reason *</label>
                    <textarea name="rejection_reason" class="form-control" rows="4" 
                              placeholder="Explain why the verification is being rejected..." required></textarea>
                    <small class="form-text">This will be sent to the user</small>
                </div>
                
                <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                    <button type="submit" class="btn btn-danger" style="flex: 1;">Reject Verification</button>
                    <button type="button" onclick="closeRejectModal()" class="btn btn-secondary" style="flex: 1;">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Tab switching
        function switchTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });

            // Show selected tab
            document.getElementById(tabName + '-tab').classList.add('active');
            event.target.classList.add('active');
        }

        // Document viewer
        function viewDocument(url, title) {
            document.getElementById('documentTitle').textContent = title;
            
            if (url.endsWith('.pdf')) {
                document.getElementById('documentContent').innerHTML = 
                    '<iframe src="' + url + '" style="width: 100%; height: 600px; border: none; border-radius: 8px;"></iframe>';
            } else {
                document.getElementById('documentContent').innerHTML = 
                    '<img src="' + url + '" alt="' + title + '" style="max-width: 100%; border-radius: 8px;">';
            }
            
            document.getElementById('documentModal').classList.add('active');
        }

        function closeDocumentModal() {
            document.getElementById('documentModal').classList.remove('active');
        }

        // Reject modal
        function openRejectModal(userId, userName) {
            document.getElementById('rejectUserId').value = userId;
            document.getElementById('rejectUserName').textContent = 'Rejecting verification for: ' + userName;
            document.getElementById('rejectModal').classList.add('active');
        }

        function closeRejectModal() {
            document.getElementById('rejectModal').classList.remove('active');
        }

        // Close modals on outside click
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.remove('active');
                }
            });
        });
    </script>
</body>
</html>