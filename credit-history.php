<?php
require_once 'config/config.php';
require_once 'classes/User.php';

// Hybrid rollout: route credit-history UI to Next.js when enabled.
$nextCreditHistoryUrl = nextjs_url('/credit-history');
if (feature_enabled('nextjs_credit_history') && $nextCreditHistoryUrl !== '') {
    redirect($nextCreditHistoryUrl);
}

// Require login
require_login();

$database = new Database();
$db = $database->getConnection();
$user = new User($db);

// Get user data
$user_data = $user->getUserById(get_user_id());
$user_stats = $user->getUserStats(get_user_id());

// Get credit history
$history_query = "SELECT ch.*, l.principal_mwk AS loan_amount, l.status as loan_status
                  FROM credit_history ch
                  LEFT JOIN loans l ON ch.loan_id = l.loan_id
                  WHERE ch.user_id = :user_id
                  ORDER BY ch.created_at DESC";

$history_stmt = $db->prepare($history_query);
$history_stmt->execute([':user_id' => get_user_id()]);
$credit_history = $history_stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate credit score breakdown
$credit_score = $user_data['credit_score'];

// Score factors (simplified calculation for display)
$payment_history_score = 0;
$credit_utilization_score = 0;
$credit_age_score = 0;
$loan_diversity_score = 0;

// Payment history (40% of score - max 340 points)
$total_loans = $user_stats['loans']['total_loans'] ?? 0;
$repaid_loans = $user_stats['loans']['repaid_loans'] ?? 0;

if ($total_loans > 0) {
    $payment_rate = ($repaid_loans / $total_loans);
    $payment_history_score = round($payment_rate * 340);
} else {
    $payment_history_score = 170; // Default score for new users
}

// Credit utilization (30% of score - max 255 points)
$max_loan = 300000;
$outstanding = $user_stats['outstanding_balance'] ?? 0;
$utilization = $outstanding > 0 ? min(($outstanding / $max_loan), 1) : 0;
$credit_utilization_score = round((1 - $utilization) * 255);

// Account age (20% of score - max 170 points)
$account_age_days = (time() - strtotime($user_data['created_at'])) / (60 * 60 * 24);
$age_factor = min($account_age_days / 365, 1); // Max at 1 year
$credit_age_score = round($age_factor * 170);

// Loan diversity (10% of score - max 85 points)
$loan_diversity_score = min($total_loans * 17, 85);

// Score breakdown percentages
$payment_history_pct = ($payment_history_score / 340) * 100;
$credit_utilization_pct = ($credit_utilization_score / 255) * 100;
$credit_age_pct = ($credit_age_score / 170) * 100;
$loan_diversity_pct = ($loan_diversity_score / 85) * 100;

// Get credit rating
function getCreditRating($score) {
    if ($score >= 750) return ['Excellent', 'var(--success)', 'You have an excellent credit score! You qualify for the best loan terms.'];
    if ($score >= 650) return ['Good', 'var(--success)', 'Your credit score is good. You qualify for favorable loan terms.'];
    if ($score >= 500) return ['Fair', 'var(--warning)', 'Your credit score is fair. You may qualify for loans with standard terms.'];
    if ($score >= 400) return ['Poor', 'var(--warning)', 'Your credit score needs improvement. Focus on timely repayments.'];
    return ['Very Poor', 'var(--error)', 'Your credit score is low. Build credit by repaying loans on time.'];
}

