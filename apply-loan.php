<?php
require_once 'config/config.php';
require_once 'classes/User.php';
require_once 'classes/Loan.php';

// Require login
require_login();

$database = new Database();
$db = $database->getConnection();
$user = new User($db);
$loan = new Loan($db);

$user_data = $user->getUserById(get_user_id());
$errors = [];
$success = null;
$application_result = null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loan_amount = floatval($_POST['loan_amount'] ?? 0);
    $loan_purpose = sanitize_input($_POST['loan_purpose'] ?? '');
    
    // Validation
    if ($loan_amount < MIN_LOAN_AMOUNT || $loan_amount > MAX_LOAN_AMOUNT) {
        $errors[] = "Loan amount must be between " . format_currency(MIN_LOAN_AMOUNT) . 
                    " and " . format_currency(MAX_LOAN_AMOUNT);
    }
    
    // Check eligibility
    $eligibility = $user->checkLoanEligibility(get_user_id(), $loan_amount);
    
    if (!$eligibility['eligible']) {
        $errors[] = $eligibility['reason'];
    }
    
    // Process loan application if no errors
    if (empty($errors)) {
        $loan_data = [
            'user_id' => get_user_id(),
            'loan_amount' => $loan_amount,
            'loan_purpose' => $loan_purpose,
            'interest_rate' => DEFAULT_INTEREST_RATE,
            'loan_term_days' => DEFAULT_LOAN_TERM
        ];
        
        $loan_id = $loan->createLoan($loan_data);
        
        if ($loan_id) {
            // Process approval automatically (FIFO: $loan_id is application_id)
            $application_result = $loan->processLoanApplication($loan_id);
            
            // Log audit
            log_audit(get_user_id(), 'LOAN_APPLICATION_SUBMITTED', 'loan_applications', $loan_id, null, $loan_data);
        } else {
            $errors[] = "Failed to submit loan application. Please try again.";
        }
    }
}

// Get system settings
$settings_query = "SELECT setting_key, setting_value FROM system_settings 
                   WHERE setting_key IN ('min_loan_amount', 'max_loan_amount', 'default_interest_rate', 'default_loan_term')";
$settings_stmt = $db->prepare($settings_query);
$settings_stmt->execute();
$settings_result = $settings_stmt->fetchAll(PDO::FETCH_ASSOC);

