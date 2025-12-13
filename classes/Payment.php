<?php
/**
 * Payment Class
 * Handles all payment and repayment operations
 */

class Payment {
    private $conn;
    private $repayments_table = "repayments";
    private $schedule_table = "repayment_schedule";
    private $loans_table = "loans";
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    /**
     * Process a loan repayment
     */
    public function processRepayment($data) {
        try {
            $this->conn->beginTransaction();
            
            // Validate loan
            $loan_query = "SELECT * FROM " . $this->loans_table . " WHERE loan_id = :loan_id AND user_id = :user_id";
            $loan_stmt = $this->conn->prepare($loan_query);
            $loan_stmt->execute([
                ':loan_id' => $data['loan_id'],
                ':user_id' => $data['user_id']
            ]);
            
            $loan = $loan_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$loan) {
                throw new Exception("Loan not found");
            }
            
            if (!in_array($loan['status'], ['active', 'overdue', 'approved', 'disbursed'])) {
                throw new Exception("This loan cannot accept payments");
            }
            
            $payment_amount = floatval($data['payment_amount']);
            $remaining_balance = floatval($loan['remaining_balance']);
            
            if ($payment_amount <= 0) {
                throw new Exception("Invalid payment amount");
            }
            
            if ($payment_amount > $remaining_balance) {
                $payment_amount = $remaining_balance; // Cap at remaining balance
            }
            
            // Generate transaction reference
            $transaction_ref = generate_transaction_reference();
            
            // Insert payment record
            $payment_query = "INSERT INTO " . $this->repayments_table . " 
                            (loan_id, user_id, payment_amount, payment_method, transaction_reference, 
                             payment_status, is_partial, notes) 
                            VALUES (:loan_id, :user_id, :payment_amount, :payment_method, :transaction_ref, 
                                    'completed', :is_partial, :notes)";
            
            $is_partial = $payment_amount < $remaining_balance;
            
            $payment_stmt = $this->conn->prepare($payment_query);
            $payment_stmt->execute([
                ':loan_id' => $data['loan_id'],
                ':user_id' => $data['user_id'],
                ':payment_amount' => $payment_amount,
                ':payment_method' => $data['payment_method'],
                ':transaction_ref' => $transaction_ref,
                ':is_partial' => $is_partial,
                ':notes' => $data['notes'] ?? null
            ]);
            
            $payment_id = $this->conn->lastInsertId();
            
            // Update loan remaining balance
            $new_balance = $remaining_balance - $payment_amount;
            $new_status = $new_balance <= 0 ? 'repaid' : $loan['status'];
            
            $update_loan_query = "UPDATE " . $this->loans_table . " 
                                 SET remaining_balance = :new_balance, 
                                     status = :new_status 
                                 WHERE loan_id = :loan_id";
            
            $update_loan_stmt = $this->conn->prepare($update_loan_query);
            $update_loan_stmt->execute([
                ':new_balance' => $new_balance,
                ':new_status' => $new_status,
                ':loan_id' => $data['loan_id']
            ]);
            
            // Update repayment schedule
            $this->updateRepaymentSchedule($data['loan_id'], $payment_amount);
            
            // Update credit score (increase for payment)
            $credit_increase = $new_balance <= 0 ? 15 : 5; // More for full repayment
            $this->updateUserCreditScore($data['user_id'], $credit_increase);
            
            // Log credit history
            $this->logCreditEvent($data['user_id'], 'payment_made', $data['loan_id'], 
                                 'Payment of ' . format_currency($payment_amount) . ' made');
            
            // Create notification
            $notif_title = $new_balance <= 0 ? 'Loan Fully Repaid! 🎉' : 'Payment Received';
            $notif_message = $new_balance <= 0 
                ? 'Congratulations! You have fully repaid your loan. Your credit score has been increased.'
                : 'Your payment of ' . format_currency($payment_amount) . ' has been received. Remaining balance: ' . format_currency($new_balance);
            
            $this->createNotification($data['user_id'], 'payment_received', $notif_title, $notif_message, $data['loan_id']);
            
            $this->conn->commit();
            
            return [
                'success' => true,
                'payment_id' => $payment_id,
                'transaction_reference' => $transaction_ref,
                'amount_paid' => $payment_amount,
                'remaining_balance' => $new_balance,
                'loan_status' => $new_status,
                'message' => $new_balance <= 0 ? 'Loan fully repaid!' : 'Payment successful'
            ];
            
        } catch(Exception $e) {
            $this->conn->rollBack();
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Update repayment schedule after payment
     */
    private function updateRepaymentSchedule($loan_id, $payment_amount) {
        // Get pending schedule items
        $schedule_query = "SELECT * FROM " . $this->schedule_table . " 
                          WHERE loan_id = :loan_id 
                          AND status IN ('pending', 'overdue', 'partial') 
                          ORDER BY installment_number ASC";
        
        $schedule_stmt = $this->conn->prepare($schedule_query);
        $schedule_stmt->execute([':loan_id' => $loan_id]);
        $schedule_items = $schedule_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $remaining_payment = $payment_amount;
        
        foreach ($schedule_items as $item) {
            if ($remaining_payment <= 0) break;
            
            $amount_remaining = $item['amount_due'] - $item['amount_paid'];
            
            if ($remaining_payment >= $amount_remaining) {
                // Full payment for this installment
                $update_query = "UPDATE " . $this->schedule_table . " 
                               SET amount_paid = amount_due, 
                                   status = 'paid', 
                                   paid_date = NOW() 
                               WHERE schedule_id = :schedule_id";
                
                $update_stmt = $this->conn->prepare($update_query);
                $update_stmt->execute([':schedule_id' => $item['schedule_id']]);
                
                $remaining_payment -= $amount_remaining;
            } else {
                // Partial payment
                $new_paid = $item['amount_paid'] + $remaining_payment;
                $update_query = "UPDATE " . $this->schedule_table . " 
                               SET amount_paid = :new_paid, 
                                   status = 'partial' 
                               WHERE schedule_id = :schedule_id";
                
                $update_stmt = $this->conn->prepare($update_query);
                $update_stmt->execute([
                    ':new_paid' => $new_paid,
                    ':schedule_id' => $item['schedule_id']
                ]);
                
                $remaining_payment = 0;
            }
        }
    }
    
    /**
     * Get payment history for a loan
     */
    public function getLoanPayments($loan_id) {
        $query = "SELECT * FROM " . $this->repayments_table . " 
                 WHERE loan_id = :loan_id 
                 ORDER BY payment_date DESC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':loan_id' => $loan_id]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get all payments for a user
     */
    public function getUserPayments($user_id, $limit = null) {
        $query = "SELECT r.*, l.loan_amount, l.status as loan_status 
                 FROM " . $this->repayments_table . " r
                 JOIN " . $this->loans_table . " l ON r.loan_id = l.loan_id
                 WHERE r.user_id = :user_id 
                 ORDER BY r.payment_date DESC";
        
        if ($limit) {
            $query .= " LIMIT :limit";
        }
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
        
        if ($limit) {
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        }
        
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get repayment schedule for a loan
     */
    public function getRepaymentSchedule($loan_id) {
        $query = "SELECT * FROM " . $this->schedule_table . " 
                 WHERE loan_id = :loan_id 
                 ORDER BY installment_number ASC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':loan_id' => $loan_id]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get overdue loans
     */
    public function getOverdueLoans($user_id = null) {
        $query = "SELECT l.*, u.full_name, u.email, u.phone 
                 FROM " . $this->loans_table . " l
                 JOIN users u ON l.user_id = u.user_id
                 WHERE l.status IN ('active', 'overdue') 
                 AND l.due_date < CURDATE()
                 AND l.remaining_balance > 0";
        
        if ($user_id) {
            $query .= " AND l.user_id = :user_id";
        }
        
        $query .= " ORDER BY l.due_date ASC";
        
        $stmt = $this->conn->prepare($query);
        
        if ($user_id) {
            $stmt->execute([':user_id' => $user_id]);
        } else {
            $stmt->execute();
        }
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Mark loans as overdue
     */
    public function markOverdueLoans() {
        $query = "UPDATE " . $this->loans_table . " 
                 SET status = 'overdue' 
                 WHERE status = 'active' 
                 AND due_date < CURDATE() 
                 AND remaining_balance > 0";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute();
    }
    
    /**
     * Calculate late payment penalty
     */
    public function calculateLatePenalty($loan_id) {
        $loan_query = "SELECT due_date, remaining_balance FROM " . $this->loans_table . " WHERE loan_id = :loan_id";
        $loan_stmt = $this->conn->prepare($loan_query);
        $loan_stmt->execute([':loan_id' => $loan_id]);
        $loan = $loan_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$loan || !$loan['due_date']) {
            return 0;
        }
        
        $today = new DateTime();
        $due_date = new DateTime($loan['due_date']);
        
        if ($today <= $due_date) {
            return 0; // Not overdue
        }
        
        $days_overdue = $today->diff($due_date)->days;
        $penalty_rate = 2.0; // 2% per day (can be made configurable)
        
        // Calculate penalty (max 20% of remaining balance)
        $penalty = min(
            ($loan['remaining_balance'] * $penalty_rate * $days_overdue) / 100,
            $loan['remaining_balance'] * 0.2
        );
        
        return round($penalty, 2);
    }
    
    /**
     * Process payment through gateway (mock implementation)
     */
    public function processPaymentGateway($payment_method, $amount, $phone_number = null) {
        // This is a mock implementation
        // In production, integrate with actual payment gateways
        
        switch ($payment_method) {
            case 'airtel_money':
                return $this->processAirtelMoney($amount, $phone_number);
            
            case 'tnm_mpamba':
                return $this->processTNMMpamba($amount, $phone_number);
            
            case 'sticpay':
                return $this->processSticpay($amount);
            
            case 'mastercard':
            case 'visa':
                return $this->processCardPayment($amount);
            
            case 'binance':
                return $this->processBinance($amount);
            
            default:
                return ['success' => false, 'message' => 'Invalid payment method'];
        }
    }
    
    /**
     * Mock payment gateway methods
     * In production, these should integrate with actual APIs
     */
    private function processAirtelMoney($amount, $phone) {
        // Mock Airtel Money API integration
        // In production: call Airtel Money API
        return [
            'success' => true,
            'transaction_id' => 'AM-' . time() . rand(1000, 9999),
            'message' => 'Payment initiated. Please complete on your phone.'
        ];
    }
    
    private function processTNMMpamba($amount, $phone) {
        // Mock TNM Mpamba API integration
        return [
            'success' => true,
            'transaction_id' => 'MP-' . time() . rand(1000, 9999),
            'message' => 'Payment initiated. Please complete on your phone.'
        ];
    }
    
    private function processSticpay($amount) {
        // Mock Sticpay API integration
        return [
            'success' => true,
            'transaction_id' => 'SP-' . time() . rand(1000, 9999),
            'message' => 'Redirecting to Sticpay...'
        ];
    }
    
    private function processCardPayment($amount) {
        // Mock Card payment API integration
        return [
            'success' => true,
            'transaction_id' => 'CD-' . time() . rand(1000, 9999),
            'message' => 'Card payment processed successfully'
        ];
    }
    
    private function processBinance($amount) {
        // Mock Binance API integration
        return [
            'success' => true,
            'transaction_id' => 'BN-' . time() . rand(1000, 9999),
            'message' => 'Crypto payment initiated'
        ];
    }
    
    /**
     * Update user credit score
     */
    private function updateUserCreditScore($user_id, $change) {
        $query = "UPDATE users 
                 SET credit_score = GREATEST(300, LEAST(850, credit_score + :change))
                 WHERE user_id = :user_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            ':change' => $change,
            ':user_id' => $user_id
        ]);
    }
    
    /**
     * Log credit event
     */
    private function logCreditEvent($user_id, $event_type, $loan_id, $description) {
        $query = "INSERT INTO credit_history 
                 (user_id, event_type, loan_id, description) 
                 VALUES (:user_id, :event_type, :loan_id, :description)";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            ':user_id' => $user_id,
            ':event_type' => $event_type,
            ':loan_id' => $loan_id,
            ':description' => $description
        ]);
    }
    
    /**
     * Create notification
     */
    private function createNotification($user_id, $type, $title, $message, $loan_id = null) {
        $query = "INSERT INTO notifications 
                 (user_id, notification_type, title, message, related_loan_id) 
                 VALUES (:user_id, :type, :title, :message, :loan_id)";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            ':user_id' => $user_id,
            ':type' => $type,
            ':title' => $title,
            ':message' => $message,
            ':loan_id' => $loan_id
        ]);
    }
}
?>