<?php
/**
 * Loan Class
 * Handles loan applications (FIFO), disbursed loans, and scoring.
 * Uses Malawi schema: loan_applications -> loans (on approve/disburse).
 */

class Loan {
    private $conn;
    private $table_loans = "loans";
    private $table_applications = "loan_applications";
    private $table_products = "loan_products";

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
     * Generate unique application reference (e.g. MLN-20240221-00001)
     */
    public function generateApplicationRef() {
        $prefix = 'MLN-' . date('Ymd') . '-';
        $stmt = $this->conn->prepare(
            "SELECT application_ref FROM loan_applications WHERE application_ref LIKE :prefix ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([':prefix' => $prefix . '%']);
        $last = $stmt->fetch(PDO::FETCH_ASSOC);
        $seq = 1;
        if ($last && preg_match('/-(\d+)$/', $last['application_ref'], $m)) {
            $seq = (int)$m[1] + 1;
        }
        return $prefix . str_pad($seq, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Get active loan products
     */
    public function getLoanProducts($activeOnly = true) {
        $sql = "SELECT * FROM " . $this->table_products;
        if ($activeOnly) {
            $sql .= " WHERE is_active = 1";
        }
        $sql .= " ORDER BY product_name";
        $stmt = $this->conn->query($sql);
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    /**
     * Get default loan product (e.g. Emergency Loan) for quick apply
     */
    public function getDefaultLoanProductId() {
        $stmt = $this->conn->query(
            "SELECT id FROM loan_products WHERE is_active = 1 AND product_name = 'Emergency Loan' LIMIT 1"
        );
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        if ($row) return (int)$row['id'];
        $stmt = $this->conn->query("SELECT id FROM loan_products WHERE is_active = 1 ORDER BY id ASC LIMIT 1");
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        return $row ? (int)$row['id'] : 1;
    }

    /**
     * Create a new loan application (Malawi schema — FIFO queue)
     */
    public function createLoan($data) {
        try {
            $user_id = (int)$data['user_id'];
            $requested_amount = (float)($data['loan_amount'] ?? 0);
            $loan_purpose = $data['loan_purpose'] ?? 'Personal';
            $loan_product_id = isset($data['loan_product_id']) ? (int)$data['loan_product_id'] : $this->getDefaultLoanProductId();
            $term_months = isset($data['term_months']) ? (int)$data['term_months'] : (int)(defined('DEFAULT_LOAN_TERM_MONTHS') ? DEFAULT_LOAN_TERM_MONTHS : 3);

            $product = $this->getProductById($loan_product_id);
            if (!$product) {
                error_log("Create loan: invalid product id " . $loan_product_id);
                return false;
            }
            $interest_rate = (float)$product['base_interest_rate'];
            $requested_amount = max($product['min_amount_mwk'], min($product['max_amount_mwk'], $requested_amount));
            $term_months = max($product['min_term_months'], min($product['max_term_months'], $term_months));

            $application_ref = $this->generateApplicationRef();

            $query = "INSERT INTO " . $this->table_applications . "
                      (user_id, loan_product_id, application_ref, requested_amount_mwk, loan_purpose, term_months, interest_rate, status)
                      VALUES (:user_id, :loan_product_id, :application_ref, :requested_amount_mwk, :loan_purpose, :term_months, :interest_rate, 'pending')";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                ':user_id' => $user_id,
                ':loan_product_id' => $loan_product_id,
                ':application_ref' => $application_ref,
                ':requested_amount_mwk' => $requested_amount,
                ':loan_purpose' => $loan_purpose,
                ':term_months' => $term_months,
                ':interest_rate' => $interest_rate,
            ]);
            $application_id = (int)$this->conn->lastInsertId();

            $this->logCreditEvent($user_id, 'loan_applied', null, $application_id, 'Applied for loan of ' . format_currency($requested_amount));
            return $application_id;
        } catch (PDOException $e) {
            error_log("Create loan error: " . $e->getMessage());
            return false;
        }
    }

    private function getProductById($id) {
        $stmt = $this->conn->prepare("SELECT * FROM loan_products WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Get next pending application in FIFO order (oldest first)
     */
    public function getNextPendingApplicationFifo() {
        $query = "SELECT la.*, u.full_name, u.email, u.phone, u.credit_score, lp.product_name, lp.base_interest_rate
                  FROM " . $this->table_applications . " la
                  JOIN users u ON la.user_id = u.user_id
                  JOIN loan_products lp ON la.loan_product_id = lp.id
                  WHERE la.status IN ('pending', 'under_review')
                  ORDER BY la.applied_at ASC
                  LIMIT 1";
        $stmt = $this->conn->query($query);
        return $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
    }

    /**
     * Get all pending/under_review applications in FIFO order (for admin)
     */
    public function getPendingApplicationsFifo($limit = 100) {
        $query = "SELECT la.*, u.full_name, u.email, u.phone, u.credit_score, lp.product_name
                  FROM " . $this->table_applications . " la
                  JOIN users u ON la.user_id = u.user_id
                  JOIN loan_products lp ON la.loan_product_id = lp.id
                  WHERE la.status IN ('pending', 'under_review')
                  ORDER BY la.applied_at ASC
                  LIMIT " . (int)$limit;
        $stmt = $this->conn->query($query);
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    /**
     * Get application by id
     */
    public function getApplicationById($application_id) {
        $query = "SELECT la.*, u.full_name, u.email, u.phone, u.credit_score, lp.product_name, lp.base_interest_rate,
                         lp.min_amount_mwk, lp.max_amount_mwk, lp.min_term_months, lp.max_term_months
                  FROM " . $this->table_applications . " la
                  JOIN users u ON la.user_id = u.user_id
                  JOIN loan_products lp ON la.loan_product_id = lp.id
                  WHERE la.id = :id LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':id' => $application_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Process loan application (FIFO: process by application id). Approve -> create loan + schedule; Reject -> update status.
     */
    public function processLoanApplication($application_id, $approved_by = null) {
        try {
            $app = $this->getApplicationById($application_id);
            if (!$app) {
                return ['success' => false, 'message' => 'Application not found'];
            }
            if (!in_array($app['status'], ['pending', 'under_review'])) {
                return ['success' => false, 'message' => 'Application already processed'];
            }

            $user_id = (int)$app['user_id'];
            $requested_amount = (float)$app['requested_amount_mwk'];
            $term_months = (int)$app['term_months'];
            $interest_rate = (float)($app['interest_rate'] ?? $app['base_interest_rate']);

            $score = $this->calculateLoanScore($user_id, $requested_amount);
            $approval_threshold = 60;

            if ($score >= $approval_threshold) {
                $approved_amount = $requested_amount;
                $total_repayable = $this->calculateTotalRepayable($approved_amount, $interest_rate, $term_months);
                $monthly_payment = round($total_repayable / $term_months, 2);
                $due_date = date('Y-m-d', strtotime("+{$term_months} months"));

                $this->conn->beginTransaction();
                try {
                    $ins = "INSERT INTO " . $this->table_loans . "
                            (application_id, user_id, principal_mwk, interest_rate, term_months, monthly_payment_mwk, total_repayable_mwk, outstanding_balance_mwk, disbursed_at, due_date, status)
                            VALUES (:application_id, :user_id, :principal_mwk, :interest_rate, :term_months, :monthly_payment_mwk, :total_repayable_mwk, :outstanding_balance_mwk, NOW(), :due_date, 'active')";
                    $stmt = $this->conn->prepare($ins);
                    $stmt->execute([
                        ':application_id' => $application_id,
                        ':user_id' => $user_id,
                        ':principal_mwk' => $approved_amount,
                        ':interest_rate' => $interest_rate,
                        ':term_months' => $term_months,
                        ':monthly_payment_mwk' => $monthly_payment,
                        ':total_repayable_mwk' => $total_repayable,
                        ':outstanding_balance_mwk' => $total_repayable,
                        ':due_date' => $due_date,
                    ]);
                    $loan_id = (int)$this->conn->lastInsertId();

                    $this->createRepaymentScheduleMalawi($loan_id, $total_repayable, $term_months, $due_date);

                    $upd = "UPDATE " . $this->table_applications . " SET status = 'approved', approved_amount_mwk = :amt, reviewed_by = :reviewed_by, reviewed_at = NOW() WHERE id = :id";
                    $this->conn->prepare($upd)->execute([
                        ':amt' => $approved_amount,
                        ':reviewed_by' => $approved_by,
                        ':id' => $application_id,
                    ]);
                    $upd2 = "UPDATE " . $this->table_applications . " SET status = 'disbursed' WHERE id = :id";
                    $this->conn->prepare($upd2)->execute([':id' => $application_id]);

                    $this->updateUserCreditScore($user_id, 10);
                    $this->logCreditEvent($user_id, 'loan_approved', $loan_id, $application_id, 'Loan approved with score: ' . $score);
                    $this->createNotification($user_id, 'approval', 'Loan Approved! 🎉',
                        'Your loan application of ' . format_currency($approved_amount) . ' has been approved and disbursed.',
                        $loan_id);

                    $this->conn->commit();
                    return [
                        'success' => true,
                        'status' => 'approved',
                        'score' => $score,
                        'message' => 'Loan approved and disbursed.',
                        'loan_id' => $loan_id,
                        'disbursement_date' => date('Y-m-d'),
                        'due_date' => $due_date,
                    ];
                } catch (Exception $e) {
                    $this->conn->rollBack();
                    throw $e;
                }
            } else {
                $rejection_reason = "Application did not meet minimum approval score (Score: $score/$approval_threshold).";
                $upd = "UPDATE " . $this->table_applications . " SET status = 'rejected', rejection_reason = :reason, reviewed_by = :reviewed_by, reviewed_at = NOW() WHERE id = :id";
                $stmt = $this->conn->prepare($upd);
                $stmt->execute([
                    ':reason' => $rejection_reason,
                    ':reviewed_by' => $approved_by,
                    ':id' => $application_id,
                ]);
                $this->updateUserCreditScore($user_id, -5);
                $this->logCreditEvent($user_id, 'loan_rejected', null, $application_id, 'Loan rejected with score: ' . $score);
                $this->createNotification($user_id, 'rejection', 'Loan Application Update', $rejection_reason, null, $application_id);
                return [
                    'success' => true,
                    'status' => 'rejected',
                    'score' => $score,
                    'message' => $rejection_reason,
                ];
            }
        } catch (PDOException $e) {
            error_log("Process loan error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Application processing failed'];
        }
    }

    /**
     * Admin force-reject an application (no scoring)
     */
    public function forceRejectApplication($application_id, $rejection_reason, $reviewed_by = null) {
        $app = $this->getApplicationById($application_id);
        if (!$app || !in_array($app['status'], ['pending', 'under_review'])) {
            return ['success' => false, 'message' => 'Application not found or already processed'];
        }
        $stmt = $this->conn->prepare("UPDATE loan_applications SET status = 'rejected', rejection_reason = :reason, reviewed_by = :rb, reviewed_at = NOW() WHERE id = :id");
        $stmt->execute([
            ':reason' => $rejection_reason,
            ':rb' => $reviewed_by,
            ':id' => $application_id,
        ]);
        $this->createNotification($app['user_id'], 'rejection', 'Loan Application Update', $rejection_reason, null, $application_id);
        return ['success' => true, 'message' => 'Application rejected'];
    }

    /**
     * Simple total repayable: principal + (principal * rate/100 * term_months/12)
     */
    private function calculateTotalRepayable($principal, $annual_rate, $term_months) {
        $interest = $principal * ($annual_rate / 100) * ($term_months / 12);
        return round($principal + $interest, 2);
    }

    private function createRepaymentScheduleMalawi($loan_id, $total_amount, $term_months, $final_due_date) {
        $installment = round($total_amount / $term_months, 2);
        $base = new DateTime($final_due_date);
        for ($i = 1; $i <= $term_months; $i++) {
            $due = clone $base;
            $due->modify('-' . ($term_months - $i) . ' months');
            $dueStr = $due->format('Y-m-d');
            $amt = ($i === (int)$term_months) ? ($total_amount - $installment * ($term_months - 1)) : $installment;
            $stmt = $this->conn->prepare(
                "INSERT INTO repayment_schedule (loan_id, installment_number, due_date, amount_due) VALUES (:loan_id, :num, :due_date, :amount_due)"
            );
            $stmt->execute([
                ':loan_id' => $loan_id,
                ':num' => $i,
                ':due_date' => $dueStr,
                ':amount_due' => $amt,
            ]);
        }
    }

    /**
     * Automated loan scoring (0–100)
     */
    public function calculateLoanScore($user_id, $requested_amount) {
        $score = 0;
        $user_query = "SELECT credit_score, is_verified, created_at FROM users WHERE user_id = :user_id";
        $user_stmt = $this->conn->prepare($user_query);
        $user_stmt->execute([':user_id' => $user_id]);
        $user = $user_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) return 0;

        $credit_score = (int)($user['credit_score'] ?? 300);
        if ($credit_score >= 700) $score += 40;
        elseif ($credit_score >= 600) $score += 30;
        elseif ($credit_score >= 500) $score += 20;
        elseif ($credit_score >= 400) $score += 10;
        else $score += 5;

        $history_query = "SELECT COUNT(*) as total_loans,
                          SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                          SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) as overdue,
                          SUM(CASE WHEN status = 'defaulted' THEN 1 ELSE 0 END) as defaulted
                          FROM loans WHERE user_id = :user_id";
        $history_stmt = $this->conn->prepare($history_query);
        $history_stmt->execute([':user_id' => $user_id]);
        $history = $history_stmt->fetch(PDO::FETCH_ASSOC);
        if ($history['total_loans'] > 0) {
            $repayment_rate = ($history['completed'] / $history['total_loans']) * 100;
            if ($repayment_rate >= 90) $score += 25;
            elseif ($repayment_rate >= 70) $score += 18;
            elseif ($repayment_rate >= 50) $score += 10;
            else $score += 5;
            if ($history['overdue'] > 0) $score -= 5;
            if ($history['defaulted'] > 0) $score -= 10;
        } else {
            $score += 15;
        }

        $payment_query = "SELECT rs.due_date, rs.paid_at
                          FROM repayment_schedule rs
                          JOIN loans l ON rs.loan_id = l.loan_id
                          WHERE l.user_id = :user_id AND rs.status = 'paid'";
        $payment_stmt = $this->conn->prepare($payment_query);
        $payment_stmt->execute([':user_id' => $user_id]);
        $payments = $payment_stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($payments) > 0) {
            $on_time = 0;
            foreach ($payments as $p) {
                if (!empty($p['paid_at']) && !empty($p['due_date']) && strtotime($p['paid_at']) <= strtotime($p['due_date'])) $on_time++;
            }
            $rate = ($on_time / count($payments)) * 100;
            if ($rate >= 95) $score += 20;
            elseif ($rate >= 80) $score += 15;
            elseif ($rate >= 60) $score += 10;
            else $score += 5;
        } else {
            $score += 10;
        }

        $account_age_days = (time() - strtotime($user['created_at'])) / 86400;
        if ($account_age_days >= 180) $score += 10;
        elseif ($account_age_days >= 90) $score += 7;
        elseif ($account_age_days >= 30) $score += 5;
        else $score += 2;

        $max_amount = defined('MAX_LOAN_AMOUNT') ? MAX_LOAN_AMOUNT : 500000;
        $ratio = $requested_amount / $max_amount;
        if ($ratio <= 0.3) $score += 5;
        elseif ($ratio <= 0.6) $score += 3;
        else $score += 1;

        return (int)max(0, min(100, round($score)));
    }

    /**
     * Get disbursed loan by loan_id (with legacy aliases for UI)
     */
    public function getLoanById($loan_id) {
        $query = "SELECT l.loan_id, l.application_id, l.user_id,
                         l.principal_mwk AS loan_amount,
                         l.interest_rate,
                         l.term_months AS loan_term_months,
                         l.monthly_payment_mwk,
                         l.total_repayable_mwk AS total_amount,
                         l.outstanding_balance_mwk AS remaining_balance,
                         l.disbursed_at AS disbursement_date,
                         l.due_date,
                         l.status,
                         l.created_at,
                         u.full_name, u.email, u.phone
                  FROM " . $this->table_loans . " l
                  JOIN users u ON l.user_id = u.user_id
                  WHERE l.loan_id = :loan_id LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':loan_id' => $loan_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Get all disbursed loans for a user (with legacy column names)
     */
    public function getUserLoans($user_id, $status = null) {
        $query = "SELECT l.loan_id, l.application_id, l.user_id,
                         l.principal_mwk AS loan_amount,
                         l.interest_rate,
                         l.term_months AS loan_term_months,
                         l.total_repayable_mwk AS total_amount,
                         l.outstanding_balance_mwk AS remaining_balance,
                         l.disbursed_at AS disbursement_date,
                         l.due_date,
                         l.status,
                         l.created_at
                  FROM " . $this->table_loans . " l
                  WHERE l.user_id = :user_id";
        if ($status) {
            $query .= " AND l.status = :status";
        }
        $query .= " ORDER BY l.created_at DESC";
        $stmt = $this->conn->prepare($query);
        $params = [':user_id' => $user_id];
        if ($status) $params[':status'] = $status;
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get loan applications for a user (pending, under_review, approved, rejected, disbursed, cancelled)
     */
    public function getUserApplications($user_id, $status = null) {
        $query = "SELECT la.*, lp.product_name
                  FROM " . $this->table_applications . " la
                  JOIN loan_products lp ON la.loan_product_id = lp.id
                  WHERE la.user_id = :user_id";
        if ($status) {
            $query .= " AND la.status = :status";
        }
        $query .= " ORDER BY la.applied_at DESC";
        $stmt = $this->conn->prepare($query);
        $params = [':user_id' => $user_id];
        if ($status) $params[':status'] = $status;
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function updateUserCreditScore($user_id, $change) {
        $stmt = $this->conn->prepare("UPDATE users SET credit_score = GREATEST(300, LEAST(850, credit_score + :ch)) WHERE user_id = :uid");
        $stmt->execute([':ch' => $change, ':uid' => $user_id]);
    }

    private function logCreditEvent($user_id, $event_type, $loan_id, $application_id, $description) {
        $stmt = $this->conn->prepare(
            "INSERT INTO credit_history (user_id, event_type, loan_id, application_id, description) VALUES (:uid, :et, :lid, :aid, :desc)"
        );
        $stmt->execute([
            ':uid' => $user_id,
            ':et' => $event_type,
            ':lid' => $loan_id,
            ':aid' => $application_id,
            ':desc' => $description,
        ]);
    }

    private function createNotification($user_id, $type, $title, $message, $loan_id = null, $application_id = null) {
        $stmt = $this->conn->prepare(
            "INSERT INTO notifications (user_id, notification_type, title, message, related_loan_id, related_application_id) VALUES (:uid, :type, :title, :msg, :lid, :aid)"
        );
        $stmt->execute([
            ':uid' => $user_id,
            ':type' => $type,
            ':title' => $title,
            ':msg' => $message,
            ':lid' => $loan_id,
            ':aid' => $application_id,
        ]);
    }
}
?>
