<?php
/**
 * Loan Class
 * Handles all loan-related operations
 */

class Loan {
    private $conn;
    private $table_name = "loans";
    
    // Loan properties
    public $loan_id;
    public $user_id;
    public $loan_amount;
    public $interest_rate;
    public $loan_term_days;
    public $loan_purpose;
    public $disbursement_date;
    public $due_date;
    public $remaining_balance;
    public $total_amount;
    public $status;
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    /**
     * Create a new loan application
     */
    public function createLoan($data) {
        try {
            // Calculate loan details
            $interest_rate = $data['interest_rate'] ?? DEFAULT_INTEREST_RATE;
            $loan_term = $data['loan_term_days'] ?? DEFAULT_LOAN_TERM;
            $total_amount = calculate_loan_total($data['loan_amount'], $interest_rate, $loan_term);
            
            $query = "INSERT INTO " . $this->table_name . " 
                     (user_id, loan_amount, interest_rate, loan_term_days, loan_purpose, 
                      total_amount, remaining_balance, status) 
                     VALUES (:user_id, :loan_amount, :interest_rate, :loan_term_days, 
                             :loan_purpose, :total_amount, :remaining_balance, 'pending')";
            
            $stmt = $this->conn->prepare($query);
            
            $stmt->execute([
                ':user_id' => $data['user_id'],
                ':loan_amount' => $data['loan_amount'],
                ':interest_rate' => $interest_rate,
                ':loan_term_days' => $loan_term,
                ':loan_purpose' => $data['loan_purpose'] ?? null,
                ':total_amount' => $total_amount,
                ':remaining_balance' => $total_amount
            ]);
            
            $loan_id = $this->conn->lastInsertId();
            
            // Log credit history
            $this->logCreditEvent($data['user_id'], 'loan_applied', $loan_id, 
                                  'Applied for loan of ' . format_currency($data['loan_amount']));
            
            return $loan_id;
            
        } catch(PDOException $e) {
            error_log("Create loan error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Automated loan scoring algorithm
     * Returns score between 0-100
     */
    public function calculateLoanScore($user_id, $requested_amount) {
        $score = 0;
        
        // Get user data
        $user_query = "SELECT credit_score, verification_status, created_at FROM users WHERE user_id = :user_id";
        $user_stmt = $this->conn->prepare($user_query);
        $user_stmt->execute([':user_id' => $user_id]);
        $user = $user_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            return 0;
        }
        
        // 1. Credit Score (40 points max)
        $credit_score = $user['credit_score'];
        if ($credit_score >= 700) {
            $score += 40;
        } elseif ($credit_score >= 600) {
            $score += 30;
        } elseif ($credit_score >= 500) {
            $score += 20;
        } elseif ($credit_score >= 400) {
            $score += 10;
        } else {
            $score += 5;
        }
        
        // 2. Loan History (25 points max)
        $history_query = "SELECT 
                            COUNT(*) as total_loans,
                            SUM(CASE WHEN status = 'repaid' THEN 1 ELSE 0 END) as repaid_loans,
                            SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) as overdue_loans,
                            SUM(CASE WHEN status = 'defaulted' THEN 1 ELSE 0 END) as defaulted_loans
                          FROM loans WHERE user_id = :user_id";
        
        $history_stmt = $this->conn->prepare($history_query);
        $history_stmt->execute([':user_id' => $user_id]);
        $history = $history_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($history['total_loans'] > 0) {
            $repayment_rate = ($history['repaid_loans'] / $history['total_loans']) * 100;
            
            if ($repayment_rate >= 90) {
                $score += 25;
            } elseif ($repayment_rate >= 70) {
                $score += 18;
            } elseif ($repayment_rate >= 50) {
                $score += 10;
            } else {
                $score += 5;
            }
            
            // Penalty for overdue/defaulted loans
            if ($history['overdue_loans'] > 0) {
                $score -= 5;
            }
            if ($history['defaulted_loans'] > 0) {
                $score -= 10;
            }
        } else {
            // First-time borrower gets moderate score
            $score += 15;
        }
        
        // 3. Payment Timeliness (20 points max)
        $payment_query = "SELECT 
                            COUNT(*) as total_payments,
                            rs.due_date,
                            rs.paid_date
                          FROM repayment_schedule rs
                          JOIN loans l ON rs.loan_id = l.loan_id
                          WHERE l.user_id = :user_id AND rs.status = 'paid'";
        
        $payment_stmt = $this->conn->prepare($payment_query);
        $payment_stmt->execute([':user_id' => $user_id]);
        $payments = $payment_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($payments) > 0) {
            $on_time_count = 0;
            foreach ($payments as $payment) {
                if ($payment['paid_date'] && $payment['due_date']) {
                    if (strtotime($payment['paid_date']) <= strtotime($payment['due_date'])) {
                        $on_time_count++;
                    }
                }
            }
            
            $on_time_rate = ($on_time_count / count($payments)) * 100;
            
            if ($on_time_rate >= 95) {
                $score += 20;
            } elseif ($on_time_rate >= 80) {
                $score += 15;
            } elseif ($on_time_rate >= 60) {
                $score += 10;
            } else {
                $score += 5;
            }
        } else {
            $score += 10; // Default for no payment history
        }
        
        // 4. Account Age (10 points max)
        $account_age_days = (time() - strtotime($user['created_at'])) / (60 * 60 * 24);
        
        if ($account_age_days >= 180) {
            $score += 10;
        } elseif ($account_age_days >= 90) {
            $score += 7;
        } elseif ($account_age_days >= 30) {
            $score += 5;
        } else {
            $score += 2;
        }
        
        // 5. Loan Amount Risk (5 points max)
        // Lower amounts get higher scores (less risk)
        $amount_ratio = $requested_amount / MAX_LOAN_AMOUNT;
        
        if ($amount_ratio <= 0.3) {
            $score += 5;
        } elseif ($amount_ratio <= 0.6) {
            $score += 3;
        } else {
            $score += 1;
        }
        
        // Ensure score is between 0 and 100
        $score = max(0, min(100, $score));
        
        return round($score);
    }
    
