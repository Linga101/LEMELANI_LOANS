<?php
require_once 'config/config.php';

// Require login
require_login();

$database = new Database();
$db = $database->getConnection();

$success = '';

// Handle mark as read
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'mark_read') {
        $notification_id = intval($_POST['notification_id'] ?? 0);
        
        $update_query = "UPDATE notifications SET is_read = 1 WHERE notification_id = :id AND user_id = :user_id";
        $update_stmt = $db->prepare($update_query);
        
        if ($update_stmt->execute([':id' => $notification_id, ':user_id' => get_user_id()])) {
            $success = "Notification marked as read";
        }
        
    } elseif ($action === 'mark_all_read') {
        $update_query = "UPDATE notifications SET is_read = 1 WHERE user_id = :user_id AND is_read = 0";
        $update_stmt = $db->prepare($update_query);
        
        if ($update_stmt->execute([':user_id' => get_user_id()])) {
            $success = "All notifications marked as read";
        }
        
    } elseif ($action === 'delete') {
        $notification_id = intval($_POST['notification_id'] ?? 0);
        
        $delete_query = "DELETE FROM notifications WHERE notification_id = :id AND user_id = :user_id";
        $delete_stmt = $db->prepare($delete_query);
        
        if ($delete_stmt->execute([':id' => $notification_id, ':user_id' => get_user_id()])) {
            $success = "Notification deleted";
        }
    }
}

// Get filter
$filter = $_GET['filter'] ?? 'all';

// Build query
$query = "SELECT * FROM notifications WHERE user_id = :user_id";

if ($filter === 'unread') {
    $query .= " AND is_read = 0";
} elseif ($filter === 'read') {
    $query .= " AND is_read = 1";
} elseif ($filter !== 'all') {
    $query .= " AND notification_type = :type";
}

$query .= " ORDER BY created_at DESC";

$stmt = $db->prepare($query);
$params = [':user_id' => get_user_id()];

if ($filter !== 'all' && $filter !== 'unread' && $filter !== 'read') {
    $params[':type'] = $filter;
}

$stmt->execute($params);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Count unread
$unread_query = "SELECT COUNT(*) as unread FROM notifications WHERE user_id = :user_id AND is_read = 0";
$unread_stmt = $db->prepare($unread_query);
$unread_stmt->execute([':user_id' => get_user_id()]);
$unread_count = $unread_stmt->fetch(PDO::FETCH_ASSOC)['unread'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <!-- FontAwesome icons -->
    <link rel="stylesheet" href="assets/css/fontawesome-all.min.css" />
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
            font-size: 0.875rem;
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

        .notification-item {
            background: var(--dark-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            transition: all 0.3s ease;
            position: relative;
        }

        .notification-item:hover {
            border-color: var(--primary-green);
            transform: translateY(-2px);
        }

        .notification-item.unread {
            background: rgba(16, 185, 129, 0.05);
            border-left: 4px solid var(--primary-green);
        }

        .notification-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 0.75rem;
        }

        .notification-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            margin-right: 1rem;
            flex-shrink: 0;
        }

        .notification-icon.approval {
            background: rgba(16, 185, 129, 0.2);
        }

        .notification-icon.rejection {
            background: rgba(239, 68, 68, 0.2);
        }

        .notification-icon.payment {
            background: rgba(59, 130, 246, 0.2);
        }

        .notification-icon.reminder {
            background: rgba(245, 158, 11, 0.2);
        }

        .notification-icon.overdue {
            background: rgba(239, 68, 68, 0.2);
        }

        .notification-icon.system {
            background: rgba(156, 163, 175, 0.2);
        }

        .notification-content {
            flex: 1;
        }

        .notification-title {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text-primary);
        }

        .notification-message {
            color: var(--text-secondary);
            font-size: 0.875rem;
            line-height: 1.6;
        }

        .notification-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border-color);
        }

        .notification-time {
            font-size: 0.75rem;
            color: var(--text-muted);
        }

        .notification-actions {
            display: flex;
            gap: 0.5rem;
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
            <li><a href="<?php echo site_url('loans.php'); ?>">
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
            <li><a href="<?php echo site_url('notifications.php'); ?>" class="active">
                <i class="fas fa-bell"></i> Notifications
                <?php if ($unread_count > 0): ?>
                    <span class="badge badge-danger" style="margin-left: auto; font-size: 0.75rem;">
                        <?php echo $unread_count; ?>
                    </span>
                <?php endif; ?>
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
                    <h1>Notifications</h1>
                    <p class="text-secondary">Stay updated with your account activities</p>
                </div>
                <?php if ($unread_count > 0): ?>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="mark_all_read">
                        <button type="submit" class="btn btn-secondary">
                            ✓ Mark All as Read
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <?php if ($success): ?>
                <div class="alert alert-success mb-3"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <!-- Statistics -->
            <div class="stats-grid mb-4">
                <div class="stat-card">
                    <div class="stat-label">Total Notifications</div>
                    <div class="stat-value"><?php echo count($notifications); ?></div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Unread</div>
                    <div class="stat-value" style="color: var(--warning);"><?php echo $unread_count; ?></div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Read</div>
                    <div class="stat-value" style="color: var(--success);"><?php echo count($notifications) - $unread_count; ?></div>
                </div>
            </div>

            <!-- Filter Tabs -->
            <div class="filter-tabs">
                <a href="notifications.php?filter=all" class="filter-tab <?php echo $filter === 'all' ? 'active' : ''; ?>">
                    All (<?php echo count($notifications); ?>)
                </a>
                <a href="notifications.php?filter=unread" class="filter-tab <?php echo $filter === 'unread' ? 'active' : ''; ?>">
                    Unread (<?php echo $unread_count; ?>)
                </a>
                <a href="notifications.php?filter=approval" class="filter-tab <?php echo $filter === 'approval' ? 'active' : ''; ?>">
                    Approvals
                </a>
                <a href="notifications.php?filter=payment_received" class="filter-tab <?php echo $filter === 'payment_received' ? 'active' : ''; ?>">
                    Payments
                </a>
                <a href="notifications.php?filter=reminder" class="filter-tab <?php echo $filter === 'reminder' ? 'active' : ''; ?>">
                    Reminders
                </a>
                <a href="notifications.php?filter=system" class="filter-tab <?php echo $filter === 'system' ? 'active' : ''; ?>">
                    System
                </a>
            </div>

            <!-- Notifications List -->
            <?php if (empty($notifications)): ?>
                <div class="card">
                    <div style="text-align: center; padding: 4rem; color: var(--text-secondary);">
                        <div style="font-size: 5rem; margin-bottom: 1rem;"><i class="fas fa-bell"></i></div>
                        <h3>No Notifications</h3>
                        <p>You're all caught up! New notifications will appear here.</p>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($notifications as $notif): ?>
                    <div class="notification-item <?php echo !$notif['is_read'] ? 'unread' : ''; ?>">
                        <div style="display: flex;">
                            <div class="notification-icon <?php echo $notif['notification_type']; ?>">
                                <?php 
                                echo match($notif['notification_type']) {
                                    'approval' => '<i class="fas fa-check-circle"></i>',
                                    'rejection' => '<i class="fas fa-times-circle"></i>',
                                    'payment_received' => '<i class="fas fa-credit-card"></i>',
                                    'reminder' => '<i class="fas fa-clock"></i>',
                                    'overdue' => '<i class="fas fa-exclamation-triangle"></i>',
                                    'system' => '<i class="fas fa-info-circle"></i>',
                                    default => '<i class="fas fa-bell"></i>'
                                };
                                ?>
                            </div>
                            
                            <div class="notification-content">
                                <div class="notification-header">
                                    <div>
                                        <div class="notification-title">
                                            <?php echo htmlspecialchars($notif['title']); ?>
                                            <?php if (!$notif['is_read']): ?>
                                                <span class="badge badge-primary" style="font-size: 0.7rem; margin-left: 0.5rem;">NEW</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="notification-time">
                                            <?php 
                                            $time_diff = time() - strtotime($notif['created_at']);
                                            if ($time_diff < 3600) {
                                                echo round($time_diff / 60) . ' minutes ago';
                                            } elseif ($time_diff < 86400) {
                                                echo round($time_diff / 3600) . ' hours ago';
                                            } else {
                                                echo date('M d, Y g:i A', strtotime($notif['created_at']));
                                            }
                                            ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="notification-message">
                                    <?php echo nl2br(htmlspecialchars($notif['message'])); ?>
                                </div>
                                
                                <div class="notification-footer">
                                    <div>
                                        <?php if ($notif['related_loan_id']): ?>
                                            <a href="loan-details.php?id=<?php echo $notif['related_loan_id']; ?>" 
                                               style="font-size: 0.875rem; color: var(--primary-green); text-decoration: none;">
                                                View Loan #LML-<?php echo str_pad($notif['related_loan_id'], 6, '0', STR_PAD_LEFT); ?> →
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="notification-actions">
                                        <?php if (!$notif['is_read']): ?>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="action" value="mark_read">
                                                <input type="hidden" name="notification_id" value="<?php echo $notif['notification_id']; ?>">
                                                <button type="submit" class="btn btn-secondary btn-sm">
                                                    Mark as Read
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        
                                        <form method="POST" style="display: inline;" 
                                              onsubmit="return confirm('Delete this notification?')">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="notification_id" value="<?php echo $notif['notification_id']; ?>">
                                            <button type="submit" class="btn btn-secondary btn-sm">
                                                Delete
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>