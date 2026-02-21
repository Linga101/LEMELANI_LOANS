<?php
require_once 'config/config.php';
require_once 'classes/Loan.php';
require_once 'classes/Payment.php';

// Require login
require_login();

$database = new Database();
$db = $database->getConnection();
$loan_obj = new Loan($db);
$payment = new Payment($db);

$errors = [];
$success = null;
$payment_result = null;

// Get loan_id from URL if specified
$selected_loan_id = intval($_GET['loan_id'] ?? 0);

// Get user's active loans (Malawi: outstanding_balance_mwk, status active/overdue)
$active_loans_query = "SELECT loan_id, user_id, principal_mwk AS loan_amount, outstanding_balance_mwk AS remaining_balance,
                              due_date, status, total_repayable_mwk AS total_amount
                       FROM loans 
                       WHERE user_id = :user_id 
                       AND status IN ('active', 'overdue')
                       AND outstanding_balance_mwk > 0
                       ORDER BY due_date ASC";
$active_loans_stmt = $db->prepare($active_loans_query);
$active_loans_stmt->execute([':user_id' => get_user_id()]);
$active_loans = $active_loans_stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle payment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loan_id = intval($_POST['loan_id'] ?? 0);
    $payment_amount = floatval($_POST['payment_amount'] ?? 0);
    $payment_method = $_POST['payment_method'] ?? '';
    $phone_number = sanitize_input($_POST['phone_number'] ?? '');
    
    // Validation
    if (!$loan_id) {
        $errors[] = "Please select a loan";
    }
    
    if ($payment_amount <= 0) {
        $errors[] = "Please enter a valid payment amount";
    }
    
    if (empty($payment_method)) {
        $errors[] = "Please select a payment method";
    }
    
    // Validate phone for mobile money
    if (in_array($payment_method, ['airtel_money', 'tnm_mpamba']) && empty($phone_number)) {
        $errors[] = "Phone number is required for mobile money payments";
    }
    
    // Process payment if no errors
    if (empty($errors)) {
        $payment_data = [
            'loan_id' => $loan_id,
            'user_id' => get_user_id(),
            'payment_amount' => $payment_amount,
            'payment_method' => $payment_method,
            'notes' => "Payment via " . ucfirst(str_replace('_', ' ', $payment_method))
        ];
        
        $payment_result = $payment->processRepayment($payment_data);
        
        if ($payment_result['success']) {
            // Log audit
            log_audit(get_user_id(), 'PAYMENT_MADE', 'repayments', $payment_result['payment_id'], null, $payment_data);
            
            $success = $payment_result['message'];
        } else {
            $errors[] = $payment_result['message'];
        }
    }
}

// Get recent payments
$recent_payments = $payment->getUserPayments(get_user_id(), 10);