    /**
     * Process loan approval based on score
     */
    public function processLoanApplication($loan_id, $approved_by = null) {
        try {
            // Get loan details
            $loan_query = "SELECT * FROM " . $this->table_name . " WHERE loan_id = :loan_id";
            $loan_stmt = $this->conn->prepare($loan_query);
            $loan_stmt->execute([':loan_id' => $loan_id]);
            $loan = $loan_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$loan) {
                return ['success' => false, 'message' => 'Loan not found'];
            }
            
            // Calculate loan score
            $score = $this->calculateLoanScore($loan['user_id'], $loan['loan_amount']);
            
            // Approval threshold: 60 points
            $approval_threshold = 60;
            
            if ($score >= $approval_threshold) {
                // Approve loan
                $disbursement_date = date('Y-m-d');
                $due_date = date('Y-m-d', strtotime("+{$loan['loan_term_days']} days"));
                
                $update_query = "UPDATE " . $this->table_name . " 
                               SET status = 'approved', 
                                   approval_date = NOW(), 
                                   disbursement_date = :disbursement_date,
                                   due_date = :due_date,
                                   approved_by = :approved_by
                               WHERE loan_id = :loan_id";
                
                $update_stmt = $this->conn->prepare($update_query);
                $update_stmt->execute([
                    ':disbursement_date' => $disbursement_date,
                    ':due_date' => $due_date,
                    ':approved_by' => $approved_by,
                    ':loan_id' => $loan_id
                ]);
                
                // Create repayment schedule
                $this->createRepaymentSchedule($loan_id, $loan['total_amount'], 
                                              $loan['loan_term_days'], $disbursement_date);
                
                // Update credit score (increase for approval)
                $this->updateUserCreditScore($loan['user_id'], 10);
                
                // Log credit history
                $this->logCreditEvent($loan['user_id'], 'loan_approved', $loan_id, 
                                     'Loan approved with score: ' . $score);
                
                // Create notification
                $this->createNotification($loan['user_id'], 'approval', 
                                        'Loan Approved! 🎉', 
                                        'Your loan application of ' . format_currency($loan['loan_amount']) . 
                                        ' has been approved. Funds will be disbursed shortly.',
                                        $loan_id);
                
                return [
                    'success' => true, 
                    'status' => 'approved', 
                    'score' => $score,
                    'message' => 'Congratulations! Your loan has been approved.',
                    'disbursement_date' => $disbursement_date,
                    'due_date' => $due_date
                ];
                
            } else {
                // Reject loan
                $rejection_reason = "Your application did not meet our minimum approval score (Score: $score/$approval_threshold). " .
                                  "Please build your credit history and try again.";
                
                $update_query = "UPDATE " . $this->table_name . " 
                               SET status = 'rejected', 
                                   rejection_reason = :rejection_reason
                               WHERE loan_id = :loan_id";
                
                $update_stmt = $this->conn->prepare($update_query);
                $update_stmt->execute([
                    ':rejection_reason' => $rejection_reason,
                    ':loan_id' => $loan_id
                ]);
                
                // Update credit score (small decrease for rejection)
                $this->updateUserCreditScore($loan['user_id'], -5);
                
                // Log credit history
                $this->logCreditEvent($loan['user_id'], 'loan_rejected', $loan_id, 
                                     'Loan rejected with score: ' . $score);
                
                // Create notification
                $this->createNotification($loan['user_id'], 'rejection', 
                                        'Loan Application Update', 
                                        $rejection_reason,
                                        $loan_id);
                
                return [
                    'success' => true, 
                    'status' => 'rejected', 
                    'score' => $score,
                    'message' => $rejection_reason
                ];
            }
            
        } catch(PDOException $e) {
            error_log("Process loan error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Application processing failed'];
        }
    }
    
    /**
     * Create repayment schedule
     */
    private function createRepaymentSchedule($loan_id, $total_amount, $loan_term_days, $start_date) {
        try {
            // For simplicity, create a single payment schedule
            // In production, you might want multiple installments
            
            $due_date = date('Y-m-d', strtotime($start_date . " +{$loan_term_days} days"));
            
            $query = "INSERT INTO repayment_schedule 
                     (loan_id, installment_number, due_date, amount_due) 
                     VALUES (:loan_id, 1, :due_date, :amount_due)";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                ':loan_id' => $loan_id,
                ':due_date' => $due_date,
                ':amount_due' => $total_amount
            ]);
            
            return true;
            
        } catch(PDOException $e) {
            error_log("Create schedule error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get loan by ID
     */
    public function getLoanById($loan_id) {
        $query = "SELECT l.*, u.full_name, u.email, u.phone 
                  FROM " . $this->table_name . " l
                  JOIN users u ON l.user_id = u.user_id
                  WHERE l.loan_id = :loan_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':loan_id' => $loan_id]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get all loans for a user
     */
    public function getUserLoans($user_id, $status = null) {
        $query = "SELECT * FROM " . $this->table_name . " WHERE user_id = :user_id";
        
        if ($status) {
            $query .= " AND status = :status";
        }
        
        $query .= " ORDER BY created_at DESC";
        
        $stmt = $this->conn->prepare($query);
        $params = [':user_id' => $user_id];
        
        if ($status) {
            $params[':status'] = $status;
        }
        
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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