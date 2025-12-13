-- Lemelani Loans Database Schema
-- MySQL Database Setup

CREATE DATABASE IF NOT EXISTS lemelani_loans;
USE lemelani_loans;

-- Users Table (All system users)
CREATE TABLE users (
    user_id INT PRIMARY KEY AUTO_INCREMENT,
    national_id VARCHAR(50) UNIQUE NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    phone VARCHAR(20) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('customer', 'admin', 'manager') DEFAULT 'customer',
    selfie_path VARCHAR(255),
    id_document_path VARCHAR(255),
    verification_status ENUM('pending', 'verified', 'rejected') DEFAULT 'pending',
    account_status ENUM('active', 'suspended', 'closed') DEFAULT 'active',
    credit_score INT DEFAULT 500,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    INDEX idx_national_id (national_id),
    INDEX idx_email (email),
    INDEX idx_role (role),
    INDEX idx_verification_status (verification_status)
);

-- Loans Table
CREATE TABLE loans (
    loan_id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    loan_amount DECIMAL(10, 2) NOT NULL,
    interest_rate DECIMAL(5, 2) DEFAULT 5.00,
    loan_term_days INT DEFAULT 30,
    loan_purpose VARCHAR(255),
    disbursement_date DATE,
    due_date DATE,
    remaining_balance DECIMAL(10, 2),
    total_amount DECIMAL(10, 2),
    status ENUM('pending', 'approved', 'rejected', 'disbursed', 'active', 'repaid', 'overdue', 'defaulted') DEFAULT 'pending',
    approval_date TIMESTAMP NULL,
    approved_by INT,
    rejection_reason TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_status (status),
    INDEX idx_due_date (due_date)
);

-- Repayments Table
CREATE TABLE repayments (
    repayment_id INT PRIMARY KEY AUTO_INCREMENT,
    loan_id INT NOT NULL,
    user_id INT NOT NULL,
    payment_amount DECIMAL(10, 2) NOT NULL,
    payment_method ENUM('airtel_money', 'tnm_mpamba', 'sticpay', 'mastercard', 'visa', 'binance') NOT NULL,
    transaction_reference VARCHAR(100),
    payment_status ENUM('pending', 'completed', 'failed', 'cancelled') DEFAULT 'pending',
    payment_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_partial BOOLEAN DEFAULT FALSE,
    notes TEXT,
    FOREIGN KEY (loan_id) REFERENCES loans(loan_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_loan_id (loan_id),
    INDEX idx_user_id (user_id),
    INDEX idx_payment_status (payment_status)
);

-- Repayment Schedule Table
CREATE TABLE repayment_schedule (
    schedule_id INT PRIMARY KEY AUTO_INCREMENT,
    loan_id INT NOT NULL,
    installment_number INT NOT NULL,
    due_date DATE NOT NULL,
    amount_due DECIMAL(10, 2) NOT NULL,
    amount_paid DECIMAL(10, 2) DEFAULT 0.00,
    status ENUM('pending', 'paid', 'overdue', 'partial') DEFAULT 'pending',
    paid_date TIMESTAMP NULL,
    penalty_amount DECIMAL(10, 2) DEFAULT 0.00,
    FOREIGN KEY (loan_id) REFERENCES loans(loan_id) ON DELETE CASCADE,
    INDEX idx_loan_id (loan_id),
    INDEX idx_due_date (due_date),
    INDEX idx_status (status)
);

-- Credit History Table
CREATE TABLE credit_history (
    history_id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    event_type ENUM('loan_applied', 'loan_approved', 'loan_rejected', 'payment_made', 'payment_missed', 'loan_repaid', 'score_adjusted') NOT NULL,
    loan_id INT,
    old_score INT,
    new_score INT,
    score_change INT,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (loan_id) REFERENCES loans(loan_id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_event_type (event_type)
);

-- Notifications Table
CREATE TABLE notifications (
    notification_id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    notification_type ENUM('reminder', 'approval', 'rejection', 'payment_received', 'overdue', 'system') NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    sent_via ENUM('in_app', 'sms', 'email', 'all') DEFAULT 'in_app',
    related_loan_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (related_loan_id) REFERENCES loans(loan_id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_is_read (is_read),
    INDEX idx_created_at (created_at)
);

-- Audit Log Table
CREATE TABLE audit_log (
    log_id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50),
    entity_id INT,
    old_values TEXT,
    new_values TEXT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_action (action),
    INDEX idx_created_at (created_at)
);

-- System Settings Table
CREATE TABLE system_settings (
    setting_id INT PRIMARY KEY AUTO_INCREMENT,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT NOT NULL,
    setting_type ENUM('string', 'number', 'boolean', 'json') DEFAULT 'string',
    description TEXT,
    updated_by INT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_setting_key (setting_key)
);

-- Insert default system settings
INSERT INTO system_settings (setting_key, setting_value, setting_type, description) VALUES
('min_loan_amount', '10000', 'number', 'Minimum loan amount in MK'),
('max_loan_amount', '300000', 'number', 'Maximum loan amount in MK'),
('default_interest_rate', '5.00', 'number', 'Default interest rate percentage'),
('default_loan_term', '30', 'number', 'Default loan term in days'),
('late_payment_penalty_rate', '2.00', 'number', 'Late payment penalty percentage per day'),
('min_credit_score', '300', 'number', 'Minimum credit score for loan approval'),
('max_active_loans', '3', 'number', 'Maximum active loans per user');

-- Insert default admin user (password: admin123 - CHANGE THIS!)
INSERT INTO users (national_id, full_name, email, phone, password_hash, role, verification_status, account_status) VALUES
('ADMIN001', 'System Administrator', 'admin@lemelaniloans.com', '+265999000000', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'verified', 'active');