// Get overdue loans
$overdue_loans = $payment->getOverdueLoans(get_user_id());
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Repayments - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .payment-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .payment-methods {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .payment-method-card {
            background: var(--dark-card);
            border: 2px solid var(--border-color);
            border-radius: 12px;
            padding: 1.5rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }

        .payment-method-card:hover {
            border-color: var(--primary-green);
            transform: translateY(-2px);
        }

        .payment-method-card.selected {
            border-color: var(--primary-green);
            background: rgba(16, 185, 129, 0.1);
        }

        .payment-method-card.selected::after {
            content: '✓';
            position: absolute;
            top: 0.5rem;
            right: 0.5rem;
            width: 24px;
            height: 24px;
            background: var(--primary-green);
            color: var(--dark-bg);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }

        .payment-method-icon {
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
        }

        .payment-method-name {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        .loan-select-card {
            background: var(--dark-card);
            border: 2px solid var(--border-color);
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .loan-select-card:hover {
            border-color: var(--primary-green);
        }

        .loan-select-card.selected {
            border-color: var(--primary-green);
            background: rgba(16, 185, 129, 0.05);
        }

        .payment-summary {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1) 0%, rgba(16, 185, 129, 0.05) 100%);
            border: 1px solid var(--primary-green);
            border-radius: 12px;
            padding: 1.5rem;
            margin-top: 1.5rem;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.75rem;
        }

        .summary-row.total {
            font-size: 1.25rem;
            font-weight: 700;
            padding-top: 0.75rem;
            border-top: 1px solid var(--border-color);
        }

        .success-modal {
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

        .success-content {
            background: var(--dark-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 3rem;
            max-width: 500px;
            width: 100%;
            text-align: center;
        }

        .success-icon {
            font-size: 5rem;
            margin-bottom: 1rem;
        }

        @media (max-width: 968px) {
            .payment-section {
                grid-template-columns: 1fr;
            }
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
            <h1>Make a Payment</h1>
            <p class="text-secondary mb-4">Repay your loans using multiple payment methods</p>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error mb-3">
                    <strong>Payment failed:</strong>
                    <ul style="margin: 0.5rem 0 0 1.5rem;">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (!empty($overdue_loans)): ?>
                <div class="alert alert-warning mb-3">
                    <strong>⚠️ Overdue Loans</strong><br>
                    You have <?php echo count($overdue_loans); ?> overdue loan(s). Please make a payment to avoid penalties.
                </div>
            <?php endif; ?>

            <?php if (empty($active_loans)): ?>
                <div class="card">
                    <div style="text-align: center; padding: 3rem;">
                        <div style="font-size: 4rem; margin-bottom: 1rem;">✅</div>
                        <h3>No Active Loans</h3>
                        <p class="text-secondary">You don't have any loans requiring repayment.</p>
                        <a href="<?php echo site_url('loans.php'); ?>" class="btn btn-secondary mt-2">View Loan History</a>
                    </div>
                </div>
            <?php else: ?>
                <form method="POST" id="paymentForm">
                    <div class="payment-section">
                        <!-- Left: Loan Selection -->
                        <div>
                            <div class="card">
                                <div class="card-header">
                                    <h3 class="card-title">Select Loan to Repay</h3>
                                </div>

                                <?php foreach ($active_loans as $loan): ?>
                                    <?php 
                                    $penalty = $payment->calculateLatePenalty($loan['loan_id']);
                                    $is_overdue = $loan['status'] === 'overdue';
                                    $is_selected = $loan['loan_id'] == $selected_loan_id;
                                    ?>
                                    <div class="loan-select-card <?php echo $is_selected ? 'selected' : ''; ?>" 
                                         onclick="selectLoan(<?php echo $loan['loan_id']; ?>, <?php echo $loan['remaining_balance']; ?>, <?php echo $penalty; ?>)">
                                        <div class="flex-between mb-2">
                                            <strong>Loan #LML-<?php echo str_pad($loan['loan_id'], 6, '0', STR_PAD_LEFT); ?></strong>
                                            <span class="badge badge-<?php echo $is_overdue ? 'danger' : 'warning'; ?>">
                                                <?php echo ucfirst($loan['status']); ?>
                                            </span>
                                        </div>
                                        <div class="flex-between">
                                            <div>
                                                <div class="text-secondary" style="font-size: 0.875rem;">Amount Due</div>
                                                <div style="font-size: 1.25rem; font-weight: 600; color: var(--primary-green);">
                                                    <?php echo format_currency($loan['remaining_balance']); ?>
                                                </div>
                                            </div>
                                            <div style="text-align: right;">
                                                <div class="text-secondary" style="font-size: 0.875rem;">Due Date</div>
                                                <div style="font-size: 0.875rem;">
                                                    <?php echo format_date($loan['due_date']); ?>
                                                </div>
                                            </div>
                                        </div>
                                        <?php if ($penalty > 0): ?>
                                            <div style="margin-top: 0.5rem; padding-top: 0.5rem; border-top: 1px solid var(--border-color);">
                                                <span class="text-danger" style="font-size: 0.875rem;">
                                                    ⚠️ Late penalty: <?php echo format_currency($penalty); ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>

                                <input type="hidden" name="loan_id" id="selectedLoanId" value="<?php echo $selected_loan_id; ?>">
                            </div>
                        </div>

                        <!-- Right: Payment Details -->
                        <div>
                            <div class="card">
                                <div class="card-header">
                                    <h3 class="card-title">Payment Details</h3>
                                </div>

                                <div class="form-group">
                                    <label for="payment_amount" class="form-label">Payment Amount *</label>
                                    <input type="number" 
                                           id="payment_amount" 
                                           name="payment_amount" 
                                           class="form-control" 
                                           placeholder="Enter amount"
                                           step="0.01"
                                           min="1"
                                           required>
                                    <small class="form-text">
                                        <span id="remainingText">Select a loan to see amount due</span>
                                    </small>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Payment Method *</label>
                                    <div class="payment-methods">
                                        <div class="payment-method-card" onclick="selectPaymentMethod('airtel_money')">
                                            <div class="payment-method-icon">📱</div>
                                            <div class="payment-method-name">Airtel Money</div>
                                        </div>

                                        <div class="payment-method-card" onclick="selectPaymentMethod('tnm_mpamba')">
                                            <div class="payment-method-icon">💰</div>
                                            <div class="payment-method-name">TNM Mpamba</div>
                                        </div>

                                        <div class="payment-method-card" onclick="selectPaymentMethod('sticpay')">
                                            <div class="payment-method-icon">💳</div>
                                            <div class="payment-method-name">Sticpay</div>
                                        </div>

                                        <div class="payment-method-card" onclick="selectPaymentMethod('mastercard')">
                                            <div class="payment-method-icon">💳</div>
                                            <div class="payment-method-name">Mastercard</div>
                                        </div>

                                        <div class="payment-method-card" onclick="selectPaymentMethod('visa')">
                                            <div class="payment-method-icon">💳</div>
                                            <div class="payment-method-name">Visa</div>
                                        </div>

                                        <div class="payment-method-card" onclick="selectPaymentMethod('binance')">
                                            <div class="payment-method-icon">₿</div>
                                            <div class="payment-method-name">Binance</div>
                                        </div>
                                    </div>
                                    <input type="hidden" name="payment_method" id="selectedPaymentMethod" required>
                                </div>

                                <div class="form-group" id="phoneNumberGroup" style="display: none;">
                                    <label for="phone_number" class="form-label">Phone Number *</label>
                                    <input type="tel" 
                                           id="phone_number" 
                                           name="phone_number" 
                                           class="form-control" 
                                           placeholder="+265...">
                                    <small class="form-text">Enter your mobile money phone number</small>
                                </div>

                                <div class="payment-summary" id="paymentSummary" style="display: none;">
                                    <h4 style="margin-bottom: 1rem;">Payment Summary</h4>
                                    <div class="summary-row">
                                        <span>Loan Amount Due:</span>
                                        <strong id="summaryAmountDue">MK 0</strong>
                                    </div>
                                    <div class="summary-row" id="penaltyRow" style="display: none;">
                                        <span>Late Penalty:</span>
                                        <strong class="text-danger" id="summaryPenalty">MK 0</strong>
                                    </div>
                                    <div class="summary-row">
                                        <span>Payment Amount:</span>
                                        <strong id="summaryPayment">MK 0</strong>
                                    </div>
                                    <div class="summary-row total">
                                        <span>Remaining Balance:</span>
                                        <strong id="summaryRemaining">MK 0</strong>
                                    </div>
                                </div>

                                <button type="submit" class="btn btn-primary btn-block btn-lg mt-3">
                                    Process Payment
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            <?php endif; ?>

            <!-- Recent Payments -->
            <?php if (!empty($recent_payments)): ?>
                <div class="card mt-4">
                    <div class="card-header">
                        <h3 class="card-title">Recent Payments</h3>
                    </div>
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Loan ID</th>
                                    <th>Amount</th>
                                    <th>Method</th>
                                    <th>Reference</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_payments as $p): ?>
                                    <tr>
                                        <td><?php echo date('M d, Y', strtotime($p['payment_date'])); ?></td>
                                        <td>#LML-<?php echo str_pad($p['loan_id'], 6, '0', STR_PAD_LEFT); ?></td>
                                        <td><?php echo format_currency($p['payment_amount']); ?></td>
                                        <td><?php echo ucfirst(str_replace('_', ' ', $p['payment_method'])); ?></td>
                                        <td><code style="font-size: 0.75rem;"><?php echo $p['transaction_reference']; ?></code></td>
                                        <td>
                                            <span class="badge badge-<?php 
                                                echo match($p['payment_status']) {
                                                    'completed' => 'success',
                                                    'pending' => 'warning',
                                                    'failed' => 'danger',
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
            <?php endif; ?>
        </div>
    </div>

    <!-- Success Modal -->
    <?php if ($payment_result && $payment_result['success']): ?>
        <div class="success-modal" id="successModal">
            <div class="success-content">
                <div class="success-icon">✅</div>
                <h2 style="color: var(--success);">Payment Successful!</h2>
                <p class="text-secondary">Your payment has been processed successfully.</p>
                
                <div style="margin: 2rem 0; text-align: left;">
                    <div class="summary-row">
                        <span>Amount Paid:</span>
                        <strong><?php echo format_currency($payment_result['amount_paid']); ?></strong>
                    </div>
                    <div class="summary-row">
                        <span>Transaction Ref:</span>
                        <strong><code style="font-size: 0.875rem;"><?php echo $payment_result['transaction_reference']; ?></code></strong>
                    </div>
                    <div class="summary-row">
                        <span>Remaining Balance:</span>
                        <strong class="<?php echo $payment_result['remaining_balance'] <= 0 ? 'text-success' : ''; ?>">
                            <?php echo format_currency($payment_result['remaining_balance']); ?>
                        </strong>
                    </div>
                </div>

                <?php if ($payment_result['remaining_balance'] <= 0): ?>
                    <div class="alert alert-success mb-3">
                        🎉 Congratulations! You have fully repaid this loan. Your credit score has been increased.
                    </div>
                <?php endif; ?>

                <div style="display: flex; gap: 1rem;">
                    <a href="<?php echo site_url('dashboard.php'); ?>" class="btn btn-primary" style="flex: 1;">Go to Dashboard</a>
                    <a href="<?php echo site_url('loans.php'); ?>" class="btn btn-secondary" style="flex: 1;">View Loans</a>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <script>
        let selectedLoanBalance = 0;
        let selectedPenalty = 0;

        function selectLoan(loanId, balance, penalty) {
            // Update hidden input
            document.getElementById('selectedLoanId').value = loanId;
            
            // Store balance and penalty
            selectedLoanBalance = balance;
            selectedPenalty = penalty;
            
            // Update UI
            document.querySelectorAll('.loan-select-card').forEach(card => {
                card.classList.remove('selected');
            });
            event.currentTarget.classList.add('selected');
            
            // Update amount input
            document.getElementById('payment_amount').value = balance;
            document.getElementById('remainingText').textContent = 'Amount due: ' + formatCurrency(balance);
            
            // Update summary
            updateSummary();
        }

        function selectPaymentMethod(method) {
            // Update hidden input
            document.getElementById('selectedPaymentMethod').value = method;
            
            // Update UI
            document.querySelectorAll('.payment-method-card').forEach(card => {
                card.classList.remove('selected');
            });
            event.currentTarget.classList.add('selected');
            
            // Show/hide phone number field for mobile money
            const phoneGroup = document.getElementById('phoneNumberGroup');
            if (method === 'airtel_money' || method === 'tnm_mpamba') {
                phoneGroup.style.display = 'block';
                document.getElementById('phone_number').required = true;
            } else {
                phoneGroup.style.display = 'none';
                document.getElementById('phone_number').required = false;
            }
        }

        function updateSummary() {
            const paymentAmount = parseFloat(document.getElementById('payment_amount').value) || 0;
            
            if (selectedLoanBalance > 0 && paymentAmount > 0) {
                document.getElementById('paymentSummary').style.display = 'block';
                
                document.getElementById('summaryAmountDue').textContent = formatCurrency(selectedLoanBalance);
                document.getElementById('summaryPayment').textContent = formatCurrency(paymentAmount);
                
                if (selectedPenalty > 0) {
                    document.getElementById('penaltyRow').style.display = 'flex';
                    document.getElementById('summaryPenalty').textContent = formatCurrency(selectedPenalty);
                } else {
                    document.getElementById('penaltyRow').style.display = 'none';
                }
                
                const remaining = Math.max(0, selectedLoanBalance - paymentAmount);
                document.getElementById('summaryRemaining').textContent = formatCurrency(remaining);
            } else {
                document.getElementById('paymentSummary').style.display = 'none';
            }
        }

        function formatCurrency(amount) {
            return 'MK ' + amount.toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
        }

        // Update summary on amount change
        document.getElementById('payment_amount')?.addEventListener('input', updateSummary);

        // Auto-select loan if specified in URL
        <?php if ($selected_loan_id): ?>
            const preselectedLoan = document.querySelector('.loan-select-card.selected');
            if (preselectedLoan) {
                preselectedLoan.click();
            }
        <?php endif; ?>

        // Form validation
        document.getElementById('paymentForm')?.addEventListener('submit', function(e) {
            if (!document.getElementById('selectedLoanId').value) {
                e.preventDefault();
                alert('Please select a loan to repay');
                return false;
            }
            
            if (!document.getElementById('selectedPaymentMethod').value) {
                e.preventDefault();
                alert('Please select a payment method');
                return false;
            }
        });
    </script>
</body>
</html>