$settings = [];
foreach ($settings_result as $setting) {
    $settings[$setting['setting_key']] = $setting['setting_value'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Apply for Loan - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <!-- FontAwesome icons -->
    <link rel="stylesheet" href="assets/css/fontawesome-all.min.css" />
    <style>
        .loan-calculator {
            background: var(--dark-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .amount-slider {
            width: 100%;
            height: 8px;
            background: var(--border-color);
            border-radius: 4px;
            outline: none;
            -webkit-appearance: none;
        }

        .amount-slider::-webkit-slider-thumb {
            -webkit-appearance: none;
            appearance: none;
            width: 24px;
            height: 24px;
            background: var(--primary-green);
            border-radius: 50%;
            cursor: pointer;
        }

        .amount-slider::-moz-range-thumb {
            width: 24px;
            height: 24px;
            background: var(--primary-green);
            border-radius: 50%;
            cursor: pointer;
            border: none;
        }

        .amount-display {
            font-size: 3rem;
            font-weight: 700;
            color: var(--primary-green);
            text-align: center;
            margin: 2rem 0;
        }

        .loan-details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-top: 2rem;
        }

        .detail-item {
            text-align: center;
            padding: 1rem;
            background: rgba(16, 185, 129, 0.05);
            border-radius: 8px;
        }

        .detail-label {
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin-bottom: 0.5rem;
        }

        .detail-value {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        .eligibility-check {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1) 0%, rgba(16, 185, 129, 0.05) 100%);
            border: 1px solid var(--primary-green);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .check-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .check-icon {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.875rem;
        }

        .check-icon.passed {
            background: var(--success);
        }

        .check-icon.failed {
            background: var(--error);
        }

        .result-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            padding: 2rem;
        }

        .result-content {
            background: var(--dark-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 3rem;
            max-width: 500px;
            width: 100%;
            text-align: center;
        }

        .result-icon {
            font-size: 5rem;
            margin-bottom: 1rem;
        }

        .result-score {
            display: inline-block;
            padding: 0.5rem 1.5rem;
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid var(--primary-green);
            border-radius: 8px;
            font-size: 1.25rem;
            margin-top: 1rem;
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
            <li><a href="<?php echo site_url('apply-loan.php'); ?>" class="active">
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
            <h1>Apply for a Loan</h1>
            <p class="text-secondary mb-4">Get instant approval for loans between <?php echo format_currency(MIN_LOAN_AMOUNT); ?> and <?php echo format_currency(MAX_LOAN_AMOUNT); ?></p>

            <?php if ($user_data['verification_status'] !== 'verified'): ?>
                <div class="alert alert-warning mb-3">
                    <strong><i class="fas fa-exclamation-triangle"></i> Account Not Verified</strong><br>
                    Your account must be verified before you can apply for loans. Please wait for verification or contact support.
                </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error mb-3">
                    <strong>Unable to process application:</strong>
                    <ul style="margin: 0.5rem 0 0 1.5rem;">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div style="max-width: 800px; margin: 0 auto;">
                <!-- Eligibility Check -->
                <div class="eligibility-check">
                    <h3 style="margin-bottom: 1rem;">Eligibility Status</h3>
                    
                    <div class="check-item">
                        <div class="check-icon <?php echo $user_data['verification_status'] === 'verified' ? 'passed' : 'failed'; ?>">
                            <?php echo $user_data['verification_status'] === 'verified' ? '✓' : '✗'; ?>
                        </div>
                        <div>
                            <strong>Account Verified</strong>
                            <p class="text-secondary" style="margin: 0; font-size: 0.875rem;">
                                Status: <?php echo ucfirst($user_data['verification_status']); ?>
                            </p>
                        </div>
                    </div>

                    <div class="check-item">
                        <div class="check-icon <?php echo $user_data['credit_score'] >= 300 ? 'passed' : 'failed'; ?>">
                            <?php echo $user_data['credit_score'] >= 300 ? '✓' : '✗'; ?>
                        </div>
                        <div>
                            <strong>Credit Score</strong>
                            <p class="text-secondary" style="margin: 0; font-size: 0.875rem;">
                                Your score: <?php echo $user_data['credit_score']; ?> (Minimum: 300)
                            </p>
                        </div>
                    </div>

                    <?php
                    // Check for overdue loans
                    $overdue_query = "SELECT COUNT(*) as count FROM loans WHERE user_id = :user_id AND status = 'overdue'";
                    $overdue_stmt = $db->prepare($overdue_query);
                    $overdue_stmt->execute([':user_id' => get_user_id()]);
                    $overdue_result = $overdue_stmt->fetch(PDO::FETCH_ASSOC);
                    $has_overdue = $overdue_result['count'] > 0;
                    ?>

                    <div class="check-item">
                        <div class="check-icon <?php echo !$has_overdue ? 'passed' : 'failed'; ?>">
                            <?php echo !$has_overdue ? '✓' : '✗'; ?>
                        </div>
                        <div>
                            <strong>No Overdue Loans</strong>
                            <p class="text-secondary" style="margin: 0; font-size: 0.875rem;">
                                <?php echo $has_overdue ? 'You have overdue loans' : 'All loans current'; ?>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Loan Calculator -->
                <form method="POST" id="loanForm">
                    <div class="loan-calculator">
                        <h3>Loan Amount</h3>
                        <p class="text-secondary">Select the amount you wish to borrow</p>

                        <div class="amount-display" id="amountDisplay">
                            MK 100,000
                        </div>

                        <input type="range" 
                               class="amount-slider" 
                               id="loanSlider"
                               name="loan_amount"
                               min="<?php echo MIN_LOAN_AMOUNT; ?>" 
                               max="<?php echo MAX_LOAN_AMOUNT; ?>" 
                               step="10000"
                               value="100000"
                               <?php echo $user_data['verification_status'] !== 'verified' ? 'disabled' : ''; ?>>

                        <div style="display: flex; justify-content: space-between; margin-top: 0.5rem; font-size: 0.875rem; color: var(--text-secondary);">
                            <span><?php echo format_currency(MIN_LOAN_AMOUNT); ?></span>
                            <span><?php echo format_currency(MAX_LOAN_AMOUNT); ?></span>
                        </div>

                        <!-- Loan Details -->
                        <div class="loan-details-grid">
                            <div class="detail-item">
                                <div class="detail-label">Interest Rate</div>
                                <div class="detail-value"><?php echo DEFAULT_INTEREST_RATE; ?>%</div>
                            </div>

                            <div class="detail-item">
                                <div class="detail-label">Loan Term</div>
                                <div class="detail-value"><?php echo DEFAULT_LOAN_TERM; ?> days</div>
                            </div>

                            <div class="detail-item">
                                <div class="detail-label">Total Repayment</div>
                                <div class="detail-value" id="totalAmount">MK 105,000</div>
                            </div>
                        </div>
                    </div>

                    <!-- Loan Purpose -->
                    <div class="form-group">
                        <label for="loan_purpose" class="form-label">Loan Purpose (Optional)</label>
                        <textarea id="loan_purpose" 
                                  name="loan_purpose" 
                                  class="form-control" 
                                  rows="3"
                                  placeholder="Tell us what you'll use this loan for..."
                                  <?php echo $user_data['verification_status'] !== 'verified' ? 'disabled' : ''; ?>></textarea>
                    </div>

                    <!-- Submit Button -->
                    <button type="submit" 
                            class="btn btn-primary btn-block btn-lg"
                            <?php echo $user_data['verification_status'] !== 'verified' ? 'disabled' : ''; ?>>
                        Submit Application
                    </button>

                    <p class="text-center text-secondary mt-2" style="font-size: 0.875rem;">
                        Your application will be processed instantly using our automated scoring system
                    </p>
                </form>
            </div>
        </div>
    </div>

    <!-- Application Result Modal -->
    <?php if ($application_result): ?>
        <div class="result-modal" id="resultModal">
            <div class="result-content">
                <?php if ($application_result['status'] === 'approved'): ?>
                    <div class="result-icon"><i class="fas fa-trophy" style="color: var(--success);"></i></div>
                    <h2 style="color: var(--success);">Loan Approved!</h2>
                    <p class="text-secondary">Congratulations! Your loan application has been approved.</p>
                    
                    <div class="result-score">
                        Approval Score: <?php echo $application_result['score']; ?>/100
                    </div>

                    <div style="margin-top: 2rem; text-align: left;">
                        <p><strong>Disbursement Date:</strong> <?php echo format_date($application_result['disbursement_date']); ?></p>
                        <p><strong>Due Date:</strong> <?php echo format_date($application_result['due_date']); ?></p>
                        <p class="text-secondary" style="font-size: 0.875rem;">
                            Funds will be disbursed to your account shortly. You can track your loan in the dashboard.
                        </p>
                    </div>

                    <div style="margin-top: 2rem; display: flex; gap: 1rem;">
                        <a href="<?php echo site_url('dashboard.php'); ?>" class="btn btn-primary" style="flex: 1;">Go to Dashboard</a>
                        <a href="<?php echo site_url('loans.php'); ?>" class="btn btn-secondary" style="flex: 1;">View Loan</a>
                    </div>
                <?php else: ?>
                    <div class="result-icon"><i class="fas fa-hourglass-half" style="color: var(--warning);"></i></div>
                    <h2 style="color: var(--warning);">Application Under Review</h2>
                    <p class="text-secondary"><?php echo htmlspecialchars($application_result['message']); ?></p>
                    
                    <div class="result-score">
                        Score: <?php echo $application_result['score']; ?>/60
                    </div>

                    <div style="margin-top: 2rem; text-align: left;">
                        <p class="text-secondary" style="font-size: 0.875rem;">
                            <strong>How to improve your chances:</strong>
                        </p>
                        <ul style="text-align: left; margin-left: 1.5rem; color: var(--text-secondary); font-size: 0.875rem;">
                            <li>Build your credit history by repaying loans on time</li>
                            <li>Start with a smaller loan amount</li>
                            <li>Keep your account active for longer</li>
                        </ul>
                    </div>

                    <div style="margin-top: 2rem;">
                        <a href="<?php echo site_url('dashboard.php'); ?>" class="btn btn-primary btn-block">Back to Dashboard</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <script>
        const slider = document.getElementById('loanSlider');
        const amountDisplay = document.getElementById('amountDisplay');
        const totalAmountDisplay = document.getElementById('totalAmount');
        const interestRate = <?php echo DEFAULT_INTEREST_RATE; ?>;

        function updateLoanDisplay() {
            const amount = parseInt(slider.value);
            const interest = amount * (interestRate / 100);
            const total = amount + interest;

            amountDisplay.textContent = 'MK ' + amount.toLocaleString();
            totalAmountDisplay.textContent = 'MK ' + total.toLocaleString();
        }

        slider.addEventListener('input', updateLoanDisplay);

        // Prevent form resubmission
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
    </script>
    <script src="assets/js/sidebar.js"></script>
</body>
</html>