<?php
/**
 * User Class
 * Handles all user-related operations
 */

class User {
    private $conn;
    private $table_name = "users";
    
    // User properties
    public $user_id;
    public $national_id;
    public $full_name;
    public $email;
    public $phone;
    public $role;
    public $verification_status;
    public $account_status;
    public $credit_score;
    public $selfie_path;
    public $id_document_path;
    public $created_at;
    public $last_login;
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    /**
     * Get user by ID
     */
    public function getUserById($user_id) {
        $query = "SELECT * FROM " . $this->table_name . " WHERE user_id = :user_id LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            // ensure optional document fields exist to prevent undefined index notices
            if (!array_key_exists('selfie_path', $row)) {
                $row['selfie_path'] = null;
            }
            if (!array_key_exists('id_document_path', $row)) {
                $row['id_document_path'] = null;
            }

            $this->mapProperties($row);
            return $row;
        }
        return null;
    }
    
    /**
     * Get user by email
     */
    public function getUserByEmail($email) {
        $query = "SELECT * FROM " . $this->table_name . " WHERE email = :email LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':email', $email);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            return $stmt->fetch(PDO::FETCH_ASSOC);
        }
        return null;
    }
    
    /**
     * Get user by national ID
     */
    public function getUserByNationalId($national_id) {
        $query = "SELECT * FROM " . $this->table_name . " WHERE national_id = :national_id LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':national_id', $national_id);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            return $stmt->fetch(PDO::FETCH_ASSOC);
        }
        return null;
    }
    
    /**
     * Get all users with optional filters
     */
    public function getAllUsers($filters = []) {
        $query = "SELECT user_id, national_id, full_name, email, phone, role, 
                         verification_status, account_status, credit_score, 
                         created_at, last_login 
                  FROM " . $this->table_name . " WHERE 1=1";
        
        $params = [];
        
        if (isset($filters['role'])) {
            $query .= " AND role = :role";
            $params[':role'] = $filters['role'];
        }
        
        if (isset($filters['verification_status'])) {
            $query .= " AND verification_status = :verification_status";
            $params[':verification_status'] = $filters['verification_status'];
        }
        
        if (isset($filters['account_status'])) {
            $query .= " AND account_status = :account_status";
            $params[':account_status'] = $filters['account_status'];
        }
        
        if (isset($filters['search'])) {
            $query .= " AND (full_name LIKE :search OR email LIKE :search OR national_id LIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }
        
        $query .= " ORDER BY created_at DESC";
        
        if (isset($filters['limit'])) {
            $query .= " LIMIT :limit";
        }
        
        $stmt = $this->conn->prepare($query);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        if (isset($filters['limit'])) {
            $stmt->bindValue(':limit', (int)$filters['limit'], PDO::PARAM_INT);
        }
        
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Update user profile
     */
    public function updateProfile($user_id, $data) {
        $allowed_fields = ['full_name', 'email', 'phone'];
        $update_fields = [];
        $params = [':user_id' => $user_id];
        
        foreach ($allowed_fields as $field) {
            if (isset($data[$field])) {
                $update_fields[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }
        
        if (empty($update_fields)) {
            return false;
        }
        
        $query = "UPDATE " . $this->table_name . " 
                  SET " . implode(', ', $update_fields) . " 
                  WHERE user_id = :user_id";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute($params);
    }
    
    /**
     * Update user password
     */
    public function updatePassword($user_id, $new_password) {
        $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
        
        $query = "UPDATE " . $this->table_name . " 
                  SET password_hash = :password_hash 
                  WHERE user_id = :user_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':password_hash', $password_hash);
        $stmt->bindParam(':user_id', $user_id);
        
        return $stmt->execute();
    }
    
    /**
     * Update verification status
     */
    public function updateVerificationStatus($user_id, $status) {
        $query = "UPDATE " . $this->table_name . " 
                  SET verification_status = :status 
                  WHERE user_id = :user_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':user_id', $user_id);
        
        return $stmt->execute();
    }
    
    /**
     * Update account status
     */
    public function updateAccountStatus($user_id, $status) {
        $query = "UPDATE " . $this->table_name . " 
                  SET account_status = :status 
                  WHERE user_id = :user_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':user_id', $user_id);
        
        return $stmt->execute();
    }
    
    /**
     * Update credit score
     */
    public function updateCreditScore($user_id, $new_score, $reason = '') {
        // Get old score first
        $user = $this->getUserById($user_id);
        $old_score = $user['credit_score'];
        
        // Update score
        $query = "UPDATE " . $this->table_name . " 
                  SET credit_score = :new_score 
                  WHERE user_id = :user_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':new_score', $new_score);
        $stmt->bindParam(':user_id', $user_id);
        
        if ($stmt->execute()) {
            // Log credit history
            $history_query = "INSERT INTO credit_history 
                            (user_id, event_type, old_score, new_score, score_change, description) 
                            VALUES (:user_id, 'score_adjusted', :old_score, :new_score, :change, :description)";
            
            $history_stmt = $this->conn->prepare($history_query);
            $score_change = $new_score - $old_score;
            
            $history_stmt->execute([
                ':user_id' => $user_id,
                ':old_score' => $old_score,
                ':new_score' => $new_score,
                ':change' => $score_change,
                ':description' => $reason
            ]);
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Get user statistics
     */
    public function getUserStats($user_id) {
        $stats = [];
        
        // Total loans (Malawi: principal_mwk, status completed = repaid)
        $query = "SELECT COUNT(*) as total_loans, 
                         SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_loans,
                         SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as repaid_loans,
                         SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) as overdue_loans
                  FROM loans WHERE user_id = :user_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        $stats['loans'] = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Outstanding balance (Malawi: outstanding_balance_mwk)
        $query = "SELECT SUM(outstanding_balance_mwk) as total_outstanding 
                  FROM loans 
                  WHERE user_id = :user_id AND status IN ('active', 'overdue')";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['outstanding_balance'] = $result['total_outstanding'] ?? 0;
        
        // Total amount borrowed (Malawi: principal_mwk)
        $query = "SELECT SUM(principal_mwk) as total_borrowed 
                  FROM loans 
                  WHERE user_id = :user_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['total_borrowed'] = $result['total_borrowed'] ?? 0;
        
        // Next due payment
        $query = "SELECT rs.due_date as next_due_date, rs.amount_due 
                  FROM repayment_schedule rs
                  JOIN loans l ON rs.loan_id = l.loan_id
                  WHERE l.user_id = :user_id AND rs.status IN ('pending', 'overdue')
                  ORDER BY rs.due_date ASC
                  LIMIT 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        $stats['next_payment'] = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $stats;
    }
    
    /**
     * Check if user is eligible for loan
     */
    public function checkLoanEligibility($user_id, $requested_amount) {
        $user = $this->getUserById($user_id);
        
        if (!$user) {
            return ['eligible' => false, 'reason' => 'User not found'];
        }
        
        // Check verification (Malawi: is_verified 0/1; legacy: verification_status)
        $verified = isset($user['is_verified']) ? (int)$user['is_verified'] : (($user['verification_status'] ?? '') === 'verified' ? 1 : 0);
        if (!$verified) {
            return ['eligible' => false, 'reason' => 'Account not verified'];
        }
        
        // Check account status
        if ($user['account_status'] !== 'active') {
            return ['eligible' => false, 'reason' => 'Account is not active'];
        }
        
        // Check credit score
        if ($user['credit_score'] < 300) {
            return ['eligible' => false, 'reason' => 'Credit score too low'];
        }
        
        // Check for overdue loans (Malawi schema)
        $query = "SELECT COUNT(*) as overdue_count 
                  FROM loans 
                  WHERE user_id = :user_id AND status = 'overdue'";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result['overdue_count'] > 0) {
            return ['eligible' => false, 'reason' => 'You have overdue loans'];
        }
        
        // Check maximum active loans (Malawi: active only)
        $query = "SELECT COUNT(*) as active_count 
                  FROM loans 
                  WHERE user_id = :user_id AND status = 'active'";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result['active_count'] >= 3) {
            return ['eligible' => false, 'reason' => 'Maximum active loans reached'];
        }
        
        // Check requested amount
        if ($requested_amount < MIN_LOAN_AMOUNT || $requested_amount > MAX_LOAN_AMOUNT) {
            return ['eligible' => false, 'reason' => 'Loan amount out of range'];
        }
        
        return ['eligible' => true, 'reason' => 'Eligible'];
    }
    
    /**
     * Map database row to object properties
     */
    private function mapProperties($row) {
        $this->user_id = $row['user_id'];
        $this->national_id = $row['national_id'];
        $this->full_name = $row['full_name'];
        $this->email = $row['email'];
        $this->phone = $row['phone'];
        $this->role = $row['role'];
        $this->verification_status = $row['verification_status'];
        $this->account_status = $row['account_status'];
        $this->credit_score = $row['credit_score'];
        // Some installations/schema versions may not include the selfie/id columns.
        // Use null-coalescing to avoid notices if the fields are absent.
        $this->selfie_path = $row['selfie_path'] ?? null;
        $this->id_document_path = $row['id_document_path'] ?? null;
        $this->created_at = $row['created_at'];
        $this->last_login = $row['last_login'];
    }
}
?>