list($rating, $rating_color, $rating_message) = getCreditRating($credit_score);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Credit History - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo asset_url('assets/css/style.css'); ?>">
    <!-- FontAwesome icons -->
    <link rel="stylesheet" href="<?php echo asset_url('assets/css/fontawesome-all.min.css'); ?>" />
    <style>
        .credit-score-card {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.2) 0%, rgba(16, 185, 129, 0.05) 100%);
            border: 1px solid var(--primary-green);
            border-radius: 12px;
            padding: 2rem;
            text-align: center;
            margin-bottom: 2rem;
        }

        .credit-score-display {
            font-size: 5rem;
            font-weight: 700;
            line-height: 1;
            margin: 1rem 0;
        }

        .credit-gauge {
            width: 100%;
            height: 150px;
            position: relative;
            margin: 2rem 0;
        }

        .gauge-bg {
            width: 100%;
            height: 20px;
            background: linear-gradient(to right, 
                #ef4444 0%, 
                #f59e0b 25%, 
                #eab308 50%, 
                #84cc16 75%, 
                #10b981 100%);
            border-radius: 10px;
            position: relative;
        }

        .gauge-marker {
            position: absolute;
            top: -10px;
            width: 40px;
            height: 40px;
            background: white;
            border: 3px solid var(--primary-green);
            border-radius: 50%;
            transform: translateX(-50%);
            transition: left 0.5s ease;
        }

        .gauge-labels {
            display: flex;
            justify-content: space-between;
            margin-top: 0.5rem;
            font-size: 0.75rem;
            color: var(--text-secondary);
        }

        .score-breakdown {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin: 2rem 0;
        }

        .breakdown-item {
            background: var(--dark-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1.5rem;
        }

        .breakdown-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .breakdown-label {
            font-weight: 600;
            color: var(--text-primary);
        }

        .breakdown-score {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-green);
        }

        .progress-bar-wrapper {
            width: 100%;
            height: 8px;
            background: var(--border-color);
            border-radius: 4px;
            overflow: hidden;
            margin-bottom: 0.5rem;
        }

        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary-green), var(--primary-dark-green));
            transition: width 0.5s ease;
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
            padding-left: 1rem;
        }

        .timeline-item::before {
            content: '';
            position: absolute;
            left: -2.5rem;
            top: 0.25rem;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            border: 2px solid var(--dark-card);
        }

        .timeline-item.positive::before {
            background: var(--success);
        }

        .timeline-item.negative::before {
            background: var(--error);
        }

        .timeline-item.neutral::before {
            background: var(--primary-green);
        }

        .timeline-date {
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin-bottom: 0.25rem;
        }

        .timeline-content {
            background: var(--dark-card);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 1rem;
        }

        .timeline-title {
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .score-change {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.875rem;
            margin-left: 0.5rem;
        }

        .score-change.positive {
            background: rgba(16, 185, 129, 0.2);
            color: var(--success);
        }

        .score-change.negative {
            background: rgba(239, 68, 68, 0.2);
            color: var(--error);
        }
    </style>
</head>
<body>
    <div class="gradient-overlay"></div>

    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <button id="sidebarToggle" class="sidebar-toggle">
            <i class="fas fa-chevron-left"></i>
        </button>
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
            <li><a href="<?php echo site_url('credit-history.php'); ?>" class="active">
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
            <h1>Credit History</h1>
            <p class="text-secondary mb-4">Track your credit score and financial activities</p>

            <!-- Credit Score Card -->
            <div class="credit-score-card">
                <h3 style="margin-bottom: 0.5rem;">Your Credit Score</h3>
                <div class="credit-score-display" style="color: <?php echo $rating_color; ?>">
                    <?php echo $credit_score; ?>
                </div>
                <div style="font-size: 1.5rem; font-weight: 600; color: <?php echo $rating_color; ?>; margin-bottom: 0.5rem;">
                    <?php echo $rating; ?>
                </div>
                <p class="text-secondary"><?php echo $rating_message; ?></p>

                <!-- Credit Gauge -->
                <div class="credit-gauge">
                    <div class="gauge-bg">
                        <div class="gauge-marker" style="left: <?php echo ($credit_score / 850) * 100; ?>%"></div>
                    </div>
                    <div class="gauge-labels">
                        <span>300</span>
                        <span>400</span>
                        <span>500</span>
                        <span>650</span>
                        <span>750</span>
                        <span>850</span>
                    </div>
                </div>

                <div style="display: flex; justify-content: center; gap: 2rem; margin-top: 2rem; flex-wrap: wrap;">
                    <div>
                        <div class="text-secondary" style="font-size: 0.875rem;">Score Range</div>
                        <div style="font-weight: 600;">300 - 850</div>
                    </div>
                    <div>
                        <div class="text-secondary" style="font-size: 0.875rem;">Last Updated</div>
                        <div style="font-weight: 600;"><?php echo format_date($user_data['updated_at']); ?></div>
                    </div>
                    <div>
                        <div class="text-secondary" style="font-size: 0.875rem;">Total Events</div>
                        <div style="font-weight: 600;"><?php echo count($credit_history); ?></div>
                    </div>
                </div>
            </div>

            <!-- Score Breakdown -->
            <div class="card mb-4">
                <div class="card-header">
                    <h3 class="card-title">Score Breakdown</h3>
                    <p class="card-subtitle">Factors affecting your credit score</p>
                </div>
                <div style="padding: 2rem;">
                    <div class="score-breakdown">
                        <div class="breakdown-item">
                            <div class="breakdown-header">
                                <span class="breakdown-label">Payment History</span>
                                <span class="breakdown-score"><?php echo round($payment_history_pct); ?>%</span>
                            </div>
                            <div class="progress-bar-wrapper">
                                <div class="progress-bar-fill" style="width: <?php echo $payment_history_pct; ?>%"></div>
                            </div>
                            <div class="text-secondary" style="font-size: 0.875rem;">
                                <?php echo $repaid_loans; ?> of <?php echo $total_loans; ?> loans repaid
                            </div>
                            <div class="text-secondary" style="font-size: 0.75rem; margin-top: 0.5rem;">
                                40% of total score
                            </div>
                        </div>

                        <div class="breakdown-item">
                            <div class="breakdown-header">
                                <span class="breakdown-label">Credit Utilization</span>
                                <span class="breakdown-score"><?php echo round($credit_utilization_pct); ?>%</span>
                            </div>
                            <div class="progress-bar-wrapper">
                                <div class="progress-bar-fill" style="width: <?php echo $credit_utilization_pct; ?>%"></div>
                            </div>
                            <div class="text-secondary" style="font-size: 0.875rem;">
                                <?php echo format_currency($outstanding); ?> outstanding
                            </div>
                            <div class="text-secondary" style="font-size: 0.75rem; margin-top: 0.5rem;">
                                30% of total score
                            </div>
                        </div>

                        <div class="breakdown-item">
                            <div class="breakdown-header">
                                <span class="breakdown-label">Account Age</span>
                                <span class="breakdown-score"><?php echo round($credit_age_pct); ?>%</span>
                            </div>
                            <div class="progress-bar-wrapper">
                                <div class="progress-bar-fill" style="width: <?php echo $credit_age_pct; ?>%"></div>
                            </div>
                            <div class="text-secondary" style="font-size: 0.875rem;">
                                <?php echo round($account_age_days); ?> days old
                            </div>
                            <div class="text-secondary" style="font-size: 0.75rem; margin-top: 0.5rem;">
                                20% of total score
                            </div>
                        </div>

                        <div class="breakdown-item">
                            <div class="breakdown-header">
                                <span class="breakdown-label">Loan Diversity</span>
                                <span class="breakdown-score"><?php echo round($loan_diversity_pct); ?>%</span>
                            </div>
                            <div class="progress-bar-wrapper">
                                <div class="progress-bar-fill" style="width: <?php echo $loan_diversity_pct; ?>%"></div>
                            </div>
                            <div class="text-secondary" style="font-size: 0.875rem;">
                                <?php echo $total_loans; ?> total loans
                            </div>
                            <div class="text-secondary" style="font-size: 0.75rem; margin-top: 0.5rem;">
                                10% of total score
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tips to Improve Score -->
            <div class="card mb-4" style="background: linear-gradient(135deg, rgba(59, 130, 246, 0.1) 0%, rgba(59, 130, 246, 0.05) 100%); 
                       border-color: rgba(59, 130, 246, 0.3);">
                <div class="card-header">
                    <h3 class="card-title">💡 Tips to Improve Your Score</h3>
                </div>
                <div style="padding: 1.5rem;">
                    <ul style="list-style: none; padding: 0; margin: 0; display: grid; gap: 1rem;">
                        <li style="display: flex; gap: 1rem;">
                            <span style="color: var(--success);">✓</span>
                            <span>Always repay your loans on time or before the due date</span>
                        </li>
                        <li style="display: flex; gap: 1rem;">
                            <span style="color: var(--success);">✓</span>
                            <span>Keep your outstanding balance low relative to your borrowing capacity</span>
                        </li>
                        <li style="display: flex; gap: 1rem;">
                            <span style="color: var(--success);">✓</span>
                            <span>Build a longer credit history by maintaining an active account</span>
                        </li>
                        <li style="display: flex; gap: 1rem;">
                            <span style="color: var(--success);">✓</span>
                            <span>Successfully complete multiple loans to show creditworthiness</span>
                        </li>
                        <li style="display: flex; gap: 1rem;">
                            <span style="color: var(--error);">✗</span>
                            <span>Avoid late payments as they significantly impact your score</span>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Credit History Timeline -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Activity Timeline</h3>
                    <p class="card-subtitle">Your complete credit history</p>
                </div>
                <div style="padding: 2rem;">
                    <?php if (empty($credit_history)): ?>
                        <div style="text-align: center; padding: 3rem; color: var(--text-secondary);">
                            <div style="font-size: 4rem; margin-bottom: 1rem;"><i class="fas fa-chart-line"></i></div>
                            <h3>No Credit History Yet</h3>
                            <p>Your credit activities will appear here as you use our services.</p>
                            <a href="<?php echo site_url('apply-loan.php'); ?>" class="btn btn-primary mt-2">Apply for Your First Loan</a>
                        </div>
                    <?php else: ?>
                        <div class="timeline">
                            <?php foreach ($credit_history as $event): ?>
                                <?php
                                $is_positive = in_array($event['event_type'], ['loan_approved', 'payment_made', 'loan_repaid']);
                                $is_negative = in_array($event['event_type'], ['loan_rejected', 'payment_missed']);
                                $item_class = $is_positive ? 'positive' : ($is_negative ? 'negative' : 'neutral');
                                ?>
                                <div class="timeline-item <?php echo $item_class; ?>">
                                    <div class="timeline-date">
                                        <?php echo date('M d, Y g:i A', strtotime($event['created_at'])); ?>
                                    </div>
                                    <div class="timeline-content">
                                        <div class="timeline-title">
                                            <?php 
                                            echo match($event['event_type']) {
                                                'loan_applied' => '<i class="fas fa-file-alt"></i> Loan Application Submitted',
                                                'loan_approved' => '<i class="fas fa-check-circle"></i> Loan Approved',
                                                'loan_rejected' => '<i class="fas fa-times-circle"></i> Loan Rejected',
                                                'payment_made' => '<i class="fas fa-credit-card"></i> Payment Made',
                                                'payment_missed' => '<i class="fas fa-exclamation-triangle"></i> Payment Missed',
                                                'loan_repaid' => '<i class="fas fa-trophy"></i> Loan Fully Repaid',
                                                'score_adjusted' => '<i class="fas fa-cog"></i> Score Adjusted',
                                                default => '<i class="fas fa-chart-line"></i> Credit Event'
                                            };
                                            ?>
                                            
                                            <?php if ($event['score_change']): ?>
                                                <span class="score-change <?php echo $event['score_change'] > 0 ? 'positive' : 'negative'; ?>">
                                                    <?php echo $event['score_change'] > 0 ? '+' : ''; ?><?php echo $event['score_change']; ?> points
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <?php if ($event['description']): ?>
                                            <p class="text-secondary" style="margin: 0.5rem 0 0 0; font-size: 0.875rem;">
                                                <?php echo htmlspecialchars($event['description']); ?>
                                            </p>
                                        <?php endif; ?>
                                        
                                        <?php if ($event['loan_id']): ?>
                                            <div style="margin-top: 0.5rem;">
                                                <a href="loan-details.php?id=<?php echo $event['loan_id']; ?>" 
                                                   style="font-size: 0.875rem; color: var(--primary-green); text-decoration: none;">
                                                    View Loan #LML-<?php echo str_pad($event['loan_id'], 6, '0', STR_PAD_LEFT); ?> →
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Animate progress bars on load
        window.addEventListener('load', function() {
            const bars = document.querySelectorAll('.progress-bar-fill');
            bars.forEach(bar => {
                const width = bar.style.width;
                bar.style.width = '0';
                setTimeout(() => {
                    bar.style.width = width;
                }, 100);
            });
        });
    </script>
    <script src="<?php echo asset_url('assets/js/sidebar.js'); ?>"></script>
</body>
</